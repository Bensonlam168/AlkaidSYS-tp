<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Repository;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;
use Domain\Lowcode\Collection\Model\Collection;
use think\facade\Db;

/**
 * Collection Repository | Collection仓储
 *
 * Handles persistence of Collection entities.
 * 处理Collection实体的持久化。
 *
 * @package Infrastructure\Lowcode\Collection\Repository
 */
class CollectionRepository
{
    protected string $table = 'lowcode_collections';

    /**
     * Save collection | 保存Collection
     *
     * @param CollectionInterface $collection Collection to save | 要保存的Collection
     * @return int Collection ID | Collection ID
     */
    public function save(CollectionInterface $collection): int
    {
        $data = [
            'name' => $collection->getName(),
            'table_name' => $collection->getTableName(),
            'schema' => json_encode([
                'title' => $collection->getTitle(),
                'description' => $collection->getDescription(),
                'options' => $collection->getOptions(),
            ]),
        ];

        if ($collection->getId()) {
            // Update existing | 更新已存在的
            Db::name($this->table)
                ->where('id', $collection->getId())
                ->update($data);
            return $collection->getId();
        } else {
            // Insert new | 插入新的
            return (int)Db::name($this->table)->insertGetId($data);
        }
    }

    /**
     * Find by name | 按名称查找
     *
     * @param string $name Collection name | Collection名称
     * @return CollectionInterface|null
     */
    public function findByName(string $name): ?CollectionInterface
    {
        $data = Db::name($this->table)
            ->where('name', $name)
            ->find();

        if (!$data) {
            return null;
        }

        return $this->hydrate($data);
    }

    /**
     * Find by ID | 按ID查找
     *
     * @param int $id Collection ID | Collection ID
     * @return CollectionInterface|null
     */
    public function findById(int $id): ?CollectionInterface
    {
        $data = Db::name($this->table)
            ->where('id', $id)
            ->find();

        if (!$data) {
            return null;
        }

        return $this->hydrate($data);
    }

    /**
     * Delete collection | 删除Collection
     *
     * @param int $id Collection ID | Collection ID
     * @return bool
     */
    public function delete(int $id): bool
    {
        return (bool)Db::name($this->table)
            ->where('id', $id)
            ->delete();
    }

    /**
     * List collections | 列出Collections
     *
     * @param array $filters Filters | 筛选条件
     * @param int $page Page number | 页码
     * @param int $pageSize Page size | 每页数量
     * @return array{list: array, total: int, page: int, pageSize: int}
     */
    public function list(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = Db::name($this->table);

        // Apply filters | 应用筛选
        if (isset($filters['name'])) {
            $query->whereLike('name', '%' . $filters['name'] . '%');
        }

        // Get total | 获取总数
        $total = $query->count();

        // Get list with pagination | 分页获取列表
        $list = $query->page($page, $pageSize)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $collections = array_map(fn ($data) => $this->hydrate($data), $list);

        return [
            'list' => $collections,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Hydrate collection from database row | 从数据库行填充Collection
     *
     * @param array $data Database row | 数据库行
     * @return CollectionInterface
     */
    protected function hydrate(array $data): CollectionInterface
    {
        $schema = json_decode($data['schema'], true) ?? [];

        $collection = new Collection($data['name'], [
            'id' => $data['id'],
            'table_name' => $data['table_name'],
            'title' => $schema['title'] ?? $data['name'],
            'description' => $schema['description'] ?? '',
            'options' => $schema['options'] ?? [],
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ]);

        return $collection;
    }
}
