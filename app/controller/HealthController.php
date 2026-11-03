<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Env;
use think\Response;

/**
 * 健康探针控制器 | Health probe controller
 *
 * 两个端点、**两种语义**，不要混用：
 *
 * | 端点 | 语义 | 依赖面 | 失败时编排层该做什么 |
 * | --- | --- | --- | --- |
 * | `GET /healthz` | liveness：本进程还能不能把 HTTP 请求处理完 | **0 个**（不查库 / 不读缓存 / 不鉴权） | 重启容器 |
 * | `GET /readyz`  | readiness：依赖（数据库 / Redis / 配置）是否可用 | DB + Redis + 配置 | **摘流量**，不要重启 |
 *
 * ## 为什么这两个端点都必须绕开全局中间件链
 *
 * `think\middleware\SessionInit` 是全局中间件（`app/middleware.php`），而本项目
 * `config/session.php` 是 `type = cache` / `store = redis` —— 它在**路由之前**读会话，
 * Redis 一挂就在任何控制器执行前抛 `RedisException`。于是「探针」实际变成了
 * 「worker + Redis 都活着」：在 K8s / Swarm 下 liveness 失败会**重启全部后端实例**，
 * 把一次 Redis 抖动放大成全站重启（这正是本轮要修的问题）。
 *
 * 因此 `app\middleware\HealthProbeBypass` 在 `SessionInit` 之前短路整条业务链
 * （SessionInit / TenantIdentify / SiteIdentify / AccessLog / RateLimit 全不执行），
 * 直接调用本类的 `index()` / `ready()`。
 *
 * 设计上 **本控制器是唯一实现**：中间件只决定「谁来处理」，响应内容（字段、状态码、
 * 缓存头、trace_id）完全由本类产出；`route/app.php` 里的路由是「中间件没生效时的兜底」。
 * 两条路径共用同一段代码，因此**不可能**出现「两条路径都能响应但行为不同」的歧义。
 *
 * ⚠️ 由此带来一条硬约束：`index()` 里**永远不能**出现 Db / Cache / 会话调用。
 * `tests/Feature/HealthzProbeTest::testProbeIssuesNoDatabaseQueries()` 用 ORM 的
 * SQL 监听器把它钉死在「零条 SQL」，谁破坏这条约束，用例立刻红。
 *
 * ## 为什么继承 `BaseController` 而不是 `ApiController`
 *
 * `ApiController::__construct()` 会 `make(LanguageService::class)`（多语言词条解析），
 * 那是业务依赖：它可能读配置、读语言包缓存，甚至间接依赖存储。探针一旦挂上它，
 * 「Redis 挂了 → 探针 500 → 编排层判定容器死了 → 重启」这条错误链路就被重新打开了。
 * 探针的依赖面必须**尽可能为 0**，因此这里只用 `BaseController`（构造函数只读容器里的 request）。
 *
 * ## 为什么不用 `ApiController::success()`
 *
 * 那个方法要 `LanguageService`（见上），且会把 `message` 走一遍 i18n 翻译。
 * 这里直接产出**同一套字段**的结构体（code/message/data/timestamp/trace_id），
 * 保持与项目统一响应规范一致，但不引入任何业务依赖。
 *
 * ## 依赖清单（故意为空的那些更重要）
 *
 * | 依赖 | `/healthz` | `/readyz` |
 * | --- | --- | --- |
 * | 数据库 | ❌ 不使用 | ✅ `SELECT 1`（短超时，独立连接） |
 * | Redis / Cache | ❌ 不使用 | ✅ `PING`（短超时，独立读超时） |
 * | 鉴权 / 权限 | ❌ 路由不挂 Auth / Permission | ❌ 同左 |
 * | 租户 / 站点上下文 | ❌ 不读 tenant_id / site_id | ❌ 只校验 `DEFAULT_TENANT_ID` 已配置 |
 *
 * @package app\controller
 */
class HealthController extends BaseController
{
    /**
     * 单次依赖检查的超时（秒）
     *
     * 刻意写死、不做成环境变量：探针超时必须短且**恒定**，可配会让「某台机器把超时
     * 调成 30 秒」变成一个没人看得见的定时炸弹。真实取值：DB 连接 2s、Redis 读 2s，
     * 加上 TCP 预检 2s，最坏情况 `/readyz` 约 4s 返回 —— K8s 的 `timeoutSeconds`
     * 必须给到 ≥ 5s（见 `docs/technical-specs/deployment/image-and-orchestration-production-requirements.md` §6）。
     */
    private const CHECK_TIMEOUT_SECONDS = 2;

