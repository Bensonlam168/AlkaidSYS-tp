<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 优化工作流表数据库索引
 * Optimize workflow table indexes
 *
 * 来源：plugins/workflow-engine/migrations/20251216000005_optimize_indexes.php
 *
 * 本次改造点：
 * 1. 原实现 change() 调用了 optimizeWorkflowEventLogsIndexes()，但实际方法名为
 *    optimizeWorkflowEventsLogsIndexes()，会直接触发 "Call to undefined method" 致命错误；
 *    现改为「索引清单 + 统一安全创建」的结构，从根上消除该问题；
 * 2. 每个 addIndex 都先按索引名判重、再包 try/catch (\Throwable)，重复或失败仅记录日志，
 *    不会中断迁移（MySQL 1061 Duplicate key name 可容忍）；
 * 3. 移除原 createPartialIndexes()：MySQL 8.0 并不支持 CREATE INDEX ... WHERE 部分索引，
 *    该段 SQL 必然失败，只会在迁移日志里制造噪音；
 * 4. 补充 down()，按索引名删除本迁移创建的索引，失败仅记录。
 */
class OptimizeIndexes extends Migrator
{
    /**
     * 索引清单：[表名, 列, 索引名, 额外选项]
     *
     * 仅包含业务查询优化所需的「附加」索引（迁移 1 已建的单列索引不在此重复）。
     *
     * @var array<int, array{0: string, 1: string[], 2: string, 3: array<string, mixed>}>
     */
    private const INDEXES = [
        // 工作流定义表：租户内按状态/类型/更新时间检索
        ['workflow_definitions', ['tenant_id', 'status', 'is_active'], 'idx_tenant_status_active', []],
        ['workflow_definitions', ['tenant_id', 'type', 'category'], 'idx_tenant_type_category', []],
        ['workflow_definitions', ['tenant_id', 'updated_at'], 'idx_tenant_updated', []],
        // 工作流定义表：名称/显示名/描述全文检索（MySQL 不支持时自动跳过）
        ['workflow_definitions', ['name', 'display_name', 'description'], 'idx_search', ['type' => 'fulltext']],

        // 工作流实例表：列表页与看板的状态/时间/处理人组合查询
        ['workflow_instances', ['tenant_id', 'status', 'started_at'], 'idx_tenant_status_time', []],
        ['workflow_instances', ['tenant_id', 'status', 'created_by'], 'idx_tenant_status_user', []],
        ['workflow_instances', ['tenant_id', 'status', 'assignee'], 'idx_tenant_status_assignee', []],
        ['workflow_instances', ['tenant_id', 'business_key'], 'idx_tenant_business_key', []],
        ['workflow_instances', ['tenant_id', 'definition_id', 'status'], 'idx_tenant_def_status', []],
        ['workflow_instances', ['status', 'started_at'], 'idx_status_time', []],
        ['workflow_instances', ['tenant_id', 'completed_at'], 'idx_tenant_completed', []],
        ['workflow_instances', ['tenant_id', 'status', 'started_at', 'completed_at'], 'idx_tenant_status_time_range', []],

        // 工作流任务表：待办列表、到期提醒、节点回查
        ['workflow_tasks', ['tenant_id', 'status', 'assignee'], 'idx_tenant_status_assignee', []],
        ['workflow_tasks', ['tenant_id', 'status', 'due_date'], 'idx_tenant_status_due', []],
        ['workflow_tasks', ['tenant_id', 'assignee', 'status'], 'idx_tenant_assignee_status', []],
        ['workflow_tasks', ['assignee', 'status', 'priority', 'due_date'], 'idx_todo_list', []],
        ['workflow_tasks', ['tenant_id', 'instance_id', 'node_id'], 'idx_tenant_instance_node', []],
        ['workflow_tasks', ['tenant_id', 'task_type', 'status'], 'idx_tenant_type_status', []],
        ['workflow_tasks', ['status', 'created_at'], 'idx_status_created', []],
        ['workflow_tasks', ['tenant_id', 'completed_at'], 'idx_tenant_completed', []],
        ['workflow_tasks', ['tenant_id', 'status', 'created_at', 'due_date'], 'idx_tenant_status_date_range', []],

        // 工作流触发器表：按定义/类型/启用状态检索
        ['workflow_triggers', ['tenant_id', 'definition_id', 'is_active'], 'idx_tenant_def_active', []],
        ['workflow_triggers', ['tenant_id', 'type', 'is_active'], 'idx_tenant_type_active', []],
        ['workflow_triggers', ['definition_id', 'type'], 'idx_def_type', []],
        ['workflow_triggers', ['tenant_id', 'updated_at'], 'idx_tenant_updated', []],

        // 工作流事件日志表：审计与统计
        ['workflow_event_logs', ['tenant_id', 'event_type', 'created_at'], 'idx_tenant_type_time', []],
        ['workflow_event_logs', ['tenant_id', 'instance_id', 'created_at'], 'idx_tenant_instance_time', []],
        ['workflow_event_logs', ['tenant_id', 'task_id', 'created_at'], 'idx_tenant_task_time', []],
        ['workflow_event_logs', ['tenant_id', 'user_id', 'created_at'], 'idx_tenant_user_time', []],
        ['workflow_event_logs', ['ip_address', 'created_at'], 'idx_ip_time', []],
        ['workflow_event_logs', ['event_type', 'created_at'], 'idx_type_time', []],
        ['workflow_event_logs', ['tenant_id', 'event_type', 'user_id', 'created_at'], 'idx_multi_condition', []],
    ];

