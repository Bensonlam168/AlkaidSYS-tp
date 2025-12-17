<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers\Types;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;

/**
 * Form Submit Trigger | 表单提交触发器
 *
 * Triggers workflow when a specified form is submitted.
 * Supports form field validation, conditional filtering, and data transformation.
 * 当指定表单提交时触发工作流。
 * 支持表单字段验证、条件过滤和数据转换。
 *
 * Configuration options:
 * - form_id: string - Target form identifier
 * - form_collection: string - Associated collection name
 * - action: string - Action type (create, update, delete)
 * - conditions: array - Field conditions to trigger
 * - field_mapping: array - Map form fields to workflow variables
 * - validate_form: bool - Whether to validate form data
 *
 * @package Alkaid\Workflow\Engine\Triggers\Types
 */
class FormSubmitTrigger extends AbstractTrigger
{
    /**
     * Form action types | 表单操作类型
     */
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_ANY = 'any';

    /**
     * Get trigger type | 获取触发器类型
     */
    public function getType(): string
    {
        return TriggerInterface::TYPE_FORM_SUBMIT;
    }

    /**
     * Get trigger name | 获取触发器名称
     */
    public function getName(): string
    {
        return '表单提交触发器';
    }

    /**
     * Get trigger description | 获取触发器描述
     */
    public function getDescription(): string
    {
        return '当用户提交指定表单时自动触发工作流，适用于表单驱动的业务流程';
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
                'form_id' => [
                    'type' => 'string',
                    'title' => '表单ID',
                    'description' => '要监听的表单标识',
                ],
                'form_collection' => [
                    'type' => 'string',
                    'title' => '关联集合',
                    'description' => '表单关联的数据集合名称',
                ],
                'action' => [
                    'type' => 'string',
                    'title' => '操作类型',
                    'description' => '触发的操作类型',
                    'enum' => [self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_DELETE, self::ACTION_ANY],
                    'default' => self::ACTION_ANY,
                ],
                'conditions' => [
                    'type' => 'array',
                    'title' => '触发条件',
                    'description' => '字段条件列表，所有条件满足时才触发',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'operator' => ['type' => 'string', 'enum' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'contains', 'regex']],
                            'value' => ['type' => ['string', 'number', 'boolean', 'array']],
                        ],
                    ],
                ],
                'field_mapping' => [
                    'type' => 'object',
                    'title' => '字段映射',
                    'description' => '表单字段到工作流变量的映射',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'validate_form' => [
                    'type' => 'boolean',
                    'title' => '验证表单',
                    'description' => '是否在触发前验证表单数据',
                    'default' => true,
                ],
                'include_form_data' => [
                    'type' => 'boolean',
                    'title' => '包含表单数据',
                    'description' => '是否在触发数据中包含完整表单数据',
                    'default' => true,
                ],
                'debounce_ms' => [
                    'type' => 'integer',
                    'title' => '防抖时间（毫秒）',
                    'description' => '连续提交的防抖时间',
                    'minimum' => 0,
                    'maximum' => 60000,
                    'default' => 0,
                ],
            ],
            'required' => [],
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

        // Check form_id match
        if (!empty($config['form_id'])) {
            $contextFormId = $data['form_id'] ?? $context->getMeta('form_id');
            if ($contextFormId !== $config['form_id']) {
                return false;
            }
        }

        // Check form_collection match
        if (!empty($config['form_collection'])) {
            $contextCollection = $data['collection'] ?? $context->getMeta('collection');
            if ($contextCollection !== $config['form_collection']) {
                return false;
            }
        }

        // Check action type
        $configAction = $config['action'] ?? self::ACTION_ANY;
        if ($configAction !== self::ACTION_ANY) {
            $contextAction = $data['action'] ?? $context->getMeta('action', self::ACTION_CREATE);
            if ($contextAction !== $configAction) {
                return false;
            }
        }

        // Check field conditions
        if (!empty($config['conditions'])) {
            $formData = $data['form_data'] ?? $data;
            if (!$this->evaluateConditions($config['conditions'], $formData)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate conditions | 评估条件
     *
     * @param array $conditions
     * @param array $data
     * @return bool
     */
    protected function evaluateConditions(array $conditions, array $data): bool
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? 'eq';
            $expectedValue = $condition['value'] ?? null;

            if ($field === null) {
                continue;
            }

            $actualValue = $this->getNestedValue($data, $field);

            if (!$this->evaluateCondition($actualValue, $operator, $expectedValue)) {
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
     * Evaluate single condition | 评估单个条件
     *
     * @param mixed $actual
     * @param string $operator
     * @param mixed $expected
     * @return bool
     */
    protected function evaluateCondition(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
            'gt' => $actual > $expected,
            'gte' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte' => $actual <= $expected,
            'in' => is_array($expected) && in_array($actual, $expected, false),
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'regex' => is_string($actual) && is_string($expected) && preg_match($expected, $actual),
            default => true,
        };
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
        $formData = $data['form_data'] ?? $data;

        // Validate form data if required
        if (!empty($config['validate_form']) && !$this->validateFormData($formData, $config)) {
            return TriggerResult::failed('表单数据验证失败')
                ->setMeta('validation_errors', $this->validationErrors);
        }

        // Apply field mapping
        $mappedData = $this->applyFieldMapping($formData, $config['field_mapping'] ?? []);

        // Build trigger data
        $triggerData = [
            'trigger_type' => $this->getType(),
            'trigger_name' => $this->getName(),
            'triggered_at' => date('Y-m-d H:i:s'),
            'tenant_id' => $context->getTenantId(),
            'form_id' => $data['form_id'] ?? $config['form_id'] ?? null,
            'form_collection' => $data['collection'] ?? $config['form_collection'] ?? null,
            'action' => $data['action'] ?? self::ACTION_CREATE,
            'record_id' => $data['record_id'] ?? null,
            'mapped_data' => $mappedData,
            'triggered_by' => $context->getUserId(),
        ];

        // Include full form data if configured
        if ($config['include_form_data'] ?? true) {
            $triggerData['form_data'] = $formData;
        }

        return TriggerResult::success($triggerData)
            ->setMeta('form_id', $triggerData['form_id'])
            ->setMeta('action', $triggerData['action']);
    }

    /**
     * Validate form data | 验证表单数据
     *
     * @param array $formData
     * @param array $config
     * @return bool
     */
    protected function validateFormData(array $formData, array $config): bool
    {
        // Basic validation - can be extended with schema validation
        return !empty($formData);
    }

    /**
     * Apply field mapping | 应用字段映射
     *
     * @param array $formData
     * @param array $mapping
     * @return array
     */
    protected function applyFieldMapping(array $formData, array $mapping): array
    {
        if (empty($mapping)) {
            return $formData;
        }

        $result = [];

        foreach ($mapping as $targetField => $sourceField) {
            $value = $this->getNestedValue($formData, $sourceField);
            $result[$targetField] = $value;
        }

        return $result;
    }
}
