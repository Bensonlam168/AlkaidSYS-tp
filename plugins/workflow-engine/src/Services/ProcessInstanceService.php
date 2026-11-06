<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Services;

use Alkaid\Workflow\Engine\Entities\ProcessInstanceEntity;
use Alkaid\Workflow\Engine\enums\ProcessConstEnum;
use Alkaid\Workflow\Engine\enums\ProcessInstanceStateEnum;
use Alkaid\Workflow\Engine\Entities\WorkflowStateMap;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\interface\IExecution;
use Alkaid\Workflow\Engine\interface\model\IProcessDefine;
use Alkaid\Workflow\Engine\interface\model\IProcessInstance;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceService;
use Alkaid\Workflow\Engine\model\TaskModel;
use Alkaid\Workflow\Engine\Services\Support\RecordsHistorySafely;
use Alkaid\Workflow\Engine\Services\Support\ResolvesWorkflowTenant;
use madong\interface\IDict;

/**
 * 工作流实例服务（真实实现，落 `workflow_instances` 表）
 *
 * ── 实现范围（引擎真正调用的 6 个方法，全部真实读写数据库）──────────────
 * | 方法 | 引擎调用点 |
 * | ---- | ---------- |
 * | `getById()`               | ProcessEngines:196、EndProcessHandler:60 |
 * | `createProcessInstance()` | ProcessEngines:69 |
 * | `addVariable()`           | ProcessEngines:228、CountersignHandler:97 |
 * | `removeVariable()`        | CountersignHandler:110 |
 * | `finishProcessInstance()` | EndProcessHandler:52 |
 * | `rejectProcessInstance()` | EndProcessHandler:50 |
 *
 * 状态流转**真正写库**（不是空实现）：
 * - 创建：`status = 'running'`、写 `started_at` / `variables` / `created_by`；
 * - 完成：`status = 'completed'`、写 `completed_at` 与 `duration`（毫秒）；
 * - 拒绝：`status = 'cancelled'`（表枚举里没有 rejected，见 WorkflowStateMap 的说明）。
 *
 * ── 未实现的部分 ──────────────────────────────────────────────────────────
 * `interrupt/resume/pending/activate/withdraw`（状态机语义未定，且 EndProcessHandler
 * 只会走 finish/reject 两条）、`createCCInstance/updateCCStatus/ccInstancePage`
 * （本仓库没有抄送表）、`approvalRecord`（需要任务历史表）、
 * `updateCountersignVariable`（会签计数写在实例变量，但键名契约未定）等一律抛异常并说明原因。
 *
 * @package Alkaid\Workflow\Engine\Services
 */
class ProcessInstanceService implements IProcessInstanceService
{
    use ResolvesWorkflowTenant;
    use RecordsHistorySafely;

    /**
     * 本服务负责的表
     */
    private const TABLE = 'workflow_instances';

    /**
     * 任务表（反查「把实例推进到终态的人」）
     */
    private const TASK_TABLE = 'workflow_tasks';

    /**
     * 实例标题列上限（workflow_instances.title varchar(200)）
     */
    private const TITLE_MAX_LENGTH = 200;

    /**
     * 按主键读取实例
     */
    public function getById(string $id): ?IProcessInstance
    {
        $instanceId = $this->numericId($id);

        if ($instanceId === null) {
            return null;
        }

        $row = $this->scoped(self::TABLE, '读取工作流实例')->find($instanceId);

        return $row === null ? null : ProcessInstanceEntity::fromRow($row);
    }

    /**
     * 按主键读取实例（getById 的别名）
     */
    public function findById(string|int $id): ?IProcessInstance
    {
        return $this->getById((string) $id);
    }

