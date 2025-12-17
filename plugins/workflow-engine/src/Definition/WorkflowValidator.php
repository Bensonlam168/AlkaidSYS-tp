<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Definition;

use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowConnectionInterface;
use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowDefinitionInterface;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;

/**
 * 工作流验证器
 *
 * 提供全面的工作流定义验证功能，包括结构验证、逻辑验证、循环检测等。
 *
 * @package Alkaid\Workflow\Engine\Definition
 */
class WorkflowValidator
{
    /**
     * 错误级别：错误
     */
    public const LEVEL_ERROR = 'error';

    /**
     * 错误级别：警告
     */
    public const LEVEL_WARNING = 'warning';

    /**
     * 错误级别：信息
     */
    public const LEVEL_INFO = 'info';

    /**
     * 验证规则
     *
     * @var array<string, callable>
     */
    protected array $rules = [];

    /**
     * 是否启用循环检测
     *
     * @var bool
     */
    protected bool $detectCycles = true;

    /**
     * 是否启用孤立节点检测
     *
     * @var bool
     */
    protected bool $detectOrphanNodes = true;

    /**
     * 是否启用条件完整性检测
     *
     * @var bool
     */
    protected bool $validateConditions = true;

    /**
     * 构造函数
     *
     * @param array $config 配置选项
     */
    public function __construct(array $config = [])
    {
        $this->detectCycles = $config['detect_cycles'] ?? true;
        $this->detectOrphanNodes = $config['detect_orphan_nodes'] ?? true;
        $this->validateConditions = $config['validate_conditions'] ?? true;

        // 注册默认验证规则
        $this->registerDefaultRules();
    }

