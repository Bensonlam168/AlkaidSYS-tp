<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Bigint Field Type | 大整数字段类型
 * 
 * Represents a BIGINT field for large integer numbers.
 * 表示用于大整数的BIGINT字段。
 * 
 * @package Infrastructure\Lowcode\Collection\Field
 */
class BigintField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'bigint';
        $length = $this->options['length'] ?? 20;
        $unsigned = $this->options['unsigned'] ?? false;
        $this->dbType = "BIGINT({$length})" . ($unsigned ? ' UNSIGNED' : '');
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
        if (!is_int($value) && !ctype_digit((string)$value)) {
            return false;
        }

        $intValue = (int)$value;

        // Minimum check | 最小值检查
        if (isset($this->options['minimum']) && $intValue < $this->options['minimum']) {
            return false;
        }

        // Maximum check | 最大值检查
        if (isset($this->options['maximum']) && $intValue > $this->options['maximum']) {
            return false;
        }

        return true;
    }
}
