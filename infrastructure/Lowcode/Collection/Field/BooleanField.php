<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Boolean Field Type | 布尔字段类型
 * 
 * Represents a TINYINT(1) field for boolean values.
 * 表示用于布尔值的TINYINT(1)字段。
 * 
 * @package Infrastructure\Lowcode\Collection\Field
 */
class BooleanField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'boolean';
        $this->dbType = 'TINYINT(1)';
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
        // Accept: true, false, 1, 0, '1', '0'
        // 接受: true, false, 1, 0, '1', '0'
        if (is_bool($value)) {
            return true;
        }

        if (in_array($value, [0, 1, '0', '1'], true)) {
            return true;
        }

        return false;
    }
}
