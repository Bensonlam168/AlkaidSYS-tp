<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers\Types;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;

/**
 * 数据事件触发器
 *
 * 当数据发生变更（创建、更新、删除）时触发工作流。
 * 支持字段级变更检测、条件过滤等功能。
 *
 * @package Alkaid\Workflow\Engine\Triggers\Types
 */
class DataEventTrigger extends AbstractTrigger
{
    /**
     * 事件类型常量
     */
    public const EVENT_CREATED = 'created';
    public const EVENT_UPDATED = 'updated';
    public const EVENT_DELETED = 'deleted';
    public const EVENT_FIELD_CHANGED = 'field_changed';

    /**
     * 获取触发器类型
     */
    public function getType(): string
    {
        return TriggerInterface::TYPE_DATA_EVENT;
    }

    /**
     * 获取触发器名称
     */
    public function getName(): string
    {
        return '数据事件触发器';
    }

    /**
     * 获取触发器描述
     */
    public function getDescription(): string
    {
        return '当数据发生变更时触发工作流，支持创建、更新、删除等事件';
    }

    /**
     * 获取配置 Schema
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
                'collection' => [
                    'type' => 'string',
                    'title' => '数据集合',
                    'description' => '监听的数据集合/表名',
                ],
                'event_types' => [
                    'type' => 'array',
                    'title' => '事件类型',
                    'description' => '监听的事件类型',
                    'items' => [
                        'type' => 'string',
                        'enum' => [
                            self::EVENT_CREATED,
                            self::EVENT_UPDATED,
                            self::EVENT_DELETED,
                            self::EVENT_FIELD_CHANGED,
                        ],
                    ],
                    'default' => [self::EVENT_CREATED, self::EVENT_UPDATED],
                ],
                'watch_fields' => [
                    'type' => 'array',
                    'title' => '监听字段',
                    'description' => '只有这些字段变更时才触发（空表示监听所有字段）',
                    'items' => ['type' => 'string'],
                ],
                'ignore_fields' => [
                    'type' => 'array',
                    'title' => '忽略字段',
                    'description' => '忽略这些字段的变更',
                    'items' => ['type' => 'string'],
                    'default' => ['updated_at', 'update_time'],
                ],
                'conditions' => [
                    'type' => 'array',
                    'title' => '过滤条件',
                    'description' => '只有满足条件的数据才触发',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'operator' => [
                                'type' => 'string',
                                'enum' => ['=', '!=', '>', '<', '>=', '<=', 'in', 'not_in', 'contains', 'starts_with', 'ends_with'],
                            ],
                            'value' => [],
                        ],
                    ],
                ],
                'tenant_isolated' => [
                    'type' => 'boolean',
                    'title' => '租户隔离',
                    'description' => '是否只监听当前租户的数据变更',
                    'default' => true,
                ],
                'debounce' => [
                    'type' => 'integer',
                    'title' => '防抖时间',
                    'description' => '防抖时间（毫秒），同一数据短时间内多次变更只触发一次',
                    'minimum' => 0,
                    'maximum' => 60000,
                    'default' => 0,
                ],
                'batch_mode' => [
                    'type' => 'boolean',
                    'title' => '批量模式',
                    'description' => '是否支持批量事件（一次触发处理多条数据）',
                    'default' => false,
                ],
                'include_old_values' => [
                    'type' => 'boolean',
                    'title' => '包含旧值',
                    'description' => '是否在事件数据中包含变更前的值',
                    'default' => true,
                ],
                'async' => [
                    'type' => 'boolean',
                    'title' => '异步执行',
                    'description' => '是否异步触发工作流（不阻塞数据操作）',
                    'default' => true,
                ],
            ],
            'required' => ['collection', 'event_types'],
        ];
    }

    /**
     * 注册时的钩子方法
     *
     * @param string $workflowId 工作流ID
     * @param array $config 配置
     * @return void
     */
    protected function onRegister(string $workflowId, array $config): void
    {
        // 注册数据事件监听器
        // 这里可以与 ThinkPHP 的模型事件或 ORM 事件系统集成
        // 例如：Event::listen('data.created', ...)
    }

