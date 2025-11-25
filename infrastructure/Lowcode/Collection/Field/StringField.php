<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * String Field Type | 字符串字段类型
 *
 * Represents a VARCHAR field for short text strings.
 * 表示用于短文本字符串的VARCHAR字段。
 *
 * @package Infrastructure\Lowcode\Collection\Field
 */
class StringField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'string';
        $length = $this->options['length'] ?? 255;
        $this->dbType = "VARCHAR({$length})";
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

        // Length check | 长度检查
        if (isset($this->options['min_length']) && mb_strlen($value) < $this->options['min_length']) {
            return false;
        }

        if (isset($this->options['max_length']) && mb_strlen($value) > $this->options['max_length']) {
            return false;
        }

        // Pattern check | 模式检查
        if (isset($this->options['pattern']) && !preg_match($this->options['pattern'], $value)) {
            return false;
        }

        return true;
    }
}
