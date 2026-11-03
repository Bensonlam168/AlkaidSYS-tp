<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission\Service;

use Infrastructure\Permission\Service\PermissionCacheService;
use Stringable;
use Tests\ThinkPHPTestCase;

/**
 * 「缓存 try 作用域」测试（#2 回归防护）
 * Cache try-scope regression tests (#2)
 *
 * ## 守住的缺陷（#2）
 *
 * `PermissionCacheService::getUserPermissions()` / `hasPermission()` 旧实现把
 * 「缓存读 + **核心取数/判定** + 缓存回写」整段包在同一个 `try` 里，`catch` 分支又
 * `return $fetcher();` / `return $checker();`。后果有两个：
 *
 * 1. **核心被多调用一次**：核心抛 `\Error`（如 `\TypeError`）时，`try` 内已经调用过一次，
 *    `catch` 里再调用一次 —— 多一次无谓的 DB 查询，且第二次的异常才上抛。
 * 2. **日志语义错位**：catch 里固定写一条 `Permission cache failure`，
 *    于是「业务代码崩了」被记成「缓存故障」，排查方向被带偏。
 *
 * 修复后的语义（本文件锁定的契约）：
 *
 * | 场景 | 期望 |
 * | --- | --- |
 * | 缓存命中 | 核心**不**被调用（短路） |
 * | 缓存读/写故障 | 降级直算；结果照常返回；日志是「缓存故障」 |
 * | 核心故障 | **原样上抛**，且核心**只被调用一次**，且**不**记成缓存故障 |
 *
 * 这四条与 `ThrowableDegradationTest`（R4 的降级边界）互补：
 * 那边锁定「旁路要降级、核心不许被吞」，这边锁定「核心只调一次、日志别记错对象」。
 *
 * ## 为什么用「替身 Logger」而不是 grep 日志文件
 *
 * 断言的是**日志语义**（这条失败属于缓存还是属于核心），不是日志落盘格式。
 * 把容器里的 `log` 实例换成只收集记录的替身，既能精确断言 message/context，
 * 又不受日志文件轮转、其它用例并发写入的影响。用完在 `tearDown()` 里换回真实实例。
 *
 * @package Tests\Unit\Infrastructure\Permission\Service
 */
class PermissionCacheTryScopeTest extends ThinkPHPTestCase
{
    /**
     * 租户 ID（这些用例不查库，只用到缓存键里的租户段）
     */
    private const TENANT_ID = 1;

    /**
     * 各场景专用的 user_id，避免用例之间互相命中缓存
     */
    private const USER_ID_CORE_ERROR = 987101;
    private const USER_ID_CHECK_ERROR = 987102;
    private const USER_ID_WRITE_FAULT = 987103;
    private const USER_ID_CHECK_WRITE_FAULT = 987104;
    private const USER_ID_CACHE_HIT = 987105;
    private const USER_ID_CHECK_HIT = 987106;

    /**
     * 替身 Logger（收集日志记录）
     *
     * 结构：`records[] = ['level' => ..., 'message' => ..., 'context' => [...]]`
     */
    private object $fakeLogger;

    /**
     * 被替换掉的真实日志实例（tearDown 时还原）
     */
    private ?object $previousLogger = null;

    /**
     * 启动替身 Logger | Install the fake logger
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeLogger = new class () {
            /**
             * 收集到的日志记录
             *
             * @var array<int, array{level: string, message: string, context: array<string, mixed>}>
             */
            public array $records = [];

            public function error(string|Stringable $message, array $context = []): void
            {
                $this->add('error', $message, $context);
            }

            public function warning(string|Stringable $message, array $context = []): void
            {
                $this->add('warning', $message, $context);
            }

            public function info(string|Stringable $message, array $context = []): void
            {
                $this->add('info', $message, $context);
            }

            public function debug(string|Stringable $message, array $context = []): void
            {
                $this->add('debug', $message, $context);
            }

