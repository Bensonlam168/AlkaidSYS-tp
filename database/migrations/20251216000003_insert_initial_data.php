<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 插入工作流引擎初始数据
 * Insert workflow engine initial data
 *
 * 来源：plugins/workflow-engine/migrations/20251216000003_insert_initial_data.php
 *
 * 本次改造点：
 * 1. 原实现给 workflow_definitions / workflow_triggers / workflow_event_logs 显式指定了
 *    'workflow_reimbursement_001' 之类的字符串主键，而这些表的主键是 int unsigned 自增，
 *    在 STRICT_TRANS_TABLES 下会直接报 1366；现改为不写主键，由自增生成，
 *    触发器通过 (tenant_id, name, version) 反查真实 definition_id 建立关联；
 * 2. 插入前检查租户 1 是否存在，不存在则整体跳过并记录（兼容全新库）；
 * 3. 每类数据插入前做存在性判断，避免重复执行时命中唯一索引导致迁移中断；
 * 4. 补充 down()，按确定性标识（name / event_name）删除本迁移插入的示例数据。
 */
class InsertInitialData extends Migrator
{
    /**
     * 示例数据所属租户
     */
    private const TENANT_ID = 1;

    /**
     * 示例工作流定义的唯一标识（tenant_id + name + version 唯一）
     *
     * @var string[]
     */
    private const DEFINITION_NAMES = ['expense_reimbursement', 'leave_application'];

    /**
     * 示例系统事件名称（用于回滚时精确定位）
     *
     * @var string[]
     */
    private const EVENT_NAMES = ['workflow_engine_initialized', 'workflow_definitions_loaded'];

    /**
     * 插入初始数据
     *
     * 刻意使用 up()/down() 而非 change()：数据插入无法被框架“反向重放”成删除，
     * 只有显式 down() 才能保证回滚干净。
     */
    public function up(): void
    {
        if (!$this->hasTable('workflow_definitions')) {
            $this->notice('跳过工作流示例数据：workflow_definitions 表不存在');

            return;
        }

        // 示例数据依赖租户 1，全新库可能尚未初始化租户，此时整体跳过
        if (!$this->tenantExists(self::TENANT_ID)) {
            $this->notice('跳过工作流示例数据：租户 ' . self::TENANT_ID . ' 不存在');

            return;
        }

        $definitionIds = $this->insertSampleWorkflowDefinitions();

        if ($definitionIds === []) {
            $this->notice('跳过示例触发器与事件日志：未取得可用的工作流定义ID');

            return;
        }

        $this->insertSampleTriggers($definitionIds);
        $this->insertSystemConfig();
    }

    /**
     * 回滚迁移：删除本迁移插入的示例数据
     *
     * 删除顺序：触发器 -> 事件日志 -> 工作流定义（先子后父，兼容外键存在与否）。
     */
    public function down(): void
    {
        if (!$this->hasTable('workflow_definitions')) {
            $this->notice('跳过示例数据回滚：workflow_definitions 表不存在');

            return;
        }

        // 按 name 精确定位示例定义的 id，用于删除其触发器
        $definitionIds = [];
        foreach (self::DEFINITION_NAMES as $name) {
            $id = $this->findDefinitionId($name);
            if ($id !== null) {
                $definitionIds[] = $id;
            }
        }

        if ($definitionIds !== [] && $this->hasTable('workflow_triggers')) {
            $affected = $this->execute(sprintf(
                'DELETE FROM `workflow_triggers` WHERE `tenant_id` = %d AND `definition_id` IN (%s)',
                self::TENANT_ID,
                implode(',', $definitionIds)
            ));
            $this->notice("已删除示例触发器 {$affected} 条（按 definition_id 匹配）");
        }

        if ($this->hasTable('workflow_event_logs')) {
            $affected = $this->execute(sprintf(
                "DELETE FROM `workflow_event_logs` WHERE `tenant_id` = %d AND `event_type` = 'system' AND `event_name` IN ('%s')",
                self::TENANT_ID,
                implode("','", self::EVENT_NAMES)
            ));
            $this->notice("已删除示例事件日志 {$affected} 条（按 event_name 匹配）");
        }

        $affected = $this->execute(sprintf(
            "DELETE FROM `workflow_definitions` WHERE `tenant_id` = %d AND `version` = 1 AND `name` IN ('%s')",
            self::TENANT_ID,
            implode("','", self::DEFINITION_NAMES)
        ));
        $this->notice("已删除示例工作流定义 {$affected} 条（按 name 匹配）");
    }

