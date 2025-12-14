<?php

declare(strict_types=1);

namespace Domain\Field;

/**
 * Field Interface | 字段接口
 *
 * Defines the contract for all field types in the low-code system.
 * 定义低代码系统中所有字段类型的契约。
 *
 * @package Domain\Field
 */
interface FieldInterface
{
    /**
     * Get field name | 获取字段名称
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get field type | 获取字段类型
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Validate field value | 验证字段值
     *
     * @param mixed $value
     * @return bool
     */
    public function validate($value): bool;

    /**
     * Convert field to array | 将字段转换为数组
     *
     * @return array
     */
    public function toArray(): array;
}
