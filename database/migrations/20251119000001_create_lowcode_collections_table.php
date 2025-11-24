<?php

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * Create lowcode_collections table migration | 创建lowcode_collections表的迁移
 * 
 * This migration creates the metadata table for storing collection definitions.
 * 此迁移创建用于存储集合定义的元数据表。
 */
class CreateLowcodeCollectionsTable extends Migrator
{
    /**
     * Run the migrations | 执行迁移
     *
     * @return void
     */
    public function change()
    {
        $table = $this->table('lowcode_collections', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Low-code collections metadata | 低代码集合元数据'
        ]);

        $table
            ->addColumn('id', 'integer', [
                'limit' => 11,
                'signed' => false,
                'identity' => true,
                'comment' => 'Primary key | 主键'
            ])
            ->addColumn('name', 'string', [
                'limit' => 64,
                'null' => false,
                'comment' => 'Collection name (unique) | 集合名称（唯一）'
            ])
            ->addColumn('table_name', 'string', [
                'limit' => 64,
                'null' => false,
                'comment' => 'Associated table name | 关联的表名'
            ])
            ->addColumn('schema', 'text', [
                'null' => false,
                'comment' => 'Collection schema (JSON) | 集合schema（JSON）'
            ])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => 'Created timestamp | 创建时间'
            ])
            ->addColumn('updated_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'comment' => 'Updated timestamp | 更新时间'
            ])
            ->addIndex(['name'], ['unique' => true, 'name' => 'uk_name'])
            ->addIndex(['table_name'], ['unique' => true, 'name' => 'uk_table_name'])
            ->create();
    }
}
