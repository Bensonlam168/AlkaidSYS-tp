<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\RateLimit;

use Infrastructure\RateLimit\Service\RateLimitService;
use Tests\ThinkPHPTestCase;

/**
 * RateLimitService Token Bucket 算法单元测试
 *
 * 测试范围：
 * 1. Token Bucket 核心算法逻辑
 * 2. 边界条件（空桶、满桶、部分令牌）
 * 3. 降级策略
 * 4. 键生成
 */
class RateLimitServiceTest extends ThinkPHPTestCase
{
    /**
     * 测试用的键前缀列表，用于清理
     */
    private array $testKeys = [];

    /**
     * RateLimitService 实例
     */
    private ?RateLimitService $service = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RateLimitService();
        $this->testKeys = [];
    }

    protected function tearDown(): void
    {
        // 清理测试用的 Redis 键
        if ($this->service !== null) {
            foreach ($this->testKeys as $key) {
                try {
                    $this->service->reset($key);
                } catch (\Throwable $e) {
                    // Ignore cleanup errors
                }
            }
        }
        $this->service = null;
        $this->testKeys = [];
        parent::tearDown();
    }

    /**
     * 注册测试键以便清理
     */
    private function registerTestKey(string $key): string
    {
        $this->testKeys[] = $key;
        // 每次使用键之前先重置，确保测试隔离
        $this->service->reset($key);
        return $key;
    }

    /**
     * 测试基本的令牌桶算法：允许请求
     */
    public function test_allow_request_when_tokens_available(): void
    {
        $key = $this->registerTestKey('rl:test:user:test1:token_bucket');
        $capacity = 10;
        $rate = 5.0;

        // 第一次请求应该允许（满桶）
        $this->assertTrue($this->service->allowRequest($key, $capacity, $rate, 1));
    }

    /**
     * 测试令牌耗尽后拒绝请求
     */
    public function test_deny_request_when_tokens_exhausted(): void
    {
        $key = $this->registerTestKey('rl:test:user:test2:token_bucket');
        $capacity = 5;
        $rate = 1.0;
        $cost = 1;

        // 消耗所有令牌
        for ($i = 0; $i < $capacity; $i++) {
            $this->assertTrue($this->service->allowRequest($key, $capacity, $rate, $cost), "Request {$i} should be allowed");
        }

        // 下一个请求应该被拒绝
        $this->assertFalse($this->service->allowRequest($key, $capacity, $rate, $cost), 'Request should be denied when tokens exhausted');
    }

    /**
     * 测试令牌补充机制
     */
    public function test_tokens_refill_over_time(): void
    {
        $key = $this->registerTestKey('rl:test:user:test3:token_bucket');
        $capacity = 10;
        // 补充速率必须远慢于「耗尽 10 个令牌」这个循环本身的耗时，否则下面的
        // 「耗尽应拒绝」断言会变成时间敏感的 flaky：
        // 原来取 10.0（=每 100ms 补 1 个），全量测试变慢后 10 次 allowRequest 一旦跨过
        // 100ms，第 11 次就已被补充放行 → assertFalse 失败（实测在 2790 断言的负载下复现，
        // 单独跑却 3/3 全绿）。
        // 改成 1.0（=每秒补 1 个）：循环耗时是毫秒级，绝无可能在窗口内补充出 1 个令牌；
        // 而「补充后可再次放行」的语义仍成立 —— 下方轮询最多等 5 秒，1 秒即会补充到位。
        // 被验证的两条语义都没变：耗尽即拒绝；令牌随时间补充。
        $rate = 1.0; // 每秒补充 1 个令牌

        // 消耗所有令牌
        for ($i = 0; $i < $capacity; $i++) {
            $this->assertTrue($this->service->allowRequest($key, $capacity, $rate, 1));
        }

        // 令牌耗尽
        $this->assertFalse($this->service->allowRequest($key, $capacity, $rate, 1));

        // 令牌补充依赖真实时间，而 Redis/容器时钟在 CI 高负载下可能滞后于 PHP 进程；
        // 因此不依赖“sleep(1) 后立即可用”的精确时序，改为轮询等待：
        // 最多等待 5 秒、每 100ms 尝试一次，首次成功即通过，全部失败才判定补充机制失效。
        // 被验证的语义不变：令牌会随时间补充，耗尽后可再次放行请求。
        $deadline = microtime(true) + 5.0;
        $refilled = false;
        while (microtime(true) < $deadline) {
            if ($this->service->allowRequest($key, $capacity, $rate, 1)) {
                $refilled = true;
                break;
            }
            usleep(100_000); // 100ms
        }

        $this->assertTrue($refilled, 'Request should be allowed after token refill');
    }

    /**
     * 测试 getRateLimitInfo 方法
     */
    public function test_get_rate_limit_info(): void
    {
        $key = $this->registerTestKey('rl:test:user:test4:token_bucket');
        $capacity = 10;
        $rate = 5.0;

        // 消耗一些令牌
        $this->service->allowRequest($key, $capacity, $rate, 3);

        $info = $this->service->getRateLimitInfo($key);

        $this->assertIsArray($info);
        $this->assertArrayHasKey('tokens', $info);
        $this->assertArrayHasKey('last_update', $info);
        $this->assertLessThanOrEqual($capacity, $info['tokens']);
        $this->assertGreaterThanOrEqual(0, $info['tokens']);
    }

    /**
     * 测试 reset 方法
     */
    public function test_reset_bucket(): void
    {
        $key = $this->registerTestKey('rl:test:user:test5:token_bucket');
        $capacity = 5;
        $rate = 1.0;

        // 消耗所有令牌
        for ($i = 0; $i < $capacity; $i++) {
            $this->service->allowRequest($key, $capacity, $rate, 1);
        }

        // 应该被拒绝
        $this->assertFalse($this->service->allowRequest($key, $capacity, $rate, 1));

        // 重置桶
        $this->service->reset($key);

        // 重置后应该允许（满桶）
        $this->assertTrue($this->service->allowRequest($key, $capacity, $rate, 1));
    }

    /**
     * 测试无效参数降级策略
     */
    public function test_fail_open_on_invalid_parameters(): void
    {
        $key = $this->registerTestKey('rl:test:user:test6:token_bucket');

        // 无效的 capacity
        $this->assertTrue($this->service->allowRequest($key, 0, 5.0, 1), 'Should fail open on invalid capacity');

        // 无效的 rate
        $this->assertTrue($this->service->allowRequest($key, 10, 0.0, 1), 'Should fail open on invalid rate');

        // 无效的 cost
        $this->assertTrue($this->service->allowRequest($key, 10, 5.0, 0), 'Should fail open on invalid cost');
    }

    /**
     * 测试 buildKey 静态方法
     */
    public function test_build_key(): void
    {
        $key = RateLimitService::buildKey('prod', 'user', '123');

        $this->assertStringContainsString('rl:prod:user:', $key);
        $this->assertStringContainsString(':token_bucket', $key);
    }

    /**
     * 测试高消耗请求（消耗多个令牌）
     */
    public function test_high_cost_request(): void
    {
        $key = $this->registerTestKey('rl:test:user:test8:token_bucket');
        $capacity = 10;
        $rate = 5.0;
        $cost = 5; // 每个请求消耗 5 个令牌

        // 第一个请求（消耗 5 个令牌）
        $this->assertTrue($this->service->allowRequest($key, $capacity, $rate, $cost));

        // 第二个请求（消耗 5 个令牌）
        $this->assertTrue($this->service->allowRequest($key, $capacity, $rate, $cost));

        // 第三个请求（令牌不足）
        $this->assertFalse($this->service->allowRequest($key, $capacity, $rate, $cost));
    }

    /**
     * 测试边界条件：capacity = 1
     */
    public function test_boundary_capacity_one(): void
    {
        $key = $this->registerTestKey('rl:test:user:test9:token_bucket');
        $capacity = 1;
        $rate = 0.5; // 每 2 秒补充 1 个令牌

        // 第一个请求应该允许
        $this->assertTrue($this->service->allowRequest($key, $capacity, $rate, 1));

        // 第二个请求应该被拒绝（令牌耗尽）
        $this->assertFalse($this->service->allowRequest($key, $capacity, $rate, 1));
    }

    /**
     * 测试浮点数速率
     */
    public function test_fractional_rate(): void
    {
        $key = $this->registerTestKey('rl:test:user:test10:token_bucket');
        $capacity = 10;
        $rate = 0.5; // 每秒补充 0.5 个令牌（每 2 秒 1 个）

        // 消耗所有令牌
        for ($i = 0; $i < $capacity; $i++) {
            $this->assertTrue($this->service->allowRequest($key, $capacity, $rate, 1));
        }

        // 令牌耗尽
        $this->assertFalse($this->service->allowRequest($key, $capacity, $rate, 1));

        // 等待 2 秒（应该补充 1 个令牌）
        sleep(2);

        // 应该允许 1 个请求
        $this->assertTrue($this->service->allowRequest($key, $capacity, $rate, 1));

        // 下一个应该被拒绝
        $this->assertFalse($this->service->allowRequest($key, $capacity, $rate, 1));
    }
}
