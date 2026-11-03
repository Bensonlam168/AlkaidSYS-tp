<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * Countersign Node | 会签节点
 *
 * A multi-approver node that requires all or a percentage of approvers
 * to approve before proceeding. Supports various completion strategies.
 * 需要所有或一定比例审批人通过才能继续的多人审批节点。
 * 支持多种完成策略。
 *
 * Configuration options:
 * - approvers: array - List of approver IDs or expressions
 * - completion_strategy: string - 'all', 'majority', 'percentage', 'count'
 * - completion_value: int|float - Value for percentage/count strategies
 * - allow_abstain: bool - Whether abstention is allowed
 * - timeout: int - Timeout in seconds
 * - timeout_action: string - Action on timeout
 * - parallel: bool - Whether approvals happen in parallel
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
class CountersignNode extends AbstractNode
{
    /**
     * Completion strategies | 完成策略
     */
    public const STRATEGY_ALL = 'all';             // All must approve
    public const STRATEGY_MAJORITY = 'majority';   // >50% must approve
    public const STRATEGY_PERCENTAGE = 'percentage'; // Custom percentage
    public const STRATEGY_COUNT = 'count';         // Fixed count must approve

    /**
     * Vote types | 投票类型
     */
    public const VOTE_APPROVE = 'approve';
    public const VOTE_REJECT = 'reject';
    public const VOTE_ABSTAIN = 'abstain';

