<?php

use think\migration\Migrator;

/**
 * 为 lowcode_fields / lowcode_relationships 增加 tenant_id，并调整索引（T-049/多租户兼容）。
 */
class AddTenantToLowcodeFieldsAndRelationships extends Migrator
{
    public function up(): void
    {
        // lowcode_fields
        $fields = $this->table('lowcode_fields');
        if (!$fields->hasColumn('tenant_id')) {
            $fields->addColumn('tenant_id', 'integer', [
                'limit' => 11,
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => 'Tenant ID | 租户ID（0 = 系统模板）',
                'after' => 'collection_id',
            ]);
        }

        // 移除旧唯一索引（collection_id, name），改为 (tenant_id, collection_id, name)
        if ($fields->hasIndex(['collection_id', 'name'])) {
            $fields->removeIndex(['collection_id', 'name']);
        }

        if (!$fields->hasIndex(['tenant_id', 'collection_id', 'name'])) {
            $fields->addIndex(['tenant_id', 'collection_id', 'name'], [
                'unique' => true,
                'name' => 'uk_tenant_collection_field',
            ]);
        }

        if (!$fields->hasIndex(['tenant_id', 'collection_id'])) {
            $fields->addIndex(['tenant_id', 'collection_id'], [
                'name' => 'idx_tenant_collection',
            ]);
        }

        $fields->update();

        // lowcode_relationships
        $relationships = $this->table('lowcode_relationships');
        if (!$relationships->hasColumn('tenant_id')) {
            $relationships->addColumn('tenant_id', 'integer', [
                'limit' => 11,
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => 'Tenant ID | 租户ID（0 = 系统模板）',
                'after' => 'collection_id',
            ]);
        }

        if ($relationships->hasIndex(['collection_id', 'name'])) {
            $relationships->removeIndex(['collection_id', 'name']);
        }

        if (!$relationships->hasIndex(['tenant_id', 'collection_id', 'name'])) {
            $relationships->addIndex(['tenant_id', 'collection_id', 'name'], [
                'unique' => true,
                'name' => 'uk_tenant_collection_relation',
            ]);
        }

        if (!$relationships->hasIndex(['tenant_id', 'collection_id'])) {
            $relationships->addIndex(['tenant_id', 'collection_id'], [
                'name' => 'idx_relation_tenant_collection',
            ]);
        }

        $relationships->update();

        // 回填 tenant_id：从 lowcode_collections 关联写入
        $this->execute(
            "UPDATE `lowcode_fields` f
             JOIN `lowcode_collections` c ON c.id = f.collection_id
             SET f.tenant_id = c.tenant_id
             WHERE f.tenant_id IS NULL OR f.tenant_id = 0"
        );

        $this->execute(
            "UPDATE `lowcode_relationships` r
             JOIN `lowcode_collections` c ON c.id = r.collection_id
             SET r.tenant_id = c.tenant_id
             WHERE r.tenant_id IS NULL OR r.tenant_id = 0"
        );
    }

    public function down(): void
    {
        $fields = $this->table('lowcode_fields');
        if ($fields->hasIndex(['tenant_id', 'collection_id', 'name'])) {
            $fields->removeIndexByName('uk_tenant_collection_field');
        }
        if ($fields->hasIndex(['tenant_id', 'collection_id'])) {
            $fields->removeIndexByName('idx_tenant_collection');
        }
        if ($fields->hasColumn('tenant_id')) {
            $fields->removeColumn('tenant_id');
        }
        if (!$fields->hasIndex(['collection_id', 'name'])) {
            $fields->addIndex(['collection_id', 'name'], ['unique' => true]);
        }
        $fields->update();

        $relationships = $this->table('lowcode_relationships');
        if ($relationships->hasIndex(['tenant_id', 'collection_id', 'name'])) {
            $relationships->removeIndexByName('uk_tenant_collection_relation');
        }
        if ($relationships->hasIndex(['tenant_id', 'collection_id'])) {
            $relationships->removeIndexByName('idx_relation_tenant_collection');
        }
        if ($relationships->hasColumn('tenant_id')) {
            $relationships->removeColumn('tenant_id');
        }
        if (!$relationships->hasIndex(['collection_id', 'name'])) {
            $relationships->addIndex(['collection_id', 'name'], ['unique' => true]);
        }
        $relationships->update();
    }
}
