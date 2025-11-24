<?php

use think\migration\Migrator;

/**
 * Create Permissions Table Migration | 创建权限表迁移
 * 
 * Creates the permissions table for RBAC support.
 * 为RBAC支持创建权限表。
 */
class CreatePermissionsTable extends Migrator
{
    /**
     * Run migration | 执行迁移
     */
    public function change(): void
    {
        $table = $this->table('permissions', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Permissions table | 权限表',
        ]);

        $table
            ->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false,
                'comment' => 'Permission ID | 权限ID',
            ])
            ->addColumn('name', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => 'Permission name | 权限名称',
            ])
            ->addColumn('slug', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => 'Permission slug | 权限标识符',
            ])
            ->addColumn('resource', 'string', [
                'limit' => 50,
                'null' => true,
                'comment' => 'Resource type | 资源类型',
            ])
            ->addColumn('action', 'string', [
                'limit' => 50,
                'null' => true,
                'comment' => 'Action type | 操作类型',
            ])
            ->addColumn('description', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Permission description | 权限描述',
            ])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => 'Created at | 创建时间',
            ])
            ->addColumn('updated_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'comment' => 'Updated at | 更新时间',
            ])
            ->addIndex(['slug'], ['unique' => true, 'name' => 'idx_slug'])
            ->addIndex(['resource', 'action'], ['name' => 'idx_resource_action'])
            ->create();
    }
}
