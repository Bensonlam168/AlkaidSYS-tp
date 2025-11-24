<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Service;

use Domain\Lowcode\Collection\Interfaces\FieldInterface;
use Domain\Schema\Interfaces\SchemaBuilderInterface;
use Infrastructure\Lowcode\Collection\Repository\FieldRepository;
use think\facade\Event;

/**
 * Field Manager Service | 字段管理服务
 * 
 * Manages field operations including add, update, and remove.
 * 管理字段操作，包括添加、更新和移除。
 * 
 * @package Infrastructure\Lowcode\Collection\Service
 */
class FieldManager
{
    protected SchemaBuilderInterface $schemaBuilder;
    protected FieldRepository $fieldRepo;
    protected CollectionManager $collectionManager;

    /**
     * Constructor | 构造函数
     * 
     * @param SchemaBuilderInterface $schemaBuilder Schema builder | Schema构建器
     * @param FieldRepository $fieldRepo Field repository | 字段仓储
     * @param CollectionManager $collectionManager Collection manager | Collection管理器
     */
    public function __construct(
        SchemaBuilderInterface $schemaBuilder,
        FieldRepository $fieldRepo,
        CollectionManager $collectionManager
    ) {
        $this->schemaBuilder = $schemaBuilder;
        $this->fieldRepo = $fieldRepo;
        $this->collectionManager = $collectionManager;
    }

    /**
     * Add field to collection | 添加字段到Collection
     * 
     * @param string $collectionName Collection name | Collection名称
     * @param FieldInterface $field Field to add | 要添加的字段
     * @return void
     * @throws \Exception
     */
    public function addField(string $collectionName, FieldInterface $field): void
    {
        $collection = $this->collectionManager->get($collectionName);
        
        if (!$collection) {
            throw new \InvalidArgumentException("Collection not found: {$collectionName}");
        }

        // 1. Add column to physical table | 添加列到物理表
        $this->schemaBuilder->addColumn(
            $collection->getTableName(),
            $field->getName(),
            $field->getDbColumn()
        );

        // 2. Save field metadata | 保存字段元数据
        $collection->addField($field);
        $this->fieldRepo->save($field, $collection->getId());

        // 3. Clear cache | 清除缓存
        $this->collectionManager->clearCache($collectionName);

        // 4. Trigger event | 触发事件
        Event::trigger('lowcode.field.added', [
            'collection' => $collection,
            'field' => $field,
        ]);
    }

    /**
     * Update field | 更新字段
     * 
     * Note: This is a simplified implementation. In production, you may want to
     * use ALTER TABLE to modify columns, which is more complex.
     * 注意：这是简化实现。生产环境可能需要使用ALTER TABLE修改列，这更复杂。
     * 
     * @param string $collectionName Collection name | Collection名称
     * @param string $fieldName Field name | 字段名称
     * @param array $changes Changes to apply | 要应用的更改
     * @return void
     * @throws \Exception
     */
    public function updateField(string $collectionName, string $fieldName, array $changes): void
    {
        $collection = $this->collectionManager->get($collectionName);
        
        if (!$collection) {
            throw new \InvalidArgumentException("Collection not found: {$collectionName}");
        }

        $field = $collection->getField($fieldName);
        
        if (!$field) {
            throw new \InvalidArgumentException("Field not found: {$fieldName}");
        }

        // For now, just update metadata | 暂时只更新元数据
        // TODO: Implement ALTER TABLE column modification | 实现ALTER TABLE列修改
        
        // Update field metadata | 更新字段元数据
        $this->fieldRepo->save($field, $collection->getId());

        // Clear cache | 清除缓存
        $this->collectionManager->clearCache($collectionName);

        // Trigger event | 触发事件
        Event::trigger('lowcode.field.updated', [
            'collection' => $collection,
            'field_name' => $fieldName,
            'changes' => $changes,
        ]);
    }

    /**
     * Remove field from collection | 从Collection移除字段
     * 
     * @param string $collectionName Collection name | Collection名称
     * @param string $fieldName Field name to remove | 要移除的字段名
     * @return void
     * @throws \Exception
     */
    public function removeField(string $collectionName, string $fieldName): void
    {
        $collection = $this->collectionManager->get($collectionName);
        
        if (!$collection) {
            throw new \InvalidArgumentException("Collection not found: {$collectionName}");
        }

        // 1. Drop column from physical table | 从物理表删除列
        $this->schemaBuilder->dropColumn($collection->getTableName(), $fieldName);

        // 2. Delete field metadata | 删除字段元数据
        $collection->removeField($fieldName);
        $this->fieldRepo->delete($collection->getId(), $fieldName);

        // 3. Clear cache | 清除缓存
        $this->collectionManager->clearCache($collectionName);

        // 4. Trigger event | 触发事件
        Event::trigger('lowcode.field.removed', [
            'collection' => $collection,
            'field_name' => $fieldName,
        ]);
    }
}
