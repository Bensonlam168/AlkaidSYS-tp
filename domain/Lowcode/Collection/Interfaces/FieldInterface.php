<?php

declare(strict_types=1);

namespace Domain\Lowcode\Collection\Interfaces;

/**
 * Field Interface | 字段接口
 * 
 * Defines the contract for all field types in the lowcode data modeling system.
 * 定义低代码数据建模系统中所有字段类型的契约。
 * 
 * @package Domain\Lowcode\Collection\Interfaces
 */
interface FieldInterface
{
    /**
     * Get field name | 获取字段名称
     * 
     * @return string Field name | 字段名称
     */
    public function getName(): string;

    /**
     * Get field type | 获取字段类型
     * 
     * @return string Field type (string, integer, decimal, etc.) | 字段类型
     */
    public function getType(): string;

    /**
     * Get database column type | 获取数据库列类型
     * 
     * @return string Database type (VARCHAR(255), INT(11), etc.) | 数据库类型
     */
    public function getDbType(): string;

    /**
     * Get database column definition | 获取数据库列定义
     * 
     * Returns complete DDL column definition for schema builder.
     * 返回供schema builder使用的完整DDL列定义。
     * 
     * @return array Column definition | 列定义
     */
    public function getDbColumn(): array;

    /**
     * Get field title | 获取字段标题
     * 
     * @return string Field title for UI display | 用于UI显示的字段标题
     */
    public function getTitle(): string;

    /**
     * Is field nullable | 字段是否可空
     * 
     * @return bool True if nullable | 如果可空则返回true
     */
    public function isNullable(): bool;

    /**
     * Get default value | 获取默认值
     * 
     * @return mixed Default value | 默认值
     */
    public function getDefault();

    /**
     * Get field options | 获取字段选项
     * 
     * @return array Field options (length, precision, component, etc.) | 字段选项
     */
    public function getOptions(): array;

    /**
     * Validate field value | 验证字段值
     * 
     * @param mixed $value Value to validate | 要验证的值
     * @return bool True if valid | 如果有效则返回true
     */
    public function validate($value): bool;

    /**
     * Convert to array | 转换为数组
     * 
     * @return array Field data as array | 字段数据数组
     */
    public function toArray(): array;
}
