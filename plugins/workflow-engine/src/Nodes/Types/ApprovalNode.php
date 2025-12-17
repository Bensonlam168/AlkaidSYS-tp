<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * Approval Node | 审批节点
 *
 * A user task node that requires approval from designated approvers.
 * Supports single approver, sequential approval, and timeout handling.
 * 需要指定审批人进行审批的用户任务节点。
 * 支持单人审批、顺序审批和超时处理。
 *
 * Configuration options:
 * - approvers: array - List of approver user IDs or expressions
 * - approval_type: string - 'single', 'sequential', 'any'
 * - timeout: int - Timeout in seconds (0 = no timeout)
 * - timeout_action: string - 'auto_approve', 'auto_reject', 'escalate', 'notify'
 * - form_schema: array - Form definition for approval
 * - require_comment: bool - Whether comment is required
 * - notification: array - Notification settings
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
class ApprovalNode extends AbstractNode
{
    /**
     * Approval types | 审批类型
     */
    public const APPROVAL_TYPE_SINGLE = 'single';         // Single approver
    public const APPROVAL_TYPE_SEQUENTIAL = 'sequential'; // Sequential approval
    public const APPROVAL_TYPE_ANY = 'any';               // Any one can approve

    /**
     * Timeout actions | 超时动作
     */
    public const TIMEOUT_ACTION_AUTO_APPROVE = 'auto_approve';
    public const TIMEOUT_ACTION_AUTO_REJECT = 'auto_reject';
    public const TIMEOUT_ACTION_ESCALATE = 'escalate';
    public const TIMEOUT_ACTION_NOTIFY = 'notify';

