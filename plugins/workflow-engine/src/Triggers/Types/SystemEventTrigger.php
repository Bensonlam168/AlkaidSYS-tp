<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers\Types;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;

/**
 * System Event Trigger | 系统事件触发器
 *
 * Triggers workflow when system-level events occur.
 * Supports various system events like user actions, order lifecycle, payment status, etc.
 * 当系统级别事件发生时触发工作流。
 * 支持各种系统事件，如用户操作、订单生命周期、支付状态等。
 *
 * Configuration options:
 * - event_category: string - Event category (user, order, payment, inventory, etc.)
 * - event_name: string - Specific event name
 * - entity_type: string - Related entity type
 * - conditions: array - Additional filtering conditions
 *
 * @package Alkaid\Workflow\Engine\Triggers\Types
 */
class SystemEventTrigger extends AbstractTrigger
{
    /**
     * Event categories | 事件分类
     */
    public const CATEGORY_USER = 'user';
    public const CATEGORY_ORDER = 'order';
    public const CATEGORY_PAYMENT = 'payment';
    public const CATEGORY_INVENTORY = 'inventory';
    public const CATEGORY_PRODUCT = 'product';
    public const CATEGORY_CUSTOMER = 'customer';
    public const CATEGORY_EMPLOYEE = 'employee';
    public const CATEGORY_DOCUMENT = 'document';
    public const CATEGORY_APPROVAL = 'approval';
    public const CATEGORY_NOTIFICATION = 'notification';
    public const CATEGORY_SYSTEM = 'system';
    public const CATEGORY_CUSTOM = 'custom';

    /**
     * Common user events | 常见用户事件
     */
    public const EVENT_USER_REGISTERED = 'user.registered';
    public const EVENT_USER_LOGIN = 'user.login';
    public const EVENT_USER_LOGOUT = 'user.logout';
    public const EVENT_USER_UPDATED = 'user.updated';
    public const EVENT_USER_DELETED = 'user.deleted';
    public const EVENT_USER_PASSWORD_CHANGED = 'user.password_changed';
    public const EVENT_USER_ROLE_CHANGED = 'user.role_changed';

    /**
     * Common order events | 常见订单事件
     */
    public const EVENT_ORDER_CREATED = 'order.created';
    public const EVENT_ORDER_PAID = 'order.paid';
    public const EVENT_ORDER_SHIPPED = 'order.shipped';
    public const EVENT_ORDER_DELIVERED = 'order.delivered';
    public const EVENT_ORDER_CANCELLED = 'order.cancelled';
    public const EVENT_ORDER_REFUNDED = 'order.refunded';
    public const EVENT_ORDER_COMPLETED = 'order.completed';

    /**
     * Common approval events | 常见审批事件
     */
    public const EVENT_APPROVAL_SUBMITTED = 'approval.submitted';
    public const EVENT_APPROVAL_APPROVED = 'approval.approved';
    public const EVENT_APPROVAL_REJECTED = 'approval.rejected';
    public const EVENT_APPROVAL_WITHDRAWN = 'approval.withdrawn';
    public const EVENT_APPROVAL_ESCALATED = 'approval.escalated';

    /**
     * Common document events | 常见文档事件
     */
    public const EVENT_DOCUMENT_CREATED = 'document.created';
    public const EVENT_DOCUMENT_UPDATED = 'document.updated';
    public const EVENT_DOCUMENT_DELETED = 'document.deleted';
    public const EVENT_DOCUMENT_SHARED = 'document.shared';
    public const EVENT_DOCUMENT_DOWNLOADED = 'document.downloaded';

    /**
     * Get trigger type | 获取触发器类型
     */
    public function getType(): string
    {
        return TriggerInterface::TYPE_SYSTEM_EVENT;
    }

    /**
     * Get trigger name | 获取触发器名称
     */
    public function getName(): string
    {
        return '系统事件触发器';
    }

