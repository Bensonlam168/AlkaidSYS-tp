<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Swoole\Exception;

/**
 * 执行取消异常
 *
 * 当工作流执行被取消时抛出。
 *
 * @package Alkaid\Workflow\Engine\Swoole\Exception
 */
class ExecutionCancelledException extends CoroutineExecutionException
{
    /**
     * 取消原因
     *
     * @var string
     */
    protected string $reason;

    /**
     * 取消时间戳
     *
     * @var float
     */
    protected float $cancelledAt;

    /**
     * 构造函数
     *
     * @param string $reason 取消原因
     * @param string|null $nodeId 节点ID
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(
        string $reason = '执行被用户取消',
        ?string $nodeId = null,
        ?\Throwable $previous = null
    ) {
        $this->reason = $reason;
        $this->cancelledAt = microtime(true);

        parent::__construct($reason, 0, $previous, $nodeId, [
            'reason' => $reason,
            'cancelled_at' => $this->cancelledAt,
        ]);
    }

    /**
     * 获取取消原因
     *
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * 获取取消时间戳
     *
     * @return float
     */
    public function getCancelledAt(): float
    {
        return $this->cancelledAt;
    }
}