    /**
     * Task status | 任务状态
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_TIMEOUT = 'timeout';

    /**
     * Constructor
     *
     * @param string|null $id Node ID
     * @param array $config Node configuration
     */
    public function __construct(?string $id = null, array $config = [])
    {
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
     * Execute the countersign node | 执行会签节点
     *
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function doExecute(ExecutionContext $context): NodeResult
    {
        $taskData = $this->getOrCreateCountersignTask($context);

        // Check if already completed
        if ($this->isCountersignCompleted($taskData)) {
            return $this->handleCompletedCountersign($taskData, $context);
        }

        // Check timeout
        if ($this->isTimedOut($taskData)) {
            return $this->handleTimeout($taskData, $context);
        }

        // Return waiting status
        return NodeResult::waiting([
            'task_id' => $taskData['task_id'],
            'task_type' => 'countersign',
            'approvers' => $taskData['approvers'],
            'votes' => $this->summarizeVotes($taskData),
            'completion_strategy' => $taskData['completion_strategy'],
            'completion_value' => $taskData['completion_value'],
            'progress' => $this->calculateProgress($taskData),
            'deadline' => $taskData['deadline'] ?? null,
            'status' => $taskData['status'],
            'node_id' => $this->id,
            'node_name' => $this->name,
        ], '等待会签');
    }

    /**
     * Get or create countersign task | 获取或创建会签任务
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function getOrCreateCountersignTask(ExecutionContext $context): array
    {
        $taskKey = "countersign_task_{$this->id}";
        $existingTask = $context->getVariable($taskKey);

        if ($existingTask !== null) {
            return $existingTask;
        }

        $taskId = $this->generateTaskId($context);
        $approvers = $this->resolveApprovers($context);
        $timeout = $this->getConfigValue('timeout', 0);
        $strategy = $this->getConfigValue('completion_strategy', self::STRATEGY_ALL);

        $taskData = [
            'task_id' => $taskId,
            'node_id' => $this->id,
            'node_name' => $this->name,
            'instance_id' => $context->getInstanceId(),
            'tenant_id' => $context->getTenantId(),
            'approvers' => $approvers,
            'completion_strategy' => $strategy,
            'completion_value' => $this->getCompletionValue($strategy),
            'status' => self::STATUS_PENDING,
            'created_at' => (new \DateTimeImmutable())->format('c'),
            'deadline' => $timeout > 0
                ? (new \DateTimeImmutable())->modify("+{$timeout} seconds")->format('c')
                : null,
            'votes' => [],
            'allow_abstain' => $this->getConfigValue('allow_abstain', false),
            'parallel' => $this->getConfigValue('parallel', true),
        ];

        // Initialize individual tasks for each approver
        foreach ($approvers as $approverId) {
            $taskData['votes'][$approverId] = [
                'vote' => null,
                'comment' => null,
                'form_data' => [],
                'voted_at' => null,
            ];
        }

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
            'csign_%s_%s_%s',
            $context->getInstanceId() ?? 'unknown',
            $this->id,
            bin2hex(random_bytes(4))
        );
    }

    /**
     * Resolve approvers | 解析审批人
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveApprovers(ExecutionContext $context): array
    {
        $approverConfig = $this->getConfigValue('approvers', []);

        if (empty($approverConfig)) {
            return [];
        }

        $resolvedApprovers = [];
        foreach ($approverConfig as $approver) {
            if (is_string($approver)) {
                if (str_starts_with($approver, '${') && str_ends_with($approver, '}')) {
                    $varName = substr($approver, 2, -1);
                    $resolved = $context->getVariable($varName);
                    if (is_array($resolved)) {
                        $resolvedApprovers = array_merge($resolvedApprovers, $resolved);
                    } elseif ($resolved) {
                        $resolvedApprovers[] = $resolved;
                    }
                } else {
                    $resolvedApprovers[] = $approver;
                }
            } elseif (is_array($approver) && isset($approver['type'])) {
                $resolvedApprovers = array_merge(
                    $resolvedApprovers,
                    $this->resolveApproverByType($approver, $context)
                );
            }
        }

        return array_unique($resolvedApprovers);
    }

    /**
     * Resolve approvers by type | 按类型解析审批人
     *
     * @param array $config
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveApproverByType(array $config, ExecutionContext $context): array
    {
        $type = $config['type'] ?? 'user';
        $value = $config['value'] ?? '';

        return match ($type) {
            'user' => is_array($value) ? $value : [$value],
            'role' => $context->getVariable("role_users_{$value}") ?? [],
            'department' => $context->getVariable("dept_users_{$value}") ?? [],
            default => [],
        };
    }

    /**
     * Get completion value based on strategy | 根据策略获取完成值
     *
     * @param string $strategy
     * @return int|float
     */
    protected function getCompletionValue(string $strategy): int|float
    {
        return match ($strategy) {
            self::STRATEGY_ALL => 100,
            self::STRATEGY_MAJORITY => 50,
            self::STRATEGY_PERCENTAGE => $this->getConfigValue('completion_value', 100),
            self::STRATEGY_COUNT => $this->getConfigValue('completion_value', 1),
            default => 100,
        };
    }

    /**
     * Summarize votes | 汇总投票
     *
     * @param array $taskData
     * @return array
     */
    protected function summarizeVotes(array $taskData): array
    {
        $summary = [
            'total' => count($taskData['approvers']),
            'voted' => 0,
            'approve' => 0,
            'reject' => 0,
            'abstain' => 0,
            'pending' => 0,
        ];

        foreach ($taskData['votes'] as $vote) {
            if ($vote['vote'] === null) {
                $summary['pending']++;
            } else {
                $summary['voted']++;
                $summary[$vote['vote']]++;
            }
        }

        return $summary;
    }

    /**
     * Calculate progress percentage | 计算进度百分比
     *
     * @param array $taskData
     * @return float
     */
    protected function calculateProgress(array $taskData): float
    {
        $total = count($taskData['approvers']);
        if ($total === 0) {
            return 100.0;
        }

        $voted = 0;
        foreach ($taskData['votes'] as $vote) {
            if ($vote['vote'] !== null) {
                $voted++;
            }
        }

        return round(($voted / $total) * 100, 2);
    }

    /**
     * Check if countersign is completed | 检查会签是否完成
     *
     * @param array $taskData
     * @return bool
     */
    protected function isCountersignCompleted(array $taskData): bool
    {
        if (in_array($taskData['status'], [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            return true;
        }

        $summary = $this->summarizeVotes($taskData);
        $total = $summary['total'];
        $strategy = $taskData['completion_strategy'];
        $value = $taskData['completion_value'];

        // Check for early rejection
        if ($this->shouldRejectEarly($taskData, $summary)) {
            return true;
        }

        // Check for early approval
        if ($this->shouldApproveEarly($taskData, $summary)) {
            return true;
        }

        // All voted
        return $summary['pending'] === 0;
    }

    /**
     * Check if should reject early | 检查是否应提前拒绝
     *
     * @param array $taskData
     * @param array $summary
     * @return bool
     */
    protected function shouldRejectEarly(array $taskData, array $summary): bool
    {
        $strategy = $taskData['completion_strategy'];
        $total = $summary['total'];
        $rejectCount = $summary['reject'];
        $pendingCount = $summary['pending'];

        // If rejection makes approval impossible, reject early
        return match ($strategy) {
            self::STRATEGY_ALL => $rejectCount > 0,
            self::STRATEGY_MAJORITY => $rejectCount > ($total / 2),
            self::STRATEGY_PERCENTAGE => (
                ($rejectCount / $total * 100) > (100 - $taskData['completion_value'])
            ),
            self::STRATEGY_COUNT => (
                $total - $rejectCount < $taskData['completion_value']
            ),
            default => false,
        };
    }

    /**
     * Check if should approve early | 检查是否应提前通过
     *
     * @param array $taskData
     * @param array $summary
     * @return bool
     */
    protected function shouldApproveEarly(array $taskData, array $summary): bool
    {
        $strategy = $taskData['completion_strategy'];
        $total = $summary['total'];
        $approveCount = $summary['approve'];
        $value = $taskData['completion_value'];

        return match ($strategy) {
            self::STRATEGY_ALL => false, // Must wait for all
            self::STRATEGY_MAJORITY => $approveCount > ($total / 2),
            self::STRATEGY_PERCENTAGE => ($approveCount / $total * 100) >= $value,
            self::STRATEGY_COUNT => $approveCount >= $value,
            default => false,
        };
    }

    /**
     * Handle completed countersign | 处理已完成的会签
     *
     * @param array $taskData
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function handleCompletedCountersign(array $taskData, ExecutionContext $context): NodeResult
    {
        $summary = $this->summarizeVotes($taskData);
        $approved = $this->isApproved($taskData, $summary);

        // Update task status
        $taskKey = "countersign_task_{$this->id}";
        $taskData['status'] = $approved ? self::STATUS_APPROVED : self::STATUS_REJECTED;
        $taskData['completed_at'] = (new \DateTimeImmutable())->format('c');
        $context->setVariable($taskKey, $taskData);

        // Store result in context
        $context->setVariable("countersign_result_{$this->id}", [
            'status' => $taskData['status'],
            'summary' => $summary,
            'votes' => $taskData['votes'],
            'completed_at' => $taskData['completed_at'],
        ]);

        if ($approved) {
            return NodeResult::success([
                'task_id' => $taskData['task_id'],
                'status' => 'approved',
                'summary' => $summary,
                'message' => '会签通过',
            ], $this->getNextNodesForOutcome('approved'));
        }

        $rejectNodes = $this->getNextNodesForOutcome('rejected');
        if (empty($rejectNodes)) {
            return NodeResult::failed('会签被拒绝', 400);
        }

        return NodeResult::success([
            'task_id' => $taskData['task_id'],
            'status' => 'rejected',
            'summary' => $summary,
            'message' => '会签被拒绝',
        ], $rejectNodes);
    }

    /**
     * Determine if countersign is approved | 判断会签是否通过
     *
     * @param array $taskData
     * @param array $summary
     * @return bool
     */
    protected function isApproved(array $taskData, array $summary): bool
    {
        $strategy = $taskData['completion_strategy'];
        $value = $taskData['completion_value'];
        $total = $summary['total'];
        $approveCount = $summary['approve'];

        return match ($strategy) {
            self::STRATEGY_ALL => $approveCount === $total,
            self::STRATEGY_MAJORITY => $approveCount > ($total / 2),
            self::STRATEGY_PERCENTAGE => ($approveCount / $total * 100) >= $value,
            self::STRATEGY_COUNT => $approveCount >= $value,
            default => $approveCount === $total,
        };
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
        $timeoutAction = $this->getConfigValue('timeout_action', 'reject');

        $taskKey = "countersign_task_{$this->id}";
        $taskData['status'] = self::STATUS_TIMEOUT;
        $taskData['timeout_at'] = (new \DateTimeImmutable())->format('c');

        // Decide based on current votes if action is 'decide'
        if ($timeoutAction === 'decide') {
            $summary = $this->summarizeVotes($taskData);
            $approved = $this->isApproved($taskData, $summary);
            $taskData['status'] = $approved ? self::STATUS_APPROVED : self::STATUS_REJECTED;
        }

        $context->setVariable($taskKey, $taskData);

        return match ($timeoutAction) {
            'approve' => NodeResult::success([
                'task_id' => $taskData['task_id'],
                'status' => 'auto_approved',
                'message' => '会签超时自动通过',
            ], $this->getNextNodesForOutcome('approved')),

            'decide' => $this->handleCompletedCountersign($taskData, $context),

            default => NodeResult::success([
                'task_id' => $taskData['task_id'],
                'status' => 'auto_rejected',
                'message' => '会签超时自动拒绝',
            ], $this->getNextNodesForOutcome('rejected')),
        };
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

        return $this->getDefaultNextNodes();
    }

    /**
     * Process vote action | 处理投票动作
     *
     * @param ExecutionContext $context
     * @param string $approverId
     * @param string $vote 'approve', 'reject', or 'abstain'
     * @param string|null $comment
     * @param array $formData
     * @return NodeResult
     */
    public function processVote(
        ExecutionContext $context,
        string $approverId,
        string $vote,
        ?string $comment = null,
        array $formData = []
    ): NodeResult {
        $taskKey = "countersign_task_{$this->id}";
        $taskData = $context->getVariable($taskKey);

        if ($taskData === null) {
            return NodeResult::failed('任务不存在', 404);
        }

        if ($this->isCountersignCompleted($taskData)) {
            return NodeResult::failed('会签已完成', 400);
        }

        if (!isset($taskData['votes'][$approverId])) {
            return NodeResult::failed('无投票权限', 403);
        }

        if ($taskData['votes'][$approverId]['vote'] !== null) {
            return NodeResult::failed('已投票，不能重复投票', 400);
        }

        // Validate abstain
        if ($vote === self::VOTE_ABSTAIN && !$taskData['allow_abstain']) {
            return NodeResult::failed('不允许弃权', 400);
        }

        // Record vote
        $taskData['votes'][$approverId] = [
            'vote' => $vote,
            'comment' => $comment,
            'form_data' => $formData,
            'voted_at' => (new \DateTimeImmutable())->format('c'),
        ];

        $context->setVariable($taskKey, $taskData);

        // Check if completed after this vote
        if ($this->isCountersignCompleted($taskData)) {
            return $this->handleCompletedCountersign($taskData, $context);
        }

        return NodeResult::waiting([
            'task_id' => $taskData['task_id'],
            'status' => 'pending',
            'summary' => $this->summarizeVotes($taskData),
            'progress' => $this->calculateProgress($taskData),
            'message' => '投票已记录，等待其他人投票',
        ], '等待其他投票');
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
                    'description' => '审批人列表',
                ],
                'completion_strategy' => [
                    'type' => 'string',
                    'enum' => [
                        self::STRATEGY_ALL,
                        self::STRATEGY_MAJORITY,
                        self::STRATEGY_PERCENTAGE,
                        self::STRATEGY_COUNT,
                    ],
                    'default' => self::STRATEGY_ALL,
                    'description' => '完成策略',
                ],
                'completion_value' => [
                    'type' => 'number',
                    'description' => '完成值（百分比或数量）',
                ],
                'allow_abstain' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => '是否允许弃权',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => '超时时间（秒）',
                ],
                'timeout_action' => [
                    'type' => 'string',
                    'enum' => ['approve', 'reject', 'decide'],
                    'default' => 'reject',
                    'description' => '超时动作',
                ],
                'parallel' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => '是否并行审批',
                ],
                'outcome_routes' => [
                    'type' => 'object',
                    'properties' => [
                        'approved' => ['type' => 'array'],
                        'rejected' => ['type' => 'array'],
                    ],
                    'description' => '结果路由',
                ],
            ],
            'required' => ['approvers'],
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

        // Must have approvers
        if (empty($configToValidate['approvers'])) {
            return false;
        }

        // Validate completion strategy
        if (isset($configToValidate['completion_strategy'])) {
            $validStrategies = [
                self::STRATEGY_ALL,
                self::STRATEGY_MAJORITY,
                self::STRATEGY_PERCENTAGE,
                self::STRATEGY_COUNT,
            ];
            if (!in_array($configToValidate['completion_strategy'], $validStrategies, true)) {
                return false;
            }
        }

        // Validate completion value for percentage strategy
        if (
            ($configToValidate['completion_strategy'] ?? '') === self::STRATEGY_PERCENTAGE &&
            isset($configToValidate['completion_value'])
        ) {
            $value = $configToValidate['completion_value'];
            if ($value < 0 || $value > 100) {
                return false;
            }
        }

        return parent::validateConfig($configToValidate);
    }
}
