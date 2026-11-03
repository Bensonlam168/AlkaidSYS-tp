<?php

declare(strict_types=1);

namespace Infrastructure\Plugin\Hook;

/**
 * 钩子回调返回值封装，用于表达停止传播等控制语义。
 */
class HookResult
{
    public function __construct(
        protected mixed $value = null,
        protected bool $stopPropagation = false
    ) {
    }

    /**
     * 创建“停止传播”的结果，可携带需要返回/透传的值。
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
