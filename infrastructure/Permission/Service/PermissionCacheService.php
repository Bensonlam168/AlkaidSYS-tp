<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Service;

use Infrastructure\Permission\Cache\PermissionCachePurger;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Log;

/**
 * Permission Cache Service | 权限缓存服务
 *
 * Provides multi-level caching for permission queries with tenant isolation.
 * 为权限查询提供多级缓存，支持租户隔离。
 *
 * Features | 功能特性:
 * - L1 Cache: Application memory cache (60s) | L1 缓存：应用内存缓存（60秒）
 * - L2 Cache: Redis cache (300s) | L2 缓存：Redis 缓存（300秒）
 * - Tenant Isolation: Cache keys with tenant_id prefix | 租户隔离：缓存键带 tenant_id 前缀
 * - Scoped Invalidation: prefix-whitelisted purge (never `Cache::clear()`) | 作用域失效：按白名单前缀清除（不再 `Cache::clear()`）
 * - Performance Monitoring: QPS, latency, hit rate tracking | 性能监控：QPS、延迟、命中率跟踪
 *
 * ## 缓存键与 TTL（失效策略的依据）
 *
 * | 键 | TTL | 说明 |
 * | --- | --- | --- |
 * | `l1:permission:tenant:{t}:user:{u}:permissions` | 60s | L1 权限集 |
 * | `l2:permission:tenant:{t}:user:{u}:permissions` | 300s | L2 权限集 |
 * | `permission:tenant:{t}:user:{u}:check:{md5(code)}` | 30s | 单权限检查结果 |
 *
 * 三个族都含 `permission:` 段，而 `session:` / 限流 `rl:` 不含 ——
 * 这条结构性差异就是「清除权限缓存不会误伤会话」的安全边界（R1 修复）。
 *
 * @package Infrastructure\Permission\Service
 */
class PermissionCacheService
{
    /**
     * 前缀清除器（驱动自适应，懒加载）
     */
    protected ?PermissionCachePurger $purger = null;

    /**
     * L1 Cache TTL (seconds) | L1 缓存生存时间（秒）
     */
    protected int $l1Ttl = 60;

    /**
     * L2 Cache TTL (seconds) | L2 缓存生存时间（秒）
     */
    protected int $l2Ttl = 300;

    /**
     * Cache statistics | 缓存统计
     */
    protected array $stats = [
        'l1_hits' => 0,
        'l1_misses' => 0,
        'l2_hits' => 0,
        'l2_misses' => 0,
        'total_queries' => 0,
        'avg_latency_ms' => 0,
    ];

    /**
     * Latency tracking for P95 calculation | 延迟跟踪用于 P95 计算
     */
    protected array $latencyHistory = [];

    /**
     * Maximum latency history size | 最大延迟历史记录大小
     */
    protected int $maxLatencyHistory = 1000;

    /**
     * Constructor | 构造函数
     */
    public function __construct()
    {
        $this->l1Ttl = (int) Config::get('permission.cache.l1_ttl', 60);
        $this->l2Ttl = (int) Config::get('permission.cache.l2_ttl', 300);
    }

