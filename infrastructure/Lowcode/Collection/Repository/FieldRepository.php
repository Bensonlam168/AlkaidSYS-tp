<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Repository;

use Domain\Lowcode\Collection\Interfaces\FieldInterface;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use Infrastructure\Tenant\TenantAwareDb;
use think\facade\Db;

/**
 * Field Repository | 字段仓储
 *
 * Handles persistence of Field entities.
 * 处理字段实体的持久化。
 *
 * @package Infrastructure\Lowcode\Collection\Repository
 */
class FieldRepository
{
    protected string $table = 'lowcode_fields';
    protected TenantAwareDb $tenantDb;

    public function __construct(?TenantAwareDb $tenantDb = null)
    {
        $this->tenantDb = $tenantDb ?? new TenantAwareDb();
    }

    /**
     * Save field | 保存字段
     *
     * @param FieldInterface $field Field to save | 要保存的字段
     * @param int $collectionId Collection ID | Collection ID
     * @param int|null $tenantId Tenant ID for isolation | 用于隔离的租户ID
     * @return int Field ID | 字段ID
     */
    public function save(FieldInterface $field, int $collectionId, ?int $tenantId = null): int
    {
        if ($tenantId === null) {
            throw new \InvalidArgumentException('tenant_id is required when saving field metadata');
        }

        $data = [
            'collection_id' => $collectionId,
            'tenant_id' => $tenantId,
            'name' => $field->getName(),
            'type' => $field->getType(),
            'db_type' => $field->getDbType(),
            'title' => $field->getTitle(),
            'nullable' => $field->isNullable(),
            'default' => $field->getDefault(),
            'options' => json_encode($field->getOptions()),
        ];

        $this->assertCollectionTenant($collectionId, $tenantId);

        // Check if field exists | 检查字段是否存在
        $exists = $this->scopedQuery($collectionId, $tenantId)
            ->where('f.name', $field->getName())
            ->find();

        if ($exists) {
            // Update existing | 更新已存在的
            // lowcode_fields 表未包含 tenant_id 字段，通过 collection_id → lowcode_collections 实现租户隔离
            Db::name($this->table)
                ->where('id', $exists['id'])
                ->where('tenant_id', $tenantId)
                ->update($data);
            return (int)$exists['id'];
        } else {
            // Insert new | 插入新的
            return (int) Db::name($this->table)->insertGetId($data);
        }
    }

    /**
     * Find fields by collection ID | 按Collection ID查找字段
     *
     * @param int $collectionId Collection ID | Collection ID
     * @param int|null $tenantId Tenant ID for isolation | 租户ID
     * @return array<string, FieldInterface>
     */
    public function findByCollectionId(int $collectionId, ?int $tenantId = null): array
    {
        if ($tenantId === null) {
            throw new \InvalidArgumentException('tenant_id is required when reading field metadata');
        }

        $rows = $this->scopedQuery($collectionId, $tenantId)
            ->order('f.sort', 'asc')
            ->order('f.id', 'asc')
            ->select()
            ->toArray();

        $fields = [];
        foreach ($rows as $row) {
            $field = $this->hydrate($row);
            $fields[$field->getName()] = $field;
        }

        return $fields;
    }

    /**
     * Delete field | 删除字段
     *
     * @param int $collectionId Collection ID | Collection ID
     * @param string $fieldName Field name | 字段名称
     * @param int|null $tenantId Tenant ID for isolation | 租户ID
     * @return bool
     */
    public function delete(int $collectionId, string $fieldName, ?int $tenantId = null): bool
    {
        $this->assertCollectionTenant($collectionId, $tenantId);

        if ($tenantId === null) {
            throw new \InvalidArgumentException('tenant_id is required when deleting field metadata');
        }

        // 通过 assertCollectionTenant 已保证 collectionId 属于 tenantId
        return (bool) Db::name($this->table)
            ->where('collection_id', $collectionId)
            ->where('tenant_id', $tenantId)
            ->where('name', $fieldName)
            ->delete();
    }

    /**
     * Delete all fields by collection ID | 按Collection ID删除所有字段
     *
     * @param int $collectionId Collection ID | Collection ID
     * @param int|null $tenantId Tenant ID for isolation | 租户ID
     * @return bool
     */
    public function deleteByCollectionId(int $collectionId, ?int $tenantId = null): bool
    {
        $this->assertCollectionTenant($collectionId, $tenantId);

        if ($tenantId === null) {
            throw new \InvalidArgumentException('tenant_id is required when deleting fields');
        }

        // 通过 assertCollectionTenant 已保证 collectionId 属于 tenantId
        return (bool) Db::name($this->table)
            ->where('collection_id', $collectionId)
            ->where('tenant_id', $tenantId)
            ->delete();
    }

    /**
     * Hydrate field from database row | 从数据库行填充字段
     *
     * @param array $data Database row | 数据库行
     * @return FieldInterface
     */
    protected function hydrate(array $data): FieldInterface
    {
        $options = json_decode($data['options'], true) ?? [];
        $options['title'] = $data['title'];
        $options['nullable'] = (bool)$data['nullable'];
        $options['default'] = $data['default'];

        return FieldFactory::create(
            $data['type'],
            $data['name'],
            $options
        );
    }

    /**
     * 构造多租户感知的查询 | Build tenant-scoped query
     */
    protected function scopedQuery(int $collectionId, ?int $tenantId = null)
    {
        $query = Db::name($this->table)
            ->alias('f')
            ->where('f.collection_id', $collectionId);

        if ($tenantId !== null) {
            $query->where('f.tenant_id', $tenantId);
        }

        return $query;
    }

    /**
     * 校验集合是否属于指定租户（防止跨租户访问）
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
