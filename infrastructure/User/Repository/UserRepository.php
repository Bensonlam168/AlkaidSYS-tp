<?php

declare(strict_types=1);

namespace Infrastructure\User\Repository;

use Domain\User\Model\User;
use InvalidArgumentException;
use think\facade\Db;

/**
 * User Repository | 用户仓储
 *
 * 多租户约定：`users` 表带 `tenant_id`，按主键读写一律同时带租户条件；
 * 租户参数是**必填**的，不提供「省略即不过滤」的兜底默认值。
 *
 * @package Infrastructure\User\Repository
 */
class UserRepository
{
    protected string $table = 'users';

    public function save(User $user): int
    {
        $data = [
            'tenant_id' => $user->getTenantId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'password' => $user->getPassword(),
            'name' => $user->getName(),
            'status' => $user->getStatus(),
        ];

        if ($user->getId()) {
            // 更新时同时校验租户，避免跨租户篡改
            // Update with tenant guard to prevent cross-tenant tampering
            Db::name($this->table)
                ->where('id', $user->getId())
                ->where('tenant_id', $user->getTenantId())
                ->update($data);
            return $user->getId();
        }

        return Db::name($this->table)->insertGetId($data);
    }

    /**
     * 按 ID 查找用户（必须带租户）
     * Find user by ID (tenant-scoped)
     *
     * @param int $id 用户ID | User ID
     * @param int $tenantId 租户ID（必填，用于隔离） | Tenant ID (required, for isolation)
     * @return User|null
     */
    public function findById(int $id, int $tenantId): ?User
    {
        $data = Db::name($this->table)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->find();

        if (!$data) {
            return null;
        }

        $user = new User($data['tenant_id'], $data['username'], $data['email'], $data['password']);
        $user->fromArray($data);
        return $user;
    }

    public function findByEmail(int $tenantId, string $email): ?User
    {
        $data = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->find();
        if (!$data) {
            return null;
        }

        $user = new User($data['tenant_id'], $data['username'], $data['email'], $data['password']);
        $user->fromArray($data);
        return $user;
    }