    /**
     * 优化索引
     *
     * 刻意使用 up()/down() 而非 change()：索引创建可能因重复/全文索引不支持而失败，
     * 只有显式 down() 才能按索引名精确撤销。
     */
    public function up(): void
    {
        foreach (self::INDEXES as [$tableName, $columns, $indexName, $options]) {
            $this->addIndexSafely($tableName, $columns, $indexName, $options);
        }
    }

    /**
     * 回滚迁移：删除本迁移添加的索引
     */
    public function down(): void
    {
        foreach (array_reverse(self::INDEXES) as [$tableName, $columns, $indexName, $options]) {
            $this->dropIndexSafely($tableName, $indexName);
        }
    }

    /**
     * 安全添加索引：表不存在、索引已存在或创建失败都只记录日志
     *
     * @param string[]             $columns 索引列
     * @param array<string, mixed> $options 额外选项（如 type=fulltext）
     */
    protected function addIndexSafely(string $tableName, array $columns, string $indexName, array $options = []): void
    {
        try {
            if (!$this->hasTable($tableName)) {
                $this->notice("跳过索引 {$tableName}.{$indexName}：表不存在");

                return;
            }

            $table = $this->table($tableName);

            // 已存在同名索引则跳过，避免 MySQL 1061 Duplicate key name
            if ($table->hasIndexByName($indexName)) {
                $this->notice("跳过索引 {$tableName}.{$indexName}：已存在");

                return;
            }

            $table->addIndex($columns, array_merge(['name' => $indexName], $options))->save();
            $this->notice("已创建索引 {$tableName}.{$indexName}");
        } catch (\Throwable $e) {
            // 典型可容忍场景：全文索引不被支持、索引名冲突、列不存在
            $this->notice("创建索引 {$tableName}.{$indexName} 失败（已跳过）：" . $e->getMessage());
        }
    }

    /**
     * 安全删除索引：索引不存在或删除失败都只记录日志
     */
    protected function dropIndexSafely(string $tableName, string $indexName): void
    {
        try {
            if (!$this->hasTable($tableName)) {
                return;
            }

            $table = $this->table($tableName);

            if (!$table->hasIndexByName($indexName)) {
                return;
            }

            $table->removeIndexByName($indexName)->save();
            $this->notice("已删除索引 {$tableName}.{$indexName}");
        } catch (\Throwable $e) {
            $this->notice("删除索引 {$tableName}.{$indexName} 失败（已忽略）：" . $e->getMessage());
        }
    }

    /**
     * 输出迁移日志
     */
    protected function notice(string $message): void
    {
        $output = $this->getOutput();
        if ($output !== null) {
            $output->writeln('[workflow] ' . $message);
        }
    }
}
