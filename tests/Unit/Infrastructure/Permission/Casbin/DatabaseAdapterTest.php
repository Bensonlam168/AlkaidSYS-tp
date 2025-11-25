<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission\Casbin;

use Casbin\Model\Model;
use Infrastructure\Permission\Casbin\DatabaseAdapter;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * DatabaseAdapter 单元测试
 * DatabaseAdapter Unit Tests
 * 
 * 测试 Casbin DatabaseAdapter 的策略加载功能。
 * Test Casbin DatabaseAdapter policy loading functionality.
 */
class DatabaseAdapterTest extends ThinkPHPTestCase
{
    protected DatabaseAdapter $adapter;
    protected Model $model;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 创建适配器实例
        // Create adapter instance
        $this->adapter = new DatabaseAdapter();
        
        // 创建 Casbin 模型
        // Create Casbin model
        $modelPath = __DIR__ . '/../../../../../config/casbin-model.conf';
        $this->model = new Model();
        $this->model->loadModel($modelPath);
        
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
        // 清理现有数据
        // Clean existing data
        $this->cleanupTestData();
        
        // 插入测试租户（假设 tenants 表存在，如果不存在则跳过）
        // Insert test tenants (skip if tenants table doesn't exist)
        
        // 插入测试用户
        // Insert test users
        Db::table('users')->insertAll([
            ['id' => 9001, 'tenant_id' => 1, 'username' => 'test_user_1', 'email' => 'test1@test.com', 'password' => 'test', 'status' => 'active'],
            ['id' => 9002, 'tenant_id' => 1, 'username' => 'test_user_2', 'email' => 'test2@test.com', 'password' => 'test', 'status' => 'active'],
            ['id' => 9003, 'tenant_id' => 2, 'username' => 'test_user_3', 'email' => 'test3@test.com', 'password' => 'test', 'status' => 'active'],
        ]);
        
        // 插入测试角色
        // Insert test roles
        Db::table('roles')->insertAll([
            ['id' => 9001, 'tenant_id' => 1, 'name' => 'Casbin Test Admin', 'slug' => 'casbin_test_admin', 'description' => 'Test'],
            ['id' => 9002, 'tenant_id' => 1, 'name' => 'Casbin Test User', 'slug' => 'casbin_test_user', 'description' => 'Test'],
            ['id' => 9003, 'tenant_id' => 2, 'name' => 'Casbin Test Admin 2', 'slug' => 'casbin_test_admin_2', 'description' => 'Test'],
        ]);

        // 插入测试权限
        // Insert test permissions
        Db::table('permissions')->insertAll([
            ['id' => 9001, 'name' => 'Casbin Test View Forms', 'slug' => 'casbin_test_forms.view', 'resource' => 'casbin_test_forms', 'action' => 'view', 'description' => 'Test'],
            ['id' => 9002, 'name' => 'Casbin Test Create Forms', 'slug' => 'casbin_test_forms.create', 'resource' => 'casbin_test_forms', 'action' => 'create', 'description' => 'Test'],
            ['id' => 9003, 'name' => 'Casbin Test Manage Users', 'slug' => 'casbin_test_users.manage', 'resource' => 'casbin_test_users', 'action' => 'manage', 'description' => 'Test'],
        ]);

        // 插入用户角色关联
        // Insert user-role associations
        Db::table('user_roles')->insertAll([
            ['user_id' => 9001, 'role_id' => 9001], // user 1 -> admin (tenant 1)
            ['user_id' => 9002, 'role_id' => 9002], // user 2 -> user (tenant 1)
            ['user_id' => 9003, 'role_id' => 9003], // user 3 -> admin (tenant 2)
        ]);

