<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Services;

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\Entities\ProcessTaskEntity;
use Alkaid\Workflow\Engine\Entities\TaskActorResolution;
use Alkaid\Workflow\Engine\Entities\WorkflowStateMap;
use Alkaid\Workflow\Engine\enums\ProcessConstEnum;
use Alkaid\Workflow\Engine\enums\ProcessTaskPerformTypeEnum;
use Alkaid\Workflow\Engine\enums\ProcessTaskStateEnum;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\interface\IExecution;
use Alkaid\Workflow\Engine\interface\model\IProcessTask;
use Alkaid\Workflow\Engine\interface\model\IProcessTaskActor;
use Alkaid\Workflow\Engine\interface\services\IProcessTaskService;
use Alkaid\Workflow\Engine\interface\services\ITaskActorDirectory;
use Alkaid\Workflow\Engine\interface\services\ITaskActorResolver;
use Alkaid\Workflow\Engine\libs\utils\ProcessFlowUtils;
use Alkaid\Workflow\Engine\libs\utils\StringHelper;
use Alkaid\Workflow\Engine\model\CustomModel;
use Alkaid\Workflow\Engine\model\ProcessModel;
use Alkaid\Workflow\Engine\model\TaskModel;
use Alkaid\Workflow\Engine\Services\Support\RecordsHistorySafely;
use Alkaid\Workflow\Engine\Services\Support\ResolvesWorkflowTenant;
use madong\helper\Dict;
use madong\interface\IDict;

/**
 * 工作流任务服务（真实实现，落 `workflow_tasks` 表）
 *
 * ── 实现范围（引擎真正调用的 10 个方法 + 1 个引擎会调但接口没声明的方法）──────
 * | 方法 | 引擎调用点 |
 * | ---- | ---------- |
 * | `getById()`            | ProcessEngines:184 |
 * | `isAllowed()`          | ProcessEngines:191 |
 * | `finishProcessTask()`  | ProcessEngines:202 |
 * | `createTask()`         | CreateTaskHandler:54 |
 * | `createCountersignTask()` | CreateTaskHandler:50、CountersignHandler:85 |
 * | `getDoingTaskList()`   | CountersignHandler:91/113 |
 * | `abandonProcessTask()` | CountersignHandler:115 |
 * | `rejectTask()`         | ProcessEngines:114 |
 * | `getTaskActors()`      | SurrogateInterceptorI:32、本项目 createCountersignTask |
 * | `getSurrogate()`       | SurrogateInterceptorI:34（**接口未声明**，见方法注释） |
 *
 * 状态流转**真正写库**：创建（pending/claimed）、完成（completed + completed_at）、
 * 废弃（cancelled + cancelled_at）、驳回（rejected + rejected_at，并在上一个任务节点新建任务）。
 *
 * ── 参与者（actor）落在哪 ─────────────────────────────────────────────────
 * 本仓库**没有** `workflow_task_actors` 表，参与者只能落 `workflow_tasks.assignee`
 * （单个处理人）与 `candidate_users`（json 数组）。这是字段口径的取舍，
 * 不是「随便找个列塞」：`assignee` 语义本来就是「处理人」，候选人是「可处理该任务的人集合」。
 *
 * 节点上配置的「谁有权办」（`assignee` / `candidate_ext_users` / `candidate_ext_groups`）
 * 由 {@see ITaskActorResolver} 解析成上述列：
 * - `assignee` / `assignee_name`：解析出的处理人与姓名；
 * - `candidate_users`：**展开后的**用户ID 并集（含 assignee）—— `isAllowed()` 只认这一列，
 *   所以角色/部门必须展开成人写进来，否则配了等于没配；
 * - `candidate_groups`：原始组引用（`role:admin` 这类）**原样保留**，用于展示与审计。
 *
 * 解析不出任何人时**不抛异常**：任务照常以 pending 落库、流程继续推进，
 * 但会记 WARNING（否则就是「任务生成了但没人能推」这种最难查的故障）。
 *
 * ── 未实现的部分 ──────────────────────────────────────────────────────────
 * `saveProcessTask/updateProcessTask`（空接口无字段声明）、`transfer`（缺转办历史表）、
 * `history`（缺任务历史表）、`jumpAbleTaskNameList`（缺已访问节点轨迹）、
 * `candidatePage`（未定义的分页 DTO）、`created/updated/list`（未定义 DTO）一律抛异常并说明原因。
 *
 * @package Alkaid\Workflow\Engine\Services
 */
class ProcessTaskService implements IProcessTaskService
{
    use ResolvesWorkflowTenant;
    use RecordsHistorySafely;

    /**
     * 本服务负责的表
     */
    private const TABLE = 'workflow_tasks';

    /**
     * 「进行中」任务对应的表状态集合（pending/claimed/escalated 都算未完成）
     *
     * @var string[]
     */
    private const DOING_STATUSES = ['pending', 'claimed', 'escalated'];

