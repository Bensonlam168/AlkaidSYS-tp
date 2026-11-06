<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\Entities\ProcessInstanceEntity;
use Alkaid\Workflow\Engine\Integration\WorkflowBootstrap;
use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Alkaid\Workflow\Engine\Services\ProcessInstanceHistoryService;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceHistoryService;
use madong\helper\Dict;
use Tests\ThinkPHPTestCase;
use think\event\LogRecord;
use think\facade\Db;

/**
 * 流程轨迹（`workflow_event_logs`）服务测试
 *
 * ## 这条测试要证明什么
 *
 * 「历史」在本仓库的口径是**事件时间线**，不是实例快照副本（理由见
 * {@see IProcessInstanceHistoryService} 的类注释）。因此这里不测「有没有历史表」，
 * 而是把四件事钉死：
 *
 * 1. **列映射**：四类事件真的落进 `workflow_event_logs`，且
 *    `event_type`（机器标识）/ `event_name`（中文标题）/ `event_data`（当时的快照）
 *    三列取值有意义、彼此不重复；
 * 2. **租户 fail-closed**：实例没有 `tenant_id` 时**抛异常**，绝不回落默认租户 1；
 * 3. **轨迹隔离**：租户 2 用同一个实例ID查 `timeline()` 只能拿到空数组；
 * 4. **旁路审计**：事件写入必然失败时，主流程照样推进到 completed，且日志里有 WARNING。
 *
 * ## 为什么写在根套件而不是插件套件
 *
 * 插件 `plugins/workflow-engine/phpunit.xml` 不连数据库（bootstrap 只有
 * `vendor/autoload.php`，且其注释明确禁止把连接参数写进插件配置）。
 * 本用例要断言「库里真的有一行」，只能放在有测试库的根 `Feature` 套件里。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowInstanceHistoryServiceTest extends ThinkPHPTestCase
{
    /** 所属租户（测试库里确实存在） */
    private const TENANT_ID = 1;

    /** 另一个租户（测试库里的 Test Tenant Two），用于轨迹隔离断言 */
    private const OTHER_TENANT_ID = 2;

    /** 引擎的系统身份：能过 isAllowed()，且 user_id 落 NULL（不撞 users 外键） */
    private const OPERATOR = 'flow_auto';

    /** 本测试自建的定义名（不是种子数据；用例结束后删除） */
    private const DEF_NAME = 'e2e_history_timeline';

    /** 旁路审计失败时，日志里必须出现的关键字 */
    private const WARNING_KEYWORD = '工作流事件写入失败';

    /** @var array<int, \think\event\LogRecord> 本用例捕获到的日志记录 */
    private array $loggedRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeFixtures();

        // 引擎与租户上下文都是静态状态：每个用例从干净状态起步
        WorkflowBootstrap::reset();
        TenantContext::clearAll();

        // 生产路径装配（按默认配置）：实例历史服务应当**自动**就位
        WorkflowBootstrap::initialize([
            'extension_parameters' => ['tenant_id' => self::TENANT_ID],
        ]);
        TenantContext::setTenantId(self::TENANT_ID);
    }

    protected function tearDown(): void
    {
        // 监听器挂在全局 think\Event 上，必须显式摘掉，避免泄漏到后续测试类
        $this->stopCapturingLog();

        $this->purgeFixtures();

        TenantContext::clearAll();
        WorkflowBootstrap::reset();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1) 列映射：四类事件真的落库，且三列各司其职
    // -----------------------------------------------------------------

    /**
     * 走完一条「申请 → 主管审批」的流程，逐列断言四类事件的落库结果
     */
    public function testFinishedFlowWritesFourEventKindsWithExpectedColumns(): void
    {
        $definitionId = $this->insertDefinition();
        $instance = $this->startInstance($definitionId);
        $instanceId = (int) $instance->getData('id');

        $applyTaskId = $this->completeNextTask($instanceId, '同意');
        $reviewTaskId = $this->completeNextTask($instanceId, '同意');

        $instanceRow = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($instanceRow);
        $this->assertSame('completed', (string) $instanceRow['status'], '流程应走到 completed');

        $rows = Db::table('workflow_event_logs')
            ->where('instance_id', $instanceId)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // ---- 顺序与种类 ----
        $this->assertSame(
            [
                ProcessInstanceHistoryService::EVENT_INSTANCE_STARTED,
                ProcessInstanceHistoryService::EVENT_TASK_COMPLETED,
                ProcessInstanceHistoryService::EVENT_TASK_COMPLETED,
                ProcessInstanceHistoryService::EVENT_INSTANCE_COMPLETED,
            ],
            array_column($rows, 'event_type'),
            '一个两步流程应产生「启动 → 任务完成 ×2 → 完成」四行事件'
        );

        // ---- event_name：人可读的中文标题（不是 event_type 的复制品） ----
        $this->assertSame(
            ['流程启动', '任务完成', '任务完成', '流程完成'],
            array_column($rows, 'event_name'),
            'event_name 必须是可读中文标题'
        );

        // ---- 租户/实例/操作人：逐列核对 ----
        foreach ($rows as $index => $row) {
            $this->assertSame(
                self::TENANT_ID,
                (int) $row['tenant_id'],
                "第 {$index} 行的 tenant_id 必须来自实例本身"
            );
            $this->assertSame($instanceId, (int) $row['instance_id'], "第 {$index} 行的 instance_id 必须指向本实例");
            $this->assertNotEmpty($row['created_at'], "第 {$index} 行的 created_at 必须写入");
            $this->assertGreaterThan(0, (int) $row['id']);

            // 系统身份没有 users 行：user_id 落 NULL（否则撞 fk_workflow_event_logs_user_id），
            // 原始标识改由 user_name 留痕 —— 否则「谁触发的」在库里彻底消失
            $this->assertNull($row['user_id'], "第 {$index} 行：flow_auto 不是真实用户，user_id 必须为 NULL");
        }

        $this->assertSame(
            [self::OPERATOR, self::OPERATOR, self::OPERATOR, null],
            $this->columnAsString($rows, 'user_name'),
            '任务级事件带操作人；终态事件的操作人来自「最后一条已完成任务」，'
            . 'flow_auto 没有 assignee，因此为 NULL（不伪造）'
        );

        // ---- task_id：任务级事件指向任务，实例级事件为 NULL ----
        $this->assertNull($rows[0]['task_id'], 'instance_started 不是任务事件');
        $this->assertSame($applyTaskId, (int) $rows[1]['task_id']);
        $this->assertSame($reviewTaskId, (int) $rows[2]['task_id']);
        $this->assertNull($rows[3]['task_id'], 'instance_completed 不是任务事件');

        // ---- event_data：当时的快照（小而具体，不塞大对象） ----
        $started = $this->decodeEventData($rows[0]);
        $this->assertSame($definitionId, $started['define_id'] ?? null, '启动快照必须含 define_id');
        $this->assertSame(self::DEF_NAME, $started['define_code'] ?? null, '启动快照必须含 define_code');
        $this->assertSame(self::OPERATOR, $started['operator'] ?? null, '启动快照必须含 operator 原始标识');
        $this->assertSame('running', $started['status'] ?? null, '启动时实例状态是 running');

        $taskDone = $this->decodeEventData($rows[1]);
        $this->assertSame('apply', $taskDone['node_name'] ?? null, '任务快照必须含节点名');
        $this->assertSame('同意', $taskDone['comment'] ?? null, '任务快照必须含审批意见（中文需原样往返）');
        $this->assertSame('completed', $taskDone['status'] ?? null);

        $finished = $this->decodeEventData($rows[3]);
        $this->assertSame('completed', $finished['status'] ?? null);
        $this->assertIsInt($finished['duration'] ?? null, '完成快照必须含耗时（毫秒）');
        $this->assertSame($reviewTaskId, $finished['last_task_id'] ?? null, '完成快照应指回最后完成的任务');
    }

    // -----------------------------------------------------------------
    // 2) 租户 fail-closed
    // -----------------------------------------------------------------

    /**
     * 实例没有 tenant_id 时必须抛异常，不能默默写进租户 1
     */
    public function testCreateHistoryProcessInstanceFailsWhenInstanceHasNoTenant(): void
    {
        $history = $this->historyService();

        // 缺 tenant_id 列（真实场景：上游实现交来的实体没有租户信息）
        $noTenant = ProcessInstanceEntity::fromRow(['id' => 1, 'definition_id' => 1]);

        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessageMatches('/tenant_id/');
        $history->createHistoryProcessInstance($noTenant, self::OPERATOR);
    }

    /**
     * tenant_id = 0 同样视为「没有租户」（0 不是合法租户，更不是"默认租户"）
     */
    public function testCreateHistoryProcessInstanceFailsWhenTenantIsZero(): void
    {
        $history = $this->historyService();

        $zeroTenant = ProcessInstanceEntity::fromRow(['id' => 1, 'definition_id' => 1, 'tenant_id' => 0]);

        $this->expectException(TenantIsolationException::class);
        $history->createHistoryProcessInstance($zeroTenant, self::OPERATOR);
    }

    // -----------------------------------------------------------------
    // 3) timeline() 租户隔离
    // -----------------------------------------------------------------

    /**
     * 同一实例ID：本租户读得到轨迹，换到租户 2 就一行都读不到
     */
    public function testTimelineIsTenantIsolated(): void
    {
        $definitionId = $this->insertDefinition();
        $instance = $this->startInstance($definitionId);
        $instanceId = (int) $instance->getData('id');

        $history = $this->historyService();

        $own = $history->timeline((string) $instanceId);
        $this->assertNotEmpty($own, '本租户应能读到自己的轨迹');
        $this->assertSame(
            ProcessInstanceHistoryService::EVENT_INSTANCE_STARTED,
            $own[0]['event_type'],
            '轨迹首行应是流程启动'
        );

        // 换租户：同一个实例ID必须查不到（不是"读到了但看不见"）
        TenantContext::setTenantId(self::OTHER_TENANT_ID);
        $this->assertSame([], $history->timeline((string) $instanceId), '跨租户读轨迹必须为空（租户隔离红线）');

        // 切回来又能读到，证明是"按租户过滤"而不是数据被删了
        TenantContext::setTenantId(self::TENANT_ID);
        $this->assertCount(count($own), $history->timeline((string) $instanceId), '切回原租户后轨迹应重新可读');
    }

    /**
     * 没有协程上下文时，按 `ResolvesWorkflowTenant` 的口径回落到引擎配置的 tenant_id
     *
     * 这条用例的存在是为了钉住一个易被误读的点：**「没有上下文」不等于「不过滤」**。
     * 回落值来自 `extension_parameters.tenant_id`（CLI / 队列场景的显式声明），
     * 而不是「读全表」。
     */
    public function testTimelineFallsBackToConfiguredTenantWhenContextIsMissing(): void
    {
        $definitionId = $this->insertDefinition();
        $instance = $this->startInstance($definitionId);
        $instanceId = (int) $instance->getData('id');

        $history = $this->historyService();
        $expectedCount = count($history->timeline((string) $instanceId));

        TenantContext::clearAll();

        $this->assertCount(
            $expectedCount,
            $history->timeline((string) $instanceId),
            '无协程上下文时应回落到 extension_parameters.tenant_id（仍带租户条件），不是读全表'
        );
    }

    /**
     * 上下文与配置都拿不到租户时，读轨迹必须抛异常（fail-closed）
     *
     * 这才是「强制 tenant 过滤」的兜底：拿不到租户绝不退化成无作用域查询。
     */
    public function testTimelineFailsWhenNeitherContextNorConfigProvidesTenant(): void
    {
        $definitionId = $this->insertDefinition();
        $instance = $this->startInstance($definitionId);
        $instanceId = (int) $instance->getData('id');

        // 同时抽掉两条来源：协程上下文 + 引擎配置（is_numeric(null) 为 false）
        TenantContext::clearAll();
        ServiceContext::extensions(['tenant_id' => null]);

        $this->expectException(TenantIsolationException::class);
        $this->historyService()->timeline((string) $instanceId);
    }

    // -----------------------------------------------------------------
    // 4) 旁路审计：写入失败不影响主流程
    // -----------------------------------------------------------------

    /**
     * 历史服务每次写入都抛异常时：流程仍然走完，且日志里留下 WARNING
     *
     * 这是本能力最重要的安全属性：「审批成功但审计失败」绝不能变成「审批失败」。
     * 用「永远抛异常的实现」而不是「不存在的实例」来做：前者能同时覆盖
     * 启动 / 任务完成 / 实例完成三个写入点，且不依赖外键行为的细节。
     */
    public function testHistoryWriteFailureDoesNotBreakMainFlow(): void
    {
        // 换成必失败的历史服务（宿主覆盖能力，与生产装配同一个入口）
        WorkflowBootstrap::reset();
        TenantContext::clearAll();
        WorkflowBootstrap::initialize([
            'extension_parameters'          => ['tenant_id' => self::TENANT_ID],
            'process_instance_history_service' => ThrowingProcessInstanceHistoryService::class,
        ]);
        TenantContext::setTenantId(self::TENANT_ID);

        $this->startCapturingLog();

        $definitionId = $this->insertDefinition();
        $instance = $this->startInstance($definitionId);
        $instanceId = (int) $instance->getData('id');

        $applyTaskId = $this->completeNextTask($instanceId, '同意');
        $reviewTaskId = $this->completeNextTask($instanceId, '同意');

        // ---- 主流程照常推进 ----
        $instanceRow = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($instanceRow);
        $this->assertSame('completed', (string) $instanceRow['status'], '历史写入失败不得影响实例终态');

        $statuses = Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->order('id', 'asc')
            ->column('status');
        $this->assertSame(['completed', 'completed'], $statuses, '任务必须照常完成');

        $this->assertGreaterThan($applyTaskId, $reviewTaskId, '第二个任务应紧接着第一个生成（流程真的推进了）');

        // ---- 审计侧：四个写入点各留一条 WARNING ----
        $warnings = array_values(array_filter(
            $this->loggedRecords,
            static fn (LogRecord $record): bool => in_array($record->type, ['warning', 'error'], true)
                && str_contains((string) $record->message, self::WARNING_KEYWORD)
        ));

        $this->assertCount(
            4,
            $warnings,
            '启动 / 任务完成 ×2 / 实例完成 共四个写入点，每个失败都要各自留一条 WARNING（不是静默吞掉）'
        );

        foreach ($warnings as $warning) {
            $this->assertStringContainsString(
                ThrowingProcessInstanceHistoryService::MESSAGE,
                (string) $warning->message,
                'WARNING 必须带上真实失败原因，否则与"没写"无法区分'
            );
        }

        // ---- 审计侧确实没有数据（证明失败是真的发生了，而不是被偷偷写成功） ----
        $this->assertSame(
            0,
            (int) Db::table('workflow_event_logs')->where('instance_id', $instanceId)->count(),
            '写入全部失败时库里不应有该实例的事件'
        );

        $this->stopCapturingLog();
    }

    // -----------------------------------------------------------------
    // 辅助方法
    // -----------------------------------------------------------------

    /**
     * 取容器里注册的实例历史服务（若为 null 直接失败，避免后续断言变成 "null 上调用"）
     */
    private function historyService(): IProcessInstanceHistoryService
    {
        $service = ServiceContext::find(IProcessInstanceHistoryService::class);

        $this->assertInstanceOf(
            ProcessInstanceHistoryService::class,
            $service,
            '默认装配下容器必须提供真实的实例历史服务（WorkflowContainer::buildCoreServices）'
        );

        return $service;
    }

    /**
     * 开始捕获 ThinkPHP 日志记录
     *
     * 为什么要用 `think\event\LogRecord` 而不是读日志文件：
     * 控制台进程里框架把 `lazy` 置为 false（`Log::createDriver`），日志是**即时写盘**的，
     * 而 `Channel::getLog()` 只留有未落盘的记录 —— 断言时它已经是空数组。
     * `Channel::record()` 在落盘之前一定会 `trigger(LogRecord)`，这个事件才是稳定的观测点。
     */
    private function startCapturingLog(): void
    {
        $this->loggedRecords = [];

        $this->app()->event->listen(LogRecord::class, function (LogRecord $record): void {
            $this->loggedRecords[] = $record;
        });
    }

    /**
     * 摘掉日志监听（挂在全局 Event 上，不摘会泄漏到后续测试类）
     */
    private function stopCapturingLog(): void
    {
        try {
            $this->app()->event->remove(LogRecord::class);
        } catch (\Throwable) {
            // 应用未启动 / 框架差异：不影响用例结论
        }

        $this->loggedRecords = [];
    }

    /**
     * 插入一条「申请 → 主管审批」的两步流程定义
     *
     * @return int 定义主键
     */
    private function insertDefinition(): int
    {
        $now = date('Y-m-d H:i:s');

        $definitionId = Db::table('workflow_definitions')->insertGetId([
            'tenant_id'        => self::TENANT_ID,
            'name'             => self::DEF_NAME,
            'display_name'     => '轨迹验证流程',
            'description'      => '事件轨迹测试定义（用例结束后删除）',
            'type'             => 'approval',
            'category'         => 'finance',
            'icon'             => 'e2e-icon',
            'version'          => 1,
            'status'           => 'published',
            'nodes_config'     => json_encode($this->graph(), JSON_UNESCAPED_UNICODE),
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
     * 启动流程实例
     *
     * @return \Alkaid\Workflow\Engine\interface\model\IProcessInstance
     */
    private function startInstance(int $definitionId)
    {
        $args = new Dict();
        $args->putAll(['amount' => 1200]);

        $instance = WorkflowBootstrap::getEngine()->startProcessInstanceById(
            (string) $definitionId,
            self::OPERATOR,
            $args
        );

        $this->assertNotNull($instance, 'startProcessInstanceById() 应返回实例对象');

        return $instance;
    }

    /**
     * 完成实例当前唯一的待办任务，返回该任务主键
     */
    private function completeNextTask(int $instanceId, string $comment): int
    {
        $task = $this->onlyPendingTask($instanceId);
        $this->assertIsArray($task, '应存在待办任务');

        $taskId = (int) $task['id'];

        $args = new Dict();
        $args->putAll(['tf_approval_comment' => $comment]);

        WorkflowBootstrap::getEngine()->executeProcessTask((string) $taskId, self::OPERATOR, $args);

        return $taskId;
    }

    /**
     * 实例当前唯一的一条待办任务
     *
     * @return array<string, mixed>|null
     */
    private function onlyPendingTask(int $instanceId): ?array
    {
        $rows = Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->where('status', 'pending')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if ($rows === []) {
            return null;
        }

        $this->assertCount(1, $rows, '直线流程在同一时刻应只有一条待办任务');

        return $rows[0];
    }

    /**
     * 解码一行事件的 event_data（json 列经 PDO 取回是字符串）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeEventData(array $row): array
    {
        $raw = $row['event_data'] ?? null;

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 取某一列并统一成「字符串或 null」
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string|null>
     */
    private function columnAsString(array $rows, string $column): array
    {
        return array_map(
            static fn (array $row) => isset($row[$column]) ? (string) $row[$column] : null,
            $rows
        );
    }

    /**
     * 两步直线流程：start → apply(task) → review(task) → end
     *
     * @return array<string, mixed>
     */
    private function graph(): array
    {
        $node = static fn (string $id, string $type, string $text): array => [
            'id'         => $id,
            'type'       => 'ingenious:' . $type,
            'x'          => 200,
            'y'          => 160,
            'text'       => ['value' => $text],
            'properties' => [
                '_alkaid_node_class' => match ($type) {
                    'start' => 'StartNode',
                    'end'   => 'EndNode',
                    default => 'TaskNode',
                },
                '_alkaid_node_type'  => $type,
            ],
        ];

        $edge = static fn (string $id, string $source, string $target): array => [
            'id'           => $id,
            'type'         => 'polyline',
            'sourceNodeId' => $source,
            'targetNodeId' => $target,
            'text'         => ['value' => ''],
            'properties'   => ['_alkaid_type' => 'sequence'],
        ];

        return [
            'name'         => self::DEF_NAME,
            'display_name' => '轨迹验证流程',
            'type'         => 'approval',
            'nodes'        => [
                $node('start', 'start', '开始'),
                $node('apply', 'task', '填写申请'),
                $node('review', 'task', '主管审批'),
                $node('end', 'end', '结束'),
            ],
            'edges'        => [
                $edge('flow_start_apply', 'start', 'apply'),
                $edge('flow_apply_review', 'apply', 'review'),
                $edge('flow_review_end', 'review', 'end'),
            ],
        ];
    }

    /**
     * 清理本测试建的定义（实例 / 任务 / 事件经外键或显式删除一并清掉）
     */
    private function purgeFixtures(): void
    {
        $definitionIds = Db::table('workflow_definitions')->where('name', self::DEF_NAME)->column('id');

        if ($definitionIds === []) {
            return;
        }

        $instanceIds = Db::table('workflow_instances')->whereIn('definition_id', $definitionIds)->column('id');

        if ($instanceIds !== []) {
            // 先删子表，不依赖各库是否真的建了 CASCADE
            Db::table('workflow_event_logs')->whereIn('instance_id', $instanceIds)->delete();
            Db::table('workflow_tasks')->whereIn('instance_id', $instanceIds)->delete();
            Db::table('workflow_instances')->whereIn('id', $instanceIds)->delete();
        }

        Db::table('workflow_definitions')->whereIn('id', $definitionIds)->delete();
    }
}

/**
 * 必失败的历史服务：每次写入都抛异常
 *
 * 用途是证明「审计写入失败 → 只记 WARNING，主流程照常推进」。
 * 刻意做成真实类而不是 mock：宿主覆盖入口
 * （`process_instance_history_service`）收的是**类名字符串**，
 * ServiceContext 归一化时只对「存在的类」包成 DI\create()，匿名类/mock 对象走不通。
 */
class ThrowingProcessInstanceHistoryService implements IProcessInstanceHistoryService
{
    /** 失败原因（断言日志里必须带出它） */
    public const MESSAGE = '模拟事件存储不可用';

    public function createHistoryProcessInstance(object $processInstance, string|int|null $operator = null): void
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function recordTaskCompleted(
        string|int $processInstanceId,
        string|int $processTaskId,
        string|int|null $operator,
        array $extra = []
    ): void {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function recordInstanceCompleted(
        string|int $processInstanceId,
        string|int|null $operator,
        array $extra = []
    ): void {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function recordInstanceRejected(
        string|int $processInstanceId,
        string|int|null $operator,
        array $extra = []
    ): void {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function timeline(string $processInstanceId): array
    {
        throw new \RuntimeException(self::MESSAGE);
    }
}
