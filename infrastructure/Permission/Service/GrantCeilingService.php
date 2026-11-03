<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Service;

use think\facade\Db;

/**
 * 授予边界服务 | Grant ceiling service（ER-05）
 *
 * ## 守住的缺陷
 *
 * 历史实现里，`role.write` / `user.write` 的持有者**可以把任意权限授予任意角色、
 * 把任意角色分配给任意用户**，只要目标角色/用户存在且不是系统角色：
 *
 * - `RoleController::setPermissions|save|update` 直接 `syncPermissions($roleId, $ids)`；
 * - `UserController::setRoles` 直接 `setRoles($userId, $roleIds)`。
 *
 * 于是「只有 `role.write` 的租户管理员」可以给自己持有的角色授上 `tenant.write`、
 * `casbin.manage` 等他自己都没有的权限，再通过自己持有的角色拿到它们 —— **条件性提权**。
 *
 * ## 判定口径：权限码（`resource:action`）而不是权限 ID
 *
 * - **请求侧**是 permission ID 数组（前端勾选框给的就是 ID）；
 * - **操作者侧**的权威来源是 `PermissionService::getUserPermissions()`，返回 `resource:action`
 *   冒号码，并且它**就是鉴权中间件用的同一份数据**（含 DB_ONLY / CASBIN_ONLY / DUAL_MODE
 *   三种模式与多级缓存）。
 *
 * 选择「统一折算成权限码再比对」，而不是「直接比 ID 集合」的理由：
 *
 * 1. **只有一份权威**：授予边界必须与「中间件当场会不会放行」用同一个口径。若改成比 ID，
 *    就会引入第二个真相源（DB 的 `role_permissions`），在 `CASBIN_ONLY` / `DUAL_MODE`
 *    下与中间件的判断分叉 —— 要么把操作者本来能做的事判成越权（可用性），
 *    要么把中间件根本不放行的能力当成「他持有」（安全性）。
 * 2. **ID → 码的映射是良定义的**：`permissions` 是全局目录表（**没有** `tenant_id`），
 *    每一行都带 `resource` + `action`，且**一个 ID 只会折算出唯一一个码**
 *    （实测：测试库 52 行 / 开发库 45 行，`resource`/`action` 空值均为 0）。
 *    反向不唯一：开发库有 10 条**历史测试脏数据**共用同一个码
 *    （8 条 `cross_tenant.test.*` + 3 组 `lowcode:xxx` / `lowcode.xxx` 重复），
 *    这不削弱边界 —— 共用同一个码意味着"授予它带来的能力完全相同"，
 *    操作者持有该码就等于持有那条重复行带来的全部能力。
 * 3. **不会把 ID 集合与 slug 集合混用**：本类**只**在两处产出/消费权限码，
 *    且都以 `resource:action` 为唯一形态：
 *    - 操作者侧：`PermissionService::getUserPermissions()` 的返回值（冒号码）；
 *    - 目标侧：`permissions` 表 `resource` + `action` 拼接（冒号码）。
 *    slug（`resource.action`）在本类里**一次都没出现**，因此不存在两种分隔符互比的可能。
 * 4. 若将来出现 `resource`/`action` 为空的权限行，该行折算不出权限码 → 任何人都
 *    「不持有」它 → 一律拒绝授予（fail-closed），而不是放行。
 *
 * ## 不做「超管绕过」
 *
 * 平台/系统管理员之所以不受限，是因为他们**天然持有全部权限**（seeded `admin` 角色挂满
 * 当时全部权限行：测试库 52 条 / 开发库 45 条），子集校验自然通过。本类不含任何硬编码的
 * 用户 ID / 角色 slug / `is_system` 判定，避免再造一个「写死 id 就免检」的后门。
 *
 * ## 边界（**防不住的**，刻意不在本类处理）
 *
 * - 操作者把**自己已有**的权限授予自己可影响的角色：子集校验必然通过 —— 这是设计上的允许
 *   （他没有获得任何新能力）；
 * - 操作者**回收**权限不受限（回收是减权，不是提权）；
 * - 操作者修改**自己所属角色**的权限集：受制于同一条子集校验，无法借此提权，
 *   但可以自我降权（self-lockout）。详见 ER-05 报告的评估结论。
 *
 * @package Infrastructure\Permission\Service
 */
