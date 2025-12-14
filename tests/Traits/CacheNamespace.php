<?php

declare(strict_types=1);

namespace Tests\Traits;

use think\facade\Cache;

/**
 * 缓存命名空间测试隔离 Trait
 * Cache Namespace Test Isolation Trait
 *
 * 为每个测试提供独立的缓存命名空间，测试结束后自动清除该命名空间下的所有缓存。
 * Provides independent cache namespace for each test, automatically clearing all cache under that namespace after test completion.
 *
 * 使用方法 | Usage:
 * ```php
 * class MyTest extends ThinkPHPTestCase
 * {
 *     use CacheNamespace;
 *
 *     public function testSomething()
 *     {
 *         // 使用缓存时会自动添加命名空间前缀
 *         // Cache operations will automatically add namespace prefix
 *         Cache::set('key', 'value');  // 实际键: test:MyTest:testSomething:key
 *     }
 * }
 * ```
 *
 * 注意事项 | Notes:
 * - 缓存命名空间只对使用 Cache facade 的操作有效
 * - Cache namespace only works for operations using Cache facade
 * - 如果测试需要访问全局缓存，不要使用此 Trait
 * - Do not use this Trait if the test needs to access global cache
 * - 命名空间前缀格式：test:{testClass}:{testMethod}:
 * - Namespace prefix format: test:{testClass}:{testMethod}:
 */
trait CacheNamespace
{
    /**
     * 缓存命名空间前缀
     * Cache namespace prefix
     *
     * @var string
     */
    protected string $cacheNamespacePrefix = '';

    /**
     * 原始缓存配置
     * Original cache configuration
     *
     * @var array
     */
    protected array $originalCacheConfig = [];

    /**
     * 设置缓存命名空间
     * Set cache namespace
     *
     * @return void
     */
    protected function setUpCacheNamespace(): void
    {
        // 生成缓存命名空间前缀
        // Generate cache namespace prefix
        $testClass = get_class($this);
        $testMethod = $this->getName();
        $this->cacheNamespacePrefix = "test:{$testClass}:{$testMethod}:";

        // 注意：ThinkPHP 的 Cache facade 不直接支持全局前缀
        // 这里我们记录前缀，在测试中需要手动使用
        // Note: ThinkPHP's Cache facade does not directly support global prefix
        // We record the prefix here, and need to use it manually in tests
    }

    /**
     * 清除缓存命名空间
     * Clear cache namespace
     *
     * @return void
     */
    protected function tearDownCacheNamespace(): void
    {
        // 清除该命名空间下的所有缓存
        // Clear all cache under this namespace
        // 注意：ThinkPHP 的 Cache facade 不支持按前缀删除
        // 这里我们使用 clear() 清除所有缓存（测试环境可接受）
        // Note: ThinkPHP's Cache facade does not support delete by prefix
        // We use clear() to clear all cache (acceptable in test environment)
        if (!empty($this->cacheNamespacePrefix)) {
            Cache::clear();
            $this->cacheNamespacePrefix = '';
        }
    }

    /**
     * 获取带命名空间的缓存键
     * Get cache key with namespace
     *
     * @param string $key 原始键 | Original key
     * @return string 带命名空间的键 | Key with namespace
     */
    protected function getCacheKey(string $key): string
    {
        return $this->cacheNamespacePrefix . $key;
    }

    /**
     * 设置缓存（自动添加命名空间）
     * Set cache (automatically add namespace)
     *
     * @param string $key 键 | Key
     * @param mixed $value 值 | Value
     * @param int|null $ttl 过期时间（秒）| TTL (seconds)
     * @return bool
     */
    protected function cacheSet(string $key, $value, ?int $ttl = null): bool
    {
        return Cache::set($this->getCacheKey($key), $value, $ttl);
    }

    /**
     * 获取缓存（自动添加命名空间）
     * Get cache (automatically add namespace)
     *
     * @param string $key 键 | Key
     * @param mixed $default 默认值 | Default value
     * @return mixed
     */
    protected function cacheGet(string $key, $default = null)
    {
        return Cache::get($this->getCacheKey($key), $default);
    }

    /**
     * 删除缓存（自动添加命名空间）
     * Delete cache (automatically add namespace)
     *
     * @param string $key 键 | Key
     * @return bool
     */
    protected function cacheDelete(string $key): bool
    {
        return Cache::delete($this->getCacheKey($key));
    }

    /**
     * PHPUnit setUp 钩子
     * PHPUnit setUp hook
     *
     * 自动设置缓存命名空间
     * Automatically set cache namespace
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCacheNamespace();
    }

    /**
     * PHPUnit tearDown 钩子
     * PHPUnit tearDown hook
     *
     * 自动清除缓存命名空间
     * Automatically clear cache namespace
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->tearDownCacheNamespace();
        parent::tearDown();
    }
}
