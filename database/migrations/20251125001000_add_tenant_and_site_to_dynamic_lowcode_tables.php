<?php

use think\migration\Migrator;
use think\facade\Db;

/**
 * Add tenant/site columns and index to dynamic lowcode tables
 * 为所有低代码动态业务表增加 tenant_id/site_id 字段和主查询索引。
 *
 * 识别范围：
 * - 通过 lowcode_collections.table_name 识别所有动态业务表；
 * - 仅对物理上真实存在的表执行变更。
 *
 * 注意：
 * - 历史数据采用默认值 tenant_id=0, site_id=0，视为系统模板空间 + 默认站点；
 * - Down 回滚会删除相关列与索引，仅用于结构级回退。
 */
class AddTenantAndSiteToDynamicLowcodeTables extends Migrator
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableNames = $this->getDynamicTableNames();

        foreach ($tableNames as $tableName) {
            if (!$this->hasTable($tableName)) {
                continue;
            }

            $table = $this->table($tableName);

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

            if (!$table->hasIndex(['tenant_id', 'id'])) {
                $table->addIndex(['tenant_id', 'id'], [
                    'name' => 'idx_tenant_id_id',
                    'unique' => false,
                ]);
            }

            $table->update();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = $this->getDynamicTableNames();

        foreach ($tableNames as $tableName) {
            if (!$this->hasTable($tableName)) {
                continue;
            }

            $table = $this->table($tableName);

            if ($table->hasIndex(['tenant_id', 'id'])) {
                $table->removeIndex(['tenant_id', 'id']);
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

    /**
     * Get all dynamic lowcode table names from lowcode_collections.
     *
     * @return string[]
     */
    protected function getDynamicTableNames(): array
    {
        $rows = Db::table('lowcode_collections')
            ->distinct(true)
            ->field('table_name')
            ->whereNotNull('table_name')
            ->where('table_name', '<>', '')
            ->select()
            ->toArray();

        $names = [];
        foreach ($rows as $row) {
            if (!empty($row['table_name'])) {
                $names[] = $row['table_name'];
            }
        }

        return array_values(array_unique($names));
    }
}
