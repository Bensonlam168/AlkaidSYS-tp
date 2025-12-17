<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes;

/**
 * 节点执行结果
 *
 * 封装节点执行的结果信息，包括状态、输出数据、错误信息等。
 *
 * @package Alkaid\Workflow\Engine\Nodes
 */
class NodeResult
{
    /**
     * 执行状态常量
     */
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SKIPPED = 'SKIPPED';
    public const STATUS_WAITING = 'WAITING';
    public const STATUS_CANCELLED = 'CANCELLED';

    /**
     * 执行状态
     */
    protected string $status;

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
     * 下一个节点列表
     */
    protected array $nextNodes = [];

    /**
     * 元数据
     */
    protected array $metadata = [];

    /**
     * 节点ID
     */
    protected ?string $nodeId = null;

    /**
     * 节点名称
     */
    protected ?string $nodeName = null;

    /**
     * 开始时间
     */
    protected float $startTime;

    /**
     * 结束时间
     */
    protected ?float $endTime = null;

    /**
     * 构造函数
     *
     * @param string $status 执行状态
     */
    protected function __construct(string $status)
    {
        $this->status = $status;
        $this->startTime = microtime(true);
    }

    /**
     * 创建成功结果
     *
     * @param array $data 输出数据
     * @param array $nextNodes 下一个节点列表
     * @return static
     */
    public static function success(array $data = [], array $nextNodes = []): static
    {
        $result = new static(self::STATUS_SUCCESS);
        $result->data = $data;
        $result->nextNodes = $nextNodes;
        $result->endTime = microtime(true);
        return $result;
    }

    /**
     * 创建失败结果
     *
     * @param string $error 错误消息
     * @param int|null $code 错误代码
     * @return static
     */
    public static function failed(string $error, ?int $code = null): static
    {
        $result = new static(self::STATUS_FAILED);
        $result->error = $error;
        $result->errorCode = $code;
        $result->endTime = microtime(true);
        return $result;
    }

    /**
     * 创建等待结果（等待外部事件或用户操作）
     *
     * @param array $data 等待相关数据
     * @param string|null $message 等待消息
     * @return static
     */
    public static function waiting(array $data = [], ?string $message = null): static
    {
        $result = new static(self::STATUS_WAITING);
        $result->data = $data;
        if ($message) {
            $result->metadata['message'] = $message;
        }
        return $result;
    }

    /**
     * 创建待处理结果（异步执行中）
     *
     * @param array $data 待处理相关数据
     * @return static
     */
    public static function pending(array $data = []): static
    {
        $result = new static(self::STATUS_PENDING);
        $result->data = $data;
        return $result;
    }

    /**
     * 创建跳过结果
     *
     * @param string $reason 跳过原因
     * @param array $nextNodes 下一个节点列表
     * @return static
     */
    public static function skipped(string $reason, array $nextNodes = []): static
    {
        $result = new static(self::STATUS_SKIPPED);
        $result->data['reason'] = $reason;
        $result->nextNodes = $nextNodes;
        $result->endTime = microtime(true);
        return $result;
    }

    /**
     * 创建取消结果
     *
     * @param string $reason 取消原因
     * @return static
     */
    public static function cancelled(string $reason): static
    {
        $result = new static(self::STATUS_CANCELLED);
        $result->data['reason'] = $reason;
        $result->endTime = microtime(true);
        return $result;
    }

    /**
     * 从异常创建结果
     *
     * @param \Throwable $e 异常
     * @return static
     */
    public static function fromException(\Throwable $e): static
    {
        $result = new static(self::STATUS_FAILED);
        $result->error = $e->getMessage();
        $result->errorCode = (int) $e->getCode();
        $result->metadata['exception_class'] = get_class($e);
        $result->metadata['exception_file'] = $e->getFile();
        $result->metadata['exception_line'] = $e->getLine();
        $result->endTime = microtime(true);
        return $result;
    }

