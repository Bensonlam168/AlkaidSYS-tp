<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Date Field Type | 日期字段类型
 *
 * Represents a DATE field for date values (YYYY-MM-DD).
 * 表示用于日期值的DATE字段(YYYY-MM-DD)。
 *
 * @package Infrastructure\Lowcode\Collection\Field
 */
class DateField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'date';
        $this->dbType = 'DATE';
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

        // Date format check | 日期格式检查
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return false;
        }

        // Verify it's a valid date | 验证是否为有效日期
        $dateFormat = 'Y-m-d';
        $dateObj = \DateTime::createFromFormat($dateFormat, $value);

        return $dateObj && $dateObj->format($dateFormat) === $value;
    }
}
