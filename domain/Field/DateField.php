<?php

declare(strict_types=1);

namespace Domain\Field;

/**
 * Date Field | 日期字段
 *
 * Represents a date type field with validation.
 * 表示带验证的日期类型字段。
 *
 * @package Domain\Field
 */
class DateField extends AbstractField
{
    /**
     * Field type | 字段类型
     * @var string
     */
    protected string $type = 'date';

    /**
     * {@inheritDoc}
     *
     * Validates if value is a valid date string or null (if nullable).
     * 验证值是否为有效的日期字符串或null（如果可为空）。
     */
    public function validate($value): bool
    {
        if ($value === null && $this->nullable) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        // Validate date format YYYY-MM-DD | 验证日期格式 YYYY-MM-DD
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            && strtotime($value) !== false;
    }
}
