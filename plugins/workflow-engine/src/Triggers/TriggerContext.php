<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;

/**
 * 触发上下文
 *
 * 封装触发器执行时的上下文信息，包括：
 * - 触发来源（手动、Webhook、定时等）
 * - 请求数据
 * - 租户信息
 * - 用户信息
 * - 元数据
 *
 * @package Alkaid\Workflow\Engine\Triggers
 */
class TriggerContext
{
    /**
     * 触发来源常量
     */
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_SCHEDULE = 'schedule';
    public const SOURCE_DATA_EVENT = 'data_event';
    public const SOURCE_API = 'api';
    public const SOURCE_INTERNAL = 'internal';

    /**
     * 触发器类型
     */
    protected string $triggerType;

    /**
     * 触发来源
     */
    protected string $source;

    /**
     * 工作流ID
     */
    protected ?string $workflowId = null;

    /**
     * 租户ID
     */
    protected ?int $tenantId = null;

    /**
     * 用户ID
     */
    protected ?int $userId = null;

    /**
     * 请求数据
     */
    protected array $data = [];

    /**
     * 请求头（用于 Webhook）
     */
    protected array $headers = [];

    /**
     * 查询参数
     */
    protected array $query = [];

    /**
     * 元数据
     */
    protected array $metadata = [];

    /**
     * 触发时间戳
     */
    protected float $triggeredAt;

    /**
     * IP地址
     */
    protected ?string $ipAddress = null;

    /**
     * 用户代理
     */
    protected ?string $userAgent = null;

    /**
     * 事件名称（用于数据事件）
     */
    protected ?string $eventName = null;

    /**
     * 事件数据（用于数据事件）
     */
    protected array $eventData = [];

    /**
     * Cron 表达式（用于定时触发）
     */
    protected ?string $cronExpression = null;

    /**
     * 计划执行时间（用于定时触发）
     */
    protected ?float $scheduledTime = null;

    /**
     * 构造函数
     *
     * @param string $triggerType 触发器类型
     * @param string $source 触发来源
     */
    public function __construct(string $triggerType = '', string $source = '')
    {
        $this->triggerType = $triggerType;
        $this->source = $source;
        $this->triggeredAt = microtime(true);
    }

    /**
     * 静态工厂方法 - 创建空上下文
     *
     * @return static
     */
    public static function create(): static
    {
        return new static();
    }

    /**
     * 静态工厂方法 - 从数组创建
     *
     * @param array $data 数据
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $context = new static(
            $data['trigger_type'] ?? '',
            $data['source'] ?? ''
        );

        if (isset($data['workflow_id'])) {
            $context->setWorkflowId($data['workflow_id']);
        }
        if (isset($data['tenant_id'])) {
            $context->setTenantId($data['tenant_id']);
        }
        if (isset($data['user_id'])) {
            $context->setUserId($data['user_id']);
        }
        if (isset($data['data'])) {
            $context->setData($data['data']);
        }
        if (isset($data['headers'])) {
            $context->setHeaders($data['headers']);
        }
        if (isset($data['query'])) {
            $context->setQuery($data['query']);
        }
        if (isset($data['metadata'])) {
            $context->setMetadata($data['metadata']);
        }
        if (isset($data['ip_address'])) {
            $context->setIpAddress($data['ip_address']);
        }
        if (isset($data['user_agent'])) {
            $context->setUserAgent($data['user_agent']);
        }
        if (isset($data['event_name'])) {
            $context->setEventName($data['event_name']);
        }
        if (isset($data['event_data'])) {
            $context->setEventData($data['event_data']);
        }
        if (isset($data['cron_expression'])) {
            $context->setCronExpression($data['cron_expression']);
        }
        if (isset($data['scheduled_time'])) {
            $context->setScheduledTime($data['scheduled_time']);
        }

        return $context;
    }

    /**
     * 静态工厂方法 - 创建手动触发上下文
     *
     * @param string $workflowId 工作流ID
     * @param int|null $tenantId 租户ID
     * @param int|null $userId 用户ID
     * @param array $data 数据
     * @return static
     */
    public static function manual(
        string $workflowId,
        ?int $tenantId = null,
        ?int $userId = null,
        array $data = []
    ): static {
        return (new static(TriggerInterface::TYPE_MANUAL, self::SOURCE_MANUAL))
            ->setWorkflowId($workflowId)
            ->setTenantId($tenantId)
            ->setUserId($userId)
            ->setData($data);
    }

    /**
     * 静态工厂方法 - 创建 Webhook 触发上下文
     *
     * @param string $workflowId 工作流ID
     * @param array $data 请求体数据
     * @param array $headers 请求头
     * @param array $query 查询参数
     * @return static
     */
    public static function webhook(
        string $workflowId,
        array $data = [],
        array $headers = [],
        array $query = []
    ): static {
        return (new static(TriggerInterface::TYPE_WEBHOOK, self::SOURCE_WEBHOOK))
            ->setWorkflowId($workflowId)
            ->setData($data)
            ->setHeaders($headers)
            ->setQuery($query);
    }

    /**
     * 静态工厂方法 - 创建定时触发上下文
     *
     * @param string $workflowId 工作流ID
     * @param string $cronExpression Cron 表达式
     * @param float|null $scheduledTime 计划执行时间
     * @return static
     */
    public static function schedule(
        string $workflowId,
        string $cronExpression,
        ?float $scheduledTime = null
    ): static {
        return (new static(TriggerInterface::TYPE_SCHEDULE, self::SOURCE_SCHEDULE))
            ->setWorkflowId($workflowId)
            ->setCronExpression($cronExpression)
            ->setScheduledTime($scheduledTime ?? microtime(true));
    }

