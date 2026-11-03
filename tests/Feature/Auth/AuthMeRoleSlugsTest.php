<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Infrastructure\Permission\Service\PermissionService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * /v1/auth/me 返回角色 slug 测试（缺陷 A7：前端权限路由失效）
 *
 * 背景：
 *   前端 `frontend/packages/utils/src/helpers/generate-routes-frontend.ts` 的
 *   `hasAuthority()` 用 `route.meta.authority`（角色 **slug**，如 `admin`）
 *   与登录用户携带的 access 列表做比对；而 `/v1/auth/me` 原先只返回 `roles`（角色 **ID**），
 *   导致带 `authority` 的路由在「整数 ID vs 字符串 slug」下永不匹配，系统管理菜单整体消失。
 *
 * 目标行为：
 *   - 新增 `role_slugs`（字符串数组，角色 slug 列表）；
 *   - **保留** `roles`（角色 ID 数组）以兼容既有消费者，避免破坏性变更；
 *   - `role_slugs` 必须是 slug 而非 ID（不含纯数字项），否则前端权限判断依旧失效。
 *
 * 测试要点：
 *   走真实 HTTP 路由 + 真实 JWT 认证链路（不绕过 Auth 中间件），
 *   播种数据使用高位 ID 以避开既有测试数据，并在 tearDown 中清理且断言无残留。
 *
 * @package Tests\Feature\Auth
 */
class AuthMeRoleSlugsTest extends ThinkPHPTestCase
{
    /**
     * 测试用户 ID（租户 1）
     * 使用高位 ID，避免与既有测试数据（<= 101050）或种子数据冲突
     */
    private int $userId = 991001;

    /**
     * 测试角色 ID（租户 1）
     */
    private int $roleId = 991001;

    /**
     * 测试角色 slug（刻意不含数字，便于断言「不是 ID」）
     */
    private string $roleSlug = 'a7_test_admin';

    protected function setUp(): void
    {
        parent::setUp();

        // 清除容器中可能被上一个用例污染的 Request 实例
        // （tests/ThinkPHPTestCase 复用同一个 App 实例，容器 make() 会缓存实例）
        try {
            $this->app()->delete(\think\Request::class);
        } catch (\Throwable $e) {
            // 容器未注册时忽略
        }

        // 绑定自定义 Request，确保 tenantId()/userId()/siteId() 多租户方法可用
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 加载认证路由（/v1/auth/me 挂载 Auth 中间件）
        $routeFile = $this->app()->getRootPath() . 'route/auth.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 复位鉴权中间件实例：其他测试类可能把 Auth / Permission 绑定为 no-op 以绕过鉴权，
        // 而 think\Container::make() 会缓存首次解析结果，导致拿不到真实用户上下文。
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        // 清空路由/控制器中间件队列（ThinkPHP 的 Middleware 队列在同一进程内跨请求累积）
        $this->resetRouteMiddlewareQueue();

        // 播种租户 1 下的用户 + 角色 + 绑定关系
        $this->seedRoleData();

        // 清除权限缓存，避免历史运行残留的缓存影响 /v1/auth/me 的 permissions 字段
        $this->clearPermissionCache();
    }

    protected function tearDown(): void
    {
        // 清理本用例播种的数据，并确认无残留
        $this->cleanupRoleData();

        $this->assertSame(
            0,
            (int) Db::table('user_roles')->where('user_id', $this->userId)->count(),
            '残留检查失败：user_roles 仍存在测试用户 ' . $this->userId . ' 的绑定记录'
        );
        $this->assertSame(
            0,
            (int) Db::table('roles')->where('id', $this->roleId)->count(),
            '残留检查失败：roles 仍存在测试角色 ' . $this->roleId
        );
        $this->assertSame(
            0,
            (int) Db::table('users')->where('id', $this->userId)->count(),
            '残留检查失败：users 仍存在测试用户 ' . $this->userId
        );

        $this->resetRouteMiddlewareQueue();
        $this->clearPermissionCache();

        parent::tearDown();
    }

