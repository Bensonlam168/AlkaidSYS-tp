<?php

declare(strict_types=1);

namespace Tests\Feature;

use Infrastructure\RateLimit\Service\RateLimitService;
use Tests\ThinkPHPTestCase;
use think\facade\Config;

class RateLimitMiddlewareTest extends ThinkPHPTestCase
{
    /**
     * 测试开始前的原始限流配置，tearDown 时原样写回。
     *
     * 说明：中间件每次请求都会读取 config('ratelimit')，因此用例内可以精确覆盖限流参数；
     * 但必须恢复原值，否则会污染同一 PHP 进程内后续测试类。
     *
     * @var array<string, mixed>
     */
    private array $originalRateLimitConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 先确保 ThinkPHP 应用完成初始化。
        // 若此时尚未初始化，config/ratelimit.php 还没被加载，随后 Http::run() 触发的
        // App::initialize() -> loadConfig() 会用配置文件内容覆盖本用例写入的覆盖值。
        if (!$this->app()->initialized()) {
            $this->app()->initialize();
        }

        // 保存原始配置（此时已包含 config/ratelimit.php 的完整内容）
        $this->originalRateLimitConfig = (array) config('ratelimit');
    }

    /**
     * 把限流配置覆盖为「仅启用 ip 维度 + 低容量 + 几乎不补充」，用于确定性触发 429 或验证限额内放行。
     *
     * 中间件每次请求都会重新读取 config('ratelimit')，因此覆盖后立即生效；
     * 原始配置已由 setUp() 保存，tearDown() 会原样写回。
     *
     * 为什么必须覆盖配置：config/ratelimit.php 的 enabled 默认取 env('RATELIMIT_ENABLED', false)，
     * 测试环境没有设置该变量 => 中间件直接 return $next($request) 全程直通，
     * 不覆盖配置的用例根本不会触碰任何限流维度（历史上「限额内不拒绝」用例就是这样空转的）。
     *
     * @param int   $capacity 桶容量（最大令牌数）
     * @param float $rate     令牌补充速率（令牌/秒）
     */
    private function useLowCapacityIpScope(int $capacity = 5, float $rate = 0.001): void
    {
        $override = $this->originalRateLimitConfig;
        $override['enabled']   = true;
        $override['algorithm'] = 'token_bucket';
        $override['scopes']['ip'] = [
            'enabled'  => true,
            // fixed window 参数（保留，便于将来切换算法）
            'limit'    => 5,
            'period'   => 60,
            // token bucket 参数：容量 5，补充速率 0.001 令牌/秒（约 1000 秒补 1 个）
            // => 连续 10 次请求内不可能靠令牌补充恢复，结果稳定可复现
            'capacity' => $capacity,
            'rate'     => $rate,
        ];
        // 显式关闭其余维度，避免 tenant/route 维度的历史桶状态干扰本用例
        foreach (['user', 'tenant', 'route'] as $scope) {
            $override['scopes'][$scope]['enabled'] = false;
        }

        Config::set($override, 'ratelimit');
    }

    protected function tearDown(): void
    {
        // 原样恢复限流配置，保证同进程内后续测试类看到的是测试前的配置
        if ($this->originalRateLimitConfig !== []) {
            Config::set($this->originalRateLimitConfig, 'ratelimit');

            // 自检：确认恢复确实生效，避免 Config::set 语义变化时静默污染后续测试
            $this->assertSame(
                $this->originalRateLimitConfig,
                (array) config('ratelimit'),
                '限流配置未正确恢复，可能污染同进程内的后续测试'
            );
        }
        $this->originalRateLimitConfig = [];

        parent::tearDown();
    }

    public function test_requests_under_limit_are_not_rate_limited(): void
    {
        $clientIp = '198.51.100.1';
        $path     = 'ratelimit-test-ok';

        // 只启用 ip 维度（容量 5、补充速率 0.001）：单次请求必然在限额内，
        // 同时把「本用例自己的桶」限定成唯一一个，便于精确断言它真的被消费。
        $this->useLowCapacityIpScope();

        // 只重置本用例自己的 ip 令牌桶（限流状态存在 Redis，与 runtime/cache 文件缓存无关）。
        // 这里刻意不再使用 Cache::clear()：File 驱动下它会 rmdir(runtime/cache) 整个目录，
        // 多进程/并行跑测试时会误删其他用例正在写的缓存（已实测导致 Casbin 相关伪失败）。
        $bucketKey = RateLimitService::buildKey((string) env('APP_ENV', 'dev'), 'ip', $clientIp);
        (new RateLimitService())->reset($bucketKey);

        $request = new \app\Request();
        $request->setMethod('GET');
        $request->setPathinfo($path);
        // 必须用 withHeader() 真正写入请求头：
        // think\Request::header($name, $default) 只是「带默认值的读取」，不会写入任何头，
        // 原写法 $request->header('X-Forwarded-For', '198.51.100.1') 因此从未设置过 XFF，
        // 中间件只能回落到 $request->ip()（测试进程中 REMOTE_ADDR 为空 => '0.0.0.0'），
        // 于是本用例从未命中过预期的那个 ip 维度标识。
        $request->withHeader(['X-Forwarded-For' => $clientIp]);

        $response = $this->runHttp($request);

        $this->assertNotNull($response);
        $this->assertNotSame(429, $response->getCode(), '限额内的请求不应被限流');

        // 断言请求真的走到了 ip 维度：容量 5 的桶被精确消费 1 个令牌 => 剩余 4。
        // 若 ip 维度被跳过（identifier 为空/取错 IP），桶根本不会被创建，tokens 会是 0；
        // 若 Redis 故障导致中间件 fail-open，tokens 同样会是 0 —— 两种情况都会被这条断言抓住。
        $info = (new RateLimitService())->getRateLimitInfo($bucketKey);
        $this->assertEqualsWithDelta(
            4.0,
            $info['tokens'],
            0.5,
            '预期 ip 维度的令牌桶被消费 1 个令牌（证明本用例真的测到了 ip 维度，而非因标识为空被跳过）'
        );
    }

    public function test_exceeding_limit_returns_429_and_sets_rate_limited_flag(): void
    {
        // 使用 /debug/session-redis 作为简单 GET 接口进行压测
        $path = 'debug/session-redis';

        // 先清理可能存在的历史访问日志，避免干扰
        $rootPath = dirname(__DIR__, 2);
        $dir      = $rootPath . '/runtime/log/access';
        if (is_dir($dir)) {
            $pattern = $dir . '/access-' . date('Ymd') . '.log';
            foreach (glob($pattern) ?: [] as $file) {
                @unlink($file);
            }
        }

        // 用例内覆盖限流配置：ip 维度容量 5、补充速率 0.001，其余维度关闭
        $this->useLowCapacityIpScope();

        // 客户端 IP：中间件优先取 X-Forwarded-For 的第一段作为 ip 维度的标识
        $clientIp = '203.0.113.10';

        // 清理本用例使用的 ip 令牌桶，消除历史运行（同一 Redis 实例）残留令牌的影响。
        // 注意：think\Request::header($name, $default) 只是“带默认值的读取”，不会写入请求头，
        // 因此这里用 withHeader() 真正设置 X-Forwarded-For。
        $bucketKey = RateLimitService::buildKey((string) env('APP_ENV', 'dev'), 'ip', $clientIp);
        (new RateLimitService())->reset($bucketKey);

        $lastResponse = null;
        // 桶容量为 5、补充速率 0.001 令牌/秒：第 6 次请求起必然被限流，
        // 发 10 次足以覆盖“先放行、后 429”的完整过程，且不依赖机器快慢。
        for ($i = 0; $i < 10; $i++) {
            $request = new \app\Request();
            $request->setMethod('GET');
            $request->setPathinfo($path);
            $request->withHeader(['X-Forwarded-For' => $clientIp]);

            $lastResponse = $this->runHttp($request);
        }

        $this->assertNotNull($lastResponse);
        $this->assertSame(429, $lastResponse->getCode());

        // 429 响应体中应包含 trace_id，便于限流诊断
        $body = json_decode($lastResponse->getContent(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('trace_id', $body);
        $this->assertNotEmpty($body['trace_id']);

        // 明确断言命中的是 ip 维度（其余维度已在 setUp 中关闭），防止用例被其他维度“误绿”
        $this->assertSame('ip', $body['data']['scope'] ?? null, '预期命中 ip 维度的限流');

        // 读取访问日志，检查是否存在 rate_limited=true 的记录
        $this->assertDirectoryExists($dir);

        $pattern = $dir . '/access-' . date('Ymd') . '.log';
        $files   = glob($pattern);
        $this->assertNotEmpty($files, 'Expected access log file to be created');

        $file  = $files[0];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotEmpty($lines, 'Expected at least one access log line');

        $foundRateLimited = false;
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }
            if (($data['path'] ?? '') === '/'.$path && !empty($data['rate_limited'])) {
                $foundRateLimited = true;
                break;
            }
        }

        $this->assertTrue($foundRateLimited, 'Expected at least one rate_limited=true entry in access log');
    }
}
