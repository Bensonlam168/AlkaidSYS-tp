<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Alkaid\Workflow\Engine\Definition\WorkflowBuilder;
use app\controller\workflow\WorkflowDefinitionController;
use app\controller\workflow\WorkflowTaskController;
use Infrastructure\Permission\Service\PermissionService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流「租户严格匹配」测试（缺陷 P3-A）
 *
 * 背景：
 *   `WorkflowTaskController::index()` / `WorkflowDefinitionController::index()` /
 *   `WorkflowInstanceController::index()` / `WorkflowTriggerController::index()`
 *   四处列表过滤写成 `X === $tenantId || X === null`，即「租户为 null 的记录对所有租户可见」。
 *   但 DB 层 `tenant_id` 列全部是 NOT NULL，null 永不成立 → 该分支是死代码，
 *   却会让「缺少 tenant_id 的脏数据」对所有租户泄漏（fail-open），
 *   并误导读者以为系统支持「全局记录」。
 *
 * 本测试通过真实 HTTP 路由 + 真实 JWT/RBAC 链路验证：
 *   1. 跨租户按 ID 读取必须 403，同租户必须 200（正常路径不被收紧破坏）；
 *   2. 列表不再把「无租户归属」的记录泄漏给任何租户；
 *   3. 同租户记录仍然可见（防止修过头）。
 *
 * 注意：
 *   - 工作流数据存放在控制器进程内 `protected static array`，本测试用反射播种，
 *     并在每个用例前后清空，避免用例间互相污染；
 *   - 「tenant_id 为 null 的记录」在 DB 中不可能存在（列 NOT NULL），
 *     这里刻意播种是为了把「已删除的 null 宽免分支」钉成回归断言：
 *     将来若有人重新引入 `|| X === null`，本测试会立刻变红。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowTenantStrictnessTest extends ThinkPHPTestCase
{
    use \Tests\Traits\WorkflowFixtureIsolation;

    /**
     * 测试用户 ID（租户 1 / 租户 2）
     *
     * 选用 66xx 段，避开既有用例占用的 6xxx 子段与以高位 ID 批量清理的测试。
     */
    private int $tenant1UserId = 6651;
    private int $tenant2UserId = 6652;

    /**
     * 测试角色 ID（租户 1 / 租户 2）
     */
    private int $tenant1RoleId = 6641;
    private int $tenant2RoleId = 6642;

    /**
     * 工作流权限 ID（任务读 / 定义读）
     */
    private int $taskReadPermissionId = 6645;
    private int $definitionReadPermissionId = 6646;

    protected function setUp(): void
    {
        parent::setUp();

        // 绑定自定义 Request，确保 tenantId()/userId() 等多租户方法可用
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 加载工作流路由（含 Auth + Permission 中间件）
        $routeFile = $this->app()->getRootPath() . 'route/workflow.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 复位鉴权中间件实例：其它测试类可能把 Auth / Permission 绑定为 no-op，
        // 容器会缓存首次解析结果，必须显式覆盖为真实实现
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        // 清空路由/控制器中间件队列（中间件队列在同进程内跨请求累积）
        $this->resetRouteMiddlewareQueue();

        // 清空控制器进程内静态存储，避免用例间互相污染
        $this->resetInMemoryStores();

        $this->seedFixtures();
        $this->clearPermissionCache();
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->resetInMemoryStores();
        $this->cleanupFixtures();
        $this->clearPermissionCache();

        parent::tearDown();
    }

    /**
     * 用例 1：跨租户按 ID 读取任务必须 403，同租户必须 200
     *
     * 这是「正常路径」基线：证明租户收紧后同租户读写不受影响。
     */
    public function testCrossTenantTaskDetailIsDeniedWhileOwnTenantAllowed(): void
    {
        $taskId = 'task_strict_detail_' . uniqid();
        $this->seedTask($taskId, 1);

        // 租户 2 读取租户 1 的任务 → 必须被拒绝
        $crossResponse = $this->httpRequest(
            'GET',
            'v1/workflow/tasks/' . $taskId,
            [],
            $this->authHeaders($this->tenant2UserId, 2)
        );

        $this->assertSame(
            403,
            $crossResponse->getCode(),
            '跨租户读取任务应以 403 拒绝，实际响应：' . $crossResponse->getContent()
        );

        $crossBody = json_decode($crossResponse->getContent(), true);
        $this->assertIsArray($crossBody);
        // 业务码 403 来自控制器 forbidden()，区别于权限中间件的 2002
        $this->assertSame(403, $crossBody['code'], '跨租户读取的业务码应为 403，实际响应：' . $crossResponse->getContent());

        // 租户 1 读取自己的任务 → 必须 200（正常路径不能被收紧破坏）
        $ownResponse = $this->httpRequest(
            'GET',
            'v1/workflow/tasks/' . $taskId,
            [],
            $this->authHeaders($this->tenant1UserId, 1)
        );

        $this->assertSame(
            200,
            $ownResponse->getCode(),
            '同租户读取任务应返回 200（移除 null 宽免不应影响正常路径），实际响应：' . $ownResponse->getContent()
        );

        $ownBody = json_decode($ownResponse->getContent(), true);
        $this->assertIsArray($ownBody);
        $this->assertSame(0, $ownBody['code'], '同租户读取的业务码应为 0');
        $this->assertSame($taskId, $ownBody['data']['id'], '返回的任务应是请求的那条');
    }

    /**
     * 用例 2（RED）：任务列表不得把「无租户归属」的记录泄漏给任何租户
     *
     * 现状缺陷：`($task['tenant_id'] ?? null) === $tenantId || ... === null`
     * 使 tenant_id 缺失/为 null 的任务同时出现在**两个**租户的列表里。
     */
    public function testTaskListDoesNotLeakRecordWithoutTenantId(): void
    {
        $ownTaskId = 'task_strict_own_' . uniqid();
        $orphanTaskId = 'task_strict_orphan_' . uniqid();

        // 一条正常归属租户 1 的任务 + 一条没有任何租户归属的任务（DB 中不可能存在，仅用于钉住死分支）
        $this->seedTask($ownTaskId, 1);
        $this->seedTask($orphanTaskId, null);

        $tenant1Ids = $this->listTaskIds($this->tenant1UserId, 1);
        $tenant2Ids = $this->listTaskIds($this->tenant2UserId, 2);

        $this->assertContains($ownTaskId, $tenant1Ids, '租户 1 的列表应包含自己租户的任务（正常路径）');
        $this->assertNotContains($ownTaskId, $tenant2Ids, '租户 2 的列表不得包含租户 1 的任务');
        $this->assertNotContains(
            $orphanTaskId,
            $tenant1Ids,
            '缺少 tenant_id 的记录（tenant_id NOT NULL，DB 中不可能存在）不得被视为「全局任务」对租户 1 可见'
        );
        $this->assertNotContains(
            $orphanTaskId,
            $tenant2Ids,
            '缺少 tenant_id 的记录不得对租户 2 可见（原 null 宽免分支会把它泄漏给所有租户）'
        );
    }

    /**
     * 用例 3（RED）：定义列表同样不得泄漏「无租户归属」的定义
     *
     * 该分支用的是对象方法（`$def->getTenantId() === null`），与数组键写法不同，单独覆盖。
     */
    public function testDefinitionListDoesNotLeakDefinitionWithoutTenantId(): void
    {
        $ownDefinitionId = $this->seedDefinition(1);
        $orphanDefinitionId = $this->seedDefinition(null);

        $tenant1Ids = $this->listDefinitionIds($this->tenant1UserId, 1);

        $this->assertContains($ownDefinitionId, $tenant1Ids, '租户 1 的列表应包含自己租户的定义（正常路径）');
        $this->assertNotContains(
            $orphanDefinitionId,
            $tenant1Ids,
            'tenant_id 为 null 的定义不得被视为「全局定义」对租户 1 可见（原 null 宽免分支）'
        );
    }

    /**
     * 读取任务列表中的 ID 集合
     *
     * @return string[]
     */
    private function listTaskIds(int $userId, int $tenantId): array
    {
        $response = $this->httpRequest('GET', 'v1/workflow/tasks', [], $this->authHeaders($userId, $tenantId));

        $this->assertSame(
            200,
            $response->getCode(),
            '任务列表接口应返回 200，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code'], '任务列表的业务码应为 0，实际响应：' . $response->getContent());

        return array_column($body['data']['list'], 'id');
    }

    /**
     * 读取定义列表中的 ID 集合
     *
     * @return string[]
     */
    private function listDefinitionIds(int $userId, int $tenantId): array
    {
        $response = $this->httpRequest('GET', 'v1/workflow/definitions', [], $this->authHeaders($userId, $tenantId));

        $this->assertSame(
            200,
            $response->getCode(),
            '定义列表接口应返回 200，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code'], '定义列表的业务码应为 0，实际响应：' . $response->getContent());

        return array_column($body['data']['list'], 'id');
    }

    /**
     * 播种一条工作流任务（tenant_id 允许为 null，用于钉住已删除的 null 宽免分支）
     */
    private function seedTask(string $taskId, ?int $tenantId): void
    {
        $now = (new \DateTimeImmutable())->format('c');

        $task = [
            'id' => $taskId,
            'tenant_id' => $tenantId,
            'instance_id' => 'inst_' . $taskId,
            'definition_id' => 'def_' . $taskId,
            'name' => 'P3-A 租户严格匹配测试任务',
            'type' => WorkflowTaskController::TYPE_APPROVAL,
            'status' => WorkflowTaskController::STATUS_PENDING,
            'assignee' => null,
            'delegated_to' => null,
            'candidate_users' => [],
            'candidate_groups' => [],
            'form_data' => [],
            'due_date' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $ref = new \ReflectionProperty(WorkflowTaskController::class, 'tasks');
        $ref->setAccessible(true);
        $tasks = (array) $ref->getValue();
        $tasks[$taskId] = $task;
        $ref->setValue(null, $tasks);
    }

    /**
     * 播种一条工作流定义，返回其 ID
     */
    private function seedDefinition(?int $tenantId): string
    {
        $builder = WorkflowBuilder::create('strict_wf_' . uniqid(), ['tenant_id' => $tenantId]);
        $definition = $builder->getDefinition();

        $ref = new \ReflectionProperty(WorkflowDefinitionController::class, 'definitions');
        $ref->setAccessible(true);

        $definitions = (array) $ref->getValue();
        $definitions[$definition->getId()] = $definition;
        $ref->setValue(null, $definitions);

        return $definition->getId();
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
        $request = $this->app()->make(\think\Request::class, [], true);
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
     * 清空工作流控制器的进程内静态存储
     */
    private function resetInMemoryStores(): void
    {
        $stores = [
            WorkflowDefinitionController::class => ['definitions'],
            \app\controller\workflow\WorkflowInstanceController::class => ['instances', 'history'],
            WorkflowTaskController::class => ['tasks', 'taskHistory'],
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
     * 播种测试夹具（用户 / 角色 / 权限 / 关联）
     *
     * 清理与幂等性由 WorkflowFixtureIsolation 负责：按「ID 区间 OR 自然键」清理，
     * 可清掉其他测试类用不同 ID 写入的同 slug 行；播种抛异常时也会自行清理。
     */
    private function seedFixtures(): void
    {
        $this->seedWorkflowFixtures(function (): void {
            $now = date('Y-m-d H:i:s');

            // 权限：PermissionService 以 `resource:action` 拼接权限码
            Db::table('permissions')->insertAll([
                [
                    'id' => $this->taskReadPermissionId,
                    'name' => 'workflow.task.read',
                    'slug' => 'workflow.task.read',
                    'resource' => 'workflow',
                    'action' => 'task:read',
                    'description' => 'P3-A workflow tenant strictness test permission',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $this->definitionReadPermissionId,
                    'name' => 'workflow.definition.read',
                    'slug' => 'workflow.definition.read',
                    'resource' => 'workflow',
                    'action' => 'definition:read',
                    'description' => 'P3-A workflow tenant strictness test permission',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // 两个租户各一个用户
            Db::table('users')->insertAll([
                [
                    'id' => $this->tenant1UserId,
                    'tenant_id' => 1,
                    'username' => 'wf_strict_tenant1',
                    'email' => 'wf_strict_tenant1@workflow-strict.test',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $this->tenant2UserId,
                    'tenant_id' => 2,
                    'username' => 'wf_strict_tenant2',
                    'email' => 'wf_strict_tenant2@workflow-strict.test',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            Db::table('roles')->insertAll([
                [
                    'id' => $this->tenant1RoleId,
                    'tenant_id' => 1,
                    'name' => 'wf_strict_role_1',
                    'slug' => 'wf_strict_role_1',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $this->tenant2RoleId,
                    'tenant_id' => 2,
                    'name' => 'wf_strict_role_2',
                    'slug' => 'wf_strict_role_2',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            Db::table('user_roles')->insertAll([
                ['user_id' => $this->tenant1UserId, 'role_id' => $this->tenant1RoleId, 'created_at' => $now],
                ['user_id' => $this->tenant2UserId, 'role_id' => $this->tenant2RoleId, 'created_at' => $now],
            ]);

            // 两个角色都授予任务读 / 定义读，确保 403 来自控制器租户判断而非权限中间件
            Db::table('role_permissions')->insertAll([
                ['role_id' => $this->tenant1RoleId, 'permission_id' => $this->taskReadPermissionId, 'created_at' => $now],
                ['role_id' => $this->tenant1RoleId, 'permission_id' => $this->definitionReadPermissionId, 'created_at' => $now],
                ['role_id' => $this->tenant2RoleId, 'permission_id' => $this->taskReadPermissionId, 'created_at' => $now],
                ['role_id' => $this->tenant2RoleId, 'permission_id' => $this->definitionReadPermissionId, 'created_at' => $now],
            ]);
        });
    }

    /**
     * 清理工作流测试夹具（含其他测试类留下的同 slug 残留）
     */
    private function cleanupFixtures(): void
    {
        $this->purgeWorkflowFixtures();
    }

    /**
     * 清除测试用户的权限缓存（L1/L2）
     */
    private function clearPermissionCache(): void
    {
        $permissionService = new PermissionService();

        foreach ([$this->tenant1UserId, $this->tenant2UserId] as $userId) {
            $permissionService->clearUserCache($userId);
        }
    }
}
