<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\enums\err\LfErrEnum;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\Integration\WorkflowBootstrap;
use Alkaid\Workflow\Engine\interface\services\ITaskActorResolver;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Alkaid\Workflow\Engine\Services\DefaultTaskActorResolver;
use madong\helper\Dict;
use Tests\ThinkPHPTestCase;
use Tests\Traits\WorkflowFixtureIsolation;
use think\facade\Db;

/**
 * 任务参与者解析的端到端测试：**「谁有权办这个任务」真的通了**
 *
 * ## 这条测试要证明什么（对应 R 系列缺陷的验收）
 *
 * 改造前 `ProcessTaskService::buildTaskColumns()` 把节点上的参与者配置整体丢掉：
 * `assignee_name` 恒 null、`candidate_groups` 恒 `[]`、`candidate_users` 只是把 assignee
 * 复制一份。于是「谁有权办这个任务」只能写死一个人，配了角色等于没配 ——
 * 因为 `isAllowed()` 只认 `assignee` + `candidate_users`，**根本不读** `candidate_groups`。
 *
 * 因此这里不做「字段非空就算过」的弱断言，而是跑真实链路：
 *
 *   1. **角色展开**：建任务时把 `role:<slug>` 展开成本租户内该角色的全部用户ID，
 *      写进 `candidate_users`；原始引用 `role:<slug>` 原样写进 `candidate_groups`；
 *   2. **正例**：角色成员 `isAllowed()` 为真，`executeProcessTask()` 真的把流程推进一步；
 *   3. **反例**：同租户内不在候选集的普通用户被拒绝（`LFlowException`），任务状态不变；
 *   4. **跨租户反例**：另一个租户的用户（哪怕拿着同一个用户ID）既读不到任务、也办不了。
 *
 * 另外断言 `assignee` / `assignee_name` 来自节点配置（`user:<id>`），
 * 以及迁移 `20251229000001` 给示例定义补的 `role:admin` 在**真实库**里能展开。
 *
 * ## 数据从哪来
 *
 * 测试库 `alkaid_sys_test` 里没有两条示例定义（`20251216000003` 在全新库因「租户 1 不存在」
 * 整体跳过），所以定义行由本测试自行插入并清理（与 `WorkflowLogicFlowExecutionEndToEndTest`
 * 同一做法）。角色/用户夹具走 `WorkflowFixtureIsolation`，用 69xx 段固定 ID，
 * 避开其他工作流测试类占用的 61xx~67xx。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowTaskActorResolutionTest extends ThinkPHPTestCase
{
    use \Tests\Traits\WorkflowFixtureIsolation;

    /** 本租户（测试库里 id=1 的 Default Tenant） */
    private const TENANT_ID = 1;

    /** 另一个租户（测试库里 id=2 的 Test Tenant Two），用于跨租户反例 */
    private const OTHER_TENANT_ID = 2;

    /** 角色成员（本租户）：节点配 role:<slug> 时应当被展开出来 */
    private const ROLE_MEMBER_ID = 6951;

    /** 同租户内的普通用户（没有任何角色）：用来证明「不在候选集就办不了」 */
    private const OUTSIDER_ID = 6952;

    /** 另一个租户的用户：用来证明跨租户办不了 */
    private const OTHER_TENANT_USER_ID = 6953;

    /** 测试用角色（角色 -> 用户展开的验证对象） */
    private const ROLE_ID = 6961;
    private const ROLE_SLUG = 'wf_actor_admin';

    /** 测试定义名（用例结束后删除） */
    private const DEFINITION_NAME = 'e2e_task_actor_resolution';

    /** 示例定义名（复用 20251228000001 的图 + 20251229000001 的参与者配置） */
    private const SAMPLE_EXPENSE = 'expense_reimbursement';

    /** 迁移：可执行 LogicFlow 图（图的唯一真相源） */
    private const GRAPH_MIGRATION_FILE = 'database/migrations/20251228000001_add_executable_logicflow_to_sample_definitions.php';
    private const GRAPH_MIGRATION_CLASS = 'AddExecutableLogicFlowToSampleDefinitions';

    /** 迁移：示例定义的参与者组配置（角色引用的唯一真相源） */
    private const ACTOR_MIGRATION_FILE = 'database/migrations/20251229000001_add_task_actor_groups_to_sample_definitions.php';
    private const ACTOR_MIGRATION_CLASS = 'AddTaskActorGroupsToSampleDefinitions';

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeFixtures();

        WorkflowBootstrap::reset();
        TenantContext::clearAll();

        // 不注入任何服务替身：要证明的是生产装配（容器按默认配置注册解析器）能跑通
        WorkflowBootstrap::initialize([
            'extension_parameters' => ['tenant_id' => self::TENANT_ID],
        ]);
        TenantContext::setTenantId(self::TENANT_ID);

        $this->seedActorFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();

        TenantContext::clearAll();
        WorkflowBootstrap::reset();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 0) 装配：解析器必须由容器默认注册（不是靠调用方 new 出来的）
    // -----------------------------------------------------------------

    public function testContainerRegistersDefaultResolver(): void
    {
        $resolver = ServiceContext::find(ITaskActorResolver::class);

        $this->assertInstanceOf(
            DefaultTaskActorResolver::class,
            $resolver,
            '默认装配必须提供真实解析器（WorkflowContainer::buildCoreServices），否则宿主只能自己 new'
        );
    }

    // -----------------------------------------------------------------
    // 1) 角色展开 + 正例：角色成员能办被指派的任务
    // -----------------------------------------------------------------

    /**
     * 角色成员既能「看到自己是候选人」，也能真的把任务办掉
     */
    public function testRoleMemberCanCompleteTaskAssignedByRole(): void
    {
        $definitionId = $this->insertDefinition();
        $instanceId = $this->startInstance($definitionId);

        // apply 节点的处理人是发起人（首个任务节点特例，行为不变）
        $applyTask = $this->onlyDoingTask($instanceId);
        $this->assertSame('apply', (string) $applyTask['node_name']);
        $this->assertSame(self::ROLE_MEMBER_ID, (int) $applyTask['assignee'], '申请节点处理人应是发起人');

        $this->executeTask((int) $applyTask['id'], self::ROLE_MEMBER_ID);

        // ---- 角色展开：这条任务只有「角色成员」是候选人 ----
        $reviewTask = $this->onlyDoingTask($instanceId);
        $this->assertSame('review_by_role', (string) $reviewTask['node_name']);

        $this->assertNull($reviewTask['assignee'], '只配角色组时任务应是候选池（无处理人）');
        $this->assertNull($reviewTask['assignee_name'], '没有处理人时不应编一个姓名');
        $this->assertSame('pending', (string) $reviewTask['status'], '无人指派 + 有候选人 → pending（谁抢谁办）');

        // 原始组引用原样保留（审计口径），展开结果进 candidate_users（鉴权口径）
        $this->assertSame(
            ['role:' . self::ROLE_SLUG],
            json_decode((string) $reviewTask['candidate_groups'], true),
            'candidate_groups 必须原样保存节点配置的组引用'
        );
        $this->assertSame(
            [$this->roleMemberIds()],
            [json_decode((string) $reviewTask['candidate_users'], true)],
            'candidate_users 必须是角色展开后的用户ID（与库里的角色成员一致）'
        );

        // ---- 正例：角色成员能办 ----
        $taskService = WorkflowBootstrap::getTaskService();
        $task = $taskService->getById((string) $reviewTask['id']);
        $this->assertNotNull($task);
        $this->assertTrue(
            $taskService->isAllowed($task, (string) self::ROLE_MEMBER_ID),
            '角色成员必须被 isAllowed() 放行（它只读 assignee + candidate_users）'
        );

        $this->executeTask((int) $reviewTask['id'], self::ROLE_MEMBER_ID);

        $finished = Db::table('workflow_tasks')->where('id', $reviewTask['id'])->find();
        $this->assertSame('completed', (string) $finished['status'], '角色成员执行后任务应完成');
        $this->assertSame(self::ROLE_MEMBER_ID, (int) $finished['assignee'], '完成时记录真实操作人');

        // 流程真的推进了：下一节点 assigned_by_user 已生成
        $nextTask = $this->onlyDoingTask($instanceId);
        $this->assertSame('assigned_by_user', (string) $nextTask['node_name'], '流程应推进到下一节点');
    }

    // -----------------------------------------------------------------
    // 2) assignee / assignee_name：节点写 user:<id> 时真的落到这两列
    // -----------------------------------------------------------------

    /**
     * 节点上写死一个人（`user:<id>`）时：status=claimed、assignee_name 来自 users.name
     */
    public function testExplicitUserAssigneeFillsAssigneeAndName(): void
    {
        $definitionId = $this->insertDefinition();
        $instanceId = $this->startInstance($definitionId);

        $this->executeTask((int) $this->onlyDoingTask($instanceId)['id'], self::ROLE_MEMBER_ID);
        $this->executeTask((int) $this->onlyDoingTask($instanceId)['id'], self::ROLE_MEMBER_ID);

        $task = $this->onlyDoingTask($instanceId);
        $this->assertSame('assigned_by_user', (string) $task['node_name']);

        $this->assertSame(self::OUTSIDER_ID, (int) $task['assignee'], 'user:<id> 应解析成该用户');
        $this->assertSame('Bob 审批人', (string) $task['assignee_name'], 'assignee_name 应来自 users.name');
        $this->assertSame('claimed', (string) $task['status'], '有处理人 → claimed');

        $this->assertSame(
            [self::OUTSIDER_ID, self::ROLE_MEMBER_ID],
            json_decode((string) $task['candidate_users'], true),
            'candidate_users 是 assignee 与 candidate_ext_users 的并集（含 assignee）'
        );
    }

    // -----------------------------------------------------------------
    // 3) 反例：同租户内不在候选集的普通用户办不了
    // -----------------------------------------------------------------

    /**
     * 普通用户（无该角色）对同一个任务的执行必须被拒绝，且任务状态不变
     */
    public function testTenantOutsiderCannotCompleteTask(): void
    {
        $definitionId = $this->insertDefinition();
        $instanceId = $this->startInstance($definitionId);

        $this->executeTask((int) $this->onlyDoingTask($instanceId)['id'], self::ROLE_MEMBER_ID);

        $reviewTask = $this->onlyDoingTask($instanceId);
        $taskId = (int) $reviewTask['id'];

        // isAllowed 直接判假
        $taskService = WorkflowBootstrap::getTaskService();
        $task = $taskService->getById((string) $taskId);
        $this->assertNotNull($task);
        $this->assertFalse(
            $taskService->isAllowed($task, (string) self::OUTSIDER_ID),
            '不在候选集的同租户用户必须被拒绝'
        );

        // 走引擎入口同样被拒
        try {
            $this->executeTask($taskId, self::OUTSIDER_ID);
            $this->fail('越权执行必须抛 LFlowException');
        } catch (LFlowException $e) {
            // 引擎把错误**码**当消息抛（`new LFlowException(LfErrEnum::X->value)`，
            // 见 ProcessEngines::execute()），所以这里比对码值而不是中文文案
            $this->assertSame(
                (string) LfErrEnum::NOT_ALLOWED_EXECUTE->value,
                $e->getMessage(),
                '拒绝原因应是「当前参与者不能执行该流程任务」'
            );
        }

        // 任务状态与处理人都没被改写
        $after = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertSame('pending', (string) $after['status'], '越权后任务应仍是 pending');
        $this->assertNull($after['assignee'], '越权后不得写入处理人');
        $this->assertNull($after['completed_at'], '越权后不得写完成时间');

        // 实例仍在运行（未被推进）
        $instance = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertSame('running', (string) $instance['status'], '越权调用不应推进实例');
    }

    // -----------------------------------------------------------------
    // 4) 跨租户反例
    // -----------------------------------------------------------------

    /**
     * 另一个租户的用户：读不到这个任务（租户隔离），也就办不了
     *
     * 这里刻意用「另一个租户里确实存在的用户ID」：外键只校验 `users.id` 存在，
     * 拦不住跨租户写入，因此挡住它的必须是租户作用域本身。
     */
    public function testOtherTenantUserCannotSeeOrCompleteTask(): void
    {
        $definitionId = $this->insertDefinition();
        $instanceId = $this->startInstance($definitionId);

        $this->executeTask((int) $this->onlyDoingTask($instanceId)['id'], self::ROLE_MEMBER_ID);

        $reviewTask = $this->onlyDoingTask($instanceId);
        $taskId = (int) $reviewTask['id'];

        // 切到另一个租户
        TenantContext::setTenantId(self::OTHER_TENANT_ID);

        $this->assertNull(
            WorkflowBootstrap::getTaskService()->getById((string) $taskId),
            '跨租户读取任务必须返回 null（租户隔离红线）'
        );
        $this->assertNull(
            WorkflowBootstrap::getInstanceService()->getById((string) $instanceId),
            '跨租户读取实例必须返回 null'
        );

        try {
            $this->executeTask($taskId, self::OTHER_TENANT_USER_ID);
            $this->fail('跨租户执行必须抛 LFlowException');
        } catch (LFlowException $e) {
            // 跨租户在「按 id 查进行中任务」这一步就被租户作用域挡住了（比鉴权更早）
            $this->assertSame(
                (string) LfErrEnum::NOT_FOUND_DOING_PROCESS_TASK->value,
                $e->getMessage(),
                '跨租户应表现为「找不到进行中的流程任务」'
            );
        }

        // 切回本租户：任务毫发无损
        TenantContext::setTenantId(self::TENANT_ID);

        $after = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertSame('pending', (string) $after['status'], '跨租户尝试后任务状态不得改变');
        $this->assertNull($after['assignee']);

        // 本租户的角色成员仍然可以办（证明确实是「按租户」而不是任务坏了）
        $this->executeTask($taskId, self::ROLE_MEMBER_ID);
        $this->assertSame(
            'completed',
            (string) Db::table('workflow_tasks')->where('id', $taskId)->value('status'),
            '切回本租户后角色成员应能正常办结'
        );
    }

    // -----------------------------------------------------------------
    // 5) 示例定义 + 迁移：role:admin 在真实库里能展开
    // -----------------------------------------------------------------

    /**
     * 迁移 `20251229000001` 给示例定义的两个审批节点补的 `role:admin`，
     * 在真实库里必须展开成租户 1 的 admin 成员（而不是空集 / 原文照抄）
     *
     * 断言用迁移自己声明的 targets()/patchGraph()，保证「迁移写的」与「测试断言的」
     * 是同一份事实，不在测试里另抄一遍 node id。
     */
    public function testSampleDefinitionRoleAdminIsExpandedInRealDatabase(): void
    {
        $graph = $this->patchedSampleGraph();

        $definitionId = $this->insertDefinition(self::SAMPLE_EXPENSE, $graph);
        $instanceId = $this->startInstance($definitionId);

        $this->executeTask((int) $this->onlyDoingTask($instanceId)['id'], self::ROLE_MEMBER_ID);

        $reviewTask = $this->onlyDoingTask($instanceId);
        $this->assertSame('review_manager', (string) $reviewTask['node_name']);

        $adminMembers = $this->adminRoleMemberIds();

        $this->assertNotEmpty(
            $adminMembers,
            '测试库的租户 1 应当有 admin 角色的成员（database/seeds/TestPlatformSeed.php 保证 user 1 → role 1）'
        );
        $this->assertSame(
            ['role:admin'],
            json_decode((string) $reviewTask['candidate_groups'], true),
            '示例定义的审批节点应带 role:admin（迁移 20251229000001 写入）'
        );
        $this->assertSame(
            $adminMembers,
            json_decode((string) $reviewTask['candidate_users'], true),
            'role:admin 应展开成租户 1 内该角色的全部用户ID'
        );

        // 管理员本人能办（只取第一个成员，避免依赖 seed 的具体人数）
        $adminId = $adminMembers[0];
        $this->assertTrue(
            WorkflowBootstrap::getTaskService()->isAllowed(
                WorkflowBootstrap::getTaskService()->getById((string) $reviewTask['id']),
                (string) $adminId
            ),
            'admin 角色成员应被 isAllowed() 放行'
        );
    }

    // -----------------------------------------------------------------
    // 辅助方法：夹具
    // -----------------------------------------------------------------

    /**
     * 播种参与者夹具：租户1 角色 + 成员 + 普通用户；租户2 一个用户
     */
    private function seedActorFixtures(): void
    {
        $this->seedWorkflowFixtures(function (): void {
            $now = date('Y-m-d H:i:s');

            Db::table('users')->insertAll([
                [
                    'id'         => self::ROLE_MEMBER_ID,
                    'tenant_id'  => self::TENANT_ID,
                    'username'   => 'wf_actor_role_member',
                    'email'      => 'wf_actor_role_member@workflow-actor.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'name'       => 'Alice 审批人',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id'         => self::OUTSIDER_ID,
                    'tenant_id'  => self::TENANT_ID,
                    'username'   => 'wf_actor_outsider',
                    'email'      => 'wf_actor_outsider@workflow-actor.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'name'       => 'Bob 审批人',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id'         => self::OTHER_TENANT_USER_ID,
                    'tenant_id'  => self::OTHER_TENANT_ID,
                    'username'   => 'wf_actor_other_tenant',
                    'email'      => 'wf_actor_other_tenant@workflow-actor.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'name'       => 'Carol 别的租户',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            Db::table('roles')->insert([
                'id'         => self::ROLE_ID,
                'tenant_id'  => self::TENANT_ID,
                'name'       => '角色审批测试',
                'slug'       => self::ROLE_SLUG,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('user_roles')->insert([
                'user_id'    => self::ROLE_MEMBER_ID,
                'role_id'    => self::ROLE_ID,
                'created_at' => $now,
            ]);
        });
    }

    /**
     * 清理夹具：本测试建的定义/实例/任务/事件 + 69xx 段用户角色
     */
    private function purgeFixtures(): void
    {
        $names = [self::DEFINITION_NAME, self::SAMPLE_EXPENSE];

        $definitionIds = Db::table('workflow_definitions')->whereIn('name', $names)->column('id');

        if ($definitionIds !== []) {
            $instanceIds = Db::table('workflow_instances')->whereIn('definition_id', $definitionIds)->column('id');

            if ($instanceIds !== []) {
                Db::table('workflow_tasks')->whereIn('instance_id', $instanceIds)->delete();
                Db::table('workflow_event_logs')->whereIn('instance_id', $instanceIds)->delete();
                Db::table('workflow_instances')->whereIn('id', $instanceIds)->delete();
            }

            Db::table('workflow_definitions')->whereIn('id', $definitionIds)->delete();
        }

        $this->purgeWorkflowFixtures();
    }

    // -----------------------------------------------------------------
    // 辅助方法：定义与流程
    // -----------------------------------------------------------------

    /**
     * 插入一条测试定义（幂等：先删同名行，避免命中 uk_tenant_name_version）
     *
     * 图刻意包含三种指派形态：
     * - `apply`：不配参与者（首个任务节点的处理人由引擎设为发起人）；
     * - `review_by_role`：`candidate_ext_groups = ["role:<slug>"]` → 角色展开；
     * - `assigned_by_user`：`assignee = "user:<id>"` + `candidate_ext_users = ["<另一人>"]`
     *   → 验证 assignee / assignee_name / 候选并集。
     *
     * @param string|null $name  定义名（默认用测试专用名）
     * @param array|null  $graph 直接指定图（示例定义用例用）
     */
    private function insertDefinition(?string $name = null, ?array $graph = null): int
    {
        $name ??= self::DEFINITION_NAME;

        $now = date('Y-m-d H:i:s');

        $definitionId = Db::table('workflow_definitions')->insertGetId([
            'tenant_id'        => self::TENANT_ID,
            'name'             => $name,
            'display_name'     => '参与者解析 E2E 流程',
            'description'      => 'E2E 测试定义（用例结束后删除）',
            'type'             => 'approval',
            'category'         => 'test',
            'icon'             => 'e2e-icon',
            'version'          => 1,
            'status'           => 'published',
            'nodes_config'     => json_encode($graph ?? $this->actorGraph(), JSON_UNESCAPED_UNICODE),
            'triggers_config'  => json_encode([], JSON_UNESCAPED_UNICODE),
            'variables_schema' => json_encode([], JSON_UNESCAPED_UNICODE),
            'is_active'        => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $this->assertGreaterThan(0, (int) $definitionId, '插入定义应拿到自增主键');

        return (int) $definitionId;
    }

    /**
     * 启动流程实例（发起人 = 角色成员，即申请节点处理人）
     */
    private function startInstance(int $definitionId): int
    {
        $args = new Dict();
        $args->putAll(['amount' => 1200, 'applicant_name' => 'Alice']);

        $instance = WorkflowBootstrap::getEngine()->startProcessInstanceById(
            (string) $definitionId,
            (string) self::ROLE_MEMBER_ID,
            $args
        );

        $this->assertNotNull($instance, 'startProcessInstanceById() 应返回实例对象');

        return (int) $instance->getData('id');
    }

    /**
     * 以指定操作人执行一个任务
     */
    private function executeTask(int $taskId, int $operatorId): array
    {
        $dict = new Dict();
        $dict->putAll(['tf_approval_comment' => '同意']);

        return WorkflowBootstrap::getEngine()->executeProcessTask((string) $taskId, (string) $operatorId, $dict);
    }

    /**
     * 取实例当前唯一一条进行中的任务（数据库行为准，不用引擎对象）
     *
     * @return array<string, mixed>
     */
    private function onlyDoingTask(int $instanceId): array
    {
        $rows = Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->whereIn('status', ['pending', 'claimed'])
            ->order('id')
            ->select()
            ->toArray();

        $this->assertCount(1, $rows, '直线流程在同一时刻应只有一条进行中的任务');

        return $rows[0];
    }

    /**
     * 本租户内该测试角色的成员ID（直接从库里查，作为「展开结果」的期望值）
     *
     * @return int[]
     */
    private function roleMemberIds(): array
    {
        $ids = Db::table('user_roles')->where('role_id', self::ROLE_ID)->column('user_id');

        return array_values(array_map('intval', $ids));
    }

    /**
     * 租户 1 内 admin 角色的成员ID（示例定义用例的期望值）
     *
     * @return int[]
     */
    private function adminRoleMemberIds(): array
    {
        $roleId = Db::table('roles')
            ->where('tenant_id', self::TENANT_ID)
            ->where('slug', 'admin')
            ->value('id');

        if ($roleId === null || $roleId === false) {
            return [];
        }

        $userIds = Db::table('user_roles')->where('role_id', (int) $roleId)->column('user_id');

        if ($userIds === []) {
            return [];
        }

        // 与 DefaultTaskActorDirectory::findUserIdsByRoleId() 同口径：只保留本租户的用户
        $scoped = Db::table('users')
            ->where('tenant_id', self::TENANT_ID)
            ->whereIn('id', array_map('intval', $userIds))
            ->order('id', 'asc')
            ->column('id');

        return array_values(array_map('intval', $scoped));
    }

    /**
     * 把迁移声明的 `role:admin` 应用到示例定义的图上（调用迁移自己的 patchGraph）
     *
     * @return array<string, mixed>
     */
    private function patchedSampleGraph(): array
    {
        $graphs = $this->loadMigrationStatics(
            self::GRAPH_MIGRATION_FILE,
            self::GRAPH_MIGRATION_CLASS
        );

        $actorMigration = $this->loadMigrationStatics(
            self::ACTOR_MIGRATION_FILE,
            self::ACTOR_MIGRATION_CLASS
        );

        $patched = $actorMigration::patchGraph($graphs::graphs()[self::SAMPLE_EXPENSE], true);

        $this->assertSame(
            $actorMigration::targets()[self::SAMPLE_EXPENSE],
            $patched['nodes'],
            '迁移声明的目标节点应与示例定义的审批节点一致'
        );

        return $patched['graph'];
    }

    /**
     * 载入迁移文件并返回其类名（迁移里的静态数组是测试的唯一真相源，不另抄一份）
     *
     * @return class-string
     */
    private function loadMigrationStatics(string $relativeFile, string $className): string
    {
        $file = $this->app()->getRootPath() . $relativeFile;

        $this->assertFileExists($file, '缺少迁移文件：' . $relativeFile);

        if (!class_exists($className, false)) {
            require_once $file;
        }

        $this->assertTrue(class_exists($className, false), '迁移类未加载：' . $className);

        return $className;
    }

    /**
     * 测试专用 LogicFlow 图
     *
     * start → apply(task) → review_by_role(task) → assigned_by_user(task) → end
     *
     * @return array<string, mixed>
     */
    private function actorGraph(): array
    {
        $nodes = [
            $this->node('start', 'start', 200, 160, '开始'),
            $this->node('apply', 'task', 420, 160, '填写申请'),
            $this->node('review_by_role', 'task', 640, 160, '角色审批', [
                // 引擎读的键是 candidate_ext_groups（INodeParser::EXT_FIELD_CANDIDATE_GROUPS_KEY）
                'candidate_ext_groups' => ['role:' . self::ROLE_SLUG],
            ]),
            $this->node('assigned_by_user', 'task', 860, 160, '指定人审批', [
                'assignee'            => 'user:' . self::OUTSIDER_ID,
                'candidate_ext_users' => [(string) self::ROLE_MEMBER_ID],
            ]),
            $this->node('end', 'end', 1080, 160, '结束'),
        ];

        $edges = [
            $this->edge('flow_start_apply', 'start', 'apply'),
            $this->edge('flow_apply_review_by_role', 'apply', 'review_by_role'),
            $this->edge('flow_review_by_role_assigned_by_user', 'review_by_role', 'assigned_by_user'),
            $this->edge('flow_assigned_by_user_end', 'assigned_by_user', 'end'),
        ];

        // 边必须带 pointsList（引擎读的是它；startPoint/endPoint 会在 stdClass 上崩）
        $points = [];

        foreach ($nodes as $node) {
            $points[$node['id']] = ['x' => $node['x'], 'y' => $node['y']];
        }

        foreach ($edges as $index => $edge) {
            $edges[$index]['pointsList'] = [
                $points[$edge['sourceNodeId']],
                $points[$edge['targetNodeId']],
            ];
        }

        return [
            'name'         => self::DEFINITION_NAME,
            'display_name' => '参与者解析 E2E 流程',
            'type'         => 'approval',
            'nodes'        => $nodes,
            'edges'        => $edges,
        ];
    }

    /**
     * 构造 LogicFlow 节点
     *
     * @param array<string, mixed> $extraProperties 额外的节点属性（参与者配置写这里）
     * @return array<string, mixed>
     */
    private function node(string $id, string $parserKey, int $x, int $y, string $displayName, array $extraProperties = []): array
    {
        return [
            'id'         => $id,
            'type'       => 'ingenious:' . $parserKey,
            'x'          => $x,
            'y'          => $y,
            'text'       => ['value' => $displayName],
            'properties' => array_merge([
                '_alkaid_node_class' => match ($parserKey) {
                    'start'    => 'StartNode',
                    'end'      => 'EndNode',
                    default    => 'TaskNode',
                },
                '_alkaid_node_type'  => $parserKey,
            ], $extraProperties),
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
}
