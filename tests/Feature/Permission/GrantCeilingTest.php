<?php

declare(strict_types=1);

namespace Tests\Feature\Permission;

use Infrastructure\Permission\Service\PermissionService;
use Infrastructure\I18n\LanguageService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;
use think\facade\Db;

/**
 * 授予边界（grant ceiling）测试 | ER-05
 *
 * ## 守住的缺陷
 *
 * `role.write` / `user.write` 的持有者此前可以把**自己没有的权限**授予角色、
 * 把**比自己更强的角色**分配给用户 —— 条件性提权：
 *
 *   攻击链：`role.write` → `PUT /roles/{自己的角色}/permissions {permissions:[tenant.write]}`
 *           → 自己立刻持有 `tenant.write`
 *
 * 修好后：四条（实际是五条）写路径都要求「请求的权限集合 ⊆ 操作者自身权限集合」，
 * 越界一律 **403 / 2002**，且**一行都不写库**。
 *
 * ## 同时守住的第二个缺陷：路由遮蔽
 *
 * `config/route.php` 的 `route_complete_match = false` 让 `PUT /roles/:id` **前缀匹配**吃掉
 * `PUT /roles/:id/permissions`，于是 `setPermissions()` 是死代码、请求实际进 `update()`
 * （响应 `role.updated`）。修复后必须命中 `setPermissions()`（响应 `role.permissions_updated`）。
 * 本类直接用**生产路由**（不再走 `tmp-cache-test` 之类的绕行路径）验证这一点。
 *
 * ## 夹具设计
 *
 * - 操作者 `990501`：角色 `990501` 只持有 `role.write` + `user.write`（**故意不给** escalation 权限）；
 * - 目标用户 `990502` / 目标角色 `990502`：用于「改权限集」；
 * - 强角色 `990503`：持有 `casbin.manage`（操作者没有 → 不可分配给用户）；
 * - 弱角色 `990504`：只持有 `role.write`（操作者持有 → 可分配）；
 * - seeded admin `1`：系统角色 `1` 挂满 52 条权限，用于「现有流程不受影响」的回归对照。
 *
 * 所有夹具都在测试库 `alkaid_sys_test`，tearDown 保证零残留。
 *
 * @package Tests\Feature\Permission
 */
class GrantCeilingTest extends ThinkPHPTestCase
{
    /**
     * 租户 ID（测试库固定存在）
     */
    private const TENANT_ID = 1;

    /**
     * 低权限操作者（只有 role.write + user.write）
     */
    private const OPERATOR_USER_ID = 990501;
    private const OPERATOR_ROLE_ID = 990501;

    /**
     * 目标用户 / 目标角色（被改名、改权限、改角色的一方）
     */
    private const TARGET_USER_ID = 990502;
    private const TARGET_ROLE_ID = 990502;

    /**
     * 强角色（持有操作者没有的 casbin.manage） / 弱角色（只持有 role.write）
     */
    private const STRONG_ROLE_ID = 990503;
    private const WEAK_ROLE_ID = 990504;

    /**
     * 系统内置管理员（seeded，角色 1 持有全部权限）
     */
    private const SEEDED_ADMIN_USER_ID = 1;

    /**
     * 本类用到的权限码（slug → 运行时解析成 ID）
     */
    private const SLUG_ROLE_WRITE = 'role.write';
    private const SLUG_USER_WRITE = 'user.write';
    private const SLUG_TENANT_WRITE = 'tenant.write';
    private const SLUG_CASBIN_MANAGE = 'casbin.manage';

    /**
     * 运行时解析出的权限 ID
     */
    private int $permRoleWrite = 0;
    private int $permUserWrite = 0;
    private int $permTenantWrite = 0;
    private int $permCasbinManage = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // 让容器解析 think\Request 时拿到 app\Request（否则取不到 userId/tenantId 上下文）
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 注册 admin 路由（与既有鉴权类用例一致；include_once 保证进程内只注册一次）
        include_once $this->app()->getRootPath() . 'route/admin.php';

        // 复位鉴权中间件实例：避免其它测试类把它绑成 no-op 替身（容器会缓存首次解析结果）
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        $this->resetRouteMiddlewareQueue();
        $this->prepareFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        $this->resetRouteMiddlewareQueue();

