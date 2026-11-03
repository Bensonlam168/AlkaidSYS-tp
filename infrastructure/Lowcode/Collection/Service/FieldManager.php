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
     * NOTE(租户必填): `$tenantId` 不再可空/带默认值 —— 原先 null 会被静默当成系统模板 0，
     * 调用方少传一个参数就悄悄落到了另一个租户的集合上。
     *
     * @param string $collectionName Collection name | Collection名称
     * @param FieldInterface $field Field to add | 要添加的字段
     * @param int $tenantId Tenant ID (0 = system template space) | 租户ID（0 表示系统模板空间）
     * @param int|null $siteId Site ID (optional) | 站点ID（可选）
     * @return void
     * @throws \Exception
     */
    public function addField(string $collectionName, FieldInterface $field, int $tenantId, ?int $siteId = null): void
    {

        $collection = $this->collectionManager->get($collectionName, $tenantId);

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
        $this->fieldRepo->save($field, $collection->getId(), $tenantId);

        // 3. Clear cache (tenant scoped) | 清除租户范围缓存
        $this->collectionManager->clearCache($collectionName, $tenantId);

        // 4. Trigger event | 触发事件
        Event::trigger('lowcode.field.added', [
            'collection' => $collection,
            'field' => $field,
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
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
     * @param int $tenantId Tenant ID (0 = system template space) | 租户ID（0 表示系统模板空间）
     * @param int|null $siteId Site ID (optional) | 站点ID（可选）
     * @return void
     * @throws \Exception
     */
    public function updateField(
        string $collectionName,
        string $fieldName,
        array $changes,
        int $tenantId,
        ?int $siteId = null
    ): void {

        $collection = $this->collectionManager->get($collectionName, $tenantId);

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
        $this->fieldRepo->save($field, $collection->getId(), $tenantId);

        // Clear cache (tenant scoped) | 清除租户范围缓存
        $this->collectionManager->clearCache($collectionName, $tenantId);

        // Trigger event | 触发事件
        Event::trigger('lowcode.field.updated', [
            'collection' => $collection,
            'field_name' => $fieldName,
            'changes' => $changes,
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
        ]);
    }

    /**
     * Remove field from collection | 从Collection移除字段
     *
     * @param string $collectionName Collection name | Collection名称
     * @param string $fieldName Field name to remove | 要移除的字段名
     * @param int $tenantId Tenant ID (0 = system template space) | 租户ID（0 表示系统模板空间）
     * @param int|null $siteId Site ID (optional) | 站点ID（可选）
     * @return void
     * @throws \Exception
     */
    public function removeField(string $collectionName, string $fieldName, int $tenantId, ?int $siteId = null): void
    {

        $collection = $this->collectionManager->get($collectionName, $tenantId);

        if (!$collection) {
            throw new \InvalidArgumentException("Collection not found: {$collectionName}");
        }

        // 1. Drop column from physical table | 从物理表删除列
        $this->schemaBuilder->dropColumn($collection->getTableName(), $fieldName);

        // 2. Delete field metadata | 删除字段元数据
        $collection->removeField($fieldName);
        $this->fieldRepo->delete($collection->getId(), $fieldName, $tenantId);

        // 3. Clear cache (tenant scoped) | 清除租户范围缓存
        $this->collectionManager->clearCache($collectionName, $tenantId);

        // 4. Trigger event | 触发事件
        Event::trigger('lowcode.field.removed', [
            'collection' => $collection,
            'field_name' => $fieldName,
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
        ]);
    }
}
