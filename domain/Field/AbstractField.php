<?php

declare(strict_types=1);

namespace Domain\Field;

/**
 * Abstract Field Class | 抽象字段类
 *
 * Base implementation for all field types, providing common functionality.
 * 所有字段类型的基础实现，提供通用功能。
 *
 * @package Domain\Field
 */
abstract class AbstractField implements FieldInterface
{
    /**
     * Field name | 字段名称
     * @var string
     */
    protected string $name;

    /**
     * Field type identifier | 字段类型标识
     * @var string
     */
    protected string $type;

    /**
     * Whether field is nullable | 字段是否可为空
     * @var bool
     */
    protected bool $nullable = true;

    /**
     * Default value | 默认值
     * @var mixed
     */
    protected $default = null;

    /**
     * Additional field options | 附加字段选项
     * @var array
     */
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
        $this->options = $options;
        $this->nullable = $options['nullable'] ?? true;
        $this->default = $options['default'] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'options' => $this->options,
        ];
    }

    /**
     * {@inheritDoc}
     */
    abstract public function validate($value): bool;
}
