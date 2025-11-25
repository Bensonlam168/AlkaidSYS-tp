<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission\Service;

use Infrastructure\Permission\Service\CasbinService;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;
use think\facade\Db;

/**
 * CasbinService 单元测试
 * CasbinService Unit Tests
 * 
 * 测试 Casbin 授权服务的权限检查和策略管理功能。
 * Test Casbin authorization service permission checking and policy management.
 */
class CasbinServiceTest extends ThinkPHPTestCase
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
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        // Clean up test data
        $this->cleanupTestData();
        
        // 清理缓存
        // Clear cache
        Cache::delete('casbin_last_reload');
        
        parent::tearDown();
    }

    /**
     * 准备测试数据
     * Prepare test data
     */
    protected function prepareTestData(): void
    {
        // 清理现有数据
        // Clean existing data
        $this->cleanupTestData();
        
        // 插入测试用户
        // Insert test users
        Db::table('users')->insertAll([
            ['id' => 8001, 'tenant_id' => 1, 'username' => 'casbin_test_user_1', 'email' => 'casbin1@test.com', 'password' => 'test', 'status' => 'active'],
            ['id' => 8002, 'tenant_id' => 1, 'username' => 'casbin_test_user_2', 'email' => 'casbin2@test.com', 'password' => 'test', 'status' => 'active'],
            ['id' => 8003, 'tenant_id' => 2, 'username' => 'casbin_test_user_3', 'email' => 'casbin3@test.com', 'password' => 'test', 'status' => 'active'],
        ]);
        
        // 插入测试角色
        // Insert test roles
        Db::table('roles')->insertAll([
            ['id' => 8001, 'tenant_id' => 1, 'name' => 'Casbin Service Test Admin', 'slug' => 'casbin_service_test_admin', 'description' => 'Test'],
            ['id' => 8002, 'tenant_id' => 1, 'name' => 'Casbin Service Test User', 'slug' => 'casbin_service_test_user', 'description' => 'Test'],
            ['id' => 8003, 'tenant_id' => 2, 'name' => 'Casbin Service Test Admin 2', 'slug' => 'casbin_service_test_admin_2', 'description' => 'Test'],
        ]);
        
        // 插入测试权限
        // Insert test permissions
        Db::table('permissions')->insertAll([
            ['id' => 8001, 'name' => 'Casbin Service Test View Forms', 'slug' => 'casbin_service_test_forms.view', 'resource' => 'casbin_service_test_forms', 'action' => 'view', 'description' => 'Test'],
            ['id' => 8002, 'name' => 'Casbin Service Test Create Forms', 'slug' => 'casbin_service_test_forms.create', 'resource' => 'casbin_service_test_forms', 'action' => 'create', 'description' => 'Test'],
            ['id' => 8003, 'name' => 'Casbin Service Test Manage Users', 'slug' => 'casbin_service_test_users.manage', 'resource' => 'casbin_service_test_users', 'action' => 'manage', 'description' => 'Test'],
        ]);
        
        // 插入用户角色关联
        // Insert user-role associations
        Db::table('user_roles')->insertAll([
            ['user_id' => 8001, 'role_id' => 8001], // user 1 -> admin (tenant 1)
            ['user_id' => 8002, 'role_id' => 8002], // user 2 -> user (tenant 1)
            ['user_id' => 8003, 'role_id' => 8003], // user 3 -> admin (tenant 2)
        ]);
        
        // 插入角色权限关联
        // Insert role-permission associations
        Db::table('role_permissions')->insertAll([
            ['role_id' => 8001, 'permission_id' => 8001], // admin -> forms.view
            ['role_id' => 8001, 'permission_id' => 8002], // admin -> forms.create
            ['role_id' => 8001, 'permission_id' => 8003], // admin -> users.manage
            ['role_id' => 8002, 'permission_id' => 8001], // user -> forms.view
            ['role_id' => 8003, 'permission_id' => 8001], // admin2 -> forms.view
        ]);
    }

    /**
     * 清理测试数据
     * Clean up test data
     */
    protected function cleanupTestData(): void
    {
        // 删除测试数据（按依赖顺序）
        // Delete test data (in dependency order)
        Db::table('role_permissions')->where('role_id', '>=', 8000)->delete();
        Db::table('user_roles')->where('user_id', '>=', 8000)->delete();
        Db::table('permissions')->where('id', '>=', 8000)->delete();
        Db::table('roles')->where('id', '>=', 8000)->delete();
        Db::table('users')->where('id', '>=', 8000)->delete();
    }

    /**
     * 测试权限检查
     * Test permission checking
     */
    public function testCheck(): void
    {
        // 测试用户 1 在租户 1 中有 forms:view 权限
        // Test user 1 has forms:view permission in tenant 1
        $this->assertTrue(
            $this->service->check(8001, 1, 'casbin_service_test_forms', 'view')
        );
        
        // 测试用户 1 在租户 1 中有 forms:create 权限
        // Test user 1 has forms:create permission in tenant 1
        $this->assertTrue(
            $this->service->check(8001, 1, 'casbin_service_test_forms', 'create')
        );
        
        // 测试用户 2 在租户 1 中有 forms:view 权限
        // Test user 2 has forms:view permission in tenant 1
        $this->assertTrue(
            $this->service->check(8002, 1, 'casbin_service_test_forms', 'view')
        );
        
        // 测试用户 2 在租户 1 中没有 forms:create 权限
        // Test user 2 does not have forms:create permission in tenant 1
        $this->assertFalse(
            $this->service->check(8002, 1, 'casbin_service_test_forms', 'create')
        );
    }

    /**
     * 测试多租户隔离
     * Test multi-tenancy isolation
     */
    public function testMultiTenancyIsolation(): void
    {
        // 测试用户 1 在租户 1 中有权限
        // Test user 1 has permission in tenant 1
        $this->assertTrue(
            $this->service->check(8001, 1, 'casbin_service_test_forms', 'view')
        );
        
        // 测试用户 1 在租户 2 中没有权限（跨租户）
        // Test user 1 does not have permission in tenant 2 (cross-tenant)
        $this->assertFalse(
            $this->service->check(8001, 2, 'casbin_service_test_forms', 'view')
        );
        
        // 测试用户 3 在租户 2 中有权限
        // Test user 3 has permission in tenant 2
        $this->assertTrue(
            $this->service->check(8003, 2, 'casbin_service_test_forms', 'view')
        );
        
        // 测试用户 3 在租户 1 中没有权限（跨租户）
        // Test user 3 does not have permission in tenant 1 (cross-tenant)
        $this->assertFalse(
            $this->service->check(8003, 1, 'casbin_service_test_forms', 'view')
        );
    }

    /**
     * 测试获取用户权限
     * Test get user permissions
     */
    public function testGetUserPermissions(): void
    {
        // 获取用户 1 在租户 1 中的权限
        // Get user 1 permissions in tenant 1
        $permissions = $this->service->getUserPermissions(8001, 1);
        
        // 验证权限数量
        // Verify permission count
        $this->assertCount(3, $permissions);
        
        // 验证权限格式
        // Verify permission format
        $this->assertContains('casbin_service_test_forms:view', $permissions);
        $this->assertContains('casbin_service_test_forms:create', $permissions);
        $this->assertContains('casbin_service_test_users:manage', $permissions);
        
        // 获取用户 2 在租户 1 中的权限
        // Get user 2 permissions in tenant 1
        $permissions = $this->service->getUserPermissions(8002, 1);
        
        // 验证权限数量
        // Verify permission count
        $this->assertCount(1, $permissions);
        
        // 验证权限格式
        // Verify permission format
        $this->assertContains('casbin_service_test_forms:view', $permissions);
    }

    /**
     * 测试 hasPermission 方法
     * Test hasPermission method
     */
    public function testHasPermission(): void
    {
        // 测试用户 1 有 forms:view 权限
        // Test user 1 has forms:view permission
        $this->assertTrue(
            $this->service->hasPermission(8001, 1, 'casbin_service_test_forms:view')
        );
        
        // 测试用户 2 没有 forms:create 权限
        // Test user 2 does not have forms:create permission
        $this->assertFalse(
            $this->service->hasPermission(8002, 1, 'casbin_service_test_forms:create')
        );
        
        // 测试无效的权限码格式
        // Test invalid permission code format
        $this->assertFalse(
            $this->service->hasPermission(8001, 1, 'invalid_format')
        );
    }

    /**
     * 测试 hasAnyPermission 方法
     * Test hasAnyPermission method
     */
    public function testHasAnyPermission(): void
    {
        // 测试用户 1 有任一权限
        // Test user 1 has any permission
        $this->assertTrue(
            $this->service->hasAnyPermission(8001, 1, [
                'casbin_service_test_forms:view',
                'casbin_service_test_forms:create',
            ])
        );
        
        // 测试用户 2 有任一权限（只有 view）
        // Test user 2 has any permission (only view)
        $this->assertTrue(
            $this->service->hasAnyPermission(8002, 1, [
                'casbin_service_test_forms:view',
                'casbin_service_test_forms:create',
            ])
        );
        
        // 测试用户 2 没有任一权限
        // Test user 2 does not have any permission
        $this->assertFalse(
            $this->service->hasAnyPermission(8002, 1, [
                'casbin_service_test_forms:create',
                'casbin_service_test_users:manage',
            ])
        );
    }

    /**
     * 测试 hasAllPermissions 方法
     * Test hasAllPermissions method
     */
    public function testHasAllPermissions(): void
    {
        // 测试用户 1 有所有权限
        // Test user 1 has all permissions
        $this->assertTrue(
            $this->service->hasAllPermissions(8001, 1, [
                'casbin_service_test_forms:view',
                'casbin_service_test_forms:create',
            ])
        );
        
        // 测试用户 2 没有所有权限（缺少 create）
        // Test user 2 does not have all permissions (missing create)
        $this->assertFalse(
            $this->service->hasAllPermissions(8002, 1, [
                'casbin_service_test_forms:view',
                'casbin_service_test_forms:create',
            ])
        );
    }

    /**
     * 测试策略刷新
     * Test policy reload
     */
    public function testReloadPolicy(): void
    {
        // 设置上次加载时间为很久以前
        // Set last reload time to long ago
        Cache::set('casbin_last_reload', time() - 1000);
        
        // 重新加载策略
        // Reload policy
        $this->service->reloadPolicy();
        
        // 验证缓存时间戳已更新
        // Verify cache timestamp updated
        $lastReload = Cache::get('casbin_last_reload');
        $this->assertGreaterThan(time() - 10, $lastReload);
    }
}

