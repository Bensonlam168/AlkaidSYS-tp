<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * File Field Type | 文件字段类型
 *
 * Represents a VARCHAR field for file path storage.
 * 表示用于存储文件路径的VARCHAR字段。
 *
 * @package Infrastructure\Lowcode\Collection\Field
 */
class FileField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'file';
        $length = $this->options['length'] ?? 255;
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
        if (!is_string($value)) {
            return false;
        }

        // File extension check | 文件扩展名检查
        if (isset($this->options['allowed_extensions'])) {
            $ext = pathinfo($value, PATHINFO_EXTENSION);
            if (!in_array(strtolower($ext), $this->options['allowed_extensions'])) {
                return false;
            }
        }

        // File size check (if value is actual file path) | 文件大小检查
        if (isset($this->options['max_size']) && file_exists($value)) {
            if (filesize($value) > $this->options['max_size']) {
                return false;
            }
        }

        return true;
    }
}
