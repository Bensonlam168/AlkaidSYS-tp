<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Alkaid\Workflow\Engine\Core\ProcessEngines;
use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use app\middleware\Auth;
use app\middleware\WorkflowTenantContext;
use Closure;
use Infrastructure\Workflow\WorkflowEngineBootstrap;
use madong\helper\Dict;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tests\ThinkPHPTestCase;
use think\facade\Db;
use think\facade\Log;
use think\facade\Route;
use think\Response;

/**
 * 工作流引擎「真正装进主应用」的验收测试
 *
 * ## 这条测试要证明什么
 *
 * 改造前主应用对引擎的装配是**零**：`grep -rn "ProcessEngines\|WorkflowContainer\|workflowEngine"
 * app config domain infrastructure` 零命中 —— 引擎代码躺在 `plugins/workflow-engine/` 里，
 * 从未被 `app/` / `config/` / `infrastructure/` 加载过。四个工作流控制器是内存 Mock，
 * 连一次 `Db::` 都没真正调用过。
 *
 * 本测试断言的是**宿主装配后的生产路径**，而不是「注入替身能跑」：
 *
 *   1. `Infrastructure\Workflow\WorkflowEngineBootstrap` 能从宿主 `config/workflow.php` 装配引擎，
 *      且**每进程只构造一次**（Swoole 常驻进程下不能每请求重建）；
 *   2. `app\middleware\WorkflowTenantContext` 在请求边界把 `app\Request::tenantId()/userId()`
 *      写进引擎的 `TenantContext`，并在**请求结束（含异常）后清理**；
 *   3. 用迁移写入的种子定义 `expense_reimbursement` 真跑一次启动链：
 *      实例落库（tenant_id / status / variables）、第一个任务生成、启动事件进轨迹表；
 *   4. **未设租户上下文时引擎调用抛 TenantIsolationException**（fail-closed 证据）；
 *   5. **跨请求不残留**：请求 A（租户 1）→ 请求 B（无租户）→ B 抛异常，而不是读到租户 1 的数据；
 *   6. **协程并发不串**：两个协程分别以租户 1/2 调引擎，各自只看到自己的数据；
 *   7. 配置层两处硬伤：`logging.channel='workflow'` 在 `config/log.php` 里真实存在；
 *      引擎**不拼**自己的 `database.prefix`（前缀由连接配置决定）；
 *   8. 中间件真的挂在路由上，且排在 `Auth` **之后**（JWT 的 tenant_id 覆盖默认租户在 Auth 内）。
 *
 * ## 数据从哪来（测试库的现实）
 *
 * 测试库 `alkaid_sys_test` 里 `workflow_definitions` 是**空表**：种子迁移 `20251216000003`
 * 在全新库执行时租户 1 尚不存在，整个示例数据块被 `tenantExists()` 跳过。
 * 因此本测试按迁移 `20251228000001` 的 `graphs()` 自行插入 `expense_reimbursement`
 * （与 `WorkflowLogicFlowExecutionEndToEndTest` 同一做法：迁移文件是唯一真相源，测试不另抄一份图）。
 * 断言的口径因此是「**迁移写入的那份种子定义**在宿主装配下真的能跑」。
 *
 * ## 为什么协程用例里有一把 Channel 互斥锁
 *
 * 本测试直接 `Swoole\Coroutine\run()`，**不经过** `think-swoole` 的请求沙箱与连接池。
 * 在这种裸协程环境里同时用 `think\facade\Db` 会互相踩：实测两个协程并发查同一张表时，
 * 一个协程抛 `ErrorException: Undefined array key "field"`，另一个拿到错误的计数（0）。
 * 那不是本测试要考察的对象（本测试考察的是 `TenantContext` 静态容器会不会串租户），
 * 因此用一把 `Channel(1)` 把「引擎调用」串行化，同时保留 `Coroutine::sleep()` 让
 * **上下文读写**在两个协程之间真实交错 —— 泄漏恰恰只会发生在交错点。
 * 生产环境里 Db 的协程安全由 think-swoole 的连接池负责，不是本包的范围。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowEngineHostWiringTest extends ThinkPHPTestCase
{
    /** 示例定义所属租户（与种子迁移的 TENANT_ID 一致） */
    private const TENANT_A = 1;

    /** 第二个租户（测试库里确实存在 id=2 的 Test Tenant Two） */
    private const TENANT_B = 2;

    /** 真实存在的用户（测试库 users.id=1，属于租户 1）——用于验证用户上下文桥接 */
    private const USER_ID = 1;

    /** 示例定义名（与种子迁移一致） */
    private const EXPENSE = 'expense_reimbursement';

    /** 租户 B 用的同名定义（避免命中 uk_tenant_name_version；名字带前缀便于清理） */
    private const EXPENSE_TENANT_B = 'host_wiring_t2_expense';

    /** 迁移文件与类名（图与节点表的唯一真相源） */
    private const MIGRATION_FILE = 'database/migrations/20251228000001_add_executable_logicflow_to_sample_definitions.php';
    private const MIGRATION_CLASS = 'AddExecutableLogicFlowToSampleDefinitions';

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeFixtures();

        // 引擎侧全是**静态**状态（TenantContext / ServiceContext / 插件侧静态适配器），
        // 每个用例从干净状态起步，避免前序测试类留下的容器影响结论。
        TenantContext::clearAll();
        WorkflowEngineBootstrap::reset();
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();

        TenantContext::clearAll();
        WorkflowEngineBootstrap::reset();

        parent::tearDown();
    }

    // =====================================================================
    // 1) 装配：每进程只构造一次
    // =====================================================================

    /**
     * 引导器必须「延迟构造 + 构造后复用」
     *
     * Swoole 常驻进程下，`think-swoole` 的 Sandbox 每个请求都会 clone 一份 App（含容器），
     * 因此把引擎放进容器 instances 只能活一个请求。真正的单例在**静态属性**里，
     * 这条用例断言的正是「同一进程内多次取用拿到的必须是同一个对象」。
     */
    public function testBootstrapBuildsEngineOncePerProcessAndReusesIt(): void
    {
        $bootstrap = WorkflowEngineBootstrap::instance();

        $this->assertFalse(
            $bootstrap->isBuilt(),
            'instance() 不应顺手构造引擎：未使用工作流的请求/命令不该付装配代价'
        );

        $engine = $bootstrap->engine();

        $this->assertInstanceOf(ProcessEngines::class, $engine, '默认实现应为插件自带的 ProcessEngines');
        $this->assertTrue($bootstrap->isBuilt(), 'engine() 之后应标记为已构造');

        $this->assertSame($engine, $bootstrap->engine(), '同一引导器必须复用同一个引擎实例');
        $this->assertSame(
            $engine,
            WorkflowEngineBootstrap::instance()->engine(),
            '单例语义：换一次 instance() 取到的仍是同一个引擎（= 跨请求复用，不重建容器）'
        );
        $this->assertSame(
            $bootstrap->container(),
            WorkflowEngineBootstrap::instance()->container(),
            'DI 容器同样只构造一次'
        );
    }

    /**
     * 宿主配置是唯一权威：引擎配置由它映射而来，且**不带**插件模板里的死配置
     */
    public function testEngineConfigComesFromHostConfigOnly(): void
    {
        $engineConfig = WorkflowEngineBootstrap::instance()->engineConfig();

        $this->assertArrayHasKey('configuration_class', $engineConfig);
        $this->assertArrayHasKey('service_dependencies', $engineConfig);
        $this->assertArrayHasKey('extension_parameters', $engineConfig);

        // 插件模板里的 database.prefix / cache / nodes / task ... 从未进入引擎配置：
        // 引擎源码里一个都不读，带进来只会制造「看起来生效」的假配置。
        $this->assertArrayNotHasKey('database', $engineConfig, '宿主只写差异项，不复制插件模板的 database 段');
        $this->assertArrayNotHasKey('cache', $engineConfig);
        $this->assertArrayNotHasKey('nodes', $engineConfig);

        // 关键：租户兜底默认为 null —— 没有上下文就必须 fail-closed，而不是落到某个租户上
        $this->assertNull(
            $engineConfig['extension_parameters']['tenant_id'],
            'tenant_id 兜底必须为 null，否则 HTTP 侧的「无租户」会被静默解释成「按该租户读写」'
        );
    }

    // =====================================================================
    // 2) 请求边界：写入 + 清理
    // =====================================================================

    /**
     * 中间件必须把宿主请求的租户/用户写进引擎的 TenantContext
     */
    public function testMiddlewareBridgesTenantAndUserIntoEngineContext(): void
    {
        $observed = [];

        $this->throughRequest(self::TENANT_A, self::USER_ID, function () use (&$observed): void {
            $observed = TenantContext::all();
        });

        $this->assertSame(self::TENANT_A, (int) ($observed['tenant_id'] ?? 0), '请求内必须写入租户');
        $this->assertSame(self::USER_ID, (int) ($observed['user_id'] ?? 0), '请求内必须写入用户');
        $this->assertSame('http', (string) ($observed['source'] ?? ''), '上下文应标明来源');
    }

    /**
     * 请求结束后（正常/异常两条路径）必须清空上下文
     *
     * 这是 Swoole 常驻进程下最危险的一处：静态容器不清理，下一个请求就会继承上一个请求的租户。
     */
    public function testTenantContextIsClearedAfterEachRequestIncludingFailurePaths(): void
    {
        // 正常返回
        $this->throughRequest(self::TENANT_A, self::USER_ID, function (): void {
            $this->assertSame(self::TENANT_A, TenantContext::getTenantId());
            $this->assertSame(self::USER_ID, TenantContext::getUserId());
        });

        $this->assertNull(TenantContext::getTenantId(), '正常返回后不得残留租户');
        $this->assertNull(TenantContext::getUserId(), '正常返回后不得残留用户');
        $this->assertSame([], TenantContext::all(), '正常返回后上下文必须为空');

        // 异常路径
        $caught = null;

        try {
            $this->throughRequest(self::TENANT_A, self::USER_ID, static function (): void {
                throw new RuntimeException('下游中间件/控制器抛异常');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught, '异常必须继续向上抛，不能被中间件吞掉');
        $this->assertNull(TenantContext::getTenantId(), '异常路径同样必须清理租户');
        $this->assertNull(TenantContext::getUserId(), '异常路径同样必须清理用户');
    }

    /**
     * 进入中间件时先清一次：即便「上一个请求」没收尾（协程被 kill / 致命错误跳过 finally），
     * 也不能把残留带进本次请求。
     */
    public function testMiddlewareClearsStaleContextOnEntry(): void
    {
        // 手工种一份「上一个请求的残留」
        TenantContext::initialize(999, 888);
        $this->assertSame(999, TenantContext::getTenantId());

        $observed = null;

        $this->throughRequest(self::TENANT_B, null, function () use (&$observed): void {
            $observed = TenantContext::getTenantId();
        });

        $this->assertSame(self::TENANT_B, $observed, '入口必须先清掉残留，再写入本次租户');
        $this->assertNull(TenantContext::getTenantId(), '本次请求结束后仍必须清空');
    }

    // =====================================================================
    // 3) 冒烟：种子定义真跑一次启动链
    // =====================================================================

    /**
     * 用种子定义 `expense_reimbursement` 启动实例：实例落库 + 第一个任务生成 + 启动事件进轨迹
     */
    public function testSeededDefinitionStartsInstanceAndCreatesFirstTask(): void
    {
        $definitionId = $this->seedExpenseDefinition(self::TENANT_A, self::EXPENSE);

        $instanceId = null;
        $taskId     = null;

        $this->throughRequest(self::TENANT_A, self::USER_ID, function () use ($definitionId, &$instanceId, &$taskId): void {
            $args = new Dict();
            $args->putAll([
                'applicant_name' => '张三',
                'amount'         => 1200,
                'description'    => '出差住宿',
                'category'       => 'travel',
            ]);

            $instance = WorkflowEngineBootstrap::instance()->engine()->startProcessInstanceById(
                (string) $definitionId,
                (string) self::USER_ID,
                $args
            );

            $this->assertNotNull($instance, 'startProcessInstanceById() 必须返回实例对象');
            $instanceId = (int) $instance->getData('id');
            $this->assertGreaterThan(0, $instanceId, '启动后必须拿到实例主键');
        });

        // ---- 实例行真的落进库里，且落在当前租户 ----
        $instanceRow = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($instanceRow, 'workflow_instances 必须真的插入实例行（不是内存 Mock）');
        $this->assertSame(self::TENANT_A, (int) $instanceRow['tenant_id'], '实例必须落在请求上下文的租户下');
        $this->assertSame($definitionId, (int) $instanceRow['definition_id']);
        $this->assertSame('running', (string) $instanceRow['status'], '刚启动的实例状态应为 running');

        $variables = json_decode((string) $instanceRow['variables'], true);
        $this->assertIsArray($variables, 'variables 应是 JSON 对象');
        $this->assertSame(1200, $variables['amount'] ?? null, '启动参数必须进实例变量');

        // ---- 第一个任务真的生成 ----
        $tasks = Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->order('id')
            ->select()
            ->toArray();

        $this->assertCount(1, $tasks, 'start 节点执行后必须生成第一个任务');
        $taskId = (int) $tasks[0]['id'];
        $this->assertSame('apply', (string) $tasks[0]['node_name'], '第一个任务应落在 apply 节点');
        $this->assertSame('user_task', (string) $tasks[0]['task_type']);
        $this->assertSame(self::TENANT_A, (int) $tasks[0]['tenant_id'], '任务同样必须带租户');

        // 状态为什么是 claimed 而不是 pending：
        // `ProcessTaskService::buildTaskColumns()` 的口径是「有处理人 = claimed，只有候选人 / 谁都没有 = pending」。
        // 本用例的操作人是**真实用户**（请求里桥接进上下文的那个 user_id），引擎把它解析成处理人，
        // 因此任务一建出来就是 claimed。这条断言同时证明了「请求里的用户身份真的进了引擎」：
        // 若用户上下文没桥接进来（operator 退化成 flow_auto / 0），这里会是 pending + assignee = NULL。
        $this->assertSame('claimed', (string) $tasks[0]['status']);
        $this->assertSame(self::USER_ID, (int) $tasks[0]['assignee'], '任务处理人必须来自请求上下文的用户');

        // ---- 轨迹：启动事件已落库（证明默认装配下历史服务真的就位）----
        $event = Db::table('workflow_event_logs')
            ->where('instance_id', $instanceId)
            ->order('id')
            ->find();
        $this->assertIsArray($event, '启动流程必须留下 instance_started 事件');
        $this->assertSame('instance_started', (string) $event['event_type']);
        $this->assertSame(self::TENANT_A, (int) $event['tenant_id']);
    }

    // =====================================================================
    // 4) fail-closed：没有租户上下文就抛异常
    // =====================================================================

    /**
     * 没有租户上下文时，引擎的任何租户作用域调用都必须抛 TenantIsolationException
     *
     * 这条是「多租户红线」的证据：宁可不做事，也不能按错误的作用域读写。
     */
    public function testEngineCallWithoutTenantContextFailsClosed(): void
    {
        TenantContext::clearAll();
        $this->assertNull(TenantContext::getTenantId());

        $bootstrap = WorkflowEngineBootstrap::instance();

        try {
            $bootstrap->instanceService()->getById('1');
            $this->fail('缺少租户上下文时读实例必须抛 TenantIsolationException，不能静默返回 null');
        } catch (TenantIsolationException $e) {
            $this->assertStringContainsString('读取工作流实例', $e->getMessage(), '异常应带操作名，便于定位');
        }

        // 任务侧同理
        $this->expectException(TenantIsolationException::class);
        $bootstrap->taskService()->getById(1);
    }

    // =====================================================================
    // 5) 跨请求残留实验：A（租户 1）→ B（无租户）
    // =====================================================================

    /**
     * 请求 A（租户 1）启动实例；请求 B（**不设租户**）必须抛异常，
     * 而不是读到租户 1 的数据。
     *
     * 这是本包最关键的安全点：Swoole 复用同一个 worker 进程，
     * 如果 A 的上下文没被清掉，B 就会「以租户 1 的身份」成功读到 A 的数据 ——
     * 那是完全静默的跨租户越权。
     */
    public function testRequestWithoutTenantFailsClosedInsteadOfInheritingPreviousTenant(): void
    {
        $definitionId = $this->seedExpenseDefinition(self::TENANT_A, self::EXPENSE);
        $instanceId   = null;

        // ---- 请求 A：租户 1，正常启动一个实例 ----
        $this->throughRequest(self::TENANT_A, self::USER_ID, function () use ($definitionId, &$instanceId): void {
            $args = new Dict();
            $args->putAll(['amount' => 800]);

            $instance = WorkflowEngineBootstrap::instance()->engine()->startProcessInstanceById(
                (string) $definitionId,
                (string) self::USER_ID,
                $args
            );

            $instanceId = (int) $instance->getData('id');

            // A 确实能看到自己的实例
            $this->assertNotNull(
                WorkflowEngineBootstrap::instance()->instanceService()->getById((string) $instanceId)
            );
        });

        // A 结束：上下文必须已被清空
        $this->assertNull(TenantContext::getTenantId(), '请求 A 结束后不得残留租户');

        // ---- 请求 B：没有租户上下文 ----
        $observedTenant = 'not-observed';
        $caught         = null;

        $this->throughRequest(null, null, function () use (&$observedTenant, &$caught, $instanceId): void {
            $observedTenant = TenantContext::getTenantId();

            try {
                // 若上一请求的上下文没清干净，这一行会「成功」读到租户 1 的实例
                WorkflowEngineBootstrap::instance()->instanceService()->getById((string) $instanceId);
            } catch (\Throwable $e) {
                $caught = $e;
            }
        });

        $this->assertNull($observedTenant, '请求 B 不得继承请求 A 的租户上下文');
        $this->assertInstanceOf(
            TenantIsolationException::class,
            $caught,
            '请求 B 必须 fail-closed 抛异常，而不是读到租户 1 的数据'
        );

        // 数据仍在库里（证明「读不到」是租户隔离生效，不是实例被删了）
        $this->assertIsArray(Db::table('workflow_instances')->where('id', $instanceId)->find());
    }

    // =====================================================================
    // 6) 同进程先后两个租户：互不串
    // =====================================================================

    /**
     * 模拟 Swoole 复用一个 worker：同一进程里先以租户 1、再以租户 2 调引擎，
     * 各自只能看到自己的实例与定义。
     */
    public function testTwoTenantsInOneProcessStayIsolated(): void
    {
        $definitionA = $this->seedExpenseDefinition(self::TENANT_A, self::EXPENSE);
        $definitionB = $this->seedExpenseDefinition(self::TENANT_B, self::EXPENSE_TENANT_B);

        $instanceA = $this->startInstanceInRequest(self::TENANT_A, $definitionA, ['amount' => 100]);
        $instanceB = $this->startInstanceInRequest(self::TENANT_B, $definitionB, ['amount' => 200]);

        $this->assertNotSame($instanceA, $instanceB);

        // 租户 A 视角
        $this->throughRequest(self::TENANT_A, null, function () use ($instanceA, $instanceB, $definitionA, $definitionB): void {
            $this->assertSame(self::TENANT_A, TenantContext::getTenantId());
            $this->assertNotNull(
                WorkflowEngineBootstrap::instance()->instanceService()->getById((string) $instanceA),
                '租户 A 必须能读到自己的实例'
            );
            $this->assertNull(
                WorkflowEngineBootstrap::instance()->instanceService()->getById((string) $instanceB),
                '租户 A 绝不能读到租户 B 的实例'
            );
            $this->assertNotNull(
                WorkflowEngineBootstrap::instance()->defineService()->getById((string) $definitionA)
            );
            $this->assertNull(
                WorkflowEngineBootstrap::instance()->defineService()->getById((string) $definitionB),
                '租户 A 绝不能读到租户 B 的定义'
            );
        });

        // 租户 B 视角
        $this->throughRequest(self::TENANT_B, null, function () use ($instanceA, $instanceB, $definitionA, $definitionB): void {
            $this->assertSame(self::TENANT_B, TenantContext::getTenantId());
            $this->assertNotNull(
                WorkflowEngineBootstrap::instance()->instanceService()->getById((string) $instanceB)
            );
            $this->assertNull(
                WorkflowEngineBootstrap::instance()->instanceService()->getById((string) $instanceA),
                '租户 B 绝不能读到租户 A 的实例'
            );
            $this->assertNull(
                WorkflowEngineBootstrap::instance()->defineService()->getById((string) $definitionA)
            );
            $this->assertNotNull(
                WorkflowEngineBootstrap::instance()->defineService()->getById((string) $definitionB)
            );
        });

        // 落库的租户列必须与请求上下文一致（证明写入侧也没串）
        $this->assertSame(self::TENANT_A, (int) Db::table('workflow_instances')->where('id', $instanceA)->value('tenant_id'));
        $this->assertSame(self::TENANT_B, (int) Db::table('workflow_instances')->where('id', $instanceB)->value('tenant_id'));
    }

    // =====================================================================
    // 7) 协程并发：不串租户
    // =====================================================================

    /**
     * 两个协程分别以租户 1 / 租户 2 调引擎，上下文在交错点不得串
     *
     * 关于那把 `Channel(1)` 互斥锁：本测试绕过了 think-swoole 的连接池，
     * 裸协程里并发用 `think\facade\Db` 会互相踩（实测 `Undefined array key "field"`），
     * 那不是本用例的考察对象。互斥只保护**引擎调用**；上下文的读写仍被 `Coroutine::sleep()`
     * 真实交错开 —— 泄漏只可能发生在交错点上。
     */
    public function testConcurrentCoroutinesKeepTenantScopesApart(): void
    {
        if (!extension_loaded('swoole') || !class_exists(Coroutine::class)) {
            $this->markTestSkipped('未安装 Swoole 扩展：协程隔离实验无法在本环境运行');
        }

        $definitionA = $this->seedExpenseDefinition(self::TENANT_A, self::EXPENSE);
        $definitionB = $this->seedExpenseDefinition(self::TENANT_B, self::EXPENSE_TENANT_B);

        $bootstrap = WorkflowEngineBootstrap::instance();
        $bootstrap->engine(); // 先在协程外完成装配，避免把构造耗时/竞态混进来
        $middleware = new WorkflowTenantContext();
        $mutex      = new Channel(1);

        /** @var array<int, array<string, mixed>> $results 按租户收集结果 */
        $results = [];

        Coroutine\run(function () use ($bootstrap, $middleware, $mutex, $definitionA, $definitionB, &$results): void {
            $cases = [
                self::TENANT_A => [self::TENANT_A, $definitionA, $definitionB],
                self::TENANT_B => [self::TENANT_B, $definitionB, $definitionA],
            ];

            foreach ($cases as $tenantId => [$expectedTenant, $ownDefinition, $otherDefinition]) {
                Coroutine::create(function () use ($bootstrap, $middleware, $mutex, $tenantId, $expectedTenant, $ownDefinition, $otherDefinition, &$results): void {
                    // 断言一律放到协程外：协程里抛出的异常会被 Swoole 直接吞掉/致命化，
                    // 落到 PHPUnit 报告里就是「进程莫名退出」，而不是一条可读的失败。
                    try {
                        $request = $this->makeRequest($tenantId, null);

                        $results[$tenantId] = $middleware->handle($request, function () use ($bootstrap, $mutex, $expectedTenant, $ownDefinition, $otherDefinition): Response {
                            // 交错点 1：让另一个协程跑一段
                            Coroutine::sleep(0.03);
                            $beforeCall = TenantContext::getTenantId();

                            $own = null;
                            $other = null;

                            // 引擎调用串行化（见类注释：裸协程下的 Db 不是本用例的考察对象）
                            $mutex->push(true);
                            try {
                                $own   = $bootstrap->defineService()->getById((string) $ownDefinition) !== null;
                                $other = $bootstrap->defineService()->getById((string) $otherDefinition) !== null;
                            } finally {
                                $mutex->pop();
                            }

                            // 交错点 2：再一次让出，确认上下文仍然属于本协程
                            Coroutine::sleep(0.03);

                            return Response::create([
                                'expected' => $expectedTenant,
                                'before'   => $beforeCall,
                                'after'    => TenantContext::getTenantId(),
                                'own'      => $own,
                                'other'    => $other,
                            ]);
                        });
                    } catch (\Throwable $e) {
                        $results[$tenantId] = $e;
                    }
                });
            }
        });

        $this->assertCount(2, $results, '两个协程都必须跑完');

        foreach ($results as $tenantId => $response) {
            if ($response instanceof \Throwable) {
                $this->fail(sprintf(
                    '协程（租户 %d）内抛出异常：%s: %s',
                    $tenantId,
                    get_class($response),
                    $response->getMessage()
                ));
            }

            $payload = $response->getData();

            $this->assertSame($tenantId, (int) $payload['expected']);
            $this->assertSame(
                $tenantId,
                (int) $payload['before'],
                "协程（租户 {$tenantId}）在交错点之前的上下文不得被另一协程改写"
            );
            $this->assertSame(
                $tenantId,
                (int) $payload['after'],
                "协程（租户 {$tenantId}）在交错点之后的上下文不得被另一协程改写"
            );
            $this->assertTrue((bool) $payload['own'], "租户 {$tenantId} 必须读到自己的定义");
            $this->assertFalse((bool) $payload['other'], "租户 {$tenantId} 绝不能读到另一租户的定义");
        }

        $this->assertNull(TenantContext::getTenantId(), '协程结束后不得残留租户上下文');
    }

    // =====================================================================
    // 8) 配置层硬伤：日志通道
    // =====================================================================

    /**
     * 引擎日志通道必须在 `config/log.php` 里真实声明，且该声明**能被框架实例化**
     *
     * 引擎写入点是硬编码的 `Log::channel('workflow')`；通道缺失时 ThinkPHP **不回落默认通道**，
     * 而是抛 `InvalidArgumentException: Channel [workflow] not found.`，引擎只能降级到
     * `error_log()` —— 流程照跑，但一条日志都进不了应用日志。
     *
     * ⚠️ 不能直接断言 `config('log.channels.workflow')`：`Tests\ThinkPHPTestCase`
     * 会**整份替换** `log` 配置（只留一个 file 通道，且把 path 指向 runtime/log），
     * 因此测试运行时里根本没有生产通道 —— 那是测试基线的选择，不是部署缺陷。
     * 所以这里断言的是**宿主交付的那份配置**：读 `config/log.php` 原文，
     * 再把该通道定义注入当前运行时，真的用它写一条日志（证明定义可用，不只是「键存在」）。
     */
    public function testWorkflowLogChannelIsDeclaredInHostLogConfig(): void
    {
        $channel = WorkflowEngineBootstrap::instance()->config('engine.logging.channel');

        $this->assertSame('workflow', $channel, '宿主配置里的通道名应与引擎硬编码的通道名一致');

        $logConfig = require $this->app()->getRootPath() . 'config/log.php';

        $this->assertIsArray($logConfig);
        $this->assertArrayHasKey(
            (string) $channel,
            $logConfig['channels'],
            "config/log.php 必须声明 channels.{$channel}，否则 Log::channel('{$channel}') 会抛 InvalidArgumentException"
        );

        $channelConfig = $logConfig['channels'][$channel];
        $this->assertSame('File', $channelConfig['type'], '工作流日志应独立成文件，便于单独采集');
        $this->assertTrue((bool) $channelConfig['realtime_write'], 'Swoole 常驻进程下必须实时写入，否则末尾日志易丢');

        // 把宿主声明的通道注入当前运行时配置，再真写一条：定义有误会在这里抛出来
        $runtimeLogConfig = (array) config('log');
        $runtimeLogConfig['channels'][$channel] = $channelConfig;
        $this->app()->config->set($runtimeLogConfig, 'log');

        Log::channel((string) $channel)->info('[workflow-engine] 通道自检（来自宿主装配测试）');

        $this->addToAssertionCount(1);
    }

    // =====================================================================
    // 9) 配置层硬伤：数据库前缀
    // =====================================================================

    /**
     * 引擎**不拼**自己的 `database.prefix`：前缀由 ThinkPHP 连接配置决定
     *
     * 插件模板里写着 `database.prefix = 'alkaid_'`，而库里的表是 `workflow_definitions`（无前缀）。
     * 引擎全部读写走 `Db::name('workflow_definitions')`，连接前缀为空 → 表名就是 `workflow_definitions`。
     * 如果有人把模板里的 `alkaid_` 接到连接配置上，每张表都会去找 `alkaid_workflow_definitions`（不存在）。
     */
    public function testEngineUsesConnectionPrefixInsteadOfPluginPrefix(): void
    {
        $connection = Db::connect();

        $this->assertSame(
            '',
            (string) $connection->getConfig('prefix'),
            '宿主连接前缀必须为空（.env 未设置 DB_PREFIX），与库里的无前缀表名匹配'
        );

        $this->assertSame(
            'workflow_definitions',
            Db::name('workflow_definitions')->getTable(),
            'Db::name() 解析出的表名必须是库里的真实表名（引擎正是这样取表）'
        );

        // 引擎配置里根本没有 database_prefix：插件模板的该键从未被任何代码读取
        $this->assertArrayNotHasKey(
            'database_prefix',
            WorkflowEngineBootstrap::instance()->engineConfig()['extension_parameters'],
            '宿主不会把模板里的 database_prefix 传给引擎（它是个死键）'
        );

        // 反向证据：真的能查到数据（表名解析错的话这里会抛「表不存在」）
        TenantContext::initialize(self::TENANT_A, null);
        try {
            $this->assertIsInt(Db::name('workflow_definitions')->where('tenant_id', self::TENANT_A)->count());
        } finally {
            TenantContext::clear();
        }
    }

    // =====================================================================
    // 10) 中间件真的挂在路由上，且顺序正确
    // =====================================================================

    /**
     * 桥接中间件必须真的挂在工作流路由上，且排在 Auth 之后
     *
     * 顺序不是风格问题：`Auth` 会用 JWT 里的 `tenant_id` 覆盖 `TenantIdentify` 的默认租户，
     * 桥接若跑在它前面，写进 TenantContext 的就是「默认租户」而不是「登录用户的租户」。
     */
    public function testWorkflowRoutesMountTenantBridgeAfterAuth(): void
    {
        $routeFile = $this->app()->getRootPath() . 'route/workflow.php';
        $this->assertFileExists($routeFile);

        include_once $routeFile;

        $checked = 0;

        foreach (Route::getRuleList() as $rule) {
            $rulePath = (string) ($rule['rule'] ?? '');

            if (!str_starts_with($rulePath, 'v1/workflow/')) {
                continue;
            }

            $middleware = array_map(
                static fn ($item) => is_array($item) ? ($item[0] ?? '') : $item,
                (array) ($rule['option']['middleware'] ?? [])
            );

            $this->assertContains(
                WorkflowTenantContext::class,
                $middleware,
                "路由 {$rulePath} 必须挂载 " . WorkflowTenantContext::class
            );

            // webhook 端点没有 Auth（外部回调不可能带 JWT），跳过顺序断言
            if (!str_contains($rulePath, '/webhook/')) {
                $authIndex   = array_search(Auth::class, $middleware, true);
                $bridgeIndex = array_search(WorkflowTenantContext::class, $middleware, true);

                $this->assertNotFalse($authIndex, "路由 {$rulePath} 必须挂载 Auth");
                $this->assertLessThan(
                    $bridgeIndex,
                    $authIndex,
                    "路由 {$rulePath}：Auth 必须排在租户桥接之前（JWT 的 tenant_id 在 Auth 内生效）"
                );
            }

            $checked++;
        }

        $this->assertGreaterThan(0, $checked, '没有扫到任何 v1/workflow/* 规则，路由文件可能没被加载');
    }

    // =====================================================================
    // 辅助方法
    // =====================================================================

    /**
     * 在「请求边界」里执行一段逻辑（直接调用桥接中间件）
     *
     * 为什么直接调中间件而不是发一次完整 HTTP 请求：
     * 本包要验证的是「宿主请求上下文 → 引擎租户上下文」这条桥，
     * 而四个工作流控制器目前还是内存 Mock（真库化是后续包的任务），
     * 走完整 HTTP 只会绕一圈回到 Mock 上，验证不到引擎。
     * 中间件本身是真实实例（不是替身），`$next` 里跑的是**真实引擎调用**。
     *
     * @param int|null $tenantId 租户（null = 不写上下文，模拟无租户请求）
     * @param int|null $userId   用户
     * @param Closure  $body     请求内要执行的逻辑
     */
    private function throughRequest(?int $tenantId, ?int $userId, Closure $body): mixed
    {
        $request = $this->makeRequest($tenantId, $userId);

        return (new WorkflowTenantContext())->handle($request, static function ($request) use ($body): Response {
            $result = $body($request);

            return $result instanceof Response ? $result : Response::create([], 'json');
        });
    }

    /**
     * 造一个等价于「已过 TenantIdentify + Auth」的请求
     *
     * `setTenantId()/setUserId()` 正是这两个中间件写入上下文的方式，
     * 因此这里等价于真实链路里中间件栈之后的状态。
     */
    private function makeRequest(?int $tenantId, ?int $userId): \app\Request
    {
        $request = new \app\Request();

        if ($tenantId !== null) {
            $request->setTenantId($tenantId);
        }

        if ($userId !== null) {
            $request->setUserId($userId);
        }

        return $request;
    }

    /**
     * 在请求边界里启动一个实例并返回主键
     *
     * @param int   $tenantId     租户
     * @param int   $definitionId 定义主键
     * @param array $variables    启动参数
     */
    private function startInstanceInRequest(int $tenantId, int $definitionId, array $variables): int
    {
        $instanceId = null;

        $this->throughRequest($tenantId, null, function () use ($definitionId, $variables, &$instanceId): void {
            $args = new Dict();
            $args->putAll($variables);

            $instance = WorkflowEngineBootstrap::instance()->engine()->startProcessInstanceById(
                (string) $definitionId,
                (string) self::USER_ID,
                $args
            );

            $this->assertNotNull($instance);
            $instanceId = (int) $instance->getData('id');
            $this->assertGreaterThan(0, $instanceId);
        });

        return (int) $instanceId;
    }

    /**
     * 插入一条 `expense_reimbursement` 定义（图来自迁移文件，测试不另抄一份）
     *
     * @param int    $tenantId 租户
     * @param string $name     定义名
     * @return int 定义主键
     */
    private function seedExpenseDefinition(int $tenantId, string $name): int
    {
        $graphs = $this->sampleGraphs();
        $graph  = $graphs[self::EXPENSE];

        if ($name !== self::EXPENSE) {
            $graph['name']         = $name;
            $graph['display_name'] = $name;
        }

        $now = date('Y-m-d H:i:s');

        $definitionId = Db::table('workflow_definitions')->insertGetId([
            'tenant_id'        => $tenantId,
            'name'             => $name,
            'display_name'     => $name,
            'description'      => '宿主装配测试定义（用例结束后删除）',
            'type'             => 'approval',
            'category'         => 'finance',
            'icon'             => 'host-wiring-icon',
            'version'          => 1,
            'status'           => 'published',
            'nodes_config'     => json_encode($graph, JSON_UNESCAPED_UNICODE),
            'triggers_config'  => json_encode([], JSON_UNESCAPED_UNICODE),
            'variables_schema' => json_encode([], JSON_UNESCAPED_UNICODE),
            'is_active'        => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $this->assertGreaterThan(0, (int) $definitionId, '插入种子定义应拿到自增主键');

        return (int) $definitionId;
    }

    /**
     * 读取迁移里的种子图（迁移文件是唯一真相源）
     *
     * @return array<string, array<string, mixed>>
     */
    private function sampleGraphs(): array
    {
        $file = $this->app()->getRootPath() . self::MIGRATION_FILE;

        $this->assertFileExists($file, '缺少种子定义迁移文件：' . self::MIGRATION_FILE);

        if (!class_exists(self::MIGRATION_CLASS, false)) {
            require_once $file;
        }

        $class = self::MIGRATION_CLASS;
        $this->assertTrue(class_exists($class, false), '迁移类未加载：' . $class);

        return $class::graphs();
    }

    /**
     * 清理本测试建的定义（实例/任务/事件随之删除）
     */
    private function purgeFixtures(): void
    {
        $definitionIds = Db::table('workflow_definitions')
            ->whereIn('name', [self::EXPENSE, self::EXPENSE_TENANT_B])
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
}
