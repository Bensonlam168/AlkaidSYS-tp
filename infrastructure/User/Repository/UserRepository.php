<?php

declare(strict_types=1);

namespace Infrastructure\User\Repository;

use Domain\User\Model\User;
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
            'name' => $user->getName(),
            'status' => $user->getStatus(),
        ];

        if ($user->getId()) {
            Db::name($this->table)->where('id', $user->getId())->update($data);
            return $user->getId();
        }

        return Db::name($this->table)->insertGetId($data);
    }

    public function findById(int $id): ?User
    {
        $data = Db::name($this->table)->where('id', $id)->find();
        if (!$data) return null;

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
        if (!$data) return null;

        $user = new User($data['tenant_id'], $data['username'], $data['email'], $data['password']);
        $user->fromArray($data);
        return $user;
    }

    public function assignRole(int $userId, int $roleId): void
    {
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
        return Db::name('user_roles')
            ->where('user_id', $userId)
            ->column('role_id');
    }
}
