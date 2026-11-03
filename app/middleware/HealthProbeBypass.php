<?php

declare(strict_types=1);

namespace app\middleware;

use app\controller\HealthController;
use Closure;
use think\App;
use think\Request;
use think\Response;

/**
 * 健康探针旁路中间件 | Health probe bypass middleware
 *
 * 位置：`app/middleware.php` 里 **Trace / Cors 之后、SessionInit 之前**。
 *
 * ## 要解决的问题（真实故障，不是理论风险）
 *
 * 全局中间件 `think\middleware\SessionInit` 在每个请求上读会话，本项目
 * `config/session.php` 是 `type = cache` / `store = redis`。于是即使 `HealthController`
 * 里一行缓存代码都没有，**Redis 一抖动，`/healthz` 也会在路由之前 500**。
 *
 * 在 K8s / Swarm 下 liveness 失败会触发**重启**：一次 Redis 抖动 →
 * 全部后端实例一起被判死 → 一起重启 → 连接风暴 → Redis 更难恢复。这不是可用性保护，
 * 是把局部故障放大成全局故障。本中间件把探针请求从这条链上摘出来。
 *
 * ## 为什么放在 Trace / Cors 之后（而不是文档里写的「最前面」）
 *
 * | 中间件 | 是否允许在它之前执行 | 理由 |
 * | --- | --- | --- |
 * | `Trace` | ✅ 必须在前 | 只做 `random_bytes()` 生成 trace_id，**零外部依赖**；放在它后面，探针响应才能带 `trace_id` 与 `X-Trace-Id`，才能和 nginx 日志 / AccessLog 对齐 |
 * | `Cors` | ✅ 允许在前 | 只读配置与请求头、给响应补 CORS 头，不碰 DB/Redis/会话 |
 * | `SessionInit` | ❌ 必须在后 | 读 Redis 会话 —— 正是要绕开的那一个 |
 * | `TenantIdentify` | ❌ 必须在后 | `DEFAULT_TENANT_ID` 未配置时 fail-closed 抛异常 |
 * | `SiteIdentify` | ❌ 必须在后 | 可能查库/查站点上下文 |
 * | `AccessLog` / `RateLimit` | ❌ 必须在后 | 探针不该被自己的限流拒掉，也不该因写日志 I/O 而变慢 |
 *
 * ## 两个端点在这里的待遇**不同**（关键设计）
 *
 * - `/healthz`（liveness）：**常量响应**。它只回答「worker 还能不能响应」，
 *   因此这里直接短路，一个外部依赖都不碰，响应由 `HealthController::index()` 产出
 *   （复用控制器而不是另写一份 JSON，避免两条路径的字段漂移）。
 * - `/readyz`（readiness）：**不是旁路，而是「会话豁免 + 真实检查」**。
 *   它必须绕开 `SessionInit` —— 否则 Redis 一挂就 500，永远得不到
 *   「503 + 哪一项挂了」这个**唯一有用的信息**（那时候正是最需要它的时刻）；
 *   但它的响应**绝不是常量**：由 `HealthController::ready()` 真的去查 DB / Redis / 配置。
 *
 *   一句话结论：`/readyz` 需要的不是「bypass」，而是「session-exempt」；两者在本类里
 *   是两个分支，语义不可混用（把 `/readyz` 也做成常量 200 等于把 readiness 变成第二个
 *   liveness，K8s 会永远认为依赖可用）。
 *
 * ## 路径匹配为什么必须严格（本类最容易变成安全缺口的地方）
 *
 * 匹配用 `===` 全等，且**同时**约束三件事：pathinfo、HTTP 方法、无尾斜杠/子路径。
 *
 * - 不用 `str_starts_with($path, 'healthz')`：那样 `/healthz-anything`、`/healthz/../v1/...`
 *   之类会一起命中 —— 虽然本类只会返回探针响应（不泄露业务数据），但它会**静默跳过
 *   AccessLog 与 RateLimit**，等于给攻击者一块「不留痕、不被限流」的探针地毯。
 * - 不做大小写归一化：`/HEALTHZ` 不是探针（框架路由也只认小写），归一化会让「写法不同但
 *   行为相同」的地方凭空多出一处，将来改错也没人发现。
 * - 不接受尾斜杠：`/healthz/` 不命中 → 落到正常链路 → 404。**这正是本类的可观测性来源**：
 *   精确命中时 AccessLog 不记录（旁路），近似路径会记录 —— 见
 *   `tests/Feature/ReadinessProbeTest::testProbePathMatchingIsStrict()`。
 * - 只放行 `GET` / `HEAD`：路由规则只有 `GET`，若 `POST /healthz` 也被短路，就会出现
 *   「路由说 405、实际却 200」的语义分裂。
 *
 * ⚠️ 已知边界：pathinfo 已经过框架归一化（`ltrim($path, '/')`），所以 `//healthz`
 * 会等价于 `healthz` 而命中。这不构成越权（命中的仍是探针本身，不涉及任何业务处理），
 * 但**新增任何以 `/` 拼接的匹配逻辑前请先想清楚这一点**，不要在 pathinfo 之外再造一套匹配。
 *
 * @package app\middleware
 */