    public function assignRole(int $userId, int $roleId): void
    {
        // 校验用户与角色租户一致，防止跨租户授权
        // Ensure user and role belong to same tenant to avoid cross-tenant assignment
        $userTenantId = Db::name($this->table)
            ->where('id', $userId)
            ->value('tenant_id');
        $roleTenantId = Db::name('roles')
            ->where('id', $roleId)
            ->value('tenant_id');

        if ($userTenantId === null || $roleTenantId === null) {
            throw new InvalidArgumentException('用户或角色不存在');
        }

        if ((int) $userTenantId !== (int) $roleTenantId) {
            throw new InvalidArgumentException('禁止跨租户授权角色');
        }

        $exists = Db::name('user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->find();

        if (!$exists) {
            Db::name('user_roles')->insert([
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);
        }
    }

    public function getRoleIds(int $userId): array
    {
        // Resolve tenant for the given user | 根据用户ID解析所属租户
        $tenantId = Db::name($this->table)
            ->where('id', $userId)
            ->value('tenant_id');

        if ($tenantId === null) {
            // User not found or no tenant information | 用户不存在或缺少租户信息
            return [];
        }

        // Get raw role IDs from pivot table | 从中间表获取原始角色ID列表
        $roleIds = Db::name('user_roles')
            ->where('user_id', $userId)
            ->column('role_id');

        if (empty($roleIds)) {
            return [];
        }

        // Enforce tenant isolation by filtering roles with matching tenant_id
        // 通过过滤 tenant_id 一致的角色来显式体现多租户隔离
        $rolesById = Db::name('roles')
            ->whereIn('id', $roleIds)
            ->column('tenant_id', 'id');

        $filteredRoleIds = [];
        foreach ($roleIds as $roleId) {
            if (isset($rolesById[$roleId]) && (int) $rolesById[$roleId] === (int) $tenantId) {
                $filteredRoleIds[] = $roleId;
            }
        }

        return array_values(array_unique($filteredRoleIds));
    }

    /**
     * 获取用户分页列表
     * Get paginated user list
     *
     * @param int $tenantId 租户ID | Tenant ID
     * @param array $filters 筛选条件 | Filter conditions
     *                       - keyword: 搜索关键词（用户名、邮箱、姓名）
     *                       - status: 用户状态
     *                       - role_id: 角色ID
     * @param int $page 页码 | Page number
     * @param int $pageSize 每页数量 | Page size
     * @return array ['items' => User[], 'total' => int, 'page' => int, 'page_size' => int]
     */
    public function list(int $tenantId, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = Db::name($this->table)
            ->where('tenant_id', $tenantId);

        // 关键词搜索 | Keyword search
        if (!empty($filters['keyword'])) {
            $keyword = '%' . $filters['keyword'] . '%';
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('username', $keyword)
                    ->whereOr('email', 'like', $keyword)
                    ->whereOr('name', 'like', $keyword);
            });
        }

        // 状态筛选 | Status filter
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        // 角色筛选 | Role filter
        if (!empty($filters['role_id'])) {
            $userIds = Db::name('user_roles')
                ->where('role_id', $filters['role_id'])
                ->column('user_id');
            if (!empty($userIds)) {
                $query->whereIn('id', $userIds);
            } else {
                // 如果没有匹配的用户，返回空结果
                $query->where('id', 0);
            }
        }

        // 获取总数 | Get total count
        $total = $query->count();

        // 分页查询 | Paginated query
        $offset = ($page - 1) * $pageSize;
        $rows = $query->order('id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        // 转换为 User 对象 | Convert to User objects
        $items = [];
        foreach ($rows as $row) {
            $user = new User($row['tenant_id'], $row['username'], $row['email'], $row['password']);
            $user->fromArray($row);
            $items[] = $user;
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 按用户名查找用户
     * Find user by username
     *
     * @param int $tenantId 租户ID | Tenant ID
     * @param string $username 用户名 | Username
     * @return User|null
     */
    public function findByUsername(int $tenantId, string $username): ?User
    {
        $data = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('username', $username)
            ->find();

        if (!$data) {
            return null;
        }

        $user = new User($data['tenant_id'], $data['username'], $data['email'], $data['password']);
        $user->fromArray($data);
        return $user;
    }

    /**
     * 删除用户
     * Delete user
     *
     * @param int $id 用户ID | User ID
     * @param int $tenantId 租户ID | Tenant ID (for tenant isolation)
     * @return bool
     */
    public function delete(int $id, int $tenantId): bool
    {
        // 先删除用户角色关联 | First delete user-role associations
        Db::name('user_roles')->where('user_id', $id)->delete();

        // 删除用户（带租户隔离） | Delete user with tenant isolation
        $affected = Db::name($this->table)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->delete();

        return $affected > 0;
    }

    /**
     * 设置用户角色（替换所有角色）
     * Set user roles (replace all roles)
     *
     * @param int $userId 用户ID | User ID
     * @param array $roleIds 角色ID列表 | Role ID list
     * @throws InvalidArgumentException 当用户不存在或存在跨租户角色时
     */
    public function setRoles(int $userId, array $roleIds): void
    {
        // 获取用户租户ID | Get user's tenant ID
        $userTenantId = Db::name($this->table)
            ->where('id', $userId)
            ->value('tenant_id');

        if ($userTenantId === null) {
            throw new InvalidArgumentException('用户不存在');
        }

        // 验证所有角色属于同一租户 | Validate all roles belong to the same tenant
        if (!empty($roleIds)) {
            $roleTenantIds = Db::name('roles')
                ->whereIn('id', $roleIds)
                ->column('tenant_id', 'id');

            foreach ($roleIds as $roleId) {
                if (!isset($roleTenantIds[$roleId])) {
                    throw new InvalidArgumentException("角色ID {$roleId} 不存在");
                }
                if ((int) $roleTenantIds[$roleId] !== (int) $userTenantId) {
                    throw new InvalidArgumentException('禁止跨租户授权角色');
                }
            }
        }

        // 使用事务确保原子性 | Use transaction for atomicity
        Db::startTrans();
        try {
            // 删除现有角色 | Delete existing roles
            Db::name('user_roles')->where('user_id', $userId)->delete();

            // 插入新角色 | Insert new roles
            if (!empty($roleIds)) {
                $insertData = [];
                foreach ($roleIds as $roleId) {
                    $insertData[] = [
                        'user_id' => $userId,
                        'role_id' => $roleId,
                    ];
                }
                Db::name('user_roles')->insertAll($insertData);
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 移除用户角色
     * Remove role from user
     *
     * @param int $userId 用户ID | User ID
     * @param int $roleId 角色ID | Role ID
     * @return bool
     */
    public function removeRole(int $userId, int $roleId): bool
    {
        $affected = Db::name('user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->delete();

        return $affected > 0;
    }

    /**
     * 检查邮箱是否已存在
     * Check if email already exists
     *
     * @param int $tenantId 租户ID | Tenant ID
     * @param string $email 邮箱 | Email
     * @param int|null $excludeId 排除的用户ID（用于编辑时） | Exclude user ID (for editing)
     * @return bool
     */
    public function emailExists(int $tenantId, string $email, ?int $excludeId = null): bool
    {
        $query = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('email', $email);

        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }

        return $query->count() > 0;
    }

    /**
     * 检查用户名是否已存在
     * Check if username already exists
     *
     * @param int $tenantId 租户ID | Tenant ID
     * @param string $username 用户名 | Username
     * @param int|null $excludeId 排除的用户ID（用于编辑时） | Exclude user ID (for editing)
     * @return bool
     */
    public function usernameExists(int $tenantId, string $username, ?int $excludeId = null): bool
    {
        $query = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('username', $username);

        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }

        return $query->count() > 0;
    }

    /**
     * 更新用户密码
     * Update user password
     *
     * @param int $userId 用户ID | User ID
     * @param int $tenantId 租户ID | Tenant ID
     * @param string $hashedPassword 已加密的密码 | Hashed password
     * @return bool
     */
    public function updatePassword(int $userId, int $tenantId, string $hashedPassword): bool
    {
        $affected = Db::name($this->table)
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->update(['password' => $hashedPassword]);

        return $affected > 0;
    }

    /**
     * 更新用户状态
     * Update user status
     *
     * @param int $userId 用户ID | User ID
     * @param int $tenantId 租户ID | Tenant ID
     * @param string $status 新状态 | New status (active/inactive/locked)
     * @return bool
     */
    public function updateStatus(int $userId, int $tenantId, string $status): bool
    {
        $validStatuses = ['active', 'inactive', 'locked'];
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException("无效的状态值: {$status}");
        }

        $affected = Db::name($this->table)
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->update(['status' => $status]);

        return $affected > 0;
    }

    /**
     * 获取用户详情（包含角色信息）
     * Get user detail with roles
     *
     * @param int $id 用户ID | User ID
     * @param int $tenantId 租户ID | Tenant ID
     * @return array|null ['user' => User, 'roles' => array]
     */
    public function findWithRoles(int $id, int $tenantId): ?array
    {
        $user = $this->findById($id, $tenantId);
        if (!$user) {
            return null;
        }

        $roleIds = $this->getRoleIds($id);

        // 获取角色详情 | Get role details
        $roles = [];
        if (!empty($roleIds)) {
            $roles = Db::name('roles')
                ->whereIn('id', $roleIds)
                ->select()
                ->toArray();
        }

        return [
            'user' => $user,
            'roles' => $roles,
        ];
    }
}
