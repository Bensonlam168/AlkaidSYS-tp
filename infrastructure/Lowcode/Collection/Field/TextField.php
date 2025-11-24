<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Text Field Type | 文本字段类型
 * 
 * Represents a TEXT field for long text content.
 * 表示用于长文本内容的TEXT字段。
 * 
 * @package Infrastructure\Lowcode\Collection\Field
 */
class TextField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'text';
        $this->dbType = 'TEXT';
    }

    /**
     * {@inheritdoc}
     */
    public function validate($value): bool
    {
        // Null check | 空值检查
        if ($value === null) {
            return $this->nullable;
        }

        // Type check | 类型检查
        if (!is_string($value)) {
            return false;
        }

        // Max length check (TEXT max is 65,535) | 最大长度检查
        if (isset($this->options['max_length']) && mb_strlen($value) > $this->options['max_length']) {
            return false;
        }

        return true;
    }
}