    /**
     * 判定「生产类环境」的环境名（与 `config/cache.php` / `route/app.php` 保持一致）
     *
     * 这些环境下 `APP_DEBUG` 必须为 false，否则错误页会把堆栈、SQL、绝对路径直接吐给调用方。
     */
    private const PRODUCTION_LIKE_ENVS = ['production', 'prod', 'stage', 'staging'];

    // -----------------------------------------------------------------------
    // liveness
    // -----------------------------------------------------------------------

    /**
     * GET /healthz —— 返回 200 + 统一响应结构（**liveness**）
     *
     * 幂等、无副作用、无鉴权：同一请求重复调用不会改变任何业务状态。
     *
     * ⚠️ 本方法只回答「worker 还能不能响应」，**不得**回答「依赖是否可用」——
     * 后者是 `ready()` 的职责。任何在此处新增的 Db / Cache 调用都会让
     * 「Redis 抖动 → 全站实例重启」这个故障模式重新出现。
     *
     * 响应体形状刻意与本轮改造前**完全一致**（`{"code":0,"message":"ok","data":{"status":"ok"}}`）：
     * 既有的 Dockerfile HEALTHCHECK、docker-compose healthcheck、nginx 端到端探针、
     * 监控告警与 `tests/Feature/HealthzProbeTest` 都依赖它，换一套「极简形状」
     * （例如裸 `OK` 文本）只会让所有使用方同步改一遍，收益为零、回归风险不为零。
     *
     * @return Response 200 + `{"code":0,"message":"ok","data":{"status":"ok"},"timestamp":...,"trace_id":...}`
     */
    public function index(): Response
    {
        return $this->buildResponse(200, 'ok', [
            'status' => 'ok',
        ]);
    }

    // -----------------------------------------------------------------------
    // readiness
    // -----------------------------------------------------------------------

