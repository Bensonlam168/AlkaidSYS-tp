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
 *
 * ## 时间敏感性约定（为什么本文件不靠「刚好睡够」来断言）
 *
 * 令牌桶的补充量是 `(now - last_update) * rate`，而 `now` 来自 `time()`
 * （整数秒，见 RateLimitService::evalLuaScript）。两条推论决定了本文件的写法：
 *
 * 1. **「耗尽后立刻拒绝」只能在 rate 极小时才是确定性的**。
 *    若 rate = 1.0，只要 5 次 allowRequest 的循环跨过一个整秒边界，就会补出 1 个令牌，
 *    第 6 次立刻被放行 —— 也就是说这个断言实际测的是「5 次 Redis 调用能否在 1 秒内跑完」，
 *    高负载下必然抖动（实测全量随机顺序下 test_reset_bucket / test_tokens_refill_over_time
 *    正是这样挂的）。因此所有「拒绝」类断言统一用 DENY_RATE（0.01 令牌/秒，补满 1 个要 100 秒），
 *    把判据从「机器多快」换回「算法对不对」。
 * 2. **「令牌会随时间补充」只能在有界轮询里断言**，不能依赖 `sleep(N)` 后恰好可用。
 *    整数秒粒度意味着「补到 1 个令牌」需要一个整秒边界跨过；轮询把等待上限放宽到几秒，
 *    下限由算法本身保证，因此与机器快慢无关（详见 waitUntilAllowed()）。
 */
class RateLimitServiceTest extends ThinkPHPTestCase
{
    /**
     * 「耗尽即拒绝」类断言专用速率（令牌/秒）
     *
     * 0.01 的含义：补满 1 个令牌需要 100 秒。因此只要一次测试方法跑完不到 100 秒，
     * 「第 N+1 次请求被拒绝」就是**确定性**结论，与本机负载无关。
     * 原值 1.0（1 秒补 1 个）会让该断言在循环耗时跨过整秒边界时翻转，
     * 这正是随机顺序下偶发失败的根因。
     */
    private const DENY_RATE = 0.01;

