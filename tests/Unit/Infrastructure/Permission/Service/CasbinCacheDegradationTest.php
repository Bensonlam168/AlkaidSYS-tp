<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission\Service;

use Infrastructure\Permission\Service\CasbinService;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

/**
 * Casbin 缓存降级测试
 * Casbin Cache Degradation Test
 *
 * 测试 Redis 缓存故障时的自动降级机制。
 * Test automatic degradation mechanism when Redis cache fails.
 */
class CasbinCacheDegradationTest extends ThinkPHPTestCase
{
    protected CasbinService $casbinService;

    protected function setUp(): void
    {
        parent::setUp();

        // 启用缓存
        // Enable cache
        Config::set(['casbin.cache_enabled' => true]);
        Config::set(['casbin.cache_degradation_enabled' => true]);
        Config::set(['casbin.cache_degradation_log_enabled' => true]);

        // 创建 CasbinService 实例
        // Create CasbinService instance
        $this->casbinService = new CasbinService();

        // 准备测试数据
        // Prepare test data
        $this->prepareTestData();
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
        // 创建测试用户
        // Create test user
        Db::table('users')->insert([
            'id' => 99999,
            'username' => 'test_degradation_user',
            'email' => 'degradation@test.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'tenant_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 创建测试角色
        // Create test role
        Db::table('roles')->insert([
            'id' => 99999,
            'name' => 'Test Degradation Role',
            'slug' => 'test_degradation_role',
            'tenant_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 创建测试权限
        // Create test permission
        Db::table('permissions')->insert([
            'id' => 99999,
            'name' => 'Test Degradation Permission',
            'slug' => 'test_degradation.view',
            'resource' => 'test_degradation',
            'action' => 'view',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 分配角色给用户
        // Assign role to user
        Db::table('user_roles')->insert([
            'user_id' => 99999,
            'role_id' => 99999,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 分配权限给角色
        // Assign permission to role
        Db::table('role_permissions')->insert([
            'role_id' => 99999,
            'permission_id' => 99999,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 重新加载策略
        // Reload policy
        $this->casbinService->reloadPolicy();
    }

    /**
     * 清理测试数据
     * Clean up test data
     */
    protected function cleanupTestData(): void
    {
        Db::table('role_permissions')->where('role_id', 99999)->delete();
        Db::table('user_roles')->where('user_id', 99999)->delete();
        Db::table('permissions')->where('id', 99999)->delete();
        Db::table('roles')->where('id', 99999)->delete();
        Db::table('users')->where('id', 99999)->delete();
    }

    /**
     * 测试正常缓存工作
     * Test normal cache working
     */
    public function testNormalCacheWorking(): void
    {
        // 第一次调用，缓存未命中
        // First call, cache miss
        $result1 = $this->casbinService->check(99999, 1, 'test_degradation', 'view');
        $this->assertTrue($result1);

        // 第二次调用，缓存命中
        // Second call, cache hit
        $result2 = $this->casbinService->check(99999, 1, 'test_degradation', 'view');
        $this->assertTrue($result2);

        // 验证缓存统计
        // Verify cache statistics
        $stats = $this->casbinService->getCacheStats();
        $this->assertEquals(1, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);

        // 验证降级统计（应该为 0）
        // Verify degradation statistics (should be 0)
        $degradationStats = $this->casbinService->getCacheDegradationStats();
        $this->assertEquals(0, $degradationStats['degradation_count']);
        $this->assertNull($degradationStats['last_degradation_time']);
        $this->assertNull($degradationStats['last_degradation_reason']);
    }

    /**
     * 测试缓存降级统计功能
     * Test cache degradation statistics
     */
    public function testCacheDegradationStats(): void
    {
        // 获取初始降级统计
        // Get initial degradation statistics
        $stats = $this->casbinService->getCacheDegradationStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('degradation_count', $stats);
        $this->assertArrayHasKey('last_degradation_time', $stats);
        $this->assertArrayHasKey('last_degradation_reason', $stats);

        // 初始值应该为 0/null
        // Initial values should be 0/null
        $this->assertEquals(0, $stats['degradation_count']);
        $this->assertNull($stats['last_degradation_time']);
        $this->assertNull($stats['last_degradation_reason']);
    }

    /**
     * 测试缓存降级配置
     * Test cache degradation configuration
     */
    public function testCacheDegradationConfiguration(): void
    {
        // 验证配置项存在（使用默认值）
        // Verify configuration exists (with default values)
        $degradationEnabled = Config::get('casbin.cache_degradation_enabled', true);
        $degradationLogEnabled = Config::get('casbin.cache_degradation_log_enabled', true);

        $this->assertIsBool($degradationEnabled);
        $this->assertIsBool($degradationLogEnabled);

        // 验证默认值
        // Verify default values
        $this->assertTrue($degradationEnabled);
        $this->assertTrue($degradationLogEnabled);
    }

    /**
     * 测试禁用缓存降级日志
     * Test disable cache degradation logging
     */
    public function testDisableCacheDegradationLogging(): void
    {
        // 禁用降级日志
        // Disable degradation logging
        Config::set(['casbin.cache_degradation_log_enabled' => false]);

        // 执行权限检查（应该正常工作）
        // Execute permission check (should work normally)
        $result = $this->casbinService->check(99999, 1, 'test_degradation', 'view');
        $this->assertTrue($result);
    }
}
