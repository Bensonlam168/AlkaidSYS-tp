<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission\Cache;

use Infrastructure\Permission\Cache\RedisScanPurger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Redis 前缀清除器单元测试
 * Redis prefix purger unit tests
 *
 * ## 这组测试守住的契约（R1）
 *
 * 1. **只用 SCAN**：不调用 `KEYS`（阻塞全库）也不调用 `FLUSHDB`（清整库）。
 *    替身只实现了 `scan()` / `del()`，任何 `keys()` / `flushDB()` 调用都会直接致命错误，
 *    因此「跑通」本身就是证据。
 * 2. **游标迭代走完全部页**：多页结果不遗漏。
 * 3. **同组只扫一轮**：`l1:permission:a` 与 `l1:permission:b` 共用一轮 `alkaid:l1:*`。
 * 4. **命中的必须是完整前缀**：同一轮 SCAN 返回的旁支键（如 `alkaid:l1:other`）不得被删。
 * 5. **分块 DEL**：1200 个键拆成 500/500/200 三次，不一次性塞进一条命令。
 *
 * 为什么用替身而不是真 Redis：这组断言要观察「调用序列」（第几次 SCAN、每批 DEL 多少个键），
 * 真 Redis 无法观测这些；真实 Redis 的端到端验证见交付报告里的手工取证输出。
 *
 * @package Tests\Unit\Infrastructure\Permission\Cache
 */
class RedisScanPurgerTest extends TestCase
{
    /**
     * 缓存驱动前缀（与 config/cache.php 的 `stores.redis.prefix` 一致）
     */
    private const PHYSICAL_PREFIX = 'alkaid:';

    /**
     * 场景 1：多页 SCAN 走完，只删完整前缀命中的键
     */
    public function testIteratesAllScanPagesAndDeletesOnlyFullPrefixMatches(): void
    {
        $handler = new FakeRedisScanHandler([
            [
                self::PHYSICAL_PREFIX . 'permission:tenant:1:user:2:check:aaa',
                self::PHYSICAL_PREFIX . 'permission:tenant:1:user:3:check:bbb', // 其它用户，不该删
            ],
            [
                self::PHYSICAL_PREFIX . 'permission:tenant:1:user:2:check:ccc',
                self::PHYSICAL_PREFIX . 'session:should-not-be-touched',
            ],
            [
                self::PHYSICAL_PREFIX . 'permission:tenant:1:user:2:check:ddd',
            ],
        ]);

        $purger = new RedisScanPurger($handler, self::PHYSICAL_PREFIX);
        $deleted = $purger->purge(['permission:tenant:1:user:2:check:']);

        $this->assertSame(3, $deleted, '三页中命中 user:2 的键应全部删除');
        $this->assertSame(
            [
                self::PHYSICAL_PREFIX . 'permission:tenant:1:user:2:check:aaa',
                self::PHYSICAL_PREFIX . 'permission:tenant:1:user:2:check:ccc',
                self::PHYSICAL_PREFIX . 'permission:tenant:1:user:2:check:ddd',
            ],
            $handler->allDeletedKeys(),
            '只应删除完整前缀命中的键'
        );
        $this->assertNotContains(
            self::PHYSICAL_PREFIX . 'session:should-not-be-touched',
            $handler->allDeletedKeys(),
            'session 键绝不能被删'
        );
        $this->assertNotContains(
            self::PHYSICAL_PREFIX . 'permission:tenant:1:user:3:check:bbb',
            $handler->allDeletedKeys(),
            '其它用户的键不该被删'
        );
    }

    /**
     * 场景 2：只用 SCAN + DEL，从不出现 KEYS / FLUSHDB
     */
    public function testUsesScanAndDelOnly(): void
    {
        $handler = new FakeRedisScanHandler([
            [self::PHYSICAL_PREFIX . 'l1:permission:tenant:1:user:2:permissions'],
        ]);

        $purger = new RedisScanPurger($handler, self::PHYSICAL_PREFIX);
        $purger->purge(['l1:permission:']);

        $this->assertSame(
            [self::PHYSICAL_PREFIX . 'l1:*'],
            $handler->scanPatterns,
            'SCAN 模式必须带驱动物理前缀，否则匹配不到真实键'
        );
        $this->assertFalse(method_exists($handler, 'keys'), '替身不提供 keys()：调用即致命错误');
        $this->assertFalse(method_exists($handler, 'flushDb'), '替身不提供 flushDb()：调用即致命错误');
    }

    /**
     * 场景 3：同组前缀只扫一轮（1000 个用户不该产生 1000 轮 SCAN）
     */
    public function testGroupsPrefixesByFirstSegmentToReduceScanPasses(): void
    {
        $handler = new FakeRedisScanHandler([
            [
                self::PHYSICAL_PREFIX . 'permission:tenant:1:user:2:check:aaa',
                self::PHYSICAL_PREFIX . 'casbin:check:2:1:dashboard:view',
                self::PHYSICAL_PREFIX . 'l1:permission:tenant:1:user:2:permissions',
            ],
        ]);

        $purger = new RedisScanPurger($handler, self::PHYSICAL_PREFIX);
        $deleted = $purger->purge([
            'permission:tenant:1:user:2:check:',
            'permission:tenant:1:user:5:check:',
            'permission:tenant:1:user:9:check:',
            'casbin:check:2:1:',
            'l1:permission:',
        ]);

        $this->assertSame(3, $deleted);
        $this->assertSame(
            [
                self::PHYSICAL_PREFIX . 'permission:*',
                self::PHYSICAL_PREFIX . 'casbin:*',
                self::PHYSICAL_PREFIX . 'l1:*',
            ],
            $handler->scanPatterns,
            '三个 permission 前缀必须共用一个 permission:* 扫描轮次'
        );
    }

