<?php

use think\migration\Migrator;

class CreateLowcodeRelationshipsTable extends Migrator
{
    /**
     * Create lowcode_relationships table | 创建低代码关系元数据表
     *
     * Stores relationship metadata between collections.
     * 存储集合之间的关系元数据。
     */
    public function change()
    {
        $table = $this->table('lowcode_relationships', [
            'id' => 'id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '低代码关系元数据表 | Lowcode Relationship Metadata',
        ]);

        $table->addColumn('collection_id', 'integer', [
                'signed' => false,
                'comment' => 'Collection ID | 所属Collection',
            ])
            ->addColumn('name', 'string', [
                'limit' => 100,
                'comment' => '关系名称 | Relationship Name',
            ])
            ->addColumn('type', 'string', [
                'limit' => 50,
                'comment' => '关系类型 | Relationship Type (hasOne, hasMany, etc.)',
            ])
            ->addColumn('target_collection', 'string', [
                'limit' => 100,
                'comment' => '目标Collection | Target Collection',
            ])
            ->addColumn('foreign_key', 'string', [
                'limit' => 100,
                'comment' => '外键字段 | Foreign Key Field',
            ])
            ->addColumn('local_key', 'string', [
                'limit' => 100,
                'comment' => '本地键字段 | Local Key Field',
            ])
            ->addColumn('options', 'text', [
                'null' => true,
                'comment' => '扩展选项(JSON) | Extended Options (JSON)',
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => '创建时间 | Created At',
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'comment' => '更新时间 | Updated At',
            ])
            ->addIndex(['collection_id'])
            ->addIndex(['collection_id', 'name'], ['unique' => true])
            ->create();
    }
}
