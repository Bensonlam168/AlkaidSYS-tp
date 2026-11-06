<?php

declare(strict_types=1);

namespace app\middleware;

use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Closure;
use think\facade\Log;
use think\Request;

/**
 * 工作流引擎租户/用户上下文桥接中间件
 *
 * ## 为什么必须有这一层
 *
 * 引擎侧的租户来源是 `Alkaid\Workflow\Engine\Multitenant\TenantContext`（静态容器），
 * 而宿主侧的权威租户在 `app\Request::tenantId()`（`TenantIdentify` 写入默认租户、
 * `Auth` 用 JWT 里的 `tenant_id` 覆盖）。两者之间**没有任何自动同步** ——
 * 不桥接的话，引擎每次调用都会因「缺少租户上下文」抛 `TenantIsolationException`（fail-closed）。
 *
 * ## 为什么这是本模块最关键的安全点（Swoole 常驻进程）
 *
 * `TenantContext` 的数据存在**静态属性**里，而 PHP 的静态属性属于进程、不属于请求。
 * Swoole 的 worker 会复用同一份进程内存处理成千上万个请求，因此：
 *
 *   请求 A（租户 1）写入上下文 → 不清理 → 请求 B（租户 2 或未登录）
 *   → 继承租户 1 的上下文 → **跨租户越权**，且日志里看不出任何异常。
 *
 * 本中间件因此做三件事，缺一不可：
 *   1. **进入即清**（`TenantContext::clear()`）：不依赖上一个请求是否正常收尾。
 *      协程 ID 会被 Swoole 复用，而「一个协程被 kill / 触发致命错误」时 `finally` 可能没跑到，
 *      残留就会挂在一个**即将被复用**的协程槽位上 —— 入口清一次把这条路彻底封掉。
 *   2. **只在拿到有效租户时写入**（`initialize()`）：取不到租户就不写，
 *      让引擎侧继续 fail-closed 抛异常，而不是拿 0 / 猜测值凑合。
 *   3. **退出必清**（`finally`）：正常返回、异常、以及下游中间件短路，三条路径都会清。
 *
 * ## 为什么不用插件自带的 `Multitenant\TenantMiddleware`
 *
 * 已读过该文件，复用会引入**安全降级**，故不采用：
 *   - 它按 `X-Tenant-ID` 请求头 / query / session 自行解析租户，**忽略**
 *     `app\Request::tenantId()` —— 而本仓库的权威租户是 JWT 里的 `tenant_id`
 *     （`TenantIdentify` 的类注释明确写了「本中间件不授予任何租户归属权」）；
 *   - 它的 `resolveUserId()` 会读 **`X-User-ID` 请求头** —— 客户端可任意伪造，
 *     等于把「谁在审批」交给调用方声明；
 *   - 它只在 `$tenantId !== null` 时初始化，`finally` 里总是 `clear()`（这点我们照抄），
 *     但没有「进入即清」。
 *
 * 结论：桥接逻辑按宿主既有语义重写（约 40 行），而不是为了「复用」把租户/用户来源换成可伪造的请求头。
 *
 * ## 挂载位置
 *
 * `route/workflow.php` 的 5 个路由组（4 个鉴权组挂在 `Auth` 之后，webhook 组单独挂）。
 * 必须在 `Auth` **之后**：JWT 里的 `tenant_id` 覆盖默认租户这一步发生在 `Auth` 里。
 *
 * @package app\middleware
 */
class WorkflowTenantContext
{
    /**
     * 处理请求
     *
     * @param Request $request 当前请求（自定义 Request，含 tenantIdOrNull()/userId()）
     * @param Closure $next    下一个中间件
     * @return mixed 下游返回值（通常是 think\Response）
     */
    public function handle(Request $request, Closure $next)
    {
        // 1) 进入即清：抹掉任何可能残留的上下文（上一个请求 / 被复用的协程槽位）
        TenantContext::clear();

        $tenantId = $this->resolveTenantId($request);
        $userId   = $this->resolveUserId($request);

        if ($tenantId !== null) {
            TenantContext::initialize($tenantId, $userId, $this->extraContext($request));
        } else {
            // 2) 取不到租户：不写上下文，只留审计日志。
            //    引擎随后会 fail-closed 抛 TenantIsolationException —— 这是**期望行为**：
            //    「不知道是哪个租户」绝不能退化成「按某个租户读写」。
            Log::warning('[WORKFLOW_TENANT_CONTEXT_MISSING] 工作流请求没有可用租户，引擎调用将 fail-closed', [
                'path'       => $request->pathinfo(),
                'method'     => $request->method(),
                'user_id'    => $userId,
                'ip'         => $request->ip(),
                'trace_id'   => method_exists($request, 'getTraceId') ? $request->getTraceId() : null,
            ]);
        }

        try {
            return $next($request);
        } finally {
            // 3) 退出必清（含异常路径）：下一请求绝不能继承本次的租户/用户
            TenantContext::clear();
        }
    }

    /**
     * 解析租户：只认宿主 Request（JWT / TenantIdentify 写入的值），不回落到请求头猜测
     *
     * 用 `tenantIdOrNull()` 而不是 `tenantId()`：
     * 后者在解析不出时抛 `TenantContextMissingException`，会让「本来就没有租户」的请求
     * 在中间件层 500；而这里的语义是「没有租户 → 不写上下文 → 交给引擎 fail-closed」，
     * 结论相同但错误来源单一（引擎侧抛出带操作名的异常，更好排查）。
     */
    private function resolveTenantId(Request $request): ?int
    {
        $tenantId = method_exists($request, 'tenantIdOrNull') ? $request->tenantIdOrNull() : null;

        // 非正整数的租户不是合法租户（tenants.id 从 1 开始），按「没有租户」处理
        if (!is_int($tenantId) || $tenantId <= 0) {
            return null;
        }

        return $tenantId;
    }

    /**
     * 解析用户：未登录返回 null（引擎侧对 null 用户按「系统/非人类发起」处理）
     */
    private function resolveUserId(Request $request): ?int
    {
        $userId = method_exists($request, 'userId') ? $request->userId() : null;

        if (!is_int($userId) || $userId <= 0) {
            return null;
        }

        return $userId;
    }

    /**
     * 附加到租户上下文的审计信息
     *
     * 只放「排查需要、且不敏感」的字段：不放 Authorization、不放请求体。
     *
     * @return array<string, mixed>
     */
    private function extraContext(Request $request): array
    {
        return [
            'source'     => 'http',
            'request_id' => $request->header('X-Request-ID'),
            'trace_id'   => method_exists($request, 'getTraceId') ? $request->getTraceId() : null,
            'ip'         => $request->ip(),
            'path'       => $request->pathinfo(),
            'method'     => $request->method(),
        ];
    }
}
