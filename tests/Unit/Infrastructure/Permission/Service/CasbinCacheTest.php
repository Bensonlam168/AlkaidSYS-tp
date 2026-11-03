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

    /**
     * 测试「清缓存不误伤非权限键」（R1 回归防护）
     * Test that clearing Casbin caches never touches non-permission keys (R1)
     *
     * 历史实现 `clearCache()` / `clearUserCache()` 都是 `Cache::clear()`：
     * Redis 驱动下等价于 `FLUSHDB`，会把 `session:*` 会话与 `rl:*` 限流计数一起清掉；
     * 为了保住 `casbin_last_reload` 还得「先备份再恢复」。
     *
     * 现在两者都改成了白名单前缀清除（`casbin:check:` + `casbin:policy:`），
     * 因此：会话/限流键结构上就不可能被删，`casbin_last_reload` 也不需要备份恢复了。
     */
    public function testClearCachesKeepNonPermissionKeys(): void
    {
        $sessionKey = 'session:casbin-r1-sid';
        $rateLimitKey = 'rl:dev:user:casbin-r1:token_bucket';
        $reloadKey = 'casbin_last_reload';

        // 造旁观者键 | Prepare bystander keys
        // 预热两个用户的检查缓存（9002 不存在于策略中，结果 false 但同样会写缓存）
        // Warm check caches for two users
        // 注意顺序：check() 可能触发 shouldReload() → reloadPolicy() 从而重写 casbin_last_reload，
        // 所以旁观者键必须在预热之后才写入，否则会被覆盖
        $this->assertTrue($this->service->check(9001, 1, 'cache_test', 'view'));
        $this->service->check(9002, 1, 'cache_test', 'view');

        // 造旁观者键（全部用字符串值：File 驱动会把标量落盘成数字字符串/普通字符串）
        Cache::set($sessionKey, 'session-data', 300);
        Cache::set($rateLimitKey, 'bucket-5', 300);
        Cache::set($reloadKey, '1234567890', 300);

        // 只清目标用户 | Clear only user 9001
        $this->service->clearUserCache(9001, 1);

        $this->assertNull(
            Cache::get('casbin:check:9001:1:cache_test:view'),
            '目标用户的检查缓存必须被清除'
        );
        $this->assertNotNull(
            Cache::get('casbin:check:9002:1:cache_test:view'),
            '其它用户的检查缓存不能被误删'
        );
        $this->assertSame('session-data', Cache::get($sessionKey), 'R1：会话键必须完好');
        $this->assertSame('bucket-5', Cache::get($rateLimitKey), 'R1：限流键必须完好');
        $this->assertSame(
            '1234567890',
            Cache::get($reloadKey),
            'casbin_last_reload 不在白名单前缀内，必须存活'
        );

        // 全量清除检查缓存 | Clear all check caches
        $this->service->clearCache();

        $this->assertNull(
            Cache::get('casbin:check:9002:1:cache_test:view'),
            'clearCache() 应清掉全部 casbin 检查缓存'
        );
        $this->assertSame('session-data', Cache::get($sessionKey), 'R1：clearCache() 不能清会话');
        $this->assertSame('bucket-5', Cache::get($rateLimitKey), 'R1：clearCache() 不能清限流计数');
        $this->assertSame(
            '1234567890',
            Cache::get($reloadKey),
            'clearCache() 不应再需要「备份/恢复 casbin_last_reload」'
        );

        // 清理旁观者键，避免污染后续用例的重载判断
        Cache::delete($sessionKey);
        Cache::delete($rateLimitKey);
        Cache::delete($reloadKey);
    }
}