class GrantCeilingService
{
    /**
     * 权限服务（操作者权限集的唯一来源）
     */
    protected PermissionService $permissionService;

    /**
     * @param PermissionService|null $permissionService 权限服务（默认新建，测试可注入）
     */
    public function __construct(?PermissionService $permissionService = null)
    {
        $this->permissionService = $permissionService ?? new PermissionService();
    }

    /**
     * 检查「授予这批权限 ID」是否越过操作者的权限边界
     *
     * @param int $operatorUserId 操作者用户 ID（必须来自已认证上下文，不能来自可伪造的请求头）
     * @param array<int|string> $permissionIds 请求要授予的权限 ID 列表
     * @return array{unknown: array<int>, denied: array<int, string>}
     *         unknown：请求里不存在的权限 ID（继续插入会撞 `role_permissions` 外键）；
     *         denied ：permissionId => 权限码 的差集，非空即越界
     */
    public function inspectPermissions(int $operatorUserId, array $permissionIds): array
    {
        $requestedIds = $this->normalizeIds($permissionIds);

        if ($requestedIds === []) {
            // 空集合是任何集合的子集：清空权限不构成提权
            return ['unknown' => [], 'denied' => []];
        }

        $codesById = $this->loadPermissionCodesById($requestedIds);

        $unknown = [];
        $denied = [];
        $operatorCodes = $this->loadOperatorPermissionCodes($operatorUserId);

        foreach ($requestedIds as $permissionId) {
            if (!array_key_exists($permissionId, $codesById)) {
                $unknown[] = $permissionId;
                continue;
            }

            $code = $codesById[$permissionId];

            // $code 为 null 表示该行 resource/action 缺失，折算不出权限码 → 无人持有 → 拒绝
            if ($code === null || !isset($operatorCodes[$code])) {
                $denied[$permissionId] = $code ?? sprintf('#%d(缺少 resource/action)', $permissionId);
            }
        }

        return ['unknown' => $unknown, 'denied' => $denied];
    }

