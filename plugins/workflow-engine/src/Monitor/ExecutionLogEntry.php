<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Monitor;

/**
 * 执行日志条目
 *
 * 表示工作流执行过程中的单个日志条目。
 *
 * @package Alkaid\Workflow\Engine\Monitor
 */
class ExecutionLogEntry
{
    /**
     * 日志级别常量
     */
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_CRITICAL = 'critical';

    /**
     * 事件类型常量
     */
    public const EVENT_INSTANCE_STARTED = 'instance.started';
    public const EVENT_INSTANCE_COMPLETED = 'instance.completed';
    public const EVENT_INSTANCE_FAILED = 'instance.failed';
    public const EVENT_INSTANCE_SUSPENDED = 'instance.suspended';
    public const EVENT_INSTANCE_RESUMED = 'instance.resumed';
    public const EVENT_INSTANCE_CANCELLED = 'instance.cancelled';
    public const EVENT_NODE_ENTERED = 'node.entered';
    public const EVENT_NODE_EXECUTING = 'node.executing';
    public const EVENT_NODE_COMPLETED = 'node.completed';
    public const EVENT_NODE_FAILED = 'node.failed';
    public const EVENT_NODE_SKIPPED = 'node.skipped';
    public const EVENT_TASK_CREATED = 'task.created';
    public const EVENT_TASK_ASSIGNED = 'task.assigned';
    public const EVENT_TASK_COMPLETED = 'task.completed';
    public const EVENT_VARIABLE_CHANGED = 'variable.changed';
    public const EVENT_TRANSITION_TAKEN = 'transition.taken';

    private string $id;
    private string $instanceId;
    private ?string $nodeId;
    private ?string $nodeType;
    private string $eventType;
    private string $level;
    private string $message;
    private array $context;
    private ?int $tenantId;
    private ?int $userId;
    private float $timestamp;
    private ?float $duration;
    private array $metadata;

    /**
     * 构造函数
     *
     * @param string $instanceId 实例 ID
     * @param string $eventType 事件类型
     * @param string $message 消息
     * @param array $options 选项
     */
    public function __construct(
        string $instanceId,
        string $eventType,
        string $message,
        array $options = []
    ) {
        $this->id = $options['id'] ?? $this->generateId();
        $this->instanceId = $instanceId;
        $this->nodeId = $options['node_id'] ?? null;
        $this->nodeType = $options['node_type'] ?? null;
        $this->eventType = $eventType;
        $this->level = $options['level'] ?? self::LEVEL_INFO;
        $this->message = $message;
        $this->context = $options['context'] ?? [];
        $this->tenantId = $options['tenant_id'] ?? null;
        $this->userId = $options['user_id'] ?? null;
        $this->timestamp = $options['timestamp'] ?? microtime(true);
        $this->duration = $options['duration'] ?? null;
        $this->metadata = $options['metadata'] ?? [];
    }

    /**
     * 创建实例启动日志
     *
     * @param string $instanceId 实例 ID
     * @param string $definitionId 定义 ID
     * @param array $variables 初始变量
     * @param array $options 选项
     * @return self
     */
    public static function instanceStarted(
        string $instanceId,
        string $definitionId,
        array $variables = [],
        array $options = []
    ): self {
        return new self(
            $instanceId,
            self::EVENT_INSTANCE_STARTED,
            "工作流实例已启动",
            array_merge($options, [
                'context' => [
                    'definition_id' => $definitionId,
                    'initial_variables' => $variables,
                ],
            ])
        );
    }

    /**
     * 创建实例完成日志
     *
     * @param string $instanceId 实例 ID
     * @param array $result 执行结果
     * @param float $duration 执行时长
     * @param array $options 选项
     * @return self
     */
    public static function instanceCompleted(
        string $instanceId,
        array $result = [],
        float $duration = 0,
        array $options = []
    ): self {
        return new self(
            $instanceId,
            self::EVENT_INSTANCE_COMPLETED,
            "工作流实例已完成",
            array_merge($options, [
                'context' => ['result' => $result],
                'duration' => $duration,
            ])
        );
    }

    /**
     * 创建实例失败日志
     *
     * @param string $instanceId 实例 ID
     * @param string $errorCode 错误码
     * @param string $errorMessage 错误消息
     * @param array $options 选项
     * @return self
     */
    public static function instanceFailed(
        string $instanceId,
        string $errorCode,
        string $errorMessage,
        array $options = []
    ): self {
        return new self(
            $instanceId,
            self::EVENT_INSTANCE_FAILED,
            "工作流实例执行失败: {$errorMessage}",
            array_merge($options, [
                'level' => self::LEVEL_ERROR,
                'context' => [
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                ],
            ])
        );
    }

    /**
     * 创建节点进入日志
     *
     * @param string $instanceId 实例 ID
     * @param string $nodeId 节点 ID
     * @param string $nodeType 节点类型
     * @param array $options 选项
     * @return self
     */
    public static function nodeEntered(
        string $instanceId,
        string $nodeId,
        string $nodeType,
        array $options = []
    ): self {
        return new self(
            $instanceId,
            self::EVENT_NODE_ENTERED,
            "进入节点: {$nodeId} ({$nodeType})",
            array_merge($options, [
                'node_id' => $nodeId,
                'node_type' => $nodeType,
            ])
        );
    }

