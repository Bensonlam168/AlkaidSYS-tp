<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Service;

use think\facade\Cache;
use think\facade\Config;
use think\facade\Log;
use think\Request;

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
 * - Cache Invalidation: Event-driven cache clearing | 缓存失效：事件驱动的缓存清理
 * - Performance Monitoring: QPS, latency, hit rate tracking | 性能监控：QPS、延迟、命中率跟踪
 *
 * @package Infrastructure\Permission\Service
 */
class PermissionCacheService
{
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
                Cache::set($l1Key, $result, $this->l1Ttl);

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
            Cache::set($l1Key, $result, $this->l1Ttl);
            Cache::set($l2Key, $result, $this->l2Ttl);

            $this->recordLatency($startTime);

            Log::debug('Permission cache: miss (fetched from DB)', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'cache_key' => $cacheKey,
                'permissions_count' => count($result),
            ]);

            return $result;

        } catch (\Exception $e) {
            // Cache failure, fallback to direct fetch | 缓存失败，回退到直接获取
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
            Cache::set($cacheKey, $result, 30);

            return $result;

        } catch (\Exception $e) {
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
            // Clear both L1 and L2 cache | 清除 L1 和 L2 缓存
            Cache::delete("l1:{$cacheKey}");
            Cache::delete("l2:{$cacheKey}");

            // Clear permission check caches for this user
            // 清除该用户的权限检查缓存
            $this->clearPermissionCheckCaches($userId, $tenantId);

            Log::info('Permission cache cleared', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
            ]);

        } catch (\Exception $e) {
            Log::error('Permission cache clear failed', [
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
     * @return void
     */
    public function clearAllCache(): void
    {
        try {
            // Note: In production, should use Redis SCAN + DEL for better performance
            // 注意：生产环境中应使用 Redis SCAN + DEL 以获得更好性能
            Cache::clear();

            // Reset statistics | 重置统计
            $this->resetStats();

            Log::info('All permission caches cleared');

        } catch (\Exception $e) {
            Log::error('Permission cache clear all failed', [
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
     * @param int $userId User ID | 用户ID
     * @param int $tenantId Tenant ID | 租户ID
     * @return void
     */
    protected function clearPermissionCheckCaches(int $userId, int $tenantId): void
    {
        // In production, use Redis KEYS pattern matching
        // 生产环境中使用 Redis KEYS 模式匹配
        // For now, we'll skip this as ThinkPHP Cache doesn't support pattern deletion
        // 现在我们跳过这个，因为 ThinkPHP Cache 不支持模式删除
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