    /**
     * 等待令牌补充的默认上限（秒）
     *
     * 取值只影响「机器极慢时的兜底」，不影响判定语义：正常机器上 rate = 1.0 时
     * 最多 1 秒内就会补充到位，5 秒是留给高负载/时钟滞后的余量。
     */
    private const REFILL_TIMEOUT_SECONDS = 5.0;

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
     * 轮询等待令牌被补充（有界等待，不依赖固定 sleep）
     * Poll until tokens are refilled (bounded wait, no fixed sleep)
     *
     * 为什么用轮询而不是 `sleep(N); assertTrue(...)`：
     *   令牌补充量的计算单位是**整秒**（`now` 来自 `time()`），所以「补到 1 个令牌」
     *   需要一个整秒边界跨过，而这个边界相对于调用时刻的位置是随机的。
     *   固定 sleep(N) 的写法等价于赌「N 秒正好跨过边界」，在容器时钟被压住时会失败。
     *   轮询把「何时补到」交给被测实现，测试只断言**有界的最终状态**：
     *   在 timeout 内一定允许，且（由 rate 决定）不会早于算法允许的时刻。
     *
     * 关键前提（已在 RateLimitService::LUA_SCRIPT 中核对）：每次调用都会
     * `HMSET tokens new_tokens last_update now`，但 **tokens 是累加的**，
     * 所以轮询中的失败尝试不会把已积累的令牌清零，只会把 last_update 推进到当前秒。
     *
     * @param string $key 桶键
     * @param int $capacity 桶容量
     * @param float $rate 补充速率（令牌/秒）
     * @param float $timeoutSeconds 等待上限（秒）
     * @return bool 是否在超时前观察到「允许」
     */
    private function waitUntilAllowed(
        string $key,
        int $capacity,
        float $rate,
        float $timeoutSeconds = self::REFILL_TIMEOUT_SECONDS
    ): bool {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            if ($this->service->allowRequest($key, $capacity, $rate, 1)) {
                return true;
            }
            // 50ms 一次：远小于整秒粒度，不会错过补充窗口；也不会把 Redis 打爆
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return false;
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
     *
     * 处置（保留在 Unit 套件）：把 rate 从 1.0 降到 DENY_RATE（0.01），
     * 使「补满 1 个令牌」需要 100 秒 —— 循环耗时内不可能补充出令牌，
     * 「耗尽即拒绝」变成确定性断言。被验证的语义没有变化。
     */
    public function test_deny_request_when_tokens_exhausted(): void
    {
        $key = $this->registerTestKey('rl:test:user:test2:token_bucket');
        $capacity = 5;
        $rate = self::DENY_RATE;
        $cost = 1;

        // 消耗所有令牌
        for ($i = 0; $i < $capacity; $i++) {
            $this->assertTrue($this->service->allowRequest($key, $capacity, $rate, $cost), "Request {$i} should be allowed");
        }

        // 下一个请求应该被拒绝
        $this->assertFalse($this->service->allowRequest($key, $capacity, $rate, $cost), 'Request should be denied when tokens exhausted');

        // 再确认一次桶的**状态**（不只是决策）：剩余令牌已不足 1 个。
        // 这条断言与上面的「拒绝」互为印证，且同样不依赖机器速度
        // （DENY_RATE 下要 100 秒才补回 1 个令牌）。
        $info = $this->service->getRateLimitInfo($key);
        $this->assertLessThan(1.0, $info['tokens'], 'Bucket should hold less than one token after exhaustion');
    }

    /**
     * 测试令牌补充机制
     *
     * 处置（保留在 Unit 套件，拆语义）：原写法在同一个用例里既要「耗尽后立刻拒绝」
     * 又要「5 秒内补充成功」，而 rate = 1.0 时前者只在循环耗时不到 1 秒时成立 ——
     * 两条断言抢同一个整秒边界，高负载下必然互相打架
     * （这正是随机顺序下失败的两条用例之一）。
     *
     * 现在两条语义各自用确定性写法：
     *   - 「此刻桶已空」用 DENY_RATE 探针断言（补 1 个要 100 秒，因此与负载无关）；
     *   - 「令牌随时间补充」用有界轮询断言（下限由算法保证，上限 5 秒是给负载的余量）。
     */
    public function test_tokens_refill_over_time(): void
    {
        $key = $this->registerTestKey('rl:test:user:test3:token_bucket');
        $capacity = 10;
        // rate = 1.0：每整秒补 1 个令牌，轮询上限 5 秒，余量充足
        $rate = 1.0;

        // 消耗所有令牌（bucket 初始为满桶，所以这 10 次一定允许）
        for ($i = 0; $i < $capacity; $i++) {
            $this->assertTrue($this->service->allowRequest($key, $capacity, $rate, 1));
        }

        // 确定性断言「桶已经空了」：用 DENY_RATE 探一次而不是用 $rate 探。
        // 用 $rate 探的话，只要循环耗时跨过整秒边界就会补出 1 个令牌、探针被放行，
        // 断言随之翻红（原实现就是这么抖的）；DENY_RATE 下 100 秒才补 1 个，探针必拒。
        $this->assertFalse(
            $this->service->allowRequest($key, $capacity, self::DENY_RATE, 1),
            'Bucket should be empty right after the tokens are exhausted'
        );

        // 有界轮询等待补充：断言「最终会被放行」这一条语义
        $this->assertTrue(
            $this->waitUntilAllowed($key, $capacity, $rate),
            'Request should be allowed after token refill'
        );
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
     *
     * 处置（保留在 Unit 套件）：rate 从 1.0 改为 DENY_RATE（0.01）。
     * 本用例的被测对象是 reset()，「耗尽后拒绝」只是它的前置条件，不该成为抖动源：
     * rate = 1.0 时前置条件只在 5 次调用耗时不到 1 秒时成立（实测就挂在这里）。
     * 换成 0.01 后前置条件变成确定性，reset 后「满桶可放行」同样是确定性的。
     */
    public function test_reset_bucket(): void
    {
        $key = $this->registerTestKey('rl:test:user:test5:token_bucket');
        $capacity = 5;
        $rate = self::DENY_RATE;

        // 消耗所有令牌
        for ($i = 0; $i < $capacity; $i++) {
            $this->service->allowRequest($key, $capacity, $rate, 1);
        }

        // 应该被拒绝（100 秒才补 1 个令牌，因此这是确定性的）
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
     *
     * 处置（保留在 Unit 套件）：rate 从 5.0 改为 DENY_RATE（0.01）。
     * 本用例验证的是 cost 记账（10 容量 / 每次 5 个 → 恰好 2 次放行、第 3 次拒绝），
     * 与补充速率无关；原 rate = 5.0 意味着第 3 次判断的容错窗口只有 1 秒
     * （补 5 个令牌只需 1 秒），属于同一类时间敏感隐患，故一并按 DENY_RATE 收敛。
     */
    public function test_high_cost_request(): void
    {
        $key = $this->registerTestKey('rl:test:user:test8:token_bucket');
        $capacity = 10;
        $rate = self::DENY_RATE;
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
     *
     * 处置（保留在 Unit 套件）：rate 从 0.5 改为 DENY_RATE（0.01）。
     * 断言内容不变（满桶放行 1 次、随后拒绝），但「随后拒绝」的容错窗口
     * 从 2 秒放宽到 100 秒，不再是「两次 Redis 调用必须在 2 秒内跑完」。
     */
    public function test_boundary_capacity_one(): void
    {
        $key = $this->registerTestKey('rl:test:user:test9:token_bucket');
        $capacity = 1;
        $rate = self::DENY_RATE; // 100 秒才补 1 个令牌

        // 第一个请求应该允许
        $this->assertTrue($this->service->allowRequest($key, $capacity, $rate, 1));

        // 第二个请求应该被拒绝（令牌耗尽）
        $this->assertFalse($this->service->allowRequest($key, $capacity, $rate, 1));
    }

    /**
     * 测试浮点数速率
     *
     * 处置（保留在 Unit 套件）：原用例有 4 段断言，其中两段是时间敏感的死结：
     *   a) sleep(2) 后「恰好补 1 个令牌，下一个必须拒绝」—— 只要 sleep 被拖动到 4 秒以上
     *      就会补出 2 个令牌，「下一个拒绝」随即翻转；
     *   b) 耗尽后「立刻拒绝」用 rate = 0.5 探针，容错窗口只有 2 秒。
     * 现在的写法保留全部独有语义，只去掉「精确到令牌个数」的时间赌注：
     *   1. 10 次耗尽均放行（cost 记账）；
     *   2. 用 DENY_RATE 探针确定性地断言「桶已空」（同一秒内 0.5 速率也能补 0.5 个，
     *      2 秒边界就会补满 1 个 —— 所以不能用 0.5 做这个断言）；
     *   3. 有界轮询断言「小数速率能累积出整令牌」：0.5/秒约 2 秒补满 1 个，
     *      若实现把 rate 截断成整数 0，则永远不会放行，这条断言会失败。
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

        // 确定性断言「桶已空」：探针用 DENY_RATE，而不是 0.5
        $this->assertFalse(
            $this->service->allowRequest($key, $capacity, self::DENY_RATE, 1),
            'Bucket should be empty right after the tokens are exhausted'
        );

        // 有界轮询：0.5/秒的速率下约 2 秒即可累积出 1 个令牌
        // 断言「小数速率不会被截断为 0」且「令牌确实随时间累积」
        $this->assertTrue(
            $this->waitUntilAllowed($key, $capacity, $rate),
            'Fractional rate should accumulate into a full token over time'
        );
    }
}