    /**
     * GET /readyz —— readiness 探针（**会真的查依赖**，失败返回 503）
     *
     * 与 `/healthz` 的分工：
     *   - `/healthz` 失败 = 进程坏了 → 重启；
     *   - `/readyz` 失败 = 依赖坏了 → **摘流量**（重启后端对 Redis/MySQL 抖动毫无帮助，
     *     只会让所有实例一起重启、连接风暴更大）。
     *
     * 三项检查**互相独立**：每项各自 try/catch + 各自超时，一项失败不影响其它项执行，
     * 因此响应里能一次列全「到底挂了哪些」，而不是遇到第一个就返回
     * （那会逼运维改一次配置、重启一次、再看下一个错误）。
     *
     * 幂等、无副作用、无鉴权：
     *   - 数据库只执行 `SELECT 1`，不读业务表、不写任何数据；
     *   - Redis 只执行 `PING`，不写 key；
     *   - 配置检查纯内存读取。
     *
     * 状态码语义：全部通过 → **200**；任一失败 → **503**（`code` 同步为 503，
     * 与 `RateLimit` 中间件用 `code=429` 表示 429 的既有约定一致）。
     *
     * @return Response 200 / 503 + `{"code":..,"data":{"status":"ok|degraded","checks":{...}}}`
     */
    public function ready(): Response
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis'    => $this->checkRedis(),
            'config'   => $this->checkConfig(),
        ];

        $healthy = !in_array(false, array_column($checks, 'ok'), true);

        return $this->buildResponse($healthy ? 200 : 503, $healthy ? 'ok' : 'degraded', [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ]);
    }

    /**
     * 数据库就绪：在一条**独立、短超时**的连接上执行 `SELECT 1`
     *
     * 为什么不用 `Db::query('SELECT 1')` 走默认连接：
     * `config/database.php` 的 `params` 是空数组，PDO 不设 `ATTR_TIMEOUT` 时，
     * 目标不可达会一路挂到 OS 级 TCP 重传超时（可达 2 分钟）。探针挂 2 分钟的
     * 后果不是「慢」，是**编排层的探测队列被占满**，连带的正常探测也被判失败。
     *
     * 因此这里按当前连接配置**复制一份**出来，只覆盖 `PDO::ATTR_TIMEOUT = 2s`，
     * 交给框架的 `Db::connect($config)` 建连接（沿用同一个 connector，仍会正确处理
     * charset / prefix / dsn / deploy）。配置相同的连接在 `DbManager` 内按
     * `md5(config)` 复用，不会每次探测都新建一条。
     *
     * @return array{ok: bool, latency_ms: float, error?: string}
     */
    private function checkDatabase(): array
    {
        $startedAt = microtime(true);

        try {
            $connection = Db::connect($this->probeDatabaseConfig());
            $connection->query('SELECT 1');

            return $this->passed($startedAt);
        } catch (\Throwable $e) {
            return $this->failed($startedAt, $e);
        }
    }

    /**
     * 构造探针专用的数据库连接配置（当前连接配置 + 短超时）
     *
     * @return array<string, mixed>
     * @throws \RuntimeException 数据库连接配置缺失时
     */
    private function probeDatabaseConfig(): array
    {
        $name   = (string) config('database.default', 'mysql');
        $config = (array) config('database.connections.' . $name, []);

        if ($config === []) {
            throw new \RuntimeException('database connection config not found: ' . $name);
        }

        $config['params'] = array_replace((array) ($config['params'] ?? []), [
            \PDO::ATTR_TIMEOUT => self::CHECK_TIMEOUT_SECONDS,
        ]);

        return $config;
    }

    /**
     * Redis 就绪：建一条**独立、双向都有超时**的连接并 `PING` 一次
     *
     * 为什么不用应用正在用的连接（`Cache::store(...)->handler()`，也就是
     * `Infrastructure\RateLimit\Service\RateLimitService::getRedisHandler()` 的做法）：
     *
     *   1. **无法设超时**：ThinkPHP 的 Redis 驱动在建连时用
     *      `cache.stores.redis.timeout`（本项目是 0，即不设超时），目标不可达会阻塞到
     *      OS 级 TCP 超时；探针挂住的后果不是「慢」，是编排层的探测队列被占满。
     *   2. **改共享连接的选项会污染它**：曾经试过在共享 handler 上临时
     *      `setOption(OPT_READ_TIMEOUT, 2)`，PING 完再恢复原值（0.0）——
     *      实测恢复成 0.0 之后**下一条业务读取立刻报 `read error on connection`**
     *      （phpredis 里 0 是「立即超时」而不是「恢复默认」）。探针绝不能改业务连接的状态。
     *   3. Swoole 下 `Cache::store()` 还是连接池代理
     *      （`think\swoole\pool\proxy\Store`），`handler()` 拿到的不是 `\Redis`，
     *      借用/归还时机由协程决定，探针在里面动手脚只会更难推理。
     *
     * 因此这里按**同一份配置**（`cache.stores.<store>` 的 host/port/password/select）
     * 新建一条短连接：`connect()` 与读都设 `CHECK_TIMEOUT_SECONDS`，用完 `close()`。
     * 语义也更准确 —— readiness 要回答的是「现在新连一条能不能用」，而不是「池里那条老连接还在不在」。
     *
     * @return array{ok: bool, latency_ms: float, skipped?: bool, reason?: string, store?: string, error?: string}
     */
    private function checkRedis(): array
    {
        $startedAt = microtime(true);

        try {
            $store = $this->probeRedisStore();

            if ($store === null) {
                // 本项目 `config/session.php` 固定 store=redis，正常部署不会走到这里；
                // 真走到了（有人把会话/缓存全换成非 Redis 驱动）也不该让 readiness 失败 ——
                // 那时 Redis 已不是依赖，报 503 只会误导运维。
                return [
                    'ok'         => true,
                    'latency_ms' => $this->elapsedMs($startedAt),
                    'skipped'    => true,
                    'reason'     => 'no_redis_store_configured',
                ];
            }

            [$storeName, $storeConfig] = $store;

            $this->pingRedis($storeName, $storeConfig);

            return $this->passed($startedAt) + ['store' => $storeName];
        } catch (\Throwable $e) {
            return $this->failed($startedAt, $e);
        }
    }

    /**
     * 选出「请求链路真正依赖的那个 Redis 存储」
     *
     * 优先 `session.store`：`SessionInit` 每个请求都读它，它挂了所有业务请求就都挂了
     * （这正是 `/healthz` 曾经 500 的根本原因）。其次是 `cache.default`（生产环境同为 Redis）。
     *
     * @return array{0: string, 1: array<string, mixed>}|null 无 Redis 存储时返回 null
     */
    private function probeRedisStore(): ?array
    {
        $candidates = [];

        if (strtolower((string) config('session.type', '')) === 'cache') {
            $candidates[] = (string) config('session.store', '');
        }
        $candidates[] = (string) config('cache.default', '');

        foreach ($candidates as $name) {
            if ($name === '') {
                continue;
            }

            $config = (array) config('cache.stores.' . $name, []);

            if (strtolower((string) ($config['type'] ?? '')) !== 'redis') {
                continue;
            }

            return [$name, $config];
        }

        return null;
    }

    /**
     * 用一条**探针专用**的短连接执行 `PING`
     *
     * 连接与读取都用 `CHECK_TIMEOUT_SECONDS` 兜底：目标不可达时是「2 秒后失败」，
     * 而不是「挂到 OS 级超时」。用完立刻 `close()`，不留常驻连接。
     *
     * @param string                $storeName   来源 store 名（仅用于错误信息）
     * @param array<string, mixed>  $storeConfig `cache.stores.<name>` 的配置
     * @throws \RuntimeException 连接或 PING 失败时
     */
    private function pingRedis(string $storeName, array $storeConfig): void
    {
        $host     = trim((string) ($storeConfig['host'] ?? ''));
        $port     = (int) ($storeConfig['port'] ?? 0);
        $password = (string) ($storeConfig['password'] ?? '');
        $database = (int) ($storeConfig['select'] ?? 0);

        if ($host === '' || $port <= 0) {
            throw new \RuntimeException(sprintf('redis store [%s] config incomplete: host/port', $storeName));
        }

        if (!extension_loaded('redis')) {
            // 会话驱动本身就是 phpredis 实现的：扩展不在，业务请求一样会挂，属于真实的不就绪
            throw new \RuntimeException('php redis extension is not loaded');
        }

        $redis    = new \Redis();
        $timeout  = (float) self::CHECK_TIMEOUT_SECONDS;
        $connected = false;

        try {
            // 第 3 个参数是连接超时，第 6 个是读超时；两者都必须显式给，否则不可达时会长时间阻塞
            try {
                $connected = @$redis->connect($host, $port, $timeout, null, 0, $timeout);
            } catch (\Throwable $e) {
                // phpredis 在部分版本里对连接失败是**抛异常**而不是返回 false；
                // 统一包一层，让错误信息带上「连的是哪台 Redis」（裸的 "Connection refused" 定位不了）
                throw new \RuntimeException(
                    sprintf('redis connect %s:%d failed (store=%s): %s', $host, $port, $storeName, $e->getMessage()),
                    0,
                    $e
                );
            }

            if ($connected === false) {
                throw new \RuntimeException(sprintf('redis connect %s:%d failed (store=%s)', $host, $port, $storeName));
            }

            if ($password !== '' && !@$redis->auth($password)) {
                throw new \RuntimeException(sprintf('redis auth failed (store=%s)', $storeName));
            }

            if ($database !== 0) {
                $redis->select($database);
            }

            $pong = $redis->ping();

            // phpredis 返回 true（部分版本返回 "+PONG"）；返回 false 表示连接异常
            if ($pong === false) {
                throw new \RuntimeException(sprintf('redis PING returned false (store=%s)', $storeName));
            }
        } finally {
            if ($connected === true) {
                try {
                    $redis->close();
                } catch (\Throwable) {
                    // 关闭失败不影响本次探测结论
                }
            }
        }
    }

    /**
     * 配置就绪：`DEFAULT_TENANT_ID` 必须显式配置且为正整数；生产类环境必须关掉 `APP_DEBUG`
     *
     * `DEFAULT_TENANT_ID` 为什么是就绪条件：`TenantIdentify` 是全局中间件，未配置时
     * **fail-closed 抛异常**（ER-04），即「配置缺了 = 所有不带 X-Tenant-ID 的请求全部 500」。
     * 这种故障在部署后第一个业务请求才暴露，放在 readiness 里能让新 Pod 直接不接流量。
     *
     * `APP_DEBUG` 为什么纳入：生产类环境下 `APP_DEBUG=true` 会把堆栈、SQL、绝对路径
     * 直接返回给调用方（`docs/technical-specs/security/security-guidelines.zh-CN.md`）。
     * 它与「能不能服务」无关，但属于「不该接流量」的配置错误 —— 让 readiness 拦在流量之前，
     * 比等第一个 500 页面泄露内部路径便宜得多。只对生产类环境生效，dev/test 不受影响。
     *
     * @return array{ok: bool, latency_ms: float, app_env: string, app_debug: bool, default_tenant_id: int|null, errors?: list<string>}
     */
    private function checkConfig(): array
    {
        $startedAt = microtime(true);
        $errors    = [];

        // 与 TenantIdentify 完全同源：Env::get('DEFAULT_TENANT_ID')，不做任何隐式默认值
        $configuredTenantId = Env::get('DEFAULT_TENANT_ID');
        $tenantId           = null;

        if ($configuredTenantId === null || $configuredTenantId === '') {
            $errors[] = 'DEFAULT_TENANT_ID 未配置（TenantIdentify 会 fail-closed，所有缺省租户请求将 500）';
        } else {
            $tenantId = (int) $configuredTenantId;

            if ($tenantId <= 0) {
                $errors[] = 'DEFAULT_TENANT_ID 不是正整数（当前值不可用）';
            }
        }

        $appEnv    = strtolower((string) env('APP_ENV', 'production'));
        $appDebug  = (bool) filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
        $prodLike  = in_array($appEnv, self::PRODUCTION_LIKE_ENVS, true);

        if ($prodLike && $appDebug) {
            $errors[] = 'APP_DEBUG 在生产类环境必须为 false（否则错误页会泄露堆栈与内部路径）';
        }

        $result = [
            'ok'                => $errors === [],
            'latency_ms'        => $this->elapsedMs($startedAt),
            'app_env'           => $appEnv,
            'app_debug'         => $appDebug,
            'default_tenant_id' => $tenantId,
        ];

        if ($errors !== []) {
            $result['errors'] = $errors;
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // 响应构造
    // -----------------------------------------------------------------------

    /**
     * 产出统一响应结构（`/healthz` 与 `/readyz` 共用，保证两条路径的字段与头一致）
     *
     * @param int                 $status   HTTP 状态码（200 / 503）
     * @param string              $message  业务消息（ok / degraded）
     * @param array<string, mixed> $data    data 段
     * @return Response
     */
    private function buildResponse(int $status, string $message, array $data): Response
    {
        $payload = [
            // code = 0 是项目约定的「成功」业务码（见 ApiController 类注释）；
            // 失败时与 HTTP 状态码保持一致（同 RateLimit 中间件用 code=429 的既有做法）
            'code'      => $status === 200 ? 0 : $status,
            'message'   => $message,
            'data'      => $data,
            'timestamp' => time(),
        ];

        // trace_id 由全局 Trace 中间件写入 Request（它在 HealthProbeBypass 之前执行）；
        // 探针同样带上，便于把「某次 unhealthy」与同一次调用在 nginx 日志里对齐排查。
        $traceId = method_exists($this->request, 'getTraceId') ? $this->request->getTraceId() : null;
        if ($traceId !== null && $traceId !== '') {
            $payload['trace_id'] = $traceId;
        }

        $response = json($payload, $status);

        // 探针结果**绝不能被缓存**：任何一层（浏览器/反代/CDN）缓存住这个 200/503，
        // 都会让「worker 已经挂了」或「依赖已经恢复」被一段时间的缓存假象掩盖。
        $response->header([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);

        return $response;
    }

    /**
     * 检查通过
     *
     * @return array{ok: bool, latency_ms: float}
     */
    private function passed(float $startedAt): array
    {
        return [
            'ok'         => true,
            'latency_ms' => $this->elapsedMs($startedAt),
        ];
    }

    /**
     * 检查失败（错误信息截断到 200 字符：探针响应可能经反代对外，不搬运完整异常栈）
     *
     * @return array{ok: bool, latency_ms: float, error: string}
     */
    private function failed(float $startedAt, \Throwable $e): array
    {
        return [
            'ok'         => false,
            'latency_ms' => $this->elapsedMs($startedAt),
            'error'      => mb_substr($e->getMessage(), 0, 200),
        ];
    }

    /**
     * 单调计时（毫秒，保留两位小数）
     */
    private function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }
}
