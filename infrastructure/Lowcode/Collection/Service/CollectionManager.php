<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Service;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;
use Domain\Lowcode\Collection\Interfaces\FieldInterface;
use Domain\Schema\Interfaces\SchemaBuilderInterface;
use Infrastructure\Lowcode\Collection\Repository\CollectionRepository;
use Infrastructure\Lowcode\Collection\Repository\FieldRepository;
use Infrastructure\Lowcode\Collection\Repository\RelationshipRepository;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Event;

/**
 * Collection Manager Service | Collection管理服务
 *
 * Manages Collection CRUD operations with physical table creation and caching.
 * 管理Collection的CRUD操作，包含物理表创建和缓存。
 *
 * @package Infrastructure\Lowcode\Collection\Service
 */
class CollectionManager
{
    protected SchemaBuilderInterface $schemaBuilder;
    protected CollectionRepository $collectionRepo;
    protected FieldRepository $fieldRepo;
    protected RelationshipRepository $relationshipRepo;

    protected string $cachePrefix = 'lowcode:collection:';
    protected int $cacheTtl = 3600; // 1 hour | 1小时

    /**
     * Constructor | 构造函数
     *
     * @param SchemaBuilderInterface $schemaBuilder Schema builder | Schema构建器
     * @param CollectionRepository $collectionRepo Collection repository | Collection仓储
     * @param FieldRepository $fieldRepo Field repository | 字段仓储
     * @param RelationshipRepository $relationshipRepo Relationship repository | 关系仓储
     */
    public function __construct(
        SchemaBuilderInterface $schemaBuilder,
        CollectionRepository $collectionRepo,
        FieldRepository $fieldRepo,
        RelationshipRepository $relationshipRepo
    ) {
        $this->schemaBuilder = $schemaBuilder;
        $this->collectionRepo = $collectionRepo;
        $this->fieldRepo = $fieldRepo;
        $this->relationshipRepo = $relationshipRepo;
    }

