<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Services;

use Alkaid\Workflow\Engine\Entities\ProcessTaskEntity;
use Alkaid\Workflow\Engine\Entities\WorkflowStateMap;
use Alkaid\Workflow\Engine\enums\ProcessConstEnum;
use Alkaid\Workflow\Engine\enums\ProcessTaskPerformTypeEnum;
use Alkaid\Workflow\Engine\enums\ProcessTaskStateEnum;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\interface\IExecution;
use Alkaid\Workflow\Engine\interface\model\IProcessTask;
use Alkaid\Workflow\Engine\interface\model\IProcessTaskActor;
use Alkaid\Workflow\Engine\interface\services\IProcessTaskService;
use Alkaid\Workflow\Engine\libs\utils\StringHelper;
use Alkaid\Workflow\Engine\model\CustomModel;
use Alkaid\Workflow\Engine\model\ProcessModel;
use Alkaid\Workflow\Engine\model\TaskModel;
use Alkaid\Workflow\Engine\Services\Support\ResolvesWorkflowTenant;
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
        $assignee = $this->firstActorId($taskModel->getAssignee(), '任务节点 ' . (string) $taskModel->getName());

        return [$this->insertTask($taskModel, $execution, $assignee)];
    }

    /**
     * 创建会签任务（一个参与者一条记录）
     *
     * @return array<int, ProcessTaskEntity>
     */
    public function createCountersignTask(TaskModel $taskModel, IExecution $execution): IProcessTask|array|null
    {
        $actors = $this->getTaskActors($taskModel, $execution);

        if ($actors === []) {
            throw new LFlowException(sprintf(
                '会签节点 %s 没有可用的参与者，无法创建会签任务'
                . '（本仓库没有 IProcessUser / ICandidateHandler 实现，无法从角色/部门/表达式解析参与者）',
                (string) $taskModel->getName()
            ));
        }

        $tasks = [];

        foreach ($actors as $actor) {
            $tasks[] = $this->insertTask($taskModel, $execution, $actor);
        }

        return $tasks;
    }

    /**
     * 任务完成（状态置 completed，写完成时间、处理结果与审批意见）
     */
    public function finishProcessTask(string|int $processTaskId, string|int $operator, IDict $args): void
    {
        $taskId = $this->requireTaskId($processTaskId, '完成流程任务');
        $this->findRowOrFail($taskId, '完成流程任务');

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
        }

        $this->scoped(self::TABLE, '完成流程任务')->updateById($taskId, $data);
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
        $assignee = $this->firstActorId($row['assignee'] ?? null, '驳回目标节点 ' . (string) $previousTaskNode->getName());

        $columns = $this->buildTaskColumns(
            $previousTaskNode,
            (string) $row['instance_id'],
            (string) ($row['created_by'] ?? ''),
            $assignee,
            ProcessTaskPerformTypeEnum::NORMAL->value
        );

        return $this->insertTaskRow($columns);
    }

    /**
     * 解析任务节点配置里声明的参与者（用于会签）
     *
     * @return string[] 参与者ID列表
     */
    public function getTaskActors(TaskModel $model, IExecution $execution): array
    {
        $tokens = [];

        $assignee = $model->getAssignee();
        if (is_scalar($assignee) && (string) $assignee !== '') {
            $tokens[] = (string) $assignee;
        }

        $candidates = $model->getCandidateUsers();
        if (is_scalar($candidates) && (string) $candidates !== '') {
            $tokens[] = (string) $candidates;
        }

        $actors = [];

        foreach ($tokens as $token) {
            $actors = array_merge($actors, $this->parseActorTokens($token, '任务节点 ' . (string) $model->getName()));
        }

        return array_values(array_unique($actors));
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
     * @param TaskModel  $taskModel 任务节点模型
     * @param IExecution $execution 执行对象
     * @param string     $assignee  处理人ID（空串表示暂不指派）
     */
    private function insertTask(TaskModel $taskModel, IExecution $execution, string $assignee): ProcessTaskEntity
    {
        $performType = $taskModel->getPerformType();

        if ($performType instanceof \BackedEnum) {
            $performType = $performType->value;
        }

        $columns = $this->buildTaskColumns(
            $taskModel,
            (string) $execution->getProcessInstanceId(),
            (string) $execution->getOperator(),
            $assignee,
            $performType
        );

        return $this->insertTaskRow($columns);
    }

    /**
     * 由任务节点模型 + 实例信息组装任务列
     *
     * @param TaskModel $taskModel 任务节点模型
     * @param string    $instanceId 实例ID
     * @param string    $operator   操作人
     * @param string    $assignee   处理人
     * @param mixed     $performType 参与类型（引擎 ProcessTaskPerformTypeEnum）
     * @return array<string, mixed>
     */
    private function buildTaskColumns(
        TaskModel $taskModel,
        string $instanceId,
        string $operator,
        string $assignee,
        mixed $performType
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
            'status'           => $assignee !== '' ? 'claimed' : 'pending',
            'priority'         => 5,
            'assignee'         => $assignee !== '' ? (int) $assignee : null,
            'assignee_name'    => null,
            'candidate_users'  => $assignee !== '' ? json_encode([$assignee], JSON_UNESCAPED_UNICODE) : json_encode([]),
            'candidate_groups' => json_encode([], JSON_UNESCAPED_UNICODE),
            'form_data'        => null,
            'result'           => null,
            'comment'          => null,
            'due_date'         => null,
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
     * 只取一个处理人：节点上写了多人时，普通任务取第一个（其余按候选人处理）
     *
     * @param mixed  $raw       原始 assignee
     * @param string $operation 操作描述
     */
    private function firstActorId(mixed $raw, string $operation): string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return '';
        }

        $actors = $this->parseActorTokens(is_scalar($raw) ? (string) $raw : '', $operation);

        return $actors[0] ?? '';
    }

    /**
     * 把参与者表达式拆成参与者ID列表
     *
     * 只接受**数字ID**。如果遇到表达式 / 表单键 / 角色标识，直接报错而不是静默丢掉：
     * 本插件没有 `IProcessUser`、`ICandidateHandler` 实现（全仓 grep 零命中），
     * 也没有用户/部门/角色服务，解析不出人来；静默丢弃会产生「任务没人能办」的暗坑。
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
                    . '本仓库没有 IProcessUser / ICandidateHandler 实现，也没有用户/部门/角色服务，'
                    . '表达式、表单键与角色标识都解析不出具体人员，因此不做猜测',
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
