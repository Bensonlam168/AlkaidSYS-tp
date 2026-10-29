<?php

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * Create lowcode_schema_versions table and add schema version/hash columns.
 * 创建 schema 版本表并为现有元数据表增加版本/哈希字段。
 */
class CreateLowcodeSchemaVersionsTable extends Migrator
{
    public function change(): void
    {
        // 1) 创建 lowcode_schema_versions 版本审计表
        if (!$this->hasTable('lowcode_schema_versions')) {
            $table = $this->table('lowcode_schema_versions', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => '低代码 Schema 版本与漂移审计表',
            ]);

            $table
                ->addColumn('id', 'biginteger', [
                    'identity' => true,
                    'signed' => false,
                    'comment' => '主键',
                ])
                ->addColumn('tenant_id', 'integer', [
                    'limit' => 11,
                    'signed' => false,
                    'default' => 0,
                    'comment' => '租户ID（0 = 系统模板）',
                ])
                ->addColumn('site_id', 'integer', [
                    'limit' => 11,
                    'signed' => false,
                    'default' => 0,
                    'comment' => '站点ID（0 = 默认站点）',
                ])
                ->addColumn('object_type', 'string', [
                    'limit' => 32,
                    'comment' => '对象类型：collection/form',
                ])
                ->addColumn('object_name', 'string', [
                    'limit' => 128,
                    'comment' => '对象名称（如 Collection 名称或 Form 名称）',
                ])
                ->addColumn('version', 'integer', [
                    'limit' => 11,
                    'signed' => false,
                    'default' => 1,
                    'comment' => 'Schema 版本号',
                ])
                ->addColumn('schema_hash', 'string', [
                    'limit' => 64,
                    'comment' => 'Schema 哈希（sha256）',
                ])
                ->addColumn('schema', 'json', [
                    'null' => false,
                    'comment' => 'Schema 快照（JSON）',
                ])
                ->addColumn('created_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                ])
                ->addColumn('updated_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'update' => 'CURRENT_TIMESTAMP',
                ])
                ->addIndex(['tenant_id', 'site_id', 'object_type', 'object_name', 'version'], [
                    'unique' => true,
                    'name' => 'uk_schema_version',
                ])
                ->addIndex(['schema_hash'], ['name' => 'idx_schema_hash'])
                ->create();
        }

        // 2) 为 lowcode_collections 增加 schema_version/schema_hash
        $collections = $this->table('lowcode_collections');
        if (!$collections->hasColumn('schema_version')) {
            $collections->addColumn('schema_version', 'integer', [
                'limit' => 11,
                'signed' => false,
                'default' => 1,
                'comment' => 'Schema 版本号',
            ]);
        }
        if (!$collections->hasColumn('schema_hash')) {
            $collections->addColumn('schema_hash', 'string', [
                'limit' => 64,
                'null' => true,
                'comment' => 'Schema 哈希（sha256）',
            ]);
        }
        $collections->update();
        // 基于现有 schema 初始化哈希
        // 注意：schema 为关键字，需使用反引号；同时将 JSON/文本列显式参与 SHA2 计算
        $this->execute("UPDATE lowcode_collections SET schema_version = 1, schema_hash = SHA2(`schema`, 256) WHERE schema_hash IS NULL");

        // 3) 为 lowcode_forms 增加 schema_version/schema_hash
        if ($this->hasTable('lowcode_forms')) {
            $forms = $this->table('lowcode_forms');
            if (!$forms->hasColumn('schema_version')) {
                $forms->addColumn('schema_version', 'integer', [
                    'limit' => 11,
                    'signed' => false,
                    'default' => 1,
                    'comment' => 'Schema 版本号',
                ]);
            }
            if (!$forms->hasColumn('schema_hash')) {
                $forms->addColumn('schema_hash', 'string', [
                    'limit' => 64,
                    'null' => true,
                    'comment' => 'Schema 哈希（sha256）',
                ]);
            }
            $forms->update();
            $this->execute("UPDATE lowcode_forms SET schema_version = 1, schema_hash = SHA2(`schema`, 256) WHERE schema_hash IS NULL");
        }
    }
}
