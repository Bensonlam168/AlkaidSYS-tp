<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers\Types;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;

/**
 * Message Trigger | 消息触发器
 *
 * Triggers workflow when a specific message is received.
 * Supports message type filtering, content matching, and source validation.
 * 当接收到特定消息时触发工作流。
 * 支持消息类型过滤、内容匹配和来源验证。
 *
 * Configuration options:
 * - message_name: string - Expected message name/identifier
 * - message_type: string - Expected message type
 * - source_filter: array - Filter by message source
 * - content_match: array - Match message content patterns
 * - correlation_key: string - Key for message correlation
 *
 * @package Alkaid\Workflow\Engine\Triggers\Types
 */
class MessageTrigger extends AbstractTrigger
{
    /**
     * Message types | 消息类型
     */
    public const MSG_TYPE_COMMAND = 'command';
    public const MSG_TYPE_EVENT = 'event';
    public const MSG_TYPE_REQUEST = 'request';
    public const MSG_TYPE_RESPONSE = 'response';
    public const MSG_TYPE_NOTIFICATION = 'notification';
    public const MSG_TYPE_ANY = 'any';

    /**
     * Message sources | 消息来源
     */
    public const SOURCE_INTERNAL = 'internal';
    public const SOURCE_EXTERNAL = 'external';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_USER = 'user';
    public const SOURCE_WORKFLOW = 'workflow';

    /**
     * Get trigger type | 获取触发器类型
     */
    public function getType(): string
    {
        return TriggerInterface::TYPE_MESSAGE;
    }

    /**
     * Get trigger name | 获取触发器名称
     */
    public function getName(): string
    {
        return '消息触发器';
    }

