<?php

use think\migration\Migrator;

/**
 * Create Role Permissions Table Migration | 创建角色权限关联表迁移
 *
 * Creates the role_permissions pivot table.
 * 创建角色权限中间表。
 */
class CreateRolePermissionsTable extends Migrator
{
    /**
     * Run migration | 执行迁移
     */
    public function change(): void
    {
        $table = $this->table('role_permissions', [
            'id' => false,
            'primary_key' => ['role_id', 'permission_id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Role permissions pivot table | 角色权限关联表',
        ]);

        $table
            ->addColumn('role_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'Role ID | 角色ID',
            ])
            ->addColumn('permission_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'Permission ID | 权限ID',
            ])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => 'Created at | 创建时间',
            ])
            ->addIndex(['role_id'], ['name' => 'idx_role_id'])
            ->addIndex(['permission_id'], ['name' => 'idx_permission_id'])
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_role_permissions_role_id'
            ])
            ->addForeignKey('permission_id', 'permissions', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_role_permissions_permission_id'
            ])
            ->create();
    }
}