    /**
     * 创建流程实例
     *
     * @param IProcessDefine $processDefine  流程定义实体
     * @param string         $operator       发起人
     * @param IDict          $args           启动参数（启动后即为实例变量）
     * @param string|null    $parentId       父实例ID（本表未建模，非空时拒绝）
     * @param string|null    $parentNodeName 父节点名（本表未建模，非空时拒绝）
     *
     * @throws LFlowException 子流程父关系无法持久化、定义对象不可读、写库失败时
     */
    public function createProcessInstance(
        IProcessDefine $processDefine,
        string $operator,
        IDict $args,
        string|null $parentId = '',
        string|null $parentNodeName = ''
    ): ?IProcessInstance {
        // 子流程父子关系：workflow_instances 没有 parent_id / parent_node_name 列。
        // 静默丢弃会让「子流程结束时回到父流程」永远不触发，且看不出原因，故 fail-closed。
        if (($parentId !== null && $parentId !== '') || ($parentNodeName !== null && $parentNodeName !== '')) {
            throw new LFlowException(
                '子流程实例无法持久化：workflow_instances 表没有 parent_id / parent_node_name 列，'
                . '父流程回调（EndProcessHandler 的 parent 分支）在本仓库表结构下不可用'
            );
        }

        if (!method_exists($processDefine, 'getData')) {
            throw new LFlowException(sprintf(
                '定义对象 %s 没有 getData() 方法，无法取得流程定义主键',
                get_debug_type($processDefine)
            ));
        }

        $definitionId = $this->numericId((string) $processDefine->getData('id'));

        if ($definitionId === null) {
            throw new LFlowException('创建流程实例失败：流程定义主键不是合法的整数主键');
        }

        $tenantId = $this->tenantId('创建流程实例');
        $timestamp = date('Y-m-d H:i:s');
        $variables = $args->toArray();

        // title 是 NOT NULL 列，而引擎的启动参数没有强制的标题契约：
        // 优先取引擎自己约定的 auto_gen_title（ProcessConstEnum::AUTO_GEN_TITLE），
        // 缺省时退化为「发起人的定义名」。
        $title = trim((string) ($args->get(ProcessConstEnum::AUTO_GEN_TITLE->value) ?? ''));

        if ($title === '') {
            $displayName = method_exists($processDefine, 'getDisplayName')
                ? (string) $processDefine->getDisplayName()
                : (string) $processDefine->getData('display_name', '未命名流程');
            $title = sprintf('%s的%s', $operator === '' ? '-' : $operator, $displayName === '' ? '未命名流程' : $displayName);
        }

        $businessKey = $args->get(ProcessConstEnum::BUSINESS_NO->value);
        $row = [
            'definition_id' => $definitionId,
            'tenant_id'     => $tenantId,
            'business_key'  => is_scalar($businessKey) && (string) $businessKey !== '' ? (string) $businessKey : null,
            'title' => mb_substr($title, 0, self::TITLE_MAX_LENGTH),
            // 表枚举：pending/running/completed/failed/cancelled/suspended
            'status'            => 'running',
            'priority'          => 5,
            'current_node_id'   => null,
            'current_node_name' => null,
            'context'           => [],
            'variables'         => $variables,
            'started_at'        => $timestamp,
            'completed_at'      => null,
            'duration'          => null,
            'created_at'        => $timestamp,
            'updated_at'        => $timestamp,
            'created_by'        => $this->userIdOrNull($operator),
        ];

        $insertedId = $this->scoped(self::TABLE, '创建流程实例')->insert($row);

        if (empty($insertedId)) {
            throw new LFlowException('创建流程实例失败：数据库未返回自增主键');
        }

        return ProcessInstanceEntity::fromRow(['id' => (int) $insertedId] + $row);
    }

