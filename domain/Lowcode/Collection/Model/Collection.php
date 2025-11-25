<?php

declare(strict_types=1);

namespace Domain\Lowcode\Collection\Model;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;
use Domain\Lowcode\Collection\Interfaces\FieldInterface;
use Domain\Lowcode\Collection\Interfaces\RelationshipInterface;

/**
 * Collection Model | Collection模型
 *
 * Represents a dynamic data collection (table) in the lowcode system.
 * 表示低代码系统中的动态数据集合（表）。
 *
 * @package Domain\Lowcode\Collection\Model
 */
class Collection implements CollectionInterface
{
    protected ?int $id = null;
    protected string $name;
    protected string $tableName;
    protected string $title;
    protected string $description = '';
    protected array $fields = [];
    protected array $relationships = [];
    protected array $options = [];
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    /**
     * Constructor | 构造函数
     *
     * @param string $name Collection name | Collection名称
     * @param array $config Configuration array | 配置数组
     */
    public function __construct(string $name, array $config = [])
    {
        $this->name = $name;
        $this->tableName = $config['table_name'] ?? $this->generateTableName($name);
        $this->title = $config['title'] ?? $name;
        $this->description = $config['description'] ?? '';
        $this->options = $config['options'] ?? [];

        if (isset($config['id'])) {
            $this->id = (int)$config['id'];
        }

        if (isset($config['created_at'])) {
            $this->createdAt = $config['created_at'];
        }

        if (isset($config['updated_at'])) {
            $this->updatedAt = $config['updated_at'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set collection ID | 设置Collection ID
     *
     * @param int $id
     * @return self
     */
    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

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
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Set table name | 设置表名
     *
     * @param string $tableName
     * @return self
     */
    public function setTableName(string $tableName): self
    {
        $this->tableName = $tableName;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Set title | 设置标题
     *
     * @param string $title
     * @return self
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Set description | 设置描述
     *
     * @param string $description
     * @return self
     */
    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * {@inheritdoc}
     */
    public function addField(FieldInterface $field): self
    {
        $this->fields[$field->getName()] = $field;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getField(string $name): ?FieldInterface
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function removeField(string $name): self
    {
        unset($this->fields[$name]);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getRelationships(): array
    {
        return $this->relationships;
    }

    /**
     * {@inheritdoc}
     */
    public function addRelationship(string $name, RelationshipInterface $relationship): self
    {
        $this->relationships[$name] = $relationship;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getRelationship(string $name): ?RelationshipInterface
    {
        return $this->relationships[$name] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Set options | 设置选项
     *
     * @param array $options
     * @return self
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Get created timestamp | 获取创建时间
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * Get updated timestamp | 获取更新时间
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'table_name' => $this->tableName,
            'title' => $this->title,
            'description' => $this->description,
            'fields' => array_map(fn ($field) => $field->toArray(), $this->fields),
            'relationships' => array_map(fn ($rel) => $rel->toArray(), $this->relationships),
            'options' => $this->options,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function fromArray(array $data): self
    {
        if (isset($data['id'])) {
            $this->id = (int)$data['id'];
        }

        if (isset($data['title'])) {
            $this->title = $data['title'];
        }

        if (isset($data['description'])) {
            $this->description = $data['description'];
        }

        if (isset($data['table_name'])) {
            $this->tableName = $data['table_name'];
        }

        if (isset($data['options'])) {
            $this->options = is_array($data['options']) ? $data['options'] : json_decode($data['options'], true);
        }

        if (isset($data['created_at'])) {
            $this->createdAt = $data['created_at'];
        }

        if (isset($data['updated_at'])) {
            $this->updatedAt = $data['updated_at'];
        }

        return $this;
    }

    /**
     * Generate default table name | 生成默认表名
     *
     * @param string $name Collection name | Collection名称
     * @return string Table name | 表名
     */
    protected function generateTableName(string $name): string
    {
        // Convert camelCase to snake_case and add lc_ prefix
        // 将驼峰命名转换为下划线命名并添加lc_前缀
        $snakeCase = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name));
        return 'lc_' . $snakeCase;
    }
}
