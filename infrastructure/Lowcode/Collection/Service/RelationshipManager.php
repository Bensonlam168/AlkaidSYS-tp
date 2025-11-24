<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Service;

use Domain\Lowcode\Collection\Interfaces\RelationshipInterface;
use Domain\Lowcode\Collection\Enum\RelationType;
use Domain\Schema\Interfaces\SchemaBuilderInterface;
use Infrastructure\Lowcode\Collection\Repository\RelationshipRepository;
use think\facade\Event;

/**
 * Relationship Manager Service | 关系管理服务
 * 
 * Manages relationships between collections including pivot table creation.
 * 管理Collection之间的关系，包括中间表创建。
 * 
 * @package Infrastructure\Lowcode\Collection\Service
 */
class RelationshipManager
{
    protected SchemaBuilderInterface $schemaBuilder;
    protected RelationshipRepository $relationshipRepo;
    protected CollectionManager $collectionManager;

    /**
     * Constructor | 构造函数
     * 
     * @param SchemaBuilderInterface $schemaBuilder Schema builder | Schema构建器
     * @param RelationshipRepository $relationshipRepo Relationship repository | 关系仓储
     * @param CollectionManager $collectionManager Collection manager | Collection管理器
     */
    public function __construct(
        SchemaBuilderInterface $schemaBuilder,
        RelationshipRepository $relationshipRepo,
        CollectionManager $collectionManager
    ) {
        $this->schemaBuilder = $schemaBuilder;
        $this->relationshipRepo = $relationshipRepo;
        $this->collectionManager = $collectionManager;
    }

    /**
     * Add relationship to collection | 添加关系到Collection
     * 
     * @param string $collectionName Collection name | Collection名称
     * @param RelationshipInterface $relationship Relationship to add | 要添加的关系
     * @return void
     * @throws \Exception
     */
    public function addRelationship(string $collectionName, RelationshipInterface $relationship): void
    {
        $collection = $this->collectionManager->get($collectionName);
        
        if (!$collection) {
            throw new \InvalidArgumentException("Collection not found: {$collectionName}");
        }

        // Verify target collection exists | 验证目标Collection存在
        $targetCollection = $this->collectionManager->get($relationship->getTargetCollection());
        if (!$targetCollection) {
            throw new \InvalidArgumentException(
                "Target collection not found: {$relationship->getTargetCollection()}"
            );
        }

        // Handle relationship type | 处理关系类型
        $this->handleRelationshipType($collection, $targetCollection, $relationship);

        // Save relationship metadata | 保存关系元数据
        $collection->addRelationship($relationship->getName(), $relationship);
        $this->relationshipRepo->save($relationship, $collection->getId());

        // Clear cache | 清除缓存
        $this->collectionManager->clearCache($collectionName);

        // Trigger event | 触发事件
        Event::trigger('lowcode.relationship.added', [
            'collection' => $collection,
            'relationship' => $relationship,
        ]);
    }

    /**
     * Remove relationship | 移除关系
     * 
     * @param string $collectionName Collection name | Collection名称
     * @param string $relationshipName Relationship name | 关系名称
     * @param bool $dropPivotTable Drop pivot table for belongsToMany | 是否删除belongsToMany的中间表
     * @return void
     * @throws \Exception
     */
    public function removeRelationship(
        string $collectionName,
        string $relationshipName,
        bool $dropPivotTable = true
    ): void {
        $collection = $this->collectionManager->get($collectionName);
        
        if (!$collection) {
            throw new \InvalidArgumentException("Collection not found: {$collectionName}");
        }

        $relationship = $collection->getRelationship($relationshipName);
        
        if (!$relationship) {
            throw new \InvalidArgumentException("Relationship not found: {$relationshipName}");
        }

        // Drop pivot table if exists | 如果存在则删除中间表
        if ($dropPivotTable && $relationship->getType() === RelationType::BELONGS_TO_MANY) {
            $pivotTable = $this->getPivotTableName($collection, $relationship);
            if ($this->schemaBuilder->hasTable($pivotTable)) {
                $this->schemaBuilder->dropTable($pivotTable);
            }
        }

        // Delete relationship metadata | 删除关系元数据
        $this->relationshipRepo->delete($collection->getId(), $relationshipName);

        // Clear cache | 清除缓存
        $this->collectionManager->clearCache($collectionName);

        // Trigger event | 触发事件
        Event::trigger('lowcode.relationship.removed', [
            'collection' => $collection,
            'relationship_name' => $relationshipName,
        ]);
    }

