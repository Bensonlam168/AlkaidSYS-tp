<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Integer Field Type | 整数字段类型
 *
 * Represents an INT field for integer numbers.
 * 表示用于整数的INT字段。
 *
 * @package Infrastructure\Lowcode\Collection\Field
 */
class IntegerField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'integer';
        $length = $this->options['length'] ?? 11;
        $unsigned = $this->options['unsigned'] ?? false;
        $this->dbType = "INT({$length})" . ($unsigned ? ' UNSIGNED' : '');
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

        // Unsigned check | 无符号检查
        if (($this->options['unsigned'] ?? false) && $intValue < 0) {
            return false;
        }

        return true;
    }
}
