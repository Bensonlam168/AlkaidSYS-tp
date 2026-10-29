<?php

use think\migration\Migrator;

/**
 * Create Depts and Menus Tables Migration | 创建部门和菜单表迁移
 *
 * Creates the departments and menus tables for organization and navigation management.
 * 为组织架构和导航管理创建部门表和菜单表。
 */
class CreateDeptsAndMenusTables extends Migrator
{
    /**
     * Run migration | 执行迁移
     */
    public function change(): void
    {
        $this->createDeptsTable();
        $this->createMenusTable();
    }

    /**
     * Create departments table | 创建部门表
     */
    protected function createDeptsTable(): void
    {
        $table = $this->table('depts', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Departments table | 部门表',
        ]);

        $table
            ->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false,
                'comment' => 'Department ID | 部门ID',
            ])
            ->addColumn('tenant_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'Tenant ID | 租户ID',
            ])
            ->addColumn('pid', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'comment' => 'Parent department ID | 父部门ID',
            ])
            ->addColumn('name', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => 'Department name | 部门名称',
            ])
            ->addColumn('status', 'boolean', [
                'default' => true,
                'comment' => 'Status: 1=active, 0=inactive | 状态：1=启用，0=停用',
            ])
            ->addColumn('sort', 'integer', [
                'signed' => false,
                'default' => 0,
                'comment' => 'Sort order | 排序',
            ])
            ->addColumn('remark', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Remark | 备注',
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
            ->addIndex(['tenant_id'], ['name' => 'idx_depts_tenant_id'])
            ->addIndex(['tenant_id', 'pid'], ['name' => 'idx_depts_tenant_pid'])
            ->addIndex(['tenant_id', 'name'], ['name' => 'idx_depts_tenant_name'])
            ->addForeignKey('tenant_id', 'tenants', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_depts_tenant_id'
            ])
            ->create();
    }

    /**
     * Create menus table | 创建菜单表
     */
    protected function createMenusTable(): void
    {
        $table = $this->table('menus', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Menus table | 菜单表',
        ]);

        $table
            ->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false,
                'comment' => 'Menu ID | 菜单ID',
            ])
            ->addColumn('tenant_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'Tenant ID | 租户ID',
            ])
            ->addColumn('pid', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'comment' => 'Parent menu ID | 父菜单ID',
            ])
            ->addColumn('name', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => 'Menu name (route name) | 菜单名称（路由名称）',
            ])
            ->addColumn('type', 'enum', [
                'values' => ['catalog', 'menu', 'embedded', 'link', 'button'],
                'default' => 'menu',
                'comment' => 'Menu type | 菜单类型',
            ])
            ->addColumn('path', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Route path | 路由路径',
            ])
            ->addColumn('component', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Component path | 组件路径',
            ])
            ->addColumn('redirect', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Redirect path | 重定向路径',
            ])
            ->addColumn('auth_code', 'string', [
                'limit' => 100,
                'null' => true,
                'comment' => 'Auth code for permission check | 权限标识',
            ])
            ->addColumn('meta', 'json', [
                'null' => true,
                'comment' => 'Menu meta data (title, icon, etc.) | 菜单元数据（标题、图标等）',
            ])
            ->addColumn('status', 'boolean', [
                'default' => true,
                'comment' => 'Status: 1=active, 0=inactive | 状态：1=启用，0=停用',
            ])
            ->addColumn('sort', 'integer', [
                'signed' => false,
                'default' => 0,
                'comment' => 'Sort order | 排序',
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
            ->addIndex(['tenant_id'], ['name' => 'idx_menus_tenant_id'])
            ->addIndex(['tenant_id', 'pid'], ['name' => 'idx_menus_tenant_pid'])
            ->addIndex(['tenant_id', 'name'], ['unique' => true, 'name' => 'idx_menus_tenant_name'])
            ->addIndex(['tenant_id', 'path'], ['name' => 'idx_menus_tenant_path'])
            ->addIndex(['auth_code'], ['name' => 'idx_menus_auth_code'])
            ->addForeignKey('tenant_id', 'tenants', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_menus_tenant_id'
            ])
            ->create();
    }
}
