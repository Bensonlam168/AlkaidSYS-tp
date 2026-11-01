<?php

declare(strict_types=1);

namespace Tests\Feature\Permission;

use Domain\Permission\Model\Permission;
use Infrastructure\Permission\Repository\PermissionRepository;
use Infrastructure\Permission\Repository\RoleRepository;
use Infrastructure\Permission\Service\PermissionService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Route;

/**
 * 撤权 / 改角色 / 停用后「权限缓存立即失效」测试（ER-06 回归防护）
 * Permission cache invalidation on authority changes (ER-06 regression guard)
 *
 * ## 守住的缺陷
 *
 * 权限/角色变更后**没有任何缓存失效**，于是撤权只改了数据库：
 * 用户的 L1(60s) / L2(300s) 权限集与检查缓存仍然命中旧值 ——
 * 「撤权成功」与「撤权生效」之间存在最长 300s 的窗口。
 *
 * ## 为什么用真实 HTTP 端点
 *
 * 失效钩子挂在 `RoleRepository::syncPermissions/delete`、`PermissionRepository::save/delete`
 * 与 `UserController`（`updateStatus` / `setRoles` / `update` / `delete`）上。
 * 走完整「路由 → Auth → Permission 中间件 → 控制器 → 仓储」链路，
 * 才能证明「运维点一次撤权按钮」真的会让权限立刻消失。
 *
 * 断言方式：`PermissionService::hasPermission()` 在**同一进程内**紧接着读取。
 * 若缓存没被清，它必然命中旧值（TTL 还没到）—— 断言就会失败。这就是「立即生效」的证据。
 *
 * ## 已知路由缺陷（本次不改，`route/**` 不在改动范围）
 *
 * `config/route.php` 的 `route_complete_match = false`，导致
 * `PUT v1/admin/roles/{id}/permissions`（`setPermissions`）与
 * `PUT v1/admin/users/{id}/roles`（`setRoles`）被先注册的
 * `PUT :id`（`update`）**部分匹配遮蔽**，这两个端点实际进不了各自的控制器动作
 * （实测：请求 `PUT v1/admin/roles/990402/permissions` 返回 `role.updated`，
 * 即走的是 `update`）。这是既有缺陷，与本次改动无关，但会挡住本测试。
 *
 * 因此本类用**独有路径**把请求送进同样的控制器动作：
 * `PUT v1/admin/tmp-cache-test/users/:id/roles` → `UserController@setRoles`，
 * `PUT v1/admin/tmp-cache-test/roles/:id/permissions` → `RoleController@setPermissions`。
 * 路径独有 → 不会被任何既有规则部分匹配，中间件链与控制器逻辑与生产完全一致。
 *
 * @package Tests\Feature\Permission
 */
class PermissionCacheInvalidationTest extends ThinkPHPTestCase
{
    /**
     * 租户 ID（测试库固定存在）
     */
    private const TENANT_ID = 1;

    /**
     * 管理员（持有 user.write / role.write / permission.write）
     */
    private const ADMIN_USER_ID = 990401;
    private const ADMIN_ROLE_ID = 990401;

    /**
     * 被操作的目标用户
     */
    private const TARGET_USER_ID = 990402;

    /**
     * 目标用户初始角色（只持有 permcache.view）
     */
    private const TARGET_ROLE_ID = 990402;

    /**
     * 备用角色（只持有 permcache.edit），用于「换角色」场景
     */
    private const ALT_ROLE_ID = 990403;

    /**
     * 两个自定义权限：资源固定为 permcache，动作 view / edit
     */
    private const PERMISSION_VIEW_ID = 990401;
    private const PERMISSION_EDIT_ID = 990402;

    /**
     * 测试专用路由是否已注册（路由表是进程级的，只需注册一次）
     */
    private static bool $testRoutesRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();

        // 让容器解析 think\Request 时拿到 app\Request（否则取不到 userId/tenantId 上下文）
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 注册 admin 路由（与 PermissionMonitorRouteAuthTest / AuthPermissionIntegrationTest 一致）
        include_once $this->app()->getRootPath() . 'route/admin.php';