    /**
     * 创建节点执行中日志
     *
     * @param string $instanceId 实例 ID
     * @param string $nodeId 节点 ID
     * @param string $nodeType 节点类型
     * @param array $context 执行上下文
     * @param array $options 选项
     * @return self
     */
    public static function nodeExecuting(
        string $instanceId,
        string $nodeId,
        string $nodeType,
        array $context = [],
        array $options = []
    ): self {
        return new self(
            $instanceId,
            self::EVENT_NODE_EXECUTING,
            "执行节点: {$nodeId}",
            array_merge($options, [
                'node_id' => $nodeId,
                'node_type' => $nodeType,
                'context' => $context,
            ])
        );
    }

    /**
     * 创建节点完成日志
     *
     * @param string $instanceId 实例 ID
     * @param string $nodeId 节点 ID
     * @param string $nodeType 节点类型
     * @param array $result 执行结果
     * @param float $duration 执行时长
     * @param array $options 选项
     * @return self
     */
    public static function nodeCompleted(
        string $instanceId,
        string $nodeId,
        string $nodeType,
        array $result = [],
        float $duration = 0,
        array $options = []
    ): self {
        return new self(
            $instanceId,
            self::EVENT_NODE_COMPLETED,
            "节点执行完成: {$nodeId}",
            array_merge($options, [
                'node_id' => $nodeId,
                'node_type' => $nodeType,
                'context' => ['result' => $result],
                'duration' => $duration,
            ])
        );
    }

    /**
     * 创建节点失败日志
     *
     * @param string $instanceId 实例 ID
     * @param string $nodeId 节点 ID
     * @param string $nodeType 节点类型
     * @param string $errorCode 错误码
     * @param string $errorMessage 错误消息
     * @param array $options 选项
     * @return self
     */
    public static function nodeFailed(
        string $instanceId,
        string $nodeId,
        string $nodeType,
        string $errorCode,
        string $errorMessage,
        array $options = []
    ): self {
        return new self(
            $instanceId,
            self::EVENT_NODE_FAILED,
            "节点执行失败: {$nodeId} - {$errorMessage}",
            array_merge($options, [
                'level' => self::LEVEL_ERROR,
                'node_id' => $nodeId,
                'node_type' => $nodeType,
                'context' => [
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                ],
            ])
        );
    }

    /**
     * 创建任务创建日志
     *
     * @param string $instanceId 实例 ID
     * @param string $taskId 任务 ID
     * @param string $nodeId 节点 ID
     * @param array $assignees 处理人
     * @param array $options 选项
     * @return self
     */
    public static function taskCreated(
        string $instanceId,
        string $taskId,
        string $nodeId,
        array $assignees = [],
        array $options = []
    ): self {
        return new self(
            $instanceId,
            self::EVENT_TASK_CREATED,
            "任务已创建: {$taskId}",
            array_merge($options, [
                'node_id' => $nodeId,
                'context' => [
                    'task_id' => $taskId,
                    'assignees' => $assignees,
                ],
            ])
        );
    }

    /**
     * 创建变量变更日志
     *
     * @param string $instanceId 实例 ID
     * @param string $variableName 变量名
     * @param mixed $oldValue 旧值
     * @param mixed $newValue 新值
     * @param array $options 选项
     * @return self
     */
    public static function variableChanged(
        string $instanceId,
        string $variableName,
        mixed $oldValue,
        mixed $newValue,
        array $options = []
    ): self {
        return new self(
            $instanceId,
            self::EVENT_VARIABLE_CHANGED,
            "变量已变更: {$variableName}",
            array_merge($options, [
                'level' => self::LEVEL_DEBUG,
                'context' => [
                    'variable_name' => $variableName,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                ],
            ])
        );
    }

    // ========== Getters ==========

    public function getId(): string
    {
        return $this->id;
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function getNodeId(): ?string
    {
        return $this->nodeId;
    }

    public function getNodeType(): ?string
    {
        return $this->nodeType;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function getDuration(): ?float
    {
        return $this->duration;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * 获取格式化的时间戳
     *
     * @param string $format 时间格式
     * @return string
     */
    public function getFormattedTimestamp(string $format = 'Y-m-d H:i:s.u'): string
    {
        return \DateTime::createFromFormat('U.u', sprintf('%.6f', $this->timestamp))
            ->format($format);
    }

    /**
     * 设置元数据
     *
     * @param string $key 键
     * @param mixed $value 值
     * @return self
     */
    public function setMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * 转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'instance_id' => $this->instanceId,
            'node_id' => $this->nodeId,
            'node_type' => $this->nodeType,
            'event_type' => $this->eventType,
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'timestamp' => $this->timestamp,
            'formatted_timestamp' => $this->getFormattedTimestamp(),
            'duration' => $this->duration,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * 从数组创建
     *
     * @param array $data 数据
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['instance_id'],
            $data['event_type'],
            $data['message'],
            [
                'id' => $data['id'] ?? null,
                'node_id' => $data['node_id'] ?? null,
                'node_type' => $data['node_type'] ?? null,
                'level' => $data['level'] ?? self::LEVEL_INFO,
                'context' => $data['context'] ?? [],
                'tenant_id' => $data['tenant_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'timestamp' => $data['timestamp'] ?? microtime(true),
                'duration' => $data['duration'] ?? null,
                'metadata' => $data['metadata'] ?? [],
            ]
        );
    }

    /**
     * 生成唯一 ID
     *
     * @return string
     */
    private function generateId(): string
    {
        return sprintf(
            'log_%s_%s',
            date('YmdHis'),
            substr(md5(uniqid((string) mt_rand(), true)), 0, 8)
        );
    }
}
