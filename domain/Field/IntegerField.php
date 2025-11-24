<?php

declare(strict_types=1);

namespace Domain\Field;

/**
 * Integer Field | 整数字段
 * 
 * Represents an integer type field with validation.
 * 表示带验证的整数类型字段。
 * 
 * @package Domain\Field
 */
class IntegerField extends AbstractField
{
    /**
     * Field type | 字段类型
     * @var string
     */
    protected string $type = 'integer';

    /**
     * {@inheritDoc}
     * 
     * Validates if value is an integer or null (if nullable).
     * 验证值是否为整数或null（如果可为空）。
     */
    public function validate($value): bool
    {
        if ($value === null && $this->nullable) {
            return true;
        }

        return is_int($value) || ctype_digit((string)$value);
    }
}
