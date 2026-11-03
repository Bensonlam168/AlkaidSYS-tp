<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Cascader Field Type | 级联选择字段类型
 *
 * 典型用于保存层级路径，例如 [province, city, district]。
 * 数据库存储建议使用 JSON，避免强耦合具体层级深度。
 */
class CascaderField extends AbstractField
{
    protected function initializeType(): void
    {
        $this->type = 'cascader';
        $this->dbType = 'JSON';
    }

    public function validate($value): bool
    {
        if ($value === null) {
            return $this->nullable;
        }

        // 允许 string（单值）或 array（路径数组）
        if (is_string($value) || is_numeric($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        // 路径数组元素应为 string/number
        foreach ($value as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                return false;
            }
        }

        return true;
    }
}
