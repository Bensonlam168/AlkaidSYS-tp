<?php

use think\migration\Migrator;

/**
 * Create User Roles Table Migration | 创建用户角色关联表迁移
 *
 * Creates the user_roles pivot table.
 * 创建用户角色中间表。
 */
class CreateUserRolesTable extends Migrator
{
    /**
     * Run migration | 执行迁移
     */
    public function change(): void
    {
        $table = $this->table('user_roles', [
            'id' => false,
            'primary_key' => ['user_id', 'role_id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'User roles pivot table | 用户角色关联表',
        ]);

        $table
            ->addColumn('user_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'User ID | 用户ID',
            ])
            ->addColumn('role_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'Role ID | 角色ID',
            ])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => 'Created at | 创建时间',
            ])
            ->addIndex(['user_id'], ['name' => 'idx_user_id'])
            ->addIndex(['role_id'], ['name' => 'idx_role_id'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_user_roles_user_id'
            ])
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_user_roles_role_id'
            ])
            ->create();
    }
}