    /**
     * Approval status | 审批状态
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Constructor
     *
     * @param string|null $id Node ID
     * @param array $config Node configuration
     */
    public function __construct(?string $id = null, array $config = [])
    {
        // Set default config values
        $config['sub_type'] = $config['sub_type'] ?? NodeInterface::TASK_USER;
        parent::__construct($id, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return NodeInterface::TYPE_TASK;
    }

    /**
     * {@inheritDoc}
     */
    public function getSubType(): ?string
    {
        return $this->config['sub_type'] ?? NodeInterface::TASK_USER;
    }

    /**
     * {@inheritDoc}
     */
    public function setConfig(array $config): static
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Execute the approval node | 执行审批节点
     *
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function doExecute(ExecutionContext $context): NodeResult
    {
        // Get or create task data
        $taskData = $this->getOrCreateTaskData($context);

        // Check if already completed
        if ($this->isTaskCompleted($taskData)) {
            return $this->handleCompletedTask($taskData, $context);
        }

        // Check timeout
        if ($this->isTimedOut($taskData)) {
            return $this->handleTimeout($taskData, $context);
        }

        // Return waiting status for pending approval
        return NodeResult::waiting([
            'task_id' => $taskData['task_id'],
            'task_type' => 'approval',
            'approvers' => $taskData['approvers'],
            'approval_type' => $taskData['approval_type'],
            'form_schema' => $this->getConfigValue('form_schema', []),
            'require_comment' => $this->getConfigValue('require_comment', false),
            'deadline' => $taskData['deadline'] ?? null,
            'status' => $taskData['status'],
            'node_id' => $this->id,
            'node_name' => $this->name,
        ], '等待审批');
    }

    /**
     * Get or create task data | 获取或创建任务数据
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function getOrCreateTaskData(ExecutionContext $context): array
    {
        $taskKey = "approval_task_{$this->id}";
        $existingTask = $context->getVariable($taskKey);

        if ($existingTask !== null) {
            return $existingTask;
        }

        // Create new task
        $taskId = $this->generateTaskId($context);
        $approvers = $this->resolveApprovers($context);
        $timeout = $this->getConfigValue('timeout', 0);

        $taskData = [
            'task_id' => $taskId,
            'node_id' => $this->id,
            'node_name' => $this->name,
            'instance_id' => $context->getInstanceId(),
            'tenant_id' => $context->getTenantId(),
            'approvers' => $approvers,
            'approval_type' => $this->getConfigValue('approval_type', self::APPROVAL_TYPE_SINGLE),
            'status' => self::STATUS_PENDING,
            'created_at' => (new \DateTimeImmutable())->format('c'),
            'deadline' => $timeout > 0
                ? (new \DateTimeImmutable())->modify("+{$timeout} seconds")->format('c')
                : null,
            'approvals' => [],
            'rejections' => [],
            'current_approver_index' => 0,
        ];

        // Store task in context
        $context->setVariable($taskKey, $taskData);

        return $taskData;
    }

    /**
     * Generate task ID | 生成任务ID
     *
     * @param ExecutionContext $context
     * @return string
     */
    protected function generateTaskId(ExecutionContext $context): string
    {
        return sprintf(
            'task_%s_%s_%s',
            $context->getInstanceId() ?? 'unknown',
            $this->id,
            bin2hex(random_bytes(4))
        );
    }

    /**
     * Resolve approvers from configuration | 从配置解析审批人
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveApprovers(ExecutionContext $context): array
    {
        $approverConfig = $this->getConfigValue('approvers', []);

        if (empty($approverConfig)) {
            // Use initiator as fallback
            $initiator = $context->getVariable('initiator_id');
            return $initiator ? [$initiator] : [];
        }

        $resolvedApprovers = [];
        foreach ($approverConfig as $approver) {
            if (is_string($approver)) {
                // Check if it's an expression
                if (str_starts_with($approver, '${') && str_ends_with($approver, '}')) {
                    $varName = substr($approver, 2, -1);
                    $resolved = $context->getVariable($varName);
                    if (is_array($resolved)) {
                        $resolvedApprovers = array_merge($resolvedApprovers, $resolved);
                    } elseif ($resolved) {
                        $resolvedApprovers[] = $resolved;
                    }
                } else {
                    // Direct user ID
                    $resolvedApprovers[] = $approver;
                }
            } elseif (is_array($approver)) {
                // Handle complex approver definition
                if (isset($approver['type'])) {
                    $resolvedApprovers = array_merge(
                        $resolvedApprovers,
                        $this->resolveApproverByType($approver, $context)
                    );
                }
            }
        }

        return array_unique($resolvedApprovers);
    }

    /**
     * Resolve approvers by type | 按类型解析审批人
     *
     * @param array $approverConfig
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveApproverByType(array $approverConfig, ExecutionContext $context): array
    {
        $type = $approverConfig['type'] ?? 'user';
        $value = $approverConfig['value'] ?? '';

        return match ($type) {
            'user' => is_array($value) ? $value : [$value],
            'role' => $this->resolveUsersByRole($value, $context),
            'department' => $this->resolveUsersByDepartment($value, $context),
            'expression' => $this->evaluateApproverExpression($value, $context),
            'initiator' => [$context->getVariable('initiator_id') ?? ''],
            'superior' => $this->resolveSuperior($context),
            default => [],
        };
    }

    /**
     * Resolve users by role | 按角色解析用户
     *
     * @param string $role
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveUsersByRole(string $role, ExecutionContext $context): array
    {
        // This would typically query the user service
        // For now, return from context if available
        $roleUsers = $context->getVariable("role_users_{$role}");
        return is_array($roleUsers) ? $roleUsers : [];
    }

    /**
     * Resolve users by department | 按部门解析用户
     *
     * @param string $department
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveUsersByDepartment(string $department, ExecutionContext $context): array
    {
        // This would typically query the organization service
        // For now, return from context if available
        $deptUsers = $context->getVariable("dept_users_{$department}");
        return is_array($deptUsers) ? $deptUsers : [];
    }

    /**
     * Evaluate approver expression | 评估审批人表达式
     *
     * @param string $expression
     * @param ExecutionContext $context
     * @return array
     */
    protected function evaluateApproverExpression(string $expression, ExecutionContext $context): array
    {
        // Simple expression evaluation
        // Format: variable.property or just variable
        $result = $this->evaluateCondition($expression, $context);
        return is_array($result) ? $result : ($result ? [$result] : []);
    }

    /**
     * Resolve superior | 解析上级
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveSuperior(ExecutionContext $context): array
    {
        $initiatorId = $context->getVariable('initiator_id');
        if (!$initiatorId) {
            return [];
        }

        // This would typically query the organization service
        $superior = $context->getVariable("user_{$initiatorId}_superior");
        return $superior ? [$superior] : [];
    }

    /**
     * Check if task is completed | 检查任务是否完成
     *
     * @param array $taskData
     * @return bool
     */
    protected function isTaskCompleted(array $taskData): bool
    {
        return in_array($taskData['status'], [
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /**
     * Handle completed task | 处理已完成的任务
     *
     * @param array $taskData
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function handleCompletedTask(array $taskData, ExecutionContext $context): NodeResult
    {
        $status = $taskData['status'];

        // Store approval result in context
        $context->setVariable("approval_result_{$this->id}", [
            'status' => $status,
            'approvals' => $taskData['approvals'],
            'rejections' => $taskData['rejections'],
            'completed_at' => (new \DateTimeImmutable())->format('c'),
        ]);

        if ($status === self::STATUS_APPROVED) {
            return NodeResult::success([
                'task_id' => $taskData['task_id'],
                'status' => $status,
                'approvals' => $taskData['approvals'],
                'message' => '审批通过',
            ], $this->getNextNodesForOutcome('approved'));
        }

        if ($status === self::STATUS_REJECTED) {
            // Get rejection route or default next nodes
            $rejectNodes = $this->getNextNodesForOutcome('rejected');
            if (empty($rejectNodes)) {
                return NodeResult::failed('审批被拒绝', 400);
            }

            return NodeResult::success([
                'task_id' => $taskData['task_id'],
                'status' => $status,
                'rejections' => $taskData['rejections'],
                'message' => '审批被拒绝',
            ], $rejectNodes);
        }

        return NodeResult::failed('任务已取消', 400);
    }

    /**
     * Check if task is timed out | 检查任务是否超时
     *
     * @param array $taskData
     * @return bool
     */
    protected function isTimedOut(array $taskData): bool
    {
        if (empty($taskData['deadline'])) {
            return false;
        }

        $deadline = new \DateTimeImmutable($taskData['deadline']);
        return new \DateTimeImmutable() > $deadline;
    }

    /**
     * Handle timeout | 处理超时
     *
     * @param array $taskData
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function handleTimeout(array $taskData, ExecutionContext $context): NodeResult
    {
        $timeoutAction = $this->getConfigValue('timeout_action', self::TIMEOUT_ACTION_NOTIFY);

        // Update task status
        $taskKey = "approval_task_{$this->id}";
        $taskData['status'] = self::STATUS_TIMEOUT;
        $taskData['timeout_at'] = (new \DateTimeImmutable())->format('c');
        $context->setVariable($taskKey, $taskData);

        return match ($timeoutAction) {
            self::TIMEOUT_ACTION_AUTO_APPROVE => NodeResult::success([
                'task_id' => $taskData['task_id'],
                'status' => 'auto_approved',
                'message' => '审批超时自动通过',
            ], $this->getNextNodesForOutcome('approved')),

            self::TIMEOUT_ACTION_AUTO_REJECT => NodeResult::success([
                'task_id' => $taskData['task_id'],
                'status' => 'auto_rejected',
                'message' => '审批超时自动拒绝',
            ], $this->getNextNodesForOutcome('rejected')),

            self::TIMEOUT_ACTION_ESCALATE => $this->handleEscalation($taskData, $context),

            default => NodeResult::waiting([
                'task_id' => $taskData['task_id'],
                'status' => 'timeout_notified',
                'message' => '审批超时已通知',
            ], '审批超时'),
        };
    }

    /**
     * Handle escalation | 处理升级
     *
     * @param array $taskData
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function handleEscalation(array $taskData, ExecutionContext $context): NodeResult
    {
        $escalateTo = $this->getConfigValue('escalate_to', []);

        if (empty($escalateTo)) {
            // No escalation target, auto reject
            return NodeResult::success([
                'task_id' => $taskData['task_id'],
                'status' => 'escalation_failed',
                'message' => '无升级目标，审批失败',
            ], $this->getNextNodesForOutcome('rejected'));
        }

        // Update approvers for escalation
        $taskKey = "approval_task_{$this->id}";
        $taskData['approvers'] = $escalateTo;
        $taskData['escalated'] = true;
        $taskData['escalated_at'] = (new \DateTimeImmutable())->format('c');
        $taskData['status'] = self::STATUS_PENDING;

        // Reset deadline
        $timeout = $this->getConfigValue('timeout', 0);
        if ($timeout > 0) {
            $taskData['deadline'] = (new \DateTimeImmutable())->modify("+{$timeout} seconds")->format('c');
        }

        $context->setVariable($taskKey, $taskData);

        return NodeResult::waiting([
            'task_id' => $taskData['task_id'],
            'status' => 'escalated',
            'approvers' => $escalateTo,
            'message' => '已升级至上级审批',
        ], '已升级审批');
    }

    /**
     * Get next nodes for specific outcome | 获取特定结果的后续节点
     *
     * @param string $outcome
     * @return array
     */
    protected function getNextNodesForOutcome(string $outcome): array
    {
        $outcomeRoutes = $this->getConfigValue('outcome_routes', []);

        if (isset($outcomeRoutes[$outcome])) {
            return is_array($outcomeRoutes[$outcome])
                ? $outcomeRoutes[$outcome]
                : [$outcomeRoutes[$outcome]];
        }

        // Default to configured next nodes
        return $this->getDefaultNextNodes();
    }

    /**
     * Process approval action | 处理审批动作
     *
     * This method should be called when an approver takes action.
     * 当审批人执行操作时应调用此方法。
     *
     * @param ExecutionContext $context
     * @param string $action 'approve' or 'reject'
     * @param string $approverId
     * @param string|null $comment
     * @param array $formData
     * @return NodeResult
     */
    public function processAction(
        ExecutionContext $context,
        string $action,
        string $approverId,
        ?string $comment = null,
        array $formData = []
    ): NodeResult {
        $taskKey = "approval_task_{$this->id}";
        $taskData = $context->getVariable($taskKey);

        if ($taskData === null) {
            return NodeResult::failed('任务不存在', 404);
        }

        if ($this->isTaskCompleted($taskData)) {
            return NodeResult::failed('任务已完成', 400);
        }

        // Verify approver
        if (!in_array($approverId, $taskData['approvers'], true)) {
            return NodeResult::failed('无审批权限', 403);
        }

        // Validate comment requirement
        if ($this->getConfigValue('require_comment', false) && empty($comment)) {
            return NodeResult::failed('审批意见不能为空', 400);
        }

        // Record the action
        $actionRecord = [
            'approver_id' => $approverId,
            'action' => $action,
            'comment' => $comment,
            'form_data' => $formData,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];

        if ($action === 'approve') {
            $taskData['approvals'][] = $actionRecord;
        } else {
            $taskData['rejections'][] = $actionRecord;
        }

        // Determine task completion based on approval type
        $approvalType = $taskData['approval_type'];
        $completed = $this->checkApprovalCompletion($taskData, $action, $approvalType);

        if ($completed) {
            $taskData['status'] = $action === 'approve' ? self::STATUS_APPROVED : self::STATUS_REJECTED;
            $taskData['completed_at'] = (new \DateTimeImmutable())->format('c');
        }

        // Update task data
        $context->setVariable($taskKey, $taskData);

        // If completed, return appropriate result
        if ($completed) {
            return $this->handleCompletedTask($taskData, $context);
        }

        // Still waiting for more approvals
        return NodeResult::waiting([
            'task_id' => $taskData['task_id'],
            'status' => self::STATUS_PENDING,
            'approvals_count' => count($taskData['approvals']),
            'rejections_count' => count($taskData['rejections']),
            'message' => '等待其他审批人',
        ], '等待其他审批');
    }

    /**
     * Check if approval process is completed | 检查审批流程是否完成
     *
     * @param array $taskData
     * @param string $lastAction
     * @param string $approvalType
     * @return bool
     */
    protected function checkApprovalCompletion(array $taskData, string $lastAction, string $approvalType): bool
    {
        $totalApprovers = count($taskData['approvers']);
        $approvalCount = count($taskData['approvals']);
        $rejectionCount = count($taskData['rejections']);

        return match ($approvalType) {
            self::APPROVAL_TYPE_SINGLE => true, // Single approver, any action completes
            self::APPROVAL_TYPE_ANY => $lastAction === 'approve' || $rejectionCount >= $totalApprovers,
            self::APPROVAL_TYPE_SEQUENTIAL => $this->checkSequentialCompletion($taskData, $lastAction),
            default => $approvalCount + $rejectionCount >= $totalApprovers,
        };
    }

    /**
     * Check sequential approval completion | 检查顺序审批完成状态
     *
     * @param array $taskData
     * @param string $lastAction
     * @return bool
     */
    protected function checkSequentialCompletion(array $taskData, string $lastAction): bool
    {
        if ($lastAction === 'reject') {
            return true; // Any rejection ends sequential approval
        }

        $currentIndex = $taskData['current_approver_index'];
        $totalApprovers = count($taskData['approvers']);

        return $currentIndex >= $totalApprovers - 1;
    }

    /**
     * Get configuration schema | 获取配置 Schema
     *
     * @return array
     */
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'approvers' => [
                    'type' => 'array',
                    'description' => '审批人配置',
                    'items' => [
                        'oneOf' => [
                            ['type' => 'string'],
                            [
                                'type' => 'object',
                                'properties' => [
                                    'type' => ['type' => 'string', 'enum' => ['user', 'role', 'department', 'expression', 'initiator', 'superior']],
                                    'value' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'approval_type' => [
                    'type' => 'string',
                    'enum' => [self::APPROVAL_TYPE_SINGLE, self::APPROVAL_TYPE_SEQUENTIAL, self::APPROVAL_TYPE_ANY],
                    'default' => self::APPROVAL_TYPE_SINGLE,
                    'description' => '审批类型',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => '超时时间（秒），0表示无超时',
                ],
                'timeout_action' => [
                    'type' => 'string',
                    'enum' => [
                        self::TIMEOUT_ACTION_AUTO_APPROVE,
                        self::TIMEOUT_ACTION_AUTO_REJECT,
                        self::TIMEOUT_ACTION_ESCALATE,
                        self::TIMEOUT_ACTION_NOTIFY,
                    ],
                    'default' => self::TIMEOUT_ACTION_NOTIFY,
                    'description' => '超时动作',
                ],
                'escalate_to' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '升级审批人',
                ],
                'form_schema' => [
                    'type' => 'object',
                    'description' => '审批表单Schema',
                ],
                'require_comment' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => '是否必须填写审批意见',
                ],
                'outcome_routes' => [
                    'type' => 'object',
                    'properties' => [
                        'approved' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'rejected' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'description' => '结果路由配置',
                ],
                'notification' => [
                    'type' => 'object',
                    'properties' => [
                        'enabled' => ['type' => 'boolean', 'default' => true],
                        'channels' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'template' => ['type' => 'string'],
                    ],
                    'description' => '通知配置',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Validate configuration | 验证配置
     *
     * @param array $config
     * @return bool
     */
    public function validateConfig(array $config = []): bool
    {
        $configToValidate = !empty($config) ? $config : $this->config;

        // Approval type validation
        if (isset($configToValidate['approval_type'])) {
            $validTypes = [self::APPROVAL_TYPE_SINGLE, self::APPROVAL_TYPE_SEQUENTIAL, self::APPROVAL_TYPE_ANY];
            if (!in_array($configToValidate['approval_type'], $validTypes, true)) {
                return false;
            }
        }

        // Timeout action validation
        if (isset($configToValidate['timeout_action'])) {
            $validActions = [
                self::TIMEOUT_ACTION_AUTO_APPROVE,
                self::TIMEOUT_ACTION_AUTO_REJECT,
                self::TIMEOUT_ACTION_ESCALATE,
                self::TIMEOUT_ACTION_NOTIFY,
            ];
            if (!in_array($configToValidate['timeout_action'], $validActions, true)) {
                return false;
            }
        }

        // Timeout validation
        if (isset($configToValidate['timeout']) && $configToValidate['timeout'] < 0) {
            return false;
        }

        return parent::validateConfig($configToValidate);
    }
}
