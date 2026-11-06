<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Infrastructure\Permission\Service\PermissionService;
use Infrastructure\Workflow\Repository\WorkflowTaskRepository;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流任务「转办 / 委托」入参校验测试（缺陷 P2）
 *
 * 背景（缺陷原文）：
 *   `transfer()` 把 `$data['target_user']` 原样写入任务的受理人，
 *   `delegate()` 把 `$data['delegate_to']` 原样写入委派人，
 *   两者**没有任何校验**。而 DB 层：
 *     - `workflow_tasks.assignee` 为 int unsigned 且外键指向 `users.id`；
 *     - 设计口径（47 号文档）同样声明 assignee 是「用户引用」。
 *   于是传非数字会 1366、传不存在的用户会 1452，把「用户输入错误」伪装成 500，
 *   属于典型的「把校验缺失暴露成系统故障」。
 *
 * 本测试通过真实 HTTP 路由 + 真实 JWT/RBAC 链路 + **真实数据库**验证：
 *   - 目标用户不存在 → 422，且 assignee **未被改写**、未写事件；
 *   - 目标用户 ID 非纯数字 → 422，且未被改写；
 *   - 目标用户属于其它租户 → 422（防止跨租户转办）；
 *   - 目标用户是本租户真实用户 → 200 且正确改写（防止修过头）。
 *
 * ## 相对 Mock 版（改前）的差异与理由
 *
 * 1. 夹具从「反射塞进控制器静态数组」改为真库（定义 → 实例 → 任务行，
 *    见 Tests\Traits\WorkflowExecutableFixture）。Mock 里改一个字段就能「通过」，
 *    只有真实数据 + 真实外键才能证明这些校验真的挡住了坏数据。
 * 2. 「历史」从控制器的进程内 `$taskHistory` 改为 `workflow_event_logs` 表，
 *    事件名取真值 `task_transferred` / `task_delegated`（旧 Mock 是大写 `TRANSFERRED` / `DELEGATED`）。
 * 3. 「delegated_to 未被改写」改为「assignee / candidate_users 未被改写 + 无事件」：
 *    `workflow_tasks` **没有** `delegated_to` 列（见交付报告「契约冲突」），
 *    委派在本仓库的落库形态是「assignee 改成被委派人 + 原受理人保留在候选集」。
 * 4. 转办成功后任务状态从 `pending` 改为 `claimed`：这不是放宽断言 ——
 *    旧 Mock 里 `pending` 是「待处理」的笼统状态；引擎口径中**有受理人即 claimed**，
 *    所以这里同时断言「状态仍在 DOING_STATUSES 里」（即新受理人确实能办），比原来的字面量更强。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowTaskTransferValidationTest extends ThinkPHPTestCase
{
    use \Tests\Traits\WorkflowFixtureIsolation;
    use \Tests\Traits\WorkflowExecutableFixture;

    /**
     * 真库夹具定义的名称前缀（清理时按前缀删除）
     */
    private const DEFINITION_PREFIX = 'p2_transfer_validation_';

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

        // 先清掉上一轮可能残留的真库夹具（定义/实例/任务/事件）
        $this->purgeWorkflowFixturesByNamePrefix(self::DEFINITION_PREFIX);

        $this->seedFixtures();
        $this->clearPermissionCache();
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->purgeWorkflowFixturesByNamePrefix(self::DEFINITION_PREFIX);
        $this->cleanupFixtures();
        $this->clearPermissionCache();

        parent::tearDown();
    }

    /**
     * 用例 A：target_user 指向不存在的用户 → 必须 422，且 assignee 不得被改写
     *
     * 缺陷口径：无校验时 assignee 会被写成 6699（落库时是 1452 外键错误 → 500）。
     */
    public function testTransferRejectsNonExistentTargetUser(): void
    {
        $taskId = $this->seedTask();

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
            (int) $task['assignee'],
            '校验失败时不得改写 assignee（原实现会把不存在的用户 ID 写进去）'
        );
        $this->assertSame(
            'claimed',
            $task['status'],
            '校验失败时任务状态不得变化'
        );
        $this->assertSame([], $this->readHistory($taskId), '校验失败时不得写入转办事件');
    }

    /**
     * 用例 B：target_user 非纯数字 → 必须 422，且 assignee 不得被改写
     *
     * 缺陷口径：'user_name' 通过了 `empty()` 检查并被直接写入 assignee（DB 侧 1366）。
     */
    public function testTransferRejectsNonNumericTargetUser(): void
    {
        $taskId = $this->seedTask();

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
            (int) $task['assignee'],
            '校验失败时不得把非数字字符串写入 assignee（原实现会写成 "user_name"）'
        );
        $this->assertSame([], $this->readHistory($taskId), '校验失败时不得写入转办事件');
    }

    /**
     * 用例 C：target_user 属于其它租户 → 必须 422
     *
     * 校验必须限定在当前租户，否则可以把任务转办给别的租户的用户（跨租户数据泄漏）。
     */
    public function testTransferRejectsTargetUserOfAnotherTenant(): void
    {
        $taskId = $this->seedTask();

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
            (int) $this->readTask($taskId)['assignee'],
            '跨租户转办被拒绝后 assignee 不得被改写'
        );
        $this->assertSame([], $this->readHistory($taskId), '跨租户转办被拒绝后不得写入事件');
    }

    /**
     * 用例 D（防修过头）：target_user 是本租户真实用户 → 必须 200 且正确改写
     */
    public function testTransferSucceedsForExistingTenantUser(): void
    {
        $taskId = $this->seedTask();

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
            (int) $task['assignee'],
            '转办成功后 assignee 应为目标用户（且为 int，与 DB 列 int unsigned 保持一致）'
        );
        $this->assertSame(
            'claimed',
            $task['status'],
            '转办后任务应仍处于在办状态（引擎口径：有受理人即 claimed）'
        );
        $this->assertContains(
            $task['status'],
            WorkflowTaskRepository::DOING_STATUSES,
            '转办后任务必须仍可被新受理人办理（状态不能掉出在办集合）'
        );

        $candidates = json_decode((string) $task['candidate_users'], true);
        $this->assertIsArray($candidates);
        $this->assertContains(
            (string) $this->validTargetUserId,
            array_map('strval', $candidates),
            '转办后目标用户应进入候选集'
        );
        $this->assertNotContains(
            (string) $this->actorUserId,
            array_map('strval', $candidates),
            '转办是彻底交出：原受理人应退出候选集'
        );

        $history = $this->readHistory($taskId);
        $this->assertCount(1, $history, '合法转办应写入一条事件');
        $this->assertSame('task_transferred', $history[0]['event_type']);
        $this->assertSame($this->actorUserId, (int) $history[0]['user_id'], '事件操作人应为原受理人');
    }

    /**
     * 用例 E：delegate_to 指向不存在的用户 → 必须 422，且任务数据不得被改写
     *
     * 说明：`delegate()` 的入参字段是 `delegate_to`，与 `transfer()` 的 `target_user` 不同，
     * 因此单独覆盖字段级错误定位。
     */
    public function testDelegateRejectsNonExistentTargetUser(): void
    {
        $taskId = $this->seedTask();

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
        $this->assertSame($this->actorUserId, (int) $task['assignee'], '校验失败时不得改写受理人');
        $this->assertSame([], $this->readHistory($taskId), '校验失败时不得写入委托事件');
    }

    /**
     * 用例 F（防修过头）：delegate_to 是本租户真实用户 → 必须 200 且正确改写
     */
    public function testDelegateSucceedsForExistingTenantUser(): void
    {
        $taskId = $this->seedTask();

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
        $this->assertSame(
            $this->validTargetUserId,
            (int) $task['assignee'],
            '委托成功后受理人应为被委托人（表里没有 delegated_to 列，委派落在 assignee 上）'
        );

        $candidates = array_map('strval', (array) json_decode((string) $task['candidate_users'], true));
        $this->assertContains(
            (string) $this->actorUserId,
            $candidates,
            '委托是找人代办：原受理人应保留在候选集（与「转办」的关键区别）'
        );

        $history = $this->readHistory($taskId);
        $this->assertCount(1, $history, '合法委托应写入一条事件');
        $this->assertSame('task_delegated', $history[0]['event_type']);
    }

    // =====================================================================
    // 夹具
    // =====================================================================

    /**
     * 播种一条「已由当前用户受理」的工作流任务（租户 1，状态 claimed）
     *
     * 真库形态：可执行定义 → 运行中实例 → 任务行（受理人 = actorUserId）。
     * 任务状态取 `claimed`（有受理人）而不是 `pending`，与引擎建任务的落库口径一致。
     *
     * @return int 任务主键
     */
    private function seedTask(int $tenantId = 1): int
    {
        $definitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . uniqid(), $tenantId);
        $instanceId   = $this->seedWorkflowInstanceRow($tenantId, $definitionId, $this->actorUserId);

        return $this->seedWorkflowTaskRow($tenantId, $instanceId, [
            'assignee' => $this->actorUserId,
            'status'   => 'claimed',
        ]);
    }

    /**
     * 读取任务行（用于断言非法请求未改写数据）
     *
     * @return array<string, mixed>
     */
    private function readTask(int $taskId): array
    {
        $row = Db::table('workflow_tasks')->where('id', $taskId)->find();

        $this->assertIsArray($row, '任务行应存在于 workflow_tasks 中（id=' . $taskId . '）');

        return $row;
    }

    /**
     * 读取任务的事件日志（用于断言校验失败时不写事件、成功时写对事件）
     *
     * @return array<int, array<string, mixed>>
     */
    private function readHistory(int $taskId): array
    {
        return Db::table('workflow_event_logs')
            ->where('task_id', $taskId)
            ->order('id', 'asc')
            ->select()
            ->toArray();
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
            // 说明：PermissionService 以 `resource:action` 拼接权限码，
            // 因此 resource=workflow_task + action=write 才能匹配中间件的 workflow_task.write
            $this->readPermissionId = $this->ensureWorkflowPermission(
                'workflow_task',
                'read',
                $this->readPermissionId,
                'P2 workflow task transfer validation test permission'
            );
            $this->writePermissionId = $this->ensureWorkflowPermission(
                'workflow_task',
                'write',
                $this->writePermissionId,
                'P2 workflow task transfer validation test permission'
            );

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