    /**
     * 从数组创建结果
     *
     * @param array $data 结果数据
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $result = new static($data['status'] ?? self::STATUS_SUCCESS);
        $result->data = $data['data'] ?? [];
        $result->error = $data['error'] ?? null;
        $result->errorCode = $data['error_code'] ?? null;
        $result->nextNodes = $data['next_nodes'] ?? [];
        $result->metadata = $data['metadata'] ?? [];
        $result->nodeId = $data['node_id'] ?? null;
        $result->nodeName = $data['node_name'] ?? null;
        $result->startTime = $data['start_time'] ?? microtime(true);
        $result->endTime = $data['end_time'] ?? null;
        return $result;
    }

    /**
     * 是否成功
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * 是否失败
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * 是否等待中
     *
     * @return bool
     */
    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }

    /**
     * 是否待处理
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * 是否被跳过
     *
     * @return bool
     */
    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    /**
     * 是否被取消
     *
     * @return bool
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * 是否已完成（成功、失败、跳过、取消都算完成）
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /**
     * 获取状态
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * 获取输出数据
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * 获取指定数据
     *
     * @param string $key 数据键
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * 设置数据
     *
     * @param string $key 数据键
     * @param mixed $value 数据值
     * @return static
     */
    public function set(string $key, mixed $value): static
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * 合并数据
     *
     * @param array $data 要合并的数据
     * @return static
     */
    public function mergeData(array $data): static
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * 获取错误消息
     *
     * @return string|null
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * 获取错误代码
     *
     * @return int|null
     */
    public function getErrorCode(): ?int
    {
        return $this->errorCode;
    }

    /**
     * 获取下一个节点列表
     *
     * @return array
     */
    public function getNextNodes(): array
    {
        return $this->nextNodes;
    }

    /**
     * 设置下一个节点列表
     *
     * @param array $nodes 节点列表
     * @return static
     */
    public function setNextNodes(array $nodes): static
    {
        $this->nextNodes = $nodes;
        return $this;
    }

    /**
     * 添加下一个节点
     *
     * @param string $nodeId 节点ID
     * @return static
     */
    public function addNextNode(string $nodeId): static
    {
        if (!in_array($nodeId, $this->nextNodes, true)) {
            $this->nextNodes[] = $nodeId;
        }
        return $this;
    }

    /**
     * 获取元数据
     *
     * @param string|null $key 元数据键
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getMeta(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->metadata;
        }
        return $this->metadata[$key] ?? $default;
    }

    /**
     * 设置元数据
     *
     * @param string $key 元数据键
     * @param mixed $value 元数据值
     * @return static
     */
    public function setMeta(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * 设置节点信息
     *
     * @param string $nodeId 节点ID
     * @param string|null $nodeName 节点名称
     * @return static
     */
    public function setNodeInfo(string $nodeId, ?string $nodeName = null): static
    {
        $this->nodeId = $nodeId;
        $this->nodeName = $nodeName;
        return $this;
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
     * 获取节点名称
     *
     * @return string|null
     */
    public function getNodeName(): ?string
    {
        return $this->nodeName;
    }

    /**
     * 获取开始时间
     *
     * @return float
     */
    public function getStartTime(): float
    {
        return $this->startTime;
    }

    /**
     * 获取结束时间
     *
     * @return float|null
     */
    public function getEndTime(): ?float
    {
        return $this->endTime;
    }

    /**
     * 标记结束
     *
     * @return static
     */
    public function markEnd(): static
    {
        if ($this->endTime === null) {
            $this->endTime = microtime(true);
        }
        return $this;
    }

    /**
     * 获取执行时长（毫秒）
     *
     * @return float
     */
    public function getDuration(): float
    {
        $endTime = $this->endTime ?? microtime(true);
        return ($endTime - $this->startTime) * 1000;
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
            'success' => $this->isSuccess(),
            'data' => $this->data,
            'error' => $this->error,
            'error_code' => $this->errorCode,
            'next_nodes' => $this->nextNodes,
            'metadata' => $this->metadata,
            'node_id' => $this->nodeId,
            'node_name' => $this->nodeName,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'duration_ms' => $this->getDuration(),
        ];
    }
}
