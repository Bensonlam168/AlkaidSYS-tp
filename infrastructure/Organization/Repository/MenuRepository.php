<?php

declare(strict_types=1);

namespace Infrastructure\Organization\Repository;

use Domain\Organization\Model\Menu;
use think\facade\Db;

/**
 * Menu Repository | 菜单仓储
 *
 * Provides data access operations for menus.
 * 提供菜单的数据访问操作。
 *
 * 多租户约定：`menus` 表带 `tenant_id`，按主键读写一律同时带租户条件；
 * 租户参数是**必填**的，不提供「省略即不过滤」的兜底默认值。
 *
 * @package Infrastructure\Organization\Repository
 */
class MenuRepository
{
    protected string $table = 'menus';

    /**
     * Find menu by ID | 根据 ID 查找菜单
     *
     * @param int $id Menu ID | 菜单 ID
     * @param int $tenantId Tenant ID for isolation (required) | 租户 ID（必填，用于隔离）
     * @return Menu|null
     */
    public function findById(int $id, int $tenantId): ?Menu
    {
        $data = Db::name($this->table)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->find();

        if ($data === null) {
            return null;
        }

        return $this->hydrate($data);
    }

    /**
     * Get all menus as tree structure | 获取所有菜单（树形结构）
     *
     * @param int $tenantId Tenant ID | 租户 ID
     * @return array Tree structure of menus | 菜单树形结构
     */
    public function getTree(int $tenantId): array
    {
        $data = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // Transform to camelCase for frontend | 转换为驼峰命名供前端使用
        $data = array_map([$this, 'transformToCamelCase'], $data);

        return $this->buildTree($data);
    }

    /**
     * Get flat list of all menus | 获取菜单列表（扁平）
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
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (isset($filters['status'])) {
            $query->where('status', (int)$filters['status']);
        }

        $data = $query
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // Decode JSON meta field | 解码 JSON meta 字段
        foreach ($data as &$item) {
            if (isset($item['meta']) && is_string($item['meta'])) {
                $item['meta'] = json_decode($item['meta'], true);
            }
        }

        return $data;
    }

    /**
     * Save menu | 保存菜单
     *
     * @param Menu $menu Menu entity | 菜单实体
     * @return int Menu ID | 菜单 ID
     */
    public function save(Menu $menu): int
    {
        $data = $menu->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at']);

        // Encode meta to JSON | 将 meta 编码为 JSON
        if (isset($data['meta']) && is_array($data['meta'])) {
            $data['meta'] = json_encode($data['meta'], JSON_UNESCAPED_UNICODE);
        }

        $now = date('Y-m-d H:i:s');

        if ($menu->getId()) {
            $data['updated_at'] = $now;
            Db::name($this->table)
                ->where('id', $menu->getId())
                ->where('tenant_id', $menu->getTenantId())
                ->update($data);
            return $menu->getId();
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        return Db::name($this->table)->insertGetId($data);
    }

    /**
     * Delete menu | 删除菜单
     *
     * @param int $id Menu ID | 菜单 ID
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
     * Check if menu has children | 检查菜单是否有子菜单
     *
     * @param int $id Menu ID | 菜单 ID
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
     * Check if menu name exists | 检查菜单名称是否存在
     *
     * @param string $name Menu name | 菜单名称
     * @param int $tenantId Tenant ID | 租户 ID
     * @param int|null $excludeId ID to exclude | 排除的 ID
     * @return bool
     */
    public function nameExists(string $name, int $tenantId, ?int $excludeId = null): bool
    {
        $query = Db::name($this->table)
            ->where('name', $name)
            ->where('tenant_id', $tenantId);

        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }

        return $query->count() > 0;
    }

    /**
     * Check if menu path exists | 检查菜单路径是否存在
     *
     * @param string $path Menu path | 菜单路径
     * @param int $tenantId Tenant ID | 租户 ID
     * @param int|null $excludeId ID to exclude | 排除的 ID
     * @return bool
     */
    public function pathExists(string $path, int $tenantId, ?int $excludeId = null): bool
    {
        $query = Db::name($this->table)
            ->where('path', $path)
            ->where('tenant_id', $tenantId);

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
     * Hydrate menu from database row | 从数据库行水合菜单
     *
     * @param array $data Database row | 数据库行
     * @return Menu
     */
    protected function hydrate(array $data): Menu
    {
        // Decode JSON meta field | 解码 JSON meta 字段
        if (isset($data['meta']) && is_string($data['meta'])) {
            $data['meta'] = json_decode($data['meta'], true);
        }

        $menu = new Menu((int)$data['tenant_id'], $data['name']);
        $menu->fromArray($data);
        return $menu;
    }

    /**
     * Transform database row to camelCase for frontend | 转换数据库行为驼峰命名供前端使用
     *
     * @param array $item Database row | 数据库行
     * @return array Transformed row | 转换后的行
     */
    protected function transformToCamelCase(array $item): array
    {
        // Decode JSON meta field | 解码 JSON meta 字段
        if (isset($item['meta']) && is_string($item['meta'])) {
            $item['meta'] = json_decode($item['meta'], true);
        }

        return [
            'id' => (int)$item['id'],
            'tenantId' => (int)$item['tenant_id'],
            'pid' => $item['pid'] !== null ? (int)$item['pid'] : null,
            'name' => $item['name'],
            'type' => $item['type'],
            'path' => $item['path'],
            'component' => $item['component'],
            'redirect' => $item['redirect'],
            'authCode' => $item['auth_code'],
            'meta' => $item['meta'],
            'status' => (int)$item['status'],
            'sort' => (int)$item['sort'],
            'createdAt' => $item['created_at'],
            'updatedAt' => $item['updated_at'],
        ];
    }
}
