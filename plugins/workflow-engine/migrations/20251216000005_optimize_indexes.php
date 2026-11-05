<?php

declare(strict_types=1);

// ⚠️ 历史留档，勿执行：权威定义见 database/migrations/20251216000005_optimize_indexes.php
use think\migration\Migrator;

/**
 * 优化数据库索引
 * Optimize database indexes
 *
 * @package plugins\workflow-engine\migrations
 */
class OptimizeIndexes extends Migrator
{
    /**
     * 优化索引
     */
    public function change(): void
    {
        // 优化工作流定义表索引
        $this->optimizeWorkflowDefinitionsIndexes();

        // 优化工作流实例表索引
        $this->optimizeWorkflowInstancesIndexes();

        // 优化工作流任务表索引
        $this->optimizeWorkflowTasksIndexes();

        // 优化工作流触发器表索引
        $this->optimizeWorkflowTriggersIndexes();

        // 优化工作流事件日志表索引
        $this->optimizeWorkflowEventLogsIndexes();

        // 创建复合索引
        $this->createCompositeIndexes();

        // 创建部分索引（如果数据库支持）
        $this->createPartialIndexes();
    }

    /**
     * 优化工作流定义表索引
     */
    protected function optimizeWorkflowDefinitionsIndexes(): void
    {
        // 为常用查询添加复合索引
        $this->table('workflow_definitions')
            ->addIndex(['tenant_id', 'status', 'is_active'], ['name' => 'idx_tenant_status_active'])
            ->save();

        $this->table('workflow_definitions')
            ->addIndex(['tenant_id', 'type', 'category'], ['name' => 'idx_tenant_type_category'])
            ->save();

        $this->table('workflow_definitions')
            ->addIndex(['tenant_id', 'updated_at'], ['name' => 'idx_tenant_updated'])
            ->save();

        // 添加全文搜索索引（如果需要）
        try {
            $this->table('workflow_definitions')
                ->addIndex(['name', 'display_name', 'description'], [
                    'name' => 'idx_search',
                    'type' => 'fulltext'
                ])
                ->save();
        } catch (\Exception $e) {
            // 如果不支持全文索引，跳过
            $this->output->writeln('跳过全文索引创建: ' . $e->getMessage());
        }
    }

    /**
     * 优化工作流实例表索引
     */
    protected function optimizeWorkflowInstancesIndexes(): void
    {
        // 添加状态和时间的复合索引
        $this->table('workflow_instances')
            ->addIndex(['tenant_id', 'status', 'started_at'], ['name' => 'idx_tenant_status_time'])
            ->save();

        $this->table('workflow_instances')
            ->addIndex(['tenant_id', 'status', 'created_by'], ['name' => 'idx_tenant_status_user'])
            ->save();

        $this->table('workflow_instances')
            ->addIndex(['tenant_id', 'status', 'assignee'], ['name' => 'idx_tenant_status_assignee'])
            ->save();

        // 添加业务查询优化索引
        $this->table('workflow_instances')
            ->addIndex(['tenant_id', 'business_key'], ['name' => 'idx_tenant_business_key'])
            ->save();

        $this->table('workflow_instances')
            ->addIndex(['tenant_id', 'definition_id', 'status'], ['name' => 'idx_tenant_def_status'])
            ->save();

        // 添加性能监控索引
        $this->table('workflow_instances')
            ->addIndex(['status', 'started_at'], ['name' => 'idx_status_time'])
            ->save();

        $this->table('workflow_instances')
            ->addIndex(['tenant_id', 'completed_at'], ['name' => 'idx_tenant_completed'])
            ->save();
    }

    /**
     * 优化工作流任务表索引
     */
    protected function optimizeWorkflowTasksIndexes(): void
    {
        // 添加任务状态相关索引
        $this->table('workflow_tasks')
            ->addIndex(['tenant_id', 'status', 'assignee'], ['name' => 'idx_tenant_status_assignee'])
            ->save();

        $this->table('workflow_tasks')
            ->addIndex(['tenant_id', 'status', 'due_date'], ['name' => 'idx_tenant_status_due'])
            ->save();

        $this->table('workflow_tasks')
            ->addIndex(['tenant_id', 'assignee', 'status'], ['name' => 'idx_tenant_assignee_status'])
            ->save();

        // 添加待办任务优化索引
        $this->table('workflow_tasks')
            ->addIndex(['assignee', 'status', 'priority', 'due_date'], ['name' => 'idx_todo_list'])
            ->save();

        // 添加任务查询优化索引
        $this->table('workflow_tasks')
            ->addIndex(['tenant_id', 'instance_id', 'node_id'], ['name' => 'idx_tenant_instance_node'])
            ->save();

        $this->table('workflow_tasks')
            ->addIndex(['tenant_id', 'task_type', 'status'], ['name' => 'idx_tenant_type_status'])
            ->save();

        // 添加时间相关索引
        $this->table('workflow_tasks')
            ->addIndex(['status', 'created_at'], ['name' => 'idx_status_created'])
            ->save();

        $this->table('workflow_tasks')
            ->addIndex(['tenant_id', 'completed_at'], ['name' => 'idx_tenant_completed'])
            ->save();
    }

