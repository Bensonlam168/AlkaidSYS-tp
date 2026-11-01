<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Cache;

use RuntimeException;

/**
 * Redis 前缀清除器（SCAN 游标迭代 + 批量 DEL）
 * Redis prefix purger (SCAN cursor iteration + batched DEL)
 *
 * ## 为什么不能用 `KEYS` / `FLUSHDB`
 *
 * - `KEYS pattern` 是 O(N) 且**阻塞**单线程 Redis：库里有几十万键时会卡住整个实例，
 *   生产上等同于一次自我 DoS。
 * - `FLUSHDB`（即 ThinkPHP Redis 驱动的 `clear()`）清的是**整个库**，
 *   而 `config/session.php` 的 `store = redis`、限流键（`rl:*`）与缓存**同库**，
 *   于是一次「清权限缓存」会顺手踢掉全部在线会话与限流计数（R1 缺陷）。
 *
 * 因此这里只用 `SCAN`：游标式、非阻塞、允许漏/重（本场景只删不读，重删无害）。
 *
 * ## 键名形态（已实测，见报告）
 *
 * `Cache::set('l1:permission:...')` 落到 Redis 的真实键名是
 * `alkaid:l1:permission:...` —— 驱动 `config/cache.php` 的 `prefix = 'alkaid:'`
 * 会**再加一层前缀**（`think\cache\Driver::getCacheKey()` = `options['prefix'] . $name`）。
 * 所以 SCAN 的 MATCH 模式必须拼接 `getCacheKey('')` 得到的物理前缀，否则一个键都匹配不到。
 *
 * ## 只按「第一段」分组扫描
 *
 * 传入的前缀可能是 `permission:tenant:1:user:2:check:` 这类「带参数尾巴」的精确前缀。
 * 若每个前缀单独 SCAN 一轮，1000 个用户就是 1000 轮；这里先按首个 `:` 之前的段
 * （`l1` / `l2` / `permission` / `casbin`）分组，同组只扫一轮 `alkaid:{段}:*`，
 * 再在 PHP 侧用完整前缀逐键判定，既减少往返又不放大删除范围。
 *
 * @package Infrastructure\Permission\Cache
 */
final class RedisScanPurger
{
    /**
     * 底层 Redis 客户端（`\Redis` 或同签名对象）
     *
     * 用 `object` 而不是 `\Redis` 是刻意的：单元测试需要一个只实现 `scan()` / `del()`
     * 的替身来断言「只用了 SCAN、没有 KEYS/flushDB、分块 DEL 正确」，
     * 而伪造一个完整的 `\Redis` 子类既脆弱又不必要。
     *
     * @var object
     */
    private object $handler;

    /**
     * 缓存驱动前缀（物理前缀），如 `alkaid:`
     */
    private string $physicalPrefix;

    /**
     * SCAN 每轮建议返回条数（Redis 的 COUNT 是提示值，不是硬上限）
     */
    private int $scanCount;

    /**
     * 单次 DEL 携带的键数量上限（避免一次 DEL 参数过多）
     */
    private int $deleteChunkSize;

    /**
     * SCAN 轮次上限：防御游标异常导致的死循环
     */
    private int $maxScanIterations;

    /**
     * @param object $handler 底层 Redis 客户端（需提供 `scan(&$cursor, $pattern, $count)` 与 `del(array)`）
     * @param string $physicalPrefix 缓存驱动前缀，取 `$store->getCacheKey('')`
     * @param int $scanCount SCAN COUNT 建议值
     * @param int $deleteChunkSize 批量 DEL 分块大小
     * @param int $maxScanIterations SCAN 轮次上限
     */
    public function __construct(
        object $handler,
        string $physicalPrefix,
        int $scanCount = 500,
        int $deleteChunkSize = 500,
        int $maxScanIterations = 100000
    ) {
        $this->handler = $handler;
        $this->physicalPrefix = $physicalPrefix;
        $this->scanCount = max(1, $scanCount);
        $this->deleteChunkSize = max(1, $deleteChunkSize);
        $this->maxScanIterations = max(1, $maxScanIterations);
    }