    /**
     * 按主键读取任务
     */
    public function getById(string|int $id): ?IProcessTask
    {
        $taskId = $this->numericId($id);

        if ($taskId === null) {
            return null;
        }

        $row = $this->scoped(self::TABLE, '读取工作流任务')->find($taskId);

        return $row === null ? null : ProcessTaskEntity::fromRow($row);
    }

    /**
     * 按主键读取任务（getById 的别名）
     */
    public function findById(string|int $id): ?IProcessTask
    {
        return $this->getById($id);
    }

    /**
     * 读取流程实例下「进行中」的任务
     *
     * @param string|int        $processInstanceId 实例ID
     * @param string|int|array  $taskNames         节点名（空串/空数组表示不过滤）
     */
    public function getDoingTaskList(string|int $processInstanceId, string|int|array $taskNames): ?array
    {
        $instanceId = $this->numericId($processInstanceId);

        if ($instanceId === null) {
            throw new LFlowException(sprintf(
                '读取进行中任务失败：流程实例ID「%s」不是合法的整数主键',
                (string) $processInstanceId
            ));
        }

        $query = $this->scoped(self::TABLE, '读取进行中任务')
            ->query()
            ->where('instance_id', (string) $instanceId)
            ->whereIn('status', self::DOING_STATUSES);

        $names = array_values(array_filter(
            array_map(static fn ($name) => is_scalar($name) ? (string) $name : '', (array) $taskNames),
            static fn (string $name) => $name !== ''
        ));

        if ($names !== []) {
            $query->whereIn('node_name', $names);
        }

        $rows = $query->order('id', 'asc')->select()->toArray();

        return array_map(static fn (array $row) => ProcessTaskEntity::fromRow($row), $rows);
    }

    /**
     * 创建任务（普通任务，一个节点一条记录）
     *
     * @return array<int, ProcessTaskEntity>
     */
    public function createTask(TaskModel $taskModel, IExecution $execution): ?array
    {
        return [$this->insertTask($taskModel, $execution)];
    }

    /**
     * 创建会签任务（一个参与者一条记录）
     *
     * 参与者同样走解析器：角色/部门会被展开成具体的人，然后**一人一条任务**。
     * 展开失败（角色不存在等）时不会伪造参与者，也不会抛异常卡住流程 ——
     * 解析结果为空时这里才报错，因为「会签节点一个人都没有」等于会签永远签不完。
     *
     * @return array<int, ProcessTaskEntity>
     */
    public function createCountersignTask(TaskModel $taskModel, IExecution $execution): IProcessTask|array|null
    {
        $resolution = $this->resolveNodeActors($taskModel, $this->executionVariables($execution), null);
        $actors = $resolution->candidateUserIds;

        if ($actors === []) {
            throw new LFlowException(sprintf(
                '会签节点 %s 没有可用的参与者，无法创建会签任务（%s）',
                (string) $taskModel->getName(),
                $resolution->warnings === []
                    ? '节点未配置 assignee / candidate_ext_users / candidate_ext_groups'
                    : '解析告警：' . implode('；', $resolution->warnings)
            ));
        }

        $tasks = [];

        foreach ($actors as $actor) {
            // 每个会签人一条记录：处理人是他自己，候选人也只放他自己（不允许代签）
            $tasks[] = $this->insertTask($taskModel, $execution, $resolution->forActor($actor));
        }

        return $tasks;
    }

    /**
     * 任务完成（状态置 completed，写完成时间、处理结果与审批意见）
     *
     * 状态更新成功之后追加一条 `task_completed` 事件（`workflow_event_logs`）。
     * 事件写入是旁路审计：失败只记 WARNING，不影响本方法的结果（见 recordHistorySafely）。
     */
    public function finishProcessTask(string|int $processTaskId, string|int $operator, IDict $args): void
    {
        $taskId = $this->requireTaskId($processTaskId, '完成流程任务');
        $row = $this->findRowOrFail($taskId, '完成流程任务');

        $timestamp = date('Y-m-d H:i:s');
        $comment = $args->get(ProcessConstEnum::APPROVAL_COMMENT->value);

        $data = [
            'status'       => WorkflowStateMap::engineToTask(ProcessTaskStateEnum::FINISHED->value),
            'completed_at' => $timestamp,
            'result'       => json_encode($args->toArray(), JSON_UNESCAPED_UNICODE),
            'updated_at'   => $timestamp,
        ];

        if (is_scalar($comment) && (string) $comment !== '') {
            $data['comment'] = (string) $comment;
        }

        // 有真实操作人时记录为该任务的最终处理人（原来可能是空）
        $operatorId = $this->userIdOrNull($operator);

        if ($operatorId !== null) {
            $data['assignee'] = $operatorId;
            // 候选池任务（建任务时没有 assignee）被认领后，姓名要跟着补上 ——
            // 否则列表里会出现「assignee=996667 / assignee_name=NULL」这种半截数据
            $operatorName = $this->resolveUserName($operatorId);

            if ($operatorName !== null) {
                $data['assignee_name'] = $operatorName;
            }
        }

        $this->scoped(self::TABLE, '完成流程任务')->updateById($taskId, $data);

        // 任务状态已经落库 → 追加轨迹事件（顺序：先状态、后审计，审计失败不回滚状态）
        $instanceId = (string) ($row['instance_id'] ?? '');
        $this->recordHistorySafely('记录任务完成事件', function ($history) use ($instanceId, $taskId, $operator, $row, $data): void {
            $history->recordTaskCompleted($instanceId, $taskId, $operator, [
                'node_name' => $row['node_name'] ?? null,
                'title'     => $row['title'] ?? null,
                'comment'   => $data['comment'] ?? null,
                'status'    => $data['status'],
            ]);
        });
    }

