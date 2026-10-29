<?php

use think\migration\Migrator;

/**
 * Add Dept and Menu Permissions Migration | 添加部门和菜单权限迁移
 *
 * Adds permissions for department and menu management.
 * 添加部门管理和菜单管理的权限记录。
 */
class AddDeptAndMenuPermissions extends Migrator
{
    /**
     * Run migration | 执行迁移
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $permissions = [
            // Department permissions | 部门权限
            [
                'name' => '查看部门',
                'slug' => 'dept.read',
                'resource' => 'dept',
                'action' => 'read',
                'description' => 'View department list and details | 查看部门列表和详情',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => '编辑部门',
                'slug' => 'dept.write',
                'resource' => 'dept',
                'action' => 'write',
                'description' => 'Create and update departments | 创建和更新部门',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => '删除部门',
                'slug' => 'dept.delete',
                'resource' => 'dept',
                'action' => 'delete',
                'description' => 'Delete departments | 删除部门',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            // Menu permissions | 菜单权限
            [
                'name' => '查看菜单',
                'slug' => 'menu.read',
                'resource' => 'menu',
                'action' => 'read',
                'description' => 'View menu list and details | 查看菜单列表和详情',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => '编辑菜单',
                'slug' => 'menu.write',
                'resource' => 'menu',
                'action' => 'write',
                'description' => 'Create and update menus | 创建和更新菜单',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => '删除菜单',
                'slug' => 'menu.delete',
                'resource' => 'menu',
                'action' => 'delete',
                'description' => 'Delete menus | 删除菜单',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $this->table('permissions')->insert($permissions)->saveData();

        // Assign permissions to Administrator role (role_id = 1) | 将权限分配给管理员角色
        $this->execute("
            INSERT INTO role_permissions (role_id, permission_id, created_at)
            SELECT 1, p.id, NOW()
            FROM permissions p
            WHERE p.resource IN ('dept', 'menu')
            AND NOT EXISTS (
                SELECT 1 FROM role_permissions rp
                WHERE rp.role_id = 1 AND rp.permission_id = p.id
            )
        ");
    }

    /**
     * Rollback migration | 回滚迁移
     */
    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE resource IN ('dept', 'menu')");
    }
}
