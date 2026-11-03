<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

// 加载 ThinkPHP 助手函数 | Load ThinkPHP helper functions
if (!function_exists('env')) {
    require_once __DIR__ . '/../../../vendor/topthink/framework/src/helper.php';
}

/**
 * 权限监控路由鉴权测试
 * Permission Monitor Route Authorization Test
 *
 * ## 背景（本测试要守住的回归）
 *
 * `route/admin.php` 的 `v1/admin/permission-monitor` 组历史上**整组没有任何中间件**。
 * 实测（改动前）：
 * ```
 * GET    /v1/admin/permission-monitor/cache-stats            无 token = 200
 * GET    /v1/admin/permission-monitor/performance            无 token = 200
 * GET    /v1/admin/permission-monitor/health                 无 token = 206
 * GET    /v1/admin/permission-monitor/user/1/permissions     无 token = 200
 * GET    /v1/admin/permission-monitor/user/1/check/user:read 无 token = 200
 * DELETE /v1/admin/permission-monitor/cache/user/<id>        无 token = 200
 * DELETE /v1/admin/permission-monitor/cache                  无 token = 200
 * ```
 * 即：任何能访问该端口的人都能读权限子系统内部指标，并清空指定用户 / 全部权限缓存。
 *
 * ## 现在守住的契约
 *
 * 1. 7 条路由全部要求 Auth：无 token → 401 + 2001；
 * 2. 5 条只读路由要求 `permission.read`：有 token 无权限 → 403 + 2002，有权限 → 200（health 允许 200/206）；
 * 3. 2 条清缓存路由要求 `permission.write`：只有 `permission.read` → 403，有 `permission.write` → 非 401/403；
 * 4. `:userId` 必须属于**当前请求租户**（跨租户读/清一律 404，且不泄露「该 id 是否存在」）；
 * 5. `:userId` 只接受纯数字；`:permission` 允许 `resource:action` 且不被截断。
 *
 * ## 夹具策略
 *
 * 全部夹具自带固定 ID 并在 tearDown 删除，**不依赖种子数据里「哪个用户是管理员」**——
 * 开发库正是因为该假设不成立（`alkaid_sys` 缺 `permission.read` 权限行）而出现「管理员一律 403」。
 *
 * ## 为什么要清 `think\Middleware::$queue`
 *
 * `think\route\Dispatch::doRouteAfter()` 会把当前规则的中间件 `import(..., 'route')` 进
 * `think\Middleware::$queue`，而该队列是**进程级累加、框架从不清空**（只有 `array_unique` 去重）。
 * 于是一个进程内先请求过 `DELETE .../cache/user/<id>`（挂 `permission.write`）之后，
 * 后续任何请求都会**额外**跑一遍那条 `permission.write` 检查 —— 表现为「只读用户访问只读路由也 403」。
 * 这与 `Tests\Feature\Auth\SessionInvalidationTest` / `WorkflowTenantIsolationTest` 的做法一致：
 * 每次发请求前清空 route/controller 队列（全局中间件保留）。
 *
 * @package Tests\Feature\Admin
 */
class PermissionMonitorRouteAuthTest extends ThinkPHPTestCase
{
    /**
     * 租户 A（默认租户）ID：测试库固定存在
     */
    private const TENANT_A = 1;

    /**
     * 租户 B ID：测试库固定存在（Test Tenant Two）
     */
    private const TENANT_B = 2;

    /**
     * 只有 `permission.read` 的用户
     */
    private const USER_READ_ONLY = 990101;

    /**
     * 同时具备 `permission.read` + `permission.write` 的用户
     */
    private const USER_WRITER = 990102;

    /**
     * 无任何角色的用户（已认证但无权限）
     */
    private const USER_NO_ROLE = 990103;

    /**
     * 属于**租户 B** 的受害者用户
     */
    private const USER_TENANT_B = 990104;

    /**
     * 夹具角色 ID（租户 A 两个 / 租户 B 一个）
     */
    private const ROLE_READ_ONLY = 990101;
    private const ROLE_WRITER    = 990102;
    private const ROLE_TENANT_B  = 990103;

