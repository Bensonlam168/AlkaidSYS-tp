<?php

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * Create Tenants Table Migration | 创建租户表迁移
 * 
 * Creates the tenants table for multi-tenant support.
 * 为多租户支持创建租户表。
 */
class CreateTenantsTable extends Migrator
{
    /**
     * Run migration | 执行迁移
     */
    public function change(): void
    {
        $table = $this->table('tenants', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Tenants table | 租户表',
        ]);

        $table
            ->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false,
                'comment' => 'Tenant ID | 租户ID',
            ])
            ->addColumn('name', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => 'Tenant name | 租户名称',
            ])
            ->addColumn('slug', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => 'Tenant slug (unique identifier) | 租户标识符',
            ])
            ->addColumn('domain', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Custom domain | 自定义域名',
            ])
            ->addColumn('status', 'enum', [
                'values' => ['active', 'inactive', 'suspended'],
                'default' => 'active',
                'comment' => 'Tenant status | 租户状态',
            ])
            ->addColumn('config', 'text', [
                'null' => true,
                'comment' => 'Tenant configuration (JSON) | 租户配置（JSON）',
            ])
            ->addColumn('max_sites', 'integer', [
                'signed' => false,
                'default' => 1,
                'comment' => 'Maximum sites allowed | 允许的最大站点数',
            ])
            ->addColumn('max_users', 'integer', [
                'signed' => false,
                'default' => 10,
                'comment' => 'Maximum users allowed | 允许的最大用户数',
            ])
            ->addColumn('expires_at', 'timestamp', [
                'null' => true,
                'comment' => 'Subscription expiration | 订阅过期时间',
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
            ->addIndex(['domain'], ['unique' => true, 'name' => 'idx_domain'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->create();
    }
}
