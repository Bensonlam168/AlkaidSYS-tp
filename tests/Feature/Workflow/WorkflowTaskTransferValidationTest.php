<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use app\controller\workflow\WorkflowTaskController;
use Infrastructure\Permission\Service\PermissionService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流任务「转办 / 委托」入参校验测试（缺陷 P2）
 *
 * 背景：
 *   `transfer()` 把 `$data['target_user']` 原样写入 `$task['assignee']`，
 *   `delegate()` 把 `$data['delegate_to']` 原样写入 `$task['delegated_to']`，
 *   两者**没有任何校验**。而 DB 层：
 *     - `workflow_tasks.assignee` 为 int unsigned 且外键指向 `users.id`；
 *     - 设计口径（47 号文档）同样声明 assignee 是「用户引用」。
 *   于是传非数字会 1366、传不存在的用户会 1452，把「用户输入错误」伪装成 500，
 *   属于典型的「把校验缺失暴露成系统故障」。
 *
 * 本测试通过真实 HTTP 路由 + 真实 JWT/RBAC 链路验证：
 *   - 目标用户不存在 → 422，且 assignee / delegated_to **未被改写**、未写历史；
 *   - 目标用户 ID 非纯数字 → 422，且未被改写；
 *   - 目标用户属于其它租户 → 422（防止跨租户转办）；
 *   - 目标用户是本租户真实用户 → 200 且正确改写（防止修过头）。
 *
 * 注意：
 *   - 工作流任务存放在控制器进程内 `protected static array $tasks`，本测试用反射播种，
 *     并在每个用例前后清空，避免用例间互相污染；
 *   - 路由挂载了 `Permission` 中间件（workflow_task.write），按项目既有做法在数据库内
 *     授予测试用户相应权限，走真实权限链路，不绕过中间件。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowTaskTransferValidationTest extends ThinkPHPTestCase
{
    use \Tests\Traits\WorkflowFixtureIsolation;

    /**
     * 测试用户 ID
     *
     * 选用 66xx 段（6xxx 内的新空段）：6xxx 的其它子段已被既有用例占用
     * （6101-6103、6201-6202、6301-6302、6401/6411/6421、6501/6511/6521），
     * 且需避开以 id >= 7000 做批量清理的测试，避免会话中途被删。
     *
     * - actorUserId：当前登录用户（任务受理人，执行转办/委托的人）
     * - validTargetUserId：本租户（tenant_id=1）内真实存在的目标用户
     * - otherTenantUserId：另一个租户（tenant_id=2）的用户，用于验证租户限定
     */
    private int $actorUserId = 6601;
    private int $validTargetUserId = 6602;
    private int $otherTenantUserId = 6603;

    /**
     * 测试角色 ID
     */
    private int $roleId = 6621;

    /**
     * 工作流任务权限 ID（读 / 写）
     */
    private int $readPermissionId = 6631;
    private int $writePermissionId = 6632;

    protected function setUp(): void
    {
        parent::setUp();

        // 绑定自定义 Request，确保 userId()/tenantId() 等多租户方法可用
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
     * 用例 A（RED）：target_user 指向不存在的用户 → 必须 422，且 assignee 不得被改写
     *
     * 现状缺陷：无任何校验，assignee 会被写成 6699（DB 落库时是 1452 外键错误）。
     */
    public function testTransferRejectsNonExistentTargetUser(): void
    {
        $taskId = 'task_transfer_missing_' . uniqid();
        $this->seedTask($taskId);

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/transfer',
            ['target_user' => 6699, 'comment' => '目标用户不存在'],
            $this->authHeaders($this->actorUserId, 1)
        );

        $this->assertSame(
            422,
            $response->getCode(),
            '目标用户不存在时应返回 422 业务错误（而非 200 静默写入 / 500 数据库错误），实际响应：'
            . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(422, $body['code'], '业务码应为 422，实际响应：' . $response->getContent());
        $this->assertArrayHasKey('target_user', $body['data']['errors'] ?? [], '错误信息应定位到 target_user 字段');

        $task = $this->readTask($taskId);
        $this->assertSame(
            $this->actorUserId,
            $task['assignee'],
            '校验失败时不得改写 assignee（原实现会把不存在的用户 ID 写进去）'
        );
        $this->assertSame(
            WorkflowTaskController::STATUS_PENDING,
            $task['status'],
            '校验失败时任务状态不得变化'
        );
        $this->assertSame([], $this->readHistory($taskId), '校验失败时不得写入转办历史');
    }

    /**
     * 用例 B（RED）：target_user 非纯数字 → 必须 422，且 assignee 不得被改写
     *
     * 现状缺陷：'user_name' 通过了 `empty()` 检查并被直接写入 assignee。
     */
    public function testTransferRejectsNonNumericTargetUser(): void
    {
        $taskId = 'task_transfer_string_' . uniqid();
        $this->seedTask($taskId);

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/transfer',
            ['target_user' => 'user_name', 'comment' => '目标用户不是数字'],
            $this->authHeaders($this->actorUserId, 1)
        );

        $this->assertSame(
            422,
            $response->getCode(),
            'target_user 非纯数字时应返回 422，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(422, $body['code'], '业务码应为 422，实际响应：' . $response->getContent());

        $task = $this->readTask($taskId);
        $this->assertSame(
            $this->actorUserId,
            $task['assignee'],
            '校验失败时不得把非数字字符串写入 assignee（原实现会写成 "user_name"）'
        );
        $this->assertSame([], $this->readHistory($taskId), '校验失败时不得写入转办历史');
    }

    /**
     * 用例 C（RED）：target_user 属于其它租户 → 必须 422
     *
     * 校验必须限定在当前租户，否则可以把任务转办给别的租户的用户（跨租户数据泄漏）。
     */
    public function testTransferRejectsTargetUserOfAnotherTenant(): void
    {
        $taskId = 'task_transfer_cross_tenant_' . uniqid();
        $this->seedTask($taskId);

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/transfer',
            ['target_user' => $this->otherTenantUserId, 'comment' => '目标用户属于其它租户'],
            $this->authHeaders($this->actorUserId, 1)
        );

        $this->assertSame(
            422,
            $response->getCode(),
            '目标用户属于其它租户时应返回 422，实际响应：' . $response->getContent()
        );

        $this->assertSame(
            $this->actorUserId,
            $this->readTask($taskId)['assignee'],
            '跨租户转办被拒绝后 assignee 不得被改写'
        );
    }

    /**
     * 用例 D（防修过头）：target_user 是本租户真实用户 → 必须 200 且正确改写
     */
    public function testTransferSucceedsForExistingTenantUser(): void
    {
        $taskId = 'task_transfer_ok_' . uniqid();
        $this->seedTask($taskId);

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/transfer',
            ['target_user' => $this->validTargetUserId, 'comment' => '正常转办'],
            $this->authHeaders($this->actorUserId, 1)
        );

        $this->assertSame(
            200,
            $response->getCode(),
            '合法转办应返回 200，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code'], '合法转办的业务码应为 0，实际响应：' . $response->getContent());

        $task = $this->readTask($taskId);
        $this->assertSame(
            $this->validTargetUserId,
            $task['assignee'],
            '转办成功后 assignee 应为目标用户（且为 int，与 DB 列 int unsigned 保持一致）'
        );
        $this->assertSame(
            WorkflowTaskController::STATUS_PENDING,
            $task['status'],
            '转办后任务应回到 pending 等待新受理人处理'
        );

        $history = $this->readHistory($taskId);
        $this->assertCount(1, $history, '合法转办应写入一条历史');
        $this->assertSame('TRANSFERRED', $history[0]['action']);
    }

    /**
     * 用例 E（RED）：delegate_to 指向不存在的用户 → 必须 422，且 delegated_to 不得被改写
     *
     * 说明：`delegate()` 的入参字段是 `delegate_to`，落到任务的 `delegated_to` 字段，
     * 与 `transfer()` 的 `target_user` → `assignee` 不同，因此单独覆盖。
     */
    public function testDelegateRejectsNonExistentTargetUser(): void
    {
        $taskId = 'task_delegate_missing_' . uniqid();
        $this->seedTask($taskId);

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/delegate',
            ['delegate_to' => 6698, 'comment' => '委托人不存在'],
            $this->authHeaders($this->actorUserId, 1)
        );

        $this->assertSame(
            422,
            $response->getCode(),
            '委托给不存在的用户时应返回 422，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(422, $body['code'], '业务码应为 422，实际响应：' . $response->getContent());
        $this->assertArrayHasKey('delegate_to', $body['data']['errors'] ?? [], '错误信息应定位到 delegate_to 字段');

        $task = $this->readTask($taskId);
        $this->assertNull($task['delegated_to'], '校验失败时不得改写 delegated_to');
        $this->assertSame([], $this->readHistory($taskId), '校验失败时不得写入委托历史');
    }

    /**
     * 用例 F（防修过头）：delegate_to 是本租户真实用户 → 必须 200 且正确改写
     */
    public function testDelegateSucceedsForExistingTenantUser(): void
    {
        $taskId = 'task_delegate_ok_' . uniqid();
        $this->seedTask($taskId);

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/delegate',
            ['delegate_to' => $this->validTargetUserId, 'comment' => '正常委托'],
            $this->authHeaders($this->actorUserId, 1)
        );

        $this->assertSame(
            200,
            $response->getCode(),
            '合法委托应返回 200，实际响应：' . $response->getContent()
        );

        $task = $this->readTask($taskId);
        $this->assertSame($this->validTargetUserId, $task['delegated_to'], '委托成功后 delegated_to 应为目标用户');
        $this->assertSame($this->actorUserId, $task['assignee'], '委托不改写原受理人');

        $history = $this->readHistory($taskId);
        $this->assertCount(1, $history, '合法委托应写入一条历史');
        $this->assertSame('DELEGATED', $history[0]['action']);
    }

    /**
     * 播种一条「已由当前用户受理」的工作流任务（租户 1，状态 pending）
     */
    private function seedTask(string $taskId, int $tenantId = 1): void
    {
        $now = (new \DateTimeImmutable())->format('c');

        $task = [
            'id' => $taskId,
            'tenant_id' => $tenantId,
            'instance_id' => 'inst_' . $taskId,
            'definition_id' => 'def_' . $taskId,
            'name' => 'P2 转办入参校验测试任务',
            'type' => WorkflowTaskController::TYPE_APPROVAL,
            'status' => WorkflowTaskController::STATUS_PENDING,
            'assignee' => $this->actorUserId,
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
     * 读取控制器进程内静态存储中的任务（用于断言非法请求未改写数据）
     */
    private function readTask(string $taskId): array
    {
        $ref = new \ReflectionProperty(WorkflowTaskController::class, 'tasks');
        $ref->setAccessible(true);
        $tasks = (array) $ref->getValue();

        $this->assertArrayHasKey($taskId, $tasks, '任务应存在于控制器静态存储中');

        return $tasks[$taskId];
    }

    /**
     * 读取任务的进程内历史（用于断言校验失败时不写历史）
     */
    private function readHistory(string $taskId): array
    {
        $ref = new \ReflectionProperty(WorkflowTaskController::class, 'taskHistory');
        $ref->setAccessible(true);
        $history = (array) $ref->getValue();

        return $history[$taskId] ?? [];
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
        // 第三个参数 true（新建实例）：容器会缓存最近一次 HTTP 请求实例，
        // 必须强制新建，否则不同用例会复用同一请求对象
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
     * 清空工作流任务控制器的进程内静态存储
     */
    private function resetInMemoryStores(): void
    {
        foreach (['tasks', 'taskHistory'] as $property) {
            if (!property_exists(WorkflowTaskController::class, $property)) {
                continue;
            }

            $ref = new \ReflectionProperty(WorkflowTaskController::class, $property);
            $ref->setAccessible(true);
            $ref->setValue(null, []);
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

            // 权限：PermissionService 以 `resource:action` 拼接权限码，
            // 因此 resource=workflow_task + action=write 才能匹配中间件的 workflow_task.write
            Db::table('permissions')->insertAll([
                [
                    'id' => $this->readPermissionId,
                    'name' => 'workflow_task.read',
                    'slug' => 'workflow_task.read',
                    'resource' => 'workflow_task',
                    'action' => 'read',
                    'description' => 'P2 workflow task transfer validation test permission',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $this->writePermissionId,
                    'name' => 'workflow_task.write',
                    'slug' => 'workflow_task.write',
                    'resource' => 'workflow_task',
                    'action' => 'write',
                    'description' => 'P2 workflow task transfer validation test permission',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // 用户：同租户两个（操作用户 + 合法目标用户），另一个租户一个（跨租户目标用户）
            Db::table('users')->insertAll([
                [
                    'id' => $this->actorUserId,
                    'tenant_id' => 1,
                    'username' => 'wf_transfer_actor',
                    'email' => 'wf_transfer_actor@workflow-transfer.test',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $this->validTargetUserId,
                    'tenant_id' => 1,
                    'username' => 'wf_transfer_target',
                    'email' => 'wf_transfer_target@workflow-transfer.test',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $this->otherTenantUserId,
                    'tenant_id' => 2,
                    'username' => 'wf_transfer_other_tenant',
                    'email' => 'wf_transfer_other_tenant@workflow-transfer.test',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // 角色：操作用户需要 workflow_task.write 权限
            Db::table('roles')->insert([
                'id' => $this->roleId,
                'tenant_id' => 1,
                'name' => 'wf_transfer_role',
                'slug' => 'wf_transfer_role',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('user_roles')->insert([
                'user_id' => $this->actorUserId,
                'role_id' => $this->roleId,
                'created_at' => $now,
            ]);

            Db::table('role_permissions')->insertAll([
                ['role_id' => $this->roleId, 'permission_id' => $this->readPermissionId, 'created_at' => $now],
                ['role_id' => $this->roleId, 'permission_id' => $this->writePermissionId, 'created_at' => $now],
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

        foreach ([$this->actorUserId, $this->validTargetUserId, $this->otherTenantUserId] as $userId) {
            $permissionService->clearUserCache($userId);
        }
    }
}
