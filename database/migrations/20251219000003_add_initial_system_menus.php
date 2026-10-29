<?php

use think\migration\Migrator;

/**
 * Add Initial System Menus Migration | 添加初始系统菜单迁移
 *
 * Creates default system menu structure for tenant 1.
 * 为租户 1 创建默认系统菜单结构。
 */
class AddInitialSystemMenus extends Migrator
{
    /**
     * Run migration | 执行迁移
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $tenantId = 1;

        // System Management catalog | 系统管理目录
        $systemCatalogId = $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => null,
            'name' => 'System',
            'type' => 'catalog',
            'path' => '/system',
            'component' => null,
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => '系统管理',
                'icon' => 'lucide:settings',
                'order' => 9000,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 9000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // User Management | 用户管理
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $systemCatalogId,
            'name' => 'UserManagement',
            'type' => 'menu',
            'path' => '/system/user',
            'component' => '/system/user/list',
            'redirect' => null,
            'auth_code' => 'user.read',
            'meta' => json_encode([
                'title' => '用户管理',
                'icon' => 'lucide:user',
                'order' => 1,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Role Management | 角色管理
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $systemCatalogId,
            'name' => 'RoleManagement',
            'type' => 'menu',
            'path' => '/system/role',
            'component' => '/system/role/list',
            'redirect' => null,
            'auth_code' => 'role.read',
            'meta' => json_encode([
                'title' => '角色管理',
                'icon' => 'lucide:users',
                'order' => 2,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Permission Management | 权限管理
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $systemCatalogId,
            'name' => 'PermissionManagement',
            'type' => 'menu',
            'path' => '/system/permission',
            'component' => '/system/permission/list',
            'redirect' => null,
            'auth_code' => 'permission.read',
            'meta' => json_encode([
                'title' => '权限管理',
                'icon' => 'lucide:shield',
                'order' => 3,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Tenant Management | 租户管理
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $systemCatalogId,
            'name' => 'TenantManagement',
            'type' => 'menu',
            'path' => '/system/tenant',
            'component' => '/system/tenant/list',
            'redirect' => null,
            'auth_code' => 'tenant.read',
            'meta' => json_encode([
                'title' => '租户管理',
                'icon' => 'lucide:building',
                'order' => 4,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 4,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Department Management | 部门管理
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $systemCatalogId,
            'name' => 'DeptManagement',
            'type' => 'menu',
            'path' => '/system/dept',
            'component' => '/system/dept/list',
            'redirect' => null,
            'auth_code' => 'dept.read',
            'meta' => json_encode([
                'title' => '部门管理',
                'icon' => 'lucide:network',
                'order' => 5,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Menu Management | 菜单管理
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $systemCatalogId,
            'name' => 'MenuManagement',
            'type' => 'menu',
            'path' => '/system/menu',
            'component' => '/system/menu/list',
            'redirect' => null,
            'auth_code' => 'menu.read',
            'meta' => json_encode([
                'title' => '菜单管理',
                'icon' => 'lucide:menu',
                'order' => 6,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 6,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Rollback migration | 回滚迁移
     */
    public function down(): void
    {
        $this->execute("DELETE FROM menus WHERE tenant_id = 1 AND name IN (
            'System', 'UserManagement', 'RoleManagement', 'PermissionManagement',
            'TenantManagement', 'DeptManagement', 'MenuManagement'
        )");
    }

    /**
     * Insert menu and return ID | 插入菜单并返回 ID
     */
    private function insertMenu(array $data): int
    {
        $this->table('menus')->insert($data)->saveData();
        return $this->fetchRow("SELECT LAST_INSERT_ID() as id")['id'];
    }
}
