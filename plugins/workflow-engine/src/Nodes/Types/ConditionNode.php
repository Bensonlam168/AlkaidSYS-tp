<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * Condition Node | 条件分支节点
 *
 * A gateway node that evaluates conditions and routes to different branches.
 * Supports exclusive (XOR), parallel (AND), and inclusive (OR) gateway patterns.
 * 评估条件并路由到不同分支的网关节点。
 * 支持排他(XOR)、并行(AND)和包含(OR)网关模式。
 *
 * Configuration options:
 * - gateway_type: string - 'exclusive', 'parallel', 'inclusive'
 * - conditions: array - List of conditions with target nodes
 * - default_branch: string - Default target node when no conditions match
 * - expression_engine: string - Expression evaluation engine
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
class ConditionNode extends AbstractNode
{
    /**
     * Gateway types | 网关类型
     */
    public const GATEWAY_EXCLUSIVE = 'exclusive'; // XOR - only one branch
    public const GATEWAY_PARALLEL = 'parallel';   // AND - all branches
    public const GATEWAY_INCLUSIVE = 'inclusive'; // OR - matching branches

    /**
     * Condition operators | 条件运算符
     */
    public const OP_EQUALS = 'eq';
    public const OP_NOT_EQUALS = 'neq';
    public const OP_GREATER_THAN = 'gt';
    public const OP_GREATER_EQUALS = 'gte';
    public const OP_LESS_THAN = 'lt';
    public const OP_LESS_EQUALS = 'lte';
    public const OP_CONTAINS = 'contains';
    public const OP_NOT_CONTAINS = 'not_contains';
    public const OP_STARTS_WITH = 'starts_with';
    public const OP_ENDS_WITH = 'ends_with';
    public const OP_IN = 'in';
    public const OP_NOT_IN = 'not_in';
    public const OP_IS_NULL = 'is_null';
    public const OP_IS_NOT_NULL = 'is_not_null';
    public const OP_MATCHES = 'matches'; // Regex
    public const OP_BETWEEN = 'between';

