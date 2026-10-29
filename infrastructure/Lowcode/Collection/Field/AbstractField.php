<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Field;

use Domain\Lowcode\Collection\Interfaces\FieldInterface;

/**
 * Abstract Field Base Class | 抽象字段基类
 *
 * Base implementation for all field types providing common functionality.
 * 为所有字段类型提供通用功能的基础实现。
 *
 * @package Infrastructure\Lowcode\Collection\Field
 */
abstract class AbstractField implements FieldInterface
{
    protected string $name;
    protected string $type;
    protected string $dbType;
    protected string $title;
    protected bool $nullable = true;
    protected $default = null;
    /**
     * 是否显式设置了默认值（用于区分“未设置”与“默认值为 null”）。
     *
     * 说明：
     * - MySQL 对 NOT NULL 列不允许 DEFAULT NULL；
     * - 因此仅当用户显式传入 default 时才输出 DEFAULT 子句。
     */
    protected bool $hasDefault = false;
    protected array $options = [];

    /**
     * Constructor | 构造函数
     *
     * @param string $name Field name | 字段名称
     * @param array $options Field options | 字段选项
     */
    public function __construct(string $name, array $options = [])
    {
        $this->name = $name;
        $this->title = $options['title'] ?? $name;
        $this->nullable = $options['nullable'] ?? true;
        $this->hasDefault = array_key_exists('default', $options);
        $this->default = $options['default'] ?? null;
        $this->options = $options;

        // Initialize type-specific properties in child classes
        // 在子类中初始化特定类型的属性
        $this->initializeType();
    }

    /**
     * Initialize type-specific properties | 初始化特定类型属性
     *
     * Override in child classes to set type and dbType.
     * 在子类中重写以设置type和dbType。
     *
     * @return void
     */
    abstract protected function initializeType(): void;

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     */
    public function getDbType(): string
    {
        return $this->dbType;
    }

    /**
     * {@inheritdoc}
     */
    public function getDbColumn(): array
    {
        $column = [
            'name' => $this->name,
            'type' => $this->dbType,
            'nullable' => $this->nullable,
            'comment' => $this->title,
        ];

        if ($this->hasDefault) {
            $column['default'] = $this->default;
        }

        return $column;
    }

    /**
     * {@inheritdoc}
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * {@inheritdoc}
     */
    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefault()
    {
        return $this->default;
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'db_type' => $this->dbType,
            'title' => $this->title,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'options' => $this->options,
        ];
    }

    /**
     * {@inheritdoc}
     */
    abstract public function validate($value): bool;
}
