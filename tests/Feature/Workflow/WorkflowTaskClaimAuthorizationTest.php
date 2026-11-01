<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use app\controller\workflow\WorkflowTaskController;
use Infrastructure\Permission\Service\PermissionService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流任务认领 / 审批授权测试（缺陷 R1）
 *
 * 背景（两处缺陷，同一根因链）：
 *   1. `$request->userGroups` 是属性访问，而 `app\Request` 既无该属性也无同名方法，
 *      于是走 `think\Request::__get()` → `middleware('userGroups')` → 恒为 null，
 *      导致「按角色组匹配候选任务」的判断永远失效；
 *   2. `claim()` 的守卫要求「候选用户与候选组同时非空」才拦截（`&&`），
 *      使「只设 candidate_users」或「只设 candidate_groups」的任务变成 fail-open（任何人可认领）；
 *      `checkTaskAccess()` 里算出的 `$canAccess` 从未被使用，未认领任务同样 fail-open。
 *
 * 本测试通过真实 HTTP 路由 + 真实 JWT 认证链路 + 真实 RBAC 权限播种验证：
 *   - 仅 candidate_users 且当前用户不在其中 → claim 403；
 *   - 仅 candidate_groups 且角色不匹配 → claim 403；
 *   - 仅 candidate_groups 且角色匹配 → claim 200（防止修过头）；
 *   - 开放任务（无任何候选约束）→ claim 200（回归保护）；
 *   - 仅 candidate_groups 且角色不匹配 → approve 403（覆盖 checkTaskAccess）；
 *   - `app\Request::userGroups()` 契约：返回当前用户在当前租户下的角色 slug，无角色返回 []。
 *
 * 注意：
 *   - 工作流任务存放在控制器进程内 `protected static array $tasks`，本测试用反射直接播种，
 *     并在每个用例前后清空，避免用例间互相污染；
 *   - 工作流任务路由挂载了 `Permission` 中间件（workflow_task.read / write），
 *     本测试按项目既有做法在数据库内授予测试用户相应权限，走真实权限校验链路，
 *     不绕过中间件。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowTaskClaimAuthorizationTest extends ThinkPHPTestCase
{
    use \Tests\Traits\WorkflowFixtureIsolation;

    /**
     * 测试用户 ID
     *
     * 选用 6xxx 区间（< 7000）的固定 ID：
     *   - 避开既有种子数据（roles 1~11）与既有测试数据；
     *   - 避开其他测试用 `WHERE id >= 7000 / 8000 / 9000 / 10000` 的批量清理，
     *     否则并行/交叉运行时会话中途被删，导致外键插入失败与结论误判。
     *
     * - plainUser：拥有角色 wf_claim_non_member（与候选组不匹配）
     * - groupUser：拥有角色 wf_claim_abc_test（与候选组匹配）
     * - noRoleUser：无任何角色（用于 userGroups() 契约测试）
     */
    private int $plainUserId = 6101;
    private int $groupUserId = 6102;
    private int $noRoleUserId = 6103;

    /**
     * 测试角色 ID
     */
    private int $plainRoleId = 6201;
    private int $groupRoleId = 6202;

    /**
     * 工作流任务权限 ID（读 / 写）
     */
    private int $readPermissionId = 6301;
    private int $writePermissionId = 6302;

    /**
     * 候选组（角色 slug）名称
     */
    private string $groupSlug = 'wf_claim_abc_test';
    private string $nonMemberGroupSlug = 'wf_claim_non_member';

    protected function setUp(): void
    {
        parent::setUp();

        // 绑定自定义 Request，确保 userId()/tenantId()/userGroups() 等多租户方法可用
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 加载工作流路由（含 Auth + Permission 中间件）
        $routeFile = $this->app()->getRootPath() . 'route/workflow.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 复位鉴权中间件实例：其他测试类可能把 Auth / Permission 绑定为 no-op，
        // 容器会缓存首次解析结果，必须显式覆盖为真实实现。
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        // 清空路由/控制器中间件队列（中间件队列在同进程内跨请求累积）
        $this->resetRouteMiddlewareQueue();

        // 清空控制器进程内静态存储，避免用例间互相污染
        $this->resetInMemoryStores();

        // 授予测试用户工作流任务读写权限
        $this->seedAuthorizationFixtures();

        // 清除权限缓存，避免历史运行残留的空权限集合导致误判
        $this->clearPermissionCache();
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->resetInMemoryStores();
        $this->cleanupAuthorizationFixtures();

        parent::tearDown();
    }

    /**
     * 用例 1（RED）：仅 candidate_users 且当前用户不在其中 → claim 必须 403
     *
     * 现状缺陷：`claim()` 的守卫用 `!empty($candidateUsers) && !empty($candidateGroups)` 判断，
     * 候选组为空时直接跳过校验，任何登录用户都能认领。
     */
    public function testClaimDeniedWhenOnlyCandidateUsersAndUserNotListed(): void
    {
        $taskId = 'task_claim_users_' . uniqid();
        // 任务只设候选用户，且不含当前登录用户
        $this->seedTask($taskId, [$this->groupUserId], []);

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/claim',
            [],
            $this->authHeaders($this->plainUserId)
        );

        $this->assertSame(
            403,
            $response->getCode(),
            '仅设 candidate_users 且用户不在候选中的任务，claim 应返回 403（fail-open 缺陷），实际响应：'
            . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(
            403,
            $body['code'],
            'claim 拒绝的业务码应为 403（控制器 forbidden），实际响应：' . $response->getContent()
        );
    }

    /**
     * 用例 2（RED）：仅 candidate_groups 且用户角色不匹配 → claim 必须 403
     *
     * 现状缺陷：候选用户为空时守卫同样被跳过；且 `$request->userGroups` 恒为 null，
     * 即使只看组匹配也永远命中不了。
     */
    public function testClaimDeniedWhenOnlyCandidateGroupsAndRoleMismatch(): void
    {
        $taskId = 'task_claim_groups_' . uniqid();
        // 任务只设候选组（当前用户角色为 non_member，不匹配）
        $this->seedTask($taskId, [], [$this->groupSlug]);

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/claim',
            [],
            $this->authHeaders($this->plainUserId)
        );

        $this->assertSame(
            403,
            $response->getCode(),
            '仅设 candidate_groups 且角色不匹配的任务，claim 应返回 403（fail-open 缺陷），实际响应：'
            . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(
            403,
            $body['code'],
            'claim 拒绝的业务码应为 403（控制器 forbidden），实际响应：' . $response->getContent()
        );

        // 额外断言：任务必须保持未认领状态，不能被越权改写
        $this->assertSame(
            null,
            $this->readTask($taskId)['assignee'],
            '越权 claim 被拒绝后，任务 assignee 不应被改写'
        );
    }

    /**
     * 用例 3：仅 candidate_groups 且用户角色匹配 → claim 必须 200
     *
     * 防止修复过度（把合法候选也拦掉）。
     */
    public function testClaimAllowedWhenCandidateGroupMatchesUserRole(): void
    {
        $taskId = 'task_claim_match_' . uniqid();
        // 任务候选组包含当前用户角色 slug
        $this->seedTask($taskId, [], [$this->groupSlug]);

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/claim',
            [],
            $this->authHeaders($this->groupUserId)
        );

        $this->assertSame(
            200,
            $response->getCode(),
            '候选组命中用户角色的任务，claim 应返回 200，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code'], 'claim 成功的业务码应为 0，实际响应：' . $response->getContent());
        $this->assertSame(
            $this->groupUserId,
            $body['data']['assignee'],
            'claim 成功后 assignee 应为当前用户'
        );
        $this->assertSame(
            WorkflowTaskController::STATUS_CLAIMED,
            $body['data']['status'],
            'claim 成功后任务状态应为 claimed'
        );
    }

    /**
     * 用例 4：开放任务（无 candidate_users / candidate_groups）→ claim 必须 200
     *
     * 回归保护：确保守卫收紧后不会把合法开放任务也拦掉。
     */
    public function testClaimAllowedForOpenTaskWithoutCandidacy(): void
    {
        $taskId = 'task_claim_open_' . uniqid();
        $this->seedTask($taskId, [], []);

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/claim',
            [],
            $this->authHeaders($this->plainUserId)
        );

        $this->assertSame(
            200,
            $response->getCode(),
            '无任何候选约束的开放任务，claim 应返回 200，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code'], '开放任务 claim 的业务码应为 0，实际响应：' . $response->getContent());
    }

    /**
     * 用例 5（RED）：未认领且仅设 candidate_groups、角色不匹配 → approve 必须 403
     *
     * 现状缺陷：`checkTaskAccess()` 计算出的 `$canAccess` 是死代码，
     * 无 assignee 时直接放行，等同任何人可审批。
     */
    public function testApproveDeniedWhenUnassignedTaskCandidateGroupMismatch(): void
    {
        $taskId = 'task_approve_groups_' . uniqid();
        // 未认领（assignee=null）+ 仅候选组，当前用户角色不匹配
        $this->seedTask($taskId, [], [$this->groupSlug]);

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/approve',
            ['comment' => '越权审批尝试'],
            $this->authHeaders($this->plainUserId)
        );

        $this->assertSame(
            403,
            $response->getCode(),
            '未认领且候选组不匹配的任务，approve 应返回 403（checkTaskAccess fail-open），实际响应：'
            . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(
            403,
            $body['code'],
            'approve 拒绝的业务码应为 403（控制器 forbidden），实际响应：' . $response->getContent()
        );

        // 任务状态不得被越权改写
        $this->assertSame(
            WorkflowTaskController::STATUS_PENDING,
            $this->readTask($taskId)['status'],
            '越权 approve 被拒绝后，任务状态应仍为 pending'
        );
    }

    /**
     * 用例 6（RED）：`app\Request::userGroups()` 契约
     *
     * 期望：返回当前用户在当前租户下的角色 slug 列表（本项目「用户组」映射约定）；
     * 无角色 / 未认证时返回空数组。
     */
    public function testUserGroupsResolvesRoleSlugsOfCurrentTenantUser(): void
    {
        // 有角色用户：应返回其角色 slug
        $request = $this->app()->make(\think\Request::class, [], true);
        $this->assertInstanceOf(\app\Request::class, $request);
        $request->setUserId($this->groupUserId);

        $groups = $request->userGroups();
        $this->assertIsArray($groups);
        $this->assertContains(
            $this->groupSlug,
            $groups,
            'userGroups() 应包含当前用户的角色 slug，实际返回：' . json_encode($groups, JSON_UNESCAPED_UNICODE)
        );
        $this->assertNotContains(
            $this->nonMemberGroupSlug,
            $groups,
            'userGroups() 不应包含未被授予的角色 slug，实际返回：' . json_encode($groups, JSON_UNESCAPED_UNICODE)
        );

        // 实例缓存：同一请求重复调用结果一致（且不应抛异常）
        $this->assertSame($groups, $request->userGroups(), '同一请求内 userGroups() 结果应保持一致');

        // 无角色用户：返回空数组
        $noRoleRequest = $this->app()->make(\think\Request::class, [], true);
        $noRoleRequest->setUserId($this->noRoleUserId);
        $this->assertSame([], $noRoleRequest->userGroups(), '无角色用户的 userGroups() 应为空数组');

        // 未认证（userId 为空）：返回空数组
        $anonymousRequest = $this->app()->make(\think\Request::class, [], true);
        $this->assertSame([], $anonymousRequest->userGroups(), '未认证请求的 userGroups() 应为空数组');

        // setUserGroups()：允许中间件/测试一次性预置用户组，避免重复查库
        $injectedRequest = $this->app()->make(\think\Request::class, [], true);
        $injectedRequest->setUserGroups(['injected_group_a', 'injected_group_b']);
        $this->assertSame(
            ['injected_group_a', 'injected_group_b'],
            $injectedRequest->userGroups(),
            'setUserGroups() 预置后 userGroups() 应直接返回预置值'
        );
    }

    /**
     * 用例 7：已分配任务 —— 候选身份不能越过受理人，但受理人本人必须放行
     *
     * 覆盖 checkTaskAccess() 的第 1 条语义（有 assignee → 仅 assignee/delegate），
     * 防止把「候选用户」误当成「可越过受理人」的通行证；同时校准返回值，
     * 确认收紧后受理人仍然可以正常审批（不修过头）。
     */
    public function testAssignedTaskRejectsCandidateUserButAllowsAssignee(): void
    {
        // 情形 A：任务是候选用户之一，但已被别人受理 → 必须 403
        $taskIdA = 'task_assigned_reject_' . uniqid();
        $this->seedTask($taskIdA, [$this->plainUserId], [], $this->groupUserId);

        $rejected = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskIdA . '/approve',
            ['comment' => '候选用户尝试越过受理人审批'],
            $this->authHeaders($this->plainUserId)
        );

        $this->assertSame(
            403,
            $rejected->getCode(),
            '任务已有受理人时，候选用户不应能审批，实际响应：' . $rejected->getContent()
        );

        // 情形 B：当前用户就是受理人 → 必须 200
        $taskIdB = 'task_assigned_allow_' . uniqid();
        $this->seedTask($taskIdB, [$this->groupUserId], [], $this->plainUserId);

        $allowed = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskIdB . '/approve',
            ['comment' => '受理人正常审批'],
            $this->authHeaders($this->plainUserId)
        );

        $this->assertSame(
            200,
            $allowed->getCode(),
            '受理人本人应可审批自己的任务，实际响应：' . $allowed->getContent()
        );

        $body = json_decode($allowed->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(
            WorkflowTaskController::STATUS_COMPLETED,
            $body['data']['status'],
            '受理人审批后任务状态应为 completed'
        );
    }

    /**
     * 播种一条工作流任务到控制器进程内静态存储
     *
     * @param string   $taskId         任务 ID
     * @param string[] $candidateUsers 候选用户 ID 列表
     * @param string[] $candidateGroups 候选组（角色 slug）列表
     * @param int|null $assignee       当前受理人
     */
    private function seedTask(
        string $taskId,
        array $candidateUsers = [],
        array $candidateGroups = [],
        ?int $assignee = null
    ): void {
        $now = (new \DateTimeImmutable())->format('c');

        $task = [
            'id' => $taskId,
            'tenant_id' => 1,
            'instance_id' => 'inst_' . $taskId,
            'definition_id' => 'def_' . $taskId,
            'name' => 'R1 授权测试任务',
            'type' => WorkflowTaskController::TYPE_APPROVAL,
            'status' => WorkflowTaskController::STATUS_PENDING,
            'assignee' => $assignee,
            'delegated_to' => null,
            'candidate_users' => $candidateUsers,
            'candidate_groups' => $candidateGroups,
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
     * 读取控制器进程内静态存储中的任务（用于断言越权操作未改写数据）
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
     * 构造携带 JWT 的请求头（租户上下文来自 token 中的 tenant_id）
     */
    private function authHeaders(int $userId): array
    {
        return [
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                userId: $userId,
                tenantId: 1,
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
        // 第三个参数 true（newInstance）：容器把最近一次 HTTP 请求实例缓存在
        // instances['app\Request'] 下，必须强制新建，否则不同用例会复用同一请求对象，
        // 使 app\Request::userGroups() 的实例级缓存跨用例串味。
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
     * 播种授权测试所需数据（用户 / 角色 / 权限 / 关联）
     *
     * 清理与幂等性由 WorkflowFixtureIsolation 负责：按「ID 区间 OR 自然键」清理，
     * 可清掉其他测试类用不同 ID 写入的同 slug 行；播种抛异常时也会自行清理。
     */
    private function seedAuthorizationFixtures(): void
    {
        $this->seedWorkflowFixtures(function (): void {
            $now = date('Y-m-d H:i:s');

            // 权限改为「按 slug 确保存在」（R51）：seeder 可能已预建同 slug 行（id 1024..1033），
            // 此时复用其 id；未预建才按本用例的固定 id 插入。
            // 说明：PermissionService 以 `resource:action` 拼接权限码，
            // 因此 resource=workflow_task + action=read 才能匹配中间件的 workflow_task.read
            $this->readPermissionId = $this->ensureWorkflowPermission(
                'workflow_task',
                'read',
                $this->readPermissionId,
                'R1 workflow task claim authorization test permission'
            );
            $this->writePermissionId = $this->ensureWorkflowPermission(
                'workflow_task',
                'write',
                $this->writePermissionId,
                'R1 workflow task claim authorization test permission'
            );

            // 用户
            Db::table('users')->insertAll([
                [
                    'id' => $this->plainUserId,
                    'tenant_id' => 1,
                    'username' => 'wf_claim_plain_user',
                    'email' => 'wf_claim_plain_user@workflow-claim.test',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $this->groupUserId,
                    'tenant_id' => 1,
                    'username' => 'wf_claim_group_user',
                    'email' => 'wf_claim_group_user@workflow-claim.test',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $this->noRoleUserId,
                    'tenant_id' => 1,
                    'username' => 'wf_claim_no_role_user',
                    'email' => 'wf_claim_no_role_user@workflow-claim.test',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // 角色：一个与候选组不匹配，一个与候选组匹配
            Db::table('roles')->insertAll([
                [
                    'id' => $this->plainRoleId,
                    'tenant_id' => 1,
                    'name' => $this->nonMemberGroupSlug,
                    'slug' => $this->nonMemberGroupSlug,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $this->groupRoleId,
                    'tenant_id' => 1,
                    'name' => $this->groupSlug,
                    'slug' => $this->groupSlug,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // 用户 → 角色（noRoleUser 不分配角色）
            Db::table('user_roles')->insertAll([
                ['user_id' => $this->plainUserId, 'role_id' => $this->plainRoleId, 'created_at' => $now],
                ['user_id' => $this->groupUserId, 'role_id' => $this->groupRoleId, 'created_at' => $now],
            ]);

            // 角色 → 权限（两个角色都授予任务读写，确保 403 来自控制器授权判断而非权限中间件）
            Db::table('role_permissions')->insertAll([
                ['role_id' => $this->plainRoleId, 'permission_id' => $this->readPermissionId, 'created_at' => $now],
                ['role_id' => $this->plainRoleId, 'permission_id' => $this->writePermissionId, 'created_at' => $now],
                ['role_id' => $this->groupRoleId, 'permission_id' => $this->readPermissionId, 'created_at' => $now],
                ['role_id' => $this->groupRoleId, 'permission_id' => $this->writePermissionId, 'created_at' => $now],
            ]);
        });
    }

    /**
     * 清理工作流测试夹具（含其他测试类留下的同 slug 残留）
     */
    private function cleanupAuthorizationFixtures(): void
    {
        $this->purgeWorkflowFixtures();
    }

    /**
     * 清除测试用户的权限缓存（L1/L2）
     */
    private function clearPermissionCache(): void
    {
        $permissionService = new PermissionService();
        $permissionService->clearUserCache($this->plainUserId);
        $permissionService->clearUserCache($this->groupUserId);
        $permissionService->clearUserCache($this->noRoleUserId);
    }
}
