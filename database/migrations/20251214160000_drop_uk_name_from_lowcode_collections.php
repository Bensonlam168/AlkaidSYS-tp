<?php

use think\migration\Migrator;

/**
 * Drop uk_name unique index from lowcode_collections | 删除 lowcode_collections 的 uk_name 唯一索引
 *
 * This migration removes the global unique constraint on 'name' column,
 * allowing different tenants to create collections with the same name.
 * The (tenant_id, name) composite unique index (uk_tenant_name) remains
 * to ensure uniqueness within each tenant.
 *
 * 此迁移删除 'name' 列的全局唯一约束，允许不同租户创建同名集合。
 * (tenant_id, name) 组合唯一索引 (uk_tenant_name) 保留，确保租户内唯一性。
 *
 * @see https://github.com/Bensonlam168/AlkaidSYS-tp/issues/T-066
 */
class DropUkNameFromLowcodeCollections extends Migrator
{
    /**
     * Run the migrations | 执行迁移
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('lowcode_collections');

        // Check if uk_name index exists before dropping
        // 删除前检查 uk_name 索引是否存在
        if ($table->hasIndex(['name'])) {
            $table->removeIndex(['name']);
            $table->update();
        }
    }

    /**
     * Reverse the migrations | 回滚迁移
     *
     * @return void
     */
    public function down(): void
    {
        $table = $this->table('lowcode_collections');

        // Restore uk_name unique index
        // 恢复 uk_name 唯一索引
        if (!$table->hasIndex(['name'])) {
            $table->addIndex(['name'], [
                'unique' => true,
                'name' => 'uk_name',
            ]);
            $table->update();
        }
    }
}
