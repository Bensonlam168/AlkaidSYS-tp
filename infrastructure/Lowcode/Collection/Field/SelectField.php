<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Select Field Type | 下拉选择字段类型
 *
 * Represents a VARCHAR field for single select dropdown.
 * 表示用于单选下拉菜单的VARCHAR字段。
 *
 * @package Infrastructure\Lowcode\Collection\Field
 */
class SelectField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'select';
        $length = $this->options['length'] ?? 50;
        $this->dbType = "VARCHAR({$length})";
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
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        // Enum validation | 枚举值验证
        if (isset($this->options['enum']) && is_array($this->options['enum'])) {
            return in_array($value, $this->options['enum'], true);
        }

        return true;
    }
}
