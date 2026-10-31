<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Expression\Cache;

/**
 * Expression Cache | 表达式缓存
 *
 * Caches compiled expressions for better performance.
 * 缓存已编译的表达式以提高性能。
 *
 * @package Alkaid\Workflow\Engine\Expression\Cache
 */
class ExpressionCache
{
    /**
     * Cache storage
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    /**
     * Cache hit count
     *
     * @var int
     */
    private int $hits = 0;

    /**
     * Cache miss count
     *
     * @var int
     */
    private int $misses = 0;

    /**
     * Maximum cache size
     *
     * @var int
     */
    private int $maxSize;

    /**
     * Access order for LRU (most recent at end)
     *
     * @var array<string>
     */
    private array $accessOrder = [];

    /**
     * Constructor
     *
     * @param int $maxSize Maximum cache size (default: 1000)
     */
    public function __construct(int $maxSize = 1000)
    {
        $this->maxSize = $maxSize;
    }

    /**
     * Get cached item | 获取缓存项
     *
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found
     */
    public function get(string $key): mixed
    {
        if ($this->has($key)) {
            $this->hits++;
            $this->updateAccessOrder($key);
            return $this->cache[$key];
        }

        $this->misses++;
        return null;
    }

    /**
     * Check if key exists in cache | 检查键是否存在于缓存中
     *
     * @param string $key Cache key
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->cache);
    }

    /**
     * Set cache item | 设置缓存项
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        // If key already exists, update it
        if ($this->has($key)) {
            $this->cache[$key] = $value;
            $this->updateAccessOrder($key);
            return;
        }

        // Evict if cache is full
        while (count($this->cache) >= $this->maxSize) {
            $this->evictLru();
        }

        $this->cache[$key] = $value;
        $this->accessOrder[] = $key;
    }

    /**
     * Remove cache item | 移除缓存项
     *
     * @param string $key Cache key
     * @return void
     */
    public function remove(string $key): void
    {
        unset($this->cache[$key]);
        $this->accessOrder = array_values(array_filter(
            $this->accessOrder,
            fn ($k) => $k !== $key
        ));
    }

    /**
     * Clear all cache | 清除所有缓存
     *
     * @return void
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->accessOrder = [];
        $this->hits = 0;
        $this->misses = 0;
    }

    /**
     * Delete cache item | 删除缓存项
     *
     * @param string $key Cache key
     * @return void
     */
    public function delete(string $key): void
    {
        $this->remove($key);
    }

    /**
     * Get all cache keys | 获取所有缓存键
     *
     * @return array
     */
    public function getKeys(): array
    {
        return array_keys($this->cache);
    }

    /**
     * Get cache statistics | 获取缓存统计
     *
     * @return array
     */
    public function getStats(): array
    {
        $total = $this->hits + $this->misses;
        $hitRatio = $total > 0 ? $this->hits / $total : 0.0;

        return [
            'size' => count($this->cache),
            'maxSize' => $this->maxSize,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hitRatio' => $hitRatio,
            'memoryUsage' => $this->estimateMemoryUsage(),
        ];
    }

    /**
     * Get cache size | 获取缓存大小
     *
     * @return int
     */
    public function size(): int
    {
        return count($this->cache);
    }

    /**
     * Generate cache key from expression | 从表达式生成缓存键
     *
     * @param string $expression
     * @return string
     */
    public static function generateKey(string $expression): string
    {
        return 'expr_' . md5($expression);
    }

    /**
     * Update access order for LRU | 更新LRU访问顺序
     *
     * @param string $key
     * @return void
     */
    private function updateAccessOrder(string $key): void
    {
        // Remove key from current position
        $this->accessOrder = array_values(array_filter(
            $this->accessOrder,
            fn ($k) => $k !== $key
        ));
        // Add to end (most recently used)
        $this->accessOrder[] = $key;
    }

    /**
     * Evict least recently used entry | 驱逐最近最少使用的条目
     *
     * @return void
     */
    private function evictLru(): void
    {
        if (empty($this->accessOrder)) {
            return;
        }

        // Remove the first entry (least recently used)
        $keyToRemove = array_shift($this->accessOrder);
        unset($this->cache[$keyToRemove]);
    }

    /**
     * Estimate memory usage | 估算内存使用
     *
     * @return int Estimated bytes
     */
    private function estimateMemoryUsage(): int
    {
        $serialized = serialize($this->cache);
        return strlen($serialized);
    }
}
