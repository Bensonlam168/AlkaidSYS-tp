<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission\Service;

use Infrastructure\Permission\Service\CasbinService;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

/**
 * Casbin 缓存功能测试
 * Casbin Cache Feature Tests
 *
 * 测试 CasbinService 的缓存功能。
 * Test cache features of CasbinService.
 */
class CasbinCacheTest extends ThinkPHPTestCase
{
    protected CasbinService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建服务实例
        // Create service instance
        $this->service = new CasbinService();

        // 准备测试数据
        // Prepare test data
        $this->prepareTestData();

        // 重新加载策略
        // Reload policy
        $this->service->reloadPolicy();
        
        // 启用缓存
        // Enable cache
        Config::set(['casbin.cache_enabled' => true]);
        Config::set(['casbin.cache_ttl' => 300]);
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        // Clean up test data
        $this->cleanupTestData();

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
            'id' => 9001,
            'tenant_id' => 1,
            'username' => 'cache_test_user',
            'email' => 'cache@test.com',
            'password' => 'test',
            'status' => 'active',
        ]);

        // 插入测试角色
        // Insert test role
        Db::table('roles')->insert([
            'id' => 9001,
            'tenant_id' => 1,
            'name' => 'Cache Test Role',
            'slug' => 'cache_test_role',
            'description' => 'Cache Test',
        ]);

        // 插入测试权限
        // Insert test permission
        Db::table('permissions')->insert([
            'id' => 9001,
            'name' => 'Cache Test Permission',
            'slug' => 'cache_test.view',
            'resource' => 'cache_test',
            'action' => 'view',
            'description' => 'Cache Test',
        ]);

        // 插入用户角色关联
        // Insert user-role association
        Db::table('user_roles')->insert([
            'user_id' => 9001,
            'role_id' => 9001,
        ]);

        // 插入角色权限关联
        // Insert role-permission association
        Db::table('role_permissions')->insert([
            'role_id' => 9001,
            'permission_id' => 9001,
        ]);
    }

    /**
     * 清理测试数据
     * Clean up test data
     */
    protected function cleanupTestData(): void
    {
        Db::table('role_permissions')->where('role_id', 9001)->delete();
        Db::table('user_roles')->where('user_id', 9001)->delete();
        Db::table('permissions')->where('id', 9001)->delete();
        Db::table('roles')->where('id', 9001)->delete();
        Db::table('users')->where('id', 9001)->delete();
    }

    /**
     * 测试缓存命中
     * Test cache hit
     */
    public function testCacheHit(): void
    {
        // 第一次检查（缓存未命中）
        // First check (cache miss)
        $result1 = $this->service->check(9001, 1, 'cache_test', 'view');
        $this->assertTrue($result1);

        // 第二次检查（缓存命中）
        // Second check (cache hit)
        $result2 = $this->service->check(9001, 1, 'cache_test', 'view');
        $this->assertTrue($result2);

        // 验证缓存统计
        // Verify cache statistics
        $stats = $this->service->getCacheStats();
        $this->assertEquals(1, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
        $this->assertEquals(2, $stats['total']);
        $this->assertEquals(50.0, $stats['hit_rate']);
    }

    /**
     * 测试缓存清除
     * Test cache clear
     */
    public function testCacheClear(): void
    {
        // 第一次检查（缓存未命中）
        // First check (cache miss)
        $result1 = $this->service->check(9001, 1, 'cache_test', 'view');
        $this->assertTrue($result1);

        // 清除缓存
        // Clear cache
        $this->service->clearCache();

        // 第二次检查（缓存未命中，因为缓存已清除）
        // Second check (cache miss, because cache was cleared)
        $result2 = $this->service->check(9001, 1, 'cache_test', 'view');
        $this->assertTrue($result2);

        // 验证缓存统计（应该重置）
        // Verify cache statistics (should be reset)
        $stats = $this->service->getCacheStats();
        $this->assertEquals(0, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
    }

    /**
     * 测试用户缓存清除
     * Test user cache clear
     */
    public function testUserCacheClear(): void
    {
        // 第一次检查（缓存未命中）
        // First check (cache miss)
        $result1 = $this->service->check(9001, 1, 'cache_test', 'view');
        $this->assertTrue($result1);

        // 清除用户缓存
        // Clear user cache
        $this->service->clearUserCache(9001, 1);

        // 第二次检查（缓存未命中，因为缓存已清除）
        // Second check (cache miss, because cache was cleared)
        $result2 = $this->service->check(9001, 1, 'cache_test', 'view');
        $this->assertTrue($result2);

        // 验证缓存统计（应该重置）
        // Verify cache statistics (should be reset)
        $stats = $this->service->getCacheStats();
        $this->assertEquals(0, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
    }

    /**
     * 测试策略刷新清除缓存
     * Test policy reload clears cache
     */
    public function testPolicyReloadClearsCache(): void
    {
        // 第一次检查（缓存未命中）
        // First check (cache miss)
        $result1 = $this->service->check(9001, 1, 'cache_test', 'view');
        $this->assertTrue($result1);

        // 重新加载策略
        // Reload policy
        $this->service->reloadPolicy();

        // 第二次检查（缓存未命中，因为策略刷新清除了缓存）
        // Second check (cache miss, because policy reload cleared cache)
        $result2 = $this->service->check(9001, 1, 'cache_test', 'view');
        $this->assertTrue($result2);

        // 验证缓存统计（应该重置）
        // Verify cache statistics (should be reset)
        $stats = $this->service->getCacheStats();
        $this->assertEquals(0, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
    }

}

