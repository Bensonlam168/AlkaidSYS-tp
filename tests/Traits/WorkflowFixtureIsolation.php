<?php

declare(strict_types=1);

namespace Tests\Traits;

use Infrastructure\Permission\Service\PermissionCacheService;
use think\facade\Db;
use think\facade\Log;

/**
 * 工作流测试夹具隔离 Trait
 * Workflow test fixture isolation trait
 *
 * 背景（问题根因）：
 *   1. `permissions.slug` 上有唯一索引 `idx_slug`；
 *   2. `tests/Feature/Workflow/` 下多个测试类共用同一批权限 slug
 *      （`workflow.task.read`、`workflow.definition.read`、`workflow.task.write` …），
 *      却各自使用不同的固定 ID（6101 / 6201 / 6301 / 6421 / 6521 / 6631 / 6721 … 以及 99xxxx）；
 *   3. 各类原先的 `seedFixtures()` 只在**自己的 ID 范围**内「先清理再插入」，
 *      清不掉别的类用不同 ID 写入的同 slug 行；
 *   4. PHPUnit 在 `setUp()` 抛异常时**不会执行** `tearDown()`，
 *      于是「一次失败」就把残留留给后续所有运行，形成级联失败，
 *      必须手工清库才能恢复（典型报错：`Duplicate entry 'workflow.task.read' for key 'permissions.idx_slug'`）。
 *
 * 本 Trait 的解法：
 *   - `purgeWorkflowFixtures()` 同时按「ID 区间 6000-6999」与「自然键」清理夹具，
 *     自然键（slug / email / username 前缀）能覆盖其他测试类用不同 ID 写入的同 slug 行；
 *   - `seedWorkflowFixtures()` 在播种抛异常时立刻再次清理并重新抛出，
 *     保证 `setUp()` 失败时不留残留。
 *
 * 安全边界（不误删真实数据）：
 *   - 自然键全部是测试专用形态：`workflow.*` 权限 slug、`wf_*` / `workflow_*` 角色 slug、
 *     `*@workflow-*` 邮箱、`wf_*` / `workflow_*` 用户名；
 *   - `database/seeds/` 中不存在任何 `workflow.*` 权限，真实角色 slug 为
 *     `admin` / `user` / `test_admin` 等，真实用户名为 `test_user` 等，均不匹配上述形态；
 *   - 除自然键外，所有删除条件都限定在 6000-6999 夹具区间内，不触碰区间外的其他数据。
 *
 * 使用方法：
 * ```php
 * class MyWorkflowTest extends ThinkPHPTestCase
 * {
 *     use \Tests\Traits\WorkflowFixtureIsolation;
 *
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->seedWorkflowFixtures(function (): void {
 *             // 原播种逻辑
 *         });
 *     }
 *
 *     protected function tearDown(): void
 *     {
 *         $this->purgeWorkflowFixtures();
 *         parent::tearDown();
 *     }
 * }
 * ```
 *
 * @package Tests\Traits
 */
trait WorkflowFixtureIsolation
{
    /**
     * 工作流测试夹具 ID 区间下界（含）
     * 与既有测试类使用的 6xxx 固定 ID 段保持一致
     */
    private const WORKFLOW_FIXTURE_ID_MIN = 6000;

    /**
     * 工作流测试夹具 ID 区间上界（含）
     */
    private const WORKFLOW_FIXTURE_ID_MAX = 6999;

