<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Repository;

use Domain\Permission\Model\Permission;
use Infrastructure\Permission\Cache\PermissionCacheInvalidator;
use think\facade\Db;

/**
 * Permission Repository | 权限仓储
 *
 * Provides data access operations for permissions.
 * 提供权限的数据访问操作。
 *
 * @package Infrastructure\Permission\Repository
 */
class PermissionRepository
{
    protected string $table = 'permissions';

    /**
     * 权限缓存失效器（ER-06）
     *
     * 构造注入（默认自建）：生产行为不变，测试可注入「会抛异常的失效器」
     * 以证明「清缓存失败不影响写入」。
     */
    protected PermissionCacheInvalidator $cacheInvalidator;

    /**
     * @param PermissionCacheInvalidator|null $cacheInvalidator 缓存失效器（默认新建）
     */
    public function __construct(?PermissionCacheInvalidator $cacheInvalidator = null)
    {
        $this->cacheInvalidator = $cacheInvalidator ?? new PermissionCacheInvalidator();
    }

    /**
     * Find permission by ID | 根据 ID 查找权限
     *
     * @param int $id Permission ID | 权限 ID
     * @return Permission|null
     */
    public function findById(int $id): ?Permission
    {
        $data = Db::name($this->table)->where('id', $id)->find();

        if ($data === null) {
            return null;
        }

        return $this->hydrate($data);
    }

    /**
     * Find permission by slug | 根据 slug 查找权限
     *
     * @param string $slug Permission slug | 权限标识
     * @return Permission|null
     */
    public function findBySlug(string $slug): ?Permission
    {
        $data = Db::name($this->table)->where('slug', $slug)->find();

        if ($data === null) {
            return null;
        }

        return $this->hydrate($data);
    }

    /**
     * Get all permissions | 获取所有权限
     *
     * @return array<Permission>
     */
    public function all(): array
    {
        $records = Db::name($this->table)->order('resource', 'asc')->order('action', 'asc')->select()->toArray();

        return array_map(fn ($data) => $this->hydrate($data), $records);
    }