    /**
     * Handle relationship type specific logic | 处理关系类型特定逻辑
     * 
     * @param $sourceCollection Source collection | 源Collection
     * @param $targetCollection Target collection | 目标Collection
     * @param RelationshipInterface $relationship Relationship | 关系
     * @return void
     * @throws \Exception
     */
    protected function handleRelationshipType(
        $sourceCollection,
        $targetCollection,
        RelationshipInterface $relationship
    ): void {
        switch ($relationship->getType()) {
            case RelationType::HAS_ONE:
            case RelationType::HAS_MANY:
                // No additional table needed | 不需要额外的表
                // Foreign key should exist in target collection | 外键应存在于目标Collection中
                break;

            case RelationType::BELONGS_TO:
                // No additional table needed | 不需要额外的表
                // Foreign key should exist in source collection | 外键应存在于源Collection中
                break;

            case RelationType::BELONGS_TO_MANY:
                // Create pivot table | 创建中间表
                $this->createPivotTable($sourceCollection, $targetCollection, $relationship);
                break;

            default:
                throw new \InvalidArgumentException("Unsupported relationship type: {$relationship->getType()}");
        }
    }

    /**
     * Create pivot table for many-to-many relationship | 为多对多关系创建中间表
     * 
     * @param $sourceCollection Source collection | 源Collection
     * @param $targetCollection Target collection | 目标Collection
     * @param RelationshipInterface $relationship Relationship | 关系
     * @return void
     */
    protected function createPivotTable(
        $sourceCollection,
        $targetCollection,
        RelationshipInterface $relationship
    ): void {
        $pivotTable = $this->getPivotTableName($sourceCollection, $relationship);

        // Skip if pivot table already exists | 如果中间表已存在则跳过
        if ($this->schemaBuilder->hasTable($pivotTable)) {
            return;
        }

        $columns = [
            'id' => [
                'type' => 'INT',
                'primary' => true,
                'auto_increment' => true,
                'unsigned' => true,
            ],
            $relationship->getLocalKey() => [
                'type' => 'INT',
                'unsigned' => true,
                'comment' => "Foreign key to {$sourceCollection->getTableName()}",
            ],
            $relationship->getForeignKey() => [
                'type' => 'INT',
                'unsigned' => true,
                'comment' => "Foreign key to {$targetCollection->getTableName()}",
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'default' => 'CURRENT_TIMESTAMP',
            ],
        ];

        $this->schemaBuilder->createTable($pivotTable, $columns);

        // TODO: Add indexes for better query performance
        // 添加索引以提高查询性能
    }

    /**
     * Get pivot table name | 获取中间表名
     * 
     * @param $sourceCollection Source collection | 源Collection
     * @param RelationshipInterface $relationship Relationship | 关系
     * @return string Pivot table name | 中间表名
     */
    protected function getPivotTableName($sourceCollection, RelationshipInterface $relationship): string
    {
        $options = $relationship->getOptions();
        
        if (isset($options['pivot_table'])) {
            return $options['pivot_table'];
        }

        // Generate default pivot table name | 生成默认中间表名
        // Format: lc_pivot_{source}_{target}
        // 格式: lc_pivot_{源}_{目标}
        $sourceName = str_replace('lc_', '', $sourceCollection->getTableName());
        $targetName = str_replace('lc_', '', $relationship->getTargetCollection());
        
        $names = [$sourceName, $targetName];
        sort($names); // Ensure consistent naming | 确保命名一致性
        
        return 'lc_pivot_' . implode('_', $names);
   }
}
