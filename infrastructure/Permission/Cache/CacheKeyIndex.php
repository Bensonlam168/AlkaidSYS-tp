<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Cache;

use think\facade\Cache;
use think\facade\Log;

/**
 * 权限缓存键索引（仅用于「无法按前缀枚举」的缓存驱动）
 * Permission cache key index (only for drivers that cannot enumerate by prefix)
 *
 * ## 为什么需要它
 *
 * Redis 可以用 `SCAN` 按前缀枚举键，但 **File 驱动做不到**：
 * `think\cache\driver\File::getCacheKey()` 把键名 `md5()` 后落到
 * `runtime/cache/{前2位}/{后30位}.php`（已实测，见报告），
 * 文件名与逻辑键之间**不可逆**，所以「按目录 glob 匹配前缀」这条路根本走不通。
 *
 * 于是对这类驱动，只能把「我们写过哪些键」记下来 —— 这就是索引。
 * 索引本身存为一条普通缓存（键名 `__perm_cache_index`，刻意**不**落在
 * `permission:` / `casbin:` 前缀族内，避免被前缀清除误伤或误判）。
 *
 * ## 代价与边界
 *
 * - 每次权限缓存写入多一次索引读 + 一次索引写（仅 File / 内存等不可枚举驱动；
 *   Redis 走 SCAN，`PermissionCachePurger::trackKey()` 直接短路，零额外开销）；
 * - 索引有 TTL：`permission.cache.l2_ttl` 远小于索引 TTL，索引一旦过期，
 *   说明期间没有任何权限缓存写入，也就没有需要清理的活键；
 * - 索引有条数上限：超出后丢弃最旧的记录并记 warning（宁可少记，不可无限增长），
 *   被丢弃的键只能等 TTL 自然过期 —— 这是**已知降级**，已在安全指南中登记。
 *
 * @package Infrastructure\Permission\Cache
 */
final class CacheKeyIndex
{
    /**
     * 索引在缓存中的键名
     */
    public const INDEX_KEY = '__perm_cache_index';

    /**
     * 索引 TTL（秒）：远大于 L2 TTL，留足「写入后又被清理」的时间窗
     */
    private const INDEX_TTL = 3600;

    /**
     * 索引条目上限（超出后丢弃最旧条目并记 warning）
     */
    private const MAX_TRACKED_KEYS = 50000;

    /**
     * 读取全部已记录的缓存键
     *
     * @return array<string>
     */
    public function all(): array
    {
        try {
            $keys = Cache::get(self::INDEX_KEY);
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($keys)) {
            return [];
        }

        $normalized = [];
        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
                $normalized[] = $key;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * 记录一批已写入的缓存键
     *
     * @param array<string> $keys 逻辑键（不含驱动前缀）
     * @return void
     */
    public function record(array $keys): void
    {
        $keys = array_values(array_unique(array_filter($keys, static fn ($key) => is_string($key) && $key !== '')));
        if ($keys === []) {
            return;
        }

        // 用 array_flip 去重合并：复杂度 O(现有 + 新增)，避免 array_merge + array_unique 的二次扫描
        $merged = array_flip($this->all());
        foreach ($keys as $key) {
            $merged[$key] = true;
        }
        $merged = array_keys($merged);

        if (count($merged) > self::MAX_TRACKED_KEYS) {
            $dropped = count($merged) - self::MAX_TRACKED_KEYS;
            // 保新弃旧：最近写入的键更可能仍存活（旧键多半已过 TTL）
            $merged = array_slice($merged, -self::MAX_TRACKED_KEYS);

            Log::warning('权限缓存键索引超出上限，已丢弃最旧条目（该部分键只能等 TTL 过期）', [
                'max_tracked_keys' => self::MAX_TRACKED_KEYS,
                'dropped' => $dropped,
            ]);
        }

        Cache::set(self::INDEX_KEY, $merged, self::INDEX_TTL);
    }

    /**
     * 从索引中移除一批键（删除缓存后同步收缩索引）
     *
     * @param array<string> $keys
     * @return void
     */
    public function remove(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $current = $this->all();
        if ($current === []) {
            return;
        }

        // 同样是 O(现有 + 待删)：array_diff 在 4000 × 4000 时会退化成千万级比较
        $flipped = array_flip($current);
        $changed = false;
        foreach ($keys as $key) {
            if (isset($flipped[$key])) {
                unset($flipped[$key]);
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        if ($flipped === []) {
            Cache::delete(self::INDEX_KEY);
            return;
        }

        Cache::set(self::INDEX_KEY, array_keys($flipped), self::INDEX_TTL);
    }

    /**
     * 清空索引
     *
     * @return void
     */
    public function clear(): void
    {
        Cache::delete(self::INDEX_KEY);
    }
}
