<?php

use think\migration\Migrator;

/**
 * Create Roles Table Migration | 创建角色表迁移
 * 
 * Creates the roles table for RBAC support.
 * 为RBAC支持创建角色表。
 */
class CreateRolesTable extends Migrator
{
    /**
     * Run migration | 执行迁移
     */
    public function change(): void
    {
        $table = $this->table('roles', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Roles table | 角色表',
        ]);

        $table
            ->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false,
                'comment' => 'Role ID | 角色ID',
            ])
            ->addColumn('tenant_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'Tenant ID | 租户ID',
            ])
            ->addColumn('name', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => 'Role name | 角色名称',
            ])
            ->addColumn('slug', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => 'Role slug | 角色标识符',
            ])
            ->addColumn('description', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Role description | 角色描述',
            ])
            ->addColumn('is_system', 'boolean', [
                'default' => false,
                'comment' => 'Is system role | 是否系统角色',
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
            ->addIndex(['tenant_id'], ['name' => 'idx_tenant_id'])
            ->addIndex(['slug'], ['name' => 'idx_slug'])
            ->addIndex(['tenant_id', 'slug'], ['unique' => true, 'name' => 'idx_tenant_slug'])
            ->addForeignKey('tenant_id', 'tenants', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_roles_tenant_id'
            ])
            ->create();
    }
}
