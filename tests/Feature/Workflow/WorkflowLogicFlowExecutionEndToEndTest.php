<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\Integration\WorkflowBootstrap;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceHistoryService;
use Alkaid\Workflow\Engine\parser\ModelParser;
use madong\helper\Dict;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流定义「可执行」端到端测试（P1 验收）
 *
 * ## 这条测试要证明什么
 *
 * 定义体唯一执行格式是 **LogicFlow JSON**（存在 `workflow_definitions.nodes_config`）。
 * 只要这份 JSON 缺 `nodes` / `edges` / 节点 `type` / 连线，引擎就会在
 * `ModelParser::parse()` 处**静默返回空模型**（src/parser/ModelParser.php:67-69），
 * 故障延迟到 `$processModel->getStart()->execute()` 才以
 * 「Call to a member function on null」暴露 —— 这正是两条示例定义此前的状态。
 *
 * 因此这里不做「数组里有 nodes/edges 就算过」的弱断言，而是**真跑执行链**：
 *
 *   1. 拿迁移写入的那份图 → `ModelParser::parse()` 得到**非空** ProcessModel；
 *   2. `startProcessInstanceById()` 启动实例 → 实例行真的插入、状态 running；
 *   3. 第一个任务真的生成（节点 apply）；
 *   4. `executeProcessTask()` 逐节点推进 → 下一个任务真的生成、实例变量与状态真的变化；
 *   5. 走到 end 后实例 status = completed；
 *   6. 跨租户读同一实例返回 null（多租户红线）。
 *
 * 另外两条用例补齐「为什么必须修」与「决策边真的靠 expr 走」：
 *   - 旧形态（`{start:{id,name},...}` 节点表）必须**明确报错**而不是产出空模型；
 *   - 决策节点的两条出边必须靠 `properties.expr` 分派（引擎只读这个键）。
 *
 * ## 历史服务：本测试**不注入任何替身**
 *
 * `startProcessInstanceById()` 第 3.1 步会写历史流程实例，但本仓库**还没有**历史服务：
 * `IProcessInstanceHistoryService` 是空接口（方法声明全被注释），也没有历史表与实现类。
 * 此前的写法是往容器里注入一个「记录型历史服务」替身来「让引擎跑起来」——
 * 那证明的是替身可用，不是生产路径可用：
 * 生产路径下该服务为 null，第 3.1 步直接
 * `Error: Call to a member function createHistoryProcessInstance() on null`（ProcessEngines.php:72）。
 * 替身已于本包删除（`tests/Feature/Workflow/Support/RecordingProcessInstanceHistoryService.php`）。
 *
 * 现在的口径：引擎把历史写入当**审计旁路**，服务缺失时跳过并记一条日志，主流程照常推进。
 * 因此本测试**注入零个依赖**（`service_dependencies` 都不传），跑的就是生产路径；
 * {@see testStartInstancePersistsRunningInstanceAndFirstTask()} 里还额外断言
 * 「容器里确实没有历史服务」—— 缺它也能起流程，正是本包的修复口径。
 *
 * ## 数据从哪来（测试库的现实）
 *
 * 测试库 `alkaid_sys_test` 里**没有**这两条示例定义：`20251216000003` 在全新库执行时
 * 租户 1 尚不存在，整个示例数据块被 `tenantExists()` 跳过（`db: workflow_definitions` 为空）。
 * 因此定义行由本测试按迁移的 `graphs()` 自行插入并清理。也就是说：本测试断言的是
 * 「**迁移写入的那份图**可执行」，而「迁移是否真的执行过」由
 * `php think migrate:run` + `migrate:status` + 库内 `nodes_config` 快照来验证（见交付说明）。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowLogicFlowExecutionEndToEndTest extends ThinkPHPTestCase
{
    /** 示例定义所属租户（与 20251216000003 / 20251228000001 的 TENANT_ID 一致） */
    private const TENANT_ID = 1;

    /** 另一个租户（测试库里确实存在 id=2 的 Test Tenant Two），用于跨租户隔离断言 */
    private const OTHER_TENANT_ID = 2;

    /** 引擎的系统身份：能通过 ProcessTaskService::isAllowed()，且落库时 created_by 记 NULL（不会撞 users 外键） */
    private const OPERATOR = 'flow_auto';

    /** 示例定义名（与种子数据一致） */
    private const EXPENSE = 'expense_reimbursement';
    private const LEAVE = 'leave_application';

    /** 测试自建的决策分支定义名（不属于种子数据，只用来验证 properties.expr 分派） */
    private const AMOUNT_GATE = 'e2e_amount_gate';

    /** 迁移文件（`graphs()` 与 `legacyNodesConfig()` 的唯一真相源） */
    private const MIGRATION_FILE = 'database/migrations/20251228000001_add_executable_logicflow_to_sample_definitions.php';

    /** 迁移类名（require 之后用它取图） */
    private const MIGRATION_CLASS = 'AddExecutableLogicFlowToSampleDefinitions';

    protected function setUp(): void
    {
        parent::setUp();

        // 上一轮残留（含曾经失败留下的半成品实例）先清掉，保证 setUp → 用例 的前提成立
        $this->purgeFixtures();

        // 引擎与租户上下文都是静态状态：每个用例从干净状态起步
        WorkflowBootstrap::reset();
        TenantContext::clearAll();

        // 只给租户上下文，**不注入任何服务替身**：
        // 本测试要证明的是「生产路径（容器按默认配置装配）能跑通启动链」，
        // 注入 `service_dependencies` 就不再是生产路径了。
        WorkflowBootstrap::initialize([
            'extension_parameters' => ['tenant_id' => self::TENANT_ID],
        ]);
        TenantContext::setTenantId(self::TENANT_ID);
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();

        TenantContext::clearAll();
        WorkflowBootstrap::reset();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1) 图 → 模型
    // -----------------------------------------------------------------

    /**
     * 迁移写入的两条示例定义必须是**引擎可解析**的 LogicFlow 图
     *
     * 断言口径与生产路径一致：走 `ProcessDefineService::processDefineToModel()`，
     * 它内部就是 `ModelParser::parse()`，并在「解析后没有节点」时抛异常
     * （src/Services/ProcessDefineService.php 的 processDefineToModel）。
     */
    public function testSampleDefinitionsProduceExecutableProcessModel(): void
    {
        $graphs = $this->sampleGraphs();

        foreach ([self::EXPENSE, self::LEAVE] as $name) {
            $definitionId = $this->insertDefinition($name, $name, $graphs[$name]);

            $define = WorkflowBootstrap::getDefineService()->getById((string) $definitionId);
            $this->assertNotNull($define, "定义 {$name} 应能从库里读回");

            // hasEngineReadableContent() 只认 {nodes, edges} 两个数组键（ProcessDefineEntity）
            $model = WorkflowBootstrap::getDefineService()->processDefineToModel($define);

            $this->assertNotNull($model, "定义 {$name} 应能转成 ProcessModel");
            $this->assertGreaterThan(0, count($model->getNodes()), "定义 {$name} 解析后不得是空模型");

            // 5 个节点：start / 3 个 task / end
            $this->assertCount(5, $model->getNodes(), "定义 {$name} 应有 5 个节点模型");
            $this->assertCount(3, $model->getTasks(), "定义 {$name} 应有 3 个人工任务节点");

            // 引擎必须能定位开始节点，否则 startProcessInstanceById() 会在 getStart() 处崩
            $start = $model->getStart();
            $this->assertNotNull($start, "定义 {$name} 必须能定位 start 节点");
            $this->assertSame('start', $start->getName(), '引擎侧节点名就是 LogicFlow 节点 id');

            // 按节点 id 取得节点模型：这是引擎 executeProcessTask() 回查节点的方式
            // （task 表的 node_name 存的就是节点 id，见 ProcessTaskService::buildTaskColumns）
            $this->assertNotNull($model->getNode('review_manager'), "定义 {$name} 必须能按 id 找回 review_manager 节点");
        }

        // 直接对 JSON 再解析一次，确认「图的节点类型全都是引擎注册的解析器键」
        // —— 未注册类型会被 ModelParser 静默丢弃，节点数就会少
        $explicit = ModelParser::parse((string) json_encode($graphs[self::EXPENSE], JSON_UNESCAPED_UNICODE));
        $this->assertSame(
            ['ingenious:start', 'ingenious:task', 'ingenious:task', 'ingenious:task', 'ingenious:end'],
            array_column($graphs[self::EXPENSE]['nodes'], 'type'),
            '示例定义的节点类型必须是引擎解析器键前缀形式'
        );
        $this->assertCount(5, $explicit->getNodes(), '直接解析 JSON 也必须得到 5 个节点模型');
    }

    /**
     * 旧形态（节点表，无 nodes/edges/type/连线）必须是**明确报错**，不能是「看起来能跑的空模型」
     *
     * 这条用例把「为什么要新增迁移」钉成回归：`20251216000003` 写的那份数据
     * 若再被谁写回库，就会在这里立刻变红，而不是等到线上起不来流程。
     */
    public function testLegacyNodesConfigIsRejectedInsteadOfSilentlyEmptyModel(): void
    {
        $graphs = $this->sampleGraphs();
        $definitionId = $this->insertDefinition(self::EXPENSE, self::EXPENSE, $graphs[self::EXPENSE]);

        // 把 nodes_config 改回旧形态
        Db::table('workflow_definitions')->where('id', $definitionId)->update([
            'nodes_config' => json_encode($this->legacyNodesConfig()[self::EXPENSE], JSON_UNESCAPED_UNICODE),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $define = WorkflowBootstrap::getDefineService()->getById((string) $definitionId);
        $this->assertNotNull($define);

        // 具体异常文案由 ProcessDefineEntity / ProcessDefineService 决定，这里只断言「明确失败」
        $this->expectException(LFlowException::class);
        WorkflowBootstrap::getDefineService()->processDefineToModel($define);
    }

    // -----------------------------------------------------------------
    // 2) 启动实例
    // -----------------------------------------------------------------

    /**
     * 启动实例：实例行真的落库、状态 running、第一个任务真的生成
     */
    public function testStartInstancePersistsRunningInstanceAndFirstTask(): void
    {
        $graphs = $this->sampleGraphs();
        $definitionId = $this->insertDefinition(self::EXPENSE, self::EXPENSE, $graphs[self::EXPENSE]);

        $variables = [
            'applicant_name' => '张三',
            'amount'         => 1200,
            'description'    => '出差住宿',
            'category'       => 'travel',
        ];

        $instance = $this->startInstance($definitionId, $variables);
        $instanceId = (int) $instance->getData('id');

        $this->assertGreaterThan(0, $instanceId, '启动后应拿到实例主键');

        // ---- 实例行 ----
        $row = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($row, 'workflow_instances 必须真的插入实例行');
        $this->assertSame($definitionId, (int) $row['definition_id'], '实例必须指向启动它的定义');
        $this->assertSame(self::TENANT_ID, (int) $row['tenant_id'], '实例必须落在当前租户下');
        $this->assertSame('running', (string) $row['status'], '刚启动的实例状态应为 running');
        $this->assertNotEmpty($row['started_at'], 'started_at 必须写入');

        // 启动参数即实例变量（ProcessInstanceService::createProcessInstance 直接落 variables 列）
        $storedVariables = json_decode((string) $row['variables'], true);
        $this->assertIsArray($storedVariables, 'variables 应是 JSON 对象');
        $this->assertSame(1200, $storedVariables['amount'] ?? null, '启动参数 amount 必须进实例变量');

        // ---- 第一个任务 ----
        $task = $this->onlyPendingTask($instanceId);
        $this->assertIsArray($task, 'start 节点执行后必须生成第一个任务');
        $this->assertSame('apply', (string) $task['node_name'], '第一个任务应落在 apply 节点（原定义「填写申请」）');
        $this->assertSame('pending', (string) $task['status'], '示例定义未指派参与者，任务应为 pending');
        $this->assertSame('user_task', (string) $task['task_type'], '人工任务类型应为 user_task');

        // ---- 生产路径自证：容器里根本没有历史服务实现，流程照样起来了 ----
        //
        // 本仓库还没有历史表与实现类（见类注释），`service_dependencies` 也没注入任何东西，
        // 所以这里取到的必然是 null。断言它，是为了把「缺历史服务也能启动」这件事钉住：
        // 引擎的 3.1 步必须走守卫分支（跳过 + 记日志），而不是 `... on null`。
        // 将来补上真实实现（含建表迁移）后，这条断言应改成「历史行已落库」。
        $this->assertNull(
            ServiceContext::find(IProcessInstanceHistoryService::class),
            '生产路径下容器不应提供历史服务实现；本用例证明的正是「缺它也能启动」'
        );

        // 说明（已知缺口，不作断言）：workflow_instances.current_node_id / current_node_name
        // 由 createProcessInstance() 初始化为 NULL，且引擎执行链里**没有任何代码**回写它们，
        // 因此「当前节点」只能从待办任务的 node_name 读，不能从实例列读。
    }

    // -----------------------------------------------------------------
    // 3) 执行任务：状态推进 + 下一节点任务生成
    // -----------------------------------------------------------------

    /**
     * 逐节点执行任务：每一步都断言「任务完成 + 下一节点任务生成 + 链路正确」，直到实例 completed
     */
    public function testExecuteTasksAdvancesInstanceNodeByNodeUntilCompleted(): void
    {
        $graphs = $this->sampleGraphs();
        $definitionId = $this->insertDefinition(self::EXPENSE, self::EXPENSE, $graphs[self::EXPENSE]);

        $instance = $this->startInstance($definitionId, ['amount' => 1200]);
        $instanceId = (int) $instance->getData('id');

        // 原 bpmn_xml 的直线链：apply → review_manager → review_finance → end
        $expectedChain = ['apply', 'review_manager', 'review_finance'];

        foreach ($expectedChain as $index => $expectedNode) {
            $task = $this->onlyPendingTask($instanceId);
            $this->assertIsArray($task, "第 {$index} 步：应存在待办任务（期望节点 {$expectedNode}）");
            $this->assertSame($expectedNode, (string) $task['node_name'], "第 {$index} 步的待办任务节点应为 {$expectedNode}");

            $finishedTaskId = (int) $task['id'];
            $this->executeTask($finishedTaskId, ['tf_approval_comment' => '同意']);

            // 上一个任务必须变成 completed
            $finished = Db::table('workflow_tasks')->where('id', $finishedTaskId)->find();
            $this->assertIsArray($finished);
            $this->assertSame('completed', (string) $finished['status'], "第 {$index} 步：已执行任务应变为 completed");
            $this->assertNotEmpty($finished['completed_at'], "第 {$index} 步：任务 completed_at 必须写入");

            // 实例仍应是运行中（还有后续节点，或已结束 —— 由下一步断言区分）
            $instanceRow = Db::table('workflow_instances')->where('id', $instanceId)->find();
            $this->assertIsArray($instanceRow);

            if ($index < count($expectedChain) - 1) {
                $this->assertSame('running', (string) $instanceRow['status'], "第 {$index} 步后实例应仍在运行中");
                $nextTask = $this->onlyPendingTask($instanceId);
                $this->assertIsArray($nextTask, "第 {$index} 步后必须生成下一节点任务");
                $this->assertSame(
                    $expectedChain[$index + 1],
                    (string) $nextTask['node_name'],
                    "第 {$index} 步后应推进到节点 {$expectedChain[$index + 1]}"
                );
            }
        }

        // 最后一个任务推进到 end：实例必须完成
        $finalRow = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($finalRow);
        $this->assertSame('completed', (string) $finalRow['status'], '走到 end 后实例状态应为 completed');
        $this->assertNotEmpty($finalRow['completed_at'], 'completed_at 必须写入');
        $this->assertNull($this->onlyPendingTask($instanceId), '流程结束后不得再有待办任务');

        // 全流程恰好 3 个人工任务（apply / review_manager / review_finance）
        $taskNodes = Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->order('id')
            ->column('node_name');
        $this->assertSame($expectedChain, $taskNodes, '实例的任务节点序列应与原 bpmn_xml 的 userTask 顺序一致');
    }

    // -----------------------------------------------------------------
    // 4) 决策节点：边上 properties.expr 真的决定走向
    // -----------------------------------------------------------------

    /**
     * 决策节点的两条出边靠 `properties.expr` 分派（引擎只读这个键）
     *
     * 图是**测试自建**的，不写进种子数据：原 `bpmn_xml` 里没有任何网关，
     * 给示例定义凭空加分支等于改写已发布定义的业务语义（见迁移文件头注释）。
     * 这里单独造一条 mini 流程，专门验证 DecisionModel 的分派能力：
     *
     *     start → apply(task) → amount_check(decision) ─┬─ expr: $amount >= 1000 → review_finance(task) → end
     *                                                   └─ expr: $amount <  1000 → end
     */
    public function testDecisionNodeRoutesByEdgeExpression(): void
    {
        $definitionId = $this->insertDefinition(
            self::AMOUNT_GATE,
            '金额分支验证流程',
            $this->amountGateGraph()
        );

        // ---- 大额：必须经过 review_finance ----
        $large = $this->startInstance($definitionId, ['amount' => 2500]);
        $largeId = (int) $large->getData('id');

        $task = $this->onlyPendingTask($largeId);
        $this->assertIsArray($task);
        $this->assertSame('apply', (string) $task['node_name']);

        $this->executeTask((int) $task['id'], ['tf_approval_comment' => '提交']);

        // decision 由 apply 执行时的输出边驱动，执行完 apply 后应落到 review_finance
        $nextTask = $this->onlyPendingTask($largeId);
        $this->assertIsArray($nextTask, '大额分支应生成财务审批任务');
        $this->assertSame('review_finance', (string) $nextTask['node_name'], 'amount >= 1000 必须走 review_finance 分支');

        $this->executeTask((int) $nextTask['id'], ['tf_approval_comment' => '同意']);
        $largeRow = Db::table('workflow_instances')->where('id', $largeId)->find();
        $this->assertIsArray($largeRow);
        $this->assertSame('completed', (string) $largeRow['status'], '大额分支走完后实例应完成');

        // ---- 小额：必须直接到 end，不得生成财务审批任务 ----
        $small = $this->startInstance($definitionId, ['amount' => 200]);
        $smallId = (int) $small->getData('id');

        $task = $this->onlyPendingTask($smallId);
        $this->assertIsArray($task);
        $this->assertSame('apply', (string) $task['node_name']);

        $this->executeTask((int) $task['id'], ['tf_approval_comment' => '提交']);

        $this->assertNull($this->onlyPendingTask($smallId), 'amount < 1000 不应生成任何审批任务（直接到 end）');
        $smallRow = Db::table('workflow_instances')->where('id', $smallId)->find();
        $this->assertIsArray($smallRow);
        $this->assertSame('completed', (string) $smallRow['status'], '小额分支应直接结束');

        $smallNodes = Db::table('workflow_tasks')->where('instance_id', $smallId)->column('node_name');
        $this->assertSame(['apply'], $smallNodes, '小额分支只应有 apply 一个任务');
    }

    // -----------------------------------------------------------------
    // 5) 多租户隔离
    // -----------------------------------------------------------------

    /**
     * 跨租户读同一实例必须拿不到（fail-closed，不是「读到了但看不见」）
     */
    public function testInstanceIsInvisibleFromAnotherTenant(): void
    {
        $graphs = $this->sampleGraphs();
        $definitionId = $this->insertDefinition(self::EXPENSE, self::EXPENSE, $graphs[self::EXPENSE]);

        $instance = $this->startInstance($definitionId, ['amount' => 800]);
        $instanceId = (int) $instance->getData('id');

        $instanceService = WorkflowBootstrap::getInstanceService();

        // 本租户能读到
        $this->assertNotNull(
            $instanceService->getById((string) $instanceId),
            '本租户应能读到自己的实例'
        );

        // 换租户：读不到（TenantScopeManager 强制 where tenant_id = ?）
        TenantContext::setTenantId(self::OTHER_TENANT_ID);
        $this->assertNull(
            $instanceService->getById((string) $instanceId),
            '跨租户读取实例必须返回 null（租户隔离红线）'
        );

        // 任务侧同样隔离
        $task = Db::table('workflow_tasks')->where('instance_id', $instanceId)->find();
        $this->assertIsArray($task);
        $this->assertNull(
            WorkflowBootstrap::getTaskService()->getById((string) $task['id']),
            '跨租户读取任务必须返回 null'
        );

        // 切回本租户后又能读到，证明确实是「按租户过滤」而不是实例被删了
        TenantContext::setTenantId(self::TENANT_ID);
        $this->assertNotNull(
            $instanceService->getById((string) $instanceId),
            '切回原租户后应重新可读'
        );
    }

    // -----------------------------------------------------------------
    // 辅助方法
    // -----------------------------------------------------------------

    /**
     * 读取迁移里的两份示例图（迁移文件是唯一真相源，测试不抄一份）
     *
     * @return array<string, array<string, mixed>>
     */
    private function sampleGraphs(): array
    {
        $file = $this->app()->getRootPath() . self::MIGRATION_FILE;

        $this->assertFileExists($file, '缺少示例定义迁移文件：' . self::MIGRATION_FILE);

        if (!class_exists(self::MIGRATION_CLASS, false)) {
            require_once $file;
        }

        $class = self::MIGRATION_CLASS;
        $this->assertTrue(class_exists($class, false), '迁移类未加载：' . $class);

        return $class::graphs();
    }

    /**
     * 读取迁移里的原始节点表（旧形态，用于「必须报错」的回归）
     *
     * @return array<string, array<string, array{id: string, name: string}>>
     */
    private function legacyNodesConfig(): array
    {
        $this->sampleGraphs();

        $class = self::MIGRATION_CLASS;

        return $class::legacyNodesConfig();
    }

    /**
     * 插入一条定义（幂等：先删同名行，避免命中 uk_tenant_name_version）
     *
     * @param string $name        定义名
     * @param string $displayName 显示名
     * @param array  $graph       LogicFlow 图
     * @return int 定义主键
     */
    private function insertDefinition(string $name, string $displayName, array $graph): int
    {
        $now = date('Y-m-d H:i:s');

        $definitionId = Db::table('workflow_definitions')->insertGetId([
            'tenant_id'        => self::TENANT_ID,
            'name'             => $name,
            'display_name'     => $displayName,
            'description'      => 'E2E 测试定义（用例结束后删除）',
            'type'             => 'approval',
            'category'         => 'finance',
            'icon'             => 'e2e-icon',
            'version'          => 1,
            'status'           => 'published',
            'nodes_config'     => json_encode($graph, JSON_UNESCAPED_UNICODE),
            'triggers_config'  => json_encode([], JSON_UNESCAPED_UNICODE),
            'variables_schema'  => json_encode([], JSON_UNESCAPED_UNICODE),
            'is_active'        => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $this->assertGreaterThan(0, (int) $definitionId, '插入定义应拿到自增主键');

        return (int) $definitionId;
    }

    /**
     * 启动流程实例
     *
     * @param int   $definitionId 定义主键
     * @param array $variables    启动参数（即实例变量）
     * @return \Alkaid\Workflow\Engine\interface\model\IProcessInstance
     */
    private function startInstance(int $definitionId, array $variables)
    {
        $args = new Dict();
        $args->putAll($variables);

        $instance = WorkflowBootstrap::getEngine()->startProcessInstanceById(
            (string) $definitionId,
            self::OPERATOR,
            $args
        );

        $this->assertNotNull($instance, 'startProcessInstanceById() 应返回实例对象');

        return $instance;
    }

    /**
     * 执行一个任务（推进流程）
     *
     * @param int   $taskId 任务主键
     * @param array $args   执行参数（审批意见等）
     * @return array 引擎返回的新生成任务列表
     */
    private function executeTask(int $taskId, array $args = []): array
    {
        $dict = new Dict();
        $dict->putAll($args);

        return WorkflowBootstrap::getEngine()->executeProcessTask((string) $taskId, self::OPERATOR, $dict);
    }

    /**
     * 取实例当前唯一的一条待办任务（没有则返回 null）
     *
     * @param int $instanceId 实例主键
     * @return array<string, mixed>|null
     */
    private function onlyPendingTask(int $instanceId): ?array
    {
        $rows = Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->where('status', 'pending')
            ->order('id')
            ->select()
            ->toArray();

        if ($rows === []) {
            return null;
        }

        $this->assertCount(1, $rows, '直线流程在同一时刻应只有一条待办任务');

        return $rows[0];
    }

    /**
     * 决策分支验证用的 mini 图（不是种子数据）
     *
     * 只有它带 `properties.expr`：引擎 `AbstractINodeParser` 把边上的 `expr` 读进
     * `TransitionModel`，`DecisionModel::exec()` 再用 `Expression::eval()` 逐条判断，
     * 表达式里的变量来自实例变量（`extract((array) $dict)`）。
     *
     * @return array<string, mixed>
     */
    private function amountGateGraph(): array
    {
        return [
            'name'         => self::AMOUNT_GATE,
            'display_name' => '金额分支验证流程',
            'type'         => 'approval',
            'nodes'        => [
                $this->node('start', 'start', 200, 160, '开始'),
                $this->node('apply', 'task', 420, 160, '填写报销申请'),
                $this->node('amount_check', 'decision', 640, 160, '金额判断'),
                $this->node('review_finance', 'task', 860, 160, '财务审批'),
                $this->node('end', 'end', 1080, 160, '结束'),
            ],
            'edges'        => [
                $this->edge('flow_start_apply', 'start', 'apply'),
                $this->edge('flow_apply_amount_check', 'apply', 'amount_check'),
                // 两条决策出边：必须带 properties.expr，且互斥
                $this->edge('flow_amount_large', 'amount_check', 'review_finance', '$amount >= 1000'),
                $this->edge('flow_amount_small', 'amount_check', 'end', '$amount < 1000'),
                $this->edge('flow_review_finance_end', 'review_finance', 'end'),
            ],
        ];
    }

    /**
     * 构造 LogicFlow 节点
     *
     * @param string $id          节点 id（即引擎侧节点名）
     * @param string $parserKey   解析器键
     * @param int    $x           横坐标
     * @param int    $y           纵坐标
     * @param string $displayName 显示名
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
                    'start'    => 'StartNode',
                    'end'      => 'EndNode',
                    'decision' => 'GatewayNode',
                    default    => 'TaskNode',
                },
                '_alkaid_node_type'  => $parserKey === 'decision' ? 'gateway' : $parserKey,
            ],
        ];
    }

    /**
     * 构造 LogicFlow 边
     *
     * @param string      $id     边 id
     * @param string      $source 源节点 id
     * @param string      $target 目标节点 id
     * @param string|null $expr   条件表达式（决策出边才需要）
     * @return array<string, mixed>
     */
    private function edge(string $id, string $source, string $target, ?string $expr = null): array
    {
        $properties = ['_alkaid_type' => 'sequence'];

        if ($expr !== null) {
            // 引擎真正读的键是 expr（INodeParser::EXPR_KEY）；condition 是主应用语义键，一并写
            $properties['expr']      = $expr;
            $properties['condition'] = $expr;
        }

        return [
            'id'           => $id,
            'type'         => 'polyline',
            'sourceNodeId' => $source,
            'targetNodeId' => $target,
            'text'         => ['value' => $expr ?? ''],
            'properties'   => $properties,
        ];
    }

    /**
     * 清理本测试建的定义（实例与任务经外键 ON DELETE CASCADE 一并删除）
     */
    private function purgeFixtures(): void
    {
        $names = [self::EXPENSE, self::LEAVE, self::AMOUNT_GATE];

        $definitionIds = Db::table('workflow_definitions')
            ->whereIn('name', $names)
            ->column('id');

        if ($definitionIds !== []) {
            // 先按实例删任务，避免依赖各库是否真的建了 CASCADE
            $instanceIds = Db::table('workflow_instances')->whereIn('definition_id', $definitionIds)->column('id');

            if ($instanceIds !== []) {
                Db::table('workflow_tasks')->whereIn('instance_id', $instanceIds)->delete();
                Db::table('workflow_event_logs')->whereIn('instance_id', $instanceIds)->delete();
                Db::table('workflow_instances')->whereIn('id', $instanceIds)->delete();
            }

            Db::table('workflow_definitions')->whereIn('id', $definitionIds)->delete();
        }
    }
}
