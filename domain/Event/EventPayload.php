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
}