    /**
     * Create collection | 创建Collection
     *
     * Creates collection metadata and physical database table.
     * 创建Collection元数据和物理数据库表。
     *
     * @param CollectionInterface $collection Collection to create | 要创建的Collection
     * @return void
     * @throws \Exception
     */
    public function create(CollectionInterface $collection): void
    {
        Db::startTrans();
        try {
            // 1. Create physical table | 创建物理表
            $columns = $this->buildColumns($collection->getFields());
            $indexes = $this->buildIndexes();
            $this->schemaBuilder->createTable($collection->getTableName(), $columns, $indexes);

            // 2. Save collection metadata | 保存Collection元数据
            $collectionId = $this->collectionRepo->save($collection);
            $collection->setId($collectionId);

            // 3. Save field metadata | 保存字段元数据
            foreach ($collection->getFields() as $field) {
                $this->fieldRepo->save($field, $collectionId);
            }

            // 4. Save relationship metadata | 保存关系元数据
            foreach ($collection->getRelationships() as $relationship) {
                $this->relationshipRepo->save($relationship, $collectionId);
            }

            // 5. Cache the collection | 缓存Collection
            $this->cache($collection);

            // 6. Trigger event | 触发事件
            Event::trigger('lowcode.collection.created', [
                'collection' => $collection,
            ]);

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * Get collection by name | 按名称获取Collection
     *
     * @param string   $name     Collection name | Collection名称
     * @param int|null $tenantId Tenant ID (null means system template) | 租户ID（null 表示系统模板）
     * @return CollectionInterface|null
     */
    public function get(string $name, ?int $tenantId = null): ?CollectionInterface
    {
        $effectiveTenantId = $tenantId ?? 0;

        // Try cache first | 优先从缓存获取
        $cacheKey = $this->buildCacheKey($name, $effectiveTenantId);
        $cached = Cache::get($cacheKey);

        if ($cached !== null && $cached !== false) {
            return $cached;
        }

        // Load from database (tenant-scoped) | 从数据库按租户范围加载
        $collection = $this->collectionRepo->findByName($name, $effectiveTenantId);

        if (!$collection) {
            return null;
        }

        // Load fields | 加载字段
        $fields = $this->fieldRepo->findByCollectionId($collection->getId());
        foreach ($fields as $field) {
            $collection->addField($field);
        }

        // Load relationships | 加载关系
        $relationships = $this->relationshipRepo->findByCollectionId($collection->getId());
        foreach ($relationships as $relName => $relationship) {
            $collection->addRelationship($relName, $relationship);
        }

        // Cache the collection (tenant-scoped) | 缓存Collection（按租户隔离）
        $this->cache($collection, $effectiveTenantId);

        return $collection;
    }

    /**
     * Update collection | 更新Collection
     *
     * @param CollectionInterface $collection Collection to update | 要更新的Collection
     * @return void
     * @throws \Exception
     */
    public function update(CollectionInterface $collection): void
    {
        if (!$collection->getId()) {
            throw new \InvalidArgumentException('Collection ID is required for update');
        }

        Db::startTrans();
        try {
            // 1. Update collection metadata | 更新Collection元数据
            $this->collectionRepo->save($collection);

            // 2. Update fields (simplified: delete and recreate) | 更新字段（简化：删除后重建）
            $this->fieldRepo->deleteByCollectionId($collection->getId());
            foreach ($collection->getFields() as $field) {
                $this->fieldRepo->save($field, $collection->getId());
            }

            // 3. Update relationships | 更新关系
            $this->relationshipRepo->deleteByCollectionId($collection->getId());
            foreach ($collection->getRelationships() as $relationship) {
                $this->relationshipRepo->save($relationship, $collection->getId());
            }

            // 4. Clear cache | 清除缓存
            $this->clearCache($collection->getName());

            // 5. Trigger event | 触发事件
            Event::trigger('lowcode.collection.updated', [
                'collection' => $collection,
            ]);

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * Delete collection | 删除Collection
     *
     * Deletes collection metadata and optionally drops physical table.
     * 删除Collection元数据并可选择删除物理表。
     *
     * @param string      $name      Collection name | Collection名称
     * @param bool        $dropTable Drop physical table | 是否删除物理表
     * @param int|null    $tenantId  Tenant ID (reserved for future multi-tenant support)
     *                               租户ID（预留多租户支持，当前仍使用全局表）
     * @return void
     * @throws \Exception
     */
    public function delete(string $name, bool $dropTable = true, ?int $tenantId = null): void
    {
        $collection = $this->get($name, $tenantId);

        if (!$collection) {
            throw new \InvalidArgumentException("Collection not found: {$name}");
        }

        Db::startTrans();
        try {
            $collectionId = $collection->getId();

            // 1. Delete relationships | 删除关系
            $this->relationshipRepo->deleteByCollectionId($collectionId);

            // 2. Delete fields | 删除字段
            $this->fieldRepo->deleteByCollectionId($collectionId);

            // 3. Delete collection | 删除Collection
            $this->collectionRepo->delete($collectionId);

            // 4. Drop physical table if requested | 如果请求则删除物理表
            if ($dropTable && $this->schemaBuilder->hasTable($collection->getTableName())) {
                $this->schemaBuilder->dropTable($collection->getTableName());
            }

            // 5. Clear cache | 清除缓存
            $this->clearCache($name, $tenantId);

            // 6. Trigger event | 触发事件
            Event::trigger('lowcode.collection.deleted', [
                'collection_name' => $name,
                'collection_id' => $collectionId,
            ]);

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * List collections | 列出Collections
     *
     * @param int   $tenantId Tenant ID | 租户ID
     * @param array $filters  Filters | 筛选条件
     * @param int   $page     Page number | 页码
     * @param int   $pageSize Page size | 每页数量
     * @return array{list: array, total: int, page: int, pageSize: int}
     */
    public function list(int $tenantId, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        // NOTE(T-002: lowcode-collections-tenant): P1 起在 Repository/DB 层按 tenant_id 强隔离。
        return $this->collectionRepo->list($filters, $page, $pageSize, $tenantId);
    }

    /**
     * Build columns array for SchemaBuilder | 为SchemaBuilder构建列数组
     *
     * @param array<string, FieldInterface> $fields Fields | 字段
     * @return array<string, array>
     */
    protected function buildColumns(array $fields): array
    {
        $columns = [
            // Auto-increment primary key | 自增主键
            'id' => [
                'type' => 'INT',
                'primary' => true,
                'auto_increment' => true,
                'unsigned' => true,
            ],
            // Tenant scope | 租户维度（0 = 系统模板空间）
            'tenant_id' => [
                'type' => 'INT',
                'length' => 11,
                'nullable' => false,
                'default' => 0,
            ],
            // Site scope | 站点维度（0 = 默认站点）
            'site_id' => [
                'type' => 'INT',
                'length' => 11,
                'nullable' => false,
                'default' => 0,
            ],
        ];

        foreach ($fields as $field) {
            $columns[$field->getName()] = $field->getDbColumn();
        }

        // Add timestamps | 添加时间戳
        $columns['created_at'] = [
            'type' => 'DATETIME',
            'default' => 'CURRENT_TIMESTAMP',
        ];
        $columns['updated_at'] = [
            'type' => 'DATETIME',
            'default' => 'CURRENT_TIMESTAMP',
            'on_update' => 'CURRENT_TIMESTAMP',
        ];

        return $columns;
    }

    /**
     * Build default indexes for dynamic table | 为动态表构建默认索引
     *
     * @return array<string, array>
     */
    protected function buildIndexes(): array
    {
        return [
            // Main lookup index: tenant-scoped primary key access | 主查询索引：按租户范围的主键访问
            'idx_tenant_id_id' => [
                'columns' => ['tenant_id', 'id'],
                'unique' => false,
            ],
        ];
    }

    /**
     * Cache collection | 缓存Collection
     *
     * @param CollectionInterface $collection Collection to cache | 要缓存的Collection
     * @param int                 $tenantId   Tenant ID for cache key (default: 0 system template)
     *                                       缓存使用的租户ID（默认 0 = 系统模板）
     * @return void
     */
    protected function cache(CollectionInterface $collection, int $tenantId = 0): void
    {
        // NOTE(T-002): 当前阶段 Collection 实体尚未包含租户信息，默认将 $tenantId 视为 0（系统模板）。
        // 未来当 Domain Collection 支持 getTenantId() 时，可在此优先从实体读取租户ID。
        $cacheKey = $this->buildCacheKey($collection->getName(), $tenantId);
        Cache::set($cacheKey, $collection, $this->cacheTtl);
    }

    /**
     * Build cache key with tenant scope | 构建带租户维度的缓存键
     *
     * @param string $name     Collection name | Collection名称
     * @param int    $tenantId Tenant ID | 租户ID
     * @return string
     */
    protected function buildCacheKey(string $name, int $tenantId): string
    {
        return $this->cachePrefix . $tenantId . ':' . $name;
    }

    /**
     * Clear cache | 清除缓存
     *
     * @param string   $name     Collection name | Collection名称
     * @param int|null $tenantId Tenant ID (null means system template) | 租户ID（null 表示系统模板）
     * @return void
     */
    public function clearCache(string $name, ?int $tenantId = null): void
    {
        // NOTE(T-002): 多租户场景下建议调用方显式传入 tenantId；未传入时视为系统模板租户 0。
        $effectiveTenantId = $tenantId ?? 0;
        $cacheKey = $this->buildCacheKey($name, $effectiveTenantId);
        Cache::delete($cacheKey);
    }
}
