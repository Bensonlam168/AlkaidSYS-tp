<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Cache;

use think\facade\Db;
use think\facade\Log;

/**
 * 权限缓存失效编排器（ER-06）
 * Permission cache invalidation orchestrator (ER-06)
 *
 * ## 缺陷背景
 *
 * 撤权/改角色/停用账号后，相关用户的权限缓存**没有任何失效动作**，
 * 于是最长 300s（L2 TTL）内旧权限仍然生效 —— 撤权「看起来成功了，实际没生效」。
 *
 * ## 失效粒度
 *
 * | 变更 | 失效对象 | 实现 |
 * | --- | --- | --- |
 * | 角色权限变更（`syncPermissions`） | 该角色下**所有用户** | `invalidateRoleUsers()` |
 * | 用户角色变更（`setRoles`/`assignRole`/`removeRole`） | 该用户 | `invalidateUser()` |
 * | 用户状态变更（`updateStatus`/`update`） | 该用户 | `invalidateUser()` |
 * | 权限定义变更（`PermissionRepository::save/delete`） | 全部权限缓存 | `invalidateAll()` |
 *
 * ## 为什么放在这一层（依赖方向）
 *
 * 这里是**叶子类**：只依赖 `think\facade\Db`、`think\facade\Log` 与 `PermissionCachePurger`，
 * 不依赖 `PermissionService`、也不依赖任何 Repository，因此不会形成
 * `Repository → PermissionService → Repository` 的循环依赖。
 * 调用点分两类：
 *   - **Repository 层**（`RoleRepository::syncPermissions/delete`、`PermissionRepository::save/delete`）：
 *     这些都是「权限知识」的写入收口处，放在这里可以保证**将来任何新调用方都不会忘记失效**
 *     （控制器有多处调用 `syncPermissions`，逐个补必然漏）；
 *   - **控制器层**（`UserController`）：`UserRepository` 不在本次改动范围，
 *     而用户级写操作的控制器入口本身唯一，因此挂在这里。
 *
 * ## 时机：写操作之后
 *
 * 所有钩子都放在**数据写入完成之后**（`syncPermissions` 内部无外层事务；`UserRepository::setRoles`
 * 自带事务并在返回前提交）。若在提交前清，并发请求会用「未提交的旧数据」把缓存重新填满，
 * 提交后这份旧数据还要再活一个 TTL —— 那才是真正的不一致。
 *
 * ## 失败处理：尽力而为，绝不影响业务
 *
 * 每个公开方法都自带 try/catch：清缓存失败只记 warning，**不向上抛**。
 * 依据：缓存是性能优化，不是数据真相；让「清缓存失败」把一次成功的撤权回滚掉是本末倒置。
 *
 * @package Infrastructure\Permission\Cache
 */
final class PermissionCacheInvalidator
{
    /**
     * 批量失效阈值：命中用户数超过该值时改为「整族清除」
     *
     * 为什么需要：逐用户失效在 Redis 下虽已按前缀分组（2 轮 SCAN），但每个用户仍要
     * 精确 DEL 2 个键；当受影响用户上千时，「一次整族 SCAN + DEL」比「上千用户逐个拼键」
     * 更省往返、也更不容易漏。阈值取 100：低于它的日常变更走精确路径，避免误伤他人缓存。
     */
    public const BULK_USER_THRESHOLD = 100;

    /**
     * 前缀清除器
     */
    private PermissionCachePurger $purger;

    /**
     * @param PermissionCachePurger|null $purger 前缀清除器（默认新建）
     */
    public function __construct(?PermissionCachePurger $purger = null)
    {
        $this->purger = $purger ?? new PermissionCachePurger();
    }

    /**
     * 失效单个用户的权限缓存
     *
     * @param int $userId 用户 ID
     * @param int $tenantId 用户所属租户 ID（缓存键的一部分，必须准确）
     * @return array 失效统计
     */
    public function invalidateUser(int $userId, int $tenantId): array
    {
        return $this->invalidateUsers([['user_id' => $userId, 'tenant_id' => $tenantId]]);
    }

    /**
     * 批量失效多个用户的权限缓存
     *
     * @param array<int, array{user_id?: int, tenant_id?: int}> $userTenantPairs 用户-租户对
     * @return array 失效统计
     */
    public function invalidateUsers(array $userTenantPairs): array
    {
        try {
            $pairs = $this->normalizePairs($userTenantPairs);

            if ($pairs === []) {
                return $this->report('noop', 0, ['users' => 0]);
            }

            if (count($pairs) > self::BULK_USER_THRESHOLD) {
                // 影响面过大：整族清除一次，胜过逐用户拼键
                $report = $this->invalidateAll();
                $report['escalated_from_users'] = count($pairs);

                return $report;
            }

            $exactKeys = [];
            $prefixes = [];

            foreach ($pairs as $pair) {
                $userId = $pair['user_id'];
                $tenantId = $pair['tenant_id'];

                // 结构已知的两级缓存：精确键删除
                $exactKeys[] = sprintf('l1:permission:tenant:%d:user:%d:permissions', $tenantId, $userId);
                $exactKeys[] = sprintf('l2:permission:tenant:%d:user:%d:permissions', $tenantId, $userId);

                // 尾部为 md5(权限码) / resource:action 的键无法枚举，按前缀清除
                $prefixes[] = sprintf('permission:tenant:%d:user:%d:check:', $tenantId, $userId);
                $prefixes[] = sprintf('casbin:check:%d:%d:', $userId, $tenantId);
            }

            // 角色→权限（p）与用户→角色（g）都属于 Casbin 策略，变更后全量策略缓存必须失效
            $exactKeys[] = 'casbin:policy:full';

            $deleted = $this->purger->deleteKeys($exactKeys);
            $purge = $this->purger->purge($prefixes);
            $deleted += (int) ($purge['deleted'] ?? 0);

            Log::info('权限缓存已按用户失效', [
                'users' => count($pairs),
                'deleted' => $deleted,
                'strategy' => $purge['strategy'] ?? 'unknown',
            ]);

            return $this->report((string) ($purge['strategy'] ?? 'mixed'), $deleted, ['users' => count($pairs)]);
        } catch (\Throwable $e) {
            return $this->fail('invalidateUsers', $e, ['users' => count($userTenantPairs)]);
        }
    }

