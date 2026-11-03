<?php

declare(strict_types=1);

namespace Domain\Event;

/**
 * 事件监听器返回值封装，用于表达“是否停止传播”等控制语义。
 */
class EventResult
{
    public function __construct(
        protected mixed $value = null,
        protected bool $stopPropagation = false
    ) {
    }

    /**
     * 创建一个“停止传播”的结果。
     */
    public static function stop(mixed $value = null): self
    {
        return new self($value, true);
    }

    public function shouldStopPropagation(): bool
    {
        return $this->stopPropagation;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }
}
