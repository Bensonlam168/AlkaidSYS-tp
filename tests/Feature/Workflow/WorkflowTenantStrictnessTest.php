<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

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
 * 注意（2026：四个工作流控制器全部改真库 + 真引擎后）：
 *   - 工作流数据不再存在控制器进程内的 `protected static array` 里，本测试改为向
 *     `workflow_*` 表插真行，并在每个用例后按名称前缀清理；
 *   - 「tenant_id 为 null 的记录」在真库中**插不进去**（列 NOT NULL），
 *     因此「列表不得泄漏无租户归属记录」这组用例改写为**真实可构造**的对照
 *     「租户 1 的列表里不得出现租户 2 的定义/任务」—— 同样能钉死
 *     `X === $tenantId || X === null` 这类「多了个 null 宽免」的回归（且强度更高：
 *     现在走的是真实 SQL + 真实索引，而不是内存 foreach）。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowTenantStrictnessTest extends ThinkPHPTestCase
{
    use \Tests\Traits\WorkflowFixtureIsolation;

    /**
     * 真库夹具定义的名称前缀（清理时按前缀删除，避免误删真实定义）
     */
    private const STRICT_DEFINITION_PREFIX = 'p3a_tenant_strictness_';

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
        $taskId = $this->seedTask(1);

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
        // 业务码 403 来自服务层的租户归属校验，区别于权限中间件的 2002
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
     * 用例 2：任务列表只能返回本租户的任务（不能把别的租户的任务混进来）
     *
     * 旧用例用「tenant_id 为 null 的孤儿任务」来钉住 `|| === null` 这条死分支。
     * 真库下该数据**不可表示**（`workflow_tasks.tenant_id` NOT NULL + 外键），
     * 因此改用真实可构造的对照：两个租户各一条任务，断言各自的列表里只有自己那条。
     * 若将来有人重新引入 `|| X === null` 或干脆去掉租户过滤，跨租户那条会立刻出现在列表里。
     */
    public function testTaskListDoesNotLeakTaskFromAnotherTenant(): void
    {
        $ownTaskId     = $this->seedTask(1);
        $foreignTaskId = $this->seedTask(2);

        $tenant1Ids = $this->listTaskIds($this->tenant1UserId, 1);
        $tenant2Ids = $this->listTaskIds($this->tenant2UserId, 2);

        $this->assertContains($ownTaskId, $tenant1Ids, '租户 1 的列表应包含自己租户的任务（正常路径）');
        $this->assertNotContains($ownTaskId, $tenant2Ids, '租户 2 的列表不得包含租户 1 的任务');
        $this->assertContains($foreignTaskId, $tenant2Ids, '租户 2 的列表应包含自己租户的任务（正常路径）');
        $this->assertNotContains($foreignTaskId, $tenant1Ids, '租户 1 的列表不得包含租户 2 的任务');
    }

    /**
     * 用例 3：定义列表严格按 tenant_id 过滤，不泄漏其它租户的定义
     *
     * 2026 变更（Mock → 真库）：控制器不再用 `static $definitions` 存对象，
     * 而 `workflow_definitions.tenant_id` 是 `NOT NULL`，旧用例那条
     * 「tenant_id 为 null 的定义不得被视为全局定义」的孤儿行在真库里**插不进去**。
     * 因此改用**真实可构造**的对照：同样是本租户用户，另一个租户定义不得出现在列表里。
     * 语义（列表必须按 tenant_id 严格过滤、不是「全都返回」）保持不变，强度不降反升
     * —— 因为现在用的是真实 SQL 查询路径与真实索引，而不是内存数组的 foreach。
     */
    public function testDefinitionListDoesNotLeakDefinitionFromOtherTenant(): void
    {
        $ownDefinitionId     = $this->seedDefinition(1);
        $foreignDefinitionId = $this->seedDefinition(2);

        $tenant1Ids = $this->listDefinitionIds($this->tenant1UserId, 1);
        $tenant2Ids = $this->listDefinitionIds($this->tenant2UserId, 2);

        $this->assertContains($ownDefinitionId, $tenant1Ids, '租户 1 的列表应包含自己租户的定义（正常路径）');
        $this->assertNotContains(
            $foreignDefinitionId,
            $tenant1Ids,
            '租户 2 的定义不得出现在租户 1 的列表里（列表查询必须带 tenant_id 过滤）'
        );
        $this->assertContains($foreignDefinitionId, $tenant2Ids, '租户 2 的列表应包含自己租户的定义（正常路径）');
        $this->assertNotContains($ownDefinitionId, $tenant2Ids, '租户 1 的定义不得出现在租户 2 的列表里');
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
     * 播种一条工作流任务（**真库**：定义 → 实例 → 任务）
     *
     * 任务的 `instance_id` / `tenant_id` 都有外键，无法凭空插入；
     * 这里按外键顺序造出最小可追溯数据，用例结束后由 `purgeWorkflowBusinessFixtures()` 清理。
     *
     * @param int $tenantId 归属租户
     * @return int 任务主键
     */
    private function seedTask(int $tenantId): int
    {
        $now = date('Y-m-d H:i:s');

        $definitionId = (int) Db::table('workflow_definitions')->insertGetId([
            'tenant_id'    => $tenantId,
            'name'         => self::STRICT_DEFINITION_PREFIX . uniqid(),
            'display_name' => 'P3-A 租户严格匹配测试定义',
            'description'  => 'P3-A 测试夹具（用例结束后删除）',
            'type'         => 'approval',
            'category'     => 'finance',
            'icon'         => 'strict-icon',
            'version'      => 1,
            'status'       => 'draft',
            'is_active'    => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $instanceId = (int) Db::table('workflow_instances')->insertGetId([
            'definition_id' => $definitionId,
            'tenant_id'     => $tenantId,
            'title'         => 'P3-A 租户严格匹配测试实例',
            'status'        => 'running',
            'priority'      => 0,
            'variables'     => json_encode([], JSON_UNESCAPED_UNICODE),
            'context'       => json_encode([], JSON_UNESCAPED_UNICODE),
            'started_at'    => $now,
            'created_by'    => $tenantId === 1 ? $this->tenant1UserId : $this->tenant2UserId,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return (int) Db::table('workflow_tasks')->insertGetId([
            'instance_id'      => $instanceId,
            'tenant_id'        => $tenantId,
            'node_id'          => 'apply',
            'node_name'        => 'apply',
            'task_type'        => 'user_task',
            'task_sub_type'    => 'approval',
            'title'            => 'P3-A 租户严格匹配测试任务',
            'status'           => 'pending',
            'priority'         => 0,
            'candidate_users'  => json_encode([], JSON_UNESCAPED_UNICODE),
            'candidate_groups' => json_encode([], JSON_UNESCAPED_UNICODE),
            'form_data'        => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    /**
     * 播种一条工作流定义（**真库**），返回其主键
     *
     * 2026 变更：控制器改为真库 + 真引擎后，反射塞内存对象不存在了；
     * 直接插一行 draft（`nodes_config` 为最小合法图），由
     * `purgeWorkflowBusinessFixtures()` 按名称前缀统一回收。
     *
     * @param int $tenantId 归属租户
     */
    private function seedDefinition(int $tenantId): int
    {
        $name = self::STRICT_DEFINITION_PREFIX . uniqid();
        $now  = date('Y-m-d H:i:s');

        return (int) Db::table('workflow_definitions')->insertGetId([
            'tenant_id'    => $tenantId,
            'name'         => $name,
            'display_name' => 'P3-A 租户严格匹配测试定义',
            'description'  => 'P3-A 测试夹具（用例结束后删除）',
            'type'         => 'approval',
            'version'      => 1,
            'status'       => 'draft',
            'is_active'    => 0,
            'nodes_config' => json_encode([
                'name'  => $name,
                'type'  => 'approval',
                'nodes' => [
                    ['id' => 'start', 'type' => 'ingenious:start', 'x' => 200, 'y' => 160,
                        'text' => ['value' => '开始'], 'properties' => ['_alkaid_node_class' => 'StartNode', '_alkaid_node_type' => 'start']],
                    ['id' => 'end', 'type' => 'ingenious:end', 'x' => 420, 'y' => 160,
                        'text' => ['value' => '结束'], 'properties' => ['_alkaid_node_class' => 'EndNode', '_alkaid_node_type' => 'end']],
                ],
                'edges' => [
                    ['id' => 'flow_start_end', 'type' => 'polyline', 'sourceNodeId' => 'start',
                        'targetNodeId' => 'end', 'text' => ['value' => ''], 'properties' => ['_alkaid_type' => 'sequence']],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_at'   => $now,
            'updated_at'   => $now,
            'created_by'   => $tenantId === 1 ? $this->tenant1UserId : $this->tenant2UserId,
            'updated_by'   => $tenantId === 1 ? $this->tenant1UserId : $this->tenant2UserId,
        ]);
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
            // 定义与触发器控制器已改真库，不再有这些静态属性（property_exists 会跳过）
            \app\controller\workflow\WorkflowDefinitionController::class => ['definitions'],
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

            // 权限改为「按 slug 确保存在」（R51）：seeder 可能已预建同 slug 行（id 1024..1033），
            // 此时复用其 id；未预建才按本用例的固定 id 插入。
            // 说明：PermissionService 以 `resource:action` 拼接权限码
            $this->taskReadPermissionId = $this->ensureWorkflowPermission(
                'workflow_task',
                'read',
                $this->taskReadPermissionId,
                'P3-A workflow tenant strictness test permission'
            );
            $this->definitionReadPermissionId = $this->ensureWorkflowPermission(
                'workflow_definition',
                'read',
                $this->definitionReadPermissionId,
                'P3-A workflow tenant strictness test permission'
            );

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
        $this->purgeWorkflowBusinessFixtures();
        $this->purgeWorkflowFixtures();
    }

    /**
     * 删除本类播种的业务数据（按定义名前缀圈定；外键顺序：任务 → 实例 → 定义）
     */
    private function purgeWorkflowBusinessFixtures(): void
    {
        $definitionIds = Db::table('workflow_definitions')
            ->whereLike('name', self::STRICT_DEFINITION_PREFIX . '%')
            ->column('id');

        if ($definitionIds === []) {
            return;
        }

        $instanceIds = Db::table('workflow_instances')->whereIn('definition_id', $definitionIds)->column('id');

        if ($instanceIds !== []) {
            Db::table('workflow_tasks')->whereIn('instance_id', $instanceIds)->delete();
            Db::table('workflow_event_logs')->whereIn('instance_id', $instanceIds)->delete();
            Db::table('workflow_instances')->whereIn('id', $instanceIds)->delete();
        }

        Db::table('workflow_definitions')->whereIn('id', $definitionIds)->delete();
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
