<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 插入工作流引擎初始数据
 * Insert workflow engine initial data
 *
 * @package plugins\workflow-engine\migrations
 */
class InsertInitialData extends Migrator
{
    /**
     * 插入初始数据
     */
    public function change(): void
    {
        // 插入示例工作流定义
        $this->insertSampleWorkflowDefinitions();

        // 插入示例触发器
        $this->insertSampleTriggers();

        // 插入系统配置数据
        $this->insertSystemConfig();
    }

    /**
     * 插入示例工作流定义
     */
    protected function insertSampleWorkflowDefinitions(): void
    {
        $timestamp = date('Y-m-d H:i:s');

        // 示例：费用报销工作流
        $reimbursementWorkflow = [
            'id' => 'workflow_reimbursement_001',
            'tenant_id' => 1,
            'name' => 'expense_reimbursement',
            'display_name' => '费用报销流程',
            'description' => '员工费用报销审批流程',
            'type' => 'approval',
            'category' => 'finance',
            'icon' => 'expense-icon',
            'version' => 1,
            'status' => 'published',
            'bpmn_xml' => $this->getSampleBpmnXml('expense_reimbursement'),
            'nodes_config' => json_encode([
                'start' => ['id' => 'start', 'name' => '开始'],
                'apply' => ['id' => 'apply', 'name' => '填写申请'],
                'review_manager' => ['id' => 'review_manager', 'name' => '部门经理审批'],
                'review_finance' => ['id' => 'review_finance', 'name' => '财务审批'],
                'end' => ['id' => 'end', 'name' => '结束'],
            ]),
            'triggers_config' => json_encode([]),
            'variables_schema' => json_encode([
                'applicant_name' => ['type' => 'string', 'required' => true],
                'amount' => ['type' => 'number', 'required' => true],
                'description' => ['type' => 'string', 'required' => true],
                'category' => ['type' => 'string', 'required' => true],
            ]),
            'is_active' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'created_by' => 1,
            'updated_by' => 1,
        ];

        // 示例：请假申请工作流
        $leaveWorkflow = [
            'id' => 'workflow_leave_001',
            'tenant_id' => 1,
            'name' => 'leave_application',
            'display_name' => '请假申请流程',
            'description' => '员工请假申请审批流程',
            'type' => 'approval',
            'category' => 'hr',
            'icon' => 'leave-icon',
            'version' => 1,
            'status' => 'published',
            'bpmn_xml' => $this->getSampleBpmnXml('leave_application'),
            'nodes_config' => json_encode([
                'start' => ['id' => 'start', 'name' => '开始'],
                'apply' => ['id' => 'apply', 'name' => '填写申请'],
                'review_manager' => ['id' => 'review_manager', 'name' => '直属领导审批'],
                'review_hr' => ['id' => 'review_hr', 'name' => 'HR审批'],
                'end' => ['id' => 'end', 'name' => '结束'],
            ]),
            'triggers_config' => json_encode([]),
            'variables_schema' => json_encode([
                'employee_name' => ['type' => 'string', 'required' => true],
                'leave_type' => ['type' => 'string', 'required' => true],
                'start_date' => ['type' => 'date', 'required' => true],
                'end_date' => ['type' => 'date', 'required' => true],
                'reason' => ['type' => 'string', 'required' => true],
            ]),
            'is_active' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'created_by' => 1,
            'updated_by' => 1,
        ];

        $this->table('workflow_definitions')->insert([
            $reimbursementWorkflow,
            $leaveWorkflow,
        ])->save();
    }