    /**
     * 追加实例变量（合并写入，不清空已有键）
     */
    public function addVariable(string|int $processInstanceId, IDict $args): void
    {
        $id = $this->requireInstanceId($processInstanceId, '追加流程实例变量');
        $row = $this->findRowOrFail($id, '追加流程实例变量');

        $variables = $this->decodeJsonColumn($row['variables'] ?? null);
        $merged = array_merge($variables, $args->toArray());

        $this->scoped(self::TABLE, '追加流程实例变量')->updateById($id, [
            'variables'  => json_encode($merged, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 删除实例变量（按 key 删除，其余保留）
     *
     * @param string|array $keys 单个键或键列表
     */
    public function removeVariable(string|int $processInstanceId, string|array $keys): void
    {
        $id = $this->requireInstanceId($processInstanceId, '删除流程实例变量');
        $row = $this->findRowOrFail($id, '删除流程实例变量');

        $variables = $this->decodeJsonColumn($row['variables'] ?? null);

        foreach ((array) $keys as $key) {
            if (is_scalar($key)) {
                unset($variables[(string) $key]);
            }
        }

        $this->scoped(self::TABLE, '删除流程实例变量')->updateById($id, [
            'variables'  => json_encode($variables, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 结束流程实例（状态置 completed，写完成时间与执行时长）
     *
     * 状态落库后追加 `instance_completed` 事件（旁路审计，失败只记 WARNING）。
     */
    public function finishProcessInstance(string|int $processInstanceId): void
    {
        $terminal = $this->transitionToTerminalState(
            $processInstanceId,
            WorkflowStateMap::engineToInstance(ProcessInstanceStateEnum::FINISHED->value),
            '结束流程实例'
        );

        $this->recordHistorySafely('记录流程完成事件', function ($history) use ($processInstanceId, $terminal): void {
            $history->recordInstanceCompleted($processInstanceId, $terminal['operator'], $terminal);
        });
    }

    /**
     * 拒绝流程实例
     *
     * 注意：`workflow_instances.status` 的取值域里**没有** rejected，
     * 引擎侧 `ProcessInstanceStateEnum` 有 REJECT(45)，两个枚举不是一一对应。
     * 这里按 WorkflowStateMap 的口径把「拒绝」落到 cancelled（终态），
     * 任务级拒绝仍能在 `workflow_tasks.status = 'rejected'` 上精确表达。
     *
     * 状态落库后追加 `instance_rejected` 事件（旁路审计，失败只记 WARNING）。
     */
    public function rejectProcessInstance(string|int $processInstanceId): void
    {
        $terminal = $this->transitionToTerminalState(
            $processInstanceId,
            WorkflowStateMap::engineToInstance(ProcessInstanceStateEnum::REJECT->value),
            '拒绝流程实例'
        );

        $this->recordHistorySafely('记录流程拒绝事件', function ($history) use ($processInstanceId, $terminal): void {
            $history->recordInstanceRejected($processInstanceId, $terminal['operator'], $terminal);
        });
    }

    /**
     * 把实例置为终态：状态 + 完成时间 + 执行时长（毫秒）
     *
     * @param string|int $processInstanceId 实例ID
     * @param string     $status            目标状态（表枚举字符串）
     * @param string     $operation         操作描述
     * @return array{status: string, completed_at: string, duration: int|null, operator: string|null, last_task_id: int|null}
     */
    private function transitionToTerminalState(string|int $processInstanceId, string $status, string $operation): array
    {
        $id = $this->requireInstanceId($processInstanceId, $operation);
        $row = $this->findRowOrFail($id, $operation);

        $timestamp = time();
        $startedAt = isset($row['started_at']) && $row['started_at'] ? strtotime((string) $row['started_at']) : false;
        $completedAt = date('Y-m-d H:i:s', $timestamp);
        $duration = $startedAt === false ? null : max(0, ($timestamp - $startedAt) * 1000);

        $this->scoped(self::TABLE, $operation)->updateById($id, [
            'status'       => $status,
            'completed_at' => $completedAt,
            'duration'     => $duration,
            'updated_at'   => $completedAt,
        ]);

        // 终态事件需要「谁把它推到终态的」：见 lastCompletedTask() 的说明
        $lastTask = $this->lastCompletedTask($id);

        return [
            'status'       => $status,
            'completed_at' => $completedAt,
            'duration'     => $duration,
            'operator'     => $lastTask['assignee'],
            'last_task_id' => $lastTask['id'],
        ];
    }

    /**
     * 取「本实例最后完成的那条任务」（终态事件的 data 来源）
     *
     * 为什么是反查而不是直接拿 operator：`finishProcessInstance()` / `rejectProcessInstance()`
     * 的接口签名只有实例ID（上游既定契约，不能只为审计改签名），而真正知道 operator 的
     * EndProcessHandler 不在本包可改范围。`finishProcessTask()` 完成时会把真实操作人写进
     * `workflow_tasks.assignee`，因此「最后一条 completed 任务的 assignee」就是推进终态的人。
     *
     * 查不到（系统身份 `flow_auto` 没有 user_id、或流程里没有人工任务）时返回 null：
     * 宁可在审计里留下空白，也不伪造一个操作人。
     *
     * @return array{id: int|null, assignee: string|null}
     */
    private function lastCompletedTask(int $instanceId): array
    {
        try {
            $row = $this->scoped(self::TASK_TABLE, '读取终态操作人')
                ->query()
                ->where('instance_id', (string) $instanceId)
                ->where('status', 'completed')
                ->order('id', 'desc')
                ->find();
        } catch (\Throwable) {
            // 终态写入已经成功，这里只是给审计补快照，不能因此让 finish/reject 失败
            return ['id' => null, 'assignee' => null];
        }

        return [
            'id'       => isset($row['id']) ? (int) $row['id'] : null,
            'assignee' => isset($row['assignee']) && $row['assignee'] !== null ? (string) $row['assignee'] : null,
        ];
    }

    /**
     * 收敛实例主键：非数字主键不可能存在，直接报错（这是写操作，不能像读那样静默返回）
     *
     * @param string|int $processInstanceId 实例ID
     * @param string     $operation         操作描述
     */
    private function requireInstanceId(string|int $processInstanceId, string $operation): int
    {
        $id = $this->numericId($processInstanceId);

        if ($id === null) {
            throw new LFlowException(sprintf(
                '%s失败：流程实例ID「%s」不是合法的整数主键',
                $operation,
                (string) $processInstanceId
            ));
        }

        return $id;
    }

    /**
     * 在租户作用域内读取实例行，不存在则报错
     *
     * @param int    $id        实例ID
     * @param string $operation 操作描述
     * @return array<string, mixed>
     */
    private function findRowOrFail(int $id, string $operation): array
    {
        $row = $this->scoped(self::TABLE, $operation)->find($id);

        if ($row === null) {
            throw new LFlowException(sprintf('%s失败：流程实例 #%d 不存在或不属于当前租户', $operation, $id));
        }

        return $row;
    }

    /**
     * 解码 JSON 列（Db 取回的 json 列是字符串）
     *
     * @param mixed $value 原始列值
     * @return array<string, mixed>
     */
    private function decodeJsonColumn(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * {@inheritdoc}
     */
    public function created(object $param): ?IProcessInstance
    {
        $this->unimplemented(
            __METHOD__,
            '入参是未定义的 DTO（object $param）；实例创建的真实入口是 createProcessInstance()，'
            . '该 DTO 没有可对齐的字段契约'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function updated(object $param): bool
    {
        $this->unimplemented(
            __METHOD__,
            '入参是未定义的 DTO（object $param），没有可对齐的字段契约'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function list(object $param): array
    {
        $this->unimplemented(
            __METHOD__,
            '入参是未定义的分页 DTO（object $param），本仓库 API 层的列表契约基于内存数组'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function saveProcessInstance(IProcessInstance $processInstance): ?IProcessInstance
    {
        $this->unimplemented(
            __METHOD__,
            '接口要求「保存任意实例对象」，但空接口 IProcessInstance 没有任何字段声明，'
            . '无法确定要写哪些列；实例创建/流转已由 createProcessInstance / finish / reject 覆盖'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function updateProcessInstance(IProcessInstance $processInstance): void
    {
        $this->unimplemented(
            __METHOD__,
            '空接口 IProcessInstance 没有字段声明，无法确定待更新的列集合'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function interrupt(string|int $processInstanceId, string|int $operator): void
    {
        $this->unimplemented(
            __METHOD__,
            '强行终止的状态机语义未定（引擎只走 finish/reject 两条路径，'
            . '且 workflow_instances.status 没有独立的 interrupted 取值）'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function resume(string|int $processInstanceId, string|int $operator): void
    {
        $this->unimplemented(
            __METHOD__,
            '恢复语义未定：需要先定义 suspended 与 pending 的区别，以及恢复后从哪个节点继续'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function cascadeDelete(string|int|array $processInstanceId, string|null|int $operator = null): array
    {
        $this->unimplemented(
            __METHOD__,
            '级联删除需要先拍板「实例被删时任务/事件日志如何处理」，本仓库只有 5 张表，'
            . '删除策略（软删/硬删/拒删）未定义'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function pending(string|int $processInstanceId, string|int $operator): void
    {
        $this->unimplemented(
            __METHOD__,
            '挂起语义未定：表里 suspended 与引擎 PENDING 的对应关系已定义（WorkflowStateMap），'
            . '但「挂起后是否终止进行中任务」未定'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function activate(string|int $processInstanceId, string|int $operator): void
    {
        $this->unimplemented(
            __METHOD__,
            '激活语义未定（与 resume 的重叠未澄清）'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function startAndExecute(string|int $processDefineId, IDict $args): ?IProcessInstance
    {
        $this->unimplemented(
            __METHOD__,
            '本方法应「创建实例并立刻执行到第一个任务」，需要执行引擎与定义体格式同时可用；'
            . '定义体格式尚未定案（见 ProcessDefineEntity 类注释），此时实现只会产出半截状态'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function highLight(string|int $processInstanceId): array
    {
        $this->unimplemented(
            __METHOD__,
            '高亮（已走过的节点/连线）需要实例级节点轨迹，本仓库没有节点轨迹表，'
            . 'workflow_event_logs 也没有 node_id 列'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function calculateTimeDifference($startTimestamp, $endTimestamp): string
    {
        $this->unimplemented(
            __METHOD__,
            '输出格式（「x天y小时」还是「HH:mm:ss」）没有约定，凭空定格式会让调用方解析出错'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function approvalRecord(string|int $processInstanceId): array
    {
        $this->unimplemented(
            __METHOD__,
            '审批记录需要「任务历史」表，本仓库 5 张表里没有任务历史表'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function withdraw(string|int|array $processInstanceId, string|int $operator): void
    {
        $this->unimplemented(
            __METHOD__,
            '撤回需要「把已完成的任务回退成待办」的节点轨迹能力，本仓库没有任务历史表'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function updateCountersignVariable(TaskModel $taskModel, IExecution $execution, array $taskActors): void
    {
        $this->unimplemented(
            __METHOD__,
            '会签计数的变量键名与完成条件（全部完成/按数量/按比例/一票否决）'
            . '只在引擎枚举里有注释，落库口径未定'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function createCCInstance(string|int $processInstanceId, string|int $creator, string|int $actorIds): void
    {
        $this->unimplemented(
            __METHOD__,
            '本仓库没有抄送表（workflow_cc_instances），无处落库'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function updateCCStatus(string|int $processInstanceId, string|int $actorId): void
    {
        $this->unimplemented(
            __METHOD__,
            '本仓库没有抄送表（workflow_cc_instances），无处落库'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function ccInstancePage(object $param): array
    {
        $this->unimplemented(
            __METHOD__,
            '本仓库没有抄送表（workflow_cc_instances），也入参是未定义的分页 DTO'
        );
    }
}
