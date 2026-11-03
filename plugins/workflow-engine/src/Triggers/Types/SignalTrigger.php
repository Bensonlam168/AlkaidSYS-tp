<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers\Types;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;

/**
 * Signal Trigger | 信号触发器 (BPMN)
 *
 * Triggers workflow when a BPMN signal is received.
 * Supports signal name matching, signal data validation, and broadcast/targeted modes.
 * 当接收到 BPMN 信号时触发工作流。
 * 支持信号名称匹配、信号数据验证和广播/定向模式。
 *
 * This trigger implements BPMN 2.0 Signal Events:
 * - Start Event: Triggers new workflow instance
 * - Intermediate Catch Event: Can be used for inter-process communication
 * - Boundary Event: Can interrupt running activities
 *
 * Configuration options:
 * - signal_name: string - Expected signal name
 * - signal_scope: string - Signal scope (global, tenant, process)
 * - data_schema: array - Expected signal data schema
 * - catch_mode: string - How to catch signals (all, first, filtered)
 *
 * @package Alkaid\Workflow\Engine\Triggers\Types
 */
class SignalTrigger extends AbstractTrigger
{
    /**
     * Signal scopes | 信号作用域
     */
    public const SCOPE_GLOBAL = 'global';         // All processes across all tenants
    public const SCOPE_TENANT = 'tenant';         // All processes within tenant
    public const SCOPE_PROCESS = 'process';       // Specific process only
    public const SCOPE_INSTANCE = 'instance';     // Specific instance only

    /**
     * Signal catch modes | 信号捕获模式
     */
    public const CATCH_ALL = 'all';               // Trigger all matching workflows
    public const CATCH_FIRST = 'first';           // Trigger only first match
    public const CATCH_FILTERED = 'filtered';     // Trigger based on filter

    /**
     * Get trigger type | 获取触发器类型
     */
    public function getType(): string
    {
        return TriggerInterface::TYPE_SIGNAL;
    }

    /**
     * Get trigger name | 获取触发器名称
     */
    public function getName(): string
    {
        return '信号触发器';
    }

    /**
     * Get trigger description | 获取触发器描述
     */
    public function getDescription(): string
    {
        return 'BPMN 2.0 信号事件触发器，用于跨流程通信和广播通知';
    }