    /**
     * Constructor
     *
     * @param string|null $id Node ID
     * @param array $config Node configuration
     */
    public function __construct(?string $id = null, array $config = [])
    {
        $gatewayType = $config['gateway_type'] ?? self::GATEWAY_EXCLUSIVE;
        $config['sub_type'] = $config['sub_type'] ?? $this->mapGatewayType($gatewayType);
        parent::__construct($id, $config);
    }

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
        $gatewayType = $this->config['gateway_type'] ?? self::GATEWAY_EXCLUSIVE;
        return $this->mapGatewayType($gatewayType);
    }

    /**
     * Map gateway type to interface constant | 映射网关类型到接口常量
     *
     * @param string $type
     * @return string
     */
    protected function mapGatewayType(string $type): string
    {
        return match ($type) {
            self::GATEWAY_EXCLUSIVE => NodeInterface::GATEWAY_EXCLUSIVE,
            self::GATEWAY_PARALLEL => NodeInterface::GATEWAY_PARALLEL,
            self::GATEWAY_INCLUSIVE => NodeInterface::GATEWAY_INCLUSIVE,
            default => NodeInterface::GATEWAY_EXCLUSIVE,
        };
    }

    /**
     * Execute the condition node | 执行条件节点
     *
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function doExecute(ExecutionContext $context): NodeResult
    {
        $gatewayType = $this->getConfigValue('gateway_type', self::GATEWAY_EXCLUSIVE);

        return match ($gatewayType) {
            self::GATEWAY_PARALLEL => $this->executeParallel($context),
            self::GATEWAY_INCLUSIVE => $this->executeInclusive($context),
            default => $this->executeExclusive($context),
        };
    }

    /**
     * Execute exclusive gateway | 执行排他网关
     *
     * Only one branch is taken (first matching condition)
     *
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function executeExclusive(ExecutionContext $context): NodeResult
    {
        $conditions = $this->getConfigValue('conditions', []);
        $evaluationResults = [];

        foreach ($conditions as $index => $condition) {
            $result = $this->evaluateConditionGroup($condition, $context);
            $evaluationResults[] = [
                'index' => $index,
                'name' => $condition['name'] ?? "Condition {$index}",
                'result' => $result,
                'target' => $condition['target'] ?? null,
            ];

            if ($result && !empty($condition['target'])) {
                // First matching condition wins
                return NodeResult::success([
                    'gateway_type' => self::GATEWAY_EXCLUSIVE,
                    'matched_condition' => $condition['name'] ?? "Condition {$index}",
                    'evaluation_results' => $evaluationResults,
                    'selected_branch' => $condition['target'],
                ], $this->normalizeTargets($condition['target']));
            }
        }

        // No condition matched, use default branch
        $defaultBranch = $this->getConfigValue('default_branch');
        if ($defaultBranch) {
            return NodeResult::success([
                'gateway_type' => self::GATEWAY_EXCLUSIVE,
                'matched_condition' => 'default',
                'evaluation_results' => $evaluationResults,
                'selected_branch' => $defaultBranch,
            ], $this->normalizeTargets($defaultBranch));
        }

        // No matching condition and no default
        return NodeResult::failed('条件分支：无匹配条件且无默认分支', 400);
    }

    /**
     * Execute parallel gateway | 执行并行网关
     *
     * All branches are taken regardless of conditions
     *
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function executeParallel(ExecutionContext $context): NodeResult
    {
        $conditions = $this->getConfigValue('conditions', []);
        $allTargets = [];
        $evaluationResults = [];

        foreach ($conditions as $index => $condition) {
            $target = $condition['target'] ?? null;
            if ($target) {
                $targets = $this->normalizeTargets($target);
                $allTargets = array_merge($allTargets, $targets);
            }
            $evaluationResults[] = [
                'index' => $index,
                'name' => $condition['name'] ?? "Branch {$index}",
                'target' => $target,
            ];
        }

        // Include default branch if configured
        $defaultBranch = $this->getConfigValue('default_branch');
        if ($defaultBranch) {
            $allTargets = array_merge($allTargets, $this->normalizeTargets($defaultBranch));
        }

        $allTargets = array_unique($allTargets);

        if (empty($allTargets)) {
            return NodeResult::failed('并行网关：无目标分支', 400);
        }

        return NodeResult::success([
            'gateway_type' => self::GATEWAY_PARALLEL,
            'branch_count' => count($allTargets),
            'branches' => $allTargets,
            'evaluation_results' => $evaluationResults,
        ], $allTargets);
    }

    /**
     * Execute inclusive gateway | 执行包含网关
     *
     * All matching conditions trigger their branches
     *
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function executeInclusive(ExecutionContext $context): NodeResult
    {
        $conditions = $this->getConfigValue('conditions', []);
        $matchedTargets = [];
        $evaluationResults = [];

        foreach ($conditions as $index => $condition) {
            $result = $this->evaluateConditionGroup($condition, $context);
            $evaluationResults[] = [
                'index' => $index,
                'name' => $condition['name'] ?? "Condition {$index}",
                'result' => $result,
                'target' => $condition['target'] ?? null,
            ];

            if ($result && !empty($condition['target'])) {
                $targets = $this->normalizeTargets($condition['target']);
                $matchedTargets = array_merge($matchedTargets, $targets);
            }
        }

        // If no matches, use default branch
        if (empty($matchedTargets)) {
            $defaultBranch = $this->getConfigValue('default_branch');
            if ($defaultBranch) {
                return NodeResult::success([
                    'gateway_type' => self::GATEWAY_INCLUSIVE,
                    'matched_conditions' => [],
                    'evaluation_results' => $evaluationResults,
                    'selected_branches' => [$defaultBranch],
                    'used_default' => true,
                ], $this->normalizeTargets($defaultBranch));
            }

            return NodeResult::failed('包含网关：无匹配条件且无默认分支', 400);
        }

        $matchedTargets = array_unique($matchedTargets);

        return NodeResult::success([
            'gateway_type' => self::GATEWAY_INCLUSIVE,
            'matched_count' => count($matchedTargets),
            'evaluation_results' => $evaluationResults,
            'selected_branches' => $matchedTargets,
        ], $matchedTargets);
    }

    /**
     * Evaluate a condition group | 评估条件组
     *
     * @param array $conditionGroup
     * @param ExecutionContext $context
     * @return bool
     */
    protected function evaluateConditionGroup(array $conditionGroup, ExecutionContext $context): bool
    {
        // Simple expression evaluation
        if (isset($conditionGroup['expression'])) {
            return (bool)$this->evaluateCondition($conditionGroup['expression'], $context);
        }

        // Structured rules evaluation
        if (isset($conditionGroup['rules'])) {
            $logic = $conditionGroup['logic'] ?? 'and';
            return $this->evaluateRules($conditionGroup['rules'], $logic, $context);
        }

        // Single rule evaluation
        if (isset($conditionGroup['field'])) {
            return $this->evaluateSingleRule($conditionGroup, $context);
        }

        // Default to true if no conditions specified
        return true;
    }

    /**
     * Evaluate multiple rules | 评估多条规则
     *
     * @param array $rules
     * @param string $logic 'and' or 'or'
     * @param ExecutionContext $context
     * @return bool
     */
    protected function evaluateRules(array $rules, string $logic, ExecutionContext $context): bool
    {
        if (empty($rules)) {
            return true;
        }

        foreach ($rules as $rule) {
            // Handle nested rule groups
            if (isset($rule['rules'])) {
                $nestedLogic = $rule['logic'] ?? 'and';
                $result = $this->evaluateRules($rule['rules'], $nestedLogic, $context);
            } else {
                $result = $this->evaluateSingleRule($rule, $context);
            }

            if ($logic === 'and' && !$result) {
                return false;
            }
            if ($logic === 'or' && $result) {
                return true;
            }
        }

        return $logic === 'and';
    }

    /**
     * Evaluate a single rule | 评估单条规则
     *
     * @param array $rule
     * @param ExecutionContext $context
     * @return bool
     */
    protected function evaluateSingleRule(array $rule, ExecutionContext $context): bool
    {
        $field = $rule['field'] ?? '';
        $operator = $rule['operator'] ?? self::OP_EQUALS;
        $value = $rule['value'] ?? null;

        // Get field value from context
        $fieldValue = $this->getFieldValue($field, $context);

        // Resolve value if it's an expression
        $compareValue = $this->resolveValue($value, $context);

        return $this->compareValues($fieldValue, $operator, $compareValue);
    }

    /**
     * Get field value from context | 从上下文获取字段值
     *
     * @param string $field
     * @param ExecutionContext $context
     * @return mixed
     */
    protected function getFieldValue(string $field, ExecutionContext $context): mixed
    {
        // Support dot notation for nested values
        if (str_contains($field, '.')) {
            $parts = explode('.', $field, 2);
            $rootValue = $context->getVariable($parts[0]);

            if (is_array($rootValue) && isset($parts[1])) {
                return $this->getNestedValue($rootValue, $parts[1]);
            }

            return $rootValue;
        }

        return $context->getVariable($field);
    }

    /**
     * Get nested value using dot notation | 使用点符号获取嵌套值
     *
     * @param array $array
     * @param string $path
     * @return mixed
     */
    protected function getNestedValue(array $array, string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $array;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Resolve value (handle expressions) | 解析值（处理表达式）
     *
     * @param mixed $value
     * @param ExecutionContext $context
     * @return mixed
     */
    protected function resolveValue(mixed $value, ExecutionContext $context): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // Check for variable reference
        if (str_starts_with($value, '${') && str_ends_with($value, '}')) {
            $varName = substr($value, 2, -1);
            return $this->getFieldValue($varName, $context);
        }

        return $value;
    }

    /**
     * Compare values using operator | 使用运算符比较值
     *
     * @param mixed $fieldValue
     * @param string $operator
     * @param mixed $compareValue
     * @return bool
     */
    protected function compareValues(mixed $fieldValue, string $operator, mixed $compareValue): bool
    {
        return match ($operator) {
            self::OP_EQUALS, '=', '==' => $fieldValue == $compareValue,
            self::OP_NOT_EQUALS, '!=', '<>' => $fieldValue != $compareValue,
            self::OP_GREATER_THAN, '>' => $fieldValue > $compareValue,
            self::OP_GREATER_EQUALS, '>=' => $fieldValue >= $compareValue,
            self::OP_LESS_THAN, '<' => $fieldValue < $compareValue,
            self::OP_LESS_EQUALS, '<=' => $fieldValue <= $compareValue,
            self::OP_CONTAINS => is_string($fieldValue) && str_contains($fieldValue, (string)$compareValue),
            self::OP_NOT_CONTAINS => is_string($fieldValue) && !str_contains($fieldValue, (string)$compareValue),
            self::OP_STARTS_WITH => is_string($fieldValue) && str_starts_with($fieldValue, (string)$compareValue),
            self::OP_ENDS_WITH => is_string($fieldValue) && str_ends_with($fieldValue, (string)$compareValue),
            self::OP_IN => is_array($compareValue) && in_array($fieldValue, $compareValue, false),
            self::OP_NOT_IN => is_array($compareValue) && !in_array($fieldValue, $compareValue, false),
            self::OP_IS_NULL => $fieldValue === null,
            self::OP_IS_NOT_NULL => $fieldValue !== null,
            self::OP_MATCHES => is_string($fieldValue) && is_string($compareValue) &&
                @preg_match($compareValue, $fieldValue) === 1,
            self::OP_BETWEEN => is_array($compareValue) && count($compareValue) === 2 &&
                $fieldValue >= $compareValue[0] && $fieldValue <= $compareValue[1],
            default => false,
        };
    }

    /**
     * Normalize targets to array | 将目标标准化为数组
     *
     * @param mixed $target
     * @return array
     */
    protected function normalizeTargets(mixed $target): array
    {
        if (is_array($target)) {
            return $target;
        }

        return $target ? [$target] : [];
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
                'gateway_type' => [
                    'type' => 'string',
                    'enum' => [self::GATEWAY_EXCLUSIVE, self::GATEWAY_PARALLEL, self::GATEWAY_INCLUSIVE],
                    'default' => self::GATEWAY_EXCLUSIVE,
                    'description' => '网关类型',
                ],
                'conditions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => '条件名称'],
                            'expression' => ['type' => 'string', 'description' => '表达式'],
                            'rules' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'field' => ['type' => 'string'],
                                        'operator' => ['type' => 'string'],
                                        'value' => ['description' => '比较值'],
                                    ],
                                ],
                            ],
                            'logic' => [
                                'type' => 'string',
                                'enum' => ['and', 'or'],
                                'default' => 'and',
                            ],
                            'target' => [
                                'oneOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                                'description' => '目标节点',
                            ],
                        ],
                    ],
                    'description' => '条件列表',
                ],
                'default_branch' => [
                    'type' => 'string',
                    'description' => '默认分支节点ID',
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

        // Validate gateway type
        if (isset($configToValidate['gateway_type'])) {
            $validTypes = [self::GATEWAY_EXCLUSIVE, self::GATEWAY_PARALLEL, self::GATEWAY_INCLUSIVE];
            if (!in_array($configToValidate['gateway_type'], $validTypes, true)) {
                return false;
            }
        }

        // For exclusive gateway, should have conditions or default branch
        $gatewayType = $configToValidate['gateway_type'] ?? self::GATEWAY_EXCLUSIVE;
        if ($gatewayType === self::GATEWAY_EXCLUSIVE) {
            if (empty($configToValidate['conditions']) && empty($configToValidate['default_branch'])) {
                return false;
            }
        }

        return parent::validateConfig($configToValidate);
    }
}
