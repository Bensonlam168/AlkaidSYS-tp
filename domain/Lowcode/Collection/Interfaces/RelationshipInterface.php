<?php

declare(strict_types=1);

namespace Domain\Lowcode\Collection\Interfaces;

/**
 * Relationship Interface | 关系接口
 * 
 * Defines the contract for relationships between collections.
 * 定义Collection之间关系的契约。
 * 
 * @package Domain\Lowcode\Collection\Interfaces
 */
interface RelationshipInterface
{
    /**
     * Get relationship ID | 获取关系ID
     * 
     * @return int|null
     */
    public function getId(): ?int;

    /**
     * Get relationship name | 获取关系名称
     * 
     * @return string Relationship name | 关系名称
     */
    public function getName(): string;

    /**
     * Get relationship type | 获取关系类型
     * 
     * @return string Type: hasOne, hasMany, belongsTo, belongsToMany | 类型
     */
    public function getType(): string;

    /**
     * Get target collection | 获取目标Collection
     * 
     * @return string Target collection name | 目标Collection名称
     */
    public function getTargetCollection(): string;

    /**
     * Get foreign key | 获取外键
     * 
     * @return string Foreign key field name | 外键字段名
     */
    public function getForeignKey(): string;

    /**
     * Get local key | 获取本地键
     * 
     * @return string Local key field name | 本地键字段名
     */
    public function getLocalKey(): string;

    /**
     * Get relationship options | 获取关系选项
     * 
     * @return array Options (pivot table, etc.) | 选项（中间表等）
     */
    public function getOptions(): array;

    /**
     * Convert to array | 转换为数组
     * 
     * @return array Relationship data as array | 关系数据数组
     */
    public function toArray(): array;
}
