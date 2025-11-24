<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Timestamp Field Type | 时间戳字段类型
 * 
 * Represents a TIMESTAMP field for date and time with automatic updates.
 * 表示用于自动更新的日期时间的TIMESTAMP字段。
 * 
 * @package Infrastructure\Lowcode\Collection\Field
 */
class TimestampField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'timestamp';
        $this->dbType = 'TIMESTAMP';
    }

    /**
     * {@inheritdoc}
     */
    public function getDbColumn(): array
    {
        $column = parent::getDbColumn();
        
        // Add default CURRENT_TIMESTAMP if specified | 如果指定则添加默认CURRENT_TIMESTAMP
        if ($this->options['default_current'] ?? false) {
            $column['default'] = 'CURRENT_TIMESTAMP';
        }
        
        // Add ON UPDATE CURRENT_TIMESTAMP if specified | 如果指定则添加更新时自动更新
        if ($this->options['on_update_current'] ?? false) {
            $column['on_update'] = 'CURRENT_TIMESTAMP';
        }
        
        return $column;
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
        if (is_int($value)) {
            // Unix timestamp | Unix时间戳
            return $value >= 0;
        }

        if (is_string($value)) {
            // Date string | 日期字符串
            return strtotime($value) !== false;
        }

        return false;
    }
}
