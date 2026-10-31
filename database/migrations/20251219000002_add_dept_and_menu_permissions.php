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
        //
        // 【容错：角色不存在时跳过分配（R43 修复）】
        // 管理员角色由 database/seeds/CorePlatformSeed.php 创建，而种子数据要在迁移之后才执行；
        // 全新数据库上执行 `php think migrate:run` 时 roles 表还是空的，
        // 此时若直接 INSERT ... SELECT 1 会命中外键 fk_role_permissions_role_id，报 1452 并中断整条迁移链。
        //
        // 因此追加 EXISTS (SELECT 1 FROM roles WHERE id = 1) 作为守卫：角色不存在就一行也不插，
        // 权限记录（上面的 6 条 dept.* / menu.*）照常写入，迁移正常完成。
        //
        // 跳过分配 ≠ 丢失授权：seeder 的 assignPermissionsToRoles() 已改为按 permissions 表的**全部**权限
        // 重新分配给管理员，因此 `migrate:run` + `seed:run` 之后管理员仍然拥有 dept.* / menu.*。
        // 注：seeder 自身还有其它「不可重复执行」的历史缺陷（createPermissions 硬编码 id 1..20，
        // 在有既有数据的库上会撞主键），已在 R43 报告中单列，不在本条迁移的修复范围内。
        //
        // 这里用 EXISTS 而不是 JOIN roles：EXISTS 只是条件守卫，语义上「角色存在才分配」一目了然；
        // JOIN 写法会让人误以为需要从 roles 取数据（实际只用来判存在）。
        $this->execute("
            INSERT INTO role_permissions (role_id, permission_id, created_at)
            SELECT 1, p.id, NOW()
            FROM permissions p
            WHERE p.resource IN ('dept', 'menu')
            AND EXISTS (
                SELECT 1 FROM roles r WHERE r.id = 1
            )
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