    /**
     * 插入示例工作流定义，返回 [定义name => 定义id]
     *
     * @return array<string, int>
     */
    protected function insertSampleWorkflowDefinitions(): array
    {
        $timestamp = date('Y-m-d H:i:s');

        $rows = [
            $this->buildDefinitionRow(
                'expense_reimbursement',
                '费用报销流程',
                '员工费用报销审批流程',
                'finance',
                'expense-icon',
                [
                    'start' => ['id' => 'start', 'name' => '开始'],
                    'apply' => ['id' => 'apply', 'name' => '填写申请'],
                    'review_manager' => ['id' => 'review_manager', 'name' => '部门经理审批'],
                    'review_finance' => ['id' => 'review_finance', 'name' => '财务审批'],
                    'end' => ['id' => 'end', 'name' => '结束'],
                ],
                [
                    'applicant_name' => ['type' => 'string', 'required' => true],
                    'amount' => ['type' => 'number', 'required' => true],
                    'description' => ['type' => 'string', 'required' => true],
                    'category' => ['type' => 'string', 'required' => true],
                ],
                $timestamp
            ),
            $this->buildDefinitionRow(
                'leave_application',
                '请假申请流程',
                '员工请假申请审批流程',
                'hr',
                'leave-icon',
                [
                    'start' => ['id' => 'start', 'name' => '开始'],
                    'apply' => ['id' => 'apply', 'name' => '填写申请'],
                    'review_manager' => ['id' => 'review_manager', 'name' => '直属领导审批'],
                    'review_hr' => ['id' => 'review_hr', 'name' => 'HR审批'],
                    'end' => ['id' => 'end', 'name' => '结束'],
                ],
                [
                    'employee_name' => ['type' => 'string', 'required' => true],
                    'leave_type' => ['type' => 'string', 'required' => true],
                    'start_date' => ['type' => 'date', 'required' => true],
                    'end_date' => ['type' => 'date', 'required' => true],
                    'reason' => ['type' => 'string', 'required' => true],
                ],
                $timestamp
            ),
        ];

        // 已存在则跳过，避免重复执行命中 uk_tenant_name_version
        $pending = [];
        foreach ($rows as $row) {
            if ($this->definitionExists($row['name'])) {
                $this->notice("跳过示例工作流定义 {$row['name']}：已存在");
                continue;
            }
            $pending[] = $row;
        }

        if ($pending !== []) {
            $this->table('workflow_definitions')->insert($pending)->save();
            $this->notice('已插入示例工作流定义 ' . count($pending) . ' 条');
        }

        // 反查真实自增主键，供触发器建立关联
        $definitionIds = [];
        foreach (self::DEFINITION_NAMES as $name) {
            $id = $this->findDefinitionId($name);
            if ($id !== null) {
                $definitionIds[$name] = $id;
            }
        }

        return $definitionIds;
    }

