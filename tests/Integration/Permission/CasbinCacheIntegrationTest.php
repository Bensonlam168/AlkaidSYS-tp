<?php

declare(strict_types=1);

namespace Tests\Integration\Permission;

use Infrastructure\Permission\Service\CasbinService;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

/**
 * Casbin 缓存行为集成测试
 * Casbin Cache Behavior Integration Tests
 *
 * 测试 Casbin 缓存功能在不同场景下的行为，包括缓存启用、禁用、命中、未命中和清除。
 * Test Casbin cache behavior in different scenarios, including cache enabled, disabled, hit, miss, and clear.
 *
 * 本测试不依赖性能指标，只验证功能正确性。
 * This test does not rely on performance metrics, only verifies functional correctness.
 */
class CasbinCacheIntegrationTest extends ThinkPHPTestCase
{
    protected CasbinService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 准备测试数据
        // Prepare test data
        $this->prepareTestData();

        // 创建服务实例
        // Create service instance
        $this->service = new CasbinService();

        // 重新加载策略
        // Reload policy
        $this->service->reloadPolicy();
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        // Clean up test data
        $this->cleanupTestData();

        // 清除缓存
        // Clear cache
        Cache::clear();

        parent::tearDown();
    }

    /**
     * 准备测试数据
     * Prepare test data
     */
    protected function prepareTestData(): void
    {
        // 插入测试用户
        // Insert test user
        Db::table('users')->insert([
            'id' => 9100,
            'tenant_id' => 1,
            'username' => 'cache_integration_user',
            'email' => 'cache_integration@test.com',
            'password' => 'test',
            'status' => 'active',
        ]);

        // 插入测试角色
        // Insert test role
        Db::table('roles')->insert([
            'id' => 9100,
            'tenant_id' => 1,
            'name' => 'Cache Integration Role',
            'slug' => 'cache_integration_role',
            'description' => 'Cache Integration Test',
        ]);

        // 插入测试权限
        // Insert test permission
        Db::table('permissions')->insert([
            'id' => 9100,
            'name' => 'Cache Integration Permission',
            'slug' => 'cache_integration.view',
            'resource' => 'cache_integration',
            'action' => 'view',
            'description' => 'Cache Integration Test',
        ]);

        // 插入用户角色关联
        // Insert user-role association
        Db::table('user_roles')->insert([
            'user_id' => 9100,
            'role_id' => 9100,
        ]);

        // 插入角色权限关联
        // Insert role-permission association
        Db::table('role_permissions')->insert([
            'role_id' => 9100,
            'permission_id' => 9100,
        ]);
    }

    /**
     * 清理测试数据
     * Clean up test data
     */
    protected function cleanupTestData(): void
    {
        Db::table('role_permissions')->where('role_id', 9100)->delete();
        Db::table('user_roles')->where('user_id', 9100)->delete();
        Db::table('permissions')->where('id', 9100)->delete();
        Db::table('roles')->where('id', 9100)->delete();
        Db::table('users')->where('id', 9100)->delete();
    }

    /**
     * 测试缓存启用和禁用场景
     * Test cache enabled and disabled scenarios
     *
     * 验证缓存启用时的命中行为和缓存禁用时的直接查询行为。
     * Verify cache hit behavior when enabled and direct query behavior when disabled.
     */
    public function testCacheEnabledAndDisabled(): void
    {
        // ========== 测试缓存启用场景 ==========
        // ========== Test cache enabled scenario ==========

        // 启用缓存
        // Enable cache
        Config::set(['casbin.cache_enabled' => true]);

        // 清除所有缓存
        // Clear all cache
        Cache::clear();

        // 创建新的服务实例
        // Create new service instance
        $service1 = new CasbinService();
        $service1->reloadPolicy();

        // 第一次检查（缓存未命中）
        // First check (cache miss)
        $result1 = $service1->check(9100, 1, 'cache_integration', 'view');
        $this->assertTrue($result1, 'User should have permission');

        // 验证缓存统计
        // Verify cache statistics
        $stats1 = $service1->getCacheStats();
        $this->assertEquals(1, $stats1['misses'], 'Should have 1 cache miss');
        $this->assertEquals(0, $stats1['hits'], 'Should have 0 cache hits');

        // 第二次检查（缓存命中）
        // Second check (cache hit)
        $result2 = $service1->check(9100, 1, 'cache_integration', 'view');
        $this->assertTrue($result2, 'User should still have permission');

        // 验证缓存统计
        // Verify cache statistics
        $stats2 = $service1->getCacheStats();
        $this->assertEquals(1, $stats2['misses'], 'Should still have 1 cache miss');
        $this->assertEquals(1, $stats2['hits'], 'Should have 1 cache hit');

        // 验证缓存中有数据
        // Verify cache has data
        $cacheKey = 'casbin:check:9100:1:cache_integration:view';
        $cachedValue = Cache::get($cacheKey);
        $this->assertNotNull($cachedValue, 'Cache should have value');
        $this->assertTrue((bool) $cachedValue, 'Cached value should be true');

        // ========== 测试缓存禁用场景 ==========
        // ========== Test cache disabled scenario ==========

        // 清除所有缓存（清除之前测试留下的缓存数据）
        // Clear all cache (clear cache data from previous test)
        Cache::clear();

        // 禁用缓存
        // Disable cache
        Config::set(['casbin.cache_enabled' => false]);

        // 创建新的服务实例
        // Create new service instance
        $service2 = new CasbinService();
        $service2->reloadPolicy();

        // 第一次检查（缓存禁用，直接查询）
        // First check (cache disabled, direct query)
        $result3 = $service2->check(9100, 1, 'cache_integration', 'view');
        $this->assertTrue($result3, 'User should have permission');

        // 第二次检查（缓存禁用，直接查询）
        // Second check (cache disabled, direct query)
        $result4 = $service2->check(9100, 1, 'cache_integration', 'view');
        $this->assertTrue($result4, 'User should still have permission');

        // 验证结果一致性（缓存禁用时，每次都直接查询，结果应该一致）
        // Verify result consistency (when cache is disabled, each query is direct, results should be consistent)
        $this->assertEquals($result3, $result4, 'Results should be consistent when cache is disabled');

        // 验证功能正确性：即使缓存禁用，权限检查仍然正常工作
        // Verify functional correctness: permission check still works even when cache is disabled
        $this->assertTrue($result3 && $result4, 'Permission check should work correctly when cache is disabled');
    }

    /**
     * 测试缓存清除功能
     * Test cache clear functionality
     *
     * 验证 clearCache() 方法能够正确清除缓存并重置统计。
     * Verify clearCache() method correctly clears cache and resets statistics.
     */
    public function testCacheClear(): void
    {
        // 启用缓存
        // Enable cache
        Config::set(['casbin.cache_enabled' => true]);

        // 清除所有缓存
        // Clear all cache
        Cache::clear();

        // 创建服务实例
        // Create service instance
        $service = new CasbinService();
        $service->reloadPolicy();

        // 第一次检查（缓存未命中）
        // First check (cache miss)
        $result1 = $service->check(9100, 1, 'cache_integration', 'view');
        $this->assertTrue($result1);

        // 第二次检查（缓存命中）
        // Second check (cache hit)
        $result2 = $service->check(9100, 1, 'cache_integration', 'view');
        $this->assertTrue($result2);

        // 验证缓存统计
        // Verify cache statistics
        $stats1 = $service->getCacheStats();
        $this->assertEquals(1, $stats1['hits']);
        $this->assertEquals(1, $stats1['misses']);

        // 清除缓存
        // Clear cache
        $service->clearCache();

        // 验证缓存统计已重置
        // Verify cache statistics are reset
        $stats2 = $service->getCacheStats();
        $this->assertEquals(0, $stats2['hits']);
        $this->assertEquals(0, $stats2['misses']);

        // 第三次检查（缓存已清除，应该未命中）
        // Third check (cache cleared, should miss)
        $result3 = $service->check(9100, 1, 'cache_integration', 'view');
        $this->assertTrue($result3);

        // 验证缓存统计
        // Verify cache statistics
        $stats3 = $service->getCacheStats();
        $this->assertEquals(0, $stats3['hits']);
        $this->assertEquals(1, $stats3['misses']);
    }

    /**
     * 测试用户缓存清除功能
     * Test user cache clear functionality
     *
     * 验证 clearUserCache() 方法能够正确清除特定用户的缓存。
     * Verify clearUserCache() method correctly clears cache for specific user.
     */
    public function testUserCacheClear(): void
    {
        // 启用缓存
        // Enable cache
        Config::set(['casbin.cache_enabled' => true]);

        // 清除所有缓存
        // Clear all cache
        Cache::clear();

        // 创建服务实例
        // Create service instance
        $service = new CasbinService();
        $service->reloadPolicy();

        // 第一次检查（缓存未命中）
        // First check (cache miss)
        $result1 = $service->check(9100, 1, 'cache_integration', 'view');
        $this->assertTrue($result1);

        // 第二次检查（缓存命中）
        // Second check (cache hit)
        $result2 = $service->check(9100, 1, 'cache_integration', 'view');
        $this->assertTrue($result2);

        // 验证缓存统计
        // Verify cache statistics
        $stats1 = $service->getCacheStats();
        $this->assertEquals(1, $stats1['hits']);

        // 清除用户缓存
        // Clear user cache
        $service->clearUserCache(9100, 1);

        // 验证缓存统计已重置
        // Verify cache statistics are reset
        $stats2 = $service->getCacheStats();
        $this->assertEquals(0, $stats2['hits']);
        $this->assertEquals(0, $stats2['misses']);

        // 第三次检查（缓存已清除，应该未命中）
        // Third check (cache cleared, should miss)
        $result3 = $service->check(9100, 1, 'cache_integration', 'view');
        $this->assertTrue($result3);

        // 验证缓存统计
        // Verify cache statistics
        $stats3 = $service->getCacheStats();
        $this->assertEquals(0, $stats3['hits']);
        $this->assertEquals(1, $stats3['misses']);
    }
}
