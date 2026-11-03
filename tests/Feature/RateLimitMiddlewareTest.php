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
     * @param int          $capacity       桶容量（最大令牌数）
     * @param float        $rate           令牌补充速率（令牌/秒）
     * @param list<string> $trustedProxies 可信代理列表（ER-09：默认空 = 不信任任何 XFF）
     */
    private function useLowCapacityIpScope(int $capacity = 5, float $rate = 0.001, array $trustedProxies = []): void
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

        // ER-09：客户端 IP 的解析完全由可信代理列表决定，用例必须显式声明
        $override['trusted_proxies'] = $trustedProxies;

        Config::set($override, 'ratelimit');
    }

    /**
     * 把限流配置覆盖为「仅启用 user 维度 + 低容量」，用于验证 user scope 的边界（ER-09）
     *
     * @param int $capacity 桶容量
     */
    private function useLowCapacityUserScope(int $capacity = 1): void
    {
        $override = $this->originalRateLimitConfig;
        $override['enabled']   = true;
        $override['algorithm'] = 'token_bucket';
        $override['scopes']['user'] = [
            'enabled'  => true,
            'limit'    => $capacity,
            'period'   => 60,
            'capacity' => $capacity,
            'rate'     => 0.0001,
        ];
        foreach (['tenant', 'ip', 'route'] as $scope) {
            $override['scopes'][$scope]['enabled'] = false;
        }
        // 可信代理与本用例无关，显式清空，避免环境注入影响结论
        $override['trusted_proxies'] = [];

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
        // ER-09 之后的语义：客户端 IP **不再**从 X-Forwarded-For 里取（那是客户端可伪造的头），
        // 而是取自 TCP 对端，即 REMOTE_ADDR。因此这里用 withServer() 模拟「客户端直连后端」。
        // 历史写法是 withHeader(['X-Forwarded-For' => $clientIp])：改前确实能改变标识，
        // 但那恰恰是缺陷 —— 任何客户端换一个 XFF 就换一个桶。
        $request->withServer(['REMOTE_ADDR' => $clientIp]);

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

        // 客户端 IP：ER-09 之后取自 TCP 对端（REMOTE_ADDR），不再取自 X-Forwarded-For
        $clientIp = '203.0.113.10';

        // 清理本用例使用的 ip 令牌桶，消除历史运行（同一 Redis 实例）残留令牌的影响。
        $bucketKey = RateLimitService::buildKey((string) env('APP_ENV', 'dev'), 'ip', $clientIp);
        (new RateLimitService())->reset($bucketKey);

        $lastResponse = null;
        // 桶容量为 5、补充速率 0.001 令牌/秒：第 6 次请求起必然被限流，
        // 发 10 次足以覆盖“先放行、后 429”的完整过程，且不依赖机器快慢。
        for ($i = 0; $i < 10; $i++) {
            $request = new \app\Request();
            $request->setMethod('GET');
            $request->setPathinfo($path);
            $request->withServer(['REMOTE_ADDR' => $clientIp]);

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

    /**
     * ER-09 用例 1：伪造 X-Forwarded-For **不能**换桶（对端不可信 → 整条 XFF 被忽略）
     *
     * 这是本次修复的核心证据。改前取 XFF 最左值，攻击者每次换一个伪造 IP 就能无限绕过
     * 按 IP 的限流；改后标识只来自 TCP 对端，换多少个伪造值都会落进同一个桶 ——
     * 因此第 capacity+1 次请求必须 429，且命中标识是**对端地址**而不是伪造值。
     */
    public function test_forged_xff_cannot_change_the_ip_bucket(): void
    {
        $path   = 'ratelimit-test-forged-xff';
        $peerIp = '198.51.100.50';

        // 默认姿态：不信任任何代理
        $this->useLowCapacityIpScope(3, 0.0001, []);

        $bucketKey = RateLimitService::buildKey((string) env('APP_ENV', 'dev'), 'ip', $peerIp);
        (new RateLimitService())->reset($bucketKey);

        $codes        = [];
        $lastResponse = null;

        // 每次换一个伪造 IP：改前每次都是新桶（永远 200），改后必须共用对端 IP 的桶
        for ($i = 1; $i <= 6; $i++) {
            $request = new \app\Request();
            $request->setMethod('GET');
            $request->setPathinfo($path);
            $request->withServer(['REMOTE_ADDR' => $peerIp]);
            $request->withHeader(['X-Forwarded-For' => '9.9.9.' . $i]);

            $lastResponse = $this->runHttp($request);
            $codes[]      = $lastResponse->getCode();
        }

        // 容量 3：前 3 次放行、第 4 次起必须 429 —— 伪造头换桶的路径已被封死。
        // 注：这里只断言「有没有被限流」，不锁定前 3 次的业务状态码
        // （该测试路径本身没有对应路由，正常是 404，与限流无关）。
        $this->assertSame(
            [429, 429, 429],
            array_slice($codes, 3),
            '伪造 X-Forwarded-For 不应改变限流桶：期望第 4 次起被限流，实际 ' . json_encode($codes)
        );
        foreach ([0, 1, 2] as $index) {
            $this->assertNotSame(
                429,
                $codes[$index],
                '容量 3：第 ' . ($index + 1) . ' 次不应被限流，实际 ' . json_encode($codes)
            );
        }

        $body = json_decode((string) $lastResponse->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('ip', $body['data']['scope'] ?? null);
        $this->assertSame(
            $peerIp,
            $body['data']['identifier'] ?? null,
            '限流标识必须是对端地址，而不是客户端伪造的 X-Forwarded-For'
        );
    }

    /**
     * ER-09 用例 2：对端可信时，按 XFF 中「从右往左第一个不可信地址」建桶
     *
     * 模拟 nginx 的真实行为：客户端带来的 XFF 在最左，nginx 追加真实客户端 IP 在最右，
     * TCP 对端则是 nginx 自己。此时限流标识应当是真实客户端 IP。
     */
    public function test_trusted_proxy_resolves_real_client_ip_for_bucket(): void
    {
        $path       = 'ratelimit-test-trusted-proxy';
        $proxyIp    = '10.0.0.1';
        $realClient = '198.51.100.77';

        // 只信任 nginx 那一个地址
        $this->useLowCapacityIpScope(3, 0.0001, [$proxyIp]);

        $bucketKey = RateLimitService::buildKey((string) env('APP_ENV', 'dev'), 'ip', $realClient);
        (new RateLimitService())->reset($bucketKey);

        $lastResponse = null;
        for ($i = 1; $i <= 4; $i++) {
            $request = new \app\Request();
            $request->setMethod('GET');
            $request->setPathinfo($path);
            // TCP 对端 = 可信代理
            $request->withServer(['REMOTE_ADDR' => $proxyIp]);
            // 最左是客户端伪造值，最右是代理追加的真实客户端地址
            $request->withHeader(['X-Forwarded-For' => '9.9.9.' . $i . ', ' . $realClient]);

            $lastResponse = $this->runHttp($request);
        }

        $this->assertSame(429, $lastResponse->getCode(), '容量 3，第 4 次应被限流');

        $body = json_decode((string) $lastResponse->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(
            $realClient,
            $body['data']['identifier'] ?? null,
            '可信代理场景下应取「从右往左第一个不可信地址」，而不是最左的伪造值'
        );

        // 反向证明：桶确实是按 $realClient 建的（令牌被消费过）
        $info = (new RateLimitService())->getRateLimitInfo($bucketKey);
        $this->assertLessThan(1.0, $info['tokens'], '预期真实客户端 IP 的桶已被耗尽');
    }

    /**
     * ER-09 用例 3：user scope 不再静默共用桶
     *
     * 认证前（本中间件位置）必须**跳过** user 维度：即使把容量压到 1、请求 5 次也不能 429，
     * 因为那会把「所有用户」的配额合成一条全局配额。
     * 同时验证「一旦 Auth 写入 userId，user 维度就真的按用户生效」——
     * 说明算法本身没问题，缺的只是执行位置。
     */
    public function test_user_scope_is_skipped_before_auth_and_works_after_user_id_is_set(): void
    {
        $path = 'ratelimit-test-user-scope';

        $this->useLowCapacityUserScope(1);

        // 1) 未认证（RateLimit 的真实执行位置）：5 次都必须放行，且不消耗任何 user 桶
        for ($i = 0; $i < 5; $i++) {
            $request = new \app\Request();
            $request->setMethod('GET');
            $request->setPathinfo($path);
            $request->withServer(['REMOTE_ADDR' => '198.51.100.60']);

            $response = $this->runHttp($request);

            $this->assertNotSame(
                429,
                $response->getCode(),
                '认证前 user scope 必须被跳过，绝不能退化成「所有人共用一个桶」'
            );
        }

        // 2) 已认证（模拟 Auth 之后）：容量 1，第 2 次必须 429 且标识为该用户
        $userId    = 424242;
        $bucketKey = RateLimitService::buildKey((string) env('APP_ENV', 'dev'), 'user', (string) $userId);
        (new RateLimitService())->reset($bucketKey);

        $responses = [];
        for ($i = 0; $i < 2; $i++) {
            $request = new \app\Request();
            $request->setMethod('GET');
            $request->setPathinfo($path);
            $request->withServer(['REMOTE_ADDR' => '198.51.100.60']);
            // 等价于 Auth 中间件解析完 JWT 后写入的身份
            $request->setUserId($userId);

            $responses[] = $this->runHttp($request);
        }

        $this->assertNotSame(429, $responses[0]->getCode(), '容量 1：第 1 次应放行');
        $this->assertSame(429, $responses[1]->getCode(), '容量 1：第 2 次应被限流（说明 user 维度按用户生效）');

        $body = json_decode((string) $responses[1]->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('user', $body['data']['scope'] ?? null);
        $this->assertSame(
            (string) $userId,
            $body['data']['identifier'] ?? null,
            'user 维度的标识必须是真实用户 id，而不是空串'
        );
    }
}
