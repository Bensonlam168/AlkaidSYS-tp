<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission;

use Infrastructure\Permission\Service\PermissionService;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

// Load ThinkPHP helper functions | 加载 ThinkPHP 助手函数
if (!function_exists('env')) {
    require_once __DIR__ . '/../../../../vendor/topthink/framework/src/helper.php';
}

/**
 * Permission Service Test | 权限服务测试
 *
 * Tests the PermissionService class.
 * 测试 PermissionService 类。
 *
 * @package Tests\Unit\Infrastructure\Permission
 */
class PermissionServiceTest extends ThinkPHPTestCase
{
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = new PermissionService();
    }

    /**
     * Test getUserPermissions with no roles | 测试无角色用户的权限
     */
    public function testGetUserPermissionsWithNoRoles(): void
    {
        // Create a user with no roles | 创建一个没有角色的用户
        $userId = 999;  // Non-existent user | 不存在的用户

        $permissions = $this->permissionService->getUserPermissions($userId);

        // Should return empty array | 应该返回空数组
        $this->assertIsArray($permissions);
        $this->assertEmpty($permissions);
    }

    /**
     * Test getUserPermissions with single role | 测试单角色用户的权限
     */
    public function testGetUserPermissionsWithSingleRole(): void
    {
        // Use the default admin user (ID: 1) from seed data | 使用种子数据中的默认管理员用户
        $userId = 1;

        $permissions = $this->permissionService->getUserPermissions($userId);

        // Should return array of permissions | 应该返回权限数组
        $this->assertIsArray($permissions);
        $this->assertNotEmpty($permissions);

        // Check format: resource:action | 检查格式：resource:action
        foreach ($permissions as $permission) {
            $this->assertIsString($permission);
            $this->assertStringContainsString(':', $permission);

            // Split and verify format | 分割并验证格式
            $parts = explode(':', $permission);
            $this->assertCount(2, $parts);
            $this->assertNotEmpty($parts[0]);  // resource
            $this->assertNotEmpty($parts[1]);  // action
        }
    }

    /**
     * Test permission format conversion | 测试权限格式转换
     */
    public function testPermissionFormatConversion(): void
    {
        // Use the default admin user | 使用默认管理员用户
        $userId = 1;

        $permissions = $this->permissionService->getUserPermissions($userId);

        // Admin should have forms.view permission in DB | 管理员应该有 forms.view 权限（数据库中）
        // It should be converted to forms:view | 应该转换为 forms:view
        $this->assertContains('forms:view', $permissions);
        $this->assertContains('forms:create', $permissions);
        $this->assertContains('forms:update', $permissions);
        $this->assertContains('forms:delete', $permissions);
    }

    /**
     * Test getUserPermissions with multiple roles | 测试多角色用户的权限
     */
    public function testGetUserPermissionsWithMultipleRoles(): void
    {
        // Use admin user (ID: 1) who already has roles | 使用已有角色的管理员用户
        $userId = 1;

        $permissions = $this->permissionService->getUserPermissions($userId);

        // Should return unique permissions from all roles | 应该返回所有角色的唯一权限
        $this->assertIsArray($permissions);
        $this->assertNotEmpty($permissions);

        // Should not have duplicates | 不应该有重复
        $this->assertEquals(count($permissions), count(array_unique($permissions)));
    }

    /**
     * Test hasPermission method | 测试 hasPermission 方法
     */
    public function testHasPermission(): void
    {
        $userId = 1;  // Admin user | 管理员用户

        // Admin should have forms:view permission | 管理员应该有 forms:view 权限
        $this->assertTrue($this->permissionService->hasPermission($userId, 'forms:view'));

        // Admin should not have non-existent permission | 管理员不应该有不存在的权限
        $this->assertFalse($this->permissionService->hasPermission($userId, 'nonexistent:permission'));
    }

    /**
     * Test hasAnyPermission method | 测试 hasAnyPermission 方法
     */
    public function testHasAnyPermission(): void
    {
        $userId = 1;  // Admin user | 管理员用户

        // Should return true if user has any of the permissions | 如果用户有任一权限应返回 true
        $this->assertTrue($this->permissionService->hasAnyPermission($userId, [
            'forms:view',
            'nonexistent:permission'
        ]));

        // Should return false if user has none of the permissions | 如果用户没有任何权限应返回 false
        $this->assertFalse($this->permissionService->hasAnyPermission($userId, [
            'nonexistent:permission1',
            'nonexistent:permission2'
        ]));
    }

    /**
     * Test hasAllPermissions method | 测试 hasAllPermissions 方法
     */
    public function testHasAllPermissions(): void
    {
        $userId = 1;  // Admin user | 管理员用户

        // Should return true if user has all permissions | 如果用户有所有权限应返回 true
        $this->assertTrue($this->permissionService->hasAllPermissions($userId, [
            'forms:view',
            'forms:create'
        ]));

        // Should return false if user is missing any permission | 如果用户缺少任何权限应返回 false
        $this->assertFalse($this->permissionService->hasAllPermissions($userId, [
            'forms:view',
            'nonexistent:permission'
        ]));
    }

    /**
     * Test permission deduplication | 测试权限去重
     */
    public function testPermissionDeduplication(): void
    {
        $userId = 1;  // Admin user | 管理员用户

        $permissions = $this->permissionService->getUserPermissions($userId);

        // Should not have duplicates | 不应该有重复
        $this->assertEquals(count($permissions), count(array_unique($permissions)));
    }

    /**
     * Test tenant isolation in permission queries | 测试权限查询中的多租户隔离
     *
     * This test simulates a cross-tenant role assignment and asserts that
     * PermissionService::getUserPermissions only returns permissions from
     * roles that belong to the same tenant as the user.
     *
     * 本测试模拟跨租户角色关联场景，验证 PermissionService::getUserPermissions
     * 只返回与用户所属租户匹配的角色权限，实现显式多租户隔离。
     */
    public function testGetUserPermissionsEnforcesTenantIsolation(): void
    {
        $userId = 1; // Seeded admin user in tenant 1 | 种子数据中的租户1管理员用户

        // 1. Create a second tenant | 创建第二个租户
        $secondTenantId = Db::name('tenants')->insertGetId([
            'name'       => 'Second Tenant',
            'slug'       => 'second-tenant-' . uniqid(),
            'domain'     => null,
            'status'     => 'active',
            'config'     => json_encode(['locale' => 'zh_CN', 'timezone' => 'Asia/Shanghai']),
            'max_sites'  => 5,
            'max_users'  => 50,
            'expires_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 2. Create a role for the second tenant | 为第二个租户创建角色
        $crossTenantRoleId = Db::name('roles')->insertGetId([
            'tenant_id'  => $secondTenantId,
            'name'       => 'Cross Tenant Role',
            'slug'       => 'cross-tenant-role-' . uniqid(),
            'description' => 'Role from another tenant used for isolation tests',
            'is_system'  => false,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 3. Create a permission that only this cross-tenant role has | 创建仅跨租户角色拥有的权限
        $crossTenantPermissionSlug = 'cross_tenant.test.' . uniqid();
        $crossTenantResource = 'cross_tenant';
        $crossTenantAction = 'test';

        $crossTenantPermissionId = Db::name('permissions')->insertGetId([
            'name'        => 'Cross Tenant Test Permission',
            'slug'        => $crossTenantPermissionSlug,
            'resource'    => $crossTenantResource,
            'action'      => $crossTenantAction,
            'description' => 'Permission used to verify tenant isolation in PermissionService tests',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // 4. Link the permission to the second-tenant role | 将权限关联到第二个租户的角色
        Db::name('role_permissions')->insert([
            'role_id'     => $crossTenantRoleId,
            'permission_id' => $crossTenantPermissionId,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        // 5. (Intentionally) assign this second-tenant role to tenant-1 user | 有意将第二租户角色分配给租户1用户
        Db::name('user_roles')->insert([
            'user_id'    => $userId,
            'role_id'    => $crossTenantRoleId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 6. Fetch permissions for the admin user | 获取管理员用户的权限
        $permissions = $this->permissionService->getUserPermissions($userId);

        // 7. The cross-tenant permission must NOT be present due to tenant isolation
        // 由于多租户隔离，跨租户角色对应的权限不应出现在结果中
        $crossTenantCode = $crossTenantResource . ':' . $crossTenantAction;
        $this->assertNotContains($crossTenantCode, $permissions);
    }
}