    /**
     * 取消注册时的钩子方法
     *
     * @param string $workflowId 工作流ID
     * @param array $config 配置
     * @return void
     */
    protected function onUnregister(string $workflowId, array $config): void
    {
        // 取消事件监听器
    }

    /**
     * 检查触发条件
     *
     * @param TriggerContext $context 触发上下文
     * @return bool
     */
    protected function checkTriggerConditions(TriggerContext $context): bool
    {
        if (!$context->isDataEvent()) {
            return false;
        }

        $workflowId = $context->getWorkflowId();
        if (!$workflowId || !$this->isRegistered($workflowId)) {
            return false;
        }

        $config = $this->getWorkflowConfig($workflowId);

        // 检查事件类型
        $eventName = $context->getEventName();
        $allowedEvents = $config['event_types'] ?? [self::EVENT_CREATED, self::EVENT_UPDATED];
        if (!in_array($eventName, $allowedEvents, true)) {
            return false;
        }

        // 检查集合/表名
        $eventData = $context->getEventData();
        $collection = $eventData['collection'] ?? $eventData['table'] ?? null;
        if ($collection !== ($config['collection'] ?? null)) {
            return false;
        }

        // 检查租户隔离
        if (($config['tenant_isolated'] ?? true) === true) {
            $configTenantId = $config['tenant_id'] ?? null;
            $contextTenantId = $context->getTenantId();
            if ($configTenantId && $contextTenantId && $configTenantId !== $contextTenantId) {
                return false;
            }
        }

        // 检查字段变更
        if ($eventName === self::EVENT_UPDATED || $eventName === self::EVENT_FIELD_CHANGED) {
            if (!$this->checkFieldChanges($eventData, $config)) {
                return false;
            }
        }

        // 检查过滤条件
        if (!$this->checkConditions($eventData['data'] ?? [], $config['conditions'] ?? [])) {
            return false;
        }

        return true;
    }

    /**
     * 检查字段变更
     *
     * @param array $eventData 事件数据
     * @param array $config 配置
     * @return bool
     */
    protected function checkFieldChanges(array $eventData, array $config): bool
    {
        $changedFields = $eventData['changed_fields'] ?? [];

        if (empty($changedFields)) {
            return true; // 没有变更信息，默认通过
        }

        $watchFields = $config['watch_fields'] ?? [];
        $ignoreFields = $config['ignore_fields'] ?? ['updated_at', 'update_time'];

        // 过滤掉忽略的字段
        $changedFields = array_diff($changedFields, $ignoreFields);

        if (empty($changedFields)) {
            return false; // 所有变更的字段都被忽略
        }

        // 如果配置了监听字段，检查是否有监听的字段变更
        if (!empty($watchFields)) {
            $matchedFields = array_intersect($changedFields, $watchFields);
            return !empty($matchedFields);
        }

        return true;
    }

    /**
     * 检查过滤条件
     *
     * @param array $data 数据
     * @param array $conditions 条件列表
     * @return bool
     */
    protected function checkConditions(array $data, array $conditions): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? '=';
            $expectedValue = $condition['value'] ?? null;

            if (!$field) {
                continue;
            }

            $actualValue = $data[$field] ?? null;

            if (!$this->evaluateCondition($actualValue, $operator, $expectedValue)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 评估单个条件
     *
     * @param mixed $actual 实际值
     * @param string $operator 操作符
     * @param mixed $expected 期望值
     * @return bool
     */
    protected function evaluateCondition(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '=' => $actual == $expected,
            '===' => $actual === $expected,
            '!=' => $actual != $expected,
            '!==' => $actual !== $expected,
            '>' => $actual > $expected,
            '<' => $actual < $expected,
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            'not_in' => is_array($expected) && !in_array($actual, $expected, true),
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'starts_with' => is_string($actual) && is_string($expected) && str_starts_with($actual, $expected),
            'ends_with' => is_string($actual) && is_string($expected) && str_ends_with($actual, $expected),
            'is_null' => is_null($actual),
            'is_not_null' => !is_null($actual),
            'is_empty' => empty($actual),
            'is_not_empty' => !empty($actual),
            default => true,
        };
    }

