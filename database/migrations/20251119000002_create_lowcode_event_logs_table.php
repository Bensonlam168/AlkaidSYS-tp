<?php

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * Create lowcode_event_logs table migration | 创建lowcode_event_logs表的迁移
 * 
 * This migration creates the table for storing event execution logs.
 * 此迁移创建用于存储事件执行日志的表。
 */
class CreateLowcodeEventLogsTable extends Migrator
{
    /**
     * Run the migrations | 执行迁移
     *
     * @return void
     */
    public function change()
    {
        $table = $this->table('lowcode_event_logs', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Low-code event execution logs | 低代码事件执行日志'
        ]);

        $table
            ->addColumn('id', 'integer', [
                'limit' => 11,
                'signed' => false,
                'identity' => true,
                'comment' => 'Primary key | 主键'
            ])
            ->addColumn('event', 'string', [
                'limit' => 128,
                'null' => false,
                'comment' => 'Event name | 事件名称'
            ])
            ->addColumn('listener', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Listener identifier | 监听器标识'
            ])
            ->addColumn('params', 'text', [
                'null' => true,
                'comment' => 'Event parameters (JSON) | 事件参数（JSON）'
            ])
            ->addColumn('status', 'string', [
                'limit' => 16,
                'null' => false,
                'comment' => 'Execution status | 执行状态'
            ])
            ->addColumn('error', 'text', [
                'null' => true,
                'comment' => 'Error message | 错误信息'
            ])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => 'Created timestamp | 创建时间'
            ])
            ->addIndex(['event'], ['name' => 'idx_event'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['created_at'], ['name' => 'idx_created_at'])
            ->create();
    }
}