    /**
     * 注册默认验证规则
     *
     * @return void
     */
    protected function registerDefaultRules(): void
    {
        // 开始节点验证
        $this->addRule('start_node', function (WorkflowDefinitionInterface $definition): array {
            $errors = [];
            $startNodes = [];

            foreach ($definition->getNodes() as $node) {
                if ($node->getType() === NodeInterface::TYPE_START) {
                    $startNodes[] = $node;
                }
            }

            if (empty($startNodes)) {
                $errors[] = $this->createError(
                    'MISSING_START_NODE',
                    '工作流必须有一个开始节点',
                    self::LEVEL_ERROR
                );
            } elseif (count($startNodes) > 1) {
                $errors[] = $this->createError(
                    'MULTIPLE_START_NODES',
                    '工作流只能有一个开始节点，当前有 ' . count($startNodes) . ' 个',
                    self::LEVEL_ERROR,
                    ['count' => count($startNodes)]
                );
            }

            return $errors;
        });

        // 结束节点验证
        $this->addRule('end_node', function (WorkflowDefinitionInterface $definition): array {
            $errors = [];
            $endNodes = $definition->getEndNodes();

            if (empty($endNodes)) {
                $errors[] = $this->createError(
                    'MISSING_END_NODE',
                    '工作流必须至少有一个结束节点',
                    self::LEVEL_ERROR
                );
            }

            return $errors;
        });

        // 节点连接验证
        $this->addRule('node_connections', function (WorkflowDefinitionInterface $definition): array {
            $errors = [];

            foreach ($definition->getNodes() as $node) {
                $nodeId = $node->getId();
                $nodeType = $node->getType();
                $nodeName = $node->getName();

                // 检查输入连接（开始节点除外）
                if ($nodeType !== NodeInterface::TYPE_START) {
                    $inputConnections = $definition->getNodeInputConnections($nodeId);
                    if (empty($inputConnections)) {
                        $errors[] = $this->createError(
                            'NO_INPUT_CONNECTION',
                            "节点 '{$nodeName}' ({$nodeId}) 没有输入连接",
                            self::LEVEL_ERROR,
                            ['node_id' => $nodeId, 'node_name' => $nodeName]
                        );
                    }
                }

                // 检查输出连接（结束节点除外）
                if ($nodeType !== NodeInterface::TYPE_END) {
                    $outputConnections = $definition->getNodeOutputConnections($nodeId);
                    if (empty($outputConnections)) {
                        $errors[] = $this->createError(
                            'NO_OUTPUT_CONNECTION',
                            "节点 '{$nodeName}' ({$nodeId}) 没有输出连接",
                            self::LEVEL_ERROR,
                            ['node_id' => $nodeId, 'node_name' => $nodeName]
                        );
                    }
                }
            }

            return $errors;
        });

        // 连接有效性验证
        $this->addRule('connection_validity', function (WorkflowDefinitionInterface $definition): array {
            $errors = [];

            foreach ($definition->getConnections() as $connection) {
                $sourceNodeId = $connection->getSourceNodeId();
                $targetNodeId = $connection->getTargetNodeId();
                $connectionId = $connection->getId();

                if (!$definition->hasNode($sourceNodeId)) {
                    $errors[] = $this->createError(
                        'INVALID_SOURCE_NODE',
                        "连接 '{$connectionId}' 的源节点 '{$sourceNodeId}' 不存在",
                        self::LEVEL_ERROR,
                        ['connection_id' => $connectionId, 'source_node_id' => $sourceNodeId]
                    );
                }

                if (!$definition->hasNode($targetNodeId)) {
                    $errors[] = $this->createError(
                        'INVALID_TARGET_NODE',
                        "连接 '{$connectionId}' 的目标节点 '{$targetNodeId}' 不存在",
                        self::LEVEL_ERROR,
                        ['connection_id' => $connectionId, 'target_node_id' => $targetNodeId]
                    );
                }

                // 检查自环
                if ($sourceNodeId === $targetNodeId) {
                    $errors[] = $this->createError(
                        'SELF_LOOP',
                        "连接 '{$connectionId}' 创建了自环（源和目标是同一节点）",
                        self::LEVEL_WARNING,
                        ['connection_id' => $connectionId, 'node_id' => $sourceNodeId]
                    );
                }
            }

            return $errors;
        });

        // 可达性验证
        $this->addRule('reachability', function (WorkflowDefinitionInterface $definition): array {
            $errors = [];
            $startNode = $definition->getStartNode();
            $endNodes = $definition->getEndNodes();

            if ($startNode === null || empty($endNodes)) {
                return $errors; // 如果没有开始或结束节点，跳过此验证
            }

            $reachable = $this->findReachableNodes($definition, $startNode->getId());

            // 检查所有结束节点是否可达
            foreach ($endNodes as $endNode) {
                if (!in_array($endNode->getId(), $reachable, true)) {
                    $errors[] = $this->createError(
                        'UNREACHABLE_END_NODE',
                        "结束节点 '{$endNode->getName()}' ({$endNode->getId()}) 无法从开始节点到达",
                        self::LEVEL_ERROR,
                        ['node_id' => $endNode->getId(), 'node_name' => $endNode->getName()]
                    );
                }
            }

            return $errors;
        });

        // 孤立节点检测
        $this->addRule('orphan_nodes', function (WorkflowDefinitionInterface $definition): array {
            if (!$this->detectOrphanNodes) {
                return [];
            }

            $errors = [];
            $startNode = $definition->getStartNode();

            if ($startNode === null) {
                return $errors;
            }

            $reachable = $this->findReachableNodes($definition, $startNode->getId());

            foreach ($definition->getNodes() as $node) {
                if (!in_array($node->getId(), $reachable, true)) {
                    $errors[] = $this->createError(
                        'ORPHAN_NODE',
                        "节点 '{$node->getName()}' ({$node->getId()}) 是孤立的，无法从开始节点到达",
                        self::LEVEL_WARNING,
                        ['node_id' => $node->getId(), 'node_name' => $node->getName()]
                    );
                }
            }

            return $errors;
        });

        // 循环检测
        $this->addRule('cycle_detection', function (WorkflowDefinitionInterface $definition): array {
            if (!$this->detectCycles) {
                return [];
            }

            $errors = [];
            $cycles = $this->detectCyclesInGraph($definition);

            foreach ($cycles as $cycle) {
                $cycleStr = implode(' -> ', $cycle) . ' -> ' . $cycle[0];
                $errors[] = $this->createError(
                    'CYCLE_DETECTED',
                    "检测到循环路径: {$cycleStr}",
                    self::LEVEL_INFO,
                    ['cycle' => $cycle]
                );
            }

            return $errors;
        });

        // 网关验证
        $this->addRule('gateway_validation', function (WorkflowDefinitionInterface $definition): array {
            $errors = [];

            foreach ($definition->getNodes() as $node) {
                if ($node->getType() !== NodeInterface::TYPE_GATEWAY) {
                    continue;
                }

                $outputConnections = $definition->getNodeOutputConnections($node->getId());

                // 排他网关需要至少有一个默认路径
                if ($node->getSubType() === 'exclusive') {
                    $hasDefault = false;
                    $hasConditional = false;

                    foreach ($outputConnections as $conn) {
                        if ($conn->isDefault()) {
                            $hasDefault = true;
                        }
                        if ($conn->getCondition() !== null) {
                            $hasConditional = true;
                        }
                    }

                    if ($hasConditional && !$hasDefault && count($outputConnections) > 1) {
                        $errors[] = $this->createError(
                            'GATEWAY_NO_DEFAULT',
                            "排他网关 '{$node->getName()}' 有条件分支但没有默认路径",
                            self::LEVEL_WARNING,
                            ['node_id' => $node->getId(), 'node_name' => $node->getName()]
                        );
                    }
                }

                // 并行网关需要有配对的汇聚网关
                if ($node->getSubType() === 'parallel') {
                    $forkMode = $node->getConfig('mode') ?? $node->getConfig('fork_mode');
                    if ($forkMode === 'fork' || $forkMode === true) {
                        // 这是一个分支网关，检查是否有对应的汇聚
                        $pairId = $node->getConfig('pair_id');
                        if ($pairId !== null && !$definition->hasNode($pairId)) {
                            $errors[] = $this->createError(
                                'GATEWAY_MISSING_PAIR',
                                "并行网关 '{$node->getName()}' 的配对汇聚网关 '{$pairId}' 不存在",
                                self::LEVEL_WARNING,
                                ['node_id' => $node->getId(), 'pair_id' => $pairId]
                            );
                        }
                    }
                }
            }

            return $errors;
        });

        // 条件表达式验证
        $this->addRule('condition_validation', function (WorkflowDefinitionInterface $definition): array {
            if (!$this->validateConditions) {
                return [];
            }

            $errors = [];

            foreach ($definition->getConnections() as $connection) {
                $condition = $connection->getCondition();
                if ($condition === null) {
                    continue;
                }

                // 基本语法检查
                if (!$this->isValidConditionSyntax($condition)) {
                    $errors[] = $this->createError(
                        'INVALID_CONDITION_SYNTAX',
                        "连接 '{$connection->getId()}' 的条件表达式语法无效: {$condition}",
                        self::LEVEL_WARNING,
                        ['connection_id' => $connection->getId(), 'condition' => $condition]
                    );
                }
            }

            return $errors;
        });
    }

