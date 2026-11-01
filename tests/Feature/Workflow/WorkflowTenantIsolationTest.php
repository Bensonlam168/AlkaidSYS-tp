<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Infrastructure\Permission\Service\PermissionService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流多租户隔离 HTTP 级集成测试（缺陷 C-7）
 *
 * 背景：
 *   控制器原本使用 `$request->tenantId`（属性访问），而 `app\Request` 中 `tenantId`
 *   是受保护属性 + 同名方法。PHP 属性访问会走到 `think\Request::__get()` →
 *   `middleware('tenantId')` → 恒为 null，导致所有租户过滤分支永不生效。
 *
 * 本测试通过真实 HTTP 路由 + 真实 JWT 认证链路验证：
 *   1. 跨租户不可读（列表不包含、按 ID 读取被拒绝）；
 *   2. 写入的 `tenant_id` 来自 JWT 中解析出的租户上下文，而不是 null。
 *
 * 注意：
 *   - 工作流控制器使用进程内 `protected static array` 存储，必须在每个用例前后清空，
 *     否则前序用例数据会污染本用例结论；
 *   - 工作流定义路由挂载了 `Permission` 中间件（权限码 workflow_definition.*），
 *     本测试按项目既有做法（参考 CasbinControllerTest）在数据库内授予测试用户
 *     相应权限，从而走真实权限校验链路，不绕过中间件。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowTenantIsolationTest extends ThinkPHPTestCase
{
    use \Tests\Traits\WorkflowFixtureIsolation;

    /**
     * 测试用户 ID（租户 1 / 租户 2）
     * 使用高位 ID，避免与既有测试数据（<= 101050）或种子数据冲突
     */
    private int $tenant1UserId = 990001;
    private int $tenant2UserId = 990002;

    /**
     * 测试角色 ID（租户 1 / 租户 2）
     */
    private int $tenant1RoleId = 990001;
    private int $tenant2RoleId = 990002;

    /**
     * 工作流定义权限 ID（读 / 写）
     */
    private int $readPermissionId = 990101;
    private int $writePermissionId = 990102;

    protected function setUp(): void
    {
        parent::setUp();

        // 绑定自定义 Request，确保 tenantId()/userId()/siteId() 多租户方法可用
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 加载工作流路由（含 Auth + Permission 中间件）
        $routeFile = $this->app()->getRootPath() . 'route/workflow.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 复位鉴权中间件实例：其他测试类（如低代码多租户测试）会把 Auth / Permission
        // 绑定为 no-op 以绕过鉴权，而 think\Container::make() 会把首次解析结果缓存进
        // instances，导致后续测试拿到 no-op（拿不到真实租户上下文）。
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        // 清空路由/控制器中间件队列：ThinkPHP 的 Middleware 队列在同一进程内跨请求累积，
        // 前序用例注册的路由中间件（含其他权限码）会被重复执行，必须在本用例开始前复位。
        $this->resetRouteMiddlewareQueue();

        // 清空控制器进程内静态存储，避免用例间互相污染
        $this->resetInMemoryStores();

        // 授予测试用户工作流定义读写权限
        $this->seedPermissions();

        // 清除权限缓存，避免历史运行残留的空权限集合导致误判
        $this->clearPermissionCache();
    }

    protected function tearDown(): void
    {
        // 清空本用例注册的路由中间件，避免污染后续测试套件
        $this->resetRouteMiddlewareQueue();
        $this->resetInMemoryStores();
        $this->cleanupPermissions();
        $this->clearPermissionCache();

        parent::tearDown();
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
     * 用例 1（核心）：跨租户不可读
     *
     * 租户 1 创建定义后：
     *   - 同租户列表必须能看到（基线，防止"根本没写入"的假绿）；
     *   - 租户 2 列表不得包含该定义；
     *   - 租户 2 按 ID 读取必须被拒绝（403，业务码同样是 403，区别于权限中间件的 2002）。
     */
    public function testCrossTenantDefinitionIsNotReadable(): void
    {
        // 步骤 1：租户 1 创建定义
        $definitionId = $this->createDefinitionAsTenant1();

        // 基线：同租户列表应能看到自己创建的定义
        $ownListResponse = $this->httpRequest(
            'GET',
            'v1/workflow/definitions',
            [],
            $this->authHeaders($this->tenant1UserId, 1)
        );
        $this->assertSame(
            200,
            $ownListResponse->getCode(),
            '租户 1 列表接口应返回 200，实际响应：' . $ownListResponse->getContent()
        );

        $ownListBody = json_decode($ownListResponse->getContent(), true);
        $this->assertIsArray($ownListBody);
        $this->assertSame(0, $ownListBody['code']);
        $ownIds = array_column($ownListBody['data']['list'], 'id');
        $this->assertContains(
            $definitionId,
            $ownIds,
            '基线失败：同租户列表看不到自己创建的定义，说明写入链路本身有问题'
        );

        // 步骤 2：租户 2 列表不得包含租户 1 的定义
        $crossListResponse = $this->httpRequest(
            'GET',
            'v1/workflow/definitions',
            [],
            $this->authHeaders($this->tenant2UserId, 2)
        );
        $this->assertSame(
            200,
            $crossListResponse->getCode(),
            '租户 2 列表接口应返回 200，实际响应：' . $crossListResponse->getContent()
        );

        $crossListBody = json_decode($crossListResponse->getContent(), true);
        $this->assertIsArray($crossListBody);
        $crossIds = array_column($crossListBody['data']['list'], 'id');
        $this->assertNotContains(
            $definitionId,
            $crossIds,
            '租户隔离缺陷：租户 2 的列表接口读到了租户 1 的工作流定义 ' . $definitionId
        );

        // 步骤 3：租户 2 按 ID 读取必须被拒绝（不能是 200）
        $crossReadResponse = $this->httpRequest(
            'GET',
            'v1/workflow/definitions/' . $definitionId,
            [],
            $this->authHeaders($this->tenant2UserId, 2)
        );
        $this->assertNotSame(
            200,
            $crossReadResponse->getCode(),
            '租户隔离缺陷：租户 2 成功读取了租户 1 的工作流定义，响应：' . $crossReadResponse->getContent()
        );
        $this->assertSame(
            403,
            $crossReadResponse->getCode(),
            '跨租户读取应以 403 拒绝，实际响应：' . $crossReadResponse->getContent()
        );

        $crossReadBody = json_decode($crossReadResponse->getContent(), true);
        $this->assertIsArray($crossReadBody);
        // 业务码 403 来自控制器 forbidden()，用于区分权限中间件的 2002
        $this->assertSame(
            403,
            $crossReadBody['code'],
            '跨租户读取的业务码应为 403（控制器访问拒绝），实际响应：' . $crossReadResponse->getContent()
        );
    }

    /**
     * 用例 2：写入的 tenant_id 来自 JWT 租户上下文（不为 null）
     */
    public function testCreatedDefinitionStoresTenantIdFromToken(): void
    {
        $definitionId = $this->createDefinitionAsTenant1();

        // 同租户读取详情，校验读取链路正常且 tenant_id 正确落库
        $readResponse = $this->httpRequest(
            'GET',
            'v1/workflow/definitions/' . $definitionId,
            [],
            $this->authHeaders($this->tenant1UserId, 1)
        );
        $this->assertSame(
            200,
            $readResponse->getCode(),
            '同租户读取详情应返回 200，实际响应：' . $readResponse->getContent()
        );

        $body = json_decode($readResponse->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code']);
        $this->assertArrayHasKey('tenant_id', $body['data']);
        $this->assertSame(
            1,
            $body['data']['tenant_id'],
            '租户 1 创建的定义，其 tenant_id 应为 1（当前为 null 说明租户上下文未生效）'
        );
    }

    /**
     * 以租户 1 身份创建一条工作流定义并返回其 ID
     */
    private function createDefinitionAsTenant1(): string
    {
        $response = $this->httpRequest(
            'POST',
            'v1/workflow/definitions',
            [
                'name' => 'tenant_isolation_wf_' . uniqid(),
                'display_name' => 'Tenant Isolation Workflow',
                'description' => 'C-7 tenant isolation test',
                'type' => 'approval',
            ],
            $this->authHeaders($this->tenant1UserId, 1)
        );

        $this->assertSame(
            201,
            $response->getCode(),
            '租户 1 创建定义应返回 201，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('id', $body['data']);

        $definitionId = (string) $body['data']['id'];
        $this->assertNotSame('', $definitionId);

        return $definitionId;
    }

    /**
     * 构造携带 JWT 的请求头（租户上下文来自 token 中的 tenant_id）
     */
    private function authHeaders(int $userId, int $tenantId): array
    {
        return [
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                userId: $userId,
                tenantId: $tenantId,
                siteId: 0
            ),
            'Accept' => 'application/json',
        ];
    }

    /**
     * 简易 HTTP 调用辅助（与既有 Feature 测试保持一致）
     */
    private function httpRequest(string $method, string $uri, array $data = [], array $headers = [])
    {
        $request = $this->app()->make(\think\Request::class);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        if ($method === 'GET') {
            $request->withGet($data);
        } elseif ($method === 'POST') {
            $request->withPost($data);
            $request->withInput(json_encode($data));
        }

        $request = $request->withHeader($headers);

        return $this->runHttp($request);
    }

    /**
     * 清空工作流控制器的进程内静态存储
     */
    private function resetInMemoryStores(): void
    {
        $stores = [
            \app\controller\workflow\WorkflowDefinitionController::class => ['definitions'],
            \app\controller\workflow\WorkflowInstanceController::class => ['instances', 'history'],
            \app\controller\workflow\WorkflowTaskController::class => ['tasks', 'taskHistory'],
            \app\controller\workflow\WorkflowTriggerController::class => ['triggers', 'triggerLogs'],
        ];

        foreach ($stores as $class => $properties) {
            foreach ($properties as $property) {
                if (!property_exists($class, $property)) {
                    continue;
                }

                $ref = new \ReflectionProperty($class, $property);
                $ref->setAccessible(true);
                $ref->setValue(null, []);
            }
        }
    }

    /**
     * 授予测试用户工作流定义读写权限（走真实 RBAC 校验链路）
     *
     * 清理与幂等性由 WorkflowFixtureIsolation 负责：按「ID 区间 OR 自然键」清理，
     * 可清掉其他测试类留下的同 slug 行（避免撞 permissions.idx_slug 唯一索引）。
     */
    private function seedPermissions(): void
    {
        $this->seedWorkflowFixtures(function (): void {
            $now = date('Y-m-d H:i:s');

            // 清理上一次异常中断留下的残留数据

            // 权限改为「按 slug 确保存在」（R51）：seeder 可能已预建同 slug 行（id 1024..1033），
            // 此时复用其 id；未预建才按本用例的固定 id 插入。
            // 说明：PermissionService 以 `resource:action` 拼接权限码，
            // 因此 resource=workflow_definition + action=read 才能匹配中间件的 workflow_definition.read
            $this->readPermissionId = $this->ensureWorkflowPermission(
                'workflow_definition',
                'read',
                $this->readPermissionId,
                'C-7 workflow tenant isolation test permission'
            );
            $this->writePermissionId = $this->ensureWorkflowPermission(
                'workflow_definition',
                'write',
                $this->writePermissionId,
                'C-7 workflow tenant isolation test permission'
            );

            // 租户 1 / 租户 2 各一个用户与角色
            Db::table('users')->insertAll([
                [
                    'id' => $this->tenant1UserId,
                    'tenant_id' => 1,
                    'username' => 'workflow_iso_tenant1',
                    'email' => 'workflow_iso_tenant1@workflow-iso.test',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $this->tenant2UserId,
                    'tenant_id' => 2,
                    'username' => 'workflow_iso_tenant2',
                    'email' => 'workflow_iso_tenant2@workflow-iso.test',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            Db::table('roles')->insertAll([
                [
                    'id' => $this->tenant1RoleId,
                    'tenant_id' => 1,
                    'name' => 'workflow_iso_role_1',
                    'slug' => 'workflow_iso_role_1',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $this->tenant2RoleId,
                    'tenant_id' => 2,
                    'name' => 'workflow_iso_role_2',
                    'slug' => 'workflow_iso_role_2',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // 用户 → 角色
            Db::table('user_roles')->insertAll([
                ['user_id' => $this->tenant1UserId, 'role_id' => $this->tenant1RoleId, 'created_at' => $now],
                ['user_id' => $this->tenant2UserId, 'role_id' => $this->tenant2RoleId, 'created_at' => $now],
            ]);

            // 角色 → 权限
            Db::table('role_permissions')->insertAll([
                ['role_id' => $this->tenant1RoleId, 'permission_id' => $this->readPermissionId, 'created_at' => $now],
                ['role_id' => $this->tenant1RoleId, 'permission_id' => $this->writePermissionId, 'created_at' => $now],
                ['role_id' => $this->tenant2RoleId, 'permission_id' => $this->readPermissionId, 'created_at' => $now],
                ['role_id' => $this->tenant2RoleId, 'permission_id' => $this->writePermissionId, 'created_at' => $now],
            ]);
        });
    }

    /**
     * 清理工作流测试夹具（含其他测试类留下的同 slug 残留）
     */
    private function cleanupPermissions(): void
    {
        $this->purgeWorkflowFixtures();
    }

    /**
     * 清除测试用户的权限缓存（L1/L2）
     */
    private function clearPermissionCache(): void
    {
        $permissionService = new PermissionService();
        $permissionService->clearUserCache($this->tenant1UserId);
        $permissionService->clearUserCache($this->tenant2UserId);
    }
}
