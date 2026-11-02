<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\Response;

/**
 * Health Probe Controller | 健康探针控制器
 *
 * 只服务 `GET /healthz`（见 `route/app.php`），语义是 **liveness**：
 * 「本进程还能不能把 HTTP 请求处理完并返回 200」。
 *
 * ## 为什么继承 `BaseController` 而不是 `ApiController`
 *
 * `ApiController::__construct()` 会 `make(LanguageService::class)`（多语言词条解析），
 * 那是业务依赖：它可能读配置、读语言包缓存，甚至间接依赖存储。探针一旦挂上它，
 * 「Redis 挂了 → 探针 500 → 编排层判定容器死了 → 重启」这条错误链路就被打开了。
 * 探针的依赖面必须**尽可能为 0**，因此这里只用 `BaseController`。
 *
 * ## 为什么不用 `ApiController::success()`
 *
 * 那个方法要 `LanguageService`（见上），且会把 `message` 走一遍 i18n 翻译。
 * 这里直接产出**同一套字段**的结构体（code/message/data/timestamp/trace_id），
 * 保持与项目统一响应规范一致，但不引入任何业务依赖。
 *
 * ## 依赖清单（故意为空的那些更重要）
 *
 * | 依赖 | 是否使用 | 说明 |
 * | --- | --- | --- |
 * | 数据库 | ❌ | 没有任何 Db 调用。`tests/Feature/HealthzProbeTest` 用 DB 监听器断言「零条 SQL」 |
 * | Redis / Cache | ❌ | 没有任何 Cache 调用；限流维度已按路径关闭（见 `config/ratelimit.php`） |
 * | 鉴权 / 权限 | ❌ | 路由不挂 `Auth` / `Permission` 中间件 |
 * | 租户 / 站点上下文 | ❌ | 不读 `tenant_id` / `site_id`，也不校验它们 |
 *
 * ⚠️ 但**全局中间件仍然会执行**（`app/middleware.php`）：Trace / Cors / SessionInit /
 * TenantIdentify / SiteIdentify / AccessLog / RateLimit。其中 `SessionInit` 会读 Redis 会话、
 * `TenantIdentify` 在 `DEFAULT_TENANT_ID` 未配置时会 fail-closed 抛异常 ——
 * 这两条是「探针只能证明 worker 能响应，不证明依赖可用」的根本原因，
 * 详见 `docs/technical-specs/deployment/image-and-orchestration-production-requirements.md` §6。
 *
 * @package app\controller
 */
class HealthController extends BaseController
{
    /**
     * GET /healthz —— 返回 200 + 统一响应结构
     *
     * 幂等、无副作用、无鉴权：同一请求重复调用不会改变任何业务状态。
     *
     * @return Response 200 + `{"code":0,"message":"ok","data":{"status":"ok"},"timestamp":...}`
     */
    public function index(): Response
    {
        $payload = [
            // code = 0 是项目约定的「成功」业务码（见 ApiController 类注释）
            'code'      => 0,
            'message'   => 'ok',
            'data'      => [
                'status' => 'ok',
            ],
            'timestamp' => time(),
        ];

        // trace_id 由全局 Trace 中间件写入 Request；探针同样带上，便于把「某次 unhealthy」
        // 与同一次调用在 AccessLog / nginx 日志里对齐排查。
        $traceId = method_exists($this->request, 'getTraceId') ? $this->request->getTraceId() : null;
        if ($traceId !== null && $traceId !== '') {
            $payload['trace_id'] = $traceId;
        }

        $response = json($payload, 200);

        // 探针结果**绝不能被缓存**：任何一层（浏览器/反代/CDN）缓存住这个 200，
        // 都会让「worker 已经挂了」被一段时间的缓存假象掩盖。
        $response->header([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);

        return $response;
    }
}
