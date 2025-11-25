<?php

use think\migration\Migrator;

class CreateLowcodeFormsTable extends Migrator
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
     *
     * The following commands can be used in this method and Phinx will
     * automatically reverse them when rolling back:
     *
     *    createTable
     *    renameTable
     *    addColumn
     *    renameColumn
     *    addIndex
     *    addForeignKey
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    /**
     * Create lowcode_forms table | 创建lowcode_forms表
     *
     * Schema from design/09-lowcode-framework/43-lowcode-form-designer.md line 1121-1138
     */
    public function change()
    {
        $table = $this->table('lowcode_forms', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '表单元数据表（多租户适配）',
        ]);

        $table
            ->addColumn('id', 'biginteger', [
                'signed' => false,
                'identity' => true,
                'comment' => '主键ID',
            ])
            ->addColumn('tenant_id', 'biginteger', [
                'signed' => false,
                'null' => false,
                'comment' => '租户ID',
            ])
            ->addColumn('site_id', 'biginteger', [
                'signed' => false,
                'default' => 0,
                'comment' => '站点ID',
            ])
            ->addColumn('name', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => '表单标识',
            ])
            ->addColumn('title', 'string', [
                'limit' => 200,
                'null' => false,
                'comment' => '表单标题',
            ])
            ->addColumn('description', 'text', [
                'null' => true,
                'comment' => '表单描述',
            ])
            ->addColumn('schema', 'json', [
                'null' => false,
                'comment' => '表单Schema（JSON格式）',
            ])
            ->addColumn('collection_name', 'string', [
                'limit' => 100,
                'null' => true,
                'comment' => '关联的Collection',
            ])
            ->addColumn('layout', 'string', [
                'limit' => 20,
                'default' => 'horizontal',
                'comment' => '表单布局：horizontal/vertical/inline',
            ])
            ->addColumn('status', 'boolean', [
                'default' => true,
                'comment' => '状态：1-启用，0-禁用',
            ])
            ->addColumn('created_at', 'timestamp', [
                'null' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('updated_at', 'timestamp', [
                'null' => true,
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['tenant_id', 'site_id'], ['name' => 'idx_tenant_site'])
            ->addIndex(['tenant_id', 'name'], ['unique' => true, 'name' => 'uk_tenant_name'])
            ->addIndex(['tenant_id', 'collection_name'], ['name' => 'idx_collection_name'])
            ->create();
    }
}
