<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Repository;

use Domain\Lowcode\Collection\Interfaces\RelationshipInterface;
use Domain\Lowcode\Collection\Model\Relationship;
use Infrastructure\Tenant\TenantAwareDb;
use think\facade\Db;

/**
 * Relationship Repository | 关系仓储
 *
 * Handles persistence of Relationship entities.
 * 处理关系实体的持久化。
 *
 * @package Infrastructure\Lowcode\Collection\Repository
 */
class RelationshipRepository
{
    protected string $table = 'lowcode_relationships';
    protected TenantAwareDb $tenantDb;

    public function __construct(?TenantAwareDb $tenantDb = null)
    {
        $this->tenantDb = $tenantDb ?? new TenantAwareDb();
    }

    /**
     * Save relationship | 保存关系
     *
     * @param RelationshipInterface $relationship Relationship to save | 要保存的关系
     * @param int $collectionId Collection ID | Collection ID
     * @param int|null $tenantId Tenant ID for isolation | 租户ID
     * @return int Relationship ID | 关系ID
     */
    public function save(RelationshipInterface $relationship, int $collectionId, ?int $tenantId = null): int
    {
        $data = [
            'collection_id' => $collectionId,
            'name' => $relationship->getName(),
            'type' => $relationship->getType(),
            'target_collection' => $relationship->getTargetCollection(),
            'foreign_key' => $relationship->getForeignKey(),
            'local_key' => $relationship->getLocalKey(),
            'options' => json_encode($relationship->getOptions()),
        ];

        $this->assertCollectionTenant($collectionId, $tenantId);

        // Check if relationship exists | 检查关系是否存在
        $exists = $this->scopedQuery($collectionId, $tenantId)
            ->where('r.name', $relationship->getName())
            ->find();

        if ($exists) {
            // Update existing | 更新已存在的
            Db::name($this->table)
                ->where('id', $exists['id'])
                ->update($data);
            return (int)$exists['id'];
        } else {
            // Insert new | 插入新的
            return (int)Db::name($this->table)->insertGetId($data);
        }
    }

    /**
     * Find relationships by collection ID | 按Collection ID查找关系
     *
     * @param int $collectionId Collection ID | Collection ID
     * @param int|null $tenantId Tenant ID for isolation | 租户ID
     * @return array<string, RelationshipInterface>
     */
    public function findByCollectionId(int $collectionId, ?int $tenantId = null): array
    {
        $rows = $this->scopedQuery($collectionId, $tenantId)
            ->order('r.id', 'asc')
            ->select()
            ->toArray();

        $relationships = [];
        foreach ($rows as $row) {
            $relationship = $this->hydrate($row);
            $relationships[$relationship->getName()] = $relationship;
        }

        return $relationships;
    }

    /**
     * Delete relationship | 删除关系
     *
     * @param int $collectionId Collection ID | Collection ID
     * @param string $relationshipName Relationship name | 关系名称
     * @param int|null $tenantId Tenant ID for isolation | 租户ID
     * @return bool
     */
    public function delete(int $collectionId, string $relationshipName, ?int $tenantId = null): bool
    {
        $this->assertCollectionTenant($collectionId, $tenantId);

        return (bool)Db::name($this->table)
            ->where('collection_id', $collectionId)
            ->where('name', $relationshipName)
            ->delete();
    }

    /**
     * Delete all relationships by collection ID | 按Collection ID删除所有关系
     *
     * @param int $collectionId Collection ID | Collection ID
     * @param int|null $tenantId Tenant ID for isolation | 租户ID
     * @return bool
     */
    public function deleteByCollectionId(int $collectionId, ?int $tenantId = null): bool
    {
        $this->assertCollectionTenant($collectionId, $tenantId);

        return (bool)Db::name($this->table)
            ->where('collection_id', $collectionId)
            ->delete();
    }

    /**
     * Hydrate relationship from database row | 从数据库行填充关系
     *
     * @param array $data Database row | 数据库行
     * @return RelationshipInterface
     */
    protected function hydrate(array $data): RelationshipInterface
    {
        $options = json_decode($data['options'], true) ?? [];

        return new Relationship(
            $data['collection_id'],
            $data['name'],
            [
                'id' => $data['id'],
                'type' => $data['type'],
                'target_collection' => $data['target_collection'],
                'foreign_key' => $data['foreign_key'],
                'local_key' => $data['local_key'],
                'options' => $options,
                'created_at' => $data['created_at'] ?? null,
                'updated_at' => $data['updated_at'] ?? null,
            ]
        );
    }

    /**
     * 构造多租户感知查询 | Build tenant-scoped query
     */
    protected function scopedQuery(int $collectionId, ?int $tenantId = null)
    {
        $query = Db::name($this->table)
            ->alias('r')
            ->where('r.collection_id', $collectionId);

        if ($tenantId !== null) {
            $query->join('lowcode_collections c', 'c.id = r.collection_id')
                ->where('c.tenant_id', $tenantId);
        }

        return $query;
    }

    /**
     * 校验集合租户归属，防止跨租户访问
     */
    protected function assertCollectionTenant(int $collectionId, ?int $tenantId = null): void
    {
        if ($tenantId === null) {
            return;
        }

        $exists = $this->tenantDb
            ->name('lowcode_collections', $tenantId)
            ->where('id', $collectionId)
            ->find();

        if (!$exists) {
            throw new \InvalidArgumentException("Collection {$collectionId} not found in tenant {$tenantId}");
        }
    }
}