        // 插入角色权限关联
        // Insert role-permission associations
        Db::table('role_permissions')->insertAll([
            ['role_id' => 9001, 'permission_id' => 9001], // admin -> forms.view
            ['role_id' => 9001, 'permission_id' => 9002], // admin -> forms.create
            ['role_id' => 9001, 'permission_id' => 9003], // admin -> users.manage
            ['role_id' => 9002, 'permission_id' => 9001], // user -> forms.view
            ['role_id' => 9003, 'permission_id' => 9001], // admin2 -> forms.view
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
        Db::table('role_permissions')->where('role_id', '>=', 9000)->delete();
        Db::table('user_roles')->where('user_id', '>=', 9000)->delete();
        Db::table('permissions')->where('id', '>=', 9000)->delete();
        Db::table('roles')->where('id', '>=', 9000)->delete();
        Db::table('users')->where('id', '>=', 9000)->delete();
    }

    /**
     * 测试策略加载
     * Test policy loading
     */
    public function testLoadPolicy(): void
    {
        // 加载策略
        // Load policies
        $this->adapter->loadPolicy($this->model);

        // 验证策略已加载
        // Verify policies are loaded
        $gPolicies = $this->model->getPolicy('g', 'g');
        $pPolicies = $this->model->getPolicy('p', 'p');

        $this->assertNotEmpty($gPolicies);
        $this->assertNotEmpty($pPolicies);
    }

    /**
     * 测试角色分配加载
     * Test role assignment loading
     */
    public function testLoadRoleAssignments(): void
    {
        // 加载策略
        // Load policies
        $this->adapter->loadPolicy($this->model);

        // 获取 g 策略
        // Get g policies
        $gPolicies = $this->model->getPolicy('g', 'g');

        // 过滤出测试数据（user_id >= 9000）
        // Filter test data (user_id >= 9000)
        $testPolicies = array_filter($gPolicies, function ($policy) {
            return str_starts_with($policy[0], 'user:900');
        });

        // 验证策略数量
        // Verify policy count
        $this->assertCount(3, $testPolicies);

        // 验证策略格式
        // Verify policy format
        $testPolicies = array_values($testPolicies); // 重新索引
        $this->assertEquals(['user:9001', 'role:9001', 'tenant:1'], $testPolicies[0]);
        $this->assertEquals(['user:9002', 'role:9002', 'tenant:1'], $testPolicies[1]);
        $this->assertEquals(['user:9003', 'role:9003', 'tenant:2'], $testPolicies[2]);
    }

    /**
     * 测试权限分配加载
     * Test permission assignment loading
     */
    public function testLoadPermissionAssignments(): void
    {
        // 加载策略
        // Load policies
        $this->adapter->loadPolicy($this->model);

        // 获取 p 策略
        // Get p policies
        $pPolicies = $this->model->getPolicy('p', 'p');

        // 过滤出测试数据（role_id >= 9000）
        // Filter test data (role_id >= 9000)
        $testPolicies = array_filter($pPolicies, function ($policy) {
            return str_starts_with($policy[0], 'role:900');
        });

        // 验证策略数量
        // Verify policy count
        $this->assertCount(5, $testPolicies);

        // 验证策略格式（检查第一条）
        // Verify policy format (check first one)
        $testPolicies = array_values($testPolicies); // 重新索引
        $this->assertEquals(['role:9001', 'tenant:1', 'casbin_test_forms', 'view'], $testPolicies[0]);
    }

    /**
     * 测试多租户隔离
     * Test multi-tenancy isolation
     */
    public function testMultiTenancyIsolation(): void
    {
        // 加载策略
        // Load policies
        $this->adapter->loadPolicy($this->model);

        // 获取 g 策略
        // Get g policies
        $gPolicies = $this->model->getPolicy('g', 'g');

        // 过滤出测试数据
        // Filter test data
        $testPolicies = array_filter($gPolicies, function ($policy) {
            return str_starts_with($policy[0], 'user:900');
        });

        // 验证租户 1 的策略
        // Verify tenant 1 policies
        $tenant1Policies = array_filter($testPolicies, function ($policy) {
            return $policy[2] === 'tenant:1';
        });
        $this->assertCount(2, $tenant1Policies);

        // 验证租户 2 的策略
        // Verify tenant 2 policies
        $tenant2Policies = array_filter($testPolicies, function ($policy) {
            return $policy[2] === 'tenant:2';
        });
        $this->assertCount(1, $tenant2Policies);
    }

    /**
     * 测试空数据加载
     * Test loading with empty data
     */
    public function testLoadPolicyWithEmptyData(): void
    {
        // 清理所有测试数据
        // Clean all test data
        $this->cleanupTestData();

        // 加载策略
        // Load policies
        $this->adapter->loadPolicy($this->model);

        // 获取策略
        // Get policies
        $gPolicies = $this->model->getPolicy('g', 'g');
        $pPolicies = $this->model->getPolicy('p', 'p');

        // 过滤出测试数据（应该为空）
        // Filter test data (should be empty)
        $testGPolicies = array_filter($gPolicies, function ($policy) {
            return str_starts_with($policy[0], 'user:900');
        });
        $testPPolicies = array_filter($pPolicies, function ($policy) {
            return str_starts_with($policy[0], 'role:900');
        });

        $this->assertEmpty($testGPolicies);
        $this->assertEmpty($testPPolicies);
    }

    /**
     * 测试 savePolicy 抛出异常
     * Test savePolicy throws exception
     */
    public function testSavePolicyThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('read-only mode');
        
        $this->adapter->savePolicy($this->model);
    }

    /**
     * 测试 addPolicy 抛出异常
     * Test addPolicy throws exception
     */
    public function testAddPolicyThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('read-only mode');
        
        $this->adapter->addPolicy('p', 'p', ['role:1', 'tenant:1', 'casbin_test_forms', 'view']);
    }

    /**
     * 测试 removePolicy 抛出异常
     * Test removePolicy throws exception
     */
    public function testRemovePolicyThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('read-only mode');
        
        $this->adapter->removePolicy('p', 'p', ['role:1', 'tenant:1', 'casbin_test_forms', 'view']);
    }

    /**
     * 测试 removeFilteredPolicy 抛出异常
     * Test removeFilteredPolicy throws exception
     */
    public function testRemoveFilteredPolicyThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('read-only mode');
        
        $this->adapter->removeFilteredPolicy('p', 'p', 0, 'role:1');
    }
}

