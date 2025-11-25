<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission\Service;

use Infrastructure\Permission\Service\CasbinService;
use Infrastructure\Permission\Service\PermissionService;
use Tests\ThinkPHPTestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * PermissionService 集成测试
 * PermissionService Integration Tests
 * 
 * 测试 PermissionService 与 CasbinService 的集成。
 * Test integration of PermissionService with CasbinService.
 */
class PermissionServiceIntegrationTest extends ThinkPHPTestCase
{
    protected PermissionService $service;
    protected CasbinService $casbinService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 准备测试数据
        // Prepare test data
        $this->prepareTestData();
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        // Clean up test data
        $this->cleanupTestData();
        
        // 恢复配置
        // Restore config
        Config::set([
            'casbin.enabled' => false,
            'casbin.mode' => 'DB_ONLY',
        ]);
        
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
            ['id' => 7001, 'tenant_id' => 1, 'username' => 'perm_int_test_user_1', 'email' => 'perm_int1@test.com', 'password' => 'test', 'status' => 'active'],
            ['id' => 7002, 'tenant_id' => 1, 'username' => 'perm_int_test_user_2', 'email' => 'perm_int2@test.com', 'password' => 'test', 'status' => 'active'],
        ]);
        
        // 插入测试角色
        // Insert test roles
        Db::table('roles')->insertAll([
            ['id' => 7001, 'tenant_id' => 1, 'name' => 'Perm Int Test Admin', 'slug' => 'perm_int_test_admin', 'description' => 'Test'],
            ['id' => 7002, 'tenant_id' => 1, 'name' => 'Perm Int Test User', 'slug' => 'perm_int_test_user', 'description' => 'Test'],
        ]);
        
        // 插入测试权限
        // Insert test permissions
        Db::table('permissions')->insertAll([
            ['id' => 7001, 'name' => 'Perm Int Test View Forms', 'slug' => 'perm_int_test_forms.view', 'resource' => 'perm_int_test_forms', 'action' => 'view', 'description' => 'Test'],
            ['id' => 7002, 'name' => 'Perm Int Test Create Forms', 'slug' => 'perm_int_test_forms.create', 'resource' => 'perm_int_test_forms', 'action' => 'create', 'description' => 'Test'],
        ]);
        
        // 插入用户角色关联
        // Insert user-role associations
        Db::table('user_roles')->insertAll([
            ['user_id' => 7001, 'role_id' => 7001], // user 1 -> admin
            ['user_id' => 7002, 'role_id' => 7002], // user 2 -> user
        ]);
        
        // 插入角色权限关联
        // Insert role-permission associations
        Db::table('role_permissions')->insertAll([
            ['role_id' => 7001, 'permission_id' => 7001], // admin -> forms.view
            ['role_id' => 7001, 'permission_id' => 7002], // admin -> forms.create
            ['role_id' => 7002, 'permission_id' => 7001], // user -> forms.view
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
        Db::table('role_permissions')->where('role_id', '>=', 7000)->delete();
        Db::table('user_roles')->where('user_id', '>=', 7000)->delete();
        Db::table('permissions')->where('id', '>=', 7000)->delete();
        Db::table('roles')->where('id', '>=', 7000)->delete();
        Db::table('users')->where('id', '>=', 7000)->delete();
    }

    /**
     * 测试 DB_ONLY 模式
     * Test DB_ONLY mode
     */
    public function testDbOnlyMode(): void
    {
        // 配置 DB_ONLY 模式
        // Configure DB_ONLY mode
        Config::set(['casbin.enabled' => false]);
        Config::set(['casbin.mode' => 'DB_ONLY']);
        
        // 创建服务实例
        // Create service instance
        $this->service = new PermissionService();
        
        // 获取用户权限
        // Get user permissions
        $permissions = $this->service->getUserPermissions(7001);
        
        // 验证权限
        // Verify permissions
        $this->assertCount(2, $permissions);
        $this->assertContains('perm_int_test_forms:view', $permissions);
        $this->assertContains('perm_int_test_forms:create', $permissions);
    }

    /**
     * 测试 CASBIN_ONLY 模式
     * Test CASBIN_ONLY mode
     */
    public function testCasbinOnlyMode(): void
    {
        // 配置 CASBIN_ONLY 模式
        // Configure CASBIN_ONLY mode
        Config::set(['casbin.enabled' => true]);
        Config::set(['casbin.mode' => 'CASBIN_ONLY']);
        
        // 创建 CasbinService 并重新加载策略
        // Create CasbinService and reload policy
        $this->casbinService = new CasbinService();
        $this->casbinService->reloadPolicy();
        
        // 创建服务实例
        // Create service instance
        $this->service = new PermissionService($this->casbinService);
        
        // 获取用户权限
        // Get user permissions
        $permissions = $this->service->getUserPermissions(7001);
        
        // 验证权限
        // Verify permissions
        $this->assertCount(2, $permissions);
        $this->assertContains('perm_int_test_forms:view', $permissions);
        $this->assertContains('perm_int_test_forms:create', $permissions);
    }

    /**
     * 测试 DUAL_MODE 模式
     * Test DUAL_MODE mode
     */
    public function testDualMode(): void
    {
        // 配置 DUAL_MODE 模式
        // Configure DUAL_MODE mode
        Config::set(['casbin.enabled' => true]);
        Config::set(['casbin.mode' => 'DUAL_MODE']);
        
        // 创建 CasbinService 并重新加载策略
        // Create CasbinService and reload policy
        $this->casbinService = new CasbinService();
        $this->casbinService->reloadPolicy();
        
        // 创建服务实例
        // Create service instance
        $this->service = new PermissionService($this->casbinService);
        
        // 获取用户权限
        // Get user permissions
        $permissions = $this->service->getUserPermissions(7001);
        
        // 验证权限（应该包含两种方式的并集）
        // Verify permissions (should include union of both methods)
        $this->assertGreaterThanOrEqual(2, count($permissions));
        $this->assertContains('perm_int_test_forms:view', $permissions);
        $this->assertContains('perm_int_test_forms:create', $permissions);
    }

    /**
     * 测试向后兼容性
     * Test backward compatibility
     */
    public function testBackwardCompatibility(): void
    {
        // 不启用 Casbin，应该使用数据库查询
        // Without enabling Casbin, should use database query
        Config::set(['casbin.enabled' => false]);
        
        // 创建服务实例（不注入 CasbinService）
        // Create service instance (without injecting CasbinService)
        $this->service = new PermissionService();
        
        // 获取用户权限
        // Get user permissions
        $permissions = $this->service->getUserPermissions(7001);
        
        // 验证权限
        // Verify permissions
        $this->assertCount(2, $permissions);
        $this->assertContains('perm_int_test_forms:view', $permissions);
        $this->assertContains('perm_int_test_forms:create', $permissions);
        
        // 测试 hasPermission 方法
        // Test hasPermission method
        $this->assertTrue($this->service->hasPermission(7001, 'perm_int_test_forms:view'));
        $this->assertTrue($this->service->hasPermission(7001, 'perm_int_test_forms:create'));
        $this->assertFalse($this->service->hasPermission(7002, 'perm_int_test_forms:create'));
    }

    /**
     * 测试权限检查方法
     * Test permission checking methods
     */
    public function testPermissionCheckingMethods(): void
    {
        // 配置 DB_ONLY 模式
        // Configure DB_ONLY mode
        Config::set(['casbin.enabled' => false]);
        
        // 创建服务实例
        // Create service instance
        $this->service = new PermissionService();
        
        // 测试 hasPermission
        // Test hasPermission
        $this->assertTrue($this->service->hasPermission(7001, 'perm_int_test_forms:view'));
        $this->assertFalse($this->service->hasPermission(7002, 'perm_int_test_forms:create'));
        
        // 测试 hasAnyPermission
        // Test hasAnyPermission
        $this->assertTrue($this->service->hasAnyPermission(7001, [
            'perm_int_test_forms:view',
            'perm_int_test_forms:create',
        ]));
        $this->assertTrue($this->service->hasAnyPermission(7002, [
            'perm_int_test_forms:view',
            'perm_int_test_forms:create',
        ]));
        
        // 测试 hasAllPermissions
        // Test hasAllPermissions
        $this->assertTrue($this->service->hasAllPermissions(7001, [
            'perm_int_test_forms:view',
            'perm_int_test_forms:create',
        ]));
        $this->assertFalse($this->service->hasAllPermissions(7002, [
            'perm_int_test_forms:view',
            'perm_int_test_forms:create',
        ]));
    }
}

