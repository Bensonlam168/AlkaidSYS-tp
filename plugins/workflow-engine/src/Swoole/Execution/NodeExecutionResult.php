<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Swoole\Execution;

/**
 * 节点执行结果类
 *
 * 封装工作流节点执行后的结果信息，包括执行状态、返回数据、
 * 下一个节点ID和错误信息等。支持链式调用和状态检查。
 *
 * @package Alkaid\Workflow\Engine\Swoole\Execution
 */
class NodeExecutionResult
{
    /**
     * 执行成功状态
     * 节点执行完成，可以继续执行下一个节点
     */
    public const STATUS_SUCCESS = 'SUCCESS';

    /**
     * 执行挂起状态
     * 节点需要等待外部事件（如人工审批）才能继续
     */
    public const STATUS_PENDING = 'PENDING';

    /**
     * 执行失败状态
     * 节点执行出错，需要错误处理
     */
    public const STATUS_FAILED = 'FAILED';

    /**
     * 执行跳过状态
     * 节点被条件跳过，不执行
     */
    public const STATUS_SKIPPED = 'SKIPPED';

    /**
     * 执行取消状态
     * 节点执行被取消
     */
    public const STATUS_CANCELLED = 'CANCELLED';

    /**
     * 执行状态
     *
     * @var string
     */
    protected string $status;

    /**
     * 执行结果数据
     *
     * @var array
     */
    protected array $data;

    /**
     * 下一个要执行的节点ID
     *
     * @var string|null
     */
    protected ?string $nextNodeId;

    /**
     * 错误信息
     *
     * @var string|null
     */
    protected ?string $error;

    /**
     * 错误码
     *
     * @var int|null
     */
    protected ?int $errorCode;

    /**
     * 错误堆栈
     *
     * @var string|null
     */
    protected ?string $errorStack;

    /**
     * 执行耗时（毫秒）
     *
     * @var float
     */
    protected float $duration = 0.0;

    /**
     * 执行开始时间戳
     *
     * @var float
     */
    protected float $startTime;

    /**
     * 执行结束时间戳
     *
     * @var float
     */
    protected float $endTime;

    /**
     * 节点ID
     *
     * @var string|null
     */
    protected ?string $nodeId = null;

    /**
     * 节点名称
     *
     * @var string|null
     */
    protected ?string $nodeName = null;

    /**
     * 重试次数
     *
     * @var int
     */
    protected int $retryCount = 0;

    /**
     * 最大重试次数
     *
     * @var int
     */
    protected int $maxRetries = 3;

    /**
     * 是否可重试
     *
     * @var bool
     */
    protected bool $retryable = true;

    /**
     * 元数据
     *
     * @var array
     */
    protected array $metadata = [];

    /**
     * 构造函数
     *
     * @param string $status 执行状态
     * @param array $data 结果数据
     * @param string|null $nextNodeId 下一个节点ID
     * @param string|null $error 错误信息
     */
    public function __construct(
        string $status = self::STATUS_SUCCESS,
        array $data = [],
        ?string $nextNodeId = null,
        ?string $error = null
    ) {
        $this->status = $status;
        $this->data = $data;
        $this->nextNodeId = $nextNodeId;
        $this->error = $error;
        $this->errorCode = null;
        $this->errorStack = null;
        $this->startTime = microtime(true);
        $this->endTime = $this->startTime;
    }

    /**
     * 创建成功结果
     *
     * @param array $data 结果数据
     * @param string|null $nextNodeId 下一个节点ID
     * @return static
     */
    public static function success(array $data = [], ?string $nextNodeId = null): static
    {
        $result = new static(self::STATUS_SUCCESS, $data, $nextNodeId);
        $result->markComplete();
        return $result;
    }

    /**
     * 创建挂起结果
     *
     * @param array $data 结果数据
     * @param string|null $message 挂起原因
     * @return static
     */
    public static function pending(array $data = [], ?string $message = null): static
    {
        $result = new static(self::STATUS_PENDING, $data, null, $message);
        $result->markComplete();
        return $result;
    }

    /**
     * 创建失败结果
     *
     * @param string $error 错误信息
     * @param int|null $errorCode 错误码
     * @param string|null $errorStack 错误堆栈
     * @return static
     */
    public static function failed(string $error, ?int $errorCode = null, ?string $errorStack = null): static
    {
        $result = new static(self::STATUS_FAILED, [], null, $error);
        $result->errorCode = $errorCode;
        $result->errorStack = $errorStack;
        $result->markComplete();
        return $result;
    }

    /**
     * 创建跳过结果
     *
     * @param string|null $reason 跳过原因
     * @param string|null $nextNodeId 下一个节点ID
     * @return static
     */
    public static function skipped(?string $reason = null, ?string $nextNodeId = null): static
    {
        $result = new static(self::STATUS_SKIPPED, ['reason' => $reason], $nextNodeId);
        $result->markComplete();
        return $result;
    }

    /**
     * 创建取消结果
     *
     * @param string|null $reason 取消原因
     * @return static
     */
    public static function cancelled(?string $reason = null): static
    {
        $result = new static(self::STATUS_CANCELLED, ['reason' => $reason]);
        $result->markComplete();
        return $result;
    }

    /**
     * 从异常创建失败结果
     *
     * @param \Throwable $exception 异常对象
     * @return static
     */
    public static function fromException(\Throwable $exception): static
    {
        return static::failed(
            $exception->getMessage(),
            $exception->getCode(),
            $exception->getTraceAsString()
        );
    }

    /**
     * 标记执行完成
     *
     * @return $this
     */
    public function markComplete(): static
    {
        $this->endTime = microtime(true);
        $this->duration = ($this->endTime - $this->startTime) * 1000; // 转换为毫秒
        return $this;
    }

