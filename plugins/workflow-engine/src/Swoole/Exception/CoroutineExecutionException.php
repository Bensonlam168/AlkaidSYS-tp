<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Swoole\Exception;

/**
 * 协程执行异常
 *
 * 工作流协程执行过程中发生的异常基类。
 *
 * @package Alkaid\Workflow\Engine\Swoole\Exception
 */
class CoroutineExecutionException extends \RuntimeException
{
    /**
     * 节点ID
     *
     * @var string|null
     */
    protected ?string $nodeId = null;

    /**
     * 上下文数据
     *
     * @var array
     */
    protected array $contextData = [];

    /**
     * 构造函数
     *
     * @param string $message 错误消息
     * @param int $code 错误码
     * @param \Throwable|null $previous 前一个异常
     * @param string|null $nodeId 节点ID
     * @param array $contextData 上下文数据
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $nodeId = null,
        array $contextData = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->nodeId = $nodeId;
        $this->contextData = $contextData;
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
     * 获取上下文数据
     *
     * @return array
     */
    public function getContextData(): array
    {
        return $this->contextData;
    }

    /**
     * 设置上下文数据
     *
     * @param array $contextData
     * @return $this
     */
    public function setContextData(array $contextData): static
    {
        $this->contextData = $contextData;
        return $this;
    }

    /**
     * 添加上下文数据
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function addContextData(string $key, mixed $value): static
    {
        $this->contextData[$key] = $value;
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
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'node_id' => $this->nodeId,
            'context_data' => $this->contextData,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'trace' => $this->getTraceAsString(),
        ];
    }
}
