<?php

declare(strict_types=1);

namespace Domain\Field;

/**
 * Boolean Field | 布尔字段
 * 
 * Represents a boolean type field with validation.
 * 表示带验证的布尔类型字段。
 * 
 * @package Domain\Field
 */
class BooleanField extends AbstractField
{
    /**
     * Field type | 字段类型
     * @var string
     */
    protected string $type = 'boolean';

    /**
     * {@inheritDoc}
     * 
     * Validates if value is a boolean or null (if nullable).
     * 验证值是否为布尔值或null（如果可为空）。
     */
    public function validate($value): bool
    {
        if ($value === null && $this->nullable) {
            return true;
        }

        return is_bool($value);
    }
}