    /**
     * 添加验证规则
     *
     * @param string $name 规则名称
     * @param callable $rule 规则函数
     * @return static
     */
    public function addRule(string $name, callable $rule): static
    {
        $this->rules[$name] = $rule;
        return $this;
    }

    /**
     * 移除验证规则
     *
     * @param string $name 规则名称
     * @return static
     */
    public function removeRule(string $name): static
    {
        unset($this->rules[$name]);
        return $this;
    }

    /**
     * 获取所有规则名称
     *
     * @return array<string>
     */
    public function getRuleNames(): array
    {
        return array_keys($this->rules);
    }

    /**
     * 验证工作流定义
     *
     * @param WorkflowDefinitionInterface $definition 工作流定义
     * @param array|null $rules 要执行的规则列表，null表示执行所有规则
     * @return ValidationResult
     */
    public function validate(WorkflowDefinitionInterface $definition, ?array $rules = null): ValidationResult
    {
        $allErrors = [];
        $rulesToRun = $rules ?? array_keys($this->rules);

        foreach ($rulesToRun as $ruleName) {
            if (!isset($this->rules[$ruleName])) {
                continue;
            }

            $ruleErrors = call_user_func($this->rules[$ruleName], $definition);
            foreach ($ruleErrors as $error) {
                $error['rule'] = $ruleName;
                $allErrors[] = $error;
            }
        }

        return new ValidationResult($allErrors);
    }

    /**
     * 快速验证（仅检查是否有效）
     *
     * @param WorkflowDefinitionInterface $definition 工作流定义
     * @return bool
     */
    public function isValid(WorkflowDefinitionInterface $definition): bool
    {
        $result = $this->validate($definition);
        return !$result->hasErrors();
    }

