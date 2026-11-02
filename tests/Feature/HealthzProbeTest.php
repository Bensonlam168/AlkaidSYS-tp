<?php

declare(strict_types=1);

namespace Tests\Feature;

use Infrastructure\RateLimit\Service\RateLimitService;
use Tests\ThinkPHPTestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * `/healthz` 健康探针的边界测试 | Health probe boundary tests
 *
 * 本文件锁定的是**探针契约**，不是「能不能返回 200」这么简单：
 *   1. 探针**不查库**（用 ORM 的 SQL 监听器逐条计数，零条才算通过）；
 *   2. 探针**不被自己的限流拒掉**（容量 3 的 ip 桶下连打 200 次必须全 200），
 *      并用「同一条紧配额下普通路由必须 429」证明第 2 条不是空转；
 *   3. 演示路由（`/think`、`/hello/:name`）在生产环境**不注册**，且控制器层也 404
 *      （只删路由不够，见 `app\controller\Index` 的类注释）；
 *   4. 探针的访问日志行为被**显式固定** —— 本轮的结论是「旁路命中时**不写**访问日志」，
 *      理由与补偿见对应用例的注释。
 *
 * ## 本轮变化：`/healthz` 在 `SessionInit` **之前**就被短路
 *
 * 上一轮本文件记录过一条「做不到」的边界：「Redis 不可用 → `/healthz` 仍 200」在当前架构下
 * 做不到，因为全局 `think\middleware\SessionInit` 会在路由之前读 Redis 会话。
 * 本轮由 `app\middleware\HealthProbeBypass`（注册在 `SessionInit` 之前）解决，
 * 真实进程证据见 `tests/Feature/ReadinessProbeTest`（故意把 session 的 Redis 指到
 * `127.0.0.1:1`，`/healthz` 仍 200、同一环境下的普通路由 500）。
 *
 * 也就是说：本文件负责「liveness 契约 + 不受限流影响 + 演示路由边界」，
 * 依赖故障下的行为与 readiness 契约由 `ReadinessProbeTest` 负责，两者不要重复也不要冲突。
 *
 * @package Tests\Feature
 */
class HealthzProbeTest extends ThinkPHPTestCase
{
    /**
     * 原始限流配置（用例内会覆盖，结束时原样写回）
     *
     * @var array<string, mixed>
     */
    private array $originalRateLimitConfig = [];

    /**
     * 约定探针路径
     */
    private const PROBE_PATH = 'healthz';

    protected function setUp(): void
    {
        parent::setUp();

        // 确保 App 已完成 initialize()：路由文件/配置都要在「已加载」的前提下才谈得上覆盖
        if (!$this->app()->initialized()) {
            $this->app()->initialize();
        }

        // 让 runHttp() 走项目自己的 Request（tenantId / traceId 等上下文方法都在它上面）
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 注册路由（include_once：同进程只注册一次）
        include_once $this->app()->getRootPath() . 'route/app.php';

        // 保存限流原始配置（此时 config/ratelimit.php 已加载）
        $this->originalRateLimitConfig = (array) config('ratelimit');
    }