    /**
     * 获取执行状态
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * 设置执行状态
     *
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * 获取结果数据
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * 设置结果数据
     *
     * @param array $data
     * @return $this
     */
    public function setData(array $data): static
    {
        $this->data = $data;
        return $this;
    }

    /**
     * 合并数据
     *
     * @param array $data
     * @return $this
     */
    public function mergeData(array $data): static
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * 获取指定键的数据
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * 设置指定键的数据
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function set(string $key, mixed $value): static
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * 获取下一个节点ID
     *
     * @return string|null
     */
    public function getNextNodeId(): ?string
    {
        return $this->nextNodeId;
    }

    /**
     * 设置下一个节点ID
     *
     * @param string|null $nextNodeId
     * @return $this
     */
    public function setNextNodeId(?string $nextNodeId): static
    {
        $this->nextNodeId = $nextNodeId;
        return $this;
    }

    /**
     * 获取错误信息
     *
     * @return string|null
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * 设置错误信息
     *
     * @param string|null $error
     * @return $this
     */
    public function setError(?string $error): static
    {
        $this->error = $error;
        return $this;
    }

    /**
     * 获取错误码
     *
     * @return int|null
     */
    public function getErrorCode(): ?int
    {
        return $this->errorCode;
    }

    /**
     * 设置错误码
     *
     * @param int|null $errorCode
     * @return $this
     */
    public function setErrorCode(?int $errorCode): static
    {
        $this->errorCode = $errorCode;
        return $this;
    }

    /**
     * 获取错误堆栈
     *
     * @return string|null
     */
    public function getErrorStack(): ?string
    {
        return $this->errorStack;
    }

    /**
     * 设置错误堆栈
     *
     * @param string|null $errorStack
     * @return $this
     */
    public function setErrorStack(?string $errorStack): static
    {
        $this->errorStack = $errorStack;
        return $this;
    }

    /**
     * 获取执行耗时（毫秒）
     *
     * @return float
     */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * 获取节点ID
     *
     * @return string|null
     */
    public function getNodeId(): ?string
    {
        return $this->nodeId;
    }

    /**
     * 设置节点ID
     *
     * @param string|null $nodeId
     * @return $this
     */
    public function setNodeId(?string $nodeId): static
    {
        $this->nodeId = $nodeId;
        return $this;
    }

    /**
     * 获取节点名称
     *
     * @return string|null
     */
    public function getNodeName(): ?string
    {
        return $this->nodeName;
    }

    /**
     * 设置节点名称
     *
     * @param string|null $nodeName
     * @return $this
     */
    public function setNodeName(?string $nodeName): static
    {
        $this->nodeName = $nodeName;
        return $this;
    }

    /**
     * 检查是否执行成功
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * 检查是否执行挂起
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * 检查是否执行失败
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * 检查是否被跳过
     *
     * @return bool
     */
    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    /**
     * 检查是否被取消
     *
     * @return bool
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * 检查是否已完成（成功或失败）
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
            self::STATUS_CANCELLED
        ], true);
    }

    /**
     * 检查是否需要等待
     *
     * @return bool
     */
    public function needsWait(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * 获取重试次数
     *
     * @return int
     */
    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    /**
     * 增加重试次数
     *
     * @return $this
     */
    public function incrementRetry(): static
    {
        $this->retryCount++;
        return $this;
    }

    /**
     * 检查是否可以重试
     *
     * @return bool
     */
    public function canRetry(): bool
    {
        return $this->retryable && $this->retryCount < $this->maxRetries && $this->isFailed();
    }

    /**
     * 设置是否可重试
     *
     * @param bool $retryable
     * @return $this
     */
    public function setRetryable(bool $retryable): static
    {
        $this->retryable = $retryable;
        return $this;
    }

    /**
     * 设置最大重试次数
     *
     * @param int $maxRetries
     * @return $this
     */
    public function setMaxRetries(int $maxRetries): static
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    /**
     * 获取最大重试次数
     *
     * @return int
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * 获取元数据
     *
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * 设置元数据
     *
     * @param array $metadata
     * @return $this
     */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * 获取指定键的元数据
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * 设置指定键的元数据
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setMeta(string $key, mixed $value): static
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
            'status' => $this->status,
            'data' => $this->data,
            'next_node_id' => $this->nextNodeId,
            'error' => $this->error,
            'error_code' => $this->errorCode,
            'error_stack' => $this->errorStack,
            'duration' => $this->duration,
            'node_id' => $this->nodeId,
            'node_name' => $this->nodeName,
            'retry_count' => $this->retryCount,
            'retryable' => $this->retryable,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * 转换为JSON字符串
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 从数组创建实例
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $result = new static(
            $data['status'] ?? self::STATUS_SUCCESS,
            $data['data'] ?? [],
            $data['next_node_id'] ?? null,
            $data['error'] ?? null
        );

        if (isset($data['error_code'])) {
            $result->setErrorCode($data['error_code']);
        }
        if (isset($data['error_stack'])) {
            $result->setErrorStack($data['error_stack']);
        }
        if (isset($data['node_id'])) {
            $result->setNodeId($data['node_id']);
        }
        if (isset($data['node_name'])) {
            $result->setNodeName($data['node_name']);
        }
        if (isset($data['retry_count'])) {
            $result->retryCount = $data['retry_count'];
        }
        if (isset($data['retryable'])) {
            $result->setRetryable($data['retryable']);
        }
        if (isset($data['metadata'])) {
            $result->setMetadata($data['metadata']);
        }

        return $result;
    }
}