    /**
     * 检查「把这批角色分配给用户」是否越过操作者的权限边界
     *
     * 角色比权限更粗：一个角色会带来它名下**全部**权限，因此按
     * 「目标角色集合的权限**并集**」判定，而不是逐个角色判定。
     *
     * @param int $operatorUserId 操作者用户 ID（已认证上下文）
     * @param array<int|string> $roleIds 请求要分配的角色 ID 列表
     * @param int $tenantId 当前租户 ID（角色必须属于该租户，跨租户角色一律视为不存在）
     * @return array{unknown: array<int>, denied: array<int, string>}
     *         unknown：本租户内不存在的角色 ID；
     *         denied ：roleId 集合带来的权限并集中「操作者不持有」的部分（permissionId => 权限码）
     */
    public function inspectRoles(int $operatorUserId, array $roleIds, int $tenantId): array
    {
        $requestedIds = $this->normalizeIds($roleIds);

        if ($requestedIds === []) {
            // 清空用户角色是减权，不构成提权
            return ['unknown' => [], 'denied' => []];
        }

        // 1) 角色必须存在于当前租户（跨租户角色不得被分配，也不得被当作「空权限角色」放行）
        $existingIds = array_map(
            'intval',
            Db::name('roles')
                ->whereIn('id', $requestedIds)
                ->where('tenant_id', $tenantId)
                ->column('id')
        );

        $unknown = array_values(array_diff($requestedIds, $existingIds));

        if ($existingIds === []) {
            return ['unknown' => $unknown, 'denied' => []];
        }

        // 2) 这些角色带来的权限并集（仍以权限码为唯一形态）
        //
        // 这里**不再 join `roles`**：`$existingIds` 已经由上面那条带
        // `where('tenant_id', $tenantId)` 的 `roles` 查询筛过，跨租户角色在上面就被判成
        // unknown 了；而 `role_permissions` 表本身没有 `tenant_id` 列，
        // 再 join 一次只是重复同一条约束（也会让 `scripts/check-raw-sql-tenant.php`
        // 把 `r.tenant_id` 这种带别名的过滤误判成「未过滤」）。
        $rows = Db::name('role_permissions')
            ->whereIn('role_id', $existingIds)
            ->field(['permission_id', 'role_id'])
            ->select()
            ->toArray();

        $permissionIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['permission_id'],
            $rows
        )));

        if ($permissionIds === []) {
            // 角色存在但名下没有任何权限：空集的子集必然成立
            return ['unknown' => $unknown, 'denied' => []];
        }

        $codesById = $this->loadPermissionCodesById($permissionIds);
        $operatorCodes = $this->loadOperatorPermissionCodes($operatorUserId);

        $denied = [];
        foreach ($permissionIds as $permissionId) {
            $code = $codesById[$permissionId] ?? null;

            if ($code === null || !isset($operatorCodes[$code])) {
                $denied[$permissionId] = $code ?? sprintf('#%d(缺少 resource/action)', $permissionId);
            }
        }

        return ['unknown' => $unknown, 'denied' => $denied];
    }

    /**
     * 读取操作者当前持有的权限码集合（`resource:action`）
     *
     * 刻意复用 `PermissionService`：它是鉴权中间件的同一份数据源，
     * 授予边界若能比他更宽，就等于绕过中间件。
     *
     * 失败语义：`getUserPermissions()` 内部出错时返回空数组 → 这里得到空集 →
     * 任何非空授予都会被拒绝（fail-closed，而不是「查不到就全放行」）。
     *
     * @param int $operatorUserId 操作者用户 ID
     * @return array<string, true> 以权限码为键的查找表
     */
    protected function loadOperatorPermissionCodes(int $operatorUserId): array
    {
        if ($operatorUserId <= 0) {
            return [];
        }

        $codes = $this->permissionService->getUserPermissions($operatorUserId);

        $lookup = [];
        foreach ($codes as $code) {
            if (is_string($code) && $code !== '') {
                $lookup[$code] = true;
            }
        }

        return $lookup;
    }

    /**
     * 权限 ID → 权限码（`resource:action`）
     *
     * @param array<int> $permissionIds 权限 ID 列表（已去重、已转 int）
     * @return array<int, string|null> permissionId => 权限码（无法折算时为 null）
     */
    protected function loadPermissionCodesById(array $permissionIds): array
    {
        if ($permissionIds === []) {
            return [];
        }

        $rows = Db::name('permissions')
            ->whereIn('id', $permissionIds)
            ->field(['id', 'resource', 'action'])
            ->select()
            ->toArray();

        $codesById = [];
        foreach ($rows as $row) {
            $resource = isset($row['resource']) ? (string) $row['resource'] : '';
            $action = isset($row['action']) ? (string) $row['action'] : '';

            $codesById[(int) $row['id']] = ($resource !== '' && $action !== '')
                ? $resource . ':' . $action
                : null;
        }

        return $codesById;
    }

    /**
     * 规范化 ID 数组：去重 + 转 int
     *
     * **刻意不丢弃非正数**：`0` / `-1` 不是合法主键，但它们必须在后续查询里被判成
     * 「不存在（unknown）」并因此拒绝。若在这里静默丢掉，`permissions: [0]` 就会退化成
     * 空集合（空集是任何集合的子集）而被放行，随后 `syncPermissions` 先删后插撞外键，
     * 变成「拒绝没发生、旧权限还被删了」的部分执行。
     *
     * @param array<int|string> $ids 原始 ID 列表
     * @return array<int> 规范化后的 ID 列表
     */
    protected function normalizeIds(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            $normalized[(int) $id] = true;
        }

        return array_keys($normalized);
    }
}