    /**
     * 查询角色下的用户（含各自租户），供「先取值、后删除」场景使用
     *
     * **刻意的跨租户查询**（`scripts/check-raw-sql-tenant.php` 会标记本方法）：
     * 「撤掉某个角色的权限」必须覆盖该角色的**全部**成员，一旦加 `tenant_id` 过滤
     * 就会漏掉「角色租户与用户租户不一致」的历史脏数据，导致部分用户继续持有旧权限。
     * 反过来说，失效范围也没有放大：缓存键里的租户取自 `users.tenant_id`（用户的真实归属），
     * 而不是角色的租户，因此只会删除这些用户自己的缓存。
     *
     * @param int $roleId 角色 ID
     * @return array<int, array{user_id: int, tenant_id: int}>
     */
    public function usersOfRole(int $roleId): array
    {
        try {
            if ($roleId <= 0) {
                return [];
            }

            // 取**用户自己的** tenant_id：缓存键里的租户来自 users 表，
            // 用角色的 tenant_id 会漏掉「角色租户与用户租户不一致」的历史脏数据
            $rows = Db::table('user_roles')
                ->alias('ur')
                ->join('users u', 'u.id = ur.user_id')
                ->where('ur.role_id', $roleId)
                ->field('u.id AS user_id, u.tenant_id AS tenant_id')
                ->select()
                ->toArray();

            return $this->normalizePairs($rows);
        } catch (\Throwable $e) {
            $this->fail('usersOfRole', $e, ['role_id' => $roleId]);

            return [];
        }
    }

    /**
     * 失效某角色下全部用户的权限缓存
     *
     * @param int $roleId 角色 ID
     * @return array 失效统计
     */
    public function invalidateRoleUsers(int $roleId): array
    {
        return $this->invalidateUsers($this->usersOfRole($roleId));
    }

    /**
     * 失效全部权限缓存（权限定义变更 / 大规模变更 / 管理后台清缓存）
     *
     * @return array 失效统计
     */
    public function invalidateAll(): array
    {
        try {
            $result = $this->purger->purge([
                PermissionCachePurger::PREFIX_L1_PERMISSION,
                PermissionCachePurger::PREFIX_L2_PERMISSION,
                PermissionCachePurger::PREFIX_PERMISSION,
                PermissionCachePurger::PREFIX_CASBIN_CHECK,
                PermissionCachePurger::PREFIX_CASBIN_POLICY,
            ]);

            Log::info('权限缓存已整族失效', $result);

            return $this->report(
                (string) ($result['strategy'] ?? 'unknown'),
                (int) ($result['deleted'] ?? 0),
                ['scope' => 'all']
            );
        } catch (\Throwable $e) {
            return $this->fail('invalidateAll', $e);
        }
    }

    /**
     * 归一化「用户-租户」对，去重并丢弃非法项
     *
     * @param array<mixed> $rows
     * @return array<int, array{user_id: int, tenant_id: int}>
     */
    private function normalizePairs(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $userId = (int) ($row['user_id'] ?? 0);
            $tenantId = (int) ($row['tenant_id'] ?? 0);

            if ($userId <= 0 || $tenantId <= 0) {
                continue;
            }

            $normalized[$tenantId . ':' . $userId] = ['user_id' => $userId, 'tenant_id' => $tenantId];
        }

        return array_values($normalized);
    }

    /**
     * 构造成功统计
     *
     * @param string $strategy 命中策略（scan / index / noop）
     * @param int $deleted 删除键数
     * @param array<string, mixed> $extra 附加上下文
     * @return array<string, mixed>
     */
    private function report(string $strategy, int $deleted, array $extra = []): array
    {
        return array_merge([
            'strategy' => $strategy,
            'deleted' => $deleted,
            'success' => true,
        ], $extra);
    }

    /**
     * 记录失效失败并返回统计（**不抛异常**）
     *
     * @param string $operation 操作名
     * @param \Throwable $e 异常
     * @param array<string, mixed> $extra 附加上下文
     * @return array<string, mixed>
     */
    private function fail(string $operation, \Throwable $e, array $extra = []): array
    {
        Log::warning('权限缓存失效失败（尽力而为，不影响已提交的业务写入）', array_merge([
            'operation' => $operation,
            'error_type' => get_class($e),
            'error' => $e->getMessage(),
        ], $extra));

        return array_merge([
            'strategy' => 'failed',
            'deleted' => 0,
            'success' => false,
            'error' => $e->getMessage(),
        ], $extra);
    }
}
