<?php

declare(strict_types=1);

namespace Infrastructure\Field;

use Domain\Field\FieldInterface;
use Domain\Field\StringField;
use Domain\Field\IntegerField;
use Domain\Field\BooleanField;
use Domain\Field\DateField;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;

/**
 * @deprecated FieldTypeRegistry is kept only for backward compatibility.
 *             New code should use Infrastructure\\Lowcode\\Collection\\Field\\FieldFactory directly.
 */
/**
 * Field Type Registry | 字段类型注册表
 *
 * Central registry for managing and creating field types dynamically.
 * 用于动态管理和创建字段类型的中央注册表。
 *
 * @package Infrastructure\Field
 */
class FieldTypeRegistry
{
    /**
     * Registered field types | 已注册的字段类型
     *
     * @var array<string, class-string<FieldInterface>>
     */
    protected static array $types = [];

    /**
     * Internal flag to ensure defaults are registered only once.
     * @var bool
     */
    protected static bool $defaultsRegistered = false;

    /**
     * Register a field type | 注册字段类型
     *
     * @param string $type Type identifier | 类型标识符
     * @param class-string<FieldInterface> $class Field class | 字段类
     * @return void
     */
    public static function register(string $type, string $class): void
    {
        self::$types[$type] = $class;
    }

    /**
     * Create a field instance | 创建字段实例
     *
     * @param string $type Field type | 字段类型
     * @param string $name Field name | 字段名称
     * @param array $options Field options | 字段选项
     * @return FieldInterface
     * @throws \InvalidArgumentException If field type is unknown | 如果字段类型未知
     */
    public static function create(string $type, string $name, array $options = []): FieldInterface
    {
        // If no explicit mapping is registered, delegate to Lowcode FieldFactory
        if (!isset(self::$types[$type])) {
            // Let Lowcode\FieldFactory handle unknown types; this ensures
            // future field types are defined only once in the Lowcode stack.
            return FieldFactory::create($type, $name, $options);
        }

        $class = self::$types[$type];
        return new $class($name, $options);
    }

    /**
     * Get all registered field types | 获取所有已注册的字段类型
     *
     * @return string[]
     */
    public static function getTypes(): array
    {
        return array_keys(self::$types);
    }

    /**
     * Check if a field type is registered | 检查字段类型是否已注册
     *
     * @param string $type Type identifier | 类型标识符
     * @return bool
     */
    public static function has(string $type): bool
    {
        return isset(self::$types[$type]);
    }

    /**
     * Register default field types | 注册默认字段类型
     *
     * This method is automatically called when the registry is first used.
     * 当注册表首次使用时，会自动调用此方法。
     *
     * @return void
     */
    public static function registerDefaults(): void
    {
        if (!empty(self::$types)) {
            return; // Already registered | 已注册
        }

        self::register('string', StringField::class);
        self::register('integer', IntegerField::class);
        self::register('boolean', BooleanField::class);
        self::register('date', DateField::class);
    }
}

// Initialize default field types | 初始化默认字段类型
FieldTypeRegistry::registerDefaults();