    /**
     * Get configuration schema | 获取配置 Schema
     */
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'title' => '触发器名称',
                    'description' => '触发器的显示名称',
                    'maxLength' => 100,
                ],
                'signal_name' => [
                    'type' => 'string',
                    'title' => '信号名称',
                    'description' => '期望捕获的信号名称',
                    'pattern' => '/^[a-zA-Z_][a-zA-Z0-9_\-\.]*$/',
                ],
                'signal_scope' => [
                    'type' => 'string',
                    'title' => '信号作用域',
                    'description' => '信号的作用范围',
                    'enum' => [self::SCOPE_GLOBAL, self::SCOPE_TENANT, self::SCOPE_PROCESS, self::SCOPE_INSTANCE],
                    'default' => self::SCOPE_TENANT,
                ],
                'catch_mode' => [
                    'type' => 'string',
                    'title' => '捕获模式',
                    'description' => '信号捕获方式',
                    'enum' => [self::CATCH_ALL, self::CATCH_FIRST, self::CATCH_FILTERED],
                    'default' => self::CATCH_ALL,
                ],
                'filter_expression' => [
                    'type' => 'string',
                    'title' => '过滤表达式',
                    'description' => '当 catch_mode 为 filtered 时使用的过滤表达式',
                ],
                'data_schema' => [
                    'type' => 'object',
                    'title' => '数据 Schema',
                    'description' => '期望的信号数据结构（JSON Schema）',
                ],
                'correlation_keys' => [
                    'type' => 'array',
                    'title' => '关联键',
                    'description' => '用于匹配特定流程实例的关联键',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'path' => ['type' => 'string'],
                        ],
                    ],
                ],
                'interrupt_current' => [
                    'type' => 'boolean',
                    'title' => '中断当前',
                    'description' => '是否中断当前正在执行的活动',
                    'default' => false,
                ],
                'async' => [
                    'type' => 'boolean',
                    'title' => '异步执行',
                    'description' => '是否异步处理信号',
                    'default' => true,
                ],
                'ttl' => [
                    'type' => 'integer',
                    'title' => '信号有效期（秒）',
                    'description' => '信号的有效期，过期后不再触发',
                    'minimum' => 0,
                    'maximum' => 86400,
                    'default' => 0,
                ],
            ],
            'required' => ['signal_name'],
        ];
    }

    /**
     * Check trigger conditions | 检查触发条件
     *
     * @param TriggerContext $context
     * @return bool
     */
    protected function checkTriggerConditions(TriggerContext $context): bool
    {
        $workflowId = $context->getWorkflowId();
        if (!$workflowId) {
            return false;
        }

        $config = $this->getWorkflowConfig($workflowId);
        if (!$config) {
            return false;
        }

        $data = $context->getData();

        // Check signal name match
        $expectedSignal = $config['signal_name'] ?? null;
        $actualSignal = $data['signal_name'] ?? $data['signal'] ?? $context->getMeta('signal_name');
        if ($expectedSignal && $actualSignal !== $expectedSignal) {
            return false;
        }

        // Check signal scope
        if (!$this->checkSignalScope($context, $config)) {
            return false;
        }

        // Check TTL
        if (!$this->checkSignalTTL($data, $config)) {
            return false;
        }

        // Check correlation keys
        if (!empty($config['correlation_keys'])) {
            if (!$this->checkCorrelationKeys($data, $config['correlation_keys'], $context)) {
                return false;
            }
        }

        // Check filter expression
        if (($config['catch_mode'] ?? self::CATCH_ALL) === self::CATCH_FILTERED) {
            if (!$this->evaluateFilterExpression($data, $config['filter_expression'] ?? '')) {
                return false;
            }
        }

        // Validate data schema
        if (!empty($config['data_schema'])) {
            $signalData = $data['data'] ?? $data['payload'] ?? [];
            if (!$this->validateDataSchema($signalData, $config['data_schema'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check signal scope | 检查信号作用域
     *
     * @param TriggerContext $context
     * @param array $config
     * @return bool
     */
    protected function checkSignalScope(TriggerContext $context, array $config): bool
    {
        $expectedScope = $config['signal_scope'] ?? self::SCOPE_TENANT;
        $data = $context->getData();
        $signalScope = $data['scope'] ?? self::SCOPE_GLOBAL;

        switch ($expectedScope) {
            case self::SCOPE_GLOBAL:
                return true; // Always accept global scope

            case self::SCOPE_TENANT:
                // Must match tenant - tenant-scoped workflows require matching tenant_id
                $signalTenantId = $data['tenant_id'] ?? null;
                $contextTenantId = $context->getTenantId();
                // If signal specifies a tenant_id, it must match the context tenant
                // If signal has no tenant_id, allow it (global signal to tenant workflow)
                return $signalTenantId === null || $signalTenantId === $contextTenantId;

            case self::SCOPE_PROCESS:
                // Must match process definition
                $signalProcessId = $data['process_id'] ?? null;
                $workflowId = $context->getWorkflowId();
                return $signalProcessId === null || $signalProcessId === $workflowId;

            case self::SCOPE_INSTANCE:
                // Must match specific instance
                $signalInstanceId = $data['instance_id'] ?? null;
                $contextInstanceId = $context->getMeta('instance_id');
                return $signalInstanceId !== null && $signalInstanceId === $contextInstanceId;

            default:
                return true;
        }
    }

    /**
     * Check signal TTL | 检查信号有效期
     *
     * @param array $data
     * @param array $config
     * @return bool
     */
    protected function checkSignalTTL(array $data, array $config): bool
    {
        $ttl = $config['ttl'] ?? 0;
        if ($ttl <= 0) {
            return true; // No TTL limit
        }

        $signalTime = $data['timestamp'] ?? $data['created_at'] ?? null;
        if ($signalTime === null) {
            return true;
        }

        $signalTimestamp = is_numeric($signalTime) ? (int)$signalTime : strtotime($signalTime);
        $expirationTime = $signalTimestamp + $ttl;

        return time() <= $expirationTime;
    }

    /**
     * Check correlation keys | 检查关联键
     *
     * @param array $data
     * @param array $correlationKeys
     * @param TriggerContext $context
     * @return bool
     */
    protected function checkCorrelationKeys(array $data, array $correlationKeys, TriggerContext $context): bool
    {
        foreach ($correlationKeys as $key) {
            $keyName = $key['name'] ?? null;
            $keyPath = $key['path'] ?? $keyName;

            if (!$keyName || !$keyPath) {
                continue;
            }

            // Get expected value from context
            $expectedValue = $context->getMeta("correlation.{$keyName}");
            if ($expectedValue === null) {
                continue; // No correlation requirement
            }

            // Get actual value from signal data
            $actualValue = $this->getNestedValue($data, $keyPath);

            if ($actualValue !== $expectedValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate filter expression | 评估过滤表达式
     *
     * @param array $data
     * @param string $expression
     * @return bool
     */
    protected function evaluateFilterExpression(array $data, string $expression): bool
    {
        if (empty($expression)) {
            return true;
        }

        // Simple expression evaluation
        // In real implementation, use expression engine
        // Support format: field == value, field != value, field > value, etc.

        if (preg_match('/^(\w+(?:\.\w+)*)\s*(==|!=|>|<|>=|<=)\s*(.+)$/', trim($expression), $matches)) {
            $field = $matches[1];
            $operator = $matches[2];
            $value = trim($matches[3], '"\'');

            $actualValue = $this->getNestedValue($data, $field);

            return match ($operator) {
                '==' => $actualValue == $value,
                '!=' => $actualValue != $value,
                '>' => $actualValue > $value,
                '<' => $actualValue < $value,
                '>=' => $actualValue >= $value,
                '<=' => $actualValue <= $value,
                default => true,
            };
        }

        return true;
    }

    /**
     * Validate data schema | 验证数据 Schema
     *
     * @param array $data
     * @param array $schema
     * @return bool
     */
    protected function validateDataSchema(array $data, array $schema): bool
    {
        // Basic schema validation
        $required = $schema['required'] ?? [];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get nested value by dot notation | 通过点号表示法获取嵌套值
     *
     * @param array $data
     * @param string $path
     * @return mixed
     */
    protected function getNestedValue(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Execute trigger logic | 执行触发逻辑
     *
     * @param TriggerContext $context
     * @param array $config
     * @return TriggerResult
     */
    protected function doTrigger(TriggerContext $context, array $config): TriggerResult
    {
        $data = $context->getData();

        // Extract correlation values
        $correlations = [];
        foreach ($config['correlation_keys'] ?? [] as $key) {
            $keyName = $key['name'] ?? null;
            $keyPath = $key['path'] ?? $keyName;
            if ($keyName && $keyPath) {
                $correlations[$keyName] = $this->getNestedValue($data, $keyPath);
            }
        }

        // Build trigger data
        $triggerData = [
            'trigger_type' => $this->getType(),
            'trigger_name' => $this->getName(),
            'triggered_at' => date('Y-m-d H:i:s'),
            'tenant_id' => $context->getTenantId(),
            'signal_name' => $data['signal_name'] ?? $data['signal'] ?? $config['signal_name'],
            'signal_id' => $data['signal_id'] ?? uniqid('sig_'),
            'signal_scope' => $data['scope'] ?? $config['signal_scope'] ?? self::SCOPE_TENANT,
            'signal_data' => $data['data'] ?? $data['payload'] ?? [],
            'signal_source' => $data['source'] ?? null,
            'correlations' => $correlations,
            'catch_mode' => $config['catch_mode'] ?? self::CATCH_ALL,
            'interrupt_current' => $config['interrupt_current'] ?? false,
            'async' => $config['async'] ?? true,
        ];

        return TriggerResult::success($triggerData)
            ->setMeta('signal_name', $triggerData['signal_name'])
            ->setMeta('signal_id', $triggerData['signal_id'])
            ->setMeta('correlations', $correlations);
    }

    /**
     * Broadcast signal | 广播信号
     *
     * Static helper method to broadcast a signal to all registered triggers.
     * 静态帮助方法，向所有注册的触发器广播信号。
     *
     * @param string $signalName
     * @param array $data
     * @param string $scope
     * @param int|null $tenantId
     * @return array Signal context for triggering
     */
    public static function createSignalContext(
        string $signalName,
        array $data = [],
        string $scope = self::SCOPE_TENANT,
        ?int $tenantId = null
    ): array {
        return [
            'signal_name' => $signalName,
            'signal_id' => uniqid('sig_'),
            'scope' => $scope,
            'tenant_id' => $tenantId,
            'data' => $data,
            'timestamp' => time(),
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }
}