    /**
     * List permissions with pagination | 分页列表权限
     *
     * @param array $filters Filter conditions | 筛选条件
     * @param int $page Page number | 页码
     * @param int $pageSize Page size | 每页数量
     * @return array{list: array, total: int, page: int, pageSize: int}
     */
    public function list(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = Db::name($this->table);

        // Apply filters | 应用筛选条件
        if (!empty($filters['name'])) {
            $query->whereLike('name', '%' . $filters['name'] . '%');
        }

        if (!empty($filters['slug'])) {
            $query->whereLike('slug', '%' . $filters['slug'] . '%');
        }

        if (!empty($filters['resource'])) {
            $query->where('resource', $filters['resource']);
        }

        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        // Get total count | 获取总数
        $total = $query->count();

        // Apply pagination and get data | 应用分页并获取数据
        $offset = ($page - 1) * $pageSize;
        $records = $query
            ->order('resource', 'asc')
            ->order('action', 'asc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(fn ($data) => $this->hydrateToArray($data), $records);

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Save permission | 保存权限
     *
     * 新建权限时还没有任何角色持有它，无需失效缓存；
     * **更新**权限可能改动 `resource` / `action`（即对外权限码 `resource:action`），
     * 所有持有者的缓存都可能是旧的，因此整族失效（ER-06）。
     *
     * @param Permission $permission Permission entity | 权限实体
     * @return int Permission ID | 权限 ID
     */
    public function save(Permission $permission): int
    {
        $data = $permission->toArray();
        unset($data['id']);

        $now = date('Y-m-d H:i:s');

        if ($permission->getId()) {
            $data['updated_at'] = $now;
            Db::name($this->table)->where('id', $permission->getId())->update($data);

            // ER-06：权限定义变更 → 全部权限缓存失效（尽力而为，失败只记 warning）
            $this->cacheInvalidator->invalidateAll();

            return $permission->getId();
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        return Db::name($this->table)->insertGetId($data);
    }

    /**
     * Delete permission | 删除权限
     *
     * 删除前先取「仍持有该权限的用户」，删除后精确失效；取不到时退化为整族失效。
     *
     * @param int $id Permission ID | 权限 ID
     * @return bool
     */
    public function delete(int $id): bool
    {
        // 先记录受影响用户：记录删掉之后就查不出来了
        // Collect affected users before deleting the role_permissions rows
        $affectedUsers = $this->usersOfPermission((int) $id);

        // First delete related role_permissions | 先删除相关的角色权限关联
        Db::name('role_permissions')->where('permission_id', $id)->delete();

        $deleted = Db::name($this->table)->where('id', $id)->delete() > 0;

        // ER-06：权限被删除后，所有持有者的权限集都变了
        if ($deleted) {
            if ($affectedUsers !== []) {
                $this->cacheInvalidator->invalidateUsers($affectedUsers);
            } else {
                $this->cacheInvalidator->invalidateAll();
            }
        }

        return $deleted;
    }

    /**
     * 取「持有指定权限的用户及其租户」
     * Get users (with tenant) holding the given permission
     *
     * **刻意的跨租户查询**（`scripts/check-raw-sql-tenant.php` 会标记本方法）：
     * 删除一个权限后，所有持有者（可能跨多个租户）的缓存都必须失效，
     * 加 `tenant_id` 过滤会漏掉其它租户的持有者。删除范围不会放大：
     * 后续按 `users.tenant_id` 组装缓存键，只删这些用户自己的缓存。
     *
     * @param int $permissionId 权限 ID
     * @return array<int, array{user_id: int, tenant_id: int}>
     */
    protected function usersOfPermission(int $permissionId): array
    {
        try {
            $rows = Db::table('role_permissions')
                ->alias('rp')
                ->join('user_roles ur', 'ur.role_id = rp.role_id')
                ->join('users u', 'u.id = ur.user_id')
                ->where('rp.permission_id', $permissionId)
                ->field('u.id AS user_id, u.tenant_id AS tenant_id')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Check if slug exists | 检查 slug 是否存在
     *
     * @param string $slug Slug to check | 要检查的标识
     * @param int|null $excludeId ID to exclude | 排除的 ID
     * @return bool
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $query = Db::name($this->table)->where('slug', $slug);

        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }

        return $query->count() > 0;
    }

    /**
     * Get all unique resources | 获取所有唯一资源
     *
     * @return array<string>
     */
    public function getResources(): array
    {
        return Db::name($this->table)
            ->distinct(true)
            ->where('resource', '<>', '')
            ->whereNotNull('resource')
            ->column('resource');
    }

    /**
     * Get all unique actions | 获取所有唯一操作
     *
     * @return array<string>
     */
    public function getActions(): array
    {
        return Db::name($this->table)
            ->distinct(true)
            ->where('action', '<>', '')
            ->whereNotNull('action')
            ->column('action');
    }

    /**
     * Get permissions grouped by resource | 获取按资源分组的权限
     *
     * @return array<string, array>
     */
    public function getGroupedByResource(): array
    {
        $all = $this->all();
        $grouped = [];

        foreach ($all as $permission) {
            $resource = $permission->getResource() ?? 'other';
            if (!isset($grouped[$resource])) {
                $grouped[$resource] = [];
            }
            $grouped[$resource][] = $permission->toArray();
        }

        return $grouped;
    }

    /**
     * Get roles that have this permission | 获取拥有此权限的角色
     *
     * @param int $permissionId Permission ID | 权限 ID
     * @return array<int>
     */
    public function getRolesWithPermission(int $permissionId): array
    {
        return Db::name('role_permissions')
            ->where('permission_id', $permissionId)
            ->column('role_id');
    }

    /**
     * Hydrate array to Permission entity | 将数组转换为权限实体
     *
     * @param array $data Raw data | 原始数据
     * @return Permission
     */
    protected function hydrate(array $data): Permission
    {
        $permission = new Permission($data['name'], $data['slug']);
        $permission->setId((int)$data['id']);
        $permission->fromArray($data);

        return $permission;
    }

    /**
     * Hydrate array with full data for API response | 将数组转换为完整数据用于 API 响应
     *
     * @param array $data Raw data | 原始数据
     * @return array
     */
    protected function hydrateToArray(array $data): array
    {
        return [
            'id' => (int)$data['id'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'resource' => $data['resource'],
            'action' => $data['action'],
            'description' => $data['description'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }
}