    /**
     * Get trigger description | 获取触发器描述
     */
    public function getDescription(): string
    {
        return '当系统接收到特定消息时自动触发工作流，适用于消息驱动架构和事件溯源场景';
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
                'message_name' => [
                    'type' => 'string',
                    'title' => '消息名称',
                    'description' => '期望接收的消息名称或标识',
                ],
                'message_type' => [
                    'type' => 'string',
                    'title' => '消息类型',
                    'description' => '期望的消息类型',
                    'enum' => [
                        self::MSG_TYPE_COMMAND,
                        self::MSG_TYPE_EVENT,
                        self::MSG_TYPE_REQUEST,
                        self::MSG_TYPE_RESPONSE,
                        self::MSG_TYPE_NOTIFICATION,
                        self::MSG_TYPE_ANY,
                    ],
                    'default' => self::MSG_TYPE_ANY,
                ],
                'source_filter' => [
                    'type' => 'object',
                    'title' => '来源过滤',
                    'description' => '消息来源过滤条件',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'enum' => [
                                self::SOURCE_INTERNAL,
                                self::SOURCE_EXTERNAL,
                                self::SOURCE_SYSTEM,
                                self::SOURCE_USER,
                                self::SOURCE_WORKFLOW,
                            ],
                        ],
                        'allowed_sources' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'blocked_sources' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                ],
                'content_match' => [
                    'type' => 'array',
                    'title' => '内容匹配',
                    'description' => '消息内容匹配规则',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'pattern' => ['type' => 'string'],
                            'match_type' => ['type' => 'string', 'enum' => ['exact', 'contains', 'regex', 'prefix', 'suffix']],
                        ],
                    ],
                ],
                'correlation_key' => [
                    'type' => 'string',
                    'title' => '关联键',
                    'description' => '用于消息关联的字段路径',
                ],
                'queue_name' => [
                    'type' => 'string',
                    'title' => '队列名称',
                    'description' => '监听的消息队列名称',
                ],
                'priority_threshold' => [
                    'type' => 'integer',
                    'title' => '优先级阈值',
                    'description' => '只处理优先级大于等于此值的消息',
                    'minimum' => 0,
                    'maximum' => 100,
                    'default' => 0,
                ],
                'deduplication_enabled' => [
                    'type' => 'boolean',
                    'title' => '启用去重',
                    'description' => '是否对相同消息进行去重',
                    'default' => false,
                ],
                'deduplication_key' => [
                    'type' => 'string',
                    'title' => '去重键',
                    'description' => '用于去重的字段路径',
                ],
                'deduplication_ttl' => [
                    'type' => 'integer',
                    'title' => '去重有效期（秒）',
                    'description' => '去重记录的有效期',
                    'minimum' => 1,
                    'maximum' => 86400,
                    'default' => 3600,
                ],
            ],
            'required' => ['message_name'],
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

        // Check message name
        if (!empty($config['message_name'])) {
            $messageName = $data['message_name'] ?? $data['name'] ?? $context->getMeta('message_name');
            if ($messageName !== $config['message_name']) {
                return false;
            }
        }

        // Check message type
        $configType = $config['message_type'] ?? self::MSG_TYPE_ANY;
        if ($configType !== self::MSG_TYPE_ANY) {
            $messageType = $data['message_type'] ?? $data['type'] ?? self::MSG_TYPE_EVENT;
            if ($messageType !== $configType) {
                return false;
            }
        }

        // Check source filter
        if (!empty($config['source_filter'])) {
            if (!$this->checkSourceFilter($data, $config['source_filter'])) {
                return false;
            }
        }

        // Check content match
        if (!empty($config['content_match'])) {
            $content = $data['content'] ?? $data['payload'] ?? $data;
            if (!$this->checkContentMatch($content, $config['content_match'])) {
                return false;
            }
        }

        // Check priority threshold
        $priorityThreshold = $config['priority_threshold'] ?? 0;
        $messagePriority = $data['priority'] ?? 0;
        if ($messagePriority < $priorityThreshold) {
            return false;
        }

        return true;
    }

    /**
     * Check source filter | 检查来源过滤
     *
     * @param array $data
     * @param array $filter
     * @return bool
     */
    protected function checkSourceFilter(array $data, array $filter): bool
    {
        $messageSource = $data['source'] ?? $data['from'] ?? null;

        // Check source type
        if (!empty($filter['type'])) {
            $sourceType = $data['source_type'] ?? null;
            if ($sourceType !== $filter['type']) {
                return false;
            }
        }

        // Check allowed sources
        if (!empty($filter['allowed_sources']) && $messageSource !== null) {
            if (!in_array($messageSource, $filter['allowed_sources'], true)) {
                return false;
            }
        }

        // Check blocked sources
        if (!empty($filter['blocked_sources']) && $messageSource !== null) {
            if (in_array($messageSource, $filter['blocked_sources'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check content match | 检查内容匹配
     *
     * @param array $content
     * @param array $rules
     * @return bool
     */
    protected function checkContentMatch(array $content, array $rules): bool
    {
        foreach ($rules as $rule) {
            $field = $rule['field'] ?? null;
            $pattern = $rule['pattern'] ?? null;
            $matchType = $rule['match_type'] ?? 'exact';

            if ($field === null || $pattern === null) {
                continue;
            }

            $value = $this->getNestedValue($content, $field);

            if (!$this->matchValue((string)$value, $pattern, $matchType)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Match value against pattern | 匹配值和模式
     *
     * @param string $value
     * @param string $pattern
     * @param string $matchType
     * @return bool
     */
    protected function matchValue(string $value, string $pattern, string $matchType): bool
    {
        return match ($matchType) {
            'exact' => $value === $pattern,
            'contains' => str_contains($value, $pattern),
            'regex' => (bool)preg_match($pattern, $value),
            'prefix' => str_starts_with($value, $pattern),
            'suffix' => str_ends_with($value, $pattern),
            default => $value === $pattern,
        };
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

        // Extract correlation value if configured
        $correlationValue = null;
        if (!empty($config['correlation_key'])) {
            $correlationValue = $this->getNestedValue($data, $config['correlation_key']);
        }

        // Build trigger data
        $triggerData = [
            'trigger_type' => $this->getType(),
            'trigger_name' => $this->getName(),
            'triggered_at' => date('Y-m-d H:i:s'),
            'tenant_id' => $context->getTenantId(),
            'message_name' => $data['message_name'] ?? $data['name'] ?? $config['message_name'] ?? null,
            'message_type' => $data['message_type'] ?? $data['type'] ?? self::MSG_TYPE_EVENT,
            'message_id' => $data['message_id'] ?? $data['id'] ?? uniqid('msg_'),
            'message_source' => $data['source'] ?? $data['from'] ?? null,
            'message_content' => $data['content'] ?? $data['payload'] ?? $data,
            'correlation_key' => $config['correlation_key'] ?? null,
            'correlation_value' => $correlationValue,
            'priority' => $data['priority'] ?? 0,
            'received_at' => $data['timestamp'] ?? date('Y-m-d H:i:s'),
        ];

        return TriggerResult::success($triggerData)
            ->setMeta('message_name', $triggerData['message_name'])
            ->setMeta('message_id', $triggerData['message_id'])
            ->setMeta('correlation_value', $correlationValue);
    }

    /**
     * Generate deduplication key | 生成去重键
     *
     * @param array $data
     * @param array $config
     * @return string|null
     */
    public function generateDeduplicationKey(array $data, array $config): ?string
    {
        if (empty($config['deduplication_enabled'])) {
            return null;
        }

        $keyField = $config['deduplication_key'] ?? 'message_id';
        $keyValue = $this->getNestedValue($data, $keyField);

        if ($keyValue === null) {
            return null;
        }

        return sprintf(
            'msg_dedup:%s:%s',
            $config['message_name'] ?? 'unknown',
            md5(serialize($keyValue))
        );
    }
}