    /**
     * Get user permissions from cache (multi-level) | 从缓存获取用户权限（多级）
     *
     * @param int $userId User ID | 用户ID
     * @param int $tenantId Tenant ID | 租户ID
     * @param callable $fetcher Data fetcher callback | 数据获取回调
     * @return array User permissions | 用户权限
     */
    public function getUserPermissions(int $userId, int $tenantId, callable $fetcher): array
    {
        $startTime = microtime(true);
        $traceId = request()->header('X-Trace-Id') ?: uniqid('perm_', true);
        $cacheKey = $this->getCacheKey($userId, $tenantId);

        try {
            // Try L1 cache first | 首先尝试 L1 缓存
            $l1Key = "l1:{$cacheKey}";
            $result = Cache::get($l1Key);

            if ($result !== null) {
                // L1 cache hit | L1 缓存命中
                $this->stats['l1_hits']++;
                $this->recordLatency($startTime);

                Log::debug('Permission cache: L1 hit', [
                    'trace_id' => $traceId,
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'cache_key' => $cacheKey,
                ]);

                return $result;
            }

            // L1 cache miss, try L2 cache | L1 缓存未命中，尝试 L2 缓存
            $this->stats['l1_misses']++;
            $l2Key = "l2:{$cacheKey}";
            $result = Cache::get($l2Key);

            if ($result !== null) {
                // L2 cache hit | L2 缓存命中
                $this->stats['l2_hits']++;

                // Promote to L1 cache | 提升到 L1 缓存
                $this->writeCache($l1Key, $result, $this->l1Ttl);

                $this->recordLatency($startTime);

                Log::debug('Permission cache: L2 hit', [
                    'trace_id' => $traceId,
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'cache_key' => $cacheKey,
                ]);

                return $result;
            }

            // L2 cache miss, fetch from database | L2 缓存未命中，从数据库获取
            $this->stats['l2_misses']++;
            $this->stats['total_queries']++;

            $result = $fetcher();

            // Store in both L1 and L2 caches | 存储到 L1 和 L2 缓存
            $this->writeCache($l1Key, $result, $this->l1Ttl);
            $this->writeCache($l2Key, $result, $this->l2Ttl);

            $this->recordLatency($startTime);

            Log::debug('Permission cache: miss (fetched from DB)', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'cache_key' => $cacheKey,
                'permissions_count' => count($result),
            ]);

            return $result;

        } catch (\Throwable $e) {
            // Cache failure, fallback to direct fetch | 缓存失败，回退到直接获取
            //
            // 【为什么捕 \Throwable 而不是 \Exception】（R4）
            //   这里捕获的是**缓存旁路**的失败：L1/L2 读写、键登记（PermissionCachePurger::trackKey）等。
            //   这类失败与「用户到底有哪些权限」无关，正确语义就是「缓存不可用 → 直查 DB」。
            //   而 \TypeError / \Error（例如 trackKey 收到非字符串键、驱动的返回值类型不符）
            //   在 PHP 里不继承 \Exception —— 只捕 \Exception 会让它们穿透这里，
            //   把「本应降级」的缓存故障升级为 HTTP 500（R3 实测过的放大器路径）。
            //   注意：真正决定授权结论的 $fetcher() 若抛 \Error，这里的 catch 仍会再调用一次，
            //   第二次调用在 try 之外，异常照常上抛，不会被吞。
            $this->recordLatency($startTime);

            Log::error('Permission cache failure', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            // Return fetched data | 返回获取的数据
            return $fetcher();
        }
    }

    /**
     * Check if user has permission (with caching) | 检查用户是否有权限（带缓存）
     *
     * @param int $userId User ID | 用户ID
     * @param int $tenantId Tenant ID | 租户ID
     * @param string $permissionCode Permission code | 权限码
     * @param callable $checker Permission checker callback | 权限检查回调
     * @return bool Whether has permission | 是否有权限
     */
    public function hasPermission(int $userId, int $tenantId, string $permissionCode, callable $checker): bool
    {
        // For single permission checks, we can cache the result briefly
        // 对于单个权限检查，我们可以短暂缓存结果
        $cacheKey = $this->getPermissionCheckCacheKey($userId, $tenantId, $permissionCode);

        try {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return (bool) $cached;
            }

            $result = $checker();

            // Cache for a shorter time (30s) for permission checks
            // 权限检查缓存时间较短（30秒）
            $this->writeCache($cacheKey, $result, 30);

            return $result;

        } catch (\Throwable $e) {
            // 【为什么捕 \Throwable】（R4）同上：这里是「检查结果缓存」这一旁路的失败，
            // 语义是「缓存不可用 → 回退到直接计算」，与授权结论本身无关。
            // \Error / \TypeError 不继承 \Exception，只捕 \Exception 会让缓存侧的类型错误
            // 穿透成 500，而它本应只让这次请求失去缓存加速。
            // 回退语句 `return $checker();` 位于 try 之外：核心判定若真的抛 \Error，
            // 第二次调用会把异常原样抛给调用方，不会变成「静默放行/静默拒绝」。
            Log::error('Permission check cache failure', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'permission_code' => $permissionCode,
                'error' => $e->getMessage(),
            ]);