    /**
     * 静态工厂方法 - 创建数据事件触发上下文
     *
     * @param string $workflowId 工作流ID
     * @param string $eventName 事件名称
     * @param array $eventData 事件数据
     * @param int|null $tenantId 租户ID
     * @return static
     */
    public static function dataEvent(
        string $workflowId,
        string $eventName,
        array $eventData = [],
        ?int $tenantId = null
    ): static {
        return (new static(TriggerInterface::TYPE_DATA_EVENT, self::SOURCE_DATA_EVENT))
            ->setWorkflowId($workflowId)
            ->setEventName($eventName)
            ->setEventData($eventData)
            ->setTenantId($tenantId);
    }

    // ==================== Getters ====================

    /**
     * 获取触发器类型
     */
    public function getTriggerType(): string
    {
        return $this->triggerType;
    }

    /**
     * 获取触发来源
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * 获取工作流ID
     */
    public function getWorkflowId(): ?string
    {
        return $this->workflowId;
    }

    /**
     * 获取租户ID
     */
    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    /**
     * 获取用户ID
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * 获取请求数据
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * 获取单个数据项
     *
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * 检查数据是否存在
     *
     * @param string $key 键名
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * 获取请求头
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * 获取单个请求头
     *
     * @param string $name 头名称
     * @param string|null $default 默认值
     * @return string|null
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        $name = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }
        return $default;
    }

    /**
     * 获取查询参数
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * 获取单个查询参数
     *
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getQueryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * 获取元数据
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * 获取单个元数据
     *
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * 获取触发时间戳
     */
    public function getTriggeredAt(): float
    {
        return $this->triggeredAt;
    }

    /**
     * 获取IP地址
     */
    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /**
     * 获取用户代理
     */
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    /**
     * 获取事件名称
     */
    public function getEventName(): ?string
    {
        return $this->eventName;
    }

    /**
     * 获取事件数据
     */
    public function getEventData(): array
    {
        return $this->eventData;
    }

    /**
     * 获取 Cron 表达式
     */
    public function getCronExpression(): ?string
    {
        return $this->cronExpression;
    }

    /**
     * 获取计划执行时间
     */
    public function getScheduledTime(): ?float
    {
        return $this->scheduledTime;
    }

    // ==================== Setters (Fluent) ====================

    /**
     * 设置触发器类型
     */
    public function setTriggerType(string $triggerType): static
    {
        $this->triggerType = $triggerType;
        return $this;
    }

    /**
     * 设置触发来源
     */
    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    /**
     * 设置工作流ID
     */
    public function setWorkflowId(?string $workflowId): static
    {
        $this->workflowId = $workflowId;
        return $this;
    }

    /**
     * 设置租户ID
     */
    public function setTenantId(?int $tenantId): static
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    /**
     * 设置用户ID
     */
    public function setUserId(?int $userId): static
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * 设置请求数据
     */
    public function setData(array $data): static
    {
        $this->data = $data;
        return $this;
    }

    /**
     * 设置单个数据项
     */
    public function set(string $key, mixed $value): static
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * 合并数据
     */
    public function mergeData(array $data): static
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * 设置请求头
     */
    public function setHeaders(array $headers): static
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * 设置单个请求头
     */
    public function setHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * 设置查询参数
     */
    public function setQuery(array $query): static
    {
        $this->query = $query;
        return $this;
    }

    /**
     * 设置元数据
     */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * 设置单个元数据
     */
    public function setMeta(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * 设置IP地址
     */
    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    /**
     * 设置用户代理
     */
    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    /**
     * 设置事件名称
     */
    public function setEventName(?string $eventName): static
    {
        $this->eventName = $eventName;
        return $this;
    }

    /**
     * 设置事件数据
     */
    public function setEventData(array $eventData): static
    {
        $this->eventData = $eventData;
        return $this;
    }

    /**
     * 设置 Cron 表达式
     */
    public function setCronExpression(?string $cronExpression): static
    {
        $this->cronExpression = $cronExpression;
        return $this;
    }

    /**
     * 设置计划执行时间
     */
    public function setScheduledTime(?float $scheduledTime): static
    {
        $this->scheduledTime = $scheduledTime;
        return $this;
    }

    // ==================== 辅助方法 ====================

    /**
     * 检查是否为手动触发
     */
    public function isManual(): bool
    {
        return $this->triggerType === TriggerInterface::TYPE_MANUAL;
    }

    /**
     * 检查是否为 Webhook 触发
     */
    public function isWebhook(): bool
    {
        return $this->triggerType === TriggerInterface::TYPE_WEBHOOK;
    }

    /**
     * 检查是否为定时触发
     */
    public function isSchedule(): bool
    {
        return $this->triggerType === TriggerInterface::TYPE_SCHEDULE;
    }

    /**
     * 检查是否为数据事件触发
     */
    public function isDataEvent(): bool
    {
        return $this->triggerType === TriggerInterface::TYPE_DATA_EVENT;
    }

    /**
     * 转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'trigger_type' => $this->triggerType,
            'source' => $this->source,
            'workflow_id' => $this->workflowId,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'data' => $this->data,
            'headers' => $this->headers,
            'query' => $this->query,
            'metadata' => $this->metadata,
            'triggered_at' => $this->triggeredAt,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'event_name' => $this->eventName,
            'event_data' => $this->eventData,
            'cron_expression' => $this->cronExpression,
            'scheduled_time' => $this->scheduledTime,
        ];
    }

    /**
     * 转换为 JSON
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
