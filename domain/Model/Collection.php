<?php

declare(strict_types=1);

namespace Domain\Model;

use think\Model;
use Domain\Field\FieldInterface;

/**
 * Collection Class | 集合类 (Legacy)
 *
 * Base class for first-generation dynamic data models representing a data table abstraction.
 * 第一代动态数据模型的基类，表示数据表的抽象。
 *
 * @deprecated since T1-DOMAIN-CLEANUP S3/S4, use Domain\Lowcode\Collection\Model\Collection
 *             and Domain\Lowcode\Collection\Interfaces\CollectionInterface instead.
 * @package Domain\Model
 */
class Collection extends Model
{
    /**
     * Collection name | 集合名称
     * @var string
     */
    protected string $name;

    /**
     * The table associated with the model | 关联的数据表
     * @var string
     */
    protected $table;

    /**
     * Fields definition | 字段定义
     * @var array<string, FieldInterface>
     */
    protected array $fields = [];

    /**
     * Relationships definition | 关系定义
     * @var array
     */
    protected array $relationships = [];

    /**
     * Constructor | 构造函数
     *
     * @param string $name Collection name | 集合名称
     * @param array $config Configuration array | 配置数组
     */
    public function __construct(string $name = '', array $config = [])
    {
        $this->name = $name;
        $this->table = $config['table_name'] ?? $this->getDefaultTableName();
        $this->fields = $config['fields'] ?? [];
        $this->relationships = $config['relationships'] ?? [];
        
        parent::__construct();
    }

    /**
     * Set the table name dynamically | 动态设置表名
     *
     * @param string $table Table name | 表名
     * @return $this
     */
    public function setTable(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Get collection name | 获取集合名称
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get table name | 获取表名
     *
     * @return string
     */
    public function getTableName(): string
    {
        return $this->table;
    }

    /**
     * Add a field to the collection | 向集合添加字段
     *
     * @param FieldInterface $field Field instance | 字段实例
     * @return $this
     */
    public function addField(FieldInterface $field): self
    {
        $this->fields[$field->getName()] = $field;
        return $this;
    }

    /**
     * Get a specific field | 获取特定字段
     *
     * @param string $name Field name | 字段名称
     * @return FieldInterface|null
     */
    public function getField(string $name): ?FieldInterface
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * Get all field definitions | 获取所有字段定义
     *
     * @return array<string, FieldInterface>
     */
    public function getFieldDefinitions(): array
    {
        return $this->fields;
    }

    /**
     * Add a relationship | 添加关系
     *
     * @param string $name Relationship name | 关系名称
     * @param array $config Relationship configuration | 关系配置
     * @return $this
     */
    public function addRelationship(string $name, array $config): self
    {
        $this->relationships[$name] = $config;
        return $this;
    }

    /**
     * Get all relationships | 获取所有关系
     *
     * @return array
     */
    public function getRelationships(): array
    {
        return $this->relationships;
    }

    /**
     * Get default table name | 获取默认表名
     *
     * @return string
     */
    protected function getDefaultTableName(): string
    {
        return 'lc_' . strtolower($this->name);
    }

    /**
     * Convert collection to array | 将集合转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'table_name' => $this->table,
            'fields' => array_map(fn($field) => $field->toArray(), $this->fields),
            'relationships' => $this->relationships,
        ];
    }
}