        $this->registerTestRoutes();

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
     * 注册两条测试专用路由（绕开既有规则的部分匹配遮蔽，见类注释）
     *
     * 中间件与权限码与生产路由保持一致，因此「鉴权 + 控制器 + 仓储 + 失效钩子」链路不变。
     *
     * @return void
     */
    private function registerTestRoutes(): void
    {
        if (self::$testRoutesRegistered) {
            return;
        }

        Route::put('v1/admin/tmp-cache-test/users/:id/roles', 'app\controller\admin\UserController@setRoles')
            ->middleware(\app\middleware\Auth::class)
            ->middleware(\app\middleware\Permission::class, 'user.write')
            ->completeMatch();

        Route::put('v1/admin/tmp-cache-test/roles/:id/permissions', 'app\controller\admin\RoleController@setPermissions')
            ->middleware(\app\middleware\Auth::class)
            ->middleware(\app\middleware\Permission::class, 'role.write')
            ->completeMatch();

        self::$testRoutesRegistered = true;
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
     * 建立夹具：管理员 / 目标用户 / 三个角色 / 两个权限
     */
    private function prepareFixtures(): void
    {
        $this->cleanupFixtures();

        $now = date('Y-m-d H:i:s');

        $userWriteId = Db::table('permissions')->where('slug', 'user.write')->value('id');
        $roleWriteId = Db::table('permissions')->where('slug', 'role.write')->value('id');
        $permissionWriteId = Db::table('permissions')->where('slug', 'permission.write')->value('id');

        $this->assertNotEmpty($userWriteId, '测试库缺少 user.write 权限行（夹具前提不成立）');
        $this->assertNotEmpty($roleWriteId, '测试库缺少 role.write 权限行（夹具前提不成立）');
        $this->assertNotEmpty($permissionWriteId, '测试库缺少 permission.write 权限行（夹具前提不成立）');

        // 自定义权限（resource:action → permcache:view / permcache:edit）
        Db::table('permissions')->insertAll([
            [
                'id' => self::PERMISSION_VIEW_ID,
                'name' => 'Tmp Permcache View',
                'slug' => 'permcache.view',
                'resource' => 'permcache',
                'action' => 'view',
                'description' => 'fixture',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::PERMISSION_EDIT_ID,
                'name' => 'Tmp Permcache Edit',
                'slug' => 'permcache.edit',
                'resource' => 'permcache',
                'action' => 'edit',
                'description' => 'fixture',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // 三个角色：管理员 / 目标用户初始角色 / 备用角色（都必须是 is_system = 0）
        Db::table('roles')->insertAll([
            [
                'id' => self::ADMIN_ROLE_ID,
                'tenant_id' => self::TENANT_ID,
                'name' => 'Tmp Permcache Admin',
                'slug' => 'tmp_permcache_admin',
                'description' => 'fixture: user.write + role.write + permission.write',
                'is_system' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::TARGET_ROLE_ID,
                'tenant_id' => self::TENANT_ID,
                'name' => 'Tmp Permcache Viewer',
                'slug' => 'tmp_permcache_viewer',
                'description' => 'fixture: permcache:view',
                'is_system' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::ALT_ROLE_ID,
                'tenant_id' => self::TENANT_ID,
                'name' => 'Tmp Permcache Editor',
                'slug' => 'tmp_permcache_editor',
                'description' => 'fixture: permcache:edit',
                'is_system' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Db::table('role_permissions')->insertAll([
            ['role_id' => self::ADMIN_ROLE_ID, 'permission_id' => $userWriteId, 'created_at' => $now],
            ['role_id' => self::ADMIN_ROLE_ID, 'permission_id' => $roleWriteId, 'created_at' => $now],
            ['role_id' => self::ADMIN_ROLE_ID, 'permission_id' => $permissionWriteId, 'created_at' => $now],
            ['role_id' => self::TARGET_ROLE_ID, 'permission_id' => self::PERMISSION_VIEW_ID, 'created_at' => $now],
            ['role_id' => self::ALT_ROLE_ID, 'permission_id' => self::PERMISSION_EDIT_ID, 'created_at' => $now],
        ]);

        Db::table('users')->insertAll([
            [
                'id' => self::ADMIN_USER_ID,
                'tenant_id' => self::TENANT_ID,
                'username' => 'tmp_permcache_admin',
                'email' => 'tmp_permcache_admin@test.local',
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::TARGET_USER_ID,
                'tenant_id' => self::TENANT_ID,
                'username' => 'tmp_permcache_target',
                'email' => 'tmp_permcache_target@test.local',
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Db::table('user_roles')->insertAll([
            ['user_id' => self::ADMIN_USER_ID, 'role_id' => self::ADMIN_ROLE_ID, 'created_at' => $now],
            ['user_id' => self::TARGET_USER_ID, 'role_id' => self::TARGET_ROLE_ID, 'created_at' => $now],
        ]);
    }

    /**
     * 清理夹具（保证零残留）
     */
    private function cleanupFixtures(): void
    {
        $userIds = [self::ADMIN_USER_ID, self::TARGET_USER_ID];
        $roleIds = [self::ADMIN_ROLE_ID, self::TARGET_ROLE_ID, self::ALT_ROLE_ID];
        $permissionIds = [self::PERMISSION_VIEW_ID, self::PERMISSION_EDIT_ID];

        Db::table('user_roles')->whereIn('user_id', $userIds)->delete();
        Db::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        Db::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        Db::table('users')->whereIn('id', $userIds)->delete();
        Db::table('roles')->whereIn('id', $roleIds)->delete();
        Db::table('permissions')->whereIn('id', $permissionIds)->delete();

        // 顺带清掉本类涉及的用户缓存（避免跨用例串味）
        Cache::delete('l1:permission:tenant:' . self::TENANT_ID . ':user:' . self::TARGET_USER_ID . ':permissions');
        Cache::delete('l2:permission:tenant:' . self::TENANT_ID . ':user:' . self::TARGET_USER_ID . ':permissions');
    }

    /**
     * 目标用户的 L2 权限缓存键
     *
     * @return string
     */
    private function targetL2Key(): string
    {
        return 'l2:permission:tenant:' . self::TENANT_ID . ':user:' . self::TARGET_USER_ID . ':permissions';
    }

    /**
     * 预热目标用户缓存，并断言「缓存确实已经建立」
     *
     * @return PermissionService
     */
    private function warmTargetCache(): PermissionService
    {
        $service = new PermissionService();

        $this->assertTrue(
            $service->hasPermission(self::TARGET_USER_ID, 'permcache:view'),
            '前置条件：目标用户初始应持有 permcache:view'
        );
        $this->assertNotNull(Cache::get($this->targetL2Key()), '前置条件：L2 权限缓存应已被预热');

        return $service;
    }

    /**
     * 发起一次真实 JSON 请求（走完整中间件链）
     *
     * 注意：必须先设 `Content-Type: application/json` 再 `withInput()`，
     * 否则 `think\Request::getInputData()` 按「非 json」处理返回空数组，
     * 控制器里的 `put()`（`RoleController::setPermissions` 用的就是它）会取不到参数。
     *
     * @param string $method
     * @param string $uri
     * @param array<string, mixed> $data
     * @return \think\Response
     */
    private function request(string $method, string $uri, array $data = [])
    {
        $this->resetRouteMiddlewareQueue();

        $request = $this->app()->make(\think\Request::class);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        $request = $request->withHeader(['Content-Type' => 'application/json']);

        if ($data !== []) {
            $request->withInput((string) json_encode($data));
        }

        return $this->runHttp($request->withHeader([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                self::ADMIN_USER_ID,
                self::TENANT_ID,
                0
            ),
        ]));
    }

    /**
     * 场景 1：撤销角色的权限 → 同一进程内立即不再拥有该权限（不等 TTL）
     */
    public function testRevokingRolePermissionTakesEffectImmediately(): void
    {
        $service = $this->warmTargetCache();

        $response = $this->request(
            'PUT',
            'v1/admin/tmp-cache-test/roles/' . self::TARGET_ROLE_ID . '/permissions',
            ['permissions' => []]
        );

        $this->assertSame(200, $response->getCode(), '撤权接口应成功：' . (string) $response->getContent());
        $this->assertSame(
            [],
            Db::table('role_permissions')->where('role_id', self::TARGET_ROLE_ID)->column('permission_id'),
            '角色权限应已被清空'
        );

        // 关键断言：缓存已被失效，读取立刻反映数据库新状态
        $this->assertFalse(
            $service->hasPermission(self::TARGET_USER_ID, 'permcache:view'),
            '撤权后必须立即失效缓存：否则最长 300s 内旧权限仍然生效（ER-06）'
        );
    }

    /**
     * 场景 2：角色权限**新增**同样立即生效（反向验证，防止「只会删不会加」）
     */
    public function testGrantingRolePermissionTakesEffectImmediately(): void
    {
        $service = $this->warmTargetCache();

        $this->assertFalse($service->hasPermission(self::TARGET_USER_ID, 'permcache:edit'));

        $response = $this->request(
            'PUT',
            'v1/admin/tmp-cache-test/roles/' . self::TARGET_ROLE_ID . '/permissions',
            ['permissions' => [self::PERMISSION_VIEW_ID, self::PERMISSION_EDIT_ID]]
        );

        $this->assertSame(200, $response->getCode(), (string) $response->getContent());
        $this->assertTrue(
            $service->hasPermission(self::TARGET_USER_ID, 'permcache:edit'),
            '授权后必须立即生效（缓存已失效 + 重新从数据库加载）'
        );
    }

    /**
     * 场景 3：改用户角色 → 权限集立即更新（失去旧角色权限、获得新角色权限）
     */
    public function testChangingUserRolesTakesEffectImmediately(): void
    {
        $service = $this->warmTargetCache();

        $response = $this->request(
            'PUT',
            'v1/admin/tmp-cache-test/users/' . self::TARGET_USER_ID . '/roles',
            ['role_ids' => [self::ALT_ROLE_ID]]
        );

        $this->assertSame(200, $response->getCode(), (string) $response->getContent());
        $this->assertSame(
            [self::ALT_ROLE_ID],
            array_map('intval', Db::table('user_roles')->where('user_id', self::TARGET_USER_ID)->column('role_id')),
            '角色应已切换'
        );

        $this->assertFalse(
            $service->hasPermission(self::TARGET_USER_ID, 'permcache:view'),
            '换角色后旧角色的权限必须立即消失'
        );
        $this->assertTrue(
            $service->hasPermission(self::TARGET_USER_ID, 'permcache:edit'),
            '换角色后新角色的权限必须立即生效'
        );
    }

    /**
     * 场景 4：停用用户 → 缓存立即失效（配合 ER-04 的中间件校验形成双保险）
     */
    public function testDisablingUserInvalidatesCache(): void
    {
        $this->warmTargetCache();

        $response = $this->request(
            'PATCH',
            'v1/admin/users/' . self::TARGET_USER_ID . '/status',
            ['status' => 'inactive']
        );

        $this->assertSame(200, $response->getCode(), (string) $response->getContent());
        $this->assertSame(
            'inactive',
            Db::table('users')->where('id', self::TARGET_USER_ID)->value('status')
        );

        $this->assertNull(
            Cache::get($this->targetL2Key()),
            '停用后 L2 权限缓存必须被清除（ER-06 + ER-04 双保险）'
        );
    }

    /**
     * 场景 5：权限定义变更（resource/action 改写）→ 全部权限缓存失效
     */
    public function testPermissionDefinitionUpdateInvalidatesCaches(): void
    {
        $service = $this->warmTargetCache();

        // 把 permcache:view 改写成 permcache:edit（等价于对外权限码变化）
        $permission = (new Permission('Tmp Permcache View', 'permcache.view'))->setId(self::PERMISSION_VIEW_ID);
        $permission->fromArray([
            'resource' => 'permcache',
            'action' => 'edit',
            'description' => 'fixture updated',
        ]);

        (new PermissionRepository())->save($permission);

        $this->assertFalse(
            $service->hasPermission(self::TARGET_USER_ID, 'permcache:view'),
            '权限码变化后，旧的 permcache:view 必须立即失效'
        );
        $this->assertTrue(
            $service->hasPermission(self::TARGET_USER_ID, 'permcache:edit'),
            '权限码变化后，新的 permcache:edit 必须立即生效'
        );
    }

    /**
     * 场景 6：删除角色 → 相关用户缓存失效（即便仍残留 user_roles 关联）
     */
    public function testDeletingRoleInvalidatesMemberCaches(): void
    {
        $service = $this->warmTargetCache();

        // 直接走仓储：控制器会因「角色下还有用户」而 409，这里验证的是失效钩子本身
        $deleted = (new RoleRepository())->delete(self::TARGET_ROLE_ID, self::TENANT_ID);

        $this->assertTrue($deleted);

        $this->assertNull(
            Cache::get($this->targetL2Key()),
            '删除角色后该角色成员的权限缓存必须被清除'
        );
        $this->assertFalse(
            $service->hasPermission(self::TARGET_USER_ID, 'permcache:view'),
            '角色已删除，权限必须立即消失'
        );
    }
}
