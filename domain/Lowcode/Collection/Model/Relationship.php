<?php

declare(strict_types=1);

namespace Domain\Lowcode\Collection\Model;

use Domain\Lowcode\Collection\Interfaces\RelationshipInterface;
use Domain\Lowcode\Collection\Enum\RelationType;

/**
 * Relationship Model | 关系模型
 * 
 * Represents a relationship between collections.
 * 表示Collection之间的关系。
 * 
 * @package Domain\Lowcode\Collection\Model
 */
class Relationship implements RelationshipInterface
{
    protected ?int $id = null;
    protected int $collectionId;
    protected string $name;
    protected string $type;
    protected string $targetCollection;
    protected string $foreignKey;
    protected string $localKey;
    protected array $options = [];
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    /**
     * Constructor | 构造函数
     * 
     * @param int $collectionId Owner collection ID | 所属Collection ID
     * @param string $name Relationship name | 关系名称
     * @param array $config Configuration array | 配置数组
     * @throws \InvalidArgumentException
     */
    public function __construct(int $collectionId, string $name, array $config)
    {
        $this->collectionId = $collectionId;
        $this->name = $name;
        
        if (!isset($config['type'])) {
            throw new \InvalidArgumentException("Relationship type is required");
        }

        if (!RelationType::isValid($config['type'])) {
            throw new \InvalidArgumentException("Invalid relationship type: {$config['type']}");
        }

        $this->type = $config['type'];
        
        if (!isset($config['target_collection'])) {
            throw new \InvalidArgumentException("Target collection is required");
        }

        $this->targetCollection = $config['target_collection'];
        $this->foreignKey = $config['foreign_key'] ?? $this->generateForeignKey();
        $this->localKey = $config['local_key'] ?? 'id';
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
     * Set relationship ID | 设置关系ID
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
     * Get collection ID | 获取Collection ID
     * 
     * @return int
     */
    public function getCollectionId(): int
    {
        return $this->collectionId;
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
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     */
    public function getTargetCollection(): string
    {
        return $this->targetCollection;
    }

    /**
     * {@inheritdoc}
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * {@inheritdoc}
     */
    public function getLocalKey(): string
    {
        return $this->localKey;
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
            'collection_id' => $this->collectionId,
            'name' => $this->name,
            'type' => $this->type,
            'target_collection' => $this->targetCollection,
            'foreign_key' => $this->foreignKey,
            'local_key' => $this->localKey,
            'options' => $this->options,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Generate default foreign key | 生成默认外键
     * 
     * @return string Foreign key name | 外键名称
     */
    protected function generateForeignKey(): string
    {
        // Convert target collection name to snake_case and append _id
        // 将目标collection名称转换为下划线命名并追加_id
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $this->targetCollection)) . '_id';
    }
}
