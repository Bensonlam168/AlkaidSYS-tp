<?php

declare(strict_types=1);

namespace Infrastructure\Collection;

use Domain\Model\Collection;
use Infrastructure\Schema\SchemaBuilder;
use think\facade\Db;
use think\facade\Cache;

/**
 * Collection Manager | 集合管理器 (Legacy)
 *
 * Manages the lifecycle of first-generation Collections including CRUD operations, caching, and metadata persistence.
 * 管理第一代集合的生命周期，包括CRUD操作、缓存和元数据持久化。
 *
 * @deprecated since T1-DOMAIN-CLEANUP S3/S4, use Infrastructure\Lowcode\Collection\Service\CollectionManager instead.
 * @package Infrastructure\Collection
 */
class CollectionManager
{
    /**
     * Schema builder instance | Schema构建器实例
     * @var SchemaBuilder
     */
    protected SchemaBuilder $schemaBuilder;

    /**
     * Cache key prefix | 缓存键前缀
     * @var string
     */
    protected string $cachePrefix = 'lowcode:collection:';

    /**
     * Cache TTL in seconds | 缓存过期时间（秒）
     * @var int
     */
    protected int $cacheTtl = 3600;

    /**
     * Constructor | 构造函数
     *
     * @param SchemaBuilder $schemaBuilder Schema builder dependency | Schema构建器依赖
     */
    public function __construct(SchemaBuilder $schemaBuilder)
    {
        $this->schemaBuilder = $schemaBuilder;
    }

    /**
     * Create a new collection | 创建新集合
     *
     * This method will:
     * 1. Create the physical database table
     * 2. Save collection metadata
     * 3. Clear cache
     *
     * 此方法将：
     * 1. 创建物理数据库表
     * 2. 保存集合元数据
     * 3. 清除缓存
     *
     * @param Collection $collection Collection instance | 集合实例
     * @return void
     * @throws \Exception If table creation fails | 如果创建表失败
     */
    public function create(Collection $collection): void
    {
        // 1. Create physical table | 创建物理表
        $columns = [];

        // Add primary key first | 首先添加主键
        $columns['id'] = [
            'type' => 'INT',
            'primary' => true,
            'auto_increment' => true,
            'comment' => 'Primary key | 主键'
        ];

        foreach ($collection->getFieldDefinitions() as $field) {
            $fieldArray = $field->toArray();
            $columns[$field->getName()] = [
                'type' => $this->mapFieldTypeToMysql($field->getType()),
                'length' => $this->getFieldLength($field->getType()),
                'nullable' => $fieldArray['nullable'] ?? true,
                'default' => $fieldArray['default'] ?? null,
            ];
        }

        $this->schemaBuilder->createTable(
            $collection->getTableName(),
            $columns,
            [],
            'InnoDB',
            'Low-code collection: ' . $collection->getName()
        );

        // 2. Save collection metadata | 保存集合元数据
        Db::name('lowcode_collections')->insert([
            'name' => $collection->getName(),
            'table_name' => $collection->getTableName(),
            'schema' => json_encode($collection->toArray()),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 3. Clear cache | 清除缓存
        $this->clearCache($collection->getName());
    }

    /**
     * Get a collection by name | 根据名称获取集合
     *
     * This method will:
     * 1. Try to get from cache first
     * 2. If not cached, load from database
     * 3. Cache the result for future use
     *
     * 此方法将：
     * 1. 首先尝试从缓存获取
     * 2. 如果未缓存，从数据库加载
     * 3. 缓存结果供将来使用
     *
     * @param string $name Collection name | 集合名称
     * @return Collection|null
     */
    public function get(string $name): ?Collection
    {
        // 1. Try cache first | 首先尝试缓存
        $cached = Cache::get($this->cacheKey($name));
        if ($cached) {
            return unserialize($cached);
        }

        // 2. Load from database | 从数据库加载
        $data = Db::name('lowcode_collections')
            ->where('name', $name)
            ->find();

        if (!$data) {
            return null;
        }

        // 3. Reconstruct collection | 重构集合
        $schema = json_decode($data['schema'], true);
        $collection = new Collection($name, $schema);

        // 4. Cache it | 缓存
        Cache::set($this->cacheKey($name), serialize($collection), $this->cacheTtl);

        return $collection;
    }

    /**
     * Update an existing collection | 更新现有集合
     *
     * @param Collection $collection Collection instance | 集合实例
     * @return void
     */
    public function update(Collection $collection): void
    {
        // 1. Update table structure (incremental field changes) | 更新表结构（增量字段变更）
        // TODO: Implement incremental field updates (add/drop columns)
        // TODO: 实现增量字段更新（添加/删除列）

        // 2. Update metadata | 更新元数据
        Db::name('lowcode_collections')
            ->where('name', $collection->getName())
            ->update([
                'schema' => json_encode($collection->toArray()),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        // 3. Clear cache | 清除缓存
        $this->clearCache($collection->getName());
    }

    /**
     * Delete a collection | 删除集合
     *
     * This method will:
     * 1. Drop the physical table
     * 2. Delete metadata
     * 3. Clear cache
     *
     * 此方法将：
     * 1. 删除物理表
     * 2. 删除元数据
     * 3. 清除缓存
     *
     * @param string $name Collection name | 集合名称
     * @return void
     */
    public function delete(string $name): void
    {
        $collection = $this->get($name);
        if (!$collection) {
            return;
        }

        // 1. Drop physical table | 删除物理表
        $this->schemaBuilder->dropTable($collection->getTableName());

        // 2. Delete metadata | 删除元数据
        Db::name('lowcode_collections')->where('name', $name)->delete();

        // 3. Clear cache | 清除缓存
        $this->clearCache($name);
    }

    /**
     * Map field type to MySQL type | 将字段类型映射到MySQL类型
     *
     * @param string $fieldType Field type | 字段类型
     * @return string MySQL type | MySQL类型
     */
    protected function mapFieldTypeToMysql(string $fieldType): string
    {
        return match ($fieldType) {
            'string' => 'VARCHAR',
            'integer' => 'INT',
            'boolean' => 'TINYINT',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'text' => 'TEXT',
            'float', 'decimal' => 'DECIMAL',
            default => 'VARCHAR'
        };
    }

    /**
     * Get default length for field type | 获取字段类型的默认长度
     *
     * @param string $fieldType Field type | 字段类型
     * @return int|null Default length | 默认长度
     */
    protected function getFieldLength(string $fieldType): ?int
    {
        return match ($fieldType) {
            'string' => 255,
            'integer' => 11,
            'boolean' => 1,
            default => null
        };
    }

    /**
     * Get cache key for a collection | 获取集合的缓存键
     *
     * @param string $name Collection name | 集合名称
     * @return string
     */
    protected function cacheKey(string $name): string
    {
        return $this->cachePrefix . $name;
    }

    /**
     * Clear cache for a collection | 清除集合的缓存
     *
     * @param string $name Collection name | 集合名称
     * @return void
     */
    protected function clearCache(string $name): void
    {
        Cache::delete($this->cacheKey($name));
    }
}