    /**
     * 插入示例触发器
     *
     * @param array<string, int> $definitionIds 定义名称到定义ID的映射
     */
    protected function insertSampleTriggers(array $definitionIds): void
    {
        if (!$this->hasTable('workflow_triggers')) {
            $this->notice('跳过示例触发器：workflow_triggers 表不存在');

            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $candidates = [];

        if (isset($definitionIds['expense_reimbursement'])) {
            $candidates[] = $this->buildTriggerRow(
                '手动触发报销流程',
                $definitionIds['expense_reimbursement'],
                'manual',
                [
                    'description' => '通过系统手动触发费用报销流程',
                    'form_fields' => ['applicant_name', 'amount', 'description', 'category'],
                ],
                $timestamp
            );
            $candidates[] = $this->buildTriggerRow(
                'Webhook触发报销流程',
                $definitionIds['expense_reimbursement'],
                'webhook',
                [
                    'url' => '/api/workflow/trigger/expense',
                    'method' => 'POST',
                    'auth_type' => 'token',
                    'secret_key' => 'sample_webhook_secret',
                ],
                $timestamp
            );
        }

        if (isset($definitionIds['leave_application'])) {
            $candidates[] = $this->buildTriggerRow(
                '手动触发请假流程',
                $definitionIds['leave_application'],
                'manual',
                [
                    'description' => '通过系统手动触发请假申请流程',
                    'form_fields' => ['employee_name', 'leave_type', 'start_date', 'end_date', 'reason'],
                ],
                $timestamp
            );
        }

        // 已存在则跳过，避免重复执行命中 uk_tenant_name
        $pending = [];
        foreach ($candidates as $row) {
            if ($this->triggerExists($row['name'])) {
                $this->notice("跳过示例触发器 {$row['name']}：已存在");
                continue;
            }
            $pending[] = $row;
        }

        if ($pending === []) {
            return;
        }

        $this->table('workflow_triggers')->insert($pending)->save();
        $this->notice('已插入示例触发器 ' . count($pending) . ' 条');
    }

    /**
     * 插入系统初始化事件日志
     */
    protected function insertSystemConfig(): void
    {
        if (!$this->hasTable('workflow_event_logs')) {
            $this->notice('跳过示例事件日志：workflow_event_logs 表不存在');

            return;
        }

        $timestamp = date('Y-m-d H:i:s');

        $candidates = [
            $this->buildEventLogRow(
                'workflow_engine_initialized',
                [
                    'version' => '1.0.0',
                    'init_time' => $timestamp,
                    'features' => ['multi_tenant', 'bpmn_support', 'webhook_trigger', 'manual_trigger'],
                ],
                $timestamp
            ),
            $this->buildEventLogRow(
                'workflow_definitions_loaded',
                [
                    'definitions_count' => 2,
                    'triggers_count' => 3,
                    'loaded_definitions' => ['expense_reimbursement', 'leave_application'],
                ],
                $timestamp
            ),
        ];

        $pending = [];
        foreach ($candidates as $row) {
            if ($this->eventLogExists($row['event_name'])) {
                $this->notice("跳过示例事件日志 {$row['event_name']}：已存在");
                continue;
            }
            $pending[] = $row;
        }

        if ($pending === []) {
            return;
        }

        $this->table('workflow_event_logs')->insert($pending)->save();
        $this->notice('已插入示例事件日志 ' . count($pending) . ' 条');
    }

    /**
     * 构造工作流定义行（不写主键，由自增生成）
     *
     * @param array<string, array<string, mixed>> $nodes     节点配置
     * @param array<string, array<string, mixed>> $variables 变量 Schema
     * @return array<string, mixed>
     */
    protected function buildDefinitionRow(
        string $name,
        string $displayName,
        string $description,
        string $category,
        string $icon,
        array $nodes,
        array $variables,
        string $timestamp
    ): array {
        return [
            'tenant_id' => self::TENANT_ID,
            'name' => $name,
            'display_name' => $displayName,
            'description' => $description,
            'type' => 'approval',
            'category' => $category,
            'icon' => $icon,
            'version' => 1,
            'status' => 'published',
            'bpmn_xml' => $this->getSampleBpmnXml($name),
            'nodes_config' => json_encode($nodes, JSON_UNESCAPED_UNICODE),
            'triggers_config' => json_encode([], JSON_UNESCAPED_UNICODE),
            'variables_schema' => json_encode($variables, JSON_UNESCAPED_UNICODE),
            'is_active' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    /**
     * 构造触发器行（不写主键，由自增生成）
     *
     * @param array<string, mixed> $config 触发器配置
     * @return array<string, mixed>
     */
    protected function buildTriggerRow(
        string $name,
        int $definitionId,
        string $type,
        array $config,
        string $timestamp
    ): array {
        return [
            'definition_id' => $definitionId,
            'tenant_id' => self::TENANT_ID,
            'name' => $name,
            'type' => $type,
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'is_active' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'created_by' => 1,
        ];
    }

    /**
     * 构造事件日志行（不写主键，由自增生成）
     *
     * @param array<string, mixed> $eventData 事件数据
     * @return array<string, mixed>
     */
    protected function buildEventLogRow(string $eventName, array $eventData, string $timestamp): array
    {
        return [
            'instance_id' => null,
            'task_id' => null,
            'tenant_id' => self::TENANT_ID,
            'event_type' => 'system',
            'event_name' => $eventName,
            'event_data' => json_encode($eventData, JSON_UNESCAPED_UNICODE),
            'user_id' => 1,
            'user_name' => 'System',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'System Initializer',
            'created_at' => $timestamp,
        ];
    }

    /**
     * 判断租户是否存在
     */
    protected function tenantExists(int $tenantId): bool
    {
        if (!$this->hasTable('tenants')) {
            return false;
        }

        $row = $this->fetchRow(sprintf('SELECT `id` FROM `tenants` WHERE `id` = %d LIMIT 1', $tenantId));

        return is_array($row) && $row !== [];
    }

    /**
     * 判断示例工作流定义是否已存在
     */
    protected function definitionExists(string $name): bool
    {
        return $this->findDefinitionId($name) !== null;
    }

    /**
     * 按 (tenant_id, name, version) 反查工作流定义ID
     */
    protected function findDefinitionId(string $name): ?int
    {
        if (!$this->hasTable('workflow_definitions')) {
            return null;
        }

        $row = $this->fetchRow(sprintf(
            "SELECT `id` FROM `workflow_definitions` WHERE `tenant_id` = %d AND `name` = '%s' AND `version` = 1 LIMIT 1",
            self::TENANT_ID,
            $name
        ));

        if (!is_array($row) || !isset($row['id'])) {
            return null;
        }

        return (int) $row['id'];
    }

    /**
     * 判断示例触发器是否已存在（tenant_id + name 唯一）
     */
    protected function triggerExists(string $name): bool
    {
        $row = $this->fetchRow(sprintf(
            "SELECT `id` FROM `workflow_triggers` WHERE `tenant_id` = %d AND `name` = '%s' LIMIT 1",
            self::TENANT_ID,
            $name
        ));

        return is_array($row) && $row !== [];
    }

    /**
     * 判断示例事件日志是否已存在
     */
    protected function eventLogExists(string $eventName): bool
    {
        $row = $this->fetchRow(sprintf(
            "SELECT `id` FROM `workflow_event_logs` WHERE `tenant_id` = %d AND `event_type` = 'system' AND `event_name` = '%s' LIMIT 1",
            self::TENANT_ID,
            $eventName
        ));

        return is_array($row) && $row !== [];
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