    /**
     * 按逻辑前缀清除缓存
     * Purge cache by logical prefixes
     *
     * 调用方（`PermissionCachePurger`）必须已完成白名单校验：本类不再判断前缀是否安全，
     * 它只负责「按给定前缀扫描并删除」。
     *
     * @param array<string> $logicalPrefixes 已通过白名单校验的逻辑前缀（不含驱动前缀）
     * @return int 实际删除的键数量
     */
    public function purge(array $logicalPrefixes): int
    {
        if ($logicalPrefixes === []) {
            return 0;
        }

        // 按首个 `:` 之前的段分组：同组共用一轮 SCAN
        // Group by the segment before the first `:` so one SCAN pass serves the whole group
        $groups = [];
        foreach ($logicalPrefixes as $prefix) {
            $segment = $this->firstSegment($prefix);
            $groups[$segment][] = $this->physicalPrefix . $prefix;
        }

        $deleted = 0;
        foreach ($groups as $segment => $fullPrefixes) {
            $deleted += $this->scanAndDelete(
                $this->physicalPrefix . $segment . ':*',
                $fullPrefixes
            );
        }

        return $deleted;
    }

    /**
     * 单轮 SCAN 内按完整前缀过滤并分块删除
     * Scan with one pattern, filter by full prefixes, delete in chunks
     *
     * @param string $pattern SCAN MATCH 模式（含物理前缀）
     * @param array<string> $fullPrefixes 含物理前缀的完整前缀清单
     * @return int
     */
    private function scanAndDelete(string $pattern, array $fullPrefixes): int
    {
        $cursor = null;
        $deleted = 0;
        $iterations = 0;

        while (true) {
            $iterations++;
            if ($iterations > $this->maxScanIterations) {
                throw new RuntimeException(sprintf(
                    'SCAN 迭代次数超过上限 %d，已中止（模式：%s）',
                    $this->maxScanIterations,
                    $pattern
                ));
            }

            // phpredis 语义：返回本轮的键数组；没有匹配时返回 false（**不代表迭代结束**）
            // 游标由引用参数回填，迭代结束时为 0
            $keys = $this->handler->scan($cursor, $pattern, $this->scanCount);

            if (is_array($keys) && $keys !== []) {
                $deleted += $this->deleteChunks($this->filterByPrefixes($keys, $fullPrefixes));
            }

            // 驱动未回填游标（非 php-redis 实现）时按「迭代结束」处理，避免死循环
            if (!is_int($cursor) && !(is_string($cursor) && ctype_digit($cursor))) {
                break;
            }

            if ((int) $cursor === 0) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * 过滤出命中完整前缀的键
     *
     * @param array<string> $keys SCAN 返回的物理键
     * @param array<string> $fullPrefixes 完整前缀清单
     * @return array<string>
     */
    private function filterByPrefixes(array $keys, array $fullPrefixes): array
    {
        $matched = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                continue;
            }

            foreach ($fullPrefixes as $fullPrefix) {
                if (str_starts_with($key, $fullPrefix)) {
                    $matched[] = $key;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * 分块批量 DEL
     *
     * @param array<string> $physicalKeys 物理键名
     * @return int
     */
    private function deleteChunks(array $physicalKeys): int
    {
        if ($physicalKeys === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($physicalKeys, $this->deleteChunkSize) as $chunk) {
            // 传数组：phpredis 与 Predis 都支持，且天然对应一次 DEL 的多键形式
            $deleted += (int) $this->handler->del($chunk);
        }

        return $deleted;
    }

    /**
     * 取逻辑前缀的首段（`permission:tenant:1:...` → `permission`）
     *
     * @param string $prefix
     * @return string
     */
    private function firstSegment(string $prefix): string
    {
        $position = strpos($prefix, ':');

        return $position === false ? $prefix : substr($prefix, 0, $position);
    }
}
