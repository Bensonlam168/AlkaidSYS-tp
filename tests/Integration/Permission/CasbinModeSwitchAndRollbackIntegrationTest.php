<?php

declare(strict_types=1);

namespace Tests\Integration\Permission;

use Infrastructure\Permission\Service\CasbinService;
use Infrastructure\Permission\Service\PermissionService;
use Tests\ThinkPHPTestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * Casbin 模式切换与策略回滚集成测试
 * Casbin mode switch and policy rollback integration tests.
 *
 * 本测试用例聚焦 T-012：验证在 DB_ONLY / CASBIN_ONLY 模式之间切换及回滚时，
 * 管理员权限集合保持一致；并验证在角色/权限变更后，通过 Casbin 策略刷新与回滚
 * 能够让数据库 RBAC 与 Casbin 策略重新保持一致。
 */
class CasbinModeSwitchAndRollbackIntegrationTest extends ThinkPHPTestCase
{
    private ?bool $originalCasbinEnabled = null;

    private ?string $originalCasbinMode = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 记录原始配置，测试结束后恢复
        // Remember original configuration and restore it in tearDown
        $this->originalCasbinEnabled = Config::get('casbin.enabled');
        $this->originalCasbinMode = Config::get('casbin.mode');

        // 确保测试数据干净
        // Ensure test-specific rows are clean
        $this->cleanupPolicyRollbackTestData();
    }

    protected function tearDown(): void
    {
        // 清理本测试创建的数据
        // Clean up data created by this test
        $this->cleanupPolicyRollbackTestData();

        // 恢复 Casbin 配置
        // Restore Casbin configuration
        if ($this->originalCasbinEnabled !== null) {
            Config::set(['casbin.enabled' => $this->originalCasbinEnabled]);
        }
        if ($this->originalCasbinMode !== null) {
            Config::set(['casbin.mode' => $this->originalCasbinMode]);
        }

        parent::tearDown();
    }

    /**
     * 清理策略回滚测试使用的数据
     * Clean up data used by policy rollback tests
     */
    private function cleanupPolicyRollbackTestData(): void
    {
        Db::table('role_permissions')->where('role_id', 9200)->delete();
        Db::table('user_roles')->where('user_id', 9200)->delete();
        Db::table('permissions')->where('id', 9200)->delete();
        Db::table('roles')->where('id', 9200)->delete();
        Db::table('users')->where('id', 9200)->delete();
    }

    /**
     * 为策略回滚场景创建专用测试数据
     * Create dedicated test data for policy rollback scenario
     */
    private function createPolicyRollbackTestData(): void
    {
        Db::table('users')->insert([
            'id' => 9200,
            'tenant_id' => 1,
            'username' => 't012_policy_user',
            'email' => 't012_policy_user@test.com',
            'password' => 'test',
            'status' => 'active',
        ]);

        Db::table('roles')->insert([
            'id' => 9200,
            'tenant_id' => 1,
            'name' => 'T012 Policy Test Role',
            'slug' => 't012_policy_role',
            'description' => 'Role used in Casbin policy rollback tests',
        ]);

        Db::table('permissions')->insert([
            'id' => 9200,
            'name' => 'T012 Policy Test Permission',
            'slug' => 't012_policy.view',
            'resource' => 't012_policy',
            'action' => 'view',
            'description' => 'Permission used in Casbin policy rollback tests',
        ]);

        Db::table('user_roles')->insert([
            'user_id' => 9200,
            'role_id' => 9200,
        ]);

        Db::table('role_permissions')->insert([
            'role_id' => 9200,
            'permission_id' => 9200,
        ]);
    }

    /**
     * 测试在 DB_ONLY → DUAL_MODE → CASBIN_ONLY 切换与回滚时，管理员权限集合的等价性与包含关系
     * Test that switching DB_ONLY → DUAL_MODE → CASBIN_ONLY and rolling back keeps
     * admin permissions equivalent, and that DUAL_MODE behaves as the union of DB and Casbin.
     */
    public function testModeSwitchAcrossDbOnlyDualModeAndCasbinOnlyIsReversibleForAdminUser(): void
    {
        $userId = 1; // Seeded admin user from test data | 种子数据中的管理员用户

        // ========== 基线：DB_ONLY 模式 ==========
        // ========== Baseline: DB_ONLY mode ==========
        Config::set([
            'casbin.enabled' => false,
            'casbin.mode' => 'DB_ONLY',
        ]);
        $dbOnlyService = new PermissionService();
        $dbOnlyPermissions = $dbOnlyService->getUserPermissions($userId);
        $this->assertNotEmpty($dbOnlyPermissions, 'Admin permissions in DB_ONLY mode should not be empty');

        // ========== 切换到 DUAL_MODE：应至少包含 DB_ONLY 的所有权限 ==========
        // ========== Switch to DUAL_MODE: must include all DB_ONLY permissions ==========
        Config::set([
            'casbin.enabled' => true,
            'casbin.mode' => 'DUAL_MODE',
        ]);
        $dualModeCasbinService = new CasbinService();
        $dualModeCasbinService->reloadPolicy();
        $dualModeService = new PermissionService($dualModeCasbinService);
        $dualModePermissions = $dualModeService->getUserPermissions($userId);

        foreach ($dbOnlyPermissions as $permission) {
            $this->assertContains(
                $permission,
                $dualModePermissions,
                'DUAL_MODE permissions must include all DB_ONLY permissions for seeded admin user'
            );
        }

        // ========== 切换到 CASBIN_ONLY：应包含在 DUAL_MODE 的并集中 ==========
        // ========== Switch to CASBIN_ONLY: permissions must be contained in DUAL_MODE union ==========
        Config::set([
            'casbin.enabled' => true,
            'casbin.mode' => 'CASBIN_ONLY',
        ]);
        $casbinService = new CasbinService();
        $casbinService->reloadPolicy();
        $casbinOnlyService = new PermissionService($casbinService);
        $casbinPermissions = $casbinOnlyService->getUserPermissions($userId);

        foreach ($casbinPermissions as $permission) {
            $this->assertContains(
                $permission,
                $dualModePermissions,
                'DUAL_MODE permissions must include all CASBIN_ONLY permissions for seeded admin user'
            );
        }

        // ========== 额外保证：DB_ONLY 与 CASBIN_ONLY 权限集对管理员等价 ==========
        // ========== Additional guarantee: DB_ONLY and CASBIN_ONLY sets are equivalent for admin ==========
        $sortedDbOnlyPermissions = $dbOnlyPermissions;
        sort($sortedDbOnlyPermissions);
        $sortedCasbinPermissions = $casbinPermissions;
        sort($sortedCasbinPermissions);
        $this->assertSame(
            $sortedDbOnlyPermissions,
            $sortedCasbinPermissions,
            'DB_ONLY and CASBIN_ONLY permission sets should be equivalent for seeded admin user'
        );

        // ========== 回滚到 DB_ONLY：应恢复到初始权限集 ==========
        // ========== Roll back to DB_ONLY: must restore original permission set ==========
        Config::set([
            'casbin.enabled' => false,
            'casbin.mode' => 'DB_ONLY',
        ]);
        $rolledBackService = new PermissionService();
        $rolledBackPermissions = $rolledBackService->getUserPermissions($userId);

        sort($rolledBackPermissions);

        $this->assertSame(
            $sortedDbOnlyPermissions,
            $rolledBackPermissions,
            'Rolling back from CASBIN_ONLY to DB_ONLY must restore the original permission set'
        );
    }

    /**
     * 测试权限变更后，通过策略刷新与回滚让 Casbin 与数据库重新保持一致
     * Test that after permission change, policy reload and rollback keep Casbin in sync with database.
     */
    public function testPermissionChangeReloadAndRollbackWithCasbinKeepsInSyncWithDatabase(): void
    {
        $userId = 9200;
        $permissionCode = 't012_policy:view';

        $this->createPolicyRollbackTestData();

        // ========== 基线：DB_ONLY 路径看到权限 ==========
        // ========== Baseline: DB_ONLY path sees permission ==========
        Config::set([
            'casbin.enabled' => false,
            'casbin.mode' => 'DB_ONLY',
        ]);
        $dbService = new PermissionService();
        $this->assertTrue(
            $dbService->hasPermission($userId, $permissionCode),
            'DB_ONLY path should see permission before change'
        );

        // ========== 基线：CASBIN_ONLY 路径在刷新策略后也能看到权限 ==========
        // ========== Baseline: CASBIN_ONLY path sees permission after policy reload ==========
        Config::set([
            'casbin.enabled' => true,
            'casbin.mode' => 'CASBIN_ONLY',
        ]);
        $casbinService = new CasbinService();
        $casbinService->reloadPolicy();
        $casbinPermissionService = new PermissionService($casbinService);
        $this->assertTrue(
            $casbinPermissionService->hasPermission($userId, $permissionCode),
            'CASBIN_ONLY path should also see permission before change'
        );

        // ========== 执行权限变更：移除角色-权限关联 ==========
        // ========== Apply permission change: remove role-permission relation ==========
        Db::table('role_permissions')
            ->where('role_id', 9200)
            ->where('permission_id', 9200)
            ->delete();

        // ========== 变更后：DB_ONLY 路径不再看到该权限 ==========
        // ========== After change: DB_ONLY path no longer sees the permission ==========
        Config::set([
            'casbin.enabled' => false,
            'casbin.mode' => 'DB_ONLY',
        ]);
        $dbServiceAfter = new PermissionService();
        $this->assertFalse(
            $dbServiceAfter->hasPermission($userId, $permissionCode),
            'After revocation DB_ONLY path should not have permission'
        );

        // ========== 变更后：在 CASBIN_ONLY 模式下显式刷新策略后，也不再看到该权限 ==========
        // ========== After change: in CASBIN_ONLY mode, explicit policy reload should also drop the permission ==========
        Config::set([
            'casbin.enabled' => true,
            'casbin.mode' => 'CASBIN_ONLY',
        ]);
        $casbinServiceAfter = new CasbinService();
        $casbinServiceAfter->reloadPolicy();
        $casbinPermissionServiceAfter = new PermissionService($casbinServiceAfter);
        $this->assertFalse(
            $casbinPermissionServiceAfter->hasPermission($userId, $permissionCode),
            'After revocation and policy reload CASBIN_ONLY path should not have permission'
        );

        // ========== 回滚：恢复角色-权限关联 ==========
        // ========== Rollback: restore role-permission relation ==========
        Db::table('role_permissions')->insert([
            'role_id' => 9200,
            'permission_id' => 9200,
        ]);

        // ========== 回滚后：DB_ONLY 路径重新看到该权限 ==========
        // ========== After rollback: DB_ONLY path sees the permission again ==========
        Config::set([
            'casbin.enabled' => false,
            'casbin.mode' => 'DB_ONLY',
        ]);
        $dbServiceRollback = new PermissionService();
        $this->assertTrue(
            $dbServiceRollback->hasPermission($userId, $permissionCode),
            'After rollback DB_ONLY path should restore permission'
        );

        // ========== 回滚后：CASBIN_ONLY 模式下刷新策略后也重新看到该权限 ==========
        // ========== After rollback: in CASBIN_ONLY mode, policy reload should restore the permission ==========
        Config::set([
            'casbin.enabled' => true,
            'casbin.mode' => 'CASBIN_ONLY',
        ]);
        $casbinServiceRollback = new CasbinService();
        $casbinServiceRollback->reloadPolicy();
        $casbinPermissionServiceRollback = new PermissionService($casbinServiceRollback);
        $this->assertTrue(
            $casbinPermissionServiceRollback->hasPermission($userId, $permissionCode),
            'After rollback and policy reload CASBIN_ONLY path should restore permission'
        );
    }
}
