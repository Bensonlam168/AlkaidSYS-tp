<?php

declare(strict_types=1);

namespace Domain\Lowcode\Collection\Interfaces;

/**
 * Collection Interface | Collection接口
 * 
 * Defines the contract for Collection entities in low代码 data modeling.
 * 定义低代码数据建模中Collection实体的契约。
 *
 * @package Domain\Lowcode\Collection\Interfaces
 */
interface CollectionInterface
{
    /**
     * Get collection ID | 获取Collection ID
     * 
     * @return int|null
     */
    public function getId(): ?int;

    /**
     * Get collection name | 获取Collection名称
     * 
     * @return string Collection unique identifier | Collection唯一标识
     */
    public function getName(): string;

    /**
     * Get table name | 获取表名
     * 
     * @return string Database table name | 数据库表名
     */
    public function getTableName(): string;

    /**
     * Get collection title | 获取Collection标题
     * 
     * @return string Display title | 显示标题
     */
    public function getTitle(): string;

    /**
     * Get collection description | 获取Collection描述
     * 
     * @return string Description | 描述
     */
    public function getDescription(): string;

    /**
     * Get all fields | 获取所有字段
     * 
     * @return array<string, FieldInterface>
     */
    public function getFields(): array;

    /**
     * Add field | 添加字段
     * 
     * @param FieldInterface $field Field to add | 要添加的字段
     * @return self
     */
    public function addField(FieldInterface $field): self;

    /**
     * Get field by name | 按名称获取字段
     * 
     * @param string $name Field name | 字段名称
     * @return FieldInterface|null
     */
    public function getField(string $name): ?FieldInterface;

    /**
     * Remove field | 移除字段
     * 
     * @param string $name Field name | 字段名称
     * @return self
     */
    public function removeField(string $name): self;

    /**
     * Get all relationships | 获取所有关系
     * 
     * @return array<string, RelationshipInterface>
     */
    public function getRelationships(): array;

    /**
     * Add relationship | 添加关系
     * 
     * @param string $name Relationship name | 关系名称
     * @param RelationshipInterface $relationship Relationship to add | 要添加的关系
     * @return self
     */
    public function addRelationship(string $name, RelationshipInterface $relationship): self;

    /**
     * Get relationship by name | 按名称获取关系
     * 
     * @param string $name Relationship name | 关系名称
     * @return RelationshipInterface|null
     */
    public function getRelationship(string $name): ?RelationshipInterface;

    /**
     * Get collection options | 获取Collection选项
     * 
     * @return array Options | 选项
     */
    public function getOptions(): array;

    /**
     * Convert to array | 转换为数组
     * 
     * @return array Collection data as array | Collection数据数组
     */
    public function toArray(): array;

    /**
     * Load from array | 从数组加载
     * 
     * @param array $data Array data | 数组数据
     * @return self
     */
    public function fromArray(array $data): self;
}
