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
 *      （`workflow_task.read`、`workflow_definition.read`、`workflow_task.write` …），
 *      却各自使用不同的固定 ID（6101 / 6201 / 6301 / 6421 / 6521 / 6631 / 6721 … 以及 99xxxx）；
 *   3. 各类原先的 `seedFixtures()` 只在**自己的 ID 范围**内「先清理再插入」，
 *      清不掉别的类用不同 ID 写入的同 slug 行；
 *   4. PHPUnit 在 `setUp()` 抛异常时**不会执行** `tearDown()`，
 *      于是「一次失败」就把残留留给后续所有运行，形成级联失败，
 *      必须手工清库才能恢复（典型报错：`Duplicate entry 'workflow_task.read' for key 'permissions.idx_slug'`）。
 *
 * 本 Trait 的解法：
 *   - `purgeWorkflowFixtures()` 同时按「ID 区间 6000-6999」与「自然键」清理夹具，
 *     自然键（slug / email / username 前缀）能覆盖其他测试类用不同 ID 写入的同 slug 行；
 *   - `seedWorkflowFixtures()` 在播种抛异常时立刻再次清理并重新抛出，
 *     保证 `setUp()` 失败时不留残留。
 *
 * 安全边界（不误删真实数据）：
 *   - 自然键全部是测试专用形态：`workflow_*` 权限 slug、`wf_*` / `workflow_*` 角色 slug、
 *     `*@workflow-*` 邮箱、`wf_*` / `workflow_*` 用户名；
 *   - 真实角色 slug 为 `admin` / `user` / `test_admin` 等，真实用户名为 `test_user` 等，
 *     均不匹配上述形态；
 *   - 除自然键外，所有删除条件都限定在 6000-6999 夹具区间内，不触碰区间外的其他数据。
 *
 * ⚠️ 种子权限段（1001..1033）**免疫清理** —— 见下方「R51」段落：
 *   `database/seeds/CorePlatformSeed.php` 现在会创建 10 条 `workflow_*` 权限（id 1024..1033）。
 *   它们与夹具用**同一批 slug**，而本 trait 的权限清理条件是 `slug LIKE 'workflow\_%'`，
 *   若不加排除，一次 `tearDown()` 就会把种子权限连根删掉，
 *   表现为「跑完工作流用例后，其它依赖种子权限的用例开始 403」。
 *   因此 `workflowFixtureIds('permissions')` 会把 1001..1033 段整体剔除。
 *
 * R51：权限播种改为「按 slug 确保存在」
 *   背景：seeder 一旦预建 `workflow_*` 权限，测试里写死的
 *   `INSERT INTO permissions (id, slug, ...)` 就必然撞 `permissions.idx_slug` 唯一索引
 *   （`Duplicate entry 'workflow_task.read' for key 'permissions.idx_slug'`）。
 *   做法：`ensureWorkflowPermission()` 先按 slug 查，命中就**直接复用其 id**，
 *   未命中才按用例的固定 id（被占用时退回自增）插入。
 *   于是「权限由 seeder 预建」与「权限由测试自建」两种环境都能跑，且都不会产生重复行。
 *   调用方必须用**返回的 id** 去写 `role_permissions`（返回的 id 可能与请求的固定 id 不同）。
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
 *             // 权限不要直接 insert，改为「按 slug 确保存在」并回写 id：
 *             $this->readPermissionId = $this->ensureWorkflowPermission(
 *                 'workflow_task',
 *                 'read',
 *                 $this->readPermissionId
 *             );
 *             // 其余（users / roles / 关联）保持原样
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
     * 种子权限段下界（含）—— 1001..1033 属于 `CorePlatformSeed`，本 trait **不得删除**
     *
     * 取值的唯一来源是 `database/seeds/CorePlatformSeed::permissionDefinitions()`：
     * 那里以显式 id 1001..1033 建出 33 条业务权限（含 10 条 `workflow_*`）。
     * 这里是**只读**的排除边界，不新增任何种子数据。
     */
    private const WORKFLOW_SEED_PERMISSION_ID_MIN = 1001;

    /**
     * 种子权限段上界（含）
     */
    private const WORKFLOW_SEED_PERMISSION_ID_MAX = 1033;

    /**
     * 清理工作流测试夹具（幂等，可重复调用）
     * Purge workflow test fixtures (idempotent)
     *
     * 删除范围 = 「ID 落在 6000-6999」OR「自然键命中测试夹具形态」，
     * **但权限表要再排除 1001..1033 的种子段**（R51，见类注释）。
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
                        // 权限 slug 自本包起由三段式（workflow 命名空间 + 资源 + 动作）改为
                        // 两段式 `workflow_definition.read`：route/workflow.php 与
                        // scripts/check-permission-codes.php 都只接受 `resource.action` 两段形态。
                        // 下划线在 LIKE 中是通配符，必须写成 `\_` 才是字面量，否则
                        // `workflow_%` 会把 `workflowXYZ` 这类 slug 也一并匹配进来。
                        $query->whereOr('slug', 'like', 'workflow\_%');
                        // 同时保留旧三段式形态：历史运行（改名之前）可能已在库里留下
                        // 以 `workflow.` 开头、其后仍有两段（资源 + 动作）的旧 slug 孤儿行；
                        // 不兼容清理就会在插入时撞 permissions.idx_slug 唯一索引
                        // （见本 trait 头部背景第 4 点）。
                        // 点号在 LIKE 中不是通配符，无需转义。
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

        $ids = array_map('intval', $ids);

        // 【R51：种子权限段免疫清理】
        // 权限的清理条件是 `slug LIKE 'workflow\_%'`，而 seeder 现在也用同一批 slug 建行
        //（id 1024..1033）。不排除这一段，一次 tearDown() 就会删掉种子权限，
        // 后果是「工作流用例跑完后，其它依赖 admin 种子权限的用例开始 403」。
        if ($table === 'permissions') {
            $ids = array_values(array_filter(
                $ids,
                static fn (int $id): bool => $id < self::WORKFLOW_SEED_PERMISSION_ID_MIN
                    || $id > self::WORKFLOW_SEED_PERMISSION_ID_MAX
            ));
        }

        return $ids;
    }

    /**
     * 按 slug 确保工作流权限存在并返回其 id
     * Ensure a workflow permission row exists by slug, returning its id
     *
     * 【为什么需要它（R51）】
     *   `CorePlatformSeed` 现在会预建 10 条 `workflow_*` 权限（id 1024..1033）。
     *   测试若仍然无条件 `INSERT INTO permissions (id, slug, …)`，一旦权限已由 seeder 建好，
     *   就会撞 `permissions.idx_slug` 唯一索引：`Duplicate entry 'workflow_task.read' …`。
     *   本方法把「权限从哪来」这件事变成调用方无需关心：
     *     ① 按 slug 查到 → 直接复用它的 id（不管是 seeder 建的还是上一轮残留的）；
     *     ② 查不到 → 用本用例的固定 id 插入（该 id 被别的行占用时退回 AUTO_INCREMENT）。
     *
     * 【调用方必须用返回值】
     *   返回的 id 未必等于传入的 $preferredId（种子段已占用同 slug 时会返回 1024..1033）。
     *   `role_permissions` 必须用返回的 id 去写，否则会插入一条指向不存在权限的授权，
     *   表现是「鉴权仍然 403」。
     *
     * @param string $resource    资源名，与 route/workflow.php 的权限码前缀一致（如 workflow_task）
     * @param string $action      动作名（read / write / delete）
     * @param int|null $preferredId 期望的固定 id；仅在该 id 空闲时生效，可为 null
     * @param string $description 仅新建时写入的说明，便于事后辨认夹具来源
     * @return int 该权限在 permissions 表中的真实 id
     */
    protected function ensureWorkflowPermission(
        string $resource,
        string $action,
        ?int $preferredId = null,
        string $description = ''
    ): int {
        $slug = $resource . '.' . $action;

        $existingId = $this->findWorkflowPermissionId($slug);
        if ($existingId !== null) {
            return $existingId;
        }

        $now = date('Y-m-d H:i:s');

        $row = [
            'name'        => $slug,
            'slug'        => $slug,
            'resource'    => $resource,
            'action'      => $action,
            'description' => $description !== '' ? $description : 'workflow test fixture permission',
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        // 固定 id 只在**空闲**时才用：与 CorePlatformSeed 同一策略，
        // 绝不为了凑 id 去覆盖别人的行（覆盖会静默改掉别的用例/种子数据的身份）。
        if ($preferredId !== null && $preferredId > 0 && $this->isWorkflowPermissionIdFree($preferredId)) {
            $row['id'] = $preferredId;
        }

        Db::table('permissions')->insert($row);

        // 插入后回查真实 id：即使本次没钉 id（走 AUTO_INCREMENT），也要把真实值交给调用方。
        $createdId = $this->findWorkflowPermissionId($slug);
        if ($createdId === null) {
            throw new \RuntimeException(sprintf(
                '工作流测试夹具失败：权限 slug %s 插入后仍查不到对应行，无法继续授权。',
                $slug
            ));
        }

        return $createdId;
    }

    /**
     * 按 slug 查权限 id
     *
     * @param string $slug 权限 slug（`resource.action`）
     * @return int|null 不存在时返回 null
     */
    private function findWorkflowPermissionId(string $slug): ?int
    {
        // value() 在无结果时返回 null（部分驱动返回 false），统一归一成 null
        $id = Db::table('permissions')->where('slug', $slug)->value('id');

        return ($id === null || $id === false) ? null : (int) $id;
    }

    /**
     * 判断某个权限 id 是否空闲
     *
     * @param int $id 期望使用的主键
     * @return bool 空闲返回 true
     */
    private function isWorkflowPermissionIdFree(int $id): bool
    {
        $existing = Db::table('permissions')->where('id', $id)->value('id');

        return $existing === null || $existing === false;
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
