<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 钩子监控/死信/告警表（T-052）。
 */
class CreateHookMonitorTables extends Migrator
{
    public function change(): void
    {
        // 指标表：耗时/失败率统计
        $metrics = $this->table('hook_metrics', ['comment' => '钩子指标表']);
        if (!$metrics->exists()) {
            $metrics
                ->addColumn(Column::string('hook', 191)->setDefault('')->setComment('钩子名称'))
                ->addColumn(Column::string('type', 20)->setDefault('action')->setComment('类型 action|filter'))
                ->addColumn(Column::string('trace_id', 64)->setNullable()->setComment('链路ID'))
                ->addColumn(Column::integer('tenant_id')->setDefault(0)->setComment('租户ID'))
                ->addColumn(Column::integer('site_id')->setDefault(0)->setComment('站点ID'))
                ->addColumn(Column::integer('user_id')->setDefault(0)->setComment('用户ID'))
                ->addColumn(Column::decimal('duration_ms', 10, 2)->setDefault(0)->setComment('耗时(ms)'))
                ->addColumn(Column::boolean('failed')->setDefault(0)->setComment('是否失败'))
                ->addColumn(Column::datetime('created_at')->setDefault('CURRENT_TIMESTAMP')->setComment('创建时间'))
                ->addIndex(['hook', 'created_at'])
                ->addIndex(['tenant_id', 'site_id', 'created_at'])
                ->create();
        }

        // 死信表：重试超过阈值或不可恢复错误
        $dlq = $this->table('hook_dead_letters', ['comment' => '钩子死信表']);
        if (!$dlq->exists()) {
            $dlq
                ->addColumn(Column::integer('code')->setDefault(2001)->setComment('错误码（2001/2002）'))
                ->addColumn(Column::string('hook', 191)->setDefault('')->setComment('钩子名称'))
                ->addColumn(Column::string('trace_id', 64)->setNullable()->setComment('链路ID'))
                ->addColumn(Column::integer('tenant_id')->setDefault(0)->setComment('租户ID'))
                ->addColumn(Column::integer('site_id')->setDefault(0)->setComment('站点ID'))
                ->addColumn(Column::integer('user_id')->setDefault(0)->setComment('用户ID'))
                ->addColumn(Column::integer('attempts')->setDefault(0)->setComment('重试次数'))
                ->addColumn(Column::text('error')->setComment('错误信息'))
                ->addColumn(Column::text('payload')->setNullable()->setComment('原始载荷JSON'))
                ->addColumn(Column::datetime('created_at')->setDefault('CURRENT_TIMESTAMP')->setComment('创建时间'))
                ->addIndex(['hook', 'created_at'])
                ->addIndex(['tenant_id', 'site_id', 'created_at'])
                ->create();
        }

        // 告警表：慢钩子/失败率/DLQ 告警
        $alerts = $this->table('hook_alerts', ['comment' => '钩子告警表']);
        if (!$alerts->exists()) {
            $alerts
                ->addColumn(Column::string('alert_key', 191)->setComment('告警键'))
                ->addColumn(Column::string('hook', 191)->setNullable()->setComment('钩子名称'))
                ->addColumn(Column::string('level', 32)->setDefault('warning')->setComment('级别'))
                ->addColumn(Column::string('trace_id', 64)->setNullable()->setComment('链路ID'))
                ->addColumn(Column::integer('tenant_id')->setDefault(0)->setComment('租户ID'))
                ->addColumn(Column::integer('site_id')->setDefault(0)->setComment('站点ID'))
                ->addColumn(Column::integer('user_id')->setDefault(0)->setComment('用户ID'))
                ->addColumn(Column::text('payload')->setNullable()->setComment('告警载荷'))
                ->addColumn(Column::datetime('created_at')->setDefault('CURRENT_TIMESTAMP')->setComment('创建时间'))
                ->addIndex(['alert_key', 'created_at'])
                ->addIndex(['tenant_id', 'site_id', 'created_at'])
                ->create();
        }
    }
}
