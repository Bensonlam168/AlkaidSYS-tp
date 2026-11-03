<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * TreeSelect Field Type | 树选择字段类型
 *
 * 可用于保存单选值（string/number）或多选值数组（JSON array）。
 */
class TreeSelectField extends AbstractField
{
    protected function initializeType(): void
    {
        $this->type = 'tree-select';
        $this->dbType = 'JSON';
    }

    public function validate($value): bool
    {
        if ($value === null) {
            return $this->nullable;
        }

        if (is_string($value) || is_numeric($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                return false;
            }
        }

        return true;
    }
}
