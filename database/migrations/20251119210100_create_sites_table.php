<?php

use think\migration\Migrator;

/**
 * Create Sites Table Migration | 创建站点表迁移
 *
 * Creates the sites table for multi-site support.
 * 为多站点支持创建站点表。
 */
class CreateSitesTable extends Migrator
{
    /**
     * Run migration | 执行迁移
     */
    public function change(): void
    {
        $table = $this->table('sites', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Sites table | 站点表',
        ]);

        $table
            ->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false,
                'comment' => 'Site ID | 站点ID',
            ])
            ->addColumn('tenant_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'Tenant ID | 租户ID',
            ])
            ->addColumn('name', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => 'Site name | 站点名称',
            ])
            ->addColumn('slug', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => 'Site slug | 站点标识符',
            ])
            ->addColumn('domain', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Custom domain | 自定义域名',
            ])
            ->addColumn('status', 'enum', [
                'values' => ['active', 'inactive', 'maintenance'],
                'default' => 'active',
                'comment' => 'Site status | 站点状态',
            ])
            ->addColumn('config', 'text', [
                'null' => true,
                'comment' => 'Site configuration (JSON) | 站点配置（JSON）',
            ])
            ->addColumn('theme', 'string', [
                'limit' => 50,
                'default' => 'default',
                'comment' => 'Site theme | 站点主题',
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
            ->addIndex(['domain'], ['unique' => true, 'name' => 'idx_domain'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['tenant_id', 'slug'], ['unique' => true, 'name' => 'idx_tenant_slug'])
            ->addForeignKey('tenant_id', 'tenants', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_sites_tenant_id'
            ])
            ->create();
    }
}