        parent::tearDown();
    }

    /**
     * 清空路由/控制器中间件队列（保留全局中间件）
     *
     * `think\Middleware::$queue` 是进程级累加、框架从不清空（只有 array_unique 去重），
     * 不清理会让上一次请求的规则中间件影响下一次请求。
     */
    private function resetRouteMiddlewareQueue(): void
    {
        $ref = new \ReflectionProperty(\think\Middleware::class, 'queue');
        $ref->setAccessible(true);

        $queue = (array) $ref->getValue($this->app()->middleware);
        $queue['route'] = [];
        $queue['controller'] = [];

        $ref->setValue($this->app()->middleware, $queue);
    }

    /**
     * 解析权限目录里的权限 ID（夹具前提，取不到直接失败而不是静默跳过）
     *
     * @param string $slug 权限 slug
     * @return int
     */
    private function permissionId(string $slug): int
    {
        $id = Db::table('permissions')->where('slug', $slug)->value('id');

        $this->assertNotEmpty($id, sprintf('测试库缺少权限行 %s（夹具前提不成立）', $slug));

        return (int) $id;
    }

    /**
     * 建立夹具
     */
    private function prepareFixtures(): void
    {
        $this->cleanupFixtures();

        $this->permRoleWrite = $this->permissionId(self::SLUG_ROLE_WRITE);
        $this->permUserWrite = $this->permissionId(self::SLUG_USER_WRITE);
        $this->permTenantWrite = $this->permissionId(self::SLUG_TENANT_WRITE);
        $this->permCasbinManage = $this->permissionId(self::SLUG_CASBIN_MANAGE);

        $now = date('Y-m-d H:i:s');

        // 四个角色（都必须是 is_system = 0，否则会被「系统角色只读」提前拦掉，
        // 那测的就不是授予边界了）
        Db::table('roles')->insertAll([
            [
                'id' => self::OPERATOR_ROLE_ID,
                'tenant_id' => self::TENANT_ID,
                'name' => 'Tmp Ceiling Operator',
                'slug' => 'tmp_ceiling_operator',
                'description' => 'fixture: role.write + user.write',
                'is_system' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::TARGET_ROLE_ID,
                'tenant_id' => self::TENANT_ID,
                'name' => 'Tmp Ceiling Target',
                'slug' => 'tmp_ceiling_target',
                'description' => 'fixture: 无权限，被操作者改权限集',
                'is_system' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::STRONG_ROLE_ID,
                'tenant_id' => self::TENANT_ID,
                'name' => 'Tmp Ceiling Strong',
                'slug' => 'tmp_ceiling_strong',
                'description' => 'fixture: casbin.manage（操作者没有）',
                'is_system' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::WEAK_ROLE_ID,
                'tenant_id' => self::TENANT_ID,
                'name' => 'Tmp Ceiling Weak',
                'slug' => 'tmp_ceiling_weak',
                'description' => 'fixture: role.write（操作者有）',
                'is_system' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Db::table('role_permissions')->insertAll([
            ['role_id' => self::OPERATOR_ROLE_ID, 'permission_id' => $this->permRoleWrite, 'created_at' => $now],
            ['role_id' => self::OPERATOR_ROLE_ID, 'permission_id' => $this->permUserWrite, 'created_at' => $now],
            ['role_id' => self::STRONG_ROLE_ID, 'permission_id' => $this->permCasbinManage, 'created_at' => $now],
            ['role_id' => self::WEAK_ROLE_ID, 'permission_id' => $this->permRoleWrite, 'created_at' => $now],
        ]);

        Db::table('users')->insertAll([
            [
                'id' => self::OPERATOR_USER_ID,
                'tenant_id' => self::TENANT_ID,
                'username' => 'tmp_ceiling_operator',
                'email' => 'tmp_ceiling_operator@test.local',
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::TARGET_USER_ID,
                'tenant_id' => self::TENANT_ID,
                'username' => 'tmp_ceiling_target',
                'email' => 'tmp_ceiling_target@test.local',
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Db::table('user_roles')->insertAll([
            ['user_id' => self::OPERATOR_USER_ID, 'role_id' => self::OPERATOR_ROLE_ID, 'created_at' => $now],
            ['user_id' => self::TARGET_USER_ID, 'role_id' => self::TARGET_ROLE_ID, 'created_at' => $now],
        ]);

        // 夹具是直接写库的，必须清掉两个用户的权限缓存，否则「操作者权限集」会读到旧值
        $this->flushFixtureUserCaches();
    }

    /**
     * 清掉夹具用户的权限缓存（L1 + L2）
     */
    private function flushFixtureUserCaches(): void
    {
        foreach ([self::OPERATOR_USER_ID, self::TARGET_USER_ID] as $userId) {
            Cache::delete('l1:permission:tenant:' . self::TENANT_ID . ':user:' . $userId . ':permissions');
            Cache::delete('l2:permission:tenant:' . self::TENANT_ID . ':user:' . $userId . ':permissions');
        }
    }

    /**
     * 清理夹具（保证零残留）
     */
    private function cleanupFixtures(): void
    {
        $userIds = [self::OPERATOR_USER_ID, self::TARGET_USER_ID];
        $roleIds = [
            self::OPERATOR_ROLE_ID,
            self::TARGET_ROLE_ID,
            self::STRONG_ROLE_ID,
            self::WEAK_ROLE_ID,
        ];

        Db::table('user_roles')->whereIn('user_id', $userIds)->delete();
        Db::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        Db::table('users')->whereIn('id', $userIds)->delete();
        Db::table('roles')->whereIn('id', $roleIds)->delete();

        // 测试里可能创建过角色/用户（save 的正反例），按 slug / username 兜底清理
        $createdRoleIds = Db::table('roles')
            ->where('tenant_id', self::TENANT_ID)
            ->whereLike('slug', 'tmp_ceiling_created%')
            ->column('id');
        if ($createdRoleIds !== []) {
            Db::table('role_permissions')->whereIn('role_id', $createdRoleIds)->delete();
            Db::table('roles')->whereIn('id', $createdRoleIds)->delete();
        }

        $createdUserIds = Db::table('users')
            ->where('tenant_id', self::TENANT_ID)
            ->whereLike('username', 'tmp_ceiling_created%')
            ->column('id');
        if ($createdUserIds !== []) {
            Db::table('user_roles')->whereIn('user_id', $createdUserIds)->delete();
            Db::table('users')->whereIn('id', $createdUserIds)->delete();
        }

        $this->flushFixtureUserCaches();
    }

    /**
     * 发起一次真实 JSON 请求（走完整「路由 → Auth → Permission 中间件 → 控制器」链路）
     *
     * 注意：必须先设 `Content-Type: application/json` 再 `withInput()`，
     * 否则 `think\Request::getInputData()` 按「非 json」处理返回空数组，
     * 控制器里的 `put()` / `post()` 会取不到参数。
     *
     * @param string $method HTTP 方法
     * @param string $uri 路径（不含前导斜杠）
     * @param array<string, mixed> $data 请求体
     * @param int $asUserId 以哪个用户身份发起（生成真实 JWT）
     * @param array<string, string> $extraHeaders 额外请求头（用于伪造头测试）
     * @return \think\Response
     */
    private function request(
        string $method,
        string $uri,
        array $data = [],
        int $asUserId = self::OPERATOR_USER_ID,
        array $extraHeaders = []
    ) {
        $this->resetRouteMiddlewareQueue();

        $request = $this->app()->make(\think\Request::class);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        $request = $request->withHeader(['Content-Type' => 'application/json']);

        if ($data !== []) {
            $request->withInput((string) json_encode($data));
        }

        return $this->runHttp($request->withHeader(array_merge([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                $asUserId,
                self::TENANT_ID,
                0
            ),
        ], $extraHeaders)));
    }

    /**
     * 取响应里的 JSON 字段
     *
     * @param \think\Response $response 响应
     * @return array<string, mixed>
     */
    private function json($response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($decoded, '响应必须是 JSON：' . (string) $response->getContent());

        return $decoded;
    }

    /**
     * 断言是一次「授予边界拒绝」：HTTP 403 + 业务码 2002
     *
     * 同时断言**没有泄露缺哪些权限**：message 里不能出现被拒绝的权限码/权限名。
     *
     * @param \think\Response $response 响应
     * @param array<int, string> $forbiddenSubstrings 不允许出现在 message 里的片段
     */
    private function assertCeilingDenied($response, array $forbiddenSubstrings = []): void
    {
        $body = $this->json($response);
        $lang = new LanguageService();

        $this->assertSame(403, $response->getCode(), 'HTTP 状态必须是 403：' . (string) $response->getContent());
        $this->assertSame(2002, $body['code'] ?? null, '业务码必须是 2002：' . (string) $response->getContent());
        $this->assertNotSame('', (string) ($body['message'] ?? ''), 'message 不能为空（要可操作）');

        // 关键区分：同一个 2002/403 也被 `app\middleware\Permission` 用于「权限不足」。
        // 这里必须证明 403 来自**授予边界**，而不是中间件先拦了（否则用例会在
        // 「操作者根本没有 role.write」的情况下假通过）。
        $this->assertNotSame(
            $lang->trans('error.permission_denied'),
            $body['message'],
            '403 必须来自授予边界，不能是中间件的通用「权限不足」'
        );
        $this->assertSame(
            $lang->trans('error.grant_ceiling_exceeded'),
            $body['message'],
            '拒绝文案必须使用授予边界的专用 key（可操作、且不含具体权限）'
        );

        foreach ($forbiddenSubstrings as $fragment) {
            $this->assertStringNotContainsString(
                $fragment,
                (string) $body['message'],
                '拒绝文案不得回显缺失的具体权限（避免被用来探测操作者权限边界）'
            );
        }
    }

    /**
     * 取角色的权限 ID 列表（排序后便于比较）
     *
     * @param int $roleId 角色 ID
     * @return array<int>
     */
    private function rolePermissionIds(int $roleId): array
    {
        $ids = array_map('intval', Db::table('role_permissions')->where('role_id', $roleId)->column('permission_id'));
        sort($ids);

        return $ids;
    }

    /**
     * 取用户的角色 ID 列表（排序后便于比较）
     *
     * @param int $userId 用户 ID
     * @return array<int>
     */
    private function userRoleIds(int $userId): array
    {
        $ids = array_map('intval', Db::table('user_roles')->where('user_id', $userId)->column('role_id'));
        sort($ids);

        return $ids;
    }

    // ------------------------------------------------------------------
    // 场景 1：操作者缺某个权限 → 拒绝，且数据库未被修改
    // ------------------------------------------------------------------

    /**
     * 场景 1：`setPermissions` 授予操作者自己没有的权限 → 403/2002 且一行都不写
     */
    public function testSetPermissionsRejectsPermissionOperatorDoesNotHold(): void
    {
        $before = $this->rolePermissionIds(self::TARGET_ROLE_ID);
        $this->assertSame([], $before, '前置条件：目标角色初始没有权限');

        $response = $this->request(
            'PUT',
            'v1/admin/roles/' . self::TARGET_ROLE_ID . '/permissions',
            ['permissions' => [$this->permRoleWrite, $this->permTenantWrite]]
        );

        $this->assertCeilingDenied($response, ['tenant', 'casbin']);

        $this->assertSame(
            $before,
            $this->rolePermissionIds(self::TARGET_ROLE_ID),
            '被拒绝的请求绝不能写库（哪怕是操作者自己持有的那一条）'
        );
    }

    // ------------------------------------------------------------------
    // 场景 2：操作者持有全部 → 授予成功（正面对照）
    // ------------------------------------------------------------------

    /**
     * 场景 2：操作者持有的权限 → 授予成功
     */
    public function testSetPermissionsAllowsSubsetOperatorHolds(): void
    {
        $response = $this->request(
            'PUT',
            'v1/admin/roles/' . self::TARGET_ROLE_ID . '/permissions',
            ['permissions' => [$this->permRoleWrite, $this->permUserWrite]]
        );

        $body = $this->json($response);

        $this->assertSame(200, $response->getCode(), (string) $response->getContent());
        $this->assertSame(0, $body['code'] ?? null);
        // 命中的必须是 setPermissions，不是 update（后者返回 role.updated）
        $this->assertSame('role.permissions_updated', $body['message']);

        $expected = [$this->permRoleWrite, $this->permUserWrite];
        sort($expected);
        $this->assertSame($expected, $this->rolePermissionIds(self::TARGET_ROLE_ID));
    }

    // ------------------------------------------------------------------
    // 场景 3：部分子集（授 2 个、他只有 1 个）→ 整体拒绝，不得部分成功
    // ------------------------------------------------------------------

    /**
     * 场景 3：请求里只要有一条越界，整个请求被拒；库里**保持原有权限集**
     */
    public function testSetPermissionsRejectsMixedSubsetAtomically(): void
    {
        // 前置：先合法地把角色设为 [user.write]（这两个操作者都持有）
        $setup = $this->request(
            'PUT',
            'v1/admin/roles/' . self::TARGET_ROLE_ID . '/permissions',
            ['permissions' => [$this->permUserWrite]]
        );
        $this->assertSame(200, $setup->getCode(), (string) $setup->getContent());

        $before = $this->rolePermissionIds(self::TARGET_ROLE_ID);
        $this->assertSame([$this->permUserWrite], $before);

        // 请求 = [role.write（持有）, tenant.write（不持有）]
        $response = $this->request(
            'PUT',
            'v1/admin/roles/' . self::TARGET_ROLE_ID . '/permissions',
            ['permissions' => [$this->permRoleWrite, $this->permTenantWrite]]
        );

        $this->assertCeilingDenied($response, ['tenant']);

        $this->assertSame(
            $before,
            $this->rolePermissionIds(self::TARGET_ROLE_ID),
            '不得出现部分成功：既不能插入 role.write，也不能先把原有权限删掉'
        );
    }

    // ------------------------------------------------------------------
    // 场景 4：setRoles —— 分配的角色的权限并集超出操作者 → 拒绝
    // ------------------------------------------------------------------

    /**
     * 场景 4a：把「比自己强」的角色分配给用户 → 403/2002 且角色集不变
     */
    public function testSetRolesRejectsRoleBeyondOperator(): void
    {
        $before = $this->userRoleIds(self::TARGET_USER_ID);
        $this->assertSame([self::TARGET_ROLE_ID], $before, '前置条件：目标用户初始持有目标角色');

        $response = $this->request(
            'PUT',
            'v1/admin/users/' . self::TARGET_USER_ID . '/roles',
            ['role_ids' => [self::STRONG_ROLE_ID]]
        );

        $this->assertCeilingDenied($response, ['casbin']);

        $this->assertSame(
            $before,
            $this->userRoleIds(self::TARGET_USER_ID),
            '被拒绝的角色分配绝不能写库'
        );
    }

    /**
     * 场景 4b：并集判定 —— 「弱角色 + 强角色」整体拒绝（不能因为弱角色合法就部分成功）
     */
    public function testSetRolesRejectsUnionThatExceedsOperator(): void
    {
        $before = $this->userRoleIds(self::TARGET_USER_ID);

        $response = $this->request(
            'PUT',
            'v1/admin/users/' . self::TARGET_USER_ID . '/roles',
            ['role_ids' => [self::WEAK_ROLE_ID, self::STRONG_ROLE_ID]]
        );

        $this->assertCeilingDenied($response, ['casbin']);

        $this->assertSame($before, $this->userRoleIds(self::TARGET_USER_ID), '并集越界必须整体拒绝');
    }

    /**
     * 场景 4c（正面对照）：分配「权限并集 ⊆ 操作者」的角色 → 成功
     */
    public function testSetRolesAllowsRoleWithinOperator(): void
    {
        $response = $this->request(
            'PUT',
            'v1/admin/users/' . self::TARGET_USER_ID . '/roles',
            ['role_ids' => [self::WEAK_ROLE_ID]]
        );

        $body = $this->json($response);

        $this->assertSame(200, $response->getCode(), (string) $response->getContent());
        $this->assertSame('角色设置成功', $body['message']);
        $this->assertSame([self::WEAK_ROLE_ID], $this->userRoleIds(self::TARGET_USER_ID));
    }

    /**
     * 场景 4d：不存在的角色 ID → 422（入参错误，且不泄露「他租户角色是否存在」）
     */
    public function testSetRolesRejectsUnknownRoleId(): void
    {
        $before = $this->userRoleIds(self::TARGET_USER_ID);

        $response = $this->request(
            'PUT',
            'v1/admin/users/' . self::TARGET_USER_ID . '/roles',
            ['role_ids' => [999999999]]
        );

        $this->assertSame(422, $response->getCode(), (string) $response->getContent());
        $this->assertSame($before, $this->userRoleIds(self::TARGET_USER_ID));
    }

    // ------------------------------------------------------------------
    // 场景 5：路由遮蔽 —— 必须命中 setPermissions / setRoles
    // ------------------------------------------------------------------

    /**
     * 场景 5a：`PUT /roles/:id/permissions` 命中 `setPermissions`（message 可区分）
     */
    public function testRolePermissionsRouteReachesSetPermissions(): void
    {
        // 空权限集：任何操作者都能"清空"（空集是任何集合的子集），
        // 这条请求同时证明路由进了 setPermissions 且授予边界不会误杀合法的减权。
        $response = $this->request(
            'PUT',
            'v1/admin/roles/' . self::TARGET_ROLE_ID . '/permissions',
            ['permissions' => []]
        );

        $body = $this->json($response);

        $this->assertSame(200, $response->getCode(), (string) $response->getContent());
        $this->assertSame(
            'role.permissions_updated',
            $body['message'],
            '必须命中 setPermissions；若得到 role.updated，说明仍被 PUT /roles/:id 前缀匹配遮蔽'
        );
    }

    /**
     * 场景 5b：`PUT /users/:id/roles` 命中 `setRoles`（message 可区分）
     */
    public function testUserRolesRouteReachesSetRoles(): void
    {
        $response = $this->request(
            'PUT',
            'v1/admin/users/' . self::TARGET_USER_ID . '/roles',
            ['role_ids' => [self::WEAK_ROLE_ID]]
        );

        $body = $this->json($response);

        $this->assertSame(200, $response->getCode(), (string) $response->getContent());
        $this->assertSame(
            '角色设置成功',
            $body['message'],
            '必须命中 setRoles；若得到「用户更新成功」，说明仍被 PUT /users/:id 前缀匹配遮蔽'
        );
    }

    /**
     * 场景 5c：修复遮蔽后，`PUT /roles/:id` 仍然是 update（没有把 update 顶掉）
     */
    public function testRoleUpdateRouteStillReachesUpdate(): void
    {
        $response = $this->request(
            'PUT',
            'v1/admin/roles/' . self::TARGET_ROLE_ID,
            ['name' => 'Tmp Ceiling Target Renamed']
        );

        $body = $this->json($response);

        $this->assertSame(200, $response->getCode(), (string) $response->getContent());
        $this->assertSame('role.updated', $body['message']);
        $this->assertSame(
            'Tmp Ceiling Target Renamed',
            Db::table('roles')->where('id', self::TARGET_ROLE_ID)->value('name')
        );
    }

    // ------------------------------------------------------------------
    // 其余写路径：save / update 带 permissions、创建用户带 role_ids
    // ------------------------------------------------------------------

    /**
     * 场景 6a：创建角色时带越界 permissions → 拒绝，且角色**没有被创建**
     */
    public function testRoleCreateWithPermissionsBeyondOperatorIsRejected(): void
    {
        $slug = 'tmp_ceiling_created_role';
        $this->assertSame(0, Db::table('roles')->where('slug', $slug)->count(), '前置条件：角色不存在');

        $response = $this->request('POST', 'v1/admin/roles', [
            'name' => 'Tmp Ceiling Created',
            'slug' => $slug,
            'permissions' => [$this->permTenantWrite],
        ]);

        $this->assertCeilingDenied($response, ['tenant']);

        $this->assertSame(
            0,
            Db::table('roles')->where('slug', $slug)->count(),
            '被拒绝的创建请求不能留下"半成品角色"'
        );
    }

    /**
     * 场景 6b：创建角色时带合法 permissions → 201 且权限写入
     */
    public function testRoleCreateWithPermissionsWithinOperatorSucceeds(): void
    {
        $response = $this->request('POST', 'v1/admin/roles', [
            'name' => 'Tmp Ceiling Created',
            'slug' => 'tmp_ceiling_created_role',
            'permissions' => [$this->permRoleWrite],
        ]);

        $body = $this->json($response);

        $this->assertSame(201, $response->getCode(), (string) $response->getContent());
        $this->assertSame('role.created', $body['message']);
        $this->assertSame([$this->permRoleWrite], array_map('intval', $body['data']['permissions'] ?? []));
    }

    /**
     * 场景 6c：更新角色时带越界 permissions → 拒绝，且角色名也**没有被改**
     */
    public function testRoleUpdateWithPermissionsBeyondOperatorIsRejected(): void
    {
        $originalName = Db::table('roles')->where('id', self::TARGET_ROLE_ID)->value('name');

        $response = $this->request('PUT', 'v1/admin/roles/' . self::TARGET_ROLE_ID, [
            'name' => 'Tmp Ceiling Target Renamed',
            'permissions' => [$this->permTenantWrite],
        ]);

        $this->assertCeilingDenied($response, ['tenant']);

        $this->assertSame(
            $originalName,
            Db::table('roles')->where('id', self::TARGET_ROLE_ID)->value('name'),
            '授予边界必须在写角色行之前判定，不能出现"改名成功、授权被拒"的部分执行'
        );
        $this->assertSame([], $this->rolePermissionIds(self::TARGET_ROLE_ID));
    }

    /**
     * 场景 6d：创建用户时带越界 role_ids → 拒绝，且用户**没有被创建**
     */
    public function testUserCreateWithRoleBeyondOperatorIsRejected(): void
    {
        $username = 'tmp_ceiling_created_user';
        $this->assertSame(0, Db::table('users')->where('username', $username)->count());

        $response = $this->request('POST', 'v1/admin/users', [
            'username' => $username,
            'email' => 'tmp_ceiling_created_user@test.local',
            'password' => 'password123',
            'role_ids' => [self::STRONG_ROLE_ID],
        ]);

        $this->assertCeilingDenied($response, ['casbin']);

        $this->assertSame(
            0,
            Db::table('users')->where('username', $username)->count(),
            '被拒绝的创建请求不能留下"已创建但没有角色"的账号'
        );
    }

    /**
     * 场景 6e：创建用户时带合法 role_ids → 成功
     */
    public function testUserCreateWithRoleWithinOperatorSucceeds(): void
    {
        $username = 'tmp_ceiling_created_user';

        $response = $this->request('POST', 'v1/admin/users', [
            'username' => $username,
            'email' => 'tmp_ceiling_created_user@test.local',
            'password' => 'password123',
            'role_ids' => [self::WEAK_ROLE_ID],
        ]);

        $body = $this->json($response);

        $this->assertSame(200, $response->getCode(), (string) $response->getContent());
        $this->assertSame('用户创建成功', $body['message']);

        $createdUserId = (int) ($body['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $createdUserId);
        $this->assertSame([self::WEAK_ROLE_ID], $this->userRoleIds($createdUserId));
    }

    // ------------------------------------------------------------------
    // 其它边界：伪造身份头 / 不存在的权限 ID / seeded admin 回归
    // ------------------------------------------------------------------

    /**
     * 场景 7：伪造 `X-User-Id` 请求头不能冒充高权限用户绕过授予边界
     *
     * 身份只能来自 JWT（Auth 中间件写入请求上下文）。历史实现里
     * `UserController::getCurrentUserId()` 优先读这个请求头，等于让客户端自己声明"我是谁"。
     */
    public function testSpoofedUserHeaderCannotBypassCeiling(): void
    {
        $response = $this->request(
            'PUT',
            'v1/admin/roles/' . self::TARGET_ROLE_ID . '/permissions',
            ['permissions' => [$this->permTenantWrite]],
            self::OPERATOR_USER_ID,
            ['X-User-Id' => (string) self::SEEDED_ADMIN_USER_ID]
        );

        $this->assertCeilingDenied($response, ['tenant']);
        $this->assertSame([], $this->rolePermissionIds(self::TARGET_ROLE_ID));
    }

    /**
     * 场景 8：请求里混入不存在的权限 ID → 422（不是 500，也不能先删后炸）
     */
    public function testUnknownPermissionIdIsRejectedWithoutTouchingData(): void
    {
        $setup = $this->request(
            'PUT',
            'v1/admin/roles/' . self::TARGET_ROLE_ID . '/permissions',
            ['permissions' => [$this->permRoleWrite]]
        );
        $this->assertSame(200, $setup->getCode(), (string) $setup->getContent());

        $before = $this->rolePermissionIds(self::TARGET_ROLE_ID);

        $response = $this->request(
            'PUT',
            'v1/admin/roles/' . self::TARGET_ROLE_ID . '/permissions',
            ['permissions' => [$this->permRoleWrite, 999999999]]
        );

        $this->assertSame(422, $response->getCode(), (string) $response->getContent());
        $this->assertSame($before, $this->rolePermissionIds(self::TARGET_ROLE_ID), '校验失败不能改动既有权限');
    }

    /**
     * 场景 9：seeded admin（角色 1 持有 52 条权限）现有流程不受影响
     *
     * 不做"超管绕过"的硬编码：他能授予任意权限，纯粹是因为他**本来就持有**这些权限，
     * 子集校验自然通过。
     */
    public function testSeededAdminCanStillGrantAnyPermission(): void
    {
        $before = $this->rolePermissionIds(self::TARGET_ROLE_ID);
        $this->assertSame([], $before);

        $response = $this->request(
            'PUT',
            'v1/admin/roles/' . self::TARGET_ROLE_ID . '/permissions',
            ['permissions' => [$this->permTenantWrite, $this->permCasbinManage, $this->permUserWrite]],
            self::SEEDED_ADMIN_USER_ID
        );

        $body = $this->json($response);

        $this->assertSame(200, $response->getCode(), (string) $response->getContent());
        $this->assertSame('role.permissions_updated', $body['message']);

        $expected = [$this->permTenantWrite, $this->permCasbinManage, $this->permUserWrite];
        sort($expected);
        $this->assertSame($expected, $this->rolePermissionIds(self::TARGET_ROLE_ID));
    }

    /**
     * 场景 10：seeded admin 分配强角色给用户仍然可行（回归：不能把正常运维流程锁死）
     */
    public function testSeededAdminCanStillAssignStrongRole(): void
    {
        $response = $this->request(
            'PUT',
            'v1/admin/users/' . self::TARGET_USER_ID . '/roles',
            ['role_ids' => [self::STRONG_ROLE_ID]],
            self::SEEDED_ADMIN_USER_ID
        );

        $this->assertSame(200, $response->getCode(), (string) $response->getContent());
        $this->assertSame([self::STRONG_ROLE_ID], $this->userRoleIds(self::TARGET_USER_ID));
    }

    /**
     * 场景 11：授予边界服务自身的判定口径（白盒）
     *
     * 直接验证 `GrantCeilingService` 的差集计算：这条用例把「按权限码比对」
     * 这一选择本身钉住 —— 操作者持有 `role:write`，因此 `role.write` 的 ID 放行，
     * `tenant.write` 的 ID 进入 denied。
     */
    public function testGrantCeilingServiceComputesSubsetByPermissionCode(): void
    {
        $service = new \Infrastructure\Permission\Service\GrantCeilingService(new PermissionService());

        $allowed = $service->inspectPermissions(
            self::OPERATOR_USER_ID,
            [$this->permRoleWrite, $this->permUserWrite]
        );
        $this->assertSame(['unknown' => [], 'denied' => []], $allowed);

        $escaped = $service->inspectPermissions(
            self::OPERATOR_USER_ID,
            [$this->permRoleWrite, $this->permTenantWrite]
        );
        $this->assertSame([], $escaped['unknown']);
        $this->assertSame(
            [$this->permTenantWrite => 'tenant:write'],
            $escaped['denied'],
            'denied 必须按「权限 ID ⇒ 权限码」给出完整差集（供日志取证）'
        );

        $unknown = $service->inspectPermissions(self::OPERATOR_USER_ID, [999999999, 0]);
        $this->assertSame([999999999, 0], $unknown['unknown']);
        $this->assertSame([], $unknown['denied']);
    }
}