    /**
     * 核心用例：/v1/auth/me 返回 role_slugs（字符串 slug 数组，且包含播种的 slug）
     *
     * RED 阶段：该字段不存在 → 断言 data.role_slugs 时失败。
     */
    public function testMeReturnsRoleSlugs(): void
    {
        $response = $this->requestMe();
        $body = json_decode($response->getContent(), true);

        $this->assertSame(
            200,
            $response->getCode(),
            'GET /v1/auth/me 应返回 200，实际响应：' . $response->getContent()
        );
        $this->assertIsArray($body, '响应体应为 JSON 对象');
        $this->assertSame(0, $body['code'] ?? null, '成功响应业务码应为 0');
        $this->assertArrayHasKey('data', $body);

        // 核心断言：必须新增 role_slugs 字段
        $this->assertArrayHasKey(
            'role_slugs',
            $body['data'],
            '/v1/auth/me 的 data 中必须包含 role_slugs（角色 slug 数组），'
            . '否则前端 meta.authority（slug）无法与用户角色匹配，系统管理菜单会全部消失。'
            . '实际响应：' . $response->getContent()
        );

        $slugs = $body['data']['role_slugs'];
        $this->assertIsArray($slugs, 'role_slugs 必须是数组');

        // 每一项都必须是字符串
        foreach ($slugs as $index => $slug) {
            $this->assertIsString($slug, 'role_slugs[' . $index . '] 必须是字符串，实际：' . var_export($slug, true));
        }

        // 必须包含本用例播种的角色 slug
        $this->assertContains(
            $this->roleSlug,
            $slugs,
            'role_slugs 必须包含用户实际拥有的角色 slug：' . $this->roleSlug
            . '，实际值：' . json_encode($slugs, JSON_UNESCAPED_UNICODE)
        );

        // 不得包含纯数字项（证明返回的是 slug 而不是角色 ID）
        foreach ($slugs as $slug) {
            $this->assertFalse(
                ctype_digit((string) $slug),
                'role_slugs 不应包含纯数字（角色 ID），实际项：' . $slug
            );
        }
    }

    /**
     * 兼容性回归用例：/v1/auth/me 仍保留 roles（角色 ID 数组）
     *
     * 目的：新增 role_slugs 不得移除既有 roles 字段，避免破坏现有消费者。
     */
    public function testMeKeepsLegacyRolesField(): void
    {
        $response = $this->requestMe();
        $body = json_decode($response->getContent(), true);

        $this->assertSame(
            200,
            $response->getCode(),
            'GET /v1/auth/me 应返回 200，实际响应：' . $response->getContent()
        );
        $this->assertIsArray($body);
        $this->assertArrayHasKey(
            'roles',
            $body['data'],
            '/v1/auth/me 必须继续返回 roles（角色 ID 数组），不得做破坏性变更。'
            . '实际响应：' . $response->getContent()
        );

        $roleIds = (array) $body['data']['roles'];
        // 数据库驱动可能返回字符串 ID，统一转 int 比较
        $this->assertContains(
            $this->roleId,
            array_map('intval', $roleIds),
            'roles 必须包含用户实际拥有的角色 ID：' . $this->roleId
            . '，实际值：' . json_encode($roleIds)
        );
    }

    /**
     * 访问 GET /v1/auth/me（真实 Auth 中间件链路）
     */
    private function requestMe()
    {
        // 每次新建 app\Request 实例：容器中的 Request 会残留上一个用例的路由规则
        $request = new \app\Request();
        $request->setMethod('GET');
        $request->setPathinfo('v1/auth/me');
        $request = $request->withHeader([
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                userId: $this->userId,
                tenantId: 1,
                siteId: 0
            ),
            'Accept' => 'application/json',
            'X-Tenant-ID' => '1',
            'X-Trace-Id' => 'trace-a7-role-slugs-' . uniqid(),
        ]);

        return $this->runHttp($request);
    }

    /**
     * 播种：租户 1 下的测试用户 + 测试角色 + 用户角色绑定
     */
    private function seedRoleData(): void
    {
        $now = date('Y-m-d H:i:s');

        // 先清理上一次异常中断留下的残留（按固定 ID），保证插入幂等
        $this->cleanupRoleData();

        Db::table('roles')->insert([
            'id' => $this->roleId,
            'tenant_id' => 1,
            'name' => 'A7 Test Admin',
            'slug' => $this->roleSlug,
            'description' => 'A7 role_slugs 测试角色',
            'is_system' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Db::table('users')->insert([
            'id' => $this->userId,
            'tenant_id' => 1,
            'username' => 'a7_role_slugs_user',
            'email' => 'a7_role_slugs_user@a7-role-slugs.test',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'name' => 'A7 Role Slugs User',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Db::table('user_roles')->insert([
            'user_id' => $this->userId,
            'role_id' => $this->roleId,
            'created_at' => $now,
        ]);
    }

    /**
     * 清理本用例播种的数据（先中间表，再主表）
     */
    private function cleanupRoleData(): void
    {
        Db::table('user_roles')->where('user_id', $this->userId)->delete();
        Db::table('roles')->where('id', $this->roleId)->delete();
        Db::table('users')->where('id', $this->userId)->delete();
    }

    /**
     * 清空路由/控制器中间件队列（保留全局中间件）
     */
    private function resetRouteMiddlewareQueue(): void
    {
        $middleware = $this->app()->middleware;

        $ref = new \ReflectionProperty(\think\Middleware::class, 'queue');
        $ref->setAccessible(true);

        $queue = (array) $ref->getValue($middleware);
        $queue['route'] = [];
        $queue['controller'] = [];

        $ref->setValue($middleware, $queue);
    }

    /**
     * 清除测试用户的权限缓存（L1/L2）
     */
    private function clearPermissionCache(): void
    {
        $permissionService = new PermissionService();
        $permissionService->clearUserCache($this->userId);
    }
}
