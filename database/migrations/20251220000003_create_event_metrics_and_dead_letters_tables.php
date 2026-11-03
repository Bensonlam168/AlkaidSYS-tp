<?php

use think\migration\Migrator;

/**
 * 创建事件审计与死信表（T-049）。
 */
class CreateEventMetricsAndDeadLettersTables extends Migrator
{
    public function change(): void
    {
        // 事件审计表：记录耗时/失败状态，便于监控与告警
        if (!$this->hasTable('event_metrics')) {
            $metrics = $this->table('event_metrics', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => '事件审计表（耗时/失败/上下文）',
            ]);

            $metrics
                ->addColumn('id', 'biginteger', [
                    'identity' => true,
                    'signed' => false,
                    'comment' => '主键',
                ])
                ->addColumn('event', 'string', [
                    'limit' => 128,
                    'comment' => '事件名称',
                ])
                ->addColumn('trace_id', 'string', [
                    'limit' => 64,
                    'null' => true,
                    'comment' => 'trace_id',
                ])
                ->addColumn('tenant_id', 'integer', [
                    'limit' => 11,
                    'signed' => false,
                    'default' => 0,
                    'comment' => '租户ID（0=系统模板）',
                ])
                ->addColumn('site_id', 'integer', [
                    'limit' => 11,
                    'signed' => false,
                    'default' => 0,
                    'comment' => '站点ID（0=默认站点）',
                ])
                ->addColumn('user_id', 'integer', [
                    'limit' => 11,
                    'signed' => false,
                    'default' => 0,
                    'comment' => '用户ID（无登录则为0）',
                ])
                ->addColumn('duration_ms', 'decimal', [
                    'precision' => 10,
                    'scale' => 2,
                    'default' => 0,
                    'comment' => '耗时（毫秒）',
                ])
                ->addColumn('failed', 'boolean', [
                    'default' => false,
                    'comment' => '是否失败',
                ])
                ->addColumn('created_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                ])
                ->addIndex(['event', 'created_at'], ['name' => 'idx_event_created_at'])
                ->addIndex(['tenant_id', 'site_id', 'failed'], ['name' => 'idx_tenant_site_failed'])
                ->addIndex(['trace_id'], ['name' => 'idx_trace_id'])
                ->create();
        }

        // 事件死信表：记录重试失败的事件
        if (!$this->hasTable('event_dead_letters')) {
            $dlq = $this->table('event_dead_letters', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => '事件死信表（重试失败记录）',
            ]);

            $dlq
                ->addColumn('id', 'biginteger', [
                    'identity' => true,
                    'signed' => false,
                    'comment' => '主键',
                ])
                ->addColumn('code', 'integer', [
                    'limit' => 11,
                    'signed' => false,
                    'default' => 2001,
                    'comment' => '错误码（2001/2002）',
                ])
                ->addColumn('event', 'string', [
                    'limit' => 128,
                    'comment' => '事件名称',
                ])
                ->addColumn('trace_id', 'string', [
                    'limit' => 64,
                    'null' => true,
                    'comment' => 'trace_id',
                ])
                ->addColumn('tenant_id', 'integer', [
                    'limit' => 11,
                    'signed' => false,
                    'default' => 0,
                    'comment' => '租户ID（0=系统模板）',
                ])
                ->addColumn('site_id', 'integer', [
                    'limit' => 11,
                    'signed' => false,
                    'default' => 0,
                    'comment' => '站点ID（0=默认站点）',
                ])
                ->addColumn('user_id', 'integer', [
                    'limit' => 11,
                    'signed' => false,
                    'default' => 0,
                    'comment' => '用户ID（无登录则为0）',
                ])
                ->addColumn('attempts', 'integer', [
                    'limit' => 11,
                    'signed' => false,
                    'default' => 0,
                    'comment' => '重试次数',
                ])
                ->addColumn('error', 'text', [
                    'null' => true,
                    'comment' => '错误信息',
                ])
                ->addColumn('payload', 'text', [
                    'null' => true,
                    'comment' => '原始载荷（JSON）',
                ])
                ->addColumn('created_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                ])
                ->addIndex(['event', 'created_at'], ['name' => 'idx_dlq_event_created_at'])
                ->addIndex(['code', 'created_at'], ['name' => 'idx_dlq_code_created_at'])
                ->addIndex(['tenant_id', 'site_id'], ['name' => 'idx_dlq_tenant_site'])
                ->addIndex(['trace_id'], ['name' => 'idx_dlq_trace_id'])
                ->create();
        }
    }
}
