<?php

use think\migration\Migrator;

/**
 * Add tenant/site columns to lowcode_collections | 为 lowcode_collections 增加租户/站点字段
 *
 * 此迁移在元数据表中新增 tenant_id / site_id 字段，并为 (tenant_id, name) 创建唯一索引，
 * 同时将历史数据标记为系统模板（tenant_id=0, site_id=0）。
 */
class AddTenantSiteToLowcodeCollectionsTable extends Migrator
{
    /**
     * Run the migrations | 执行迁移
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('lowcode_collections');

        if (!$table->hasColumn('tenant_id')) {
            $table->addColumn('tenant_id', 'integer', [
                'limit' => 11,
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => 'Tenant ID | 租户ID（0 = 系统模板）',
            ]);
        }

        if (!$table->hasColumn('site_id')) {
            $table->addColumn('site_id', 'integer', [
                'limit' => 11,
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => 'Site ID | 站点ID（0 = 默认站点）',
            ]);
        }

        if (!$table->hasIndex(['tenant_id', 'name'])) {
            $table->addIndex(['tenant_id', 'name'], [
                'unique' => true,
                'name' => 'uk_tenant_name',
            ]);
        }

        $table->update();

        // Mark existing records as system templates | 将历史记录标记为系统模板
        $this->execute('UPDATE lowcode_collections SET tenant_id = 0, site_id = 0 WHERE tenant_id IS NULL OR site_id IS NULL');
    }

    /**
     * Reverse the migrations | 回滚迁移
     *
     * @return void
     */
    public function down(): void
    {
        $table = $this->table('lowcode_collections');

        if (method_exists($table, 'hasIndexByName') && $table->hasIndexByName('uk_tenant_name')) {
            $table->removeIndexByName('uk_tenant_name');
        }

        if ($table->hasIndex(['tenant_id', 'name'])) {
            $table->removeIndex(['tenant_id', 'name']);
        }

        if ($table->hasColumn('tenant_id')) {
            $table->removeColumn('tenant_id');
        }

        if ($table->hasColumn('site_id')) {
            $table->removeColumn('site_id');
        }

        $table->update();
    }
}
