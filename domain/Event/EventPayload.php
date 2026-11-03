<?php

declare(strict_types=1);

namespace Domain\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * 事件载荷对象：
 * - data：业务载荷（兼容历史 listener 签名）
 * - context：链路/租户/站点/用户上下文
 * - stopPropagation：停止传播标记（同步优先级监听链可用）
 */
class EventPayload implements StoppableEventInterface
{
    protected bool $propagationStopped = false;

    /**
     * 本次触发中失败的监听器异常（ER-13）
     *
     * 背景：`EventService::trigger()` 默认「监听器异常只记日志、不打断业务」，
     * 但异步任务必须知道「虽然 trigger 正常返回，监听器其实失败了」，否则队列会误判成功、
     * 直接 delete 任务，重试与 DLQ 永远不会发生。这里把异常挂在 payload 上供调用方判断。
     *
     * @var array<int, \Throwable>
     */
    protected array $listenerFailures = [];

    public function __construct(
        protected string $event,
        protected mixed $data,
        protected EventContext $context
    ) {
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function setData(mixed $data): void
    {
        $this->data = $data;
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }

    /**
     * 停止事件传播：后续优先级监听器不再执行，且不再向 ThinkPHP 事件系统继续 trigger。
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * 记录一个失败的监听器异常
     *
     * 只做记录，不改变控制流：是否上抛由 EventService 的 fail_fast 决定。
     */
    public function addListenerFailure(\Throwable $e): void
    {
        $this->listenerFailures[] = $e;
    }

    public function hasListenerFailures(): bool
    {
        return $this->listenerFailures !== [];
    }

    /**
     * @return array<int, \Throwable>
     */
    public function getListenerFailures(): array
    {
        return $this->listenerFailures;
    }

    /**
     * 第一个失败异常：异步任务用它触发队列重试，并保留原始错误码（2001/2002）
     */
    public function getFirstListenerFailure(): ?\Throwable
    {
        return $this->listenerFailures[0] ?? null;
    }
}
