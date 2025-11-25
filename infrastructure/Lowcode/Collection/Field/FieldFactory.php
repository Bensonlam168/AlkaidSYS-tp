<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

use Domain\Lowcode\Collection\Interfaces\FieldInterface;

/**
 * Field Factory | 字段工厂
 *
 * Factory class for creating field instances by type.
 * 用于按类型创建字段实例的工厂类。
 *
 * @package Infrastructure\Lowcode\Collection\Field
 */
class FieldFactory
{
    /**
     * Field type mapping | 字段类型映射
     * @var array<string, class-string<FieldInterface>>
     */
    protected static array $fieldTypes = [
        // P0 Basic types | P0基础类型
        'string' => StringField::class,
        'text' => TextField::class,
        'integer' => IntegerField::class,
        'decimal' => DecimalField::class,
        'boolean' => BooleanField::class,
        'date' => DateField::class,
        'datetime' => DatetimeField::class,
        'json' => JsonField::class,

        // P1 Extended types | P1扩展类型
        'bigint' => BigintField::class,
        'timestamp' => TimestampField::class,
        'file' => FileField::class,
        'image' => ImageField::class,
        'select' => SelectField::class,
        'radio' => RadioField::class,
        'checkbox' => CheckboxField::class,
    ];

    /**
     * Create field instance by type | 按类型创建字段实例
     *
     * @param string $type Field type | 字段类型
     * @param string $name Field name | 字段名称
     * @param array $options Field options | 字段选项
     * @return FieldInterface
     * @throws \InvalidArgumentException
     */
    public static function create(string $type, string $name, array $options = []): FieldInterface
    {
        if (!isset(self::$fieldTypes[$type])) {
            throw new \InvalidArgumentException("Unknown field type: {$type}");
        }

        $class = self::$fieldTypes[$type];
        return new $class($name, $options);
    }

    /**
     * Register custom field type | 注册自定义字段类型
     *
     * @param string $type Field type identifier | 字段类型标识
     * @param class-string<FieldInterface> $class Field class name | 字段类名
     * @return void
     */
    public static function register(string $type, string $class): void
    {
        if (!is_subclass_of($class, FieldInterface::class)) {
            throw new \InvalidArgumentException("Class {$class} must implement FieldInterface");
        }

        self::$fieldTypes[$type] = $class;
    }

    /**
     * Get all registered field types | 获取所有已注册的字段类型
     *
     * @return array<string>
     */
    public static function getTypes(): array
    {
        return array_keys(self::$fieldTypes);
    }

    /**
     * Check if field type exists | 检查字段类型是否存在
     *
     * @param string $type Field type | 字段类型
     * @return bool
     */
    public static function hasType(string $type): bool
    {
        return isset(self::$fieldTypes[$type]);
    }
}
