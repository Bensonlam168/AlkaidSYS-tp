<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Radio Field Type | 单选框字段类型
 * 
 * Represents a VARCHAR field for radio button selection.
 * 表示用于单选按钮的VARCHAR字段。
 * 
 * @package Infrastructure\Lowcode\Collection\Field
 */
class RadioField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'radio';
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
