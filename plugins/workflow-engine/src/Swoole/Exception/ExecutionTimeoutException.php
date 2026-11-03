<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Swoole\Exception;

/**
 * 执行超时异常
 *
 * 当工作流节点执行超过指定时间限制时抛出。
 *
 * @package Alkaid\Workflow\Engine\Swoole\Exception
 */
class ExecutionTimeoutException extends CoroutineExecutionException
{
    /**
     * 超时时间（秒）
     *
     * @var int
     */
    protected int $timeout;

    /**
     * 实际执行时间（毫秒）
     *
     * @var float
     */
    protected float $actualDuration;

    /**
     * 构造函数
     *
     * @param int $timeout 超时时间（秒）
     * @param float $actualDuration 实际执行时间（毫秒）
     * @param string|null $nodeId 节点ID
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(
        int $timeout,
        float $actualDuration = 0,
        ?string $nodeId = null,
        ?\Throwable $previous = null
    ) {
        $this->timeout = $timeout;
        $this->actualDuration = $actualDuration;

        $message = sprintf(
            '节点执行超时：超时限制 %d 秒，实际执行 %.2f 毫秒',
            $timeout,
            $actualDuration
        );

        parent::__construct($message, 0, $previous, $nodeId, [
            'timeout' => $timeout,
            'actual_duration' => $actualDuration,
        ]);
    }

    /**
     * 获取超时时间
     *
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * 获取实际执行时间
     *
     * @return float
     */
    public function getActualDuration(): float
    {
        return $this->actualDuration;
    }
}