    /**
     * 清理工作流测试夹具（幂等，可重复调用）
     * Purge workflow test fixtures (idempotent)
     *
     * 删除范围 = 「ID 落在 6000-6999」OR「自然键命中测试夹具形态」。
     * 只按 ID 清理清不掉别的测试类用不同 ID 写入的同 slug 行，
     * 只按自然键清理则会失去 ID 区间兜底，故两者取并集。
     *
     * 删除顺序严格满足外键：role_permissions → user_roles → roles → users → permissions。
     *
     * @return void
     */
    protected function purgeWorkflowFixtures(): void
    {
        // 先圈定夹具行：删除之后就再也无法按自然键查询
        $userIds       = $this->workflowFixtureIds('users');
        $roleIds       = $this->workflowFixtureIds('roles');
        $permissionIds = $this->workflowFixtureIds('permissions');

        if (empty($userIds) && empty($roleIds) && empty($permissionIds)) {
            return;
        }

        // 删除前记录「用户 → 租户」，供删除后清权限缓存使用
        // （用户删除后就查不到 tenant_id 了）
        $tenantMap = empty($userIds)
            ? []
            : Db::table('users')->whereIn('id', $userIds)->column('tenant_id', 'id');

        // 1) 角色-权限关联（按角色与按权限各删一次，覆盖「夹具角色 × 真实权限」这类交叉行）
        if (!empty($roleIds)) {
            Db::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        }
        if (!empty($permissionIds)) {
            Db::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        // 2) 用户-角色关联
        if (!empty($userIds)) {
            Db::table('user_roles')->whereIn('user_id', $userIds)->delete();
        }
        if (!empty($roleIds)) {
            Db::table('user_roles')->whereIn('role_id', $roleIds)->delete();
        }

        // 3) 角色
        if (!empty($roleIds)) {
            Db::table('roles')->whereIn('id', $roleIds)->delete();
        }

        // 4) 用户
        if (!empty($userIds)) {
            Db::table('users')->whereIn('id', $userIds)->delete();
        }

        // 5) 权限
        if (!empty($permissionIds)) {
            Db::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        // 清权限缓存（L1/L2），避免残留缓存让下一次运行误判
        $this->clearWorkflowFixturePermissionCache($tenantMap);
    }

    /**
     * 先清理再播种；播种抛异常时清理后重新抛出
     * Purge first, then seed; purge again and rethrow when seeding fails
     *
     * PHPUnit 在 `setUp()` 抛异常时不会执行 `tearDown()`，
     * 因此必须在播种失败时自行清理，否则残留会把后续所有运行带崩。
     *
     * @param callable $seed 播种回调（原各类的插入逻辑）| Seeding callback
     * @return void
     */
    protected function seedWorkflowFixtures(callable $seed): void
    {
        $this->purgeWorkflowFixtures();

        try {
            $seed();
        } catch (\Throwable $e) {
            try {
                $this->purgeWorkflowFixtures();
            } catch (\Throwable $cleanupError) {
                // 清理失败不得掩盖播种阶段的原始异常，仅记录日志
                try {
                    Log::warning('工作流测试夹具在播种失败后的清理中出错', [
                        'error_type'    => get_class($cleanupError),
                        'error_message' => $cleanupError->getMessage(),
                    ]);
                } catch (\Throwable) {
                    // 日志通道不可用时静默忽略，避免掩盖原始异常
                }
            }

            throw $e;
        }
    }

    /**
     * 按「ID 区间 OR 自然键」取回夹具行 ID
     * Collect fixture row IDs by "ID range OR natural key"
     *
     * @param string $table 表名（users / roles / permissions）| Table name
     * @return int[]
     */
    private function workflowFixtureIds(string $table): array
    {
        $min = self::WORKFLOW_FIXTURE_ID_MIN;
        $max = self::WORKFLOW_FIXTURE_ID_MAX;

        $ids = Db::table($table)
            ->where(function ($query) use ($table, $min, $max): void {
                // 条件一：落在夹具 ID 区间
                $query->whereBetween('id', [$min, $max]);

                // 条件二：命中测试夹具的自然键形态
                // 说明：其他测试类可能用完全不同的 ID（含 99xxxx）写入同 slug 行，
                // 仅按 ID 区间清理会漏掉这些行，从而在插入时撞 permissions.idx_slug 唯一索引
                switch ($table) {
                    case 'permissions':
                        $query->whereOr('slug', 'like', 'workflow.%');
                        break;

                    case 'roles':
                        // 下划线在 LIKE 中是通配符，这里用 \_ 转义成字面量
                        $query->whereOr('slug', 'like', 'wf\_%');
                        $query->whereOr('slug', 'like', 'workflow\_%');
                        break;

                    case 'users':
                        $query->whereOr('email', 'like', '%@workflow-%');
                        $query->whereOr('username', 'like', 'wf\_%');
                        $query->whereOr('username', 'like', 'workflow\_%');
                        break;
                }
            })
            ->column('id');

        return array_map('intval', $ids);
    }

    /**
     * 清除夹具用户的权限缓存（L1/L2）
     * Clear permission cache for fixture users
     *
     * 与各类原有 `clearPermissionCache()` 的做法一致，但改为直接按「用户 + 租户」调用缓存服务：
     * 用户在清理阶段已被删除，`PermissionService::clearUserCache()` 会因查不到 tenant_id 而空转。
     *
     * @param array<int|string, int|string> $tenantMap 用户 ID => 租户 ID | User ID => Tenant ID
     * @return void
     */
    private function clearWorkflowFixturePermissionCache(array $tenantMap): void
    {
        if (empty($tenantMap)) {
            return;
        }

        $cacheService = new PermissionCacheService();

        foreach ($tenantMap as $userId => $tenantId) {
            $cacheService->clearUserCache((int) $userId, (int) $tenantId);
        }
    }
}
