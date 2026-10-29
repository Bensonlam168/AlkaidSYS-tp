<?php

declare(strict_types=1);

namespace Infrastructure\Organization\Repository;

use Domain\Organization\Model\Dept;
use think\facade\Db;

/**
 * Dept Repository | 部门仓储
 *
 * Provides data access operations for departments.
 * 提供部门的数据访问操作。
 *
 * @package Infrastructure\Organization\Repository
 */
class DeptRepository
{
    protected string $table = 'depts';

    /**
     * Find department by ID | 根据 ID 查找部门
     *
     * @param int $id Department ID | 部门 ID
     * @param int|null $tenantId Optional tenant ID for isolation | 可选租户 ID 用于隔离
     * @return Dept|null
     */
    public function findById(int $id, ?int $tenantId = null): ?Dept
    {
        $query = Db::name($this->table)->where('id', $id);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $data = $query->find();

        if ($data === null) {
            return null;
        }

        return $this->hydrate($data);
    }

    /**
     * Get all departments as tree structure | 获取所有部门（树形结构）
     *
     * @param int $tenantId Tenant ID | 租户 ID
     * @return array Tree structure of departments | 部门树形结构
     */
    public function getTree(int $tenantId): array
    {
        $data = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $this->buildTree($data);
    }

    /**
     * Get flat list of all departments | 获取部门列表（扁平）
     *
     * @param int $tenantId Tenant ID | 租户 ID
     * @param array $filters Filter conditions | 筛选条件
     * @return array
     */
    public function list(int $tenantId, array $filters = []): array
    {
        $query = Db::name($this->table)->where('tenant_id', $tenantId);

        // Apply filters | 应用筛选条件
        if (!empty($filters['name'])) {
            $query->whereLike('name', '%' . $filters['name'] . '%');
        }
        if (isset($filters['status'])) {
            $query->where('status', (int)$filters['status']);
        }

        return $query
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * Save department | 保存部门
     *
     * @param Dept $dept Department entity | 部门实体
     * @return int Department ID | 部门 ID
     */
    public function save(Dept $dept): int
    {
        $data = $dept->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at']);

        $now = date('Y-m-d H:i:s');

        if ($dept->getId()) {
            $data['updated_at'] = $now;
            Db::name($this->table)
                ->where('id', $dept->getId())
                ->where('tenant_id', $dept->getTenantId())
                ->update($data);
            return $dept->getId();
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        return Db::name($this->table)->insertGetId($data);
    }

    /**
     * Delete department | 删除部门
     *
     * @param int $id Department ID | 部门 ID
     * @param int $tenantId Tenant ID for isolation | 租户 ID 用于隔离
     * @return bool
     */
    public function delete(int $id, int $tenantId): bool
    {
        return Db::name($this->table)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->delete() > 0;
    }

    /**
     * Check if department has children | 检查部门是否有子部门
     *
     * @param int $id Department ID | 部门 ID
     * @param int $tenantId Tenant ID | 租户 ID
     * @return bool
     */
    public function hasChildren(int $id, int $tenantId): bool
    {
        return Db::name($this->table)
            ->where('pid', $id)
            ->where('tenant_id', $tenantId)
            ->count() > 0;
    }

    /**
     * Check if department name exists | 检查部门名称是否存在
     *
     * @param string $name Department name | 部门名称
     * @param int $tenantId Tenant ID | 租户 ID
     * @param int|null $pid Parent ID | 父 ID
     * @param int|null $excludeId ID to exclude | 排除的 ID
     * @return bool
     */
    public function nameExists(string $name, int $tenantId, ?int $pid = null, ?int $excludeId = null): bool
    {
        $query = Db::name($this->table)
            ->where('name', $name)
            ->where('tenant_id', $tenantId);

        if ($pid !== null) {
            $query->where('pid', $pid);
        } else {
            $query->whereNull('pid');
        }

        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }

        return $query->count() > 0;
    }

    /**
     * Build tree structure from flat data | 从扁平数据构建树形结构
     *
     * @param array $data Flat data | 扁平数据
     * @param int|null $parentId Parent ID | 父 ID
     * @return array
     */
    protected function buildTree(array $data, ?int $parentId = null): array
    {
        $tree = [];
        foreach ($data as $item) {
            $itemPid = $item['pid'] !== null ? (int)$item['pid'] : null;
            if ($itemPid === $parentId) {
                $dept = $this->hydrate($item);
                $children = $this->buildTree($data, (int)$item['id']);
                if (!empty($children)) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        return $tree;
    }

    /**
     * Hydrate department from database row | 从数据库行水合部门
     *
     * @param array $data Database row | 数据库行
     * @return Dept
     */
    protected function hydrate(array $data): Dept
    {
        $dept = new Dept((int)$data['tenant_id'], $data['name']);
        $dept->fromArray($data);
        return $dept;
    }
}