    /**
     * 创建错误对象
     *
     * @param string $code 错误代码
     * @param string $message 错误消息
     * @param string $level 错误级别
     * @param array $context 上下文信息
     * @return array
     */
    protected function createError(string $code, string $message, string $level = self::LEVEL_ERROR, array $context = []): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'level' => $level,
            'context' => $context,
        ];
    }

    /**
     * 查找从指定节点可达的所有节点
     *
     * @param WorkflowDefinitionInterface $definition 工作流定义
     * @param string $startNodeId 起始节点ID
     * @return array<string>
     */
    protected function findReachableNodes(WorkflowDefinitionInterface $definition, string $startNodeId): array
    {
        $reachable = [];
        $queue = [$startNodeId];

        while (!empty($queue)) {
            $nodeId = array_shift($queue);
            if (in_array($nodeId, $reachable, true)) {
                continue;
            }
            $reachable[] = $nodeId;

            $nextNodes = $definition->getNextNodes($nodeId);
            foreach ($nextNodes as $nextNode) {
                if (!in_array($nextNode->getId(), $reachable, true)) {
                    $queue[] = $nextNode->getId();
                }
            }
        }

        return $reachable;
    }

    /**
     * 检测图中的循环
     *
     * @param WorkflowDefinitionInterface $definition 工作流定义
     * @return array<array<string>> 检测到的循环列表
     */
    protected function detectCyclesInGraph(WorkflowDefinitionInterface $definition): array
    {
        $cycles = [];
        $visited = [];
        $recursionStack = [];
        $path = [];

        foreach ($definition->getNodes() as $node) {
            if (!isset($visited[$node->getId()])) {
                $this->dfsDetectCycles(
                    $definition,
                    $node->getId(),
                    $visited,
                    $recursionStack,
                    $path,
                    $cycles
                );
            }
        }

        return $cycles;
    }

    /**
     * 深度优先搜索检测循环
     *
     * @param WorkflowDefinitionInterface $definition 工作流定义
     * @param string $nodeId 当前节点ID
     * @param array $visited 已访问节点
     * @param array $recursionStack 递归栈
     * @param array $path 当前路径
     * @param array $cycles 检测到的循环
     * @return void
     */
    protected function dfsDetectCycles(
        WorkflowDefinitionInterface $definition,
        string $nodeId,
        array &$visited,
        array &$recursionStack,
        array &$path,
        array &$cycles
    ): void {
        $visited[$nodeId] = true;
        $recursionStack[$nodeId] = true;
        $path[] = $nodeId;

        $nextNodes = $definition->getNextNodes($nodeId);
        foreach ($nextNodes as $nextNode) {
            $nextId = $nextNode->getId();

            if (!isset($visited[$nextId])) {
                $this->dfsDetectCycles($definition, $nextId, $visited, $recursionStack, $path, $cycles);
            } elseif (isset($recursionStack[$nextId]) && $recursionStack[$nextId]) {
                // 找到循环
                $cycleStart = array_search($nextId, $path, true);
                if ($cycleStart !== false) {
                    $cycle = array_slice($path, $cycleStart);
                    $cycles[] = $cycle;
                }
            }
        }

        array_pop($path);
        $recursionStack[$nodeId] = false;
    }

    /**
     * 验证条件表达式语法
     *
     * @param string $condition 条件表达式
     * @return bool
     */
    protected function isValidConditionSyntax(string $condition): bool
    {
        // 基本检查：不能为空
        if (trim($condition) === '') {
            return false;
        }

        // 检查括号匹配
        $balance = 0;
        for ($i = 0; $i < strlen($condition); $i++) {
            if ($condition[$i] === '(') {
                $balance++;
            } elseif ($condition[$i] === ')') {
                $balance--;
            }
            if ($balance < 0) {
                return false;
            }
        }
        if ($balance !== 0) {
            return false;
        }

        // 检查是否有危险的PHP代码（简单检查）
        $dangerousPatterns = [
            '/\beval\s*\(/i',
            '/\bexec\s*\(/i',
            '/\bsystem\s*\(/i',
            '/\bshell_exec\s*\(/i',
            '/`[^`]+`/',
            '/\bfile_\w+\s*\(/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 设置是否检测循环
     *
     * @param bool $detect 是否检测
     * @return static
     */
    public function setDetectCycles(bool $detect): static
    {
        $this->detectCycles = $detect;
        return $this;
    }

    /**
     * 设置是否检测孤立节点
     *
     * @param bool $detect 是否检测
     * @return static
     */
    public function setDetectOrphanNodes(bool $detect): static
    {
        $this->detectOrphanNodes = $detect;
        return $this;
    }

    /**
     * 设置是否验证条件表达式
     *
     * @param bool $validate 是否验证
     * @return static
     */
    public function setValidateConditions(bool $validate): static
    {
        $this->validateConditions = $validate;
        return $this;
    }
}