    /**
     * 5 条只读路由（要求 permission.read）
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function readRoutes(): array
    {
        return [
            'cache-stats'          => ['GET', 'v1/admin/permission-monitor/cache-stats'],
            'performance'          => ['GET', 'v1/admin/permission-monitor/performance'],
            'user-permissions'     => ['GET', 'v1/admin/permission-monitor/user/' . self::USER_READ_ONLY . '/permissions'],
            'user-check-permission' => ['GET', 'v1/admin/permission-monitor/user/' . self::USER_READ_ONLY . '/check/user:read'],
            'health'               => ['GET', 'v1/admin/permission-monitor/health'],
        ];
    }

    /**
     * 2 条清缓存路由（要求 permission.write）
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function deleteRoutes(): array
    {
        return [
            'cache-user' => ['DELETE', 'v1/admin/permission-monitor/cache/user/' . self::USER_WRITER],
            'cache-all'  => ['DELETE', 'v1/admin/permission-monitor/cache'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 让容器解析 think\Request 时拿到 app\Request（否则取不到 userId/tenantId 上下文）
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 注册 admin 路由（与 AuthPermissionIntegrationTest 的写法一致）
        include_once $this->app()->getRootPath() . 'route/admin.php';

        // 复位鉴权中间件实例：其它测试类可能把 Auth / Permission 绑定成 no-op 替身，
        // 容器会缓存首次解析结果，那样本类的断言会变成「假绿」。
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        $this->resetRouteMiddlewareQueue();
        $this->prepareFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        // 不把路由中间件留给后续测试类
        $this->resetRouteMiddlewareQueue();

        parent::tearDown();
    }

    /**
     * 清空路由/控制器中间件队列（保留全局中间件）
     *
     * `think\Middleware::$queue` 是受保护属性且无公开 API，只能反射；原因见类注释。
     */
    private function resetRouteMiddlewareQueue(): void
    {
        $middleware = $this->app()->middleware;

        $ref = new \ReflectionProperty(\think\Middleware::class, 'queue');
        $ref->setAccessible(true);

        $queue = (array) $ref->getValue($middleware);
        $queue['route']      = [];
        $queue['controller'] = [];

        $ref->setValue($middleware, $queue);
    }