    protected function tearDown(): void
    {
        // 原样恢复限流配置：中间件每次请求都读 config('ratelimit')，
        // 不恢复会让后续用例（含 RateLimitMiddlewareTest）看到被改小的容量
        if ($this->originalRateLimitConfig !== []) {
            Config::set($this->originalRateLimitConfig, 'ratelimit');
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // 1. 探针不查库
    // -----------------------------------------------------------------------

    /**
     * 探针请求期间**不得执行任何 SQL**（连建立连接都不允许）
     *
     * 手法：往 ORM 的 SQL 监听器里挂一个收集器，先跑一条对照 SQL 证明「收集器确实在工作」，
     * 再清空计数、发探针请求，断言期间零条。若探针偷偷查了库（哪怕只是开连接，
     * 因为 `PDOConnection::connect()` 也会 trigger 一条 `CONNECT:...`），这里立刻红。
     */
    public function testProbeIssuesNoDatabaseQueries(): void
    {
        $statements = [];
        $manager    = $this->app()->make('db');

        // 快照/还原监听器列表：`Db::listen()` 只增不删，不还原会跨用例累积
        $listenProperty = new \ReflectionProperty($manager, 'listen');
        $listenProperty->setAccessible(true);
        $baselineListeners = $listenProperty->getValue($manager);

        Db::listen(static function ($sql) use (&$statements): void {
            $statements[] = (string) $sql;
        });

        try {
            // 对照组：证明收集器有效（否则「0 条」可能只是没挂上）
            Db::table('users')->where('id', '>', 0)->limit(1)->select();
            $controlCount = count($statements);
            $this->assertGreaterThan(0, $controlCount, 'SQL 监听器没有收到任何语句，说明本用例的观测手段失效');

            $statements = [];
            $response   = $this->probe('/' . self::PROBE_PATH);
            $duringProbe = $statements;
        } finally {
            $listenProperty->setValue($manager, $baselineListeners);
        }

        $this->assertSame(200, $response->getCode(), '响应体：' . (string) $response->getContent());
        $this->assertSame(
            [],
            $duringProbe,
            '健康探针不允许有任何数据库访问，实际执行了：' . json_encode($duringProbe, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 探针返回统一响应结构 + 禁止缓存
     */
    public function testProbeReturnsUnifiedEnvelopeAndIsNotCacheable(): void
    {
        $response = $this->probe('/' . self::PROBE_PATH);

        $this->assertSame(200, $response->getCode(), '响应体：' . (string) $response->getContent());
        $this->assertStringContainsString('application/json', (string) $response->getHeader('Content-Type'));

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body, '探针响应必须是 JSON');
        $this->assertSame(0, $body['code'] ?? null, '成功业务码必须是 0（与 ApiController 约定一致）');
        $this->assertSame('ok', $body['message'] ?? null);
        $this->assertSame('ok', $body['data']['status'] ?? null);
        $this->assertArrayHasKey('timestamp', $body);
        $this->assertArrayHasKey('trace_id', $body, '探针也要带 trace_id，便于与 AccessLog/nginx 日志对齐');
        $this->assertNotEmpty($body['trace_id']);

        // 探针结果被任何一层缓存住，都会让「worker 已挂」被缓存假象掩盖
        $this->assertStringContainsString('no-store', (string) $response->getHeader('Cache-Control'));
    }

    // -----------------------------------------------------------------------
    // 2. 限流：探针不被自己的限流拒掉（并证明这条断言不是空转）
    // -----------------------------------------------------------------------

    /**
     * 容量只有 3 的 ip 令牌桶下，连续 200 次探针必须**全部** 200
     *
     * 本轮起探针在 `SessionInit` 之前就被 `HealthProbeBypass` 短路，**压根到不了**
     * `RateLimit` 中间件 —— 因此这条用例现在的含义是「旁路生效，探针永远不会被限流」。
     * 它仍然有价值：一旦有人把旁路从 `app/middleware.php` 里删掉或挪到 `RateLimit` 之后，
     * 只要 `config/ratelimit.php` 的路径放行还在，本用例依旧绿（纵深防御），
     * 但 `testTightQuotaRejectsRegularRouteWhileProbeStaysExempt()` 会暴露「旁路没了」。
     */
    public function testProbeIsNotRejectedTwoHundredTimesInARowUnderTightQuota(): void
    {
        $clientIp = '203.0.113.240';
        $this->useTightIpQuota($clientIp);

        $codes = [];
        for ($i = 0; $i < 200; $i++) {
            $codes[$this->probe('/' . self::PROBE_PATH, $clientIp)->getCode()] = true;
        }

        $this->assertSame(
            [200],
            array_keys($codes),
            '探针在容量 3 的配额下连续 200 次必须全部 200，实际出现过的状态码：' . json_encode(array_keys($codes))
        );
    }

    /**
     * 对照组：同一条紧配额下，**普通路由**必须被限流 —— 证明限流器真的在工作
     *
     * 上一轮这条用例的做法是「拆掉 `/healthz` 的路径级放行 → 探针立刻 429」。
     * 本轮起这个手法**失效了**，而且不是断言放宽、是行为本身变了：
     * `/healthz` 在 `SessionInit` 之前就被 `HealthProbeBypass` 短路，**根本到不了**
     * `RateLimit` 中间件 —— 拆不拆 `config/ratelimit.php` 里的白名单，对探针都不再有影响。
     *
     * 因此对照组换成「同一条紧配额打普通路由」：
     *   1. 探针（即使显式删掉白名单）→ 连续 5 次全部 200，证明免疫来自旁路而不是白名单；
     *   2. 普通路由 `/think` → 必须出现 429，证明限流器本身没有空转。
     *
     * 白名单**仍然保留**在 `config/ratelimit.php`（纵深防御）：万一将来旁路被误删、
     * 或注册顺序被挪到 `RateLimit` 之后，探针也不会被自己的限流拒掉。
     */
    public function testTightQuotaRejectsRegularRouteWhileProbeStaysExempt(): void
    {
        $clientIp = '203.0.113.241';
        $this->useTightIpQuota($clientIp);

        // 显式删掉探针的路径级放行：证明「探针免疫」不是白名单带来的
        $config = (array) config('ratelimit');
        unset($config['routes']['/healthz']);
        Config::set($config, 'ratelimit');

        $probeCodes = [];
        for ($i = 0; $i < 5; $i++) {
            $probeCodes[$this->probe('/' . self::PROBE_PATH, $clientIp)->getCode()] = true;
        }
        $this->assertSame(
            [200],
            array_keys($probeCodes),
            '去掉白名单后探针仍必须全部 200（免疫来自 HealthProbeBypass 旁路）。实际状态码：'
            . json_encode(array_keys($probeCodes))
        );

        $saw429 = false;
        $codes  = [];
        for ($i = 0; $i < 20; $i++) {
            $code    = $this->probe('/think', $clientIp)->getCode();
            $codes[] = $code;
            if ($code === 429) {
                $saw429 = true;
                break;
            }
        }

        $this->assertTrue(
            $saw429,
            '容量 3 的 ip 桶下，普通路由 /think 必须出现 429（否则说明限流中间件根本没生效）。实际状态码：'
            . json_encode($codes)
        );
    }

    // -----------------------------------------------------------------------
    // 3. 演示路由：生产环境不注册 + 控制器层兜底
    // -----------------------------------------------------------------------

    /**
     * 非生产环境：`/think`、`/hello/:name`、`/healthz` 都已注册
     */
    public function testDemoRoutesAreRegisteredOutsideProduction(): void
    {
        $rules = $this->routeRulesUnderEnv('dev');

        $this->assertArrayHasKey('healthz', $rules, '探针路由必须无条件注册');
        $this->assertArrayHasKey('think', $rules, '非生产环境应保留 /think（本地排障用的最便宜验证点）；实际规则：' . json_encode(array_keys($rules)));
        $this->assertArrayHasKey('hello/<name>', $rules, '非生产环境应保留 /hello/<name>；实际规则：' . json_encode(array_keys($rules)));
    }

    /**
     * 生产环境：演示路由**不注册**，探针路由仍在
     *
     * 手法：换一个全新的 `think\Route` 实例挂进容器，再 `include`（不是 include_once）
     * 一次 `route/app.php`，让它按当前 `APP_ENV` 重新判断一遍。用独立实例是为了
     * 不污染同进程里已经注册好的真实路由表。
     */
    public function testDemoRoutesAreNotRegisteredInProduction(): void
    {
        $productionRules = $this->routeRulesUnderEnv('production');

        $this->assertArrayHasKey('healthz', $productionRules, '生产环境同样需要探针路由');
        $this->assertArrayNotHasKey('think', $productionRules, '生产环境不得注册框架演示路由 /think');
        $this->assertArrayNotHasKey('hello/<name>', $productionRules, '生产环境不得注册演示路由 /hello/<name>');
    }

    /**
     * 生产环境：演示控制器本身也必须 404（堵住 `url_route_must=false` 的自动解析入口）
     *
     * 只删路由不够：`GET /index/hello`、`GET /index`（泄露框架版本）在没有路由规则时
     * 会被框架按「控制器/方法」自动解析，所以真正的门在 `app\controller\Index` 里。
     */
    public function testDemoControllerIsDisabledInProduction(): void
    {
        $env = $this->app()->make('env');
        $originalEnv = (string) env('APP_ENV', 'production');

        $env->set('APP_ENV', 'production');
        $thrown = null;
        try {
            // 构造即执行 initialize() —— 生产环境下必须在这里 fail-closed
            new \app\controller\Index($this->app());
        } catch (\think\exception\HttpException $e) {
            $thrown = $e;
        } finally {
            $env->set('APP_ENV', $originalEnv);
        }

        $this->assertNotNull($thrown, '生产环境下构造演示控制器必须抛 404，实际没有抛异常');
        $this->assertSame(404, $thrown->getStatusCode(), '生产环境下的演示控制器必须按「资源不存在」处理');
    }

    /**
     * 非生产环境：演示控制器可用（确保上一条不是「控制器被彻底写坏」）
     */
    public function testDemoControllerStillWorksOutsideProduction(): void
    {
        $controller = new \app\controller\Index($this->app());

        $this->assertSame('hello,unit-test', $controller->hello('unit-test'));
    }

    // -----------------------------------------------------------------------
    // 4. 访问日志：显式固定「旁路命中时不写」这个决定
    // -----------------------------------------------------------------------

    /**
     * 旁路命中探针时**不写**访问日志 —— 这是一个显式的决定，不是顺手丢掉
     *
     * 上一轮本用例断言「每次探针恰好一行访问日志」。本轮 `/healthz` 由
     * `HealthProbeBypass` 在 `SessionInit` **之前**短路，而 `AccessLog` 在链路上更靠后，
     * 因此它**必然**不会执行。把新结论同样钉死：精确命中时行数必须**不变**。
     *
     * 补偿与边界（直说）：
     *   - 代价：应用侧不再有「编排层在探我」的直接日志证据；
     *   - 补偿 1：nginx 的 access log 仍然有（docker-compose 的 nginx 探针就是端到端经过它的）；
     *   - 补偿 2：探针响应自带 `X-Trace-Id`，可与反代日志对齐；
     *   - 反向对照：**近似**路径（`/healthz/`、`/healthz/x`、`/HEALTHZ`）不走旁路，
     *     仍然各写一行 —— 见 `ReadinessProbeTest::testOnlyExactProbePathsAreBypassed()`，
     *     那条用例同时证明了「旁路真的生效」而不是「访问日志坏了」。
     *   - 若将来确实需要应用侧证据，正确做法是让旁路分支写一条**独立**的 probe 日志，
     *     而不是把探针重新塞回 `AccessLog` 之后（那会把日志 I/O 重新变成 liveness 的依赖）。
     */
    public function testProbeBypassWritesNoAccessLogLine(): void
    {
        $file = dirname(__DIR__, 2) . '/runtime/log/access/access-' . date('Ymd') . '.log';
        $before = is_file($file) ? count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;

        $response = $this->probe('/' . self::PROBE_PATH);
        $this->assertSame(200, $response->getCode(), '响应体：' . (string) $response->getContent());
        // 先证明「旁路确实接管了」——否则「没有新增日志」可能只是请求压根没发生
        $this->assertSame(
            self::PROBE_PATH,
            (string) $response->getHeader(\app\middleware\HealthProbeBypass::BYPASS_HEADER),
            '探针响应必须由 HealthProbeBypass 直接产出'
        );

        $after = is_file($file) ? count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
        $this->assertSame($before, $after, '旁路命中时 AccessLog 不应执行（因此不应新增任何访问日志行）');

        // 正向对照：同一个计数手法在**非探针**路径上必须观察到新增行。
        // 没有这条，「没有新增」也可能只是日志机制整体失效 —— 那样本用例就是空转的。
        $controlBefore = $after;
        $this->probe('/think');
        $controlAfter = is_file($file) ? count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;

        $this->assertSame(
            $controlBefore + 1,
            $controlAfter,
            '对照组失败：非探针路径 /think 应恰好写一行访问日志（说明本用例的计数手段已失效）'
        );
    }

    // -----------------------------------------------------------------------
    // 辅助方法
    // -----------------------------------------------------------------------

    /**
     * 发一次探针请求（可指定 TCP 对端地址，用于把限流桶限定成本用例独有）
     *
     * @param string      $path     路径（带前导斜杠）
     * @param string|null $clientIp 模拟的 TCP 对端地址（REMOTE_ADDR）
     * @return \think\Response
     */
    private function probe(string $path, ?string $clientIp = null)
    {
        $request = new \app\Request();
        $request->setMethod('GET');
        // ⚠️ pathinfo 不能带前导斜杠：框架拿它和路由规则的 `rule`（形如 `healthz`）直接比较，
        // 传 '/healthz' 会匹配不上并退化到「控制器自动解析」（表现为 404 controller not exists）。
        $request->setPathinfo(ltrim($path, '/'));

        if ($clientIp !== null) {
            // ER-09 之后客户端 IP 只取 TCP 对端（XFF 默认可伪造、不采信）
            $request->withServer(['REMOTE_ADDR' => $clientIp]);
        }

        return $this->runHttp($request);
    }

    /**
     * 覆盖限流配置为「只启用 ip 维度、容量 3、几乎不补充」，并清空本用例的桶
     *
     * 桶按 (env, ip) 建键，因此每个用例用自己的对端地址 + 主动 reset 即可互不影响
     * （`RateLimitMiddlewareTest` 用的是同一套手法）。
     *
     * @param string $clientIp 本用例专用的对端地址
     */
    private function useTightIpQuota(string $clientIp): void
    {
        $config = $this->originalRateLimitConfig;

        $config['enabled']   = true;
        $config['algorithm'] = 'token_bucket';
        $config['scopes']['ip'] = [
            'enabled'  => true,
            'limit'    => 3,
            'period'   => 60,
            'capacity' => 3,
            // 约 1000 秒才补 1 个令牌：连续请求期间不可能靠补充恢复，结论稳定
            'rate'     => 0.001,
        ];
        // 显式关掉其它维度，避免历史桶状态干扰本次结论
        foreach (['user', 'tenant', 'route'] as $scope) {
            $config['scopes'][$scope]['enabled'] = false;
        }
        // 不信任任何代理，保证 ip 标识就是上面传入的对端地址
        $config['trusted_proxies'] = [];

        Config::set($config, 'ratelimit');

        (new RateLimitService())->reset(
            RateLimitService::buildKey((string) env('APP_ENV', 'dev'), 'ip', $clientIp)
        );
    }

    /**
     * 在指定 `APP_ENV` 下重新执行 `route/app.php`，返回「规则 => 规则」的映射
     *
     * 用独立 `think\Route` 实例承载注册结果：既不影响容器里真实的路由表，
     * 也让 `include`（非 once）能按不同 APP_ENV 反复求值。
     *
     * @param string $env 目标环境
     * @return array<string, mixed>
     */
    private function routeRulesUnderEnv(string $env): array
    {
        $app = $this->app();

        $originalRouter = $app->make('route');
        $envObject      = $app->make('env');
        $originalEnv    = (string) env('APP_ENV', 'production');

        $fresh = new \think\Route($app);
        $app->instance('route', $fresh);
        $envObject->set('APP_ENV', $env);

        try {
            include $app->getRootPath() . 'route/app.php';

            $rules = [];
            foreach ($fresh->getRuleList() as $item) {
                $rule = (string) ($item['rule'] ?? '');
                if ($rule === '') {
                    continue;
                }
                $rules[$rule] = $item;
            }

            return $rules;
        } finally {
            $app->instance('route', $originalRouter);
            $envObject->set('APP_ENV', $originalEnv);
        }
    }
}
