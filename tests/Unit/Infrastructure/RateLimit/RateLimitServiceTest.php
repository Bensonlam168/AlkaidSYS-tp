<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\RateLimit;

use Infrastructure\RateLimit\Service\RateLimitService;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;

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
    protected function setUp(): void
    {
        parent::setUp();
        // 清理 Redis 缓存
        try {
            Cache::clear();
        } catch (\Throwable $e) {
            // Ignore cache clear errors in test setup
        }
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        try {
            Cache::clear();
        } catch (\Throwable $e) {
            // Ignore
        }
        parent::tearDown();
    }

    /**
     * 测试基本的令牌桶算法：允许请求
     */
    public function test_allow_request_when_tokens_available(): void
    {
        $service = new RateLimitService();
        $key = 'rl:test:user:test1:token_bucket';
        $capacity = 10;
        $rate = 5.0;

        // 第一次请求应该允许（满桶）
        $this->assertTrue($service->allowRequest($key, $capacity, $rate, 1));
    }

    /**
     * 测试令牌耗尽后拒绝请求
     */
    public function test_deny_request_when_tokens_exhausted(): void
    {
        $service = new RateLimitService();
        $key = 'rl:test:user:test2:token_bucket';
        $capacity = 5;
        $rate = 1.0;
        $cost = 1;

        // 消耗所有令牌
        for ($i = 0; $i < $capacity; $i++) {
            $this->assertTrue($service->allowRequest($key, $capacity, $rate, $cost), "Request {$i} should be allowed");
        }

        // 下一个请求应该被拒绝
        $this->assertFalse($service->allowRequest($key, $capacity, $rate, $cost), 'Request should be denied when tokens exhausted');
    }

    /**
     * 测试令牌补充机制
     */
    public function test_tokens_refill_over_time(): void
    {
        $service = new RateLimitService();
        $key = 'rl:test:user:test3:token_bucket';
        $capacity = 10;
        $rate = 10.0; // 每秒补充 10 个令牌

        // 消耗所有令牌
        for ($i = 0; $i < $capacity; $i++) {
            $this->assertTrue($service->allowRequest($key, $capacity, $rate, 1));
        }

        // 令牌耗尽
        $this->assertFalse($service->allowRequest($key, $capacity, $rate, 1));

        // 等待 1 秒让令牌补充
        sleep(1);

        // 应该有新的令牌（至少 10 个）
        $this->assertTrue($service->allowRequest($key, $capacity, $rate, 1), 'Request should be allowed after token refill');
    }

    /**
     * 测试 getRateLimitInfo 方法
     */
    public function test_get_rate_limit_info(): void
    {
        $service = new RateLimitService();
        $key = 'rl:test:user:test4:token_bucket';
        $capacity = 10;
        $rate = 5.0;

        // 消耗一些令牌
        $service->allowRequest($key, $capacity, $rate, 3);

        $info = $service->getRateLimitInfo($key);

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
        $service = new RateLimitService();
        $key = 'rl:test:user:test5:token_bucket';
        $capacity = 5;
        $rate = 1.0;

        // 消耗所有令牌
        for ($i = 0; $i < $capacity; $i++) {
            $service->allowRequest($key, $capacity, $rate, 1);
        }

        // 应该被拒绝
        $this->assertFalse($service->allowRequest($key, $capacity, $rate, 1));

        // 重置桶
        $service->reset($key);

        // 重置后应该允许（满桶）
        $this->assertTrue($service->allowRequest($key, $capacity, $rate, 1));
    }

    /**
     * 测试无效参数降级策略
     */
    public function test_fail_open_on_invalid_parameters(): void
    {
        $service = new RateLimitService();
        $key = 'rl:test:user:test6:token_bucket';

        // 无效的 capacity
        $this->assertTrue($service->allowRequest($key, 0, 5.0, 1), 'Should fail open on invalid capacity');

        // 无效的 rate
        $this->assertTrue($service->allowRequest($key, 10, 0.0, 1), 'Should fail open on invalid rate');

        // 无效的 cost
        $this->assertTrue($service->allowRequest($key, 10, 5.0, 0), 'Should fail open on invalid cost');
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
        $service = new RateLimitService();
        $key = 'rl:test:user:test8:token_bucket';
        $capacity = 10;
        $rate = 5.0;
        $cost = 5; // 每个请求消耗 5 个令牌

        // 第一个请求（消耗 5 个令牌）
        $this->assertTrue($service->allowRequest($key, $capacity, $rate, $cost));

        // 第二个请求（消耗 5 个令牌）
        $this->assertTrue($service->allowRequest($key, $capacity, $rate, $cost));

        // 第三个请求（令牌不足）
        $this->assertFalse($service->allowRequest($key, $capacity, $rate, $cost));
    }

    /**
     * 测试边界条件：capacity = 1
     */
    public function test_boundary_capacity_one(): void
    {
        $service = new RateLimitService();
        $key = 'rl:test:user:test9:token_bucket';
        $capacity = 1;
        $rate = 0.5; // 每 2 秒补充 1 个令牌

        // 第一个请求应该允许
        $this->assertTrue($service->allowRequest($key, $capacity, $rate, 1));

        // 第二个请求应该被拒绝（令牌耗尽）
        $this->assertFalse($service->allowRequest($key, $capacity, $rate, 1));
    }

    /**
     * 测试浮点数速率
     */
    public function test_fractional_rate(): void
    {
        $service = new RateLimitService();
        $key = 'rl:test:user:test10:token_bucket';
        $capacity = 10;
        $rate = 0.5; // 每秒补充 0.5 个令牌（每 2 秒 1 个）

        // 消耗所有令牌
        for ($i = 0; $i < $capacity; $i++) {
            $this->assertTrue($service->allowRequest($key, $capacity, $rate, 1));
        }

        // 令牌耗尽
        $this->assertFalse($service->allowRequest($key, $capacity, $rate, 1));

        // 等待 2 秒（应该补充 1 个令牌）
        sleep(2);

        // 应该允许 1 个请求
        $this->assertTrue($service->allowRequest($key, $capacity, $rate, 1));

        // 下一个应该被拒绝
        $this->assertFalse($service->allowRequest($key, $capacity, $rate, 1));
    }
}
