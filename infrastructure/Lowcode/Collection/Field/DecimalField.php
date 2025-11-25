<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Decimal Field Type | 小数字段类型
 *
 * Represents a DECIMAL field for precise decimal numbers.
 * 表示用于精确小数的DECIMAL字段。
 *
 * @package Infrastructure\Lowcode\Collection\Field
 */
class DecimalField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'decimal';
        $precision = $this->options['precision'] ?? 10;
        $scale = $this->options['scale'] ?? 2;
        $this->dbType = "DECIMAL({$precision},{$scale})";
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
        if (!is_numeric($value)) {
            return false;
        }

        $floatValue = (float)$value;

        // Minimum check | 最小值检查
        if (isset($this->options['minimum']) && $floatValue < $this->options['minimum']) {
            return false;
        }

        // Maximum check | 最大值检查
        if (isset($this->options['maximum']) && $floatValue > $this->options['maximum']) {
            return false;
        }

        return true;
    }
}