    /**
     * 废弃任务（状态置 cancelled，写取消时间）
     */
    public function abandonProcessTask(string $processTaskId, string $operator, IDict $args): void
    {
        $taskId = $this->requireTaskId($processTaskId, '废弃流程任务');
        $this->findRowOrFail($taskId, '废弃流程任务');

        $timestamp = date('Y-m-d H:i:s');
        $comment = $args->get(ProcessConstEnum::APPROVAL_COMMENT->value);

        $data = [
            'status'       => 'cancelled',
            'cancelled_at' => $timestamp,
            'updated_at'   => $timestamp,
        ];

        if (is_scalar($comment) && (string) $comment !== '') {
            $data['comment'] = (string) $comment;
        }

        $this->scoped(self::TABLE, '废弃流程任务')->updateById($taskId, $data);
    }

    /**
     * 驳回任务：当前任务置 rejected，并在「上一个任务节点」新建一条待办
     *
     * 上一个节点从流程模型的输入边取（ModelParser 会给每条输入边设置 source，
     * 见 src/parser/AbstractINodeParser.php:63）。取不到任务节点就报错，
     * 而不是把任务随手丢给发起人。
     *
     * @param ProcessModel $model      流程模型
     * @param IProcessTask $currentTask 当前任务
     */
    public function rejectTask(ProcessModel $model, IProcessTask $currentTask): ?IProcessTask
    {
        $taskId = $this->requireTaskId($currentTask->getData('id') ?? '', '驳回流程任务');
        $row = $this->findRowOrFail($taskId, '驳回流程任务');

        $nodeName = (string) $currentTask->getData('task_name', '');
        $node = $nodeName === '' ? null : $model->getNode($nodeName);

        if ($node === null) {
            throw new LFlowException(sprintf('驳回失败：流程模型中找不到当前任务节点 [%s]', $nodeName));
        }

        $previousTaskNode = null;

        foreach ($node->getInputs() as $transition) {
            $source = $transition->getSource();

            if ($source instanceof TaskModel) {
                $previousTaskNode = $source;
                break;
            }
        }

        if ($previousTaskNode === null) {
            throw new LFlowException(sprintf(
                '驳回失败：节点 [%s] 之前没有可回退的任务节点（本仓库未实现「驳回到发起人」的语义）',
                $nodeName
            ));
        }

        $timestamp = date('Y-m-d H:i:s');

        // 1）当前任务置为已驳回
        $this->scoped(self::TABLE, '驳回流程任务')->updateById($taskId, [
            'status'      => 'rejected',
            'rejected_at' => $timestamp,
            'updated_at'  => $timestamp,
        ]);

        // 2）在上一个任务节点重建待办，处理人沿用原任务的处理人
        //
        // 处理人**沿用**被驳回的那条任务的处理人（他刚才审的就是这个节点），
        // 但候选人仍然按上一个节点自己的配置展开（角色/部门照旧生效），
        // 两者并集后写进 candidate_users，避免「驳回之后没人能接着办」。
        $columns = $this->buildTaskColumns(
            $previousTaskNode,
            (string) $row['instance_id'],
            (string) ($row['created_by'] ?? ''),
            ProcessTaskPerformTypeEnum::NORMAL->value,
            [],
            $this->resolveNodeActors(
                $previousTaskNode,
                [],
                $row['assignee'] ?? null
            )
        );

        return $this->insertTaskRow($columns);
    }

    /**
     * 解析任务节点配置里声明的参与者（用于会签）
     *
     * 返回值是**展开后**的用户ID（角色/部门已变成具体的人，见 {@see ITaskActorResolver}）。
     *
     * @return string[] 参与者ID列表
     */
    public function getTaskActors(TaskModel $model, IExecution $execution): array
    {
        $resolution = $this->resolveNodeActors($model, $this->executionVariables($execution), null);

        return array_map(static fn (int $id): string => (string) $id, $resolution->candidateUserIds);
    }

    /**
     * 读取任务当前的参与者
     *
     * @param mixed $processTaskId 任务ID
     * @return string[]
     */
    public function getTaskActor($processTaskId): array
    {
        $task = $this->getById(is_scalar($processTaskId) ? (string) $processTaskId : '');

        return $task instanceof ProcessTaskEntity ? $task->getActorIds() : [];
    }