class HealthProbeBypass
{
    /**
     * liveness 路径（与 `route/app.php` 的规则一致，**不带**前导斜杠）
     */
    public const LIVENESS_PATH = 'healthz';

    /**
     * readiness 路径（与 `route/app.php` 的规则一致，**不带**前导斜杠）
     */
    public const READINESS_PATH = 'readyz';

    /**
     * 允许被旁路的 HTTP 方法
     *
     * `HEAD` 一并放行：编排层的部分探针实现用 HEAD（只要状态码）。
     */
    private const ALLOWED_METHODS = ['GET', 'HEAD'];

    /**
     * @param App $app 容器（用于解析 HealthController）
     */
    public function __construct(private readonly App $app)
    {
    }

    /**
     * 响应头名：标记「本次响应由旁路直接产出」
     *
     * 为什么需要它（它不是一个可有可无的装饰）：
     * `/healthz` 有两条能返回同样 JSON 的路径 ——(1) 本中间件旁路、(2) 路由兜底。
     * 两者**内容完全一致**，但只有 (1) 具备「Redis 挂了也 200」的免疫性。
     * 没有这个头，运维就无法用一条 `curl -i` 判断免疫性到底有没有生效
     * （只能去拔 Redis，代价太大）；测试也无法把「旁路真的生效」与「路由兜底恰好也能跑」
     * 区分开（访问日志行数会被并发/文件系统缓存干扰，不能作为可靠信号）。
     *
     * 注意：这个头只出现在**旁路**分支，兜底路径没有它 —— 这是刻意的「层标记」，
     * 不是行为差异：两条路径的语义、状态码、响应体、缓存头完全一致。
     */
    public const BYPASS_HEADER = 'X-Health-Probe-Bypass';

    /**
     * 处理请求：命中探针路径则接管，否则原样放行
     *
     * @param Request $request 当前请求
     * @param Closure $next    下一个中间件
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $probe = self::matchProbePath($request->pathinfo(), $request->method());

        if ($probe === null) {
            return $next($request);
        }

        // 只在这里「决定谁来处理」，响应内容全部由控制器产出 ——
        // 因此本中间件与 route/app.php 的兜底路径不可能出现行为差异。
        $controller = $this->app->make(HealthController::class);

        $response = $probe === self::LIVENESS_PATH
            ? $controller->index()
            : $controller->ready();

        // 层标记：证明「SessionInit / TenantIdentify / AccessLog / RateLimit 均未执行」
        $response->header([self::BYPASS_HEADER => $probe]);

        return $response;
    }

    /**
     * 判定请求是否命中探针（严格匹配，见类注释）
     *
     * 独立成 public static 方法，便于用例**直接**锁定匹配规则
     * （表驱动断言比「发一个 HTTP 请求看状态码」更能覆盖近似路径）。
     *
     * @param string $pathinfo `Request::pathinfo()` 的返回值（已由框架去掉前导斜杠）
     * @param string $method   `Request::method()` 的返回值
     * @return string|null 命中的探针路径常量；未命中返回 null
     */
    public static function matchProbePath(string $pathinfo, string $method = 'GET'): ?string
    {
        if (!in_array(strtoupper($method), self::ALLOWED_METHODS, true)) {
            return null;
        }

        return match ($pathinfo) {
            self::LIVENESS_PATH  => self::LIVENESS_PATH,
            self::READINESS_PATH => self::READINESS_PATH,
            default              => null,
        };
    }
}