    /**
     * 创建夹具：两个租户的用户 / 角色 / 角色权限
     */
    private function prepareFixtures(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->cleanupFixtures();

        // 权限 id 一律按 slug 查，不硬编码：避免种子顺序变化导致夹具错位
        $permissionReadId    = Db::table('permissions')->where('slug', 'permission.read')->value('id');
        $permissionWriteId   = Db::table('permissions')->where('slug', 'permission.write')->value('id');
        $tenantBPermissionId = Db::table('permissions')->where('slug', 'collections.view')->value('id');

        $this->assertNotEmpty($permissionReadId, '测试库缺少 permission.read 权限行（夹具前提不成立）');
        $this->assertNotEmpty($permissionWriteId, '测试库缺少 permission.write 权限行（夹具前提不成立）');
        $this->assertNotEmpty($tenantBPermissionId, '测试库缺少 collections.view 权限行（夹具前提不成立）');

        // 租户 A：只读角色 | Tenant A: read-only role
        Db::table('roles')->insert([
            'id'          => self::ROLE_READ_ONLY,
            'tenant_id'   => self::TENANT_A,
            'name'        => 'Tmp Permission Monitor Reader',
            'slug'        => 'tmp_pm_reader',
            'description' => 'fixture: only permission.read',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        Db::table('role_permissions')->insert([
            'role_id'       => self::ROLE_READ_ONLY,
            'permission_id' => $permissionReadId,
            'created_at'    => $now,
        ]);

        // 租户 A：可写角色 | Tenant A: writer role
        Db::table('roles')->insert([
            'id'          => self::ROLE_WRITER,
            'tenant_id'   => self::TENANT_A,
            'name'        => 'Tmp Permission Monitor Writer',
            'slug'        => 'tmp_pm_writer',
            'description' => 'fixture: permission.read + permission.write',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        Db::table('role_permissions')->insertAll([
            ['role_id' => self::ROLE_WRITER, 'permission_id' => $permissionReadId, 'created_at' => $now],
            ['role_id' => self::ROLE_WRITER, 'permission_id' => $permissionWriteId, 'created_at' => $now],
        ]);

        // 租户 B：受害者角色（带一个可识别的租户 B 权限）| Tenant B: victim role
        Db::table('roles')->insert([
            'id'          => self::ROLE_TENANT_B,
            'tenant_id'   => self::TENANT_B,
            'name'        => 'Tmp Tenant B Role',
            'slug'        => 'tmp_pm_tenant_b',
            'description' => 'fixture: tenant B only',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        Db::table('role_permissions')->insert([
            'role_id'       => self::ROLE_TENANT_B,
            'permission_id' => $tenantBPermissionId,
            'created_at'    => $now,
        ]);

        $users = [
            [self::USER_READ_ONLY, self::TENANT_A, 'tmp_pm_read_only'],
            [self::USER_WRITER, self::TENANT_A, 'tmp_pm_writer'],
            [self::USER_NO_ROLE, self::TENANT_A, 'tmp_pm_no_role'],
            [self::USER_TENANT_B, self::TENANT_B, 'tmp_pm_tenant_b'],
        ];
        foreach ($users as [$id, $tenantId, $username]) {
            Db::table('users')->insert([
                'id'         => $id,
                'tenant_id'  => $tenantId,
                'username'   => $username,
                'email'      => $username . '@test.local',
                'password'   => password_hash('password', PASSWORD_DEFAULT),
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Db::table('user_roles')->insertAll([
            ['user_id' => self::USER_READ_ONLY, 'role_id' => self::ROLE_READ_ONLY, 'created_at' => $now],
            ['user_id' => self::USER_WRITER, 'role_id' => self::ROLE_WRITER, 'created_at' => $now],
            ['user_id' => self::USER_TENANT_B, 'role_id' => self::ROLE_TENANT_B, 'created_at' => $now],
        ]);
    }

    /**
     * 清理夹具（保证「零残留」）
     */
    private function cleanupFixtures(): void
    {
        $userIds = [self::USER_READ_ONLY, self::USER_WRITER, self::USER_NO_ROLE, self::USER_TENANT_B];
        $roleIds = [self::ROLE_READ_ONLY, self::ROLE_WRITER, self::ROLE_TENANT_B];

        Db::table('user_roles')->whereIn('user_id', $userIds)->delete();
        Db::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        Db::table('users')->whereIn('id', $userIds)->delete();
        Db::table('roles')->whereIn('id', $roleIds)->delete();
    }

    /**
     * 发起一次真实 HTTP 请求（走完整中间件链）
     *
     * @param string      $method HTTP 方法
     * @param string      $uri    pathinfo
     * @param string|null $token  Access Token（null = 不带 Authorization）
     * @return \think\Response
     */
    private function request(string $method, string $uri, ?string $token = null)
    {
        // 每个请求前清空路由中间件队列，避免上一次请求的规则中间件被累加进来（见类注释）
        $this->resetRouteMiddlewareQueue();

        $request = $this->app()->make(\think\Request::class);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $this->runHttp($request->withHeader($headers));
    }

    /**
     * 解析响应体
     *
     * @param \think\Response $response
     * @return array<string, mixed>
     */
    private function body($response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 场景 1：7 条路由全部要求认证 —— 无 token → 401 + 2001
     *
     * 这正是「改动前无 token 是 200/206」的对照组。
     */
    public function testAllRoutesRejectMissingToken(): void
    {
        $routes = self::readRoutes() + self::deleteRoutes();
        $this->assertCount(7, $routes, '必须覆盖全部 7 条路由');

        foreach ($routes as $name => [$method, $uri]) {
            $response = $this->request($method, $uri);

            $this->assertSame(401, $response->getCode(), "[{$name}] 无 token 必须返回 401");
            $this->assertSame(2001, $this->body($response)['code'] ?? null, "[{$name}] 无 token 业务码必须是 2001");
        }
    }

    /**
     * 场景 2：持有 token 但无 `permission.read` → 403 + 2002（5 条只读路由）
     */
    public function testReadRoutesRejectUserWithoutPermission(): void
    {
        $token = AuthHelper::generateTestToken(self::USER_NO_ROLE, self::TENANT_A, 0);

        foreach (self::readRoutes() as $name => [$method, $uri]) {
            $response = $this->request($method, $uri, $token);

            $this->assertSame(403, $response->getCode(), "[{$name}] 无 permission.read 必须 403");
            $this->assertSame(2002, $this->body($response)['code'] ?? null, "[{$name}] 业务码必须是 2002");
        }
    }

    /**
     * 场景 3：持有 `permission.read` → 5 条只读路由放行（health 允许 200/206）
     */
    public function testReadRoutesAllowUserWithReadPermission(): void
    {
        $token = AuthHelper::generateTestToken(self::USER_READ_ONLY, self::TENANT_A, 0);

        foreach (self::readRoutes() as $name => [$method, $uri]) {
            $response = $this->request($method, $uri, $token);
            $body = $this->body($response);

            $this->assertContains(
                $response->getCode(),
                [200, 206],
                "[{$name}] 有 permission.read 必须 200/206，实际 {$response->getCode()}：{$response->getContent()}"
            );
            $this->assertSame(200, $body['code'] ?? null, "[{$name}] 业务码应为 200");

            if ($name === 'user-permissions') {
                $this->assertSame(
                    self::USER_READ_ONLY,
                    $body['data']['user_id'] ?? null,
                    '[user-permissions] 必须返回被查询用户本人的数据'
                );
            }
        }
    }

    /**
     * 场景 4：只有 `permission.read` 时，2 条 DELETE 必须 403（清缓存属于写操作）
     */
    public function testDeleteRoutesRejectReadOnlyUser(): void
    {
        $token = AuthHelper::generateTestToken(self::USER_READ_ONLY, self::TENANT_A, 0);

        foreach (self::deleteRoutes() as $name => [$method, $uri]) {
            $response = $this->request($method, $uri, $token);

            $this->assertSame(403, $response->getCode(), "[{$name}] 只有 permission.read 必须 403");
            $this->assertSame(2002, $this->body($response)['code'] ?? null, "[{$name}] 业务码必须是 2002");
        }
    }

    /**
     * 场景 5：持有 `permission.write` → 2 条 DELETE 通过鉴权（非 401/403）
     */
    public function testDeleteRoutesAllowUserWithWritePermission(): void
    {
        $token = AuthHelper::generateTestToken(self::USER_WRITER, self::TENANT_A, 0);

        foreach (self::deleteRoutes() as $name => [$method, $uri]) {
            $response = $this->request($method, $uri, $token);

            $this->assertNotContains(
                $response->getCode(),
                [401, 403],
                "[{$name}] 有 permission.write 不应被鉴权拒绝，实际 {$response->getCode()}：{$response->getContent()}"
            );
            $this->assertContains(
                $response->getCode(),
                [200, 404],
                "[{$name}] 期望 200（清缓存成功）或 404（目标用户不属于本租户）"
            );
        }
    }

    /**
     * 场景 6：跨租户 —— 租户 A 的用户读/清**租户 B** 用户 → 一律 404
     *
     * 改动前实测：`GET /user/<租户B用户>/permissions` 返回 200 +
     * `{"user_id":990104,"permissions":["collections:view"]}`（租户 B 的授权数据被跨租户读出）。
     */
    public function testCrossTenantUserAccessIsDenied(): void
    {
        $tokenA = AuthHelper::generateTestToken(self::USER_WRITER, self::TENANT_A, 0);

        $cases = [
            'permissions' => ['GET', 'v1/admin/permission-monitor/user/' . self::USER_TENANT_B . '/permissions'],
            'check'       => ['GET', 'v1/admin/permission-monitor/user/' . self::USER_TENANT_B . '/check/collections:view'],
            'clear-cache' => ['DELETE', 'v1/admin/permission-monitor/cache/user/' . self::USER_TENANT_B],
        ];

        foreach ($cases as $name => [$method, $uri]) {
            $response = $this->request($method, $uri, $tokenA);

            $this->assertSame(404, $response->getCode(), "[{$name}] 跨租户访问必须 404，实际 {$response->getCode()}：{$response->getContent()}");
            // 业务码同样是 404（与 HTTP 状态码一致，便于前端统一处理）
            $this->assertSame(404, $this->body($response)['code'] ?? null, "[{$name}] 业务码必须是 404");
            // 状态码对了也不许把租户 B 的数据带出来（`data` 必须为空）
            $this->assertNull($this->body($response)['data'] ?? null, "[{$name}] 错误响应不应带回租户 B 的数据");
        }
    }

    /**
     * 场景 7：同租户用户仍可读 / 可清（修复不能误伤正常用法）
     */
    public function testSameTenantUserRemainsAccessible(): void
    {
        $tokenA = AuthHelper::generateTestToken(self::USER_WRITER, self::TENANT_A, 0);

        $read = $this->request(
            'GET',
            'v1/admin/permission-monitor/user/' . self::USER_READ_ONLY . '/permissions',
            $tokenA
        );
        $this->assertSame(200, $read->getCode());
        $this->assertSame(self::USER_READ_ONLY, $this->body($read)['data']['user_id'] ?? null);

        $clear = $this->request(
            'DELETE',
            'v1/admin/permission-monitor/cache/user/' . self::USER_READ_ONLY,
            $tokenA
        );
        $this->assertSame(200, $clear->getCode());
        $this->assertSame(self::USER_READ_ONLY, $this->body($clear)['data']['user_id'] ?? null);
    }

    /**
     * 场景 8：`:userId` 只接受纯数字 —— 非法路径不进入控制器
     */
    public function testNonNumericUserIdIsRejected(): void
    {
        $token = AuthHelper::generateTestToken(self::USER_WRITER, self::TENANT_A, 0);

        $response = $this->request('GET', 'v1/admin/permission-monitor/user/abc/permissions', $token);

        $this->assertNotSame(200, $response->getCode(), 'userId 非数字时不应命中该路由');
        $this->assertContains($response->getCode(), [400, 404]);
    }

    /**
     * 场景 9：`:permission` 允许 `resource:action` 形态，不被截断
     *
     * 改动前实测（默认变量规则 `[\w\.]+` 不含 `:`）：`check/user:read` 返回
     * `{"permission":"user","has_permission":false}` —— 静默错答。
     */
    public function testPermissionParamKeepsResourceActionFormat(): void
    {
        $token = AuthHelper::generateTestToken(self::USER_WRITER, self::TENANT_A, 0);

        $response = $this->request(
            'GET',
            'v1/admin/permission-monitor/user/' . self::USER_WRITER . '/check/user:read',
            $token
        );

        $this->assertSame(200, $response->getCode());
        $this->assertSame('user:read', $this->body($response)['data']['permission'] ?? null);
    }
}
