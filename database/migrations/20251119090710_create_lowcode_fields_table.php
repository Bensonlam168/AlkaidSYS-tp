<?php

use think\migration\Migrator;

class CreateLowcodeFieldsTable extends Migrator
{
    /**
     * Create lowcode_fields table | 创建低代码字段元数据表
     *
     * Stores field metadata for dynamic collections.
     * 存储动态集合的字段元数据。
     */
    public function change()
    {
        $table = $this->table('lowcode_fields', [
            'id' => 'id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '低代码字段元数据表 | Lowcode Field Metadata',
        ]);

        $table->addColumn('collection_id', 'integer', [
                'signed' => false,
                'comment' => 'Collection ID | 所属Collection',
            ])
            ->addColumn('name', 'string', [
                'limit' => 100,
                'comment' => '字段名称 | Field Name',
            ])
            ->addColumn('type', 'string', [
                'limit' => 50,
                'comment' => '字段类型 | Field Type (string, integer, etc.)',
            ])
            ->addColumn('db_type', 'string', [
                'limit' => 100,
                'comment' => '数据库类型 | Database Type (VARCHAR(255), etc.)',
            ])
            ->addColumn('title', 'string', [
                'limit' => 200,
                'comment' => '显示标题 | Display Title',
            ])
            ->addColumn('nullable', 'boolean', [
                'default' => true,
                'comment' => '是否可空 | Is Nullable',
            ])
            ->addColumn('default', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => '默认值 | Default Value',
            ])
            ->addColumn('options', 'text', [
                'null' => true,
                'comment' => '扩展选项(JSON) | Extended Options (JSON)',
            ])
            ->addColumn('sort', 'integer', [
                'default' => 0,
                'comment' => '排序 | Sort Order',
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
