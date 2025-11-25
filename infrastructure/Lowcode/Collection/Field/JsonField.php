<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * JSON Field Type | JSON字段类型
 *
 * Represents a JSON field for structured data.
 * 表示用于结构化数据的JSON字段。
 *
 * @package Infrastructure\Lowcode\Collection\Field
 */
class JsonField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'json';
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

        // Array is valid JSON | 数组是有效的JSON
        if (is_array($value)) {
            return true;
        }

        // String JSON validation | 字符串JSON验证
        if (is_string($value)) {
            json_decode($value);
            return json_last_error() === JSON_ERROR_NONE;
        }

        return false;
    }
}
