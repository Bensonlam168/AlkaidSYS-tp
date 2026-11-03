<?php

declare(strict_types=1);

namespace app\exception;

use app\constant\ErrorCode;
use RuntimeException;
use Throwable;

/**
 * 租户上下文缺失异常（fail-closed 的显式失败出口）
 *
 * 背景：`Request::tenantId()` 历史上带 `?int $default = 1` 兜底，且
 * `TenantIdentify::getDefaultTenantId()` 里还有一层 `?: 1` 回退 —— 于是「拿不到租户」
 * 会**静默**变成「按租户 1 干活」。在多租户下这是最危险的一类缺陷：
 * 一旦某个调用点跑在中间件之外（新路由、队列消费者、CLI、测试直调控制器），
 * 它不会报错，而是拿别人租户的上下文去读写。
 *
 * 本异常把这种「静默降级」变成「显式失败」。两种触发原因严格分开：
 *
 *   1. `REASON_REQUEST`（请求级）：本次请求既没有已写入的租户 ID，也没有有效的
 *      `X-Tenant-ID` 头。多半是调用链绕过了 `TenantIdentify` 中间件，
 *      或前端/调用方漏发请求头。对外 HTTP 400 + 业务码 1001（缺少必需参数）。
 *   2. `REASON_CONFIG`（配置级）：服务端没有配置 `DEFAULT_TENANT_ID`（或配成非正数），
 *      导致「未带 X-Tenant-ID 的请求」无从落到任何租户。这是**部署配置错误**，
 *      对外 HTTP 500 + 业务码 5000，并且必须在日志里写明怎么修。
 *
 * 注意：本异常不是 `ForbiddenException` 的子类 —— 403 / 2002 表示「你没有这个权限」，
 * 而这里的问题是「根本没有租户上下文可用」，两者对外语义不同，不应混用。
 *
 * @see \app\Request::tenantId() 严格版本（解析不出即抛本异常）
 * @see \app\Request::tenantIdOrNull() 显式可空版本（确实允许无租户的调用点）
 * @see \app\middleware\TenantIdentify::getDefaultTenantId() 「默认租户」的唯一决定点
 * @package app\exception
 */
class TenantContextMissingException extends RuntimeException
{
    /**
     * 请求级原因：本次请求没有任何可用的租户来源
     */
    public const REASON_REQUEST = 'request';

    /**
     * 配置级原因：`DEFAULT_TENANT_ID` 未配置或非法
     */
    public const REASON_CONFIG = 'config';

    /**
     * 触发原因（`REASON_REQUEST` / `REASON_CONFIG`）
     *
     * 供 `app\ExceptionHandle` 决定 HTTP 状态码、日志级别与「是否隐藏细节」。
     */
    private string $reason;

    /**
     * @param string         $message  可操作的中文说明（写明原因与排查方向）
     * @param int            $code     业务错误码（请求级 1001 / 配置级 5000）
     * @param string         $reason   触发原因，取 REASON_* 常量
     * @param Throwable|null $previous 原始异常
     */
    public function __construct(
        string $message,
        int $code = ErrorCode::PARAMETER_MISSING,
        string $reason = self::REASON_REQUEST,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->reason = $reason;
    }

    /**
     * 获取触发原因 | Get failure reason
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * 请求级缺失：没有任何租户来源
     *
     * @param string         $path     当前请求路径（便于日志/响应里直接定位是哪条链路）
     * @param Throwable|null $previous 原始异常
     */
    public static function forRequest(string $path = '', ?Throwable $previous = null): self
    {
        $message = '缺少租户上下文（tenant context missing）：'
            . '本次请求既没有由中间件写入租户 ID，也没有携带有效的 `X-Tenant-ID` 请求头。'
            . '租户 ID 只有三个合法来源：'
            . '（1）`Request::setTenantId()` 已被调用（正常由 `TenantIdentify` / `Auth` 中间件写入）；'
            . '（2）请求头 `X-Tenant-ID` 携带正整数；'
            . '（3）`TenantIdentify` 中间件在「未带请求头」时按 `DEFAULT_TENANT_ID` 写入默认租户。'
            . '排查方向：确认该路由是否经过全局中间件（`app/middleware.php` → `TenantIdentify`）；'
            . '若该调用点确实允许无租户（例如只做日志/审计），请改用 `Request::tenantIdOrNull()`。';

        if ($path !== '') {
            $message .= '（请求路径：' . $path . '）';
        }

        return new self($message, ErrorCode::PARAMETER_MISSING, self::REASON_REQUEST, $previous);
    }