    /**
     * 追加任务参与者
     *
     * 本仓库没有任务参与者表，合并进 `workflow_tasks.candidate_users`；
     * `assignee` 为空时同时补上第一个参与者（否则任务会「有人可办但没人负责」）。
     */
    public function addTaskActor(string|int $processTaskId, array|string|int $actors): void
    {
        $taskId = $this->requireTaskId($processTaskId, '追加任务参与者');
        $row = $this->findRowOrFail($taskId, '追加任务参与者');

        $ids = $this->normalizeActors($actors, '追加任务参与者');

        if ($ids === []) {
            return;
        }

        $candidates = $this->decodeJsonColumn($row['candidate_users'] ?? null);
        $merged = array_values(array_unique(array_merge(
            array_map(static fn ($item) => (string) $item, $candidates),
            $ids
        )));

        $data = [
            'candidate_users' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        if (!isset($row['assignee']) || $row['assignee'] === null || $row['assignee'] === '') {
            $data['assignee'] = (int) $merged[0];
            // 与 finishProcessTask() 同口径：assignee 变了，姓名也要跟上
            $name = $this->resolveUserName((int) $merged[0]);

            if ($name !== null) {
                $data['assignee_name'] = $name;
            }
        }

        $this->scoped(self::TABLE, '追加任务参与者')->updateById($taskId, $data);
    }

    /**
     * 追加候选参与者（与 addTaskActor 同口径，只写 candidate_users，不动 assignee）
     */
    public function addCandidateActor(string|int $processTaskId, array|string $actors): void
    {
        $taskId = $this->requireTaskId($processTaskId, '追加候选参与者');
        $row = $this->findRowOrFail($taskId, '追加候选参与者');

        $ids = $this->normalizeActors($actors, '追加候选参与者');

        if ($ids === []) {
            return;
        }

        $merged = array_values(array_unique(array_merge(
            array_map(static fn ($item) => (string) $item, $this->decodeJsonColumn($row['candidate_users'] ?? null)),
            $ids
        )));

        $this->scoped(self::TABLE, '追加候选参与者')->updateById($taskId, [
            'candidate_users' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 移除任务参与者（只动 candidate_users；assignee 不清空，避免任务变成无人负责）
     */
    public function removeTaskActor(string|int $processTaskId, array|string $actors): void
    {
        $taskId = $this->requireTaskId($processTaskId, '移除任务参与者');
        $row = $this->findRowOrFail($taskId, '移除任务参与者');

        $ids = $this->normalizeActors($actors, '移除任务参与者');

        if ($ids === []) {
            return;
        }

        $remaining = array_values(array_filter(
            array_map(static fn ($item) => (string) $item, $this->decodeJsonColumn($row['candidate_users'] ?? null)),
            static fn (string $item) => !in_array($item, $ids, true)
        ));

        $this->scoped(self::TABLE, '移除任务参与者')->updateById($taskId, [
            'candidate_users' => json_encode($remaining, JSON_UNESCAPED_UNICODE),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 判断操作人是否有权执行该任务
     *
     * 放行条件（任一成立）：
     * 1）操作人是引擎的系统身份：`flow_auto`（自动执行）/ `flow.admin`（超级管理员），
     *    取值见 ProcessConstEnum::AUTO_ID / ADMIN_ID；
     * 2）操作人是任务的 assignee；
     * 3）操作人在 candidate_users 里。
     */
    public function isAllowed(IProcessTask $task, string|int $operator): bool
    {
        $operatorId = (string) $operator;

        if ($operatorId === ProcessConstEnum::AUTO_ID->value
            || $operatorId === ProcessConstEnum::ADMIN_ID->value) {
            return true;
        }

        if ($operatorId === '') {
            return false;
        }

        if ($task instanceof ProcessTaskEntity) {
            return in_array($operatorId, $task->getActorIds(), true);
        }

        // 非本实体的实现：只能按引擎视角的 assignee 判断，宁可拒绝也不越权放行
        $assignee = $task->getData('assignee');

        return $assignee !== null && (string) $assignee === $operatorId;
    }

    /**
     * 代理人解析
     *
     * ⚠️ 本方法**不在** IProcessTaskService 里，但引擎会调用它
     * （src/domain/SurrogateInterceptorI.php:34)。本仓库没有 `workflow_surrogate` 表，
     * 因此没有可返回的代理人：返回 null 的语义是「没有配置代理人」，
     * 拦截器会继续使用原处理人 —— 这是如实回答，不是占位实现。
     */
    public function getSurrogate(string|int $operator, string|int $processName): ?string
    {
        return null;
    }

    /**
     * 组装并写入一条任务记录
     *
     * @param TaskModel                $taskModel  任务节点模型
     * @param IExecution               $execution  执行对象
     * @param TaskActorResolution|null $resolution 参与者解析结果（会签逐人建任务时由调用方传入；
     *                                             不传则按节点配置实时解析）
     */
    private function insertTask(
        TaskModel $taskModel,
        IExecution $execution,
        ?TaskActorResolution $resolution = null
    ): ProcessTaskEntity {
        $performType = $taskModel->getPerformType();

        if ($performType instanceof \BackedEnum) {
            $performType = $performType->value;
        }

        $variables = $this->executionVariables($execution);

        $columns = $this->buildTaskColumns(
            $taskModel,
            (string) $execution->getProcessInstanceId(),
            (string) $execution->getOperator(),
            $performType,
            $variables,
            $resolution ?? $this->resolveNodeActors(
                $taskModel,
                $variables,
                $this->firstTaskApplicant($taskModel, $execution)
            )
        );

        return $this->insertTaskRow($columns);
    }

    /**
     * 申请节点（第一个任务节点）的处理人 = 流程发起人
     *
     * ## 为什么必须在这里补
     *
     * 「第一个任务节点是申请节点，处理人等于发起人」这条规则，引擎原本只写在
     * `ProcessEngines::executeAndJumpTask()` / `executeAndJumpToFirstTaskNode()`
     * 这两个**跳转**路径里；正常启动（`startProcessInstanceById()` → 开始节点 →
     * 第一个任务）这一最常用路径上**根本没设**，于是申请任务的处理人是 null、
     * 候选人也是空集 —— 结果就是发起人自己都办不了自己的申请任务，
     * 只有 `flow_auto` / `flow.admin` 能推进（这正是既有 E2E 测试一直用 `flow_auto` 的原因）。
     *
     * 这里按**同一条语义**补上，且只作用于第一个任务节点，其它节点完全不受影响。
     * 取值口径是实例的 `created_by`（创建实例时写入的发起人ID）；
     * 顺带兼容宿主把发起人放在 `operator` 字段上的实现。
     *
     * ⚠️ 这里用 `is_callable()` 而不是 `method_exists()` 做能力探测：
     * `Execution` 的 getter 全是 `DynamicPropsTrait::__call()` 变出来的魔术方法，
     * 而 `method_exists()` **看不见魔术方法**（恒为 false）——
     * 用它守卫会得到「能力永远缺失」的错误结论（本人第一次就是这么写错的，
     * 结果是申请节点处理人仍然为空，且 `executionVariables()` 永远返回空数组）。
     *
     * @param TaskModel  $taskModel 任务节点模型
     * @param IExecution $execution 执行对象
     * @return string|null 发起人ID（取不到 / 不是第一个任务节点时为 null）
     */
    private function firstTaskApplicant(TaskModel $taskModel, IExecution $execution): ?string
    {
        $nodeName = (string) $taskModel->getName();

        if ($nodeName === '' || !is_callable([$execution, 'getProcessModel'])) {
            return null;
        }

        $processModel = $execution->getProcessModel();

        if (!$processModel instanceof ProcessModel) {
            return null;
        }

        if (!ProcessFlowUtils::isFistTaskName($processModel, $nodeName)) {
            return null;
        }

        $instance = is_callable([$execution, 'getProcessInstance']) ? $execution->getProcessInstance() : null;

        if (!is_object($instance) || !is_callable([$instance, 'getData'])) {
            return null;
        }

        foreach (['created_by', 'operator'] as $field) {
            $value = $instance->getData($field);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * 由任务节点模型 + 实例信息组装任务列
     *
     * 参与者相关的三列（`assignee` / `assignee_name` / `candidate_users` / `candidate_groups`）
     * 全部来自解析结果，不再「把 assignee 复制一份当候选人」。
     *
     * @param TaskModel          $taskModel  任务节点模型
     * @param string             $instanceId 实例ID
     * @param string             $operator   操作人
     * @param mixed              $performType 参与类型（引擎 ProcessTaskPerformTypeEnum）
     * @param array              $variables  运行时变量（表达式求值与到期时间解析用）
     * @param TaskActorResolution $resolution 参与者解析结果
     * @return array<string, mixed>
     */
    private function buildTaskColumns(
        TaskModel $taskModel,
        string $instanceId,
        string $operator,
        mixed $performType,
        array $variables = [],
        ?TaskActorResolution $resolution = null
    ): array {
        $instanceId = trim($instanceId);

        if ($instanceId === '') {
            throw new LFlowException('创建流程任务失败：执行对象没有流程实例ID');
        }

        // 引擎后续按 task_name 回查节点模型（ProcessEngines:99），必须是**节点名**，
        // 因此 node_name 存节点名，显示名放 title。
        $nodeName = (string) $taskModel->getName();

        if ($nodeName === '') {
            throw new LFlowException('创建流程任务失败：任务节点没有名称（节点名是回查节点模型的唯一键）');
        }

        $displayName = (string) $taskModel->getDisplayName();
        $isCountersign = StringHelper::equalsIgnoreCase(
            $performType instanceof \BackedEnum ? $performType->value : $performType,
            ProcessTaskPerformTypeEnum::COUNTERSIGN->value
        );

        $resolution ??= $this->resolveNodeActors($taskModel, $variables, null);

        $this->reportActorWarnings($nodeName, $instanceId, $resolution);

        $timestamp = date('Y-m-d H:i:s');

        return [
            'instance_id'      => $instanceId,
            'tenant_id'        => $this->tenantId('创建流程任务'),
            'node_id'          => $nodeName,
            'node_name'        => $nodeName,
            // 引擎的 TaskModel 表示「人工任务节点」（系统/服务节点是 CustomModel），
            // 因此 task_type 固定 user_task；会签/普通审批落在 task_sub_type。
            'task_type'        => 'user_task',
            'task_sub_type'    => $isCountersign ? 'countersign' : 'approval',
            'title'            => mb_substr($displayName !== '' ? $displayName : $nodeName, 0, 200),
            'description'      => null,
            // 有处理人 = 已指派（claimed）；只有候选人 / 谁都没有 = 待办（pending，谁抢谁办）
            'status'           => $resolution->assignee !== null ? 'claimed' : 'pending',
            'priority'         => $this->resolvePriority($taskModel),
            'assignee'         => $resolution->assignee,
            'assignee_name'    => $resolution->assigneeName,
            'candidate_users'  => json_encode($resolution->candidateUserIds, JSON_UNESCAPED_UNICODE),
            'candidate_groups' => json_encode($resolution->candidateGroupRefs, JSON_UNESCAPED_UNICODE),
            'form_data'        => null,
            'result'           => null,
            'comment'          => null,
            'due_date'         => $this->resolveDueDate($taskModel, $variables),
            'reminder_times'   => json_encode([], JSON_UNESCAPED_UNICODE),
            'completed_at'     => null,
            'rejected_at'      => null,
            'cancelled_at'     => null,
            'created_at'       => $timestamp,
            'updated_at'       => $timestamp,
            'created_by'       => $this->userIdOrNull($operator),
        ];
    }

    /**
     * 把参与者解析的告警写进日志（WARN）
     *
     * 两类都要记：
     * 1）解析过程中被拒绝/跳过的引用（角色不存在、跨租户、dept 无法展开……）；
     * 2）解析完一个人都没有 —— 任务照样落库，但**必须留下痕迹**，
     *    否则「流程推进了、任务也生成了、就是没人能办」只能靠人肉排查。
     *
     * @param string              $nodeName   节点名
     * @param string              $instanceId 实例ID
     * @param TaskActorResolution $resolution 解析结果
     */
    private function reportActorWarnings(string $nodeName, string $instanceId, TaskActorResolution $resolution): void
    {
        foreach ($resolution->warnings as $warning) {
            $this->logHistory('warning', sprintf(
                '任务参与者解析告警（实例 %s / 节点 %s）：%s',
                $instanceId,
                $nodeName,
                $warning
            ));
        }

        if (!$resolution->hasNoActor()) {
            return;
        }

        $this->logHistory('warning', sprintf(
            '任务「%s」（实例 %s）创建时没有解析出任何可执行参与者：assignee=null，candidate_users=[]，candidate_groups=%s。'
            . '任务会以 pending 落库，但普通用户无法推进（只有 %s / %s 这类系统身份能执行）。'
            . '请检查节点的参与者配置，或确认对应角色在本租户内是否已有成员',
            $nodeName,
            $instanceId,
            json_encode($resolution->candidateGroupRefs, JSON_UNESCAPED_UNICODE),
            ProcessConstEnum::AUTO_ID->value,
            ProcessConstEnum::ADMIN_ID->value
        ));
    }

    /**
     * 调解析器解析节点参与者（解析器由容器注册，宿主可替换）
     *
     * 为什么取不到就 new 默认实现，而不是抛「容器没装配」：
     * 任务创建是引擎的基础路径，插件也可能被独立使用（CLI / 单元测试没有容器）。
     * 默认实现与容器默认注册的是**同一个类**，行为一致，不存在「测试跑一套、线上跑另一套」。
     * 宿主替换解析器时会注册到容器里，这里就会优先用宿主的实现。
     *
     * @param TaskModel          $taskModel         任务节点模型
     * @param array              $variables         运行时变量
     * @param string|int|null    $preferredAssignee 强制处理人（驳回沿用原处理人）
     */
    private function resolveNodeActors(
        TaskModel $taskModel,
        array $variables,
        string|int|null $preferredAssignee
    ): TaskActorResolution {
        return $this->actorResolver()->resolve(
            $taskModel,
            $variables,
            '任务节点 ' . (string) $taskModel->getName(),
            $preferredAssignee
        );
    }

    /**
     * 取参与者解析器（容器优先，取不到回落到默认实现）
     */
    private function actorResolver(): ITaskActorResolver
    {
        try {
            $resolver = ServiceContext::find(ITaskActorResolver::class);
        } catch (\Throwable) {
            // 容器未初始化 / PHP-DI 缺失：与「未注册」同口径，回落默认实现
            $resolver = null;
        }

        return $resolver instanceof ITaskActorResolver ? $resolver : new DefaultTaskActorResolver();
    }

    /**
     * 取人员目录（容器优先，取不到回落到默认实现）
     */
    private function actorDirectory(): ITaskActorDirectory
    {
        try {
            $directory = ServiceContext::find(ITaskActorDirectory::class);
        } catch (\Throwable) {
            $directory = null;
        }

        return $directory instanceof ITaskActorDirectory ? $directory : new DefaultTaskActorDirectory();
    }

    /**
     * 查某个用户在本租户内的显示名（`users.name`，为空回落 `username`）
     *
     * 只用于「认领 / 完成时把 assignee_name 补上」这类**展示性**字段：
     * 查不到就返回 null，绝不让它把任务完成这条主链路带崩。
     * 租户过滤由目录实现负责（跨租户会查不到 → null）。
     */
    private function resolveUserName(int $userId): ?string
    {
        try {
            $names = $this->actorDirectory()->findUserNames($this->tenantId('回填任务处理人姓名'), [$userId]);
        } catch (\Throwable) {
            return null;
        }

        $name = $names[$userId] ?? null;

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /**
     * 取本次执行可见的流程变量（`${...}` 表达式与到期时间的求值上下文）
     *
     * 来源两级，后者覆盖前者：
     * 1）流程实例变量（`workflow_instances.variables`，启动参数即流程变量）；
     * 2）本次执行参数（`Execution::getArgs()`，`executeProcessTask()` 传进来的审批参数）。
     *
     * `IExecution` 接口里这些方法全被注释掉了（上游遗留），因此只能守卫式调用，
     * 取不到就当「没有变量」——不做任何猜测。
     *
     * ⚠️ 守卫必须用 `is_callable()`：`Execution` 的 getter 是 `__call()` 魔术方法，
     * `method_exists()` 对它们恒为 false，用它守卫会让本方法**永远返回空数组**，
     * 于是 `${applicant_id}` 这类表达式与「到期时间取变量」都会静默失效。
     *
     * @return array<string, mixed>
     */
    private function executionVariables(IExecution $execution): array
    {
        $variables = [];

        if (is_callable([$execution, 'getProcessInstance'])) {
            $instance = $execution->getProcessInstance();

            if (is_object($instance) && is_callable([$instance, 'getData'])) {
                $stored = $instance->getData('variable');

                if (is_array($stored)) {
                    $variables = $stored;
                }
            }
        }

        if (is_callable([$execution, 'getArgs'])) {
            $args = $execution->getArgs();

            if ($args instanceof IDict) {
                $variables = array_merge($variables, $args->toArray());
            }
        }

        return $variables;
    }

    /**
     * 解析任务优先级（`properties.field.priority`，没有就保持既有默认 5）
     *
     * 为什么读 `ext` 而不是 `properties`：`TaskParser` 只把自定义扩展属性
     * （LogicFlow 节点的 `properties.field`）整体塞进 `TaskModel::$ext`，
     * 顶层的 `properties.priority` 没有被任何解析器接住。凭空去读一个没人写入的键，
     * 只会得到「永远是默认值」的假实现。
     */
    private function resolvePriority(TaskModel $taskModel): int
    {
        $ext = $taskModel->getExt();
        $raw = $ext instanceof IDict ? $ext->get('priority') : null;

        if (!is_scalar($raw) || trim((string) $raw) === '') {
            return 5;
        }

        // 列注释口径是 1-10，越界按边界收敛（写 0 或 99 只会让排序失去意义）
        return max(1, min(10, (int) $raw));
    }

    /**
     * 解析任务到期时间（`properties.expire_time`）
     *
     * ── 语义（已查证，不猜）────────────────────────────────────────────────
     * `INodeParser::EXPIRE_TIME_KEY = 'expire_time'` 的注释写的是
     * 「期待任务完成时间**变量key**」，而它的唯一实现是
     * {@see ProcessFlowUtils::processTime()}，支持三种形态：
     * 1）**运行时变量名**：变量里存的可以是 DateTime / 时间戳 / 时间字符串；
     * 2）**时长**：`30m` / `2h` / `1d` / `60s`，表示「从现在起 N 个单位」；
     * 3）**绝对时间串**：能被 `new DateTime()` 解析的字符串。
     *
     * 因此这里直接复用该工具，不另写一套解析：
     * - 变量上下文就是流程变量（见 {@see self::executionVariables()}）；
     * - 结果统一换算到 PHP 默认时区再格式化 —— `new DateTime("@timestamp")` 得到的是 UTC，
     *   不换算就会与 `created_at`（`date()`，默认时区）差几个小时，看起来像「时区错乱」；
     * - 解析不出来就保持 null 并记 WARNING（不编一个时间，也不让建任务失败）。
     */
    private function resolveDueDate(TaskModel $taskModel, array $variables): ?string
    {
        $expireTime = $taskModel->getExpireTime();

        if (!is_scalar($expireTime) || trim((string) $expireTime) === '') {
            return null;
        }

        $args = new Dict();
        $args->putAll($variables);

        try {
            $resolved = ProcessFlowUtils::processTime((string) $expireTime, $args);
        } catch (\Throwable $e) {
            $this->logHistory('warning', sprintf(
                '任务节点 %s 的到期时间「%s」解析失败（%s: %s），due_date 留空',
                (string) $taskModel->getName(),
                (string) $expireTime,
                get_debug_type($e),
                $e->getMessage()
            ));

            return null;
        }

        if ($resolved === null) {
            return null;
        }

        return $resolved
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }

    /**
     * 写库并返回实体
     *
     * @param array<string, mixed> $columns 列数据
     */
    private function insertTaskRow(array $columns): ProcessTaskEntity
    {
        $insertedId = $this->scoped(self::TABLE, '创建流程任务')->insert($columns);

        if (empty($insertedId)) {
            throw new LFlowException('创建流程任务失败：数据库未返回自增主键');
        }

        return ProcessTaskEntity::fromRow(['id' => (int) $insertedId] + $columns);
    }

    /**
     * 把「显式传入的参与者」拆成参与者ID列表（用于 addTaskActor / removeTaskActor 这类 API）
     *
     * ⚠️ 与节点配置的解析**不是同一条路径**：节点配置走 {@see ITaskActorResolver}
     * （支持 `user:` / `role:` / `dept:` / `${表达式}`），而这里接收的是调用方
     * 明确给出的用户ID（转办、追加参与者），因此只接受**数字ID**，
     * 遇到别的形态直接报错 —— 静默丢弃会产生「加了个不存在的参与者」的暗坑。
     *
     * @param string $raw       原始参与者串（逗号/分号/竖线/空格分隔）
     * @param string $operation 操作描述
     * @return string[]
     */
    private function parseActorTokens(string $raw, string $operation): array
    {
        $tokens = preg_split('/[,;|\s]+/u', trim($raw)) ?: [];
        $actors = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            if (!ctype_digit($token) || (int) $token <= 0) {
                throw new LFlowException(sprintf(
                    '%s：参与者「%s」不是数字用户ID，无法解析。'
                    . '本方法只接受明确的用户ID；'
                    . '按角色/部门/表达式指派请配在节点上（assignee / candidate_ext_users / candidate_ext_groups），'
                    . '由参与者解析器统一展开',
                    $operation,
                    $token
                ));
            }

            $actors[] = (string) (int) $token;
        }

        return array_values(array_unique($actors));
    }

    /**
     * 把「单个ID / 数组ID」统一成参与者字符串列表
     *
     * @param array|string|int $actors 参与者
     * @param string           $operation 操作描述
     * @return string[]
     */
    private function normalizeActors(array|string|int $actors, string $operation): array
    {
        $list = [];

        foreach ((array) $actors as $actor) {
            if (!is_scalar($actor)) {
                throw new LFlowException(sprintf('%s：参与者必须是标量ID，收到 %s', $operation, get_debug_type($actor)));
            }

            foreach ($this->parseActorTokens((string) $actor, $operation) as $id) {
                $list[] = $id;
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * 收敛任务主键（写操作，非法主键直接报错）
     *
     * @param string|int $processTaskId 任务ID
     * @param string     $operation     操作描述
     */
    private function requireTaskId(string|int $processTaskId, string $operation): int
    {
        $id = $this->numericId($processTaskId);

        if ($id === null) {
            throw new LFlowException(sprintf(
                '%s失败：流程任务ID「%s」不是合法的整数主键',
                $operation,
                (string) $processTaskId
            ));
        }

        return $id;
    }

    /**
     * 在租户作用域内读取任务行，不存在则报错
     *
     * @param int    $id        任务ID
     * @param string $operation 操作描述
     * @return array<string, mixed>
     */
    private function findRowOrFail(int $id, string $operation): array
    {
        $row = $this->scoped(self::TABLE, $operation)->find($id);

        if ($row === null) {
            throw new LFlowException(sprintf('%s失败：流程任务 #%d 不存在或不属于当前租户', $operation, $id));
        }

        return $row;
    }

    /**
     * 解码 JSON 列（Db 取回的 json 列是字符串）
     *
     * @param mixed $value 原始列值
     * @return array<int|string, mixed>
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
    public function created(object $param): ?IProcessTask
    {
        $this->unimplemented(
            __METHOD__,
            '入参是未定义的 DTO（object $param）；任务创建的真实入口是 createTask() / createCountersignTask()'
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
    public function saveProcessTask(IProcessTask $processTask): void
    {
        $this->unimplemented(
            __METHOD__,
            '空接口 IProcessTask 没有字段声明，无法确定要写哪些列；创建入口已由 createTask() 覆盖'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function updateProcessTask(IProcessTask $processTask): void
    {
        $this->unimplemented(
            __METHOD__,
            '空接口 IProcessTask 没有字段声明，无法确定待更新的列集合；'
            . '状态流转由 finishProcessTask / abandonProcessTask / rejectTask 精确覆盖'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function transfer(object $param): ?IProcessTaskActor
    {
        $this->unimplemented(
            __METHOD__,
            '转办需要「转办历史」表与 IProcessTaskActor 的持久化实体，本仓库 5 张表里都没有'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function jumpAbleTaskNameList(string|int $processInstanceId): array
    {
        $this->unimplemented(
            __METHOD__,
            '「可跳转节点」需要已访问节点轨迹与流程模型匹配，本仓库没有节点轨迹表'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function candidatePage(IDict $query): object|array
    {
        $this->unimplemented(
            __METHOD__,
            '候选任务分页的查询条件（IDict）没有键名约定，返回结构（object|array）也未约定'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function history(IExecution $execution, CustomModel $model): ?IProcessTask
    {
        $this->unimplemented(
            __METHOD__,
            '祖先任务查询需要任务历史表，本仓库 5 张表里没有'
        );
    }
}
