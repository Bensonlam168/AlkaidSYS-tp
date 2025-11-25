<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Datetime Field Type | 日期时间字段类型
 *
 * Represents a DATETIME field for date and time values.
 * 表示用于日期和时间值的DATETIME字段。
 *
 * @package Infrastructure\Lowcode\Collection\Field
 */
class DatetimeField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'datetime';
        $this->dbType = 'DATETIME';
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

        // Datetime format check | 日期时间格式检查
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return false;
        }

        // Verify it's a valid datetime | 验证是否为有效日期时间
        // Accept formats: Y-m-d H:i:s, Y-m-d H:i, ISO8601, etc.
        // 接受格式: Y-m-d H:i:s, Y-m-d H:i, ISO8601等
        $dateObj = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
        if (!$dateObj) {
            $dateObj = \DateTime::createFromFormat('Y-m-d H:i', $value);
        }
        if (!$dateObj) {
            $dateObj = \DateTime::createFromFormat(\DateTime::ATOM, $value);
        }

        return $dateObj !== false;
    }
}