    /**
     * Get trigger description | 获取触发器描述
     */
    public function getDescription(): string
    {
        return '当系统级别事件发生时自动触发工作流，适用于业务事件驱动的自动化场景';
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
                'event_category' => [
                    'type' => 'string',
                    'title' => '事件分类',
                    'description' => '系统事件的分类',
                    'enum' => [
                        self::CATEGORY_USER,
                        self::CATEGORY_ORDER,
                        self::CATEGORY_PAYMENT,
                        self::CATEGORY_INVENTORY,
                        self::CATEGORY_PRODUCT,
                        self::CATEGORY_CUSTOMER,
                        self::CATEGORY_EMPLOYEE,
                        self::CATEGORY_DOCUMENT,
                        self::CATEGORY_APPROVAL,
                        self::CATEGORY_NOTIFICATION,
                        self::CATEGORY_SYSTEM,
                        self::CATEGORY_CUSTOM,
                    ],
                    'default' => self::CATEGORY_CUSTOM,
                ],
                'event_name' => [
                    'type' => 'string',
                    'title' => '事件名称',
                    'description' => '具体的事件名称（如 order.created）',
                ],
                'event_pattern' => [
                    'type' => 'string',
                    'title' => '事件模式',
                    'description' => '事件名称匹配模式（支持通配符，如 order.*）',
                ],
                'entity_type' => [
                    'type' => 'string',
                    'title' => '实体类型',
                    'description' => '关联的实体类型',
                ],
                'entity_id_field' => [
                    'type' => 'string',
                    'title' => '实体ID字段',
                    'description' => '事件数据中实体ID的字段路径',
                    'default' => 'entity_id',
                ],
                'conditions' => [
                    'type' => 'array',
                    'title' => '过滤条件',
                    'description' => '事件数据的过滤条件',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'operator' => ['type' => 'string', 'enum' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'nin', 'contains', 'regex']],
                            'value' => ['type' => ['string', 'number', 'boolean', 'array']],
                        ],
                    ],
                ],
                'field_mapping' => [
                    'type' => 'object',
                    'title' => '字段映射',
                    'description' => '事件数据到工作流变量的映射',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'include_entity_data' => [
                    'type' => 'boolean',
                    'title' => '包含实体数据',
                    'description' => '是否在触发数据中包含完整实体数据',
                    'default' => true,
                ],
                'priority' => [
                    'type' => 'integer',
                    'title' => '优先级',
                    'description' => '触发器优先级，数值越大优先级越高',
                    'minimum' => 0,
                    'maximum' => 100,
                    'default' => 50,
                ],
                'debounce_key' => [
                    'type' => 'string',
                    'title' => '防抖键',
                    'description' => '用于防抖的字段路径',
                ],
                'debounce_ms' => [
                    'type' => 'integer',
                    'title' => '防抖时间（毫秒）',
                    'description' => '防抖的时间窗口',
                    'minimum' => 0,
                    'maximum' => 60000,
                    'default' => 0,
                ],
                'rate_limit' => [
                    'type' => 'object',
                    'title' => '频率限制',
                    'description' => '触发频率限制配置',
                    'properties' => [
                        'max_triggers' => ['type' => 'integer', 'minimum' => 1],
                        'window_seconds' => ['type' => 'integer', 'minimum' => 1],
                    ],
                ],
            ],
            'required' => ['event_category'],
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

        // Check event category
        $expectedCategory = $config['event_category'] ?? self::CATEGORY_CUSTOM;
        $actualCategory = $data['event_category'] ?? $data['category'] ?? $this->extractCategory($data);
        if ($expectedCategory !== self::CATEGORY_CUSTOM && $actualCategory !== $expectedCategory) {
            return false;
        }

        // Check event name or pattern
        if (!$this->checkEventNameMatch($data, $config)) {
            return false;
        }

        // Check entity type
        if (!empty($config['entity_type'])) {
            $actualEntityType = $data['entity_type'] ?? $data['type'] ?? null;
            if ($actualEntityType !== $config['entity_type']) {
                return false;
            }
        }

        // Check additional conditions
        if (!empty($config['conditions'])) {
            if (!$this->evaluateConditions($data, $config['conditions'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract category from event name | 从事件名称提取分类
     *
     * @param array $data
     * @return string|null
     */
    protected function extractCategory(array $data): ?string
    {
        $eventName = $data['event_name'] ?? $data['event'] ?? $data['name'] ?? null;
        if ($eventName && str_contains($eventName, '.')) {
            return explode('.', $eventName)[0];
        }
        return null;
    }

    /**
     * Check event name match | 检查事件名称匹配
     *
     * @param array $data
     * @param array $config
     * @return bool
     */
    protected function checkEventNameMatch(array $data, array $config): bool
    {
        $actualEvent = $data['event_name'] ?? $data['event'] ?? $data['name'] ?? null;
        if (!$actualEvent) {
            return true; // No event name check if not provided
        }

        // Check exact match
        if (!empty($config['event_name'])) {
            if ($actualEvent !== $config['event_name']) {
                return false;
            }
        }

        // Check pattern match
        if (!empty($config['event_pattern'])) {
            if (!$this->matchEventPattern($actualEvent, $config['event_pattern'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Match event pattern | 匹配事件模式
     *
     * @param string $eventName
     * @param string $pattern
     * @return bool
     */
    protected function matchEventPattern(string $eventName, string $pattern): bool
    {
        // Convert wildcard pattern to regex
        // * matches any characters, ? matches single character
        $regex = '/^' . str_replace(
            ['\\*', '\\?'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        ) . '$/';

        return (bool)preg_match($regex, $eventName);
    }

    /**
     * Evaluate conditions | 评估条件
     *
     * @param array $data
     * @param array $conditions
     * @return bool
     */
    protected function evaluateConditions(array $data, array $conditions): bool
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
     * Get nested value | 获取嵌套值
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
            'nin' => is_array($expected) && !in_array($actual, $expected, false),
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
        $eventName = $data['event_name'] ?? $data['event'] ?? $data['name'] ?? 'unknown';

        // Extract entity information
        $entityIdField = $config['entity_id_field'] ?? 'entity_id';
        $entityId = $this->getNestedValue($data, $entityIdField) ?? $data['id'] ?? null;

        // Apply field mapping
        $mappedData = $this->applyFieldMapping($data, $config['field_mapping'] ?? []);

        // Build trigger data
        $triggerData = [
            'trigger_type' => $this->getType(),
            'trigger_name' => $this->getName(),
            'triggered_at' => date('Y-m-d H:i:s'),
            'tenant_id' => $context->getTenantId(),
            'event_category' => $config['event_category'] ?? $this->extractCategory($data) ?? self::CATEGORY_CUSTOM,
            'event_name' => $eventName,
            'entity_type' => $data['entity_type'] ?? $config['entity_type'] ?? null,
            'entity_id' => $entityId,
            'event_id' => $data['event_id'] ?? uniqid('evt_'),
            'event_timestamp' => $data['timestamp'] ?? $data['created_at'] ?? date('Y-m-d H:i:s'),
            'event_source' => $data['source'] ?? 'system',
            'triggered_by' => $context->getUserId(),
            'mapped_data' => $mappedData,
            'priority' => $config['priority'] ?? 50,
        ];

        // Include entity data if configured
        if ($config['include_entity_data'] ?? true) {
            $triggerData['entity_data'] = $data['entity_data'] ?? $data['entity'] ?? $data['payload'] ?? [];
        }

        // Add original event data
        $triggerData['event_data'] = $data;

        return TriggerResult::success($triggerData)
            ->setMeta('event_name', $eventName)
            ->setMeta('entity_type', $triggerData['entity_type'])
            ->setMeta('entity_id', $entityId);
    }

    /**
     * Apply field mapping | 应用字段映射
     *
     * @param array $data
     * @param array $mapping
     * @return array
     */
    protected function applyFieldMapping(array $data, array $mapping): array
    {
        if (empty($mapping)) {
            return [];
        }

        $result = [];

        foreach ($mapping as $targetField => $sourceField) {
            $value = $this->getNestedValue($data, $sourceField);
            $result[$targetField] = $value;
        }

        return $result;
    }

    /**
     * Get all supported event names | 获取所有支持的事件名称
     *
     * @return array
     */
    public static function getSupportedEvents(): array
    {
        return [
            self::CATEGORY_USER => [
                self::EVENT_USER_REGISTERED,
                self::EVENT_USER_LOGIN,
                self::EVENT_USER_LOGOUT,
                self::EVENT_USER_UPDATED,
                self::EVENT_USER_DELETED,
                self::EVENT_USER_PASSWORD_CHANGED,
                self::EVENT_USER_ROLE_CHANGED,
            ],
            self::CATEGORY_ORDER => [
                self::EVENT_ORDER_CREATED,
                self::EVENT_ORDER_PAID,
                self::EVENT_ORDER_SHIPPED,
                self::EVENT_ORDER_DELIVERED,
                self::EVENT_ORDER_CANCELLED,
                self::EVENT_ORDER_REFUNDED,
                self::EVENT_ORDER_COMPLETED,
            ],
            self::CATEGORY_APPROVAL => [
                self::EVENT_APPROVAL_SUBMITTED,
                self::EVENT_APPROVAL_APPROVED,
                self::EVENT_APPROVAL_REJECTED,
                self::EVENT_APPROVAL_WITHDRAWN,
                self::EVENT_APPROVAL_ESCALATED,
            ],
            self::CATEGORY_DOCUMENT => [
                self::EVENT_DOCUMENT_CREATED,
                self::EVENT_DOCUMENT_UPDATED,
                self::EVENT_DOCUMENT_DELETED,
                self::EVENT_DOCUMENT_SHARED,
                self::EVENT_DOCUMENT_DOWNLOADED,
            ],
        ];
    }

    /**
     * Create system event context | 创建系统事件上下文
     *
     * Static helper to create a properly formatted event context.
     * 静态帮助方法，创建格式正确的事件上下文。
     *
     * @param string $eventName
     * @param string|null $entityType
     * @param mixed $entityId
     * @param array $entityData
     * @param int|null $tenantId
     * @return array
     */
    public static function createEventContext(
        string $eventName,
        ?string $entityType = null,
        mixed $entityId = null,
        array $entityData = [],
        ?int $tenantId = null
    ): array {
        return [
            'event_name' => $eventName,
            'event_id' => uniqid('evt_'),
            'event_category' => str_contains($eventName, '.') ? explode('.', $eventName)[0] : self::CATEGORY_CUSTOM,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_data' => $entityData,
            'tenant_id' => $tenantId,
            'timestamp' => date('Y-m-d H:i:s'),
            'source' => 'system',
        ];
    }
}