            private function add(string $level, string|Stringable $message, array $context): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $this->previousLogger = $this->app()->make('log');
        $this->app()->instance('log', $this->fakeLogger);
    }

    /**
     * 还原真实 Logger 并清理本用例写入的缓存键
     */
    protected function tearDown(): void
    {
        if ($this->previousLogger !== null) {
            $this->app()->instance('log', $this->previousLogger);
            $this->previousLogger = null;
        }

        // 清理用例写入的权限缓存（L1/L2 + 检查缓存），避免污染后续用例
        $service = new PermissionCacheService();
        foreach ([
            self::USER_ID_CORE_ERROR,
            self::USER_ID_CHECK_ERROR,
            self::USER_ID_WRITE_FAULT,
            self::USER_ID_CHECK_WRITE_FAULT,
            self::USER_ID_CACHE_HIT,
            self::USER_ID_CHECK_HIT,
        ] as $userId) {
            $service->clearUserCache($userId, self::TENANT_ID);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 场景 1：核心抛 \TypeError → 只调用一次 + 原样上抛 + 不记成缓存故障
    // -----------------------------------------------------------------

    /**
     * `getUserPermissions()`：核心取数抛 \TypeError
     *
     * 断言 `$calls === 1`。旧实现在 catch 里再调一次 `$fetcher()`，这里会是 2 ——
     * 因此本用例就是「核心只调用一次」的证明方式（改前红、改后绿）。
     */
    public function testCoreFetcherErrorPropagatesAndIsInvokedOnlyOnce(): void
    {
        $service = new PermissionCacheService();
        $calls = 0;

        try {
            $service->getUserPermissions(
                self::USER_ID_CORE_ERROR,
                self::TENANT_ID,
                function () use (&$calls) {
                    $calls++;

                    throw new \TypeError('模拟核心取数的类型错误（#2）');
                }
            );

            $this->fail('核心取数抛 \TypeError 时必须原样上抛，不能返回空数组顶替授权结论');
        } catch (\TypeError $e) {
            $this->assertStringContainsString(
                '模拟核心取数的类型错误（#2）',
                $e->getMessage(),
                '抛出的必须是核心那个异常本身，不能被包装/替换'
            );
        }

        $this->assertSame(
            1,
            $calls,
            '核心取数只能被调用一次；旧实现会在 catch 里再调一次（计数为 2）'
        );
        $this->assertSame(
            [],
            $this->permissionCacheFaultLogs(),
            '核心故障不得被记成「缓存故障」——那会把排查引向缓存'
        );
    }

    /**
     * `hasPermission()`：核心判定抛 \TypeError（同上，检查缓存路径）
     */
    public function testCoreCheckerErrorPropagatesAndIsInvokedOnlyOnce(): void
    {
        $service = new PermissionCacheService();
        $calls = 0;

        try {
            $service->hasPermission(
                self::USER_ID_CHECK_ERROR,
                self::TENANT_ID,
                'dept:read',
                function () use (&$calls) {
                    $calls++;

                    throw new \TypeError('模拟核心判定的类型错误（#2）');
                }
            );

            $this->fail('核心判定抛 \TypeError 时必须原样上抛');
        } catch (\TypeError $e) {
            $this->assertStringContainsString('模拟核心判定的类型错误（#2）', $e->getMessage());
        }

        $this->assertSame(
            1,
            $calls,
            '核心判定只能被调用一次；旧实现会在 catch 里再调一次（计数为 2）'
        );
        $this->assertSame([], $this->permissionCacheFaultLogs(), '核心故障不得被记成「缓存故障」');
    }

    // -----------------------------------------------------------------
    // 场景 2：缓存写抛 \TypeError → 仍返回正确结果 + 日志是「缓存故障」
    // -----------------------------------------------------------------

    /**
     * 造一个「缓存写入必抛 \TypeError」的缓存服务
     *
     * `writeCache()` 是 protected，子类可覆写 —— 这比 mock 静态 `Cache` 门面更稳，
     * 也正好模拟 R3/R4 里 `trackKey()` 抛 `\TypeError` 的真实形态（两者都在 writeCache 内）。
     */
    private function makeCacheServiceWithBrokenWrite(): PermissionCacheService
    {
        return new class () extends PermissionCacheService {
            protected function writeCache(string $key, mixed $value, int $ttl): void
            {
                throw new \TypeError('模拟缓存侧类型错误：trackKey 收到非法键（#2）');
            }
        };
    }

    /**
     * `getUserPermissions()`：缓存写失败 → 已算出的结果照常返回
     */
    public function testCacheWriteTypeErrorStillReturnsFetchedResult(): void
    {
        $service = $this->makeCacheServiceWithBrokenWrite();
        $calls = 0;

        $result = $service->getUserPermissions(
            self::USER_ID_WRITE_FAULT,
            self::TENANT_ID,
            function () use (&$calls) {
                $calls++;

                return ['dept:read', 'menu:read'];
            }
        );

        $this->assertSame(
            ['dept:read', 'menu:read'],
            $result,
            '缓存写失败必须返回已经算出来的结果，不能退化成 []（那会让用户所有权限瞬间消失）'
        );
        $this->assertSame(1, $calls, '缓存写失败不得触发核心的第二次调用');

        $faults = $this->permissionCacheFaultLogs();
        $this->assertNotSame([], $faults, '缓存写失败必须留下一条日志');
        $this->assertStringContainsString(
            'cache fault',
            strtolower($faults[0]['message']),
            '这条日志必须明确写成「缓存故障」，而不是笼统的失败'
        );
        $this->assertStringContainsString(
            '模拟缓存侧类型错误',
            (string) ($faults[0]['context']['error'] ?? ''),
            '日志必须带上缓存侧的原始异常信息'
        );

        // 日志语义修正：缓存侧失败不得出现「核心」字样（那是另一类故障）
        foreach ($this->allLogMessages() as $message) {
            $this->assertStringNotContainsString('核心', $message, '缓存侧日志不得把失败说成核心/业务故障');
        }
    }

    /**
     * `hasPermission()`：缓存写失败 → 已算出的判定照常返回
     */
    public function testPermissionCheckCacheWriteTypeErrorStillReturnsDecision(): void
    {
        $service = $this->makeCacheServiceWithBrokenWrite();
        $calls = 0;

        $result = $service->hasPermission(
            self::USER_ID_CHECK_WRITE_FAULT,
            self::TENANT_ID,
            'dept:read',
            function () use (&$calls) {
                $calls++;

                return true;
            }
        );

        $this->assertTrue($result, '缓存写失败必须返回真实判定结果');
        $this->assertSame(1, $calls, '缓存写失败不得触发核心判定的第二次调用');
        $this->assertNotSame([], $this->permissionCacheFaultLogs(), '缓存写失败必须留下一条日志');

        foreach ($this->allLogMessages() as $message) {
            $this->assertStringNotContainsString('核心', $message, '缓存侧日志不得把失败说成核心/业务故障');
        }
    }

    // -----------------------------------------------------------------
    // 场景 3：缓存命中 → 核心调用次数 == 0（短路行为保持不变）
    // -----------------------------------------------------------------

    /**
     * `getUserPermissions()`：第二次调用命中 L1，核心取数 0 次
     */
    public function testPermissionCacheHitDoesNotInvokeCoreFetcher(): void
    {
        $service = new PermissionCacheService();

        $warm = $service->getUserPermissions(
            self::USER_ID_CACHE_HIT,
            self::TENANT_ID,
            static fn (): array => ['dept:read']
        );
        $this->assertSame(['dept:read'], $warm, '预热调用应返回核心结果并写入缓存');

        $calls = 0;
        $hit = $service->getUserPermissions(
            self::USER_ID_CACHE_HIT,
            self::TENANT_ID,
            function () use (&$calls) {
                $calls++;

                return ['不该被用到'];
            }
        );

        $this->assertSame(['dept:read'], $hit, '第二次调用必须命中缓存并返回缓存值');
        $this->assertSame(0, $calls, '缓存命中时核心取数调用次数必须为 0（短路不得被破坏）');
    }

    /**
     * `hasPermission()`：第二次调用命中检查缓存，核心判定 0 次
     */
    public function testPermissionCheckCacheHitDoesNotInvokeChecker(): void
    {
        $service = new PermissionCacheService();

        $this->assertTrue($service->hasPermission(
            self::USER_ID_CHECK_HIT,
            self::TENANT_ID,
            'dept:read',
            static fn (): bool => true
        ));

        $calls = 0;
        $hit = $service->hasPermission(
            self::USER_ID_CHECK_HIT,
            self::TENANT_ID,
            'dept:read',
            function () use (&$calls) {
                $calls++;

                return false;
            }
        );

        $this->assertTrue($hit, '第二次判定必须命中缓存并返回第一次的结果');
        $this->assertSame(0, $calls, '缓存命中时核心判定调用次数必须为 0');
    }

    // -----------------------------------------------------------------
    // 辅助断言
    // -----------------------------------------------------------------

    /**
     * 取「权限缓存故障」日志（message 以 `Permission cache` 开头的记录）
     *
     * 只过滤本服务写的日志：容器里其它组件（Db / Cache / framework）也可能写日志，
     * 断言必须限定在自己的命名空间内，否则会因无关日志翻红。
     *
     * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    private function permissionCacheFaultLogs(): array
    {
        return array_values(array_filter(
            $this->fakeLogger->records,
            static fn (array $record): bool => str_starts_with($record['message'], 'Permission cache')
        ));
    }

    /**
     * 取全部日志 message（用于「不得含『核心』」这类反向断言）
     *
     * @return array<int, string>
     */
    private function allLogMessages(): array
    {
        return array_map(
            static fn (array $record): string => $record['message'] . ' ' . json_encode(
                $record['context'],
                JSON_UNESCAPED_UNICODE
            ),
            $this->fakeLogger->records
        );
    }
}
