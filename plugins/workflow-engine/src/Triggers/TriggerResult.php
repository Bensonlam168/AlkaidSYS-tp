<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers;

/**
 * 触发结果
 *
 * 封装触发器执行的结果，包括：
 * - 执行状态（成功/失败/跳过）
 * - 输出数据
 * - 错误信息
 * - 工作流实例ID（如果已创建）
 *
 * @package Alkaid\Workflow\Engine\Triggers
 */
class TriggerResult
{
    /**
     * 状态常量
     */
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_SKIPPED = 'SKIPPED';
    public const STATUS_PENDING = 'PENDING';

    /**
     * 执行状态
     */
    protected string $status;

    /**
     * 是否成功
     */
    protected bool $success;

    /**
     * 输出数据
     */
    protected array $data = [];

    /**
     * 错误消息
     */
    protected ?string $error = null;

    /**
     * 错误代码
     */
    protected ?int $errorCode = null;

    /**
     * 工作流实例ID（如果已创建）
     */
    protected ?string $instanceId = null;

    /**
     * 触发器类型
     */
    protected ?string $triggerType = null;

    /**
     * 工作流ID
     */
    protected ?string $workflowId = null;

    /**
     * 执行开始时间
     */
    protected float $startTime;

    /**
     * 执行结束时间
     */
    protected ?float $endTime = null;

    /**
     * 元数据
     */
    protected array $metadata = [];

    /**
     * 构造函数
     *
     * @param string $status 状态
     * @param bool $success 是否成功
     */
    public function __construct(string $status = self::STATUS_SUCCESS, bool $success = true)
    {
        $this->status = $status;
        $this->success = $success;
        $this->startTime = microtime(true);
    }

    /**
     * 静态工厂方法 - 创建成功结果
     *
     * @param array $data 输出数据
     * @param string|null $instanceId 工作流实例ID
     * @return static
     */
    public static function success(array $data = [], ?string $instanceId = null): static
    {
        $result = new static(self::STATUS_SUCCESS, true);
        $result->data = $data;
        $result->instanceId = $instanceId;
        return $result->markComplete();
    }

    /**
     * 静态工厂方法 - 创建失败结果
     *
     * @param string $error 错误消息
     * @param int|null $errorCode 错误代码
     * @return static
     */
    public static function failed(string $error, ?int $errorCode = null): static
    {
        $result = new static(self::STATUS_FAILED, false);
        $result->error = $error;
        $result->errorCode = $errorCode;
        return $result->markComplete();
    }

    /**
     * 静态工厂方法 - 创建跳过结果
     *
     * @param string $reason 跳过原因
     * @return static
     */
    public static function skipped(string $reason = ''): static
    {
        $result = new static(self::STATUS_SKIPPED, true);
        $result->data = ['reason' => $reason];
        return $result->markComplete();
    }

    /**
     * 静态工厂方法 - 创建挂起结果
     *
     * @param array $data 数据
     * @param string|null $message 消息
     * @return static
     */
    public static function pending(array $data = [], ?string $message = null): static
    {
        $result = new static(self::STATUS_PENDING, true);
        $result->data = $data;
        if ($message) {
            $result->setMeta('message', $message);
        }
        return $result;
    }

    /**
     * 从异常创建结果
     *
     * @param \Throwable $exception 异常
     * @return static
     */
    public static function fromException(\Throwable $exception): static
    {
        $result = new static(self::STATUS_FAILED, false);
        $result->error = $exception->getMessage();
        $result->errorCode = $exception->getCode();
        $result->setMeta('exception_class', get_class($exception));
        $result->setMeta('exception_trace', $exception->getTraceAsString());
        return $result->markComplete();
    }

    // ==================== 状态检查 ====================

    /**
     * 是否成功
     */
    public function isSuccess(): bool
    {
        return $this->success && $this->status === self::STATUS_SUCCESS;
    }

    /**
     * 是否失败
     */
    public function isFailed(): bool
    {
        return !$this->success || $this->status === self::STATUS_FAILED;
    }

    /**
     * 是否跳过
     */
    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    /**
     * 是否挂起
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    // ==================== Getters ====================

    /**
     * 获取状态
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * 获取输出数据
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
     * 获取错误消息
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * 获取错误代码
     */
    public function getErrorCode(): ?int
    {
        return $this->errorCode;
    }

    /**
     * 获取工作流实例ID
     */
    public function getInstanceId(): ?string
    {
        return $this->instanceId;
    }

    /**
     * 获取触发器类型
     */
    public function getTriggerType(): ?string
    {
        return $this->triggerType;
    }

    /**
     * 获取工作流ID
     */
    public function getWorkflowId(): ?string
    {
        return $this->workflowId;
    }

    /**
     * 获取执行开始时间
     */
    public function getStartTime(): float
    {
        return $this->startTime;
    }

    /**
     * 获取执行结束时间
     */
    public function getEndTime(): ?float
    {
        return $this->endTime;
    }

    /**
     * 获取执行时长（毫秒）
     */
    public function getDuration(): float
    {
        $endTime = $this->endTime ?? microtime(true);
        return ($endTime - $this->startTime) * 1000;
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

    // ==================== Setters (Fluent) ====================

    /**
     * 设置状态
     */
    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->success = in_array($status, [self::STATUS_SUCCESS, self::STATUS_SKIPPED, self::STATUS_PENDING]);
        return $this;
    }

    /**
     * 设置输出数据
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
     * 设置错误消息
     */
    public function setError(?string $error): static
    {
        $this->error = $error;
        return $this;
    }

    /**
     * 设置错误代码
     */
    public function setErrorCode(?int $errorCode): static
    {
        $this->errorCode = $errorCode;
        return $this;
    }

    /**
     * 设置工作流实例ID
     */
    public function setInstanceId(?string $instanceId): static
    {
        $this->instanceId = $instanceId;
        return $this;
    }

    /**
     * 设置触发器类型
     */
    public function setTriggerType(?string $triggerType): static
    {
        $this->triggerType = $triggerType;
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
     * 标记完成
     */
    public function markComplete(): static
    {
        $this->endTime = microtime(true);
        return $this;
    }

    // ==================== 序列化 ====================

    /**
     * 转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'success' => $this->success,
            'data' => $this->data,
            'error' => $this->error,
            'error_code' => $this->errorCode,
            'instance_id' => $this->instanceId,
            'trigger_type' => $this->triggerType,
            'workflow_id' => $this->workflowId,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'duration' => $this->getDuration(),
            'metadata' => $this->metadata,
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

    /**
     * 从数组创建
     *
     * @param array $data 数据
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $result = new static(
            $data['status'] ?? self::STATUS_SUCCESS,
            $data['success'] ?? true
        );

        $result->data = $data['data'] ?? [];
        $result->error = $data['error'] ?? null;
        $result->errorCode = $data['error_code'] ?? null;
        $result->instanceId = $data['instance_id'] ?? null;
        $result->triggerType = $data['trigger_type'] ?? null;
        $result->workflowId = $data['workflow_id'] ?? null;
        $result->metadata = $data['metadata'] ?? [];

        if (isset($data['start_time'])) {
            $result->startTime = $data['start_time'];
        }
        if (isset($data['end_time'])) {
            $result->endTime = $data['end_time'];
        }

        return $result;
    }
}
