<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Service;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;
use Domain\Lowcode\Collection\Interfaces\FieldInterface;
use Domain\Schema\Interfaces\SchemaBuilderInterface;
use Infrastructure\Lowcode\Collection\Repository\CollectionRepository;
use Infrastructure\Lowcode\Collection\Repository\FieldRepository;
use Infrastructure\Lowcode\Collection\Repository\RelationshipRepository;
use Infrastructure\Lowcode\Schema\SchemaVersionService;
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
    protected string $cachePrefix = 'lowcode:collection:';
    protected int $cacheTtl = 3600; // 1 hour | 1小时

    protected SchemaVersionService $schemaVersionService;
    protected TableNameGenerator $tableNameGenerator;

    public function __construct(
        protected readonly SchemaBuilderInterface $schemaBuilder,
        protected readonly CollectionRepository $collectionRepo,
        protected readonly FieldRepository $fieldRepo,
        protected readonly RelationshipRepository $relationshipRepo
    ) {
        // 按需惰性构造，避免破坏现有 DI 配置
        $this->schemaVersionService = new SchemaVersionService();
        $this->tableNameGenerator = new TableNameGenerator();
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
            $effectiveTenantId = $collection->getTenantId() ?? 0;
            $effectiveSiteId = $collection->getSiteId() ?? 0;

            // 1. Create physical table if it does not exist yet | 若物理表不存在则创建
            //
            // NOTE(MT-01/T-069): 动态业务表目前采用单库多租户行级隔离策略，所有租户共享同一张
            // lc_{name} 物理表，通过 tenant_id/site_id 进行隔离。因此当多个租户使用相同的
            // Collection 名称时，应复用同一张物理表，而不是重复执行 CREATE TABLE。
            //
            // 这里通过 SchemaBuilder::hasTable() 进行显式存在性检查，仅在表尚未存在时执行
            // createTable()，以避免 MySQL 抛出 "Table already exists" 异常。
            // 表名策略：支持租户前缀（Phase 2 评估中）或共享表
            $generatedTableName = $this->tableNameGenerator->generate(
                $collection->getName(),
                $collection->getTenantId() ?? 0,
                $collection->getSiteId() ?? 0
            );
            // 若 Domain Collection 已显式设置自定义 table_name，则优先使用，不覆盖
            // 通过对比“默认生成表名”判断是否为自定义表名。
            $tableName = $collection->getTableName();
            if ($tableName === '' || $tableName === $generatedTableName) {
                $tableName = $generatedTableName;
            }
            $collection->setTableName($tableName);
            if (!$this->schemaBuilder->hasTable($tableName)) {
                $columns = $this->buildColumns($collection->getFields());
                $indexes = $this->buildIndexes();
                $this->schemaBuilder->createTable($tableName, $columns, $indexes);
            }

            // 2. Save collection metadata | 保存Collection元数据
            $collectionId = $this->collectionRepo->save($collection);
            $collection->setId($collectionId);

            // 3. Save field metadata | 保存字段元数据
            foreach ($collection->getFields() as $field) {
                $this->fieldRepo->save($field, $collectionId, $effectiveTenantId);
            }

            // 4. Save relationship metadata | 保存关系元数据
            foreach ($collection->getRelationships() as $relationship) {
                $this->relationshipRepo->save($relationship, $collectionId, $effectiveTenantId);
            }

            // 5. 记录 Schema 版本与哈希
            $this->schemaVersionService->recordCollection($collection);

            // 5. Cache the collection | 缓存Collection
            $this->cache($collection, $effectiveTenantId);

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
     * NOTE(租户必填): 原先租户参数是可空的（默认 null），会把「没传租户」静默当成系统模板 0。
     * 现在租户必须由调用方显式给出（系统模板空间就显式传 0），缺参数即 TypeError。
     *
     * @param string $name     Collection name | Collection名称
     * @param int    $tenantId Tenant ID (0 = system template space) | 租户ID（0 表示系统模板空间）
     * @return CollectionInterface|null
     */
    public function get(string $name, int $tenantId): ?CollectionInterface
    {

        // Try cache first | 优先从缓存获取
        $cacheKey = $this->buildCacheKey($name, $tenantId);
        $cached = Cache::get($cacheKey);

        if ($cached !== null && $cached !== false) {
            return $cached;
        }

        // Load from database (tenant-scoped) | 从数据库按租户范围加载
        $collection = $this->collectionRepo->findByName($name, $tenantId);

        if (!$collection) {
            return null;
        }

        // Load fields | 加载字段
        $fields = $this->fieldRepo->findByCollectionId($collection->getId(), $tenantId);
        foreach ($fields as $field) {
            $collection->addField($field);
        }

        // Load relationships | 加载关系
        $relationships = $this->relationshipRepo->findByCollectionId($collection->getId(), $tenantId);
        foreach ($relationships as $relName => $relationship) {
            $collection->addRelationship($relName, $relationship);
        }

        // Cache the collection (tenant-scoped) | 缓存Collection（按租户隔离）
        $this->cache($collection, $tenantId);

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

        $effectiveTenantId = $collection->getTenantId() ?? 0;

        Db::startTrans();
        try {
            // 1. Update collection metadata | 更新Collection元数据
            $this->collectionRepo->save($collection);

            // 2. Update fields (simplified: delete and recreate) | 更新字段（简化：删除后重建）
            $this->fieldRepo->deleteByCollectionId($collection->getId(), $effectiveTenantId);
            foreach ($collection->getFields() as $field) {
                $this->fieldRepo->save($field, $collection->getId(), $effectiveTenantId);
            }

            // 3. Update relationships | 更新关系
            $this->relationshipRepo->deleteByCollectionId($collection->getId(), $effectiveTenantId);
            foreach ($collection->getRelationships() as $relationship) {
                $this->relationshipRepo->save($relationship, $collection->getId(), $effectiveTenantId);
            }

            // 4. 记录 Schema 版本与哈希
            $this->schemaVersionService->recordCollection($collection);

            // 4. Clear cache | 清除缓存
            $this->clearCache($collection->getName(), $effectiveTenantId);

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
     * NOTE(缺陷 A 修复): 原先第 3 步是 `collectionRepo->delete($collectionId)` —— 完全省略租户，
     * 仓储侧于是执行 `where('id', $id)->delete()`，也就是**按主键跨租户删除**。
     * 现在租户一路传下去，`tenant_id` 始终在 where 条件里。
     *
     * NOTE(参数必填): `$tenantId` 原先可空（null 静默降级为 0）；现在必填。
     * 为避免 PHP 的「可选参数在必填参数之前」弃用，`$dropTable` 也一并去掉默认值 ——
     * 所有既有调用点本来都是显式传前三个参数的。
     *
     * @param string $name      Collection name | Collection名称
     * @param bool   $dropTable Drop physical table | 是否删除物理表
     * @param int    $tenantId  Tenant ID (0 = system template space) | 租户ID（0 表示系统模板空间）
     * @return void
     * @throws \Exception
     */
    public function delete(string $name, bool $dropTable, int $tenantId): void
    {
        $collection = $this->get($name, $tenantId);

        if (!$collection) {
            throw new \InvalidArgumentException("Collection not found: {$name}");
        }

        Db::startTrans();
        try {
            $collectionId = $collection->getId();

            // 1. Delete relationships | 删除关系
            $this->relationshipRepo->deleteByCollectionId($collectionId, $tenantId);

            // 2. Delete fields | 删除字段
            $this->fieldRepo->deleteByCollectionId($collectionId, $tenantId);

            // 3. Delete collection | 删除Collection（租户必传，见方法注释「缺陷 A 修复」）
            $this->collectionRepo->delete($collectionId, $tenantId);

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
        return $this->collectionRepo->list($tenantId, $filters, $page, $pageSize);
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
                'default' => '0',
            ],
            // Site scope | 站点维度（0 = 默认站点）
            'site_id' => [
                'type' => 'INT',
                'length' => 11,
                'nullable' => false,
                'default' => '0',
            ],
        ];

        // Add field columns using array_reduce for better performance
        // 使用 array_reduce 添加字段列以提升性能
        $columns = array_reduce(
            $fields,
            fn (array $cols, $field) => $cols + [$field->getName() => $field->getDbColumn()],
            $columns
        );

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
            // Composite index for tenant + site scoped queries | 租户 + 站点维度复合索引
            'idx_tenant_site' => [
                'columns' => ['tenant_id', 'site_id'],
                'unique' => false,
            ],
        ];
    }

    /**
     * Cache collection | 缓存Collection
     *
     * @param CollectionInterface $collection Collection to cache | 要缓存的Collection
     * @param int                 $tenantId   Tenant ID for cache key | 缓存使用的租户ID
     * @return void
     */
    protected function cache(CollectionInterface $collection, int $tenantId): void
    {
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
     * NOTE(租户必填): 原先 null 会被静默当成系统模板 0；现在必须显式给出租户。
     *
     * @param string $name     Collection name | Collection名称
     * @param int    $tenantId Tenant ID (0 = system template space) | 租户ID（0 表示系统模板空间）
     * @return void
     */
    public function clearCache(string $name, int $tenantId): void
    {
        $cacheKey = $this->buildCacheKey($name, $tenantId);
        Cache::delete($cacheKey);
    }
}
