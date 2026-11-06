<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Infrastructure\Permission\Service\PermissionCacheService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use Tests\Traits\WorkflowFixtureIsolation;
use think\facade\Db;

/**
 * 工作流实例 / 任务端点「真库 + 真引擎」端到端测试
 *
 * ## 这条测试要证明什么
 *
 * 改造前 `WorkflowInstanceController` / `WorkflowTaskController` 是**内存 Mock**：
 * `protected static array $instances` / `$tasks`，21 个端点都「跑得通」但一个字节都没落库。
 * 因此本测试的断言**必须落在数据库行上**，而不是只看 HTTP 200：
 *
 *   1. HTTP 启动实例 → `workflow_instances` 真的多一行 running、`workflow_tasks` 真的生成第一个任务；
 *   2. 逐节点审批 → 任务 completed、下一节点任务生成、实例最终 completed（**真的调引擎**）；
 *   3. 跨租户读/写实例与任务 → **403**（不是「查不到所以返回空/404」，也不能被权限中间件的 2002 冒充）；
 *   4. 非受理人审批 → 403 且任务行**一个字段都没变**；
 *   5. 伪造 `flow_auto` / 超级管理员身份 → 无效（操作人只取 JWT 里的 userId）。
 *
 * ## 为什么断言 `code === 403` 而不是只看 HTTP 状态
 *
 * 路由上挂着 `Permission` 中间件（`workflow_instance.read` 等），无权限时它返回
 * **HTTP 403 + 业务码 2002**。如果夹具没给用户授权，测试会因为 2002 而「看起来通过」，
 * 却根本没有触达控制器的租户校验。因此本测试既给足了 RBAC 权限（见 `seedFixtures()`），
 * 又断言业务码是 **403**（`WorkflowActionException::forbidden()` 的码），两者合起来才能证明
 * 「拒绝来自租户/授权判断，而不是来自权限中间件」。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowInstanceTaskHttpEndToEndTest extends ThinkPHPTestCase
{
    use WorkflowFixtureIsolation;

    /** 实例与任务所属租户（种子数据里的默认租户） */
    private const TENANT_ID = 1;

    /** 另一个租户（`TestPlatformSeed::ensureSecondTenant()` 建出，本测试只读不建） */
    private const OTHER_TENANT_ID = 2;

    /** 发起人（租户1）：实例 created_by / 第一个任务的受理人 */
    private const STARTER_ID = 6401;

    /** 同租户的第二个用户（租户1）：用于「同一租户内不同用户」的对照 */
    private const PEER_ID = 6402;

    /** 同租户的「局外人」（租户1，有 RBAC 权限但不在任务授权范围） */
    private const OUTSIDER_ID = 6403;

    /** 另一租户的用户（租户2，有等价 RBAC 权限，只应被租户隔离挡住） */
    private const FOREIGN_ID = 6404;

    /** 夹具角色（租户1 / 租户2 各一个，同名 slug 不同租户） */
    private const ROLE_ID = 6401;
    private const OTHER_ROLE_ID = 6402;

    /** 四个权限码对应的固定 id（实际 id 以 ensureWorkflowPermission() 返回值为准） */
    private const PERMISSION_INSTANCE_READ = 6411;
    private const PERMISSION_INSTANCE_WRITE = 6412;
    private const PERMISSION_TASK_READ = 6413;
    private const PERMISSION_TASK_WRITE = 6414;

    /** 测试定义的名称（清理时按名删除） */
    private const DEFINITION_NAME = 'e2e_http_instance_task';

    /** 由本测试插入的权限 id（真实 id，供 role_permissions 使用） */
    private array $permissionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 自定义 Request：userId()/tenantId()/userGroups() 只在 app\Request 上
        $this->app()->bind(\think\Request::class, \app\Request::class);

        $routeFile = $this->app()->getRootPath() . 'route/workflow.php';

        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 复位鉴权中间件：其它测试类可能把它绑定成 no-op，容器会缓存解析结果
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        $this->resetRouteMiddlewareQueue();
        $this->purgeBusinessFixtures();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->purgeBusinessFixtures();
        $this->purgeWorkflowFixtures();

        parent::tearDown();
    }

    // =================================================================
    // 1) 真库端到端：启动 → 审批推进 → 结束
    // =================================================================

    /**
     * HTTP 启动实例 → 生成任务 → 逐节点审批到结束
     *
     * 每一步都读库断言，证明「接口动作 = 数据库状态变化」，而不是内存里改了个字段。
     */
    public function testHttpStartAndApproveUntilInstanceCompleted(): void
    {
        $definitionId = $this->insertExecutableDefinition();

        // ---- 1. 启动实例（POST /v1/workflow/instances）----
        $started = $this->httpRequest('POST', 'v1/workflow/instances', [
            'definition_id' => $definitionId,
            'title'         => 'HTTP 端到端报销单',
            'variables'     => ['amount' => 800, 'applicant_name' => '张三'],
        ], $this->authHeaders(self::STARTER_ID));

        $this->assertSame(201, $started->getCode(), '启动实例应返回 201，实际：' . $started->getContent());

        $startBody = json_decode($started->getContent(), true);
        $this->assertIsArray($startBody);
        $this->assertSame(0, $startBody['code'], '启动成功的业务码应为 0，实际：' . $started->getContent());

        $instanceId = (int) ($startBody['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $instanceId, '响应里应返回真实实例主键（不再是 inst_xxx 字符串）');

        // ---- 2. 实例行真的落库 ----
        $instanceRow = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($instanceRow, 'workflow_instances 必须真的插入实例行');
        $this->assertSame('running', (string) $instanceRow['status'], '刚启动的实例状态应为 running');
        $this->assertSame(self::TENANT_ID, (int) $instanceRow['tenant_id'], '实例必须落在当前租户');
        $this->assertSame($definitionId, (int) $instanceRow['definition_id'], '实例必须指向启动它的定义');
        $this->assertSame(self::STARTER_ID, (int) $instanceRow['created_by'], 'created_by 必须是真实登录用户的ID');
        $this->assertSame('HTTP 端到端报销单', (string) $instanceRow['title'], 'title 应来自请求（引擎 auto_gen_title）');

        // ---- 3. 第一个任务真的生成（apply，受理人=发起人）----
        $firstTask = $this->onlyDoingTask($instanceId);
        $this->assertIsArray($firstTask, '启动后必须生成第一个任务');
        $this->assertSame('apply', (string) $firstTask['node_name'], '第一个任务应落在 apply 节点');
        // 状态是 claimed 而不是 pending：引擎的建任务口径是
        // 「有处理人 = 已指派（claimed）；只有候选人 / 谁都没有 = pending」
        // （见 ProcessTaskService::buildTaskColumns 的 status 分支），
        // 首个任务节点的受理人被引擎设为实例发起人，因此它是 claimed。
        $this->assertSame('claimed', (string) $firstTask['status'], '已指派受理人的任务状态应为 claimed');
        $this->assertSame(self::STARTER_ID, (int) $firstTask['assignee'], '第一个任务的受理人应为发起人');

        // ---- 4. 发起人审批（POST /v1/workflow/tasks/{id}/approve）----
        $approved = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $firstTask['id'] . '/approve',
            ['comment' => '提交，请财务审批'],
            $this->authHeaders(self::STARTER_ID)
        );

        $this->assertSame(200, $approved->getCode(), '受理人审批应成功，实际：' . $approved->getContent());

        $approvedBody = json_decode($approved->getContent(), true);
        $this->assertIsArray($approvedBody);
        $this->assertSame('completed', (string) $approvedBody['data']['status'], '审批后任务状态应为 completed');
        $this->assertSame('提交，请财务审批', (string) $approvedBody['data']['comment'], '审批意见应落 comment 列');
        $this->assertNotEmpty($approvedBody['data']['result'], 'result 应记录本次提交参数');

        // 库里的任务行同样是 completed
        $finishedTask = Db::table('workflow_tasks')->where('id', $firstTask['id'])->find();
        $this->assertIsArray($finishedTask);
        $this->assertSame('completed', (string) $finishedTask['status'], '审批后任务行状态必须落库为 completed');
        $this->assertNotEmpty($finishedTask['completed_at'], 'completed_at 必须写入');

        // ---- 5. 第二个任务生成（review）----
        $secondTask = $this->onlyDoingTask($instanceId);
        $this->assertIsArray($secondTask, '审批后必须生成下一节点任务');
        $this->assertSame('review', (string) $secondTask['node_name'], '应推进到 review 节点');

        // ---- 6. 第二节点审批（开放任务：本租户同事实名操作，操作人被落到 assignee）----
        $final = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $secondTask['id'] . '/approve',
            ['comment' => '同意'],
            $this->authHeaders(self::PEER_ID)
        );

        $this->assertSame(200, $final->getCode(), '开放任务应由本租户用户审批通过，实际：' . $final->getContent());

        // ---- 7. 实例走到终态 ----
        $finalInstance = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($finalInstance);
        $this->assertSame('completed', (string) $finalInstance['status'], '流程走完后实例状态应为 completed');
        $this->assertNotEmpty($finalInstance['completed_at'], '实例 completed_at 必须写入');

        // 引擎把真实操作人写进任务的 assignee（证明「谁办的」来自 JWT，不是客户端声明）
        $reviewRow = Db::table('workflow_tasks')->where('id', $secondTask['id'])->find();
        $this->assertIsArray($reviewRow);
        $this->assertSame(self::PEER_ID, (int) $reviewRow['assignee'], '审批后 assignee 必须是真实操作人');

        // ---- 8. 轨迹来自 workflow_event_logs（不是内存数组）----
        $historyResponse = $this->httpRequest(
            'GET',
            'v1/workflow/instances/' . $instanceId . '/history',
            [],
            $this->authHeaders(self::STARTER_ID)
        );

        $this->assertSame(200, $historyResponse->getCode(), '查询轨迹应成功');
        $historyBody = json_decode($historyResponse->getContent(), true);
        $this->assertIsArray($historyBody);
        $this->assertSame($instanceId, $historyBody['data']['instance_id'], 'history 应带真实实例主键');
        $this->assertSame(
            ['instance_started', 'task_completed', 'task_completed', 'instance_completed'],
            array_column($historyBody['data']['entries'], 'event_type'),
            '轨迹应来自事件表且顺序与流程推进一致，实际：' . $historyResponse->getContent()
        );
        $this->assertSame(4, $historyBody['data']['count'], 'count 应与 entries 数量一致');
    }

    // =================================================================
    // 2) 租户隔离：读 / 写都必须被拒，且不是「返回空」
    // =================================================================

    /**
     * 租户 2 的用户读租户 1 的实例 / 任务 → 403（不是 404、不是空数据）
     */
    public function testForeignTenantCannotReadInstanceOrTask(): void
    {
        [$instanceId, $taskId] = $this->startFixtureInstance();

        $instanceRead = $this->httpRequest(
            'GET',
            'v1/workflow/instances/' . $instanceId,
            [],
            $this->authHeaders(self::FOREIGN_ID)
        );

        $this->assertSame(403, $instanceRead->getCode(), '跨租户读实例必须 403，实际：' . $instanceRead->getContent());
        $body = json_decode($instanceRead->getContent(), true);
        $this->assertSame(403, $body['code'], '跨租户读实例的业务码必须是 403（而非权限中间件的 2002）');
        $this->assertNull($body['data'], '拒绝时不得返回任何实例数据（不能「返回空列表」冒充成功）');

        $taskRead = $this->httpRequest(
            'GET',
            'v1/workflow/tasks/' . $taskId,
            [],
            $this->authHeaders(self::FOREIGN_ID)
        );

        $this->assertSame(403, $taskRead->getCode(), '跨租户读任务必须 403，实际：' . $taskRead->getContent());
        $taskBody = json_decode($taskRead->getContent(), true);
        $this->assertSame(403, $taskBody['code'], '跨租户读任务的业务码必须是 403');
        $this->assertNull($taskBody['data'], '拒绝时不得返回任务数据');

        // 同租户的对照：同一个实例，租户 1 的用户必须读得到（证明上面不是「全都 403」）
        $ownRead = $this->httpRequest(
            'GET',
            'v1/workflow/instances/' . $instanceId,
            [],
            $this->authHeaders(self::STARTER_ID)
        );

        $this->assertSame(200, $ownRead->getCode(), '同租户读自己的实例应成功，实际：' . $ownRead->getContent());
        $ownBody = json_decode($ownRead->getContent(), true);
        $this->assertSame($instanceId, (int) $ownBody['data']['id'], '返回的应是同一条实例');
        $this->assertArrayHasKey('current_tasks', $ownBody['data'], '实例详情应附带当前在办任务');
        $this->assertArrayHasKey('history', $ownBody['data'], '实例详情应附带轨迹');
    }

    /**
     * 租户 2 的用户改租户 1 的实例 / 审批租户 1 的任务 → 403 且数据零变化
     */
    public function testForeignTenantCannotMutateInstanceOrTask(): void
    {
        [$instanceId, $taskId] = $this->startFixtureInstance();

        $beforeInstance = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $beforeTask     = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($beforeInstance);
        $this->assertIsArray($beforeTask);

        // 2.1 取消实例
        $cancel = $this->httpRequest(
            'POST',
            'v1/workflow/instances/' . $instanceId . '/cancel',
            ['reason' => '跨租户越权取消'],
            $this->authHeaders(self::FOREIGN_ID)
        );
        $this->assertSame(403, $cancel->getCode(), '跨租户取消实例必须 403，实际：' . $cancel->getContent());

        // 2.2 挂起实例
        $suspend = $this->httpRequest(
            'POST',
            'v1/workflow/instances/' . $instanceId . '/suspend',
            [],
            $this->authHeaders(self::FOREIGN_ID)
        );
        $this->assertSame(403, $suspend->getCode(), '跨租户挂起实例必须 403，实际：' . $suspend->getContent());

        // 2.3 改变量（PUT）
        $variables = $this->httpRequest(
            'PUT',
            'v1/workflow/instances/' . $instanceId . '/variables',
            ['variables' => ['amount' => 999999]],
            $this->authHeaders(self::FOREIGN_ID)
        );
        $this->assertSame(403, $variables->getCode(), '跨租户改变量必须 403，实际：' . $variables->getContent());

        // 2.4 审批任务
        $approve = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/approve',
            ['comment' => '跨租户越权审批'],
            $this->authHeaders(self::FOREIGN_ID)
        );
        $this->assertSame(403, $approve->getCode(), '跨租户审批任务必须 403，实际：' . $approve->getContent());

        // 数据零变化
        $afterInstance = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $afterTask     = Db::table('workflow_tasks')->where('id', $taskId)->find();

        $this->assertSame($beforeInstance['status'], $afterInstance['status'], '越权操作后实例状态不得变化');
        $this->assertSame($beforeInstance['variables'], $afterInstance['variables'], '越权操作后实例变量不得变化');
        $this->assertSame($beforeTask['status'], $afterTask['status'], '越权操作后任务状态不得变化');
        $this->assertSame($beforeTask['assignee'], $afterTask['assignee'], '越权操作后任务受理人不得变化');
    }

    // =================================================================
    // 3) 越权审批：非受理人 → 403 且任务状态不变
    // =================================================================

    /**
     * 非受理人审批已受理的任务 → 403，任务行与事件表都不产生变化
     */
    public function testNonAssigneeApproveIsRejectedAndLeavesTaskUntouched(): void
    {
        [$instanceId, $taskId] = $this->startFixtureInstance();

        $before = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($before);
        $this->assertSame(self::STARTER_ID, (int) $before['assignee'], '前提：任务应受理给发起人');

        $eventsBefore = Db::table('workflow_event_logs')->where('task_id', $taskId)->count();

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/approve',
            ['comment' => '我不是受理人，但我想批'],
            $this->authHeaders(self::OUTSIDER_ID)
        );

        $this->assertSame(403, $response->getCode(), '非受理人审批必须 403，实际：' . $response->getContent());

        $body = json_decode($response->getContent(), true);
        $this->assertSame(403, $body['code'], '拒绝业务码必须是 403（来自授权判断，不是权限中间件）');

        $after = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($after);
        $this->assertSame($before['status'], $after['status'], '越权审批后任务状态必须保持不变');
        $this->assertSame($before['assignee'], $after['assignee'], '越权审批后任务受理人必须保持不变');
        $this->assertSame($before['completed_at'], $after['completed_at'], '越权审批后不得写入完成时间');
        $this->assertNull($after['comment'], '越权审批的意见不得落库');

        $this->assertSame(
            (int) $eventsBefore,
            (int) Db::table('workflow_event_logs')->where('task_id', $taskId)->count(),
            '越权审批不得产生任何事件'
        );

        // 实例仍停在 apply 节点，没有多出任务
        $this->assertSame(1, Db::table('workflow_tasks')->where('instance_id', $instanceId)->count(), '越权审批不得推进流程');
    }

    // =================================================================
    // 4) 不能以 flow_auto / 超级管理员身份调引擎
    // =================================================================

    /**
     * 伪造操作人身份（请求头 / 请求体）一律无效：操作人只取 JWT 里的 userId
     *
     * 为什么这条是**安全不变量**而不是普通校验：引擎的 `ProcessTaskService::isAllowed()` 对
     * `flow_auto` / `flow.admin` **无条件放行**。只要 HTTP 请求能自称这两个身份，
     * 任何登录用户都能审批任意任务 —— 等于把超级管理员权限发给了所有人。
     *
     * 保证方式（本测试同时验证）：
     *   1. 控制器只调用 `$request->userId()`，它由 `Auth` 中间件从 JWT 的 `user_id` 写入；
     *   2. `WorkflowTenantContext` 中间件**不读** `X-User-ID`（它只看 `Request::userId()`）；
     *   3. 请求体里的 `operator` / `user_id` / `assignee` 等字段根本不参与身份解析。
     */
    public function testFlowAutoIdentityCannotBeForgedThroughHttp(): void
    {
        [$instanceId, $taskId] = $this->startFixtureInstance();

        $forgedHeaders = $this->authHeaders(self::OUTSIDER_ID) + [
            'X-User-ID'      => 'flow_auto',
            'X-Operator'     => 'flow_auto',
            'X-User-Id'      => 'flow_auto',
            'X-Tenant-ID'    => (string) self::OTHER_TENANT_ID,
            'X-Workflow-User' => 'flow.admin',
        ];

        // ---- 4.1 JWT 是「局外人」，同时伪造头说自己是 flow_auto → 仍然 403 ----
        $forgedAttempt = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/approve',
            [
                // 请求体里也塞满伪造身份：这些字段一律不参与身份解析
                'operator'  => 'flow_auto',
                'user_id'   => 'flow_auto',
                'assignee'  => 'flow_auto',
                'tenant_id' => self::OTHER_TENANT_ID,
                'comment'   => '伪造 flow_auto 身份审批',
            ],
            $forgedHeaders
        );

        $this->assertSame(
            403,
            $forgedAttempt->getCode(),
            '伪造 flow_auto 身份必须无效（仍按 JWT 的真实用户判定为无权），实际：' . $forgedAttempt->getContent()
        );

        $taskAfterForgery = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($taskAfterForgery);
        $this->assertSame('claimed', (string) $taskAfterForgery['status'], '伪造身份不得推进任务');
        $this->assertSame(self::STARTER_ID, (int) $taskAfterForgery['assignee'], '伪造身份不得改写受理人');
        $this->assertNull($taskAfterForgery['comment'], '伪造身份的审批意见不得落库');

        // ---- 4.2 JWT 是受理人本人，同时伪造头说自己是 flow_auto → 允许，但落库的是**真实用户ID** ----
        $legit = $this->httpRequest(
            'POST',
            'v1/workflow/tasks/' . $taskId . '/approve',
            [
                'operator' => 'flow_auto',
                'user_id'  => 'flow_auto',
                'comment'  => '受理人本人审批（同时伪造头）',
            ],
            $this->authHeaders(self::STARTER_ID) + ['X-User-ID' => 'flow_auto']
        );

        $this->assertSame(200, $legit->getCode(), '受理人本人审批应成功，实际：' . $legit->getContent());

        $completed = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($completed);
        $this->assertSame('completed', (string) $completed['status'], '受理人审批后任务应完成');
        $this->assertSame(
            self::STARTER_ID,
            (int) $completed['assignee'],
            'assignee 必须是真实登录用户ID —— 若为 0/NULL，说明引擎收到了 flow_auto（非数字身份）'
        );

        // 轨迹里的操作人同样必须是真实用户ID（引擎写 user_id 用的是 operator）
        $completedEvent = Db::table('workflow_event_logs')
            ->where('instance_id', $instanceId)
            ->where('event_type', 'task_completed')
            ->order('id', 'desc')
            ->find();

        $this->assertIsArray($completedEvent, '审批应留下 task_completed 事件');
        $this->assertSame(
            self::STARTER_ID,
            (int) $completedEvent['user_id'],
            '事件 user_id 必须是真实登录用户ID（伪造的 flow_auto 不得进审计）'
        );
    }

    // =================================================================
    // 夹具
    // =================================================================

    /**
     * 启动一条真实实例（发起人 = STARTER_ID），返回 [实例ID, 首个任务ID]
     *
     * @return array{0: int, 1: int}
     */
    private function startFixtureInstance(): array
    {
        $definitionId = $this->insertExecutableDefinition();

        $response = $this->httpRequest('POST', 'v1/workflow/instances', [
            'definition_id' => $definitionId,
            'title'         => '租户隔离验证实例',
            'variables'     => ['amount' => 500],
        ], $this->authHeaders(self::STARTER_ID));

        $this->assertSame(201, $response->getCode(), '夹具实例应启动成功，实际：' . $response->getContent());

        $body = json_decode($response->getContent(), true);
        $instanceId = (int) ($body['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $instanceId, '夹具实例应有真实主键');

        $task = $this->onlyDoingTask($instanceId);
        $this->assertIsArray($task, '夹具实例应生成第一个任务');

        return [$instanceId, (int) $task['id']];
    }

    /**
     * 取实例当前唯一一条在办任务
     *
     * @return array<string, mixed>|null
     */
    private function onlyDoingTask(int $instanceId): ?array
    {
        $rows = Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->whereIn('status', ['pending', 'claimed', 'escalated'])
            ->order('id')
            ->select()
            ->toArray();

        if ($rows === []) {
            return null;
        }

        $this->assertCount(1, $rows, '直线流程同一时刻只应有一条在办任务');

        return $rows[0];
    }

    /**
     * 插入一条**可执行**的定义（LogicFlow `{nodes, edges}`）
     *
     * 引擎只认 `workflow_definitions.nodes_config` 里的 LogicFlow 图；
     * 旧「节点表」形态会被 `ModelParser` 静默解析成空模型（见 WorkflowLogicFlowExecutionEndToEndTest）。
     */
    private function insertExecutableDefinition(): int
    {
        $now = date('Y-m-d H:i:s');

        $graph = [
            'name'         => self::DEFINITION_NAME,
            'display_name' => 'HTTP 端到端测试流程',
            'type'         => 'approval',
            'nodes'        => [
                $this->node('start', 'start', 200, 160, '开始'),
                $this->node('apply', 'task', 420, 160, '填写申请'),
                $this->node('review', 'task', 640, 160, '财务审批'),
                $this->node('end', 'end', 860, 160, '结束'),
            ],
            'edges'        => [
                $this->edge('flow_start_apply', 'start', 'apply'),
                $this->edge('flow_apply_review', 'apply', 'review'),
                $this->edge('flow_review_end', 'review', 'end'),
            ],
        ];

        return (int) Db::table('workflow_definitions')->insertGetId([
            'tenant_id'       => self::TENANT_ID,
            'name'            => self::DEFINITION_NAME,
            'display_name'    => 'HTTP 端到端测试流程',
            'description'     => 'E2E 测试定义（用例结束后删除）',
            'type'            => 'approval',
            'category'        => 'finance',
            'icon'            => 'e2e-icon',
            'version'         => 1,
            'status'          => 'published',
            'nodes_config'    => json_encode($graph, JSON_UNESCAPED_UNICODE),
            'triggers_config' => json_encode([], JSON_UNESCAPED_UNICODE),
            'variables_schema' => json_encode([], JSON_UNESCAPED_UNICODE),
            'is_active'       => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    /**
     * 构造 LogicFlow 节点
     *
     * @return array<string, mixed>
     */
    private function node(string $id, string $parserKey, int $x, int $y, string $displayName): array
    {
        return [
            'id'         => $id,
            'type'       => 'ingenious:' . $parserKey,
            'x'          => $x,
            'y'          => $y,
            'text'       => ['value' => $displayName],
            'properties' => [
                '_alkaid_node_class' => match ($parserKey) {
                    'start' => 'StartNode',
                    'end'   => 'EndNode',
                    default => 'TaskNode',
                },
                '_alkaid_node_type'  => $parserKey,
            ],
        ];
    }

    /**
     * 构造 LogicFlow 边
     *
     * @return array<string, mixed>
     */
    private function edge(string $id, string $source, string $target): array
    {
        return [
            'id'           => $id,
            'type'         => 'polyline',
            'sourceNodeId' => $source,
            'targetNodeId' => $target,
            'text'         => ['value' => ''],
            'properties'   => ['_alkaid_type' => 'sequence'],
        ];
    }

    /**
     * 播种夹具：用户 / 角色 / 权限 / 关联 / 第二租户
     */
    private function seedFixtures(): void
    {
        $this->seedWorkflowFixtures(function (): void {
            $now = date('Y-m-d H:i:s');

            // 权限：按 slug 确保存在（可能由 CorePlatformSeed 预建），调用方必须用返回 id
            $this->permissionIds = [
                $this->ensureWorkflowPermission('workflow_instance', 'read', self::PERMISSION_INSTANCE_READ),
                $this->ensureWorkflowPermission('workflow_instance', 'write', self::PERMISSION_INSTANCE_WRITE),
                $this->ensureWorkflowPermission('workflow_task', 'read', self::PERMISSION_TASK_READ),
                $this->ensureWorkflowPermission('workflow_task', 'write', self::PERMISSION_TASK_WRITE),
            ];

            // 第二租户（TestPlatformSeed 通常会建；未播种时这里补上，避免 Auth 判 tenant_not_found）
            $this->ensureSecondTenant($now);

            Db::table('users')->insertAll([
                $this->userRow(self::STARTER_ID, self::TENANT_ID, 'wf_e2e_starter', $now),
                $this->userRow(self::PEER_ID, self::TENANT_ID, 'wf_e2e_peer', $now),
                $this->userRow(self::OUTSIDER_ID, self::TENANT_ID, 'wf_e2e_outsider', $now),
                $this->userRow(self::FOREIGN_ID, self::OTHER_TENANT_ID, 'wf_e2e_foreign', $now),
            ]);

            Db::table('roles')->insertAll([
                [
                    'id'         => self::ROLE_ID,
                    'tenant_id'  => self::TENANT_ID,
                    'name'       => 'wf_e2e_role',
                    'slug'       => 'wf_e2e_role',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id'         => self::OTHER_ROLE_ID,
                    'tenant_id'  => self::OTHER_TENANT_ID,
                    'name'       => 'wf_e2e_role',
                    'slug'       => 'wf_e2e_role',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            Db::table('user_roles')->insertAll([
                ['user_id' => self::STARTER_ID, 'role_id' => self::ROLE_ID, 'created_at' => $now],
                ['user_id' => self::PEER_ID, 'role_id' => self::ROLE_ID, 'created_at' => $now],
                ['user_id' => self::OUTSIDER_ID, 'role_id' => self::ROLE_ID, 'created_at' => $now],
                ['user_id' => self::FOREIGN_ID, 'role_id' => self::OTHER_ROLE_ID, 'created_at' => $now],
            ]);

            $rolePermissions = [];

            foreach ([self::ROLE_ID, self::OTHER_ROLE_ID] as $roleId) {
                foreach ($this->permissionIds as $permissionId) {
                    $rolePermissions[] = [
                        'role_id'       => $roleId,
                        'permission_id' => $permissionId,
                        'created_at'    => $now,
                    ];
                }
            }

            Db::table('role_permissions')->insertAll($rolePermissions);
        });

        // 权限缓存必须清：其它用例可能缓存过这两个用户的空权限集合
        $cache = new PermissionCacheService();
        foreach ([self::STARTER_ID, self::PEER_ID, self::OUTSIDER_ID] as $userId) {
            $cache->clearUserCache($userId, self::TENANT_ID);
        }
        $cache->clearUserCache(self::FOREIGN_ID, self::OTHER_TENANT_ID);
    }

    /**
     * 用户行（口令用固定的 hash，测试内不做真实登录）
     *
     * @return array<string, mixed>
     */
    private function userRow(int $id, int $tenantId, string $username, string $now): array
    {
        return [
            'id'         => $id,
            'tenant_id'  => $tenantId,
            'username'   => $username,
            'email'      => $username . '@workflow-e2e.test',
            'password'   => password_hash('password', PASSWORD_DEFAULT),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * 确保第二租户存在（Auth 中间件会校验 token 里的 tenant_id 是否可用）
     */
    private function ensureSecondTenant(string $now): void
    {
        $exists = Db::table('tenants')->where('id', self::OTHER_TENANT_ID)->count();

        if ((int) $exists > 0) {
            return;
        }

        Db::table('tenants')->insert([
            'id'         => self::OTHER_TENANT_ID,
            'name'       => 'Test Tenant Two',
            'slug'       => 'test-tenant-2',
            'domain'     => null,
            'status'     => 'active',
            'config'     => json_encode(['locale' => 'zh_CN', 'timezone' => 'Asia/Shanghai']),
            'max_sites'  => 10,
            'max_users'  => 100,
            'expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 清理本测试的业务数据（定义 → 实例 → 任务 → 事件）
     *
     * 顺序按外键：任务/事件 → 实例 → 定义；不依赖数据库是否真的建了 CASCADE。
     */
    private function purgeBusinessFixtures(): void
    {
        $definitionIds = Db::table('workflow_definitions')
            ->where('name', self::DEFINITION_NAME)
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
     * 构造携带 JWT 的请求头（租户上下文来自 token 里的 tenant_id）
     *
     * @return array<string, string>
     */
    private function authHeaders(int $userId, ?int $tenantId = null): array
    {
        $tenantId ??= $userId === self::FOREIGN_ID ? self::OTHER_TENANT_ID : self::TENANT_ID;

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
     * 简易 HTTP 调用（与既有 Feature 测试保持一致）
     */
    private function httpRequest(string $method, string $uri, array $data = [], array $headers = [])
    {
        // 第三个参数 true：容器缓存了上一次请求实例，必须强制新建，
        // 否则 app\Request 的实例级缓存（userGroups）会跨用例串味
        $request = $this->app()->make(\think\Request::class, [], true);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        if ($method !== 'GET') {
            // Content-Type 必须带 json：`Request::put()` 读的是 `withInput()` 解析出来的 `put` 数组，
            // 而解析分支按 contentType 判定 —— 不带 json 时它直接返回 []，
            // 表现是「PUT 请求体整个丢失」（真实客户端一定带 Content-Type，测试必须一致）。
            // ⚠️ 顺序要紧：withHeader 必须在 withInput **之前**，否则解析时 contentType 还是空的。
            $headers['Content-Type'] = 'application/json';
            $request = $request->withHeader($headers);
            $request->withPost($data);
            $request->withInput(json_encode($data));
        } else {
            $request->withGet($data);
            $request = $request->withHeader($headers);
        }

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
}
