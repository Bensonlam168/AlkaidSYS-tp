<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Repository;

use Domain\Permission\Model\Role;
use think\facade\Db;

/**
 * Role Repository | 角色仓储
 *
 * Provides data access operations for roles.
 * 提供角色的数据访问操作。
 *
 * @package Infrastructure\Permission\Repository
 */
class RoleRepository
{
    protected string $table = 'roles';

    /**
     * Find role by ID | 根据 ID 查找角色
     *
     * @param int $id Role ID | 角色 ID
     * @param int|null $tenantId Optional tenant ID for isolation | 可选租户 ID 用于隔离
     * @return Role|null
     */
    public function findById(int $id, ?int $tenantId = null): ?Role
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
     * Find role by slug | 根据标识查找角色
     *
     * @param string $slug Role slug | 角色标识
     * @param int $tenantId Tenant ID | 租户 ID
     * @return Role|null
     */
    public function findBySlug(string $slug, int $tenantId): ?Role
    {
        $data = Db::name($this->table)
            ->where('slug', $slug)
            ->where('tenant_id', $tenantId)
            ->find();

        if ($data === null) {
            return null;
        }

        return $this->hydrate($data);
    }

    /**
     * List roles with pagination | 分页列表角色
     *
     * @param int $tenantId Tenant ID | 租户 ID
     * @param array $filters Filter conditions | 筛选条件
     * @param int $page Page number | 页码
     * @param int $pageSize Page size | 每页数量
     * @return array{list: array, total: int, page: int, pageSize: int}
     */
    public function list(int $tenantId, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = Db::name($this->table)->where('tenant_id', $tenantId);

        // Apply filters | 应用筛选条件
        if (!empty($filters['name'])) {
            $query->whereLike('name', '%' . $filters['name'] . '%');
        }
        if (!empty($filters['slug'])) {
            $query->whereLike('slug', '%' . $filters['slug'] . '%');
        }
        if (isset($filters['is_system'])) {
            $query->where('is_system', (bool)$filters['is_system']);
        }

        // Get total count | 获取总数
        $total = $query->count();

        // Get paginated data | 获取分页数据
        $offset = ($page - 1) * $pageSize;
        $data = $query
            ->limit($offset, $pageSize)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        // Hydrate and get permissions | 水合并获取权限
        $list = [];
        foreach ($data as $row) {
            $role = $this->hydrate($row);
            $roleData = $role->toArray();
            $roleData['permissions'] = $this->getRolePermissionIds($role->getId());
            $list[] = $roleData;
        }

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Save role | 保存角色
     *
     * @param Role $role Role entity | 角色实体
     * @return int Role ID | 角色 ID
     */
    public function save(Role $role): int
    {
        $data = $role->toArray();
        unset($data['id']);

        $now = date('Y-m-d H:i:s');

        if ($role->getId()) {
            $data['updated_at'] = $now;
            Db::name($this->table)->where('id', $role->getId())->update($data);
            return $role->getId();
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        return Db::name($this->table)->insertGetId($data);
    }

    /**
     * Delete role | 删除角色
     *
     * @param int $id Role ID | 角色 ID
     * @param int|null $tenantId Tenant ID for isolation | 租户 ID 用于隔离
     * @return bool
     */
    public function delete(int $id, ?int $tenantId = null): bool
    {
        $query = Db::name($this->table)->where('id', $id);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->delete() > 0;
    }

    /**
     * Check if slug exists | 检查标识是否存在
     *
     * @param string $slug Role slug | 角色标识
     * @param int $tenantId Tenant ID | 租户 ID
     * @param int|null $excludeId ID to exclude | 排除的 ID
     * @return bool
     */
    public function slugExists(string $slug, int $tenantId, ?int $excludeId = null): bool
    {
        $query = Db::name($this->table)
            ->where('slug', $slug)
            ->where('tenant_id', $tenantId);

        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }

        return $query->count() > 0;
    }

    /**
     * Get role permission IDs | 获取角色权限 ID 列表
     *
     * @param int $roleId Role ID | 角色 ID
     * @return array<int> Permission IDs | 权限 ID 列表
     */
    public function getRolePermissionIds(int $roleId): array
    {
        return Db::name('role_permissions')
            ->where('role_id', $roleId)
            ->column('permission_id');
    }

    /**
     * Get role permissions with details | 获取角色权限详情
     *
     * @param int $roleId Role ID | 角色 ID
     * @return array Permission details | 权限详情
     */
    public function getRolePermissions(int $roleId): array
    {
        return Db::name('role_permissions')
            ->alias('rp')
            ->join('permissions p', 'rp.permission_id = p.id')
            ->where('rp.role_id', $roleId)
            ->field(['p.id', 'p.name', 'p.slug', 'p.resource', 'p.action', 'p.description'])
            ->select()
            ->toArray();
    }

    /**
     * Sync role permissions | 同步角色权限
     *
     * @param int $roleId Role ID | 角色 ID
     * @param array<int> $permissionIds Permission IDs | 权限 ID 列表
     * @return void
     */
    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        // Delete existing permissions | 删除现有权限
        Db::name('role_permissions')->where('role_id', $roleId)->delete();

        // Insert new permissions | 插入新权限
        if (!empty($permissionIds)) {
            $now = date('Y-m-d H:i:s');
            $data = array_map(function ($permissionId) use ($roleId, $now) {
                return [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                ];
            }, array_unique($permissionIds));

            Db::name('role_permissions')->insertAll($data);
        }
    }

    /**
     * Get all permissions | 获取所有权限
     *
     * @return array All permissions | 所有权限
     */
    public function getAllPermissions(): array
    {
        return Db::name('permissions')
            ->field(['id', 'name', 'slug', 'resource', 'action', 'description'])
            ->order('resource', 'asc')
            ->order('action', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * Hydrate role from database row | 从数据库行水合角色
     *
     * @param array $data Database row | 数据库行
     * @return Role
     */
    protected function hydrate(array $data): Role
    {
        $role = new Role(
            (int)$data['tenant_id'],
            $data['name'],
            $data['slug']
        );

        $role->setId((int)$data['id']);

        // Use reflection or setter to set protected properties
        $reflectionClass = new \ReflectionClass($role);

        if (isset($data['description'])) {
            $descProperty = $reflectionClass->getProperty('description');
            $descProperty->setAccessible(true);
            $descProperty->setValue($role, $data['description']);
        }

        if (isset($data['is_system'])) {
            $systemProperty = $reflectionClass->getProperty('isSystem');
            $systemProperty->setAccessible(true);
            $systemProperty->setValue($role, (bool)$data['is_system']);
        }

        return $role;
    }
}
