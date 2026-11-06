<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Services;

use Alkaid\Workflow\Engine\interface\services\ITaskActorDirectory;
use Alkaid\Workflow\Engine\Multitenant\TenantScopeManager;
use think\facade\Db;

/**
 * 租户内人员目录的默认实现（读本仓库真实的 `users` / `roles` / `user_roles` / `depts` 表）
 *
 * ── 表结构事实（本实现逐条对齐，不假设不存在的列）─────────────────────────
 * | 表 | 与本能力相关的列 | 说明 |
 * | -- | --------------- | ---- |
 * | `users` | `id` / `tenant_id` / `username` / `name` | **没有** `dept_id` |
 * | `roles` | `id` / `tenant_id` / `slug` / `name` | 角色是租户内的 |
 * | `user_roles` | `user_id` / `role_id` | **没有** `tenant_id`，租户约束走 users |
 * | `depts` | `id` / `tenant_id` / `pid` / `name` | **没有**任何指向用户的列 |
 *
 * 因此：
 * 1）角色 → 用户：`user_roles` 本身没有租户列，租户条件必须落在 `users.tenant_id` 上，
 *    否则「A 租户的角色」会把「B 租户的用户」拉出来（顺序反了也不行：
 *    先按 role_id 取 user_id，再用本租户的 users 过滤，两步都带租户）；
 * 2）部门 → 用户：**做不到**。`users` 没有 `dept_id`，也没有 `user_depts` 之类的关联表，
 *    全仓 grep `dept_id` 只命中注释与未实现的 `IProcessUser`。所以
 *    {@see self::findDeptById()} 只能回答「部门在不在本租户」，
 *    解析器据此给出「`dept:` 已识别但本表结构无法展开」的 WARNING，
 *    而不是编一个人出来或者静默当空集。
 *
 * ── 为什么不用 join 取角色成员 ─────────────────────────────────────────────
 * 本仓库的连接配置带表前缀（`config/database.php` 的 `DB_PREFIX`），
 * `Db::name()` 会自动加前缀，而手写 `join('users u', ...)` 里的表名不会。
 * 为了不在「有前缀的宿主」上悄悄拼出一条不存在的表名，这里走两次单表查询（量都很小）。
 *
 * @package Alkaid\Workflow\Engine\Services
 */
class DefaultTaskActorDirectory implements ITaskActorDirectory
{
    /**
     * {@inheritdoc}
     */
    public function findRoleBySlug(int $tenantId, string $slug): ?array
    {
        $row = TenantScopeManager::forTable('roles')
            ->forTenant($tenantId)
            ->query()
            ->where('slug', $slug)
            ->field('id,tenant_id,name,slug')
            ->find();

        return is_array($row) ? $row : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findUserIdsByRoleId(int $tenantId, int $roleId): array
    {
        // 第一步：角色下的用户ID（user_roles 没有 tenant_id 列，这里只能按 role_id 取）
        $userIds = Db::name('user_roles')
            ->where('role_id', $roleId)
            ->order('user_id', 'asc')
            ->column('user_id');

        $userIds = array_values(array_unique(array_map('intval', (array) $userIds)));

        if ($userIds === []) {
            return [];
        }

        // 第二步：用**本租户**的 users 过滤，把别的租户的用户挡在外面（红线）
        $scoped = TenantScopeManager::forTable('users')
            ->forTenant($tenantId)
            ->query()
            ->whereIn('id', $userIds)
            ->order('id', 'asc')
            ->column('id');

        return array_values(array_map('intval', (array) $scoped));
    }

    /**
     * {@inheritdoc}
     */
    public function findDeptById(int $tenantId, int $deptId): ?array
    {
        $row = TenantScopeManager::forTable('depts')
            ->forTenant($tenantId)
            ->query()
            ->where('id', $deptId)
            ->field('id,tenant_id,name')
            ->find();

        return is_array($row) ? $row : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findUserNames(int $tenantId, array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === []) {
            // whereIn 空数组在部分驱动上会被拼成 `IN ('')`，这里直接短路
            return [];
        }

        $rows = TenantScopeManager::forTable('users')
            ->forTenant($tenantId)
            ->query()
            ->whereIn('id', $ids)
            ->field('id,name,username')
            ->select()
            ->toArray();

        $names = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            // users.name 允许为 NULL（本仓库测试用户就是 NULL），此时退回 username：
            // 留空会让「处理人姓名」这一列永远是空的，等于没做
            $name = trim((string) ($row['name'] ?? ''));
            $names[$id] = $name !== '' ? $name : trim((string) ($row['username'] ?? ''));
        }

        return $names;
    }
}
