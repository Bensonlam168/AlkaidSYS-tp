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
 *      并额外用「拆掉这条白名单 → 立刻 429」证明第 2 条不是空转；
 *   3. 演示路由（`/think`、`/hello/:name`）在生产环境**不注册**，且控制器层也 404
 *      （只删路由不够，见 `app\controller\Index` 的类注释）；
 *   4. 探针的访问日志行为被**显式固定**（每次一行，`rate_limited=false`）——
 *      将来若有人决定「探针不记访问日志」，这条用例会红，迫使这个决定被显式做出。
 *
 * ## 未在此文件覆盖的边界（放在报告里，因为它做不到）
 *
 * 「Redis 不可用 → `/healthz` 仍 200」**在当前架构下做不到**：全局中间件
 * `think\middleware\SessionInit` 会在每个请求上读 `session.store`（本项目配置为 `redis`）
 * 的会话数据，Redis 一挂就在路由之前抛异常。要满足这条需要让探针绕过 SessionInit，
 * 也就是改 `app/middleware.php`（本轮被明确禁止）。详见
 * `docs/technical-specs/deployment/image-and-orchestration-production-requirements.md` §6。
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
     * 若 `/healthz` 没被 `config/ratelimit.php` 的路径级放行覆盖，第 4 次就会 429 ——
     * 也就是说这条用例真正验证的是「白名单生效」，而不是「默认限流关着所以没限」。
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
     * 对照组：**拆掉** `/healthz` 的路径放行后，同样的紧配额立刻 429
     *
     * 没有这条，「连打 200 次全 200」可能只是因为限流中间件压根没在工作（历史上
     * `RATELIMIT_ENABLED` 未设置时中间件全程直通，那种用例是空转的）。
     */
    public function testTightQuotaActuallyRejectsWhenProbeExemptionIsRemoved(): void
    {
        $clientIp = '203.0.113.241';
        $this->useTightIpQuota($clientIp);

        // 只删掉本用例要验证的那一条路径级放行，其余配置保持原样
        $config = (array) config('ratelimit');
        unset($config['routes']['/healthz']);
        Config::set($config, 'ratelimit');

        $saw429 = false;
        $codes  = [];
        for ($i = 0; $i < 20; $i++) {
            $code = $this->probe('/' . self::PROBE_PATH, $clientIp)->getCode();
            $codes[] = $code;
            if ($code === 429) {
                $saw429 = true;
                break;
            }
        }

        $this->assertTrue(
            $saw429,
            '容量 3 的 ip 桶下，去掉 /healthz 放行后必须出现 429（否则说明限流中间件根本没生效）。实际状态码：'
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
    // 4. 访问日志：显式固定「保留」这个决定
    // -----------------------------------------------------------------------

    /**
     * 探针每次调用**恰好**产生一条访问日志，且 `rate_limited=false`
     *
     * 为什么保留而不是静默掉：探针日志是「编排层确实在探」的唯一应用侧证据；
     * 实测单行 296 字节，按 compose 的 15s 间隔算，backend + nginx 合计约 1.15 万行/天
     * （约 3.4 MB/天），不构成「刷爆日志」。若将来要静音，应改 `app\middleware\AccessLog`
     * 并把本用例一起改 —— 让这个决定被迫显式做出，而不是悄悄漂移。
     */
    public function testProbeWritesExactlyOneAccessLogLinePerCall(): void
    {
        $file = dirname(__DIR__, 2) . '/runtime/log/access/access-' . date('Ymd') . '.log';
        $before = is_file($file) ? count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;

        $this->probe('/' . self::PROBE_PATH);

        $after = is_file($file) ? count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
        $this->assertSame($before + 1, $after, '一次探针应恰好写一行访问日志');

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $entry = json_decode((string) end($lines), true);

        $this->assertIsArray($entry);
        $this->assertSame('/healthz', $entry['path'] ?? null);
        $this->assertSame(200, $entry['status_code'] ?? null);
        $this->assertFalse((bool) ($entry['rate_limited'] ?? true), '探针不应被标记为已限流');
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