    /**
     * 场景 4：批量 DEL 分块（默认 500/块）
     */
    public function testDeletesInChunks(): void
    {
        $keys = [];
        for ($i = 0; $i < 1200; $i++) {
            $keys[] = self::PHYSICAL_PREFIX . 'permission:tenant:1:user:2:check:' . $i;
        }

        $handler = new FakeRedisScanHandler([$keys]);
        $purger = new RedisScanPurger($handler, self::PHYSICAL_PREFIX, 500, 500);
        $deleted = $purger->purge(['permission:tenant:1:user:2:check:']);

        $this->assertSame(1200, $deleted);
        $this->assertSame(
            [500, 500, 200],
            array_map('count', $handler->deleteCalls),
            'DEL 必须分块，不能一次性塞 1200 个键'
        );
    }

    /**
     * 场景 5：空前缀清单是空操作（不发任何命令）
     */
    public function testEmptyPrefixListIsNoop(): void
    {
        $handler = new FakeRedisScanHandler([]);
        $purger = new RedisScanPurger($handler, self::PHYSICAL_PREFIX);

        $this->assertSame(0, $purger->purge([]));
        $this->assertSame([], $handler->scanPatterns);
        $this->assertSame([], $handler->deleteCalls);
    }

    /**
     * 场景 6：SCAN 不返回匹配键（返回 false）但游标未归零时继续迭代
     *
     * phpredis 的 `scan()` 在「本轮无匹配」时返回 false，这**不代表**迭代结束。
     * 若实现里把 false 当结束，就会漏掉后面的页 —— 这条就是防这个。
     */
    public function testContinuesWhenScanReturnsFalseButCursorNotZero(): void
    {
        $handler = new FakeRedisScanHandler([
            [], // 本轮无匹配
            [
                self::PHYSICAL_PREFIX . 'permission:tenant:1:user:2:check:zzz',
            ],
        ]);

        $purger = new RedisScanPurger($handler, self::PHYSICAL_PREFIX);
        $deleted = $purger->purge(['permission:tenant:1:user:2:check:']);

        $this->assertSame(1, $deleted, 'false 只是「本轮空」，不能当作迭代结束');
    }

    /**
     * 场景 7：游标异常（驱动不回填游标）时必须终止，而不是死循环
     */
    public function testBreaksWhenDriverDoesNotAdvanceCursor(): void
    {
        $handler = new class () {
            public int $calls = 0;

            public function scan(&$cursor, string $pattern, int $count = 0)
            {
                $this->calls++;
                // 刻意不回填 $cursor（保持 null）

                return [];
            }

            public function del(array $keys): int
            {
                return count($keys);
            }
        };

        $purger = new RedisScanPurger($handler, self::PHYSICAL_PREFIX);
        $this->assertSame(0, $purger->purge(['permission:']));
        $this->assertSame(1, $handler->calls, '游标不动时必须只扫一轮就退出');
    }

    /**
     * 场景 8：迭代次数超过上限时抛异常（防御游标异常导致的不收敛）
     */
    public function testThrowsWhenScanNeverConverges(): void
    {
        $handler = new class () {
            public function scan(&$cursor, string $pattern, int $count = 0)
            {
                // 永远返回同一个非零游标
                $cursor = 1;

                return [];
            }

            public function del(array $keys): int
            {
                return count($keys);
            }
        };

        $purger = new RedisScanPurger($handler, self::PHYSICAL_PREFIX, 500, 500, 5);

        $this->expectException(RuntimeException::class);
        $purger->purge(['permission:']);
    }
}

/**
 * 只实现 `scan()` / `del()` 的 Redis 替身
 *
 * `$cursor` 用整型页号模拟真实游标：初始 null → 第 0 页，返回 0 表示迭代结束。
 * 刻意**不提供** `keys()` / `flushDb()`：一旦被测代码调用它们，测试会直接致命失败。
 */
final class FakeRedisScanHandler
{
    /**
     * 已发生的 SCAN 模式（按调用顺序）
     *
     * @var array<string>
     */
    public array $scanPatterns = [];

    /**
     * 已发生的 DEL 分块
     *
     * @var array<int, array<string>>
     */
    public array $deleteCalls = [];

    /**
     * 每页返回的键
     *
     * @var array<int, array<string>>
     */
    private array $pages;

    /**
     * @param array<int, array<string>> $pages
     */
    public function __construct(array $pages)
    {
        $this->pages = array_values($pages);
    }

    /**
     * 模拟 `\Redis::scan()`
     *
     * @param mixed $cursor 游标（引用）
     * @param string $pattern 匹配模式
     * @param int $count COUNT 建议值
     * @return array<int, string>|false
     */
    public function scan(&$cursor, string $pattern, int $count = 0)
    {
        $this->scanPatterns[] = $pattern;

        $index = $cursor === null ? 0 : (int) $cursor;
        $keys = $this->pages[$index] ?? [];
        $next = $index + 1;

        $cursor = isset($this->pages[$next]) ? $next : 0;

        return $keys === [] ? false : $keys;
    }

    /**
     * 模拟 `\Redis::del(array $keys)`
     *
     * @param array<string> $keys
     * @return int
     */
    public function del(array $keys): int
    {
        $this->deleteCalls[] = array_values($keys);

        return count($keys);
    }

    /**
     * 展平所有已删除的键
     *
     * @return array<string>
     */
    public function allDeletedKeys(): array
    {
        $all = [];
        foreach ($this->deleteCalls as $chunk) {
            foreach ($chunk as $key) {
                $all[] = $key;
            }
        }

        return $all;
    }
}