    /**
     * 插入示例触发器
     */
    protected function insertSampleTriggers(): void
    {
        $timestamp = date('Y-m-d H:i:s');

        // 费用报销工作流的手动触发器
        $reimbursementTriggers = [
            [
                'id' => 'trigger_reimbursement_manual_001',
                'definition_id' => 'workflow_reimbursement_001',
                'tenant_id' => 1,
                'name' => '手动触发报销流程',
                'type' => 'manual',
                'config' => json_encode([
                    'description' => '通过系统手动触发费用报销流程',
                    'form_fields' => ['applicant_name', 'amount', 'description', 'category'],
                ]),
                'is_active' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'created_by' => 1,
            ],
            [
                'id' => 'trigger_reimbursement_webhook_001',
                'definition_id' => 'workflow_reimbursement_001',
                'tenant_id' => 1,
                'name' => 'Webhook触发报销流程',
                'type' => 'webhook',
                'config' => json_encode([
                    'url' => '/api/workflow/trigger/expense',
                    'method' => 'POST',
                    'auth_type' => 'token',
                    'secret_key' => 'sample_webhook_secret',
                ]),
                'is_active' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'created_by' => 1,
            ],
        ];

        // 请假申请工作流的手动触发器
        $leaveTriggers = [
            [
                'id' => 'trigger_leave_manual_001',
                'definition_id' => 'workflow_leave_001',
                'tenant_id' => 1,
                'name' => '手动触发请假流程',
                'type' => 'manual',
                'config' => json_encode([
                    'description' => '通过系统手动触发请假申请流程',
                    'form_fields' => ['employee_name', 'leave_type', 'start_date', 'end_date', 'reason'],
                ]),
                'is_active' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'created_by' => 1,
            ],
        ];

        $this->table('workflow_triggers')->insert(array_merge($reimbursementTriggers, $leaveTriggers))->save();
    }

    /**
     * 插入系统配置数据
     */
    protected function insertSystemConfig(): void
    {
        $timestamp = date('Y-m-d H:i:s');

        // 插入系统配置数据到事件日志表（作为系统初始化记录）
        $systemEvents = [
            [
                'id' => 'event_system_init_001',
                'instance_id' => null,
                'task_id' => null,
                'tenant_id' => 1,
                'event_type' => 'system',
                'event_name' => 'workflow_engine_initialized',
                'event_data' => json_encode([
                    'version' => '1.0.0',
                    'init_time' => $timestamp,
                    'features' => ['multi_tenant', 'bpmn_support', 'webhook_trigger', 'manual_trigger'],
                ]),
                'user_id' => 1,
                'user_name' => 'System',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'System Initializer',
                'created_at' => $timestamp,
            ],
            [
                'id' => 'event_workflow_definitions_loaded',
                'instance_id' => null,
                'task_id' => null,
                'tenant_id' => 1,
                'event_type' => 'system',
                'event_name' => 'workflow_definitions_loaded',
                'event_data' => json_encode([
                    'definitions_count' => 2,
                    'triggers_count' => 3,
                    'loaded_definitions' => ['expense_reimbursement', 'leave_application'],
                ]),
                'user_id' => 1,
                'user_name' => 'System',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'System Initializer',
                'created_at' => $timestamp,
            ],
        ];

        $this->table('workflow_event_logs')->insert($systemEvents)->save();
    }

