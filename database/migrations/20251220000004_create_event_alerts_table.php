<?php

use think\migration\Migrator;

/**
 * 创建事件告警表（T-049）。
 *
 * 说明：
 * - 本表用于“告警订阅”与审计留存，记录 event.alert.* 产生的告警事件；
 * - 若不需要 DB 级订阅，可只依赖日志收集（如 Promtail/ELK）抓取 event.alert.* 键。
 */
class CreateEventAlertsTable extends Migrator
{
    public function change(): void
    {
        if ($this->hasTable('event_alerts')) {
            return;
        }

        $table = $this->table('event_alerts', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '事件告警表（event.alert.* 订阅）',
        ]);

        $table
            ->addColumn('id', 'biginteger', [
                'identity' => true,
                'signed' => false,
                'comment' => '主键',
            ])
            ->addColumn('alert_key', 'string', [
                'limit' => 64,
                'comment' => '告警键（如 event.alert.slow）',
            ])
            ->addColumn('level', 'string', [
                'limit' => 16,
                'default' => 'warning',
                'comment' => '告警级别（info/warning/error）',
            ])
            ->addColumn('event', 'string', [
                'limit' => 128,
                'null' => true,
                'comment' => '事件名称（可为空）',
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
                'comment' => '租户ID',
            ])
            ->addColumn('site_id', 'integer', [
                'limit' => 11,
                'signed' => false,
                'default' => 0,
                'comment' => '站点ID',
            ])
            ->addColumn('user_id', 'integer', [
                'limit' => 11,
                'signed' => false,
                'default' => 0,
                'comment' => '用户ID',
            ])
            ->addColumn('payload', 'text', [
                'null' => true,
                'comment' => '告警载荷（JSON）',
            ])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['alert_key', 'created_at'], ['name' => 'idx_alert_key_created_at'])
            ->addIndex(['event', 'created_at'], ['name' => 'idx_alert_event_created_at'])
            ->addIndex(['tenant_id', 'site_id', 'created_at'], ['name' => 'idx_alert_tenant_site_created_at'])
            ->addIndex(['trace_id'], ['name' => 'idx_alert_trace_id'])
            ->create();
    }
}
