<?php

declare(strict_types=1);

namespace Domain\Field;

/**
 * String Field | 字符串字段
 * 
 * Represents a string type field with validation.
 * 表示带验证的字符串类型字段。
 * 
 * @package Domain\Field
 */
class StringField extends AbstractField
{
    /**
     * Field type | 字段类型
     * @var string
     */
    protected string $type = 'string';

    /**
     * {@inheritDoc}
     * 
     * Validates if value is a string or null (if nullable).
     * 验证值是否为字符串或null（如果可为空）。
     */
    public function validate($value): bool
    {
        if ($value === null && $this->nullable) {
            return true;
        }

        return is_string($value);
    }
}