    /**
     * ORM 作用域级缺失：全局作用域要在 ORM 层加 `tenant_id` 过滤，但拿不到租户上下文
     *
     * 触发点：`\app\model\BaseModel::scopeTenant()`。该作用域已从 fail-open 改为 fail-closed ——
     * 只要模型对应的表**确实有** `tenant_id` 列，就必须有有效租户上下文，否则抛本异常。
     *
     * 为什么复用本类而不是另造一个异常：
     *   1. **语义同源**：两者的根因都是「没有任何租户来源」，`Request::tenantIdOrNull()` 返回 null
     *      是唯一的上游事实（作用域只是在这个事实之上做决策）。真正的区别是「谁在什么时候发现」，
     *      而不是「缺了什么」；
     *   2. **对外映射已经正确且无需改动**：本类在 `app\ExceptionHandle` 里已有完整分支
     *      （REASON_REQUEST → HTTP 400 + 业务码 1001，带 trace_id、path、method 的结构化日志）。
     *      若另造新异常类，而 `ExceptionHandle` 不在本次改动范围内，它会落进「未知异常」分支
     *      被渲染成 500 + SERVER_ERROR —— 前端既有的 1001 分支失效，且排查信息全部丢失；
     *   3. **reason 取 `REASON_REQUEST` 是准确的**：在 HTTP 路径上走到这里，等价于
     *      「既没有中间件写入租户 ID，也没有合法的 `X-Tenant-ID` 头」，与 `forRequest()` 定义完全一致；
     *      而 CLI / 队列场景下 `areScopesEnabled()` 默认已经是 false，本异常根本不会被抛出
     *      （除非显式开了 `ENABLE_TENANT_SCOPES_IN_CLI=true`，那属于调用方主动选择的严格模式）。
     *
     * 文案约束（改文案前必读）：第（3）条建议里引用跨租户能力时，刻意写成
     * 「`Model::withoutTenantScope` 方法」而**不带左括号**。因为
     * `tests/Legacy/WithoutTenantScopeUsageGuardTest` 用 `strpos` 扫源码，
     * 找的是「箭头 / 双冒号 + 方法名 + 左括号」这种调用骨架，它**不区分字符串字面量** ——
     * 把调用形态写进异常文案就会被判成真实调用而报红。
     * 不要为此把本文件加进守卫白名单：白名单只放「真实但经评审的调用」，
     * 用它豁免文案会让守卫对真实调用也一起失明。
     *
     * @param string         $modelClass 触发作用域的模型类名（便于直接定位到代码）
     * @param string         $table      模型对应的数据表名（取不到时传空串）
     * @param Throwable|null $previous   上游异常（如列探测 / 上下文探测失败）
     */
    public static function forOrmScope(string $modelClass, string $table = '', ?Throwable $previous = null): self
    {
        $target = $modelClass . ($table !== '' ? '（数据表 `' . $table . '`）' : '');

        $message = '缺少租户上下文（tenant context missing）：模型 ' . $target . ' 启用了 `tenant` 全局作用域，'
            . '其数据表包含 `tenant_id` 列，说明这是**租户级数据**；但此刻拿不到有效租户ID，'
            . 'ORM 拒绝在不加租户过滤的情况下查询（fail-closed），'
            . '以避免跨租户读写。'
            . '排查方向：'
            . '（1）确认该查询所处的路由是否经过全局中间件（`app/middleware.php` → `TenantIdentify`）；'
            . '（2）确认调用方是否携带合法的 `X-Tenant-ID` 请求头（正整数）；'
            . '（3）若该查询确实需要跨租户聚合（如后台报表），请显式使用 '
            // 文案刻意不带左括号（详见本方法 docblock 的「文案约束」一段）
            . '`Model::withoutTenantScope` 方法把它排除在作用域之外，不要依赖「上下文缺失」这种隐式行为。';

        return new self($message, ErrorCode::PARAMETER_MISSING, self::REASON_REQUEST, $previous);
    }

    /**
     * 配置级缺失：`DEFAULT_TENANT_ID` 未配置或非法
     *
     * 本次改动移除了代码里隐式的「默认租户 1」，因此该配置项**必须显式设置**。
     */
    public static function forUnconfiguredDefaultTenant(?Throwable $previous = null): self
    {
        $message = '未配置 DEFAULT_TENANT_ID，请在 .env 中显式设置；本次改动移除了隐式默认租户 1。'
            . '触发场景：请求未携带 `X-Tenant-ID` 请求头，`TenantIdentify` 中间件需要回落到默认租户，'
            . '但 `DEFAULT_TENANT_ID` 缺失（或不是正整数），服务端无法决定「该落到哪个租户」。'
            . '修复方式：在 `.env`（或部署环境变量）中加一行 `DEFAULT_TENANT_ID=<已有的租户ID>`，'
            . '参考 `.env.example` 的值 1；改完需重启服务（环境变量在进程启动时读取）。'
            . '排查命令：确认目标租户存在且 `status` 为 active（`SELECT id, name, status FROM tenants`）。';

        return new self($message, ErrorCode::SERVER_ERROR, self::REASON_CONFIG, $previous);
    }
}
