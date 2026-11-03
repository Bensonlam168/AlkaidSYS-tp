<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

/**
 * Enum Field Type | 枚举字段类型
 *
 * 使用 VARCHAR 存储，结合 enum 选项进行取值校验。
 */
class EnumField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    protected function initializeType(): void
    {
        $this->type = 'enum';
        $length = $this->options['length'] ?? 64;
        $this->dbType = "VARCHAR({$length})";
    }

    /**
     * {@inheritdoc}
     */
    public function validate($value): bool
    {
        if ($value === null) {
            return $this->nullable;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        if (isset($this->options['enum']) && is_array($this->options['enum'])) {
            return in_array($value, $this->options['enum'], true);
        }

        // 若未提供 enum 列表，则按字符串/数字通过
        return true;
    }
}