    /**
     * 获取示例BPMN XML
     *
     * @param string $workflowType 工作流类型
     * @return string
     */
    protected function getSampleBpmnXml(string $workflowType): string
    {
        if ($workflowType === 'expense_reimbursement') {
            return '<?xml version="1.0" encoding="UTF-8"?>
<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"
                  xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI"
                  xmlns:dc="http://www.omg.org/spec/DD/20100524/DC"
                  xmlns:di="http://www.omg.org/spec/DD/20100524/DI"
                  id="Definitions_ExpenseReimbursement"
                  targetNamespace="http://bpmn.io/schema/bpmn">
  <bpmn:process id="Process_ExpenseReimbursement" name="费用报销流程" isExecutable="false">
    <bpmn:startEvent id="StartEvent_1" name="开始">
      <bpmn:outgoing>SequenceFlow_1</bpmn:outgoing>
    </bpmn:startEvent>
    <bpmn:userTask id="Task_ApplyExpense" name="填写报销申请">
      <bpmn:incoming>SequenceFlow_1</bpmn:incoming>
      <bpmn:outgoing>SequenceFlow_2</bpmn:outgoing>
    </bpmn:userTask>
    <bpmn:userTask id="Task_ManagerReview" name="部门经理审批">
      <bpmn:incoming>SequenceFlow_2</bpmn:incoming>
      <bpmn:outgoing>SequenceFlow_3</bpmn:outgoing>
    </bpmn:userTask>
    <bpmn:userTask id="Task_FinanceReview" name="财务审批">
      <bpmn:incoming>SequenceFlow_3</bpmn:incoming>
      <bpmn:outgoing>SequenceFlow_4</bpmn:outgoing>
    </bpmn:userTask>
    <bpmn:endEvent id="EndEvent_1" name="结束">
      <bpmn:incoming>SequenceFlow_4</bpmn:incoming>
    </bpmn:endEvent>
    <bpmn:sequenceFlow id="SequenceFlow_1" sourceRef="StartEvent_1" targetRef="Task_ApplyExpense"/>
    <bpmn:sequenceFlow id="SequenceFlow_2" sourceRef="Task_ApplyExpense" targetRef="Task_ManagerReview"/>
    <bpmn:sequenceFlow id="SequenceFlow_3" sourceRef="Task_ManagerReview" targetRef="Task_FinanceReview"/>
    <bpmn:sequenceFlow id="SequenceFlow_4" sourceRef="Task_FinanceReview" targetRef="EndEvent_1"/>
  </bpmn:process>
</bpmn:definitions>';
        }

        if ($workflowType === 'leave_application') {
            return '<?xml version="1.0" encoding="UTF-8"?>
<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"
                  xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI"
                  xmlns:dc="http://www.omg.org/spec/DD/20100524/DC"
                  xmlns:di="http://www.omg.org/spec/DD/20100524/DI"
                  id="Definitions_LeaveApplication"
                  targetNamespace="http://bpmn.io/schema/bpmn">
  <bpmn:process id="Process_LeaveApplication" name="请假申请流程" isExecutable="false">
    <bpmn:startEvent id="StartEvent_1" name="开始">
      <bpmn:outgoing>SequenceFlow_1</bpmn:outgoing>
    </bpmn:startEvent>
    <bpmn:userTask id="Task_ApplyLeave" name="填写请假申请">
      <bpmn:incoming>SequenceFlow_1</bpmn:incoming>
      <bpmn:outgoing>SequenceFlow_2</bpmn:outgoing>
    </bpmn:userTask>
    <bpmn:userTask id="Task_ManagerReview" name="直属领导审批">
      <bpmn:incoming>SequenceFlow_2</bpmn:incoming>
      <bpmn:outgoing>SequenceFlow_3</bpmn:outgoing>
    </bpmn:userTask>
    <bpmn:userTask id="Task_HRReview" name="HR审批">
      <bpmn:incoming>SequenceFlow_3</bpmn:incoming>
      <bpmn:outgoing>SequenceFlow_4</bpmn:outgoing>
    </bpmn:userTask>
    <bpmn:endEvent id="EndEvent_1" name="结束">
      <bpmn:incoming>SequenceFlow_4</bpmn:incoming>
    </bpmn:endEvent>
    <bpmn:sequenceFlow id="SequenceFlow_1" sourceRef="StartEvent_1" targetRef="Task_ApplyLeave"/>
    <bpmn:sequenceFlow id="SequenceFlow_2" sourceRef="Task_ApplyLeave" targetRef="Task_ManagerReview"/>
    <bpmn:sequenceFlow id="SequenceFlow_3" sourceRef="Task_ManagerReview" targetRef="Task_HRReview"/>
    <bpmn:sequenceFlow id="SequenceFlow_4" sourceRef="Task_HRReview" targetRef="EndEvent_1"/>
  </bpmn:process>
</bpmn:definitions>';
        }

        return '';
    }
}
