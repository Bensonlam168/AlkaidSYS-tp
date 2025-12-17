<?php

declare(strict_types=1);

use think\migration\db\Column;
use think\migration\Migrator;

/**
 * 创建工作流引擎相关数据表
 * Create workflow engine tables
 *
 * @package plugins\workflow-engine\migrations
 */
class CreateWorkflowTables extends Migrator
{
    /**
     * 创建工作流定义表
     */
    protected function createWorkflowDefinitions(): void
    {
        $table = $this->table('workflow_definitions');
        $table->setId('id')
            ->addColumn('tenant_id', 'biginteger', ['comment' => '租户ID', 'null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'comment' => '工作流名称（唯一）', 'null' => false])
            ->addColumn('display_name', 'string', ['limit' => 200, 'comment' => '显示名称', 'null' => false])
            ->addColumn('description', 'text', ['comment' => '描述', 'null' => true])
            ->addColumn('type', 'string', ['limit' => 20, 'comment' => '类型：approval/automation/hybrid', 'null' => false, 'default' => 'approval'])
            ->addColumn('category', 'string', ['limit' => 50, 'comment' => '分类', 'null' => true])
            ->addColumn('icon', 'string', ['limit' => 100, 'comment' => '图标', 'null' => true])
            ->addColumn('version', 'integer', ['comment' => '版本号', 'null' => false, 'default' => 1])
            ->addColumn('status', 'string', ['limit' => 20, 'comment' => '状态：draft/published/archived', 'null' => false, 'default' => 'draft'])
            ->addColumn('bpmn_xml', 'text', ['comment' => 'BPMN 2.0 XML定义', 'null' => true])
            ->addColumn('nodes_config', 'json', ['comment' => '节点配置JSON', 'null' => true])
            ->addColumn('triggers_config', 'json', ['comment' => '触发器配置JSON', 'null' => true])
            ->addColumn('variables_schema', 'json', ['comment' => '变量Schema定义', 'null' => true])
            ->addColumn('is_active', 'boolean', ['comment' => '是否激活', 'null' => false, 'default' => true])
            ->addColumn('created_at', 'datetime', ['comment' => '创建时间', 'null' => true])
            ->addColumn('updated_at', 'datetime', ['comment' => '更新时间', 'null' => true])
            ->addColumn('created_by', 'biginteger', ['comment' => '创建用户ID', 'null' => true])
            ->addColumn('updated_by', 'biginteger', ['comment' => '更新用户ID', 'null' => true])
            ->addIndex(['tenant_id', 'name', 'version'], ['unique' => true, 'name' => 'uk_tenant_name_version'])
            ->addIndex('tenant_id', ['name' => 'idx_tenant'])
            ->addIndex('status', ['name' => 'idx_status'])
            ->addIndex('type', ['name' => 'idx_type'])
            ->setEngine('InnoDB')
            ->setComment('工作流定义表')
            ->create();
    }

    /**
     * 创建工作流实例表
     */
    protected function createWorkflowInstances(): void
    {
        $table = $this->table('workflow_instances');
        $table->setId('id')
            ->addColumn('definition_id', 'string', ['limit' => 50, 'comment' => '工作流定义ID', 'null' => false])
            ->addColumn('tenant_id', 'biginteger', ['comment' => '租户ID', 'null' => false])
            ->addColumn('business_key', 'string', ['limit' => 100, 'comment' => '业务键', 'null' => true])
            ->addColumn('title', 'string', ['limit' => 200, 'comment' => '实例标题', 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'comment' => '状态：pending/running/completed/failed/cancelled/suspended', 'null' => false])
            ->addColumn('priority', 'integer', ['comment' => '优先级（1-10）', 'null' => false, 'default' => 5])
            ->addColumn('current_node_id', 'string', ['limit' => 50, 'comment' => '当前节点ID', 'null' => true])
            ->addColumn('current_node_name', 'string', ['limit' => 200, 'comment' => '当前节点名称', 'null' => true])
            ->addColumn('context', 'json', ['comment' => '执行上下文数据', 'null' => true])
            ->addColumn('variables', 'json', ['comment' => '变量数据', 'null' => true])
            ->addColumn('started_at', 'datetime', ['comment' => '开始时间', 'null' => true])
            ->addColumn('completed_at', 'datetime', ['comment' => '完成时间', 'null' => true])
            ->addColumn('suspended_at', 'datetime', ['comment' => '暂停时间', 'null' => true])
            ->addColumn('duration', 'biginteger', ['comment' => '执行时长（毫秒）', 'null' => true])
            ->addColumn('error_message', 'text', ['comment' => '错误信息', 'null' => true])
            ->addColumn('error_stack', 'text', ['comment' => '错误堆栈', 'null' => true])
            ->addColumn('created_at', 'datetime', ['comment' => '创建时间', 'null' => true])
            ->addColumn('updated_at', 'datetime', ['comment' => '更新时间', 'null' => true])
            ->addColumn('created_by', 'biginteger', ['comment' => '发起用户ID', 'null' => true])
            ->addColumn('assignee', 'biginteger', ['comment' => '当前处理人ID', 'null' => true])
            ->addIndex('tenant_id', ['name' => 'idx_tenant'])
            ->addIndex('definition_id', ['name' => 'idx_definition'])
            ->addIndex('status', ['name' => 'idx_status'])
            ->addIndex('business_key', ['name' => 'idx_business_key'])
            ->addIndex('started_at', ['name' => 'idx_started_at'])
            ->addIndex('created_by', ['name' => 'idx_created_by'])
            ->addIndex('assignee', ['name' => 'idx_assignee'])
            ->setEngine('InnoDB')
            ->setComment('工作流实例表')
            ->create();
    }

    /**
     * 创建工作流任务表
     */
    protected function createWorkflowTasks(): void
    {
        $table = $this->table('workflow_tasks');
        $table->setId('id')
            ->addColumn('instance_id', 'string', ['limit' => 50, 'comment' => '工作流实例ID', 'null' => false])
            ->addColumn('tenant_id', 'biginteger', ['comment' => '租户ID', 'null' => false])
            ->addColumn('node_id', 'string', ['limit' => 50, 'comment' => '节点ID', 'null' => false])
            ->addColumn('node_name', 'string', ['limit' => 200, 'comment' => '节点名称', 'null' => false])
            ->addColumn('task_type', 'string', ['limit' => 20, 'comment' => '任务类型：user_task/system_task/service_task', 'null' => false])
            ->addColumn('task_sub_type', 'string', ['limit' => 20, 'comment' => '任务子类型：approval/countersign/notify', 'null' => true])
            ->addColumn('title', 'string', ['limit' => 200, 'comment' => '任务标题', 'null' => false])
            ->addColumn('description', 'text', ['comment' => '任务描述', 'null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'comment' => '状态：pending/claimed/completed/rejected/cancelled/escalated', 'null' => false])
            ->addColumn('priority', 'integer', ['comment' => '优先级（1-10）', 'null' => false, 'default' => 5])
            ->addColumn('assignee', 'biginteger', ['comment' => '处理人ID', 'null' => true])
            ->addColumn('assignee_name', 'string', ['limit' => 100, 'comment' => '处理人姓名', 'null' => true])
            ->addColumn('candidate_users', 'json', ['comment' => '候选用户列表', 'null' => true])
            ->addColumn('candidate_groups', 'json', ['comment' => '候选组列表', 'null' => true])
            ->addColumn('form_data', 'json', ['comment' => '表单数据', 'null' => true])
            ->addColumn('result', 'json', ['comment' => '处理结果', 'null' => true])
            ->addColumn('comment', 'text', ['comment' => '审批意见', 'null' => true])
            ->addColumn('due_date', 'datetime', ['comment' => '截止时间', 'null' => true])
            ->addColumn('reminder_times', 'json', ['comment' => '提醒时间列表', 'null' => true])
            ->addColumn('completed_at', 'datetime', ['comment' => '完成时间', 'null' => true])
            ->addColumn('rejected_at', 'datetime', ['comment' => '拒绝时间', 'null' => true])
            ->addColumn('cancelled_at', 'datetime', ['comment' => '取消时间', 'null' => true])
            ->addColumn('created_at', 'datetime', ['comment' => '创建时间', 'null' => true])
            ->addColumn('updated_at', 'datetime', ['comment' => '更新时间', 'null' => true])
            ->addColumn('created_by', 'biginteger', ['comment' => '创建人ID', 'null' => true])
            ->addIndex('tenant_id', ['name' => 'idx_tenant'])
            ->addIndex('instance_id', ['name' => 'idx_instance'])
            ->addIndex('status', ['name' => 'idx_status'])
            ->addIndex('assignee', ['name' => 'idx_assignee'])
            ->addIndex('due_date', ['name' => 'idx_due_date'])
            ->addIndex('created_at', ['name' => 'idx_created_at'])
            ->addIndex('node_id', ['name' => 'idx_node'])
            ->setEngine('InnoDB')
            ->setComment('工作流任务表')
            ->create();
    }

    /**
     * 创建工作流触发器表
     */
    protected function createWorkflowTriggers(): void
    {
        $table = $this->table('workflow_triggers');
        $table->setId('id')
            ->addColumn('definition_id', 'string', ['limit' => 50, 'comment' => '工作流定义ID', 'null' => false])
            ->addColumn('tenant_id', 'biginteger', ['comment' => '租户ID', 'null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'comment' => '触发器名称', 'null' => false])
            ->addColumn('type', 'string', ['limit' => 20, 'comment' => '触发器类型：manual/webhook/schedule/data_event/message_event', 'null' => false])
            ->addColumn('config', 'json', ['comment' => '触发器配置JSON', 'null' => false])
            ->addColumn('is_active', 'boolean', ['comment' => '是否激活', 'null' => false, 'default' => true])
            ->addColumn('created_at', 'datetime', ['comment' => '创建时间', 'null' => true])
            ->addColumn('updated_at', 'datetime', ['comment' => '更新时间', 'null' => true])
            ->addColumn('created_by', 'biginteger', ['comment' => '创建人ID', 'null' => true])
            ->addIndex(['tenant_id', 'name'], ['unique' => true, 'name' => 'uk_tenant_name'])
            ->addIndex('tenant_id', ['name' => 'idx_tenant'])
            ->addIndex('definition_id', ['name' => 'idx_definition'])
            ->addIndex('type', ['name' => 'idx_type'])
            ->addIndex('is_active', ['name' => 'idx_is_active'])
            ->setEngine('InnoDB')
            ->setComment('工作流触发器表')
            ->create();
    }

    /**
     * 创建工作流事件日志表
     */
    protected function createWorkflowEventLogs(): void
    {
        $table = $this->table('workflow_event_logs');
        $table->setId('id')
            ->addColumn('instance_id', 'string', ['limit' => 50, 'comment' => '工作流实例ID', 'null' => true])
            ->addColumn('task_id', 'string', ['limit' => 50, 'comment' => '任务ID', 'null' => true])
            ->addColumn('tenant_id', 'biginteger', ['comment' => '租户ID', 'null' => false])
            ->addColumn('event_type', 'string', ['limit' => 50, 'comment' => '事件类型', 'null' => false])
            ->addColumn('event_name', 'string', ['limit' => 100, 'comment' => '事件名称', 'null' => false])
            ->addColumn('event_data', 'json', ['comment' => '事件数据', 'null' => true])
            ->addColumn('user_id', 'biginteger', ['comment' => '操作用户ID', 'null' => true])
            ->addColumn('user_name', 'string', ['limit' => 100, 'comment' => '操作用户姓名', 'null' => true])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'comment' => 'IP地址', 'null' => true])
            ->addColumn('user_agent', 'text', ['comment' => '用户代理', 'null' => true])
            ->addColumn('created_at', 'datetime', ['comment' => '创建时间', 'null' => true])
            ->addIndex('tenant_id', ['name' => 'idx_tenant'])
            ->addIndex('instance_id', ['name' => 'idx_instance'])
            ->addIndex('task_id', ['name' => 'idx_task'])
            ->addIndex('event_type', ['name' => 'idx_event_type'])
            ->addIndex('created_at', ['name' => 'idx_created_at'])
            ->setEngine('InnoDB')
            ->setComment('工作流事件日志表')
            ->create();
    }

    /**
     * 执行迁移
     */
    public function change(): void
    {
        $this->createWorkflowDefinitions();
        $this->createWorkflowInstances();
        $this->createWorkflowTasks();
        $this->createWorkflowTriggers();
        $this->createWorkflowEventLogs();
    }
}
