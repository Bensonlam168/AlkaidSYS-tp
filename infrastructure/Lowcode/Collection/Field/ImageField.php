<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Image Field Type | 图片字段类型
 * 
 * Represents a VARCHAR field for image path storage.
 * 表示用于存储图片路径的VARCHAR字段。
 * 
 * @package Infrastructure\Lowcode\Collection\Field
 */
class ImageField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'image';
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

        // Image extension check | 图片扩展名检查
        $allowedExtensions = $this->options['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $ext = strtolower(pathinfo($value, PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowedExtensions)) {
            return false;
        }

        // Image dimensions check (if value is actual file path) | 图片尺寸检查
        if (file_exists($value) && function_exists('getimagesize')) {
            $imageInfo = @getimagesize($value);
            
            if ($imageInfo === false) {
                return false;
            }
            
            [$width, $height] = $imageInfo;
            
            if (isset($this->options['max_width']) && $width > $this->options['max_width']) {
                return false;
            }
            
            if (isset($this->options['max_height']) && $height > $this->options['max_height']) {
                return false;
            }
        }

        return true;
    }
}