            // Fallback to direct check | 回退到直接检查
            return $checker();
        }
    }

    /**
     * Clear user permission cache | 清除用户权限缓存
     *
     * @param int $userId User ID | 用户ID
     * @param int $tenantId Tenant ID | 租户ID
     * @return void
     */
    public function clearUserCache(int $userId, int $tenantId): void
    {
        $traceId = request()->header('X-Trace-Id') ?: uniqid('perm_', true);
        $cacheKey = $this->getCacheKey($userId, $tenantId);

        try {
            // Clear both L1 and L2 cache (exact keys) | 精确清除 L1 和 L2 缓存
            $this->purger()->deleteKeys([
                "l1:{$cacheKey}",
                "l2:{$cacheKey}",
            ]);

            // Clear permission check caches for this user | 清除该用户的权限检查缓存
            $this->clearPermissionCheckCaches($userId, $tenantId);

            Log::info('Permission cache cleared', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
            ]);

        } catch (\Throwable $e) {
            // 缓存失效是尽力而为：失败只记 warning，不向业务抛
            // Cache invalidation is best-effort: log a warning instead of throwing
            Log::warning('Permission cache clear failed', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear all permission caches | 清除所有权限缓存
     *
     * R1 修复：只删白名单前缀（`l1:permission:` / `l2:permission:` / `permission:`）内的键。
     * 此前这里是 `Cache::clear()`，在 Redis 驱动下等价于 `FLUSHDB`，会把
     * **整个库**（含 `session:*` 会话、限流计数）一起清掉。
     *
     * 驱动适配见 {@see PermissionCachePurger}：Redis 走 `SCAN` + 批量 `DEL`，
     * 其它驱动走键索引精确删除；**任何情况都不退化为 `Cache::clear()`**。
     *
     * @return void
     */
    public function clearAllCache(): void
    {
        $traceId = request()->header('X-Trace-Id') ?: uniqid('perm_', true);

        try {
            $report = $this->purger()->purge([
                PermissionCachePurger::PREFIX_L1_PERMISSION,
                PermissionCachePurger::PREFIX_L2_PERMISSION,
                PermissionCachePurger::PREFIX_PERMISSION,
            ]);

            // Reset statistics | 重置统计
            $this->resetStats();

            Log::info('All permission caches cleared (prefix-scoped)', [
                'trace_id' => $traceId,
                'driver' => $report['driver'] ?? null,
                'strategy' => $report['strategy'] ?? null,
                'deleted' => $report['deleted'] ?? 0,
            ]);

        } catch (\Throwable $e) {
            // 失败只记 warning：这是「清缓存」，不该让调用方（管理接口）500
            Log::warning('Permission cache clear all failed', [
                'trace_id' => $traceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get cache statistics | 获取缓存统计信息
     *
     * @return array Cache statistics | 缓存统计信息
     */
    public function getCacheStats(): array
    {
        $total = $this->stats['l1_hits'] + $this->stats['l1_misses'];
        $l1HitRate = $total > 0 ? round(($this->stats['l1_hits'] / $total) * 100, 2) : 0;

        $l2Total = $this->stats['l2_hits'] + $this->stats['l2_misses'];
        $l2HitRate = $l2Total > 0 ? round(($this->stats['l2_hits'] / $l2Total) * 100, 2) : 0;

        $overallTotal = $this->stats['l1_hits'] + $this->stats['l2_hits'] + $this->stats['l1_misses'] + $this->stats['l2_misses'];
        $overallHitRate = $overallTotal > 0
            ? round((($this->stats['l1_hits'] + $this->stats['l2_hits']) / $overallTotal) * 100, 2)
            : 0;

        $p95Latency = $this->calculateP95Latency();

        return [
            'l1_cache' => [
                'hits' => $this->stats['l1_hits'],
                'misses' => $this->stats['l1_misses'],
                'hit_rate' => $l1HitRate,
                'ttl' => $this->l1Ttl,
            ],
            'l2_cache' => [
                'hits' => $this->stats['l2_hits'],
                'misses' => $this->stats['l2_misses'],
                'hit_rate' => $l2HitRate,
                'ttl' => $this->l2Ttl,
            ],
            'overall' => [
                'total_queries' => $this->stats['total_queries'],
                'overall_hit_rate' => $overallHitRate,
                'avg_latency_ms' => round($this->stats['avg_latency_ms'], 2),
                'p95_latency_ms' => round($p95Latency, 2),
            ],
        ];
    }

    /**
     * Generate cache key for user permissions | 生成用户权限缓存键
     *
     * @param int $userId User ID | 用户ID
     * @param int $tenantId Tenant ID | 租户ID
     * @return string Cache key | 缓存键
     */
    protected function getCacheKey(int $userId, int $tenantId): string
    {
        // Tenant-isolated cache key | 租户隔离的缓存键
        return "permission:tenant:{$tenantId}:user:{$userId}:permissions";
    }

    /**
     * Generate cache key for permission check | 生成权限检查缓存键
     *
     * @param int $userId User ID | 用户ID
     * @param int $tenantId Tenant ID | 租户ID
     * @param string $permissionCode Permission code | 权限码
     * @return string Cache key | 缓存键
     */
    protected function getPermissionCheckCacheKey(int $userId, int $tenantId, string $permissionCode): string
    {
        return "permission:tenant:{$tenantId}:user:{$userId}:check:" . md5($permissionCode);
    }

    /**
     * Clear permission check caches for user | 清除用户权限检查缓存
     *
     * 检查缓存的键尾巴是 `md5(权限码)`，无法逐一枚举，因此按「用户级前缀」清除：
     * `permission:tenant:{t}:user:{u}:check:`。
     *
     * 历史实现这里是**空方法**（注释写着「ThinkPHP Cache 不支持模式删除」就跳过了），
     * 于是单个用户的检查缓存会一直活到 30s TTL —— 撤权后仍能命中「有权限」。
     *
     * @param int $userId User ID | 用户ID
     * @param int $tenantId Tenant ID | 租户ID
     * @return void
     */
    protected function clearPermissionCheckCaches(int $userId, int $tenantId): void
    {
        $this->purger()->purge([
            "permission:tenant:{$tenantId}:user:{$userId}:check:",
        ]);
    }

    /**
     * 写入缓存并登记键（供不可枚举驱动按前缀清理）
     *
     * @param string $key 逻辑缓存键
     * @param mixed $value 缓存值
     * @param int $ttl 生存时间（秒）
     * @return void
     */
    protected function writeCache(string $key, mixed $value, int $ttl): void
    {
        Cache::set($key, $value, $ttl);
        $this->purger()->trackKey($key);
    }

    /**
     * 取前缀清除器（懒加载）
     *
     * @return PermissionCachePurger
     */
    protected function purger(): PermissionCachePurger
    {
        if ($this->purger === null) {
            $this->purger = new PermissionCachePurger();
        }

        return $this->purger;
    }

    /**
     * Record latency for statistics | 记录延迟用于统计
     *
     * @param float $startTime Start time | 开始时间
     * @return void
     */
    protected function recordLatency(float $startTime): void
    {
        $latency = (microtime(true) - $startTime) * 1000; // Convert to ms | 转换为毫秒

        // Update average latency | 更新平均延迟
        $total = $this->stats['l1_hits'] + $this->stats['l1_misses'] + $this->stats['l2_hits'] + $this->stats['l2_misses'];
        $this->stats['avg_latency_ms'] = ($this->stats['avg_latency_ms'] * ($total - 1) + $latency) / $total;

        // Store latency for P95 calculation | 存储延迟用于 P95 计算
        $this->latencyHistory[] = $latency;
        if (count($this->latencyHistory) > $this->maxLatencyHistory) {
            array_shift($this->latencyHistory);
        }
    }

    /**
     * Calculate P95 latency | 计算 P95 延迟
     *
     * @return float P95 latency in ms | P95 延迟（毫秒）
     */
    protected function calculateP95Latency(): float
    {
        if (empty($this->latencyHistory)) {
            return 0;
        }

        sort($this->latencyHistory);
        $index = (int) floor(count($this->latencyHistory) * 0.95);
        return $this->latencyHistory[$index] ?? 0;
    }

    /**
     * Reset cache statistics | 重置缓存统计
     *
     * @return void
     */
    public function resetStats(): void
    {
        $this->stats = [
            'l1_hits' => 0,
            'l1_misses' => 0,
            'l2_hits' => 0,
            'l2_misses' => 0,
            'total_queries' => 0,
            'avg_latency_ms' => 0,
        ];
        $this->latencyHistory = [];
    }
}