    /**
     * 执行触发逻辑
     *
     * @param TriggerContext $context 触发上下文
     * @param array $config 触发器配置
     * @return TriggerResult
     */
    protected function doTrigger(TriggerContext $context, array $config): TriggerResult
    {
        $eventData = $context->getEventData();

        // 构建触发数据
        $triggerData = [
            'trigger_type' => $this->getType(),
            'trigger_name' => $config['name'] ?? $this->getName(),
            'event_type' => $context->getEventName(),
            'collection' => $config['collection'],
            'triggered_at' => date('Y-m-d H:i:s'),
            'tenant_id' => $context->getTenantId(),
            'record_id' => $eventData['id'] ?? $eventData['record_id'] ?? null,
            'data' => $eventData['data'] ?? [],
        ];

        // 包含变更信息
        if (!empty($eventData['changed_fields'])) {
            $triggerData['changed_fields'] = $eventData['changed_fields'];
        }

        // 包含旧值
        if (($config['include_old_values'] ?? true) && !empty($eventData['old_data'])) {
            $triggerData['old_data'] = $eventData['old_data'];
        }

        // 包含变更详情
        if (!empty($eventData['changes'])) {
            $triggerData['changes'] = $eventData['changes'];
        }

        return TriggerResult::success($triggerData)
            ->setMeta('event_type', $context->getEventName())
            ->setMeta('collection', $config['collection'])
            ->setMeta('async', $config['async'] ?? true);
    }

    /**
     * 从模型事件创建上下文
     *
     * @param string $workflowId 工作流ID
     * @param string $eventType 事件类型
     * @param string $collection 集合名称
     * @param array $data 当前数据
     * @param array $oldData 旧数据（更新时）
     * @param int|null $tenantId 租户ID
     * @return TriggerContext
     */
    public static function createContextFromModelEvent(
        string $workflowId,
        string $eventType,
        string $collection,
        array $data,
        array $oldData = [],
        ?int $tenantId = null
    ): TriggerContext {
        // 计算变更的字段
        $changedFields = [];
        $changes = [];

        if ($eventType === self::EVENT_UPDATED && !empty($oldData)) {
            foreach ($data as $key => $value) {
                if (!array_key_exists($key, $oldData) || $oldData[$key] !== $value) {
                    $changedFields[] = $key;
                    $changes[$key] = [
                        'old' => $oldData[$key] ?? null,
                        'new' => $value,
                    ];
                }
            }
        }

        $eventData = [
            'collection' => $collection,
            'id' => $data['id'] ?? null,
            'data' => $data,
            'old_data' => $oldData,
            'changed_fields' => $changedFields,
            'changes' => $changes,
        ];

        return TriggerContext::dataEvent($workflowId, $eventType, $eventData, $tenantId);
    }

    /**
     * 获取支持的事件类型
     *
     * @return array
     */
    public static function getSupportedEventTypes(): array
    {
        return [
            self::EVENT_CREATED => '数据创建',
            self::EVENT_UPDATED => '数据更新',
            self::EVENT_DELETED => '数据删除',
            self::EVENT_FIELD_CHANGED => '字段变更',
        ];
    }

    /**
     * 获取支持的操作符
     *
     * @return array
     */
    public static function getSupportedOperators(): array
    {
        return [
            '=' => '等于',
            '!=' => '不等于',
            '>' => '大于',
            '<' => '小于',
            '>=' => '大于等于',
            '<=' => '小于等于',
            'in' => '在列表中',
            'not_in' => '不在列表中',
            'contains' => '包含',
            'starts_with' => '开头是',
            'ends_with' => '结尾是',
            'is_null' => '为空',
            'is_not_null' => '不为空',
            'is_empty' => '为空值',
            'is_not_empty' => '不为空值',
        ];
    }
}
