<?php

declare(strict_types=1);

namespace Infrastructure\User\Repository;

use Domain\User\Model\User;
use InvalidArgumentException;
use think\facade\Db;

/**
 * User Repository | 用户仓储
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

    public function findById(int $id, ?int $tenantId = null): ?User
    {
        $query = Db::name($this->table)->where('id', $id);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $data = $query->find();
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
}
