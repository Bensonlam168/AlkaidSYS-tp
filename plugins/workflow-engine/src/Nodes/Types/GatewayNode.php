<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * 网关节点
 *
 * 控制工作流的分支和汇聚，支持多种网关类型：
 * - 排他网关（Exclusive）：根据条件选择一条路径
 * - 并行网关（Parallel）：并行分支或等待所有分支汇聚
 * - 包容网关（Inclusive）：选择多条满足条件的路径
 * - 事件网关（Event-based）：根据事件选择路径
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
class GatewayNode extends AbstractNode
{
    /**
     * 网关方向
     */
    public const DIRECTION_DIVERGING = 'diverging';   // 分支
    public const DIRECTION_CONVERGING = 'converging'; // 汇聚

    /**
     * 条件评估器
     *
     * @var callable|null
     */
    protected $conditionEvaluator = null;

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return NodeInterface::TYPE_GATEWAY;
    }

    /**
     * {@inheritDoc}
     */
    public function getSubType(): ?string
    {
        return $this->config['sub_type'] ?? $this->config['gateway_type'] ?? NodeInterface::GATEWAY_EXCLUSIVE;
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return $this->config['description'] ?? '网关节点';
    }

    /**
     * 获取网关方向
     *
     * @return string
     */
    public function getDirection(): string
    {
        return $this->config['direction'] ?? self::DIRECTION_DIVERGING;
    }

    /**
     * {@inheritDoc}
     */
    protected function doExecute(ExecutionContext $context): NodeResult
    {
        $gatewayType = $this->getSubType();
        $direction = $this->getDirection();

        // 汇聚网关需要等待所有分支
        if ($direction === self::DIRECTION_CONVERGING) {
            return $this->handleConverging($context, $gatewayType);
        }

        // 分支网关根据类型选择路径
        return match ($gatewayType) {
            NodeInterface::GATEWAY_EXCLUSIVE => $this->executeExclusiveGateway($context),
            NodeInterface::GATEWAY_PARALLEL => $this->executeParallelGateway($context),
            NodeInterface::GATEWAY_INCLUSIVE => $this->executeInclusiveGateway($context),
            NodeInterface::GATEWAY_EVENT => $this->executeEventGateway($context),
            default => $this->executeExclusiveGateway($context),
        };
    }

    /**
     * 处理汇聚网关
     *
     * @param ExecutionContext $context 执行上下文
     * @param string $gatewayType 网关类型
     * @return NodeResult
     */
    protected function handleConverging(ExecutionContext $context, string $gatewayType): NodeResult
    {
        $inputCount = count($this->inputs);
        $arrivedCount = $this->getArrivedBranchCount($context);

        // 记录当前分支已到达
        $this->markBranchArrived($context);
        $arrivedCount++;

        return match ($gatewayType) {
            NodeInterface::GATEWAY_PARALLEL => $this->handleParallelConverging($context, $inputCount, $arrivedCount),
            NodeInterface::GATEWAY_INCLUSIVE => $this->handleInclusiveConverging($context, $inputCount, $arrivedCount),
            default => $this->handleDefaultConverging($context, $inputCount, $arrivedCount),
        };
    }

    /**
     * 处理并行汇聚
     *
     * @param ExecutionContext $context 执行上下文
     * @param int $inputCount 输入数量
     * @param int $arrivedCount 已到达数量
     * @return NodeResult
     */
    protected function handleParallelConverging(
        ExecutionContext $context,
        int $inputCount,
        int $arrivedCount
    ): NodeResult {
        // 并行网关需要等待所有分支
        if ($arrivedCount < $inputCount) {
            return NodeResult::waiting(
                [
                    'arrived_count' => $arrivedCount,
                    'total_count' => $inputCount,
                ],
                "等待所有分支到达 ({$arrivedCount}/{$inputCount})"
            )->setMeta('merge_pending', true);
        }

        // 所有分支已到达，清理状态并继续
        $this->clearArrivedBranches($context);

        return NodeResult::success(
            ['merged_branches' => $arrivedCount],
            $this->getDefaultNextNodes()
        )->setMeta('merge_complete', true);
    }

    /**
     * 处理包容汇聚
     *
     * @param ExecutionContext $context 执行上下文
     * @param int $inputCount 输入数量
     * @param int $arrivedCount 已到达数量
     * @return NodeResult
     */
    protected function handleInclusiveConverging(
        ExecutionContext $context,
        int $inputCount,
        int $arrivedCount
    ): NodeResult {
        // 包容网关需要等待所有活动分支
        $activeBranches = $this->getActiveBranchCount($context);

        if ($arrivedCount < $activeBranches) {
            return NodeResult::waiting(
                [
                    'arrived_count' => $arrivedCount,
                    'active_branches' => $activeBranches,
                ],
                "等待活动分支到达 ({$arrivedCount}/{$activeBranches})"
            )->setMeta('merge_pending', true);
        }

        $this->clearArrivedBranches($context);

        return NodeResult::success(
            ['merged_branches' => $arrivedCount],
            $this->getDefaultNextNodes()
        )->setMeta('merge_complete', true);
    }

    /**
     * 处理默认汇聚（排他网关）
     *
     * @param ExecutionContext $context 执行上下文
     * @param int $inputCount 输入数量
     * @param int $arrivedCount 已到达数量
     * @return NodeResult
     */
    protected function handleDefaultConverging(
        ExecutionContext $context,
        int $inputCount,
        int $arrivedCount
    ): NodeResult {
        // 排他网关只需要一个分支到达即可继续
        $this->clearArrivedBranches($context);

        return NodeResult::success(
            ['merged_branches' => $arrivedCount],
            $this->getDefaultNextNodes()
        )->setMeta('merge_complete', true);
    }

    /**
     * 执行排他网关（决策网关）
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    protected function executeExclusiveGateway(ExecutionContext $context): NodeResult
    {
        $selectedNode = null;
        $defaultNode = null;

        // 遍历所有输出连接，找到满足条件的
        foreach ($this->outputs as $output) {
            $condition = $this->getOutputCondition($output);
            $targetNode = $this->getOutputTarget($output);

            // 检查是否是默认路径
            if ($this->isDefaultOutput($output)) {
                $defaultNode = $targetNode;
                continue;
            }

            // 评估条件
            if ($condition && $this->evaluateOutputCondition($context, $condition)) {
                $selectedNode = $targetNode;
                break;
            }
        }

        // 使用选中的节点或默认节点
        $nextNode = $selectedNode ?? $defaultNode;

        if (!$nextNode) {
            return NodeResult::failed('排他网关无法找到满足条件的输出路径');
        }

        return NodeResult::success(
            [
                'gateway_type' => 'exclusive',
                'selected_path' => $nextNode,
            ],
            [$nextNode]
        );
    }

    /**
     * 执行并行网关
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    protected function executeParallelGateway(ExecutionContext $context): NodeResult
    {
        // 并行网关激活所有输出路径
        $nextNodes = $this->getDefaultNextNodes();

        if (empty($nextNodes)) {
            return NodeResult::failed('并行网关没有配置输出路径');
        }

        // 记录分支信息到上下文
        $branchId = $this->generateBranchId();
        $this->recordBranchInfo($context, $branchId, $nextNodes);

        return NodeResult::success(
            [
                'gateway_type' => 'parallel',
                'branch_id' => $branchId,
                'branch_count' => count($nextNodes),
                'parallel_paths' => $nextNodes,
            ],
            $nextNodes
        )->setMeta('parallel_execution', true)
         ->setMeta('branch_id', $branchId);
    }

    /**
     * 执行包容网关
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    protected function executeInclusiveGateway(ExecutionContext $context): NodeResult
    {
        $selectedNodes = [];
        $defaultNode = null;

        // 遍历所有输出连接，找到所有满足条件的
        foreach ($this->outputs as $output) {
            $condition = $this->getOutputCondition($output);
            $targetNode = $this->getOutputTarget($output);

            if ($this->isDefaultOutput($output)) {
                $defaultNode = $targetNode;
                continue;
            }

            if ($condition && $this->evaluateOutputCondition($context, $condition)) {
                $selectedNodes[] = $targetNode;
            }
        }

        // 如果没有满足条件的路径，使用默认路径
        if (empty($selectedNodes) && $defaultNode) {
            $selectedNodes[] = $defaultNode;
        }

        if (empty($selectedNodes)) {
            return NodeResult::failed('包容网关无法找到满足条件的输出路径');
        }

        // 记录分支信息
        if (count($selectedNodes) > 1) {
            $branchId = $this->generateBranchId();
            $this->recordBranchInfo($context, $branchId, $selectedNodes);
        }

        return NodeResult::success(
            [
                'gateway_type' => 'inclusive',
                'selected_paths' => $selectedNodes,
                'path_count' => count($selectedNodes),
            ],
            $selectedNodes
        )->setMeta('parallel_execution', count($selectedNodes) > 1);
    }

    /**
     * 执行事件网关
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    protected function executeEventGateway(ExecutionContext $context): NodeResult
    {
        // 事件网关等待第一个发生的事件
        $expectedEvents = $this->getExpectedEvents();

        if (empty($expectedEvents)) {
            return NodeResult::failed('事件网关未配置预期事件');
        }

        return NodeResult::waiting(
            [
                'gateway_type' => 'event_based',
                'expected_events' => $expectedEvents,
            ],
            '等待事件发生'
        )->setMeta('event_gateway', true);
    }

    /**
     * 获取输出条件
     *
     * @param mixed $output 输出配置
     * @return string|null
     */
    protected function getOutputCondition(mixed $output): ?string
    {
        if (is_array($output)) {
            return $output['condition'] ?? $output['expr'] ?? null;
        }
        return null;
    }

    /**
     * 获取输出目标节点
     *
     * @param mixed $output 输出配置
     * @return string
     */
    protected function getOutputTarget(mixed $output): string
    {
        if (is_array($output)) {
            return $output['target'] ?? $output['to'] ?? '';
        }
        return (string) $output;
    }

    /**
     * 检查是否是默认输出
     *
     * @param mixed $output 输出配置
     * @return bool
     */
    protected function isDefaultOutput(mixed $output): bool
    {
        if (is_array($output)) {
            return !empty($output['default']) || !empty($output['is_default']);
        }
        return false;
    }

    /**
     * 评估输出条件
     *
     * @param ExecutionContext $context 执行上下文
     * @param string $condition 条件表达式
     * @return bool
     */
    protected function evaluateOutputCondition(ExecutionContext $context, string $condition): bool
    {
        // 如果有自定义条件评估器，使用它
        if ($this->conditionEvaluator !== null) {
            return (bool) ($this->conditionEvaluator)($context, $condition);
        }

        // 使用父类的条件评估方法
        return $this->evaluateCondition($context, $condition);
    }

    /**
     * 获取已到达的分支数量
     *
     * @param ExecutionContext $context 执行上下文
     * @return int
     */
    protected function getArrivedBranchCount(ExecutionContext $context): int
    {
        $arrivedKey = "_gateway_{$this->id}_arrived";
        $arrived = $context->getVariable($arrivedKey, []);
        return count($arrived);
    }

    /**
     * 获取活动分支数量（用于包容网关）
     *
     * @param ExecutionContext $context 执行上下文
     * @return int
     */
    protected function getActiveBranchCount(ExecutionContext $context): int
    {
        $activeKey = "_gateway_{$this->id}_active_branches";
        return (int) $context->getVariable($activeKey, count($this->inputs));
    }

    /**
     * 标记分支已到达
     *
     * @param ExecutionContext $context 执行上下文
     */
    protected function markBranchArrived(ExecutionContext $context): void
    {
        $arrivedKey = "_gateway_{$this->id}_arrived";
        $arrived = $context->getVariable($arrivedKey, []);
        $branchId = $context->getVariable('_current_branch', 'main');
        $arrived[$branchId] = true;
        $context->setVariable($arrivedKey, $arrived);
    }

    /**
     * 清理已到达分支记录
     *
     * @param ExecutionContext $context 执行上下文
     */
    protected function clearArrivedBranches(ExecutionContext $context): void
    {
        $arrivedKey = "_gateway_{$this->id}_arrived";
        $context->setVariable($arrivedKey, []);
    }

    /**
     * 记录分支信息
     *
     * @param ExecutionContext $context 执行上下文
     * @param string $branchId 分支ID
     * @param array $branches 分支节点列表
     */
    protected function recordBranchInfo(ExecutionContext $context, string $branchId, array $branches): void
    {
        $branchKey = "_branches_{$branchId}";
        $context->setVariable($branchKey, [
            'gateway_id' => $this->id,
            'branches' => $branches,
            'total' => count($branches),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 生成分支ID
     *
     * @return string
     */
    protected function generateBranchId(): string
    {
        return sprintf(
            'branch_%s_%s',
            substr(md5(uniqid((string) mt_rand(), true)), 0, 8),
            time()
        );
    }

    /**
     * 获取预期事件列表
     *
     * @return array
     */
    protected function getExpectedEvents(): array
    {
        $events = [];

        foreach ($this->outputs as $output) {
            if (is_array($output) && isset($output['event'])) {
                $events[] = [
                    'event' => $output['event'],
                    'target' => $this->getOutputTarget($output),
                ];
            }
        }

        return $events;
    }

    /**
     * 设置条件评估器
     *
     * @param callable $evaluator 评估器
     * @return static
     */
    public function setConditionEvaluator(callable $evaluator): static
    {
        $this->conditionEvaluator = $evaluator;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getConfigSchema(): array
    {
        $baseSchema = parent::getConfigSchema();

        return array_merge_recursive($baseSchema, [
            'properties' => [
                'gateway_type' => [
                    'type' => 'string',
                    'title' => '网关类型',
                    'enum' => [
                        NodeInterface::GATEWAY_EXCLUSIVE,
                        NodeInterface::GATEWAY_PARALLEL,
                        NodeInterface::GATEWAY_INCLUSIVE,
                        NodeInterface::GATEWAY_EVENT,
                    ],
                    'default' => NodeInterface::GATEWAY_EXCLUSIVE,
                ],
                'direction' => [
                    'type' => 'string',
                    'title' => '网关方向',
                    'enum' => [
                        self::DIRECTION_DIVERGING,
                        self::DIRECTION_CONVERGING,
                    ],
                    'default' => self::DIRECTION_DIVERGING,
                ],
                'default_output' => [
                    'type' => 'string',
                    'title' => '默认输出',
                    'description' => '当没有条件满足时使用的默认输出路径',
                ],
                'wait_timeout' => [
                    'type' => 'integer',
                    'title' => '等待超时',
                    'description' => '汇聚网关等待所有分支的超时时间（秒）',
                    'minimum' => 0,
                    'default' => 0,
                ],
            ],
        ]);
    }
}
