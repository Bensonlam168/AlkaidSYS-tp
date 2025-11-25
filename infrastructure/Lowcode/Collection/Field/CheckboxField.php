<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Checkbox Field Type | 复选框字段类型
 *
 * Represents a JSON field for multiple checkbox selections.
 * 表示用于多选复选框的JSON字段。
 *
 * @package Infrastructure\Lowcode\Collection\Field
 */
class CheckboxField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'checkbox';
        $this->dbType = 'JSON';
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
        if (!is_array($value)) {
            if (is_string($value)) {
                // Try to decode JSON string | 尝试解码JSON字符串
                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return false;
                }
                $value = $decoded;
            } else {
                return false;
            }
        }

        // Enum validation | 枚举值验证
        if (isset($this->options['enum']) && is_array($this->options['enum'])) {
            foreach ($value as $item) {
                if (!in_array($item, $this->options['enum'], true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
