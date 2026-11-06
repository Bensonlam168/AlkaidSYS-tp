<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Infrastructure\Permission\Service\PermissionCacheService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use Tests\Traits\WorkflowExecutableFixture;
use Tests\Traits\WorkflowFixtureIsolation;
use think\facade\Db;

/**
 * 工作流实例 / 任务「生命周期类」端点的真库端到端测试
 *
 * ## 为什么单独一份
 *
 * `WorkflowInstanceTaskHttpEndToEndTest` 钉的是**主链路 + 安全不变量**
 * （启动 → 审批到结束、跨租户、越权、伪造身份）。但 21 个端点里还有一批
 * 「不推进流程、只改状态」的动作端点在改成真库之前从未被任何用例执行过：
 *
 * | 端点 | 引擎 API | 风险 |
 * | ---- | -------- | ---- |
 * | `POST instances/{id}/suspend` | 宿主补（引擎 `pending()` 未实现） | 挂起若只是改个标记，任务仍能被审批 → 挂起形同虚设 |
 * | `POST instances/{id}/resume` | 宿主补（引擎 `activate()` 未实现） | 同上 |
 * | `POST instances/{id}/cancel` | `abandonProcessTask()` + 宿主置终态 | 在办任务若不废弃，取消后仍可被推进 |
 * | `PUT instances/{id}/variables` | `addVariable()` / `removeVariable()` | 变量写入必须真的进库并被 `GET` 读到 |
 * | `POST tasks/{id}/reject` | `executeAndJumpToEnd()` | 引擎会把任务标 completed，宿主须纠正为 rejected |
 * | `POST tasks/{id}/claim` / `unclaim` | `addTaskActor()` | 认领要落 assignee；取消认领不得把任务变成开放任务 |
 * | `GET/POST tasks/{id}/form` | 读 `form_data` / `executeProcessTask()` | 表单提交要同时落表单并推进流程 |
 *
 * 这些路径如果只有「改前 Mock 时代跑过」，那么改成真库后它们**一次都没被执行过**——
 * 交付时「看起来做了」但可能第一次真实调用就 500。本测试就是为了让它们在 CI 里真的跑一遍。
 *
 * ## 断言口径
 *
 * 一律读库断言（`workflow_instances` / `workflow_tasks` / `workflow_event_logs`），
 * 不看 HTTP 200 了事；错误分支同时断言**数据零变化**。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowInstanceTaskLifecycleHttpTest extends ThinkPHPTestCase
{
    use WorkflowFixtureIsolation;
    use WorkflowExecutableFixture;

    /** 实例与任务所属租户（种子数据里的默认租户） */
    private const TENANT_ID = 1;

    /** 主操作人（租户1，实例发起人） */
    private const USER_ID = 6701;

    /** 同租户的另一个用户（用于「别人受理的任务不该出现在我的待办里」） */
    private const OTHER_USER_ID = 6702;

    /** 夹具角色 */
    private const ROLE_ID = 6711;

    /** 权限码对应的固定 id（实际 id 以 ensureWorkflowPermission() 返回值为准） */
    private const PERMISSION_INSTANCE_READ = 6721;
    private const PERMISSION_INSTANCE_WRITE = 6722;
    private const PERMISSION_TASK_READ = 6723;
    private const PERMISSION_TASK_WRITE = 6724;

    /** 测试定义的名称前缀（清理时按前缀删除） */
    private const DEFINITION_PREFIX = 'lifecycle_http_';

    /** 由本测试插入的权限 id（真实 id，供 role_permissions 使用） */
    private array $permissionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app()->bind(\think\Request::class, \app\Request::class);

        $routeFile = $this->app()->getRootPath() . 'route/workflow.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 复位鉴权中间件：其它测试类可能把它绑定成 no-op，容器会缓存解析结果
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        $this->resetRouteMiddlewareQueue();
        $this->purgeWorkflowFixturesByNamePrefix(self::DEFINITION_PREFIX);
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->purgeWorkflowFixturesByNamePrefix(self::DEFINITION_PREFIX);
        $this->purgeWorkflowFixtures();

        parent::tearDown();
    }

    // =================================================================
    // 1) 挂起 / 恢复
    // =================================================================

    /**
     * 挂起后任务必须**办不了**，恢复后才能继续办
     *
     * 引擎的 `executeProcessTask()` 只看任务状态、不看实例状态，
     * 所以「挂起期间任务仍可审批」是这道闸没做好的典型表现 —— 这里正面钉住它。
     */
    public function testSuspendBlocksTaskApprovalUntilResumed(): void
    {
        [$instanceId, $taskId] = $this->startInstance();

        $suspend = $this->httpRequest('POST', 'v1/workflow/instances/' . $instanceId . '/suspend');
        $this->assertSame(200, $suspend->getCode(), '挂起实例应成功，实际：' . $suspend->getContent());

        $suspended = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($suspended);
        $this->assertSame('suspended', (string) $suspended['status'], '挂起后实例状态应为 suspended');
        $this->assertNotEmpty($suspended['suspended_at'], 'suspended_at 必须落库');

        // 挂起期间审批：必须被拒，且任务行零变化
        $blocked = $this->httpRequest('POST', 'v1/workflow/tasks/' . $taskId . '/approve', ['comment' => '挂起期间审批']);
        $this->assertSame(400, $blocked->getCode(), '挂起期间审批必须被拒（400 状态冲突），实际：' . $blocked->getContent());

        $untouched = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($untouched);
        $this->assertSame('claimed', (string) $untouched['status'], '被拒后任务状态不得变化');
        $this->assertNull($untouched['comment'], '被拒后审批意见不得落库');

        // 恢复后同一任务必须能正常审批
        $resume = $this->httpRequest('POST', 'v1/workflow/instances/' . $instanceId . '/resume');
        $this->assertSame(200, $resume->getCode(), '恢复实例应成功，实际：' . $resume->getContent());

        $resumed = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($resumed);
        $this->assertSame('running', (string) $resumed['status'], '恢复后实例状态应为 running');
        $this->assertNull($resumed['suspended_at'], '恢复后 suspended_at 应清空');

        $approved = $this->httpRequest('POST', 'v1/workflow/tasks/' . $taskId . '/approve', ['comment' => '恢复后审批']);
        $this->assertSame(200, $approved->getCode(), '恢复后审批应成功，实际：' . $approved->getContent());

        $this->assertSame(
            ['instance_started', 'instance_suspended', 'instance_resumed', 'task_completed'],
            $this->eventTypes($instanceId),
            '挂起/恢复都必须留痕，且顺序与操作一致'
        );
    }

    // =================================================================
    // 2) 取消
    // =================================================================

    /**
     * 取消实例：在办任务必须被废弃，实例进终态，此后不可再推进
     */
    public function testCancelEndsInstanceAndBlocksTaskActions(): void
    {
        [$instanceId, $taskId] = $this->startInstance();

        $cancel = $this->httpRequest('POST', 'v1/workflow/instances/' . $instanceId . '/cancel', ['reason' => '发起人撤回']);
        $this->assertSame(200, $cancel->getCode(), '取消实例应成功，实际：' . $cancel->getContent());

        $instance = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($instance);
        $this->assertSame('cancelled', (string) $instance['status'], '取消后实例状态应为 cancelled');
        $this->assertNotEmpty($instance['completed_at'], '取消也应有完成时间（终态）');

        $task = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($task);
        $this->assertSame('cancelled', (string) $task['status'], '取消实例时在办任务必须被废弃为 cancelled');

        // 已取消实例上的任务不能再审批
        $approve = $this->httpRequest('POST', 'v1/workflow/tasks/' . $taskId . '/approve', ['comment' => '取消后审批']);
        $this->assertSame(400, $approve->getCode(), '取消后审批必须被拒，实际：' . $approve->getContent());

        $after = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($after);
        $this->assertSame('cancelled', (string) $after['status'], '被拒后任务状态不得变化');

        $this->assertContains('instance_cancelled', $this->eventTypes($instanceId), '取消必须留痕');
    }

    // =================================================================
    // 3) 变量
    // =================================================================

    /**
     * 变量读 / 写都必须真的落库
     */
    public function testVariablesRoundTripThroughHttp(): void
    {
        [$instanceId] = $this->startInstance(['amount' => 800]);

        $read = $this->httpRequest('GET', 'v1/workflow/instances/' . $instanceId . '/variables');
        $this->assertSame(200, $read->getCode(), '读取变量应成功，实际：' . $read->getContent());

        $readBody = json_decode($read->getContent(), true);
        $this->assertSame($instanceId, (int) $readBody['data']['instance_id'], '返回的应是同一实例');
        $this->assertSame(800, $readBody['data']['variables']['amount'] ?? null, '启动时传入的变量应可读回');

        // 合并写入
        $update = $this->httpRequest('PUT', 'v1/workflow/instances/' . $instanceId . '/variables', [
            'variables' => ['approved_amount' => 600],
        ]);
        $this->assertSame(200, $update->getCode(), '更新变量应成功，实际：' . $update->getContent());

        $updateBody = json_decode($update->getContent(), true);
        $this->assertSame(600, $updateBody['data']['variables']['approved_amount'] ?? null, '新变量应在返回里出现');
        $this->assertSame(800, $updateBody['data']['variables']['amount'] ?? null, '默认 merge=true，旧变量必须保留');

        // 库里的 variables 列同样要能读到（证明不是只在响应里拼的）
        $row = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($row);
        $stored = is_array($row['variables']) ? $row['variables'] : json_decode((string) $row['variables'], true);
        $this->assertSame(600, $stored['approved_amount'] ?? null, '更新后的变量必须落库');
        $this->assertSame(800, $stored['amount'] ?? null, '库里旧变量不得丢失');

        $this->assertContains('variables_updated', $this->eventTypes($instanceId), '变量更新必须留痕');
    }

    // =================================================================
    // 4) 驳回
    // =================================================================

    /**
     * 驳回：任务必须标 rejected（不是引擎落库的 completed），实例进 cancelled
     */
    public function testRejectMarksTaskRejectedAndEndsInstance(): void
    {
        [$instanceId, $taskId] = $this->startInstance();

        $reject = $this->httpRequest('POST', 'v1/workflow/tasks/' . $taskId . '/reject', ['comment' => '金额不符，退回']);
        $this->assertSame(200, $reject->getCode(), '驳回应成功，实际：' . $reject->getContent());

        $task = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($task);
        $this->assertSame(
            'rejected',
            (string) $task['status'],
            '驳回后任务状态必须是 rejected —— 引擎内部走的是 finishProcessTask()（会落 completed），宿主必须纠正'
        );
        $this->assertNotEmpty($task['rejected_at'], 'rejected_at 必须写入');
        $this->assertSame('金额不符，退回', (string) $task['comment'], '驳回意见应落 comment');

        $instance = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($instance);
        $this->assertSame('cancelled', (string) $instance['status'], '驳回即终结：实例应进 cancelled');

        $this->assertContains('instance_rejected', $this->eventTypes($instanceId), '驳回必须写入 instance_rejected 事件');
    }

    // =================================================================
    // 5) 表单
    // =================================================================

    /**
     * 表单读 / 提交：提交要同时落 `form_data` 并推进流程
     */
    public function testFormSubmitAdvancesProcessAndPersistsFormData(): void
    {
        [$instanceId, $taskId] = $this->startInstance();

        $form = $this->httpRequest('GET', 'v1/workflow/tasks/' . $taskId . '/form');
        $this->assertSame(200, $form->getCode(), '读取任务表单应成功，实际：' . $form->getContent());

        $formBody = json_decode($form->getContent(), true);
        $this->assertSame(
            [
                'form_data',
                'form_messages',
                'form_name',
                'form_rules',
                'form_schema',
                'reason',
                'resolved',
                'task_id',
            ],
            $this->sortedKeys($formBody['data']),
            '表单结构必须是 8 键契约（键名与顺序无关）：'
            . 'task_id / form_name / form_schema / form_rules / form_messages / form_data / resolved / reason'
        );

        $submit = $this->httpRequest('POST', 'v1/workflow/tasks/' . $taskId . '/form', [
            'form_data' => ['amount' => 800, 'reason' => '差旅报销'],
            'comment'   => '提交表单',
        ]);
        $this->assertSame(200, $submit->getCode(), '提交表单应成功，实际：' . $submit->getContent());

        $task = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($task);
        $this->assertSame('completed', (string) $task['status'], '提交后任务应完成');

        $formData = is_array($task['form_data']) ? $task['form_data'] : json_decode((string) $task['form_data'], true);
        $this->assertSame(800, $formData['amount'] ?? null, '表单数据必须落库到 form_data');
        $this->assertSame('差旅报销', $formData['reason'] ?? null, '表单数据必须落库到 form_data');

        // 流程真的推进了：下一节点任务出现
        $next = Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->where('status', 'pending')
            ->find();
        $this->assertIsArray($next, '提交表单必须推进到下一节点（生成新任务）');
        $this->assertSame('review', (string) $next['node_name'], '下一节点应为 review');

        $this->assertContains('task_form_submitted', $this->eventTypes($instanceId), '表单提交必须留痕');
    }

    // =================================================================
    // 6) 认领 / 取消认领
    // =================================================================

    /**
     * 认领 → 取消认领：状态与受理人成对变化，且不会把任务变成「开放任务」
     */
    public function testClaimAndUnclaimKeepTaskOwnershipConsistent(): void
    {
        $definitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . uniqid());
        $instanceId   = $this->seedWorkflowInstanceRow(self::TENANT_ID, $definitionId, self::USER_ID);

        // 只设候选用户（不含候选组）：认领后取消认领，候选集必须**原样保留**，
        // 否则「只有一个候选人」的任务会退化成开放任务（= 授权放大）
        $taskId = $this->seedWorkflowTaskRow(self::TENANT_ID, $instanceId, [
            'status'           => 'pending',
            'assignee'         => null,
            'candidate_users'  => [(string) self::USER_ID],
            'candidate_groups' => [],
        ]);

        $claim = $this->httpRequest('POST', 'v1/workflow/tasks/' . $taskId . '/claim');
        $this->assertSame(200, $claim->getCode(), '认领应成功，实际：' . $claim->getContent());

        $claimed = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($claimed);
        $this->assertSame('claimed', (string) $claimed['status'], '认领后任务状态应为 claimed');
        $this->assertSame(self::USER_ID, (int) $claimed['assignee'], '认领后受理人应为当前用户');

        $unclaim = $this->httpRequest('POST', 'v1/workflow/tasks/' . $taskId . '/unclaim');
        $this->assertSame(200, $unclaim->getCode(), '取消认领应成功，实际：' . $unclaim->getContent());

        $released = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($released);
        $this->assertSame('pending', (string) $released['status'], '取消认领后任务应回到 pending');
        $this->assertNull($released['assignee'], '取消认领后受理人应清空');

        $candidates = array_map('strval', (array) json_decode((string) $released['candidate_users'], true));
        $this->assertContains(
            (string) self::USER_ID,
            $candidates,
            '取消认领不得把原候选人从候选集移除（否则任务退化为开放任务 = 授权放大）'
        );

        $this->assertSame(
            ['task_claimed', 'task_unclaimed'],
            $this->eventTypes($instanceId),
            '认领与取消认领都必须留痕'
        );
    }

    // =================================================================
    // 7) 列表类端点（分页 / 待办 / 完成）
    // =================================================================

    /**
     * `GET instances` 必须强制按当前租户过滤（不能把别的租户的实例列出来）
     */
    public function testInstanceListIsTenantScoped(): void
    {
        [$mineId] = $this->startInstance();

        // 另一个租户的实例：如果查询漏了 tenant_id，它就会出现在列表里
        $foreignDefinitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . 't2_' . uniqid(), 2);
        $foreignInstanceId   = $this->seedWorkflowInstanceRow(2, $foreignDefinitionId, self::USER_ID);

        $response = $this->httpRequest('GET', 'v1/workflow/instances', ['page' => 1, 'page_size' => 50]);
        $this->assertSame(200, $response->getCode(), '实例列表应可读，实际：' . $response->getContent());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body['data']['list'] ?? null, '列表必须是分页结构 {list, total, ...}');
        $this->assertArrayHasKey('total', $body['data'], '分页结构必须带 total');

        $ids = array_map('intval', array_column($body['data']['list'], 'id'));

        $this->assertContains($mineId, $ids, '本租户的实例必须出现在列表里');
        $this->assertNotContains($foreignInstanceId, $ids, '别的租户的实例绝不能出现在列表里');

        foreach ($body['data']['list'] as $row) {
            $this->assertSame(self::TENANT_ID, (int) $row['tenant_id'], '列表里每一行都必须属于当前租户');
        }
    }

    /**
     * `GET tasks/pending` 只应返回「受理人是我」或「我在候选集里」的在办任务
     */
    public function testPendingListReturnsOnlyMyTasks(): void
    {
        $definitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . uniqid());
        $instanceId   = $this->seedWorkflowInstanceRow(self::TENANT_ID, $definitionId, self::USER_ID);

        // a) 已受理给我
        $assignedToMe = $this->seedWorkflowTaskRow(self::TENANT_ID, $instanceId, [
            'status'   => 'claimed',
            'assignee' => self::USER_ID,
        ]);

        // b) 我是候选人（未受理）
        $candidateForMe = $this->seedWorkflowTaskRow(self::TENANT_ID, $instanceId, [
            'status'           => 'pending',
            'assignee'         => null,
            'candidate_users'  => [(string) self::USER_ID],
            'candidate_groups' => [],
        ]);

        // c) 受理人是别人，且我也不在候选集
        $assignedToOther = $this->seedWorkflowTaskRow(self::TENANT_ID, $instanceId, [
            'status'   => 'claimed',
            'assignee' => self::OTHER_USER_ID,
        ]);

        $response = $this->httpRequest('GET', 'v1/workflow/tasks/pending', ['page' => 1, 'page_size' => 100]);
        $this->assertSame(200, $response->getCode(), '待办列表应可读，实际：' . $response->getContent());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body['data']['list'] ?? null, '待办列表必须是分页结构');

        $ids = array_map('intval', array_column($body['data']['list'], 'id'));

        $this->assertContains($assignedToMe, $ids, '受理人是我的任务必须出现在待办里');
        $this->assertContains($candidateForMe, $ids, '我是候选人的任务必须出现在待办里');
        $this->assertNotContains($assignedToOther, $ids, '别人受理、我又不是候选人的任务不得出现在我的待办里');
    }

    /**
     * `POST tasks/{id}/complete` 与 approve 走同一套推进逻辑，必须同样真的推进流程
     */
    public function testCompleteAdvancesProcess(): void
    {
        [$instanceId, $taskId] = $this->startInstance();

        $complete = $this->httpRequest('POST', 'v1/workflow/tasks/' . $taskId . '/complete', ['comment' => '办理完成']);
        $this->assertSame(200, $complete->getCode(), '完成任务应成功，实际：' . $complete->getContent());

        $task = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($task);
        $this->assertSame('completed', (string) $task['status'], '完成任务后状态应为 completed');
        $this->assertNotEmpty($task['completed_at'], 'completed_at 必须写入');

        $next = Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->where('status', 'pending')
            ->find();
        $this->assertIsArray($next, '完成任务必须推进到下一节点');
        $this->assertSame('review', (string) $next['node_name'], '下一节点应为 review');
    }

    // =================================================================
    // 辅助
    // =================================================================

    /**
     * 启动一条真实实例（发起人 = USER_ID），返回 [实例ID, 首个任务ID]
     *
     * @param array<string, mixed> $variables 实例变量
     * @return array{0: int, 1: int}
     */
    private function startInstance(array $variables = ['amount' => 100]): array
    {
        $definitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . uniqid());

        $response = $this->httpRequest('POST', 'v1/workflow/instances', [
            'definition_id' => $definitionId,
            'title'         => '生命周期验证实例',
            'variables'     => $variables,
        ]);

        $this->assertSame(201, $response->getCode(), '夹具实例应启动成功，实际：' . $response->getContent());

        $instanceId = (int) (json_decode($response->getContent(), true)['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $instanceId, '夹具实例应有真实主键');

        $taskId = (int) Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->whereIn('status', ['pending', 'claimed', 'escalated'])
            ->value('id');

        $this->assertGreaterThan(0, $taskId, '夹具实例应生成第一个任务');

        return [$instanceId, $taskId];
    }

    /**
     * 实例的事件类型序列（按写入顺序）
     *
     * @return array<int, string>
     */
    private function eventTypes(int $instanceId): array
    {
        return array_map(
            'strval',
            Db::table('workflow_event_logs')
                ->where('instance_id', $instanceId)
                ->order('id', 'asc')
                ->column('event_type')
        );
    }

    /**
     * 取出「形状」并排序（与字段顺序无关）
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function sortedKeys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }

    /**
     * 播种夹具：用户 / 角色 / 权限 / 关联
     */
    private function seedFixtures(): void
    {
        $this->seedWorkflowFixtures(function (): void {
            $now = date('Y-m-d H:i:s');

            // 权限：按 slug 确保存在（可能由平台种子预建），调用方必须用返回 id
            $this->permissionIds = [
                $this->ensureWorkflowPermission('workflow_instance', 'read', self::PERMISSION_INSTANCE_READ),
                $this->ensureWorkflowPermission('workflow_instance', 'write', self::PERMISSION_INSTANCE_WRITE),
                $this->ensureWorkflowPermission('workflow_task', 'read', self::PERMISSION_TASK_READ),
                $this->ensureWorkflowPermission('workflow_task', 'write', self::PERMISSION_TASK_WRITE),
            ];

            Db::table('users')->insertAll([
                [
                    'id'         => self::USER_ID,
                    'tenant_id'  => self::TENANT_ID,
                    'username'   => 'wf_lifecycle_user',
                    'email'      => 'wf_lifecycle_user@workflow-lifecycle.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id'         => self::OTHER_USER_ID,
                    'tenant_id'  => self::TENANT_ID,
                    'username'   => 'wf_lifecycle_other',
                    'email'      => 'wf_lifecycle_other@workflow-lifecycle.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            Db::table('roles')->insert([
                'id'         => self::ROLE_ID,
                'tenant_id'  => self::TENANT_ID,
                'name'       => 'wf_lifecycle_role',
                'slug'       => 'wf_lifecycle_role',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('user_roles')->insert([
                'user_id'    => self::USER_ID,
                'role_id'    => self::ROLE_ID,
                'created_at' => $now,
            ]);

            $rolePermissions = [];

            foreach ($this->permissionIds as $permissionId) {
                $rolePermissions[] = [
                    'role_id'       => self::ROLE_ID,
                    'permission_id' => $permissionId,
                    'created_at'    => $now,
                ];
            }

            Db::table('role_permissions')->insertAll($rolePermissions);
        });

        // 权限缓存必须清：其它用例可能缓存过该用户的空权限集合
        (new PermissionCacheService())->clearUserCache(self::USER_ID, self::TENANT_ID);
    }

    /**
     * 构造携带 JWT 的请求头（租户上下文来自 token 里的 tenant_id）
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                userId: self::USER_ID,
                tenantId: self::TENANT_ID,
                siteId: 0
            ),
            'Accept' => 'application/json',
        ];
    }

    /**
     * 简易 HTTP 调用（默认以 USER_ID 的 JWT 发起）
     */
    private function httpRequest(string $method, string $uri, array $data = [], array $headers = [])
    {
        // 第三个参数 true：容器缓存了上一次请求实例，必须强制新建，
        // 否则 app\Request 的实例级缓存（userGroups）会跨用例串味
        $request = $this->app()->make(\think\Request::class, [], true);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        $headers = $headers === [] ? $this->authHeaders() : $headers;

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