    /**
     * 优化工作流触发器表索引
     */
    protected function optimizeWorkflowTriggersIndexes(): void
    {
        // 添加触发器查询优化索引
        $this->table('workflow_triggers')
            ->addIndex(['tenant_id', 'definition_id', 'is_active'], ['name' => 'idx_tenant_def_active'])
            ->save();

        $this->table('workflow_triggers')
            ->addIndex(['tenant_id', 'type', 'is_active'], ['name' => 'idx_tenant_type_active'])
            ->save();

        $this->table('workflow_triggers')
            ->addIndex(['definition_id', 'type'], ['name' => 'idx_def_type'])
            ->save();

        // 添加触发器管理索引
        $this->table('workflow_triggers')
            ->addIndex(['tenant_id', 'updated_at'], ['name' => 'idx_tenant_updated'])
            ->save();
    }

    /**
     * 优化工作流事件日志表索引
     */
    protected function optimizeWorkflowEventsLogsIndexes(): void
    {
        // 添加事件查询优化索引
        $this->table('workflow_event_logs')
            ->addIndex(['tenant_id', 'event_type', 'created_at'], ['name' => 'idx_tenant_type_time'])
            ->save();

        $this->table('workflow_event_logs')
            ->addIndex(['tenant_id', 'instance_id', 'created_at'], ['name' => 'idx_tenant_instance_time'])
            ->save();

        $this->table('workflow_event_logs')
            ->addIndex(['tenant_id', 'task_id', 'created_at'], ['name' => 'idx_tenant_task_time'])
            ->save();

        // 添加用户操作审计索引
        $this->table('workflow_event_logs')
            ->addIndex(['tenant_id', 'user_id', 'created_at'], ['name' => 'idx_tenant_user_time'])
            ->save();

        // 添加IP审计索引
        $this->table('workflow_event_logs')
            ->addIndex(['ip_address', 'created_at'], ['name' => 'idx_ip_time'])
            ->save();

        // 添加事件类型统计索引
        $this->table('workflow_event_logs')
            ->addIndex(['event_type', 'created_at'], ['name' => 'idx_type_time'])
            ->save();
    }

    /**
     * 创建复合索引
     */
    protected function createCompositeIndexes(): void
    {
        // 租户+状态+时间范围查询优化
        $this->table('workflow_instances')
            ->addIndex(['tenant_id', 'status', 'started_at', 'completed_at'], ['name' => 'idx_tenant_status_time_range'])
            ->save();

        $this->table('workflow_tasks')
            ->addIndex(['tenant_id', 'status', 'created_at', 'due_date'], ['name' => 'idx_tenant_status_date_range'])
            ->save();

        // 多条件查询优化
        $this->table('workflow_event_logs')
            ->addIndex(['tenant_id', 'event_type', 'user_id', 'created_at'], ['name' => 'idx_multi_condition'])
            ->save();
    }

    /**
     * 创建部分索引
     * 注意：MySQL 8.0+才支持部分索引
     */
    protected function createPartialIndexes(): void
    {
        $db = $this->getDb();

        try {
            // 检查MySQL版本
            $version = $db->query('SELECT VERSION() as version')[0]['version'];

            if (version_compare($version, '8.0.0', '>=')) {
                // 创建活跃实例的部分索引
                $sql = "CREATE INDEX idx_active_instances ON workflow_instances (tenant_id, status, created_at) WHERE status IN ('pending', 'running')";
                $db->execute($sql);

                // 创建未完成任务的部分索引
                $sql = "CREATE INDEX idx_pending_tasks ON workflow_tasks (tenant_id, assignee, status) WHERE status = 'pending'";
                $db->execute($sql);

                // 创建激活触发器的部分索引
                $sql = 'CREATE INDEX idx_active_triggers ON workflow_triggers (tenant_id, type, is_active) WHERE is_active = 1';
                $db->execute($sql);

                $this->output->writeln('成功创建部分索引');
            } else {
                $this->output->writeln('MySQL版本不支持部分索引，跳过创建');
            }
        } catch (\Exception $e) {
            $this->output->writeln('创建部分索引失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取数据库连接
     *
     * @return \think\db\Connection
     */
    protected function getDb()
    {
        return $this->getAdapter()->getConnection();
    }
}
