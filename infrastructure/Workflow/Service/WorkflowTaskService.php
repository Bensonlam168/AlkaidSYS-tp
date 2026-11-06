<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Service;

use Alkaid\Workflow\Engine\enums\ProcessSubmitTypeEnum;
use Infrastructure\I18n\LanguageService;
use Infrastructure\Workflow\Exception\WorkflowActionException;
use Infrastructure\Workflow\Repository\WorkflowEventLogRepository;
use Infrastructure\Workflow\Repository\WorkflowInstanceRepository;
use Infrastructure\Workflow\Repository\WorkflowTaskRepository;
use Infrastructure\Workflow\Repository\WorkflowUserDirectoryRepository;
use Infrastructure\Workflow\Support\WorkflowActorMatch;
use Infrastructure\Workflow\WorkflowEngineBootstrap;
use madong\helper\Dict;
use Throwable;

/**
 * 工作流任务应用服务（任务控制器的唯一业务入口）
 *
 * ## 分层位置
 *
 * 控制器只做「取参 → 调本服务 → 包统一响应」；本服务负责「归属校验 + 状态机 + 授权 + 调引擎 + 落库」；
 * 数据访问一律走 `Infrastructure\Workflow\Repository\*`。
 *
 * ## 三层授权，缺一不可
 *
 * 1. **租户归属**（`requireOwned()`）：行不存在 → 404，行存在但属于别的租户 → 403。
 *    两者必须可区分：把「不是你的」伪装成「不存在」会让安全测试无法证明隔离真的生效。
 * 2. **宿主授权**（`assertActor()`）：移植旧控制器的 `checkTaskAccess()` 语义 ——
 *    有 `assignee` 时**只有受理人**可操作（候选身份不能越过受理人）；未受理但设了候选约束时只有命中候选者可操作；
 *    两者皆空（开放任务）才允许任何本租户用户操作。
 * 3. **引擎授权**（`ensureEngineActor()`）：真正执行前再问一次引擎 `isAllowed()`。
 *    引擎只看 `assignee` + `candidate_users`（**不认 `candidate_groups`**），因此「按角色命中候选组」的用户
 *    会被引擎判为无权限 —— 这时用 `addCandidateActor()` 把操作人写进候选集再执行。
 *    为什么用 `addCandidateActor` 而不是 `addTaskActor`：后者在 `assignee` 为空时会把参与者**自动置为受理人**，
 *    等于「点一下审批就顺手认领了」；前者只写候选集，受理人由引擎的 `finishProcessTask()` 在动作完成时按真实操作人落库。
 *
 * ## 引擎哪些活由它干、哪些不归它干
 *
 * | 动作 | 谁执行 | 说明 |
 * | ---- | ------ | ---- |
 * | 审批 / 完成 / 提交表单 | **引擎** `executeProcessTask()` | 任务状态、`result`、审批意见、`task_completed` 事件都由引擎写 |
 * | 驳回 | **引擎** `executeAndJumpToEnd()` | 引擎会 `finishProcessTask` → 跳到结束节点 → 实例置 `cancelled` |
 * | 待办 / 列表 / 详情 | 仓储 | 纯查询，不需要经过引擎 |
 * | 认领 / 取消认领 | 宿主 + 引擎协作 | 引擎 `transfer()` **未实现**（抛 `BadMethodCallException`），认领语义由宿主落库 |
 * | 转办 / 委派 | 宿主 + 引擎协作 | 同上；参与者集合用引擎 `addCandidateActor()` / `removeTaskActor()` 维护 |
 *
 * > 本包**不改** `plugins/workflow-engine/src/`，因此「引擎未实现」的方法只能由宿主补齐；
 * > 与客户端相关的语义差异写在交付报告「契约冲突」一节。
 *
 * ## 表结构事实（`SHOW COLUMNS` 核对，勿凭旧 Mock 记忆）
 *
 * `workflow_tasks` **没有** `delegated_to` / `delegated_by` / `delegated_at` / `claimed_at` / `type` / `output` /
 * `form_schema` / `form_rules`。因此：
 * - 「委派」落成「`assignee` 改成被委派人 + 原受理人**保留**在 `candidate_users` + `task_delegated` 事件」；
 * - 「转办」落成「`assignee` 改成目标 + 原受理人**移出** `candidate_users` + `task_transferred` 事件」；
 *   两者的唯一可落库差异就是「原受理人是否仍在候选集」，因为「受理人之外的人也能办」这一双人可办语义
 *   被已经钉住的不变量（候选身份不得越过受理人）明确禁止；
 * - 「审批结果 / 完成产出」落在 `result`（引擎把整个 args JSON 写进去）与 `comment`（审批意见列）。
 *
 * @package Infrastructure\Workflow\Service
 */
class WorkflowTaskService
{
    /** 动作：审批通过 */
    public const ACTION_APPROVE = 'approve';

    /** 动作：驳回 */
    public const ACTION_REJECT = 'reject';

    /** 动作：完成 */
    public const ACTION_COMPLETE = 'complete';

    /** 动作：提交表单 */
    public const ACTION_FORM = 'form';

    /**
     * 引擎的审批意见参数键（`ProcessConstEnum::APPROVAL_COMMENT`）
     *
     * 引擎 `finishProcessTask()` 用它回填 `workflow_tasks.comment` 列，
     * 因此宿主必须用同一个键，否则「意见」只存在于 args JSON 里、列表页看不到。
     */
    private const ARG_APPROVAL_COMMENT = 'tf_approval_comment';

    /**
     * 表单字段参数前缀（引擎口径：`f_title` → 实例标题，见 `ProcessFlowUtils`）
     *
     * 表单/产出的键都带此前缀并入 args：既随 `result` 落库，也成为后续节点表达式可引用的变量。
     */
    private const ARG_FORM_PREFIX = 'f_';

    private ?LanguageService $lang;

    private ?WorkflowTaskRepository $tasks;

    private ?WorkflowInstanceRepository $instances;

    private ?WorkflowEventLogRepository $events;

    private ?WorkflowUserDirectoryRepository $users;

    /**
     * @param LanguageService|null              $lang      语言服务（控制器传入同一个实例；null 时惰性新建）
     * @param WorkflowTaskRepository|null       $tasks     任务仓储
     * @param WorkflowInstanceRepository|null   $instances 实例仓储（判断实例是否允许办理）
     * @param WorkflowEventLogRepository|null   $events    事件仓储（宿主动作留痕）
     * @param WorkflowUserDirectoryRepository|null $users  用户目录（转办/委派的目标用户校验）
     */
    public function __construct(
        ?LanguageService $lang = null,
        ?WorkflowTaskRepository $tasks = null,
        ?WorkflowInstanceRepository $instances = null,
        ?WorkflowEventLogRepository $events = null,
        ?WorkflowUserDirectoryRepository $users = null
    ) {
        $this->lang      = $lang;
        $this->tasks     = $tasks;
        $this->instances = $instances;
        $this->events    = $events;
        $this->users     = $users;
    }

    // =====================================================================
    // 读
    // =====================================================================

    /**
     * 任务列表（强制租户过滤 + 分页 + 筛选）
     *
     * @param array<string, mixed> $filters 支持 status / instance_id / type / assignee / node_id
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(int $tenantId, array $filters, int $page, int $pageSize): array
    {
        return $this->tasks()->search($tenantId, $filters, $page, $pageSize);
    }

    /**
     * 我的待办（在办 + 我是受理人或命中候选范围）
     *
     * 候选判定下推到 SQL（`WorkflowTaskRepository::searchPending()`）：
     * 只有这样才能「先过滤再分页」，否则分页切的是未过滤的全集。
     *
     * @param string[] $userGroupSlugs 当前用户的角色 slug（`app\Request::userGroups()`）
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function pending(int $tenantId, int $userId, array $userGroupSlugs, int $page, int $pageSize): array
    {
        return $this->tasks()->searchPending($tenantId, $userId, $userGroupSlugs, $page, $pageSize);
    }

    /**
     * 任务详情（任务行 + 宿主动作事件）
     *
     * 只做租户级校验、不做受理人级校验：与旧实现一致（同租户内的任务详情是可见的），
     * 「谁能操作」由动作端点自己把关。
     *
     * @return array<string, mixed>
     */
    public function detail(int $tenantId, int $taskId): array
    {
        $task = $this->requireOwned($tenantId, $taskId);

        $task['history'] = $this->events()->listByTaskId($tenantId, $taskId);

        return $task;
    }

    /**
     * 任务表单（读 `form_data`）
     *
     * `form_schema` / `form_rules` 在 `workflow_tasks` 里**没有对应列**（模板定义在流程定义体上），
     * 因此保持旧结构的占位（`null` / `[]`）而不是凭定义体拼一份假 schema ——
     * 真要渲染表单，应走定义详情接口拿节点配置。
     *
     * @return array{task_id: int, form_schema: null, form_data: array<string, mixed>, form_rules: array<int, mixed>}
     */
    public function form(int $tenantId, int $taskId): array
    {
        $task = $this->requireOwned($tenantId, $taskId);

        return [
            'task_id'     => $taskId,
            'form_schema' => null,
            'form_data'   => is_array($task['form_data'] ?? null) ? $task['form_data'] : [],
            'form_rules'  => [],
        ];
    }

    // =====================================================================
    // 审批类动作（推进流程）
    // =====================================================================

    /**
     * 审批通过
     *
     * @param array<string, mixed> $payload `comment` / `form_data`
     * @param string[]             $userGroupSlugs
     * @return array<string, mixed> 任务行 + `next_tasks`
     */
    public function approve(int $tenantId, int $userId, array $userGroupSlugs, int $taskId, array $payload): array
    {
        return $this->runAction(self::ACTION_APPROVE, $tenantId, $userId, $userGroupSlugs, $taskId, $payload);
    }

    /**
     * 驳回（驳回并结束流程）
     *
     * 为什么是「驳回即结束」：引擎 `rejectTask()`（退回上一节点）只对「同一个流程模型内的上一任务节点」有意义，
     * 而本仓库允许的图可以没有上一任务节点（例如只剩结束节点）。`executeAndJumpToEnd()`
     * 是引擎里语义明确、不依赖上游节点存在性的驳回实现：任务结束 → 跳结束节点 → 实例按 `submit_type`
     * 走 `rejectProcessInstance()`（实例 `cancelled`），并在事件表留下 `instance_rejected`。
     *
     * @param array<string, mixed> $payload `comment` / `form_data`
     * @param string[]             $userGroupSlugs
     * @return array<string, mixed>
     */
    public function reject(int $tenantId, int $userId, array $userGroupSlugs, int $taskId, array $payload): array
    {
        return $this->runAction(self::ACTION_REJECT, $tenantId, $userId, $userGroupSlugs, $taskId, $payload);
    }

    /**
     * 完成任务（产出写进 args → 引擎落 `result`）
     *
     * @param array<string, mixed> $payload `comment` / `output` / `form_data`
     * @param string[]             $userGroupSlugs
     * @return array<string, mixed>
     */
    public function complete(int $tenantId, int $userId, array $userGroupSlugs, int $taskId, array $payload): array
    {
        return $this->runAction(self::ACTION_COMPLETE, $tenantId, $userId, $userGroupSlugs, $taskId, $payload);
    }

    /**
     * 提交表单（写 `form_data` 并推进流程）
     *
     * ⚠️ 与旧实现相比是**语义变化**：旧实现只把 `form_data` 写进内存对象、流程原地不动；
     * 真库实现必须推进流程 —— 否则「提交表单」与「什么都没做」对流程而言无法区分，
     * 任务永远停在待办、后续节点永远不产生。因此这里同样是 `executeProcessTask()`，
     * 表单键以 `f_` 前缀并入 args。
     *
     * @param array<string, mixed> $payload `form_data`（必填）/ `comment`
     * @param string[]             $userGroupSlugs
     * @return array<string, mixed>
     */
    public function submitForm(int $tenantId, int $userId, array $userGroupSlugs, int $taskId, array $payload): array
    {
        $formData = $payload['form_data'] ?? null;

        if (!is_array($formData) || $formData === []) {
            throw WorkflowActionException::invalid([
                'form_data' => $this->t('workflow.form_data_required'),
            ]);
        }

        return $this->runAction(self::ACTION_FORM, $tenantId, $userId, $userGroupSlugs, $taskId, $payload);
    }

    // =====================================================================
    // 认领 / 转办 / 委派（不推进流程）
    // =====================================================================

    /**
     * 认领任务
     *
     * 校验顺序与旧实现一致：租户 → 状态（只有 pending 可认领）→ 未被签收 → 候选资格。
     * 顺序不能改：先判「已被签收」再判候选资格，才能给出准确的错误（`task_already_assigned` 而非 403）。
     *
     * @param string[] $userGroupSlugs
     * @return array<string, mixed>
     */
    public function claim(int $tenantId, int $userId, array $userGroupSlugs, int $taskId): array
    {
        $task = $this->requireOwned($tenantId, $taskId);

        $this->assertInstanceRunning($tenantId, $task);

        if ((string) $task['status'] !== 'pending') {
            throw WorkflowActionException::conflict(
                $this->t('workflow.cannot_claim_task'),
                ['current_status' => (string) $task['status']]
            );
        }

        $assignee = WorkflowActorMatch::assigneeOf($task);

        if ($assignee !== null) {
            throw WorkflowActionException::conflict(
                $this->t('workflow.task_already_assigned'),
                ['assignee' => (string) $assignee]
            );
        }

        // 候选资格：只要任务设了**任一**候选约束（候选用户 或 候选组），我不在其中就必须拒绝；
        // 两者皆空 = 开放任务，本租户任何登录用户都可以认领（旧实现的 fail-open 缺陷只在
        // 「只设其中一种约束」时成立，不能把「什么都没设」也一并拦死）
        if (WorkflowActorMatch::hasCandidacy($task) && !$this->isCandidate($task, $userId, $userGroupSlugs)) {
            throw WorkflowActionException::forbidden($this->t('workflow.not_eligible_to_claim'));
        }

        // 参与者集合交给引擎维护（它是 candidate_users 的唯一写入点之一），再补状态与受理人
        try {
            $this->taskService()->addTaskActor((string) $taskId, [(string) $userId]);
        } catch (Throwable $e) {
            throw WorkflowActionException::fromEngine($e, '认领任务：写入参与者');
        }

        $this->tasks()->updateForTenant($tenantId, $taskId, [
            'status'        => 'claimed',
            'assignee'      => $userId,
            'assignee_name' => $this->users()->displayName($tenantId, $userId),
        ]);

        $this->events()->append($tenantId, (int) $task['instance_id'], $taskId, 'task_claimed', [
            'node_name' => $task['node_name'] ?? null,
        ], $userId);

        return $this->requireOwned($tenantId, $taskId);
    }

    /**
     * 取消认领
     *
     * ⚠️ 决策偏差（已写入交付报告）：**不**调用引擎的 `removeTaskActor()`。
     * 原因：`removeTaskActor()` 只从 `candidate_users` 移除，`assignee` 不变；而本方法下一步就要把
     * `assignee` 置空。若同时把人从候选集删掉，「只有一个候选人」的任务会退化成**开放任务**
     * （候选为空 = 本租户任何人可认领），这是**授权放大**。因此只归还 `assignee`，候选集保持不变。
     *
     * @return array<string, mixed>
     */
    public function unclaim(int $tenantId, int $userId, int $taskId): array
    {
        $task = $this->requireOwned($tenantId, $taskId);

        $this->assertInstanceRunning($tenantId, $task);

        // 只有受理人本人能归还（有受理人时的宿主授权口径见 assertActor）
        $this->assertActor($task, $userId, []);

        if ((string) $task['status'] !== 'claimed') {
            throw WorkflowActionException::conflict(
                $this->t('workflow.cannot_unclaim_task'),
                ['current_status' => (string) $task['status']]
            );
        }

        $this->tasks()->updateForTenant($tenantId, $taskId, [
            'status'        => 'pending',
            'assignee'      => null,
            'assignee_name' => null,
        ]);

        $this->events()->append($tenantId, (int) $task['instance_id'], $taskId, 'task_unclaimed', [
            'node_name'      => $task['node_name'] ?? null,
            'from_assignee'  => $userId,
        ], $userId);

        return $this->requireOwned($tenantId, $taskId);
    }

    /**
     * 转办：任务整体交给目标用户，原受理人退出候选集
     *
     * @param array<string, mixed> $payload `target_user`（必填）/ `comment`
     * @param string[]             $userGroupSlugs
     * @return array<string, mixed>
     */
    public function transfer(int $tenantId, int $userId, array $userGroupSlugs, int $taskId, array $payload): array
    {
        return $this->handOver($tenantId, $userId, $userGroupSlugs, $taskId, $payload, [
            'field'      => 'target_user',
            'required'   => 'workflow.target_user_required',
            'cannot'     => 'workflow.cannot_transfer_task',
            'event_type' => 'task_transferred',
            // 转办 = 彻底交出去：原受理人不再保留在候选集
            'keep_original_candidate' => false,
            'operation'  => '转办任务',
        ]);
    }

    /**
     * 委派：受理人改成被委派人，同时把原受理人写入候选集（仍可处理 / 收回）
     *
     * @param array<string, mixed> $payload `delegate_to`（必填）/ `comment`
     * @param string[]             $userGroupSlugs
     * @return array<string, mixed>
     */
    public function delegate(int $tenantId, int $userId, array $userGroupSlugs, int $taskId, array $payload): array
    {
        return $this->handOver($tenantId, $userId, $userGroupSlugs, $taskId, $payload, [
            'field'      => 'delegate_to',
            'required'   => 'workflow.delegate_user_required',
            'cannot'     => 'workflow.cannot_delegate_task',
            'event_type' => 'task_delegated',
            // 委派 = 找人代办：原受理人留在候选集，任务办完后仍能看到自己在这条流程上的痕迹
            'keep_original_candidate' => true,
            'operation'  => '委派任务',
        ]);
    }

    // =====================================================================
    // 内部：动作执行
    // =====================================================================

    /**
     * 审批类动作的统一实现（前置校验顺序固定，见类注释的三层授权）
     *
     * @param string[]             $userGroupSlugs
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function runAction(
        string $action,
        int $tenantId,
        int $userId,
        array $userGroupSlugs,
        int $taskId,
        array $payload
    ): array {
        $task = $this->requireOwned($tenantId, $taskId);

        // 1）实例必须处于「运行中」：引擎的 execute() 只看任务状态、不看实例状态，
        //    挂起/已结束的实例若还能推进任务，等于挂起形同虚设
        $this->assertInstanceRunning($tenantId, $task);

        // 2）任务必须在办
        $this->assertActable($task, $action);

        // 3）宿主授权（移植旧 checkTaskAccess 语义）
        $this->assertActor($task, $userId, $userGroupSlugs);

        // 4）表单类动作先把 form_data 落库：即使后续推进失败，用户填的内容也不会丢
        if ($action === self::ACTION_FORM) {
            $this->tasks()->updateForTenant($tenantId, $taskId, [
                'form_data' => json_encode($payload['form_data'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        // 5）引擎授权桥接
        $this->ensureEngineActor($taskId, $userId);

        $args = $this->buildActionArgs($action, $payload);

        try {
            if ($action === self::ACTION_REJECT) {
                $result = $this->engine()->executeAndJumpToEnd((string) $taskId, (string) $userId, $args);
            } else {
                $result = $this->engine()->executeProcessTask((string) $taskId, (string) $userId, $args);
            }
        } catch (Throwable $e) {
            throw WorkflowActionException::fromEngine($e, $this->actionLabel($action));
        }

        // 6）驳回的状态纠正：`executeAndJumpToEnd()` 内部走的是 `finishProcessTask()`，
        //    落库状态是 completed。若不纠正，任务列表里「我驳回了」会显示成「已完成」，
        //    与用户决策相反。这里按引擎自己的驳回词汇（`rejectTask()` 用 rejected）补齐。
        if ($action === self::ACTION_REJECT) {
            $this->tasks()->updateForTenant($tenantId, $taskId, [
                'status'      => 'rejected',
                'rejected_at' => date('Y-m-d H:i:s'),
            ]);
        }

        if ($action === self::ACTION_FORM) {
            $this->events()->append($tenantId, (int) $task['instance_id'], $taskId, 'task_form_submitted', [
                'node_name' => $task['node_name'] ?? null,
                'keys'      => array_keys($payload['form_data']),
            ], $userId);
        }

        $refreshed = $this->requireOwned($tenantId, $taskId);
        $refreshed['next_tasks'] = $this->normalizeNextTasks($result);

        return $refreshed;
    }

    /**
     * 转办 / 委派的统一实现
     *
     * 目标用户校验（移植旧 `resolveTargetUser()`，一字不弱）：
     * 纯数字 → 正整数 → **存在于当前租户**；再补一条「不能转给自己」。
     * 最后一条不是新语义：转给自己会让「原受理人退出候选集」把唯一的参与者删掉，
     * 任务当场变成无人可达的死数据。
     *
     * @param string[]             $userGroupSlugs
     * @param array<string, mixed> $payload
     * @param array{field: string, required: string, cannot: string, event_type: string, keep_original_candidate: bool, operation: string} $meta
     * @return array<string, mixed>
     */
    private function handOver(
        int $tenantId,
        int $userId,
        array $userGroupSlugs,
        int $taskId,
        array $payload,
        array $meta
    ): array {
        $task = $this->requireOwned($tenantId, $taskId);

        $this->assertInstanceRunning($tenantId, $task);

        $this->assertActor($task, $userId, $userGroupSlugs);

        $this->assertActable($task, $meta['event_type'] === 'task_delegated' ? self::ACTION_COMPLETE : self::ACTION_APPROVE, $meta['cannot']);

        $rawTarget = $payload[$meta['field']] ?? null;

        if ($rawTarget === null || $rawTarget === '' || $rawTarget === []) {
            throw WorkflowActionException::invalid([$meta['field'] => $this->t($meta['required'])]);
        }

        $targetUserId = $this->resolveTargetUser($tenantId, $rawTarget, $meta['field']);

        if ($targetUserId === $userId) {
            throw WorkflowActionException::invalid([
                $meta['field'] => '不能把任务' . ($meta['keep_original_candidate'] ? '委派' : '转办') . '给自己'
                    . ' | cannot hand the task over to yourself',
            ]);
        }

        $originalAssignee = WorkflowActorMatch::assigneeOf($task);
        $targetName       = $this->users()->displayName($tenantId, $targetUserId);

        // 1）先把受理人改成目标用户：`addCandidateActor()` 只写候选集、不动 assignee，
        //    所以顺序反过来也不会被引擎覆盖，但先写库能保证「候选集写失败」时任务不会停在半截状态之外的地方
        $this->tasks()->updateForTenant($tenantId, $taskId, [
            'status'        => 'claimed',
            'assignee'      => $targetUserId,
            'assignee_name' => $targetName,
        ]);

        try {
            // 2）目标用户并入候选集（即使将来 assignee 被清空，他仍能作为候选人继续持有该任务）
            $this->taskService()->addCandidateActor((string) $taskId, [(string) $targetUserId]);

            if ($originalAssignee !== null && $originalAssignee !== $targetUserId) {
                if ($meta['keep_original_candidate']) {
                    // 3a）委派：原受理人**显式**写入候选集。
                    //     引擎建任务时 assignee 与 candidate_users 是分开写的 —— 有受理人的任务，
                    //     候选集通常是空的；若只「不删除」而不「补写」，原受理人其实什么也没留下，
                    //     「原受理人仍可处理/收回」这句语义就落不了库，与转办也就没有可观察的差异。
                    //     这里补写不构成越权：能发起委派的人本来就是当前受理人。
                    $this->taskService()->addCandidateActor((string) $taskId, [(string) $originalAssignee]);
                } else {
                    // 3b）转办：彻底交出，原受理人退出候选集
                    $this->taskService()->removeTaskActor((string) $taskId, [(string) $originalAssignee]);
                }
            }
        } catch (Throwable $e) {
            throw WorkflowActionException::fromEngine($e, $meta['operation'] . '：维护参与者集合');
        }

        $this->events()->append($tenantId, (int) $task['instance_id'], $taskId, $meta['event_type'], [
            'node_name' => $task['node_name'] ?? null,
            'from_user' => $originalAssignee,
            'to_user'   => $targetUserId,
            'comment'   => $this->commentOf($payload),
        ], $userId);

        return $this->requireOwned($tenantId, $taskId);
    }

    // =====================================================================
    // 内部：校验
    // =====================================================================

    /**
     * 读取任务并校验租户归属（404 / 403 严格区分）
     *
     * @return array<string, mixed>
     */
    private function requireOwned(int $tenantId, int $taskId): array
    {
        if ($taskId <= 0) {
            throw WorkflowActionException::notFound($this->t('workflow.task_not_found'));
        }

        $row = $this->tasks()->findByIdUnscoped($taskId);

        if ($row === null) {
            throw WorkflowActionException::notFound($this->t('workflow.task_not_found'));
        }

        if ((int) ($row['tenant_id'] ?? 0) !== $tenantId) {
            // 存在但归属别的租户：403（不是 404 —— 不能把「存在性」也藏起来）
            throw WorkflowActionException::forbidden($this->t('workflow.access_denied'));
        }

        return $row;
    }

    /**
     * 所属实例必须「运行中」
     *
     * 引擎的 `execute()` 只校验任务 `task_state = DOING`，**不看实例状态**；
     * 所以挂起、取消后的任务在引擎眼里仍然可办。这道闸必须由宿主补上，否则：
     * - 挂起只是给人看的标记，任务照常能被审批推进；
     * - 取消实例时若某个任务废弃失败，它还会被继续推进。
     *
     * @param array<string, mixed> $task 任务行
     */
    private function assertInstanceRunning(int $tenantId, array $task): void
    {
        $instanceId = (int) ($task['instance_id'] ?? 0);
        $instance   = $this->instances()->findForTenant($tenantId, $instanceId);

        if ($instance === null) {
            // 理论上被外键挡住；出现即数据不一致，明确报「不可办理」而不是当作正常流程继续
            throw WorkflowActionException::conflict(
                '任务所属的流程实例不存在或不属于当前租户，无法办理该任务'
                . ' | the instance of this task is missing or belongs to another tenant',
                ['instance_id' => (string) $instanceId]
            );
        }

        $status = (string) ($instance['status'] ?? '');

        if ($status !== 'running') {
            throw WorkflowActionException::conflict(
                '任务所属流程实例当前状态为「' . $status . '」，不能办理该任务'
                . ' | task cannot be processed while its instance is ' . $status,
                ['instance_id' => (string) $instanceId, 'instance_status' => $status]
            );
        }
    }

    /**
     * 任务必须处于「可办理」状态（在办）
     *
     * @param array<string, mixed> $task        任务行
     * @param string               $action      动作（决定用哪条语言键）
     * @param string|null          $messageKey  覆盖语言键（转办/委派各自有专属文案）
     */
    private function assertActable(array $task, string $action, ?string $messageKey = null): void
    {
        $status = (string) ($task['status'] ?? '');

        if ($status === 'escalated') {
            // 升级任务仍在办，允许办理（与 DOING_STATUSES 口径一致）
            return;
        }

        if (in_array($status, WorkflowTaskRepository::DOING_STATUSES, true)) {
            return;
        }

        throw WorkflowActionException::conflict(
            $this->t($messageKey ?? $this->cannotMessageKey($action)),
            ['current_status' => $status]
        );
    }

    /**
     * 宿主授权（移植旧 `checkTaskAccess()` 的语义，逐条保留）
     *
     * @param array<string, mixed> $task           任务行
     * @param int                  $userId         当前登录用户
     * @param string[]             $userGroupSlugs 当前用户角色 slug
     */
    private function assertActor(array $task, int $userId, array $userGroupSlugs): void
    {
        $assignee = WorkflowActorMatch::assigneeOf($task);

        if ($assignee !== null) {
            // 有受理人：候选身份**不能越过**受理人
            if ($assignee !== $userId) {
                throw WorkflowActionException::forbidden($this->t('workflow.not_task_assignee'));
            }

            return;
        }

        if ($this->isCandidate($task, $userId, $userGroupSlugs)) {
            return;
        }

        // 既无受理人、又没有设置任何候选约束 = 开放任务；设了约束却不是候选人才拒绝
        if (WorkflowActorMatch::hasCandidacy($task)) {
            throw WorkflowActionException::forbidden($this->t('workflow.not_eligible_to_claim'));
        }
    }

    /**
     * 当前用户是否命中候选范围（受理人判定不在此方法职责内）
     *
     * @param array<string, mixed> $task
     * @param string[]             $userGroupSlugs
     */
    private function isCandidate(array $task, int $userId, array $userGroupSlugs): bool
    {
        return WorkflowActorMatch::isCandidate($task, $userId, $userGroupSlugs);
    }

    /**
     * 引擎授权桥接：把「按候选组命中的操作人」补进 `candidate_users` 后再交给引擎
     *
     * 引擎的 `isAllowed()` 只认 `assignee` + `candidate_users`（见 `ProcessTaskEntity::getActorIds()`），
     * **不认 `candidate_groups`**：按角色命中候选组的用户在引擎侧是无权限的。
     * 这里不是「绕过引擎」，而是把宿主已经判定过的候选关系如实写进引擎的参与者集合，
     * 让引擎的授权判断与宿主口径一致；写入的是**真实登录用户ID**，不是 `flow_auto` / `flow.admin`。
     */
    private function ensureEngineActor(int $taskId, int $userId): void
    {
        try {
            $engineTask = $this->taskService()->getById((string) $taskId);

            if ($engineTask === null) {
                throw WorkflowActionException::notFound($this->t('workflow.task_not_found'));
            }

            if ($this->taskService()->isAllowed($engineTask, (string) $userId)) {
                return;
            }

            // 只写候选集：`addTaskActor()` 在 assignee 为空时会顺带把参与者置为受理人，
            // 那等于「点一下审批就认领了」，与宿主语义不符
            $this->taskService()->addCandidateActor((string) $taskId, [(string) $userId]);
        } catch (WorkflowActionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw WorkflowActionException::fromEngine($e, '任务授权校验');
        }
    }

    /**
     * 解析并校验「目标用户」（移植旧 `resolveTargetUser()`）
     *
     * 两条硬性要求：
     * 1. 必须是**纯数字**用户 ID —— 最终落到 `workflow_tasks.assignee`（int unsigned + 外键 `users.id`）；
     * 2. 必须存在于**当前租户** —— 否则等价于允许把任务指派给其它租户的用户。
     */
    private function resolveTargetUser(int $tenantId, mixed $raw, string $field): int
    {
        if (!is_int($raw) && !is_string($raw)) {
            throw WorkflowActionException::invalid([
                $field => '目标用户 ID 必须为纯数字 | target user ID must be numeric',
            ]);
        }

        if (!ctype_digit((string) $raw)) {
            throw WorkflowActionException::invalid([
                $field => '目标用户 ID 必须为纯数字 | target user ID must be numeric',
            ]);
        }

        $userId = (int) $raw;

        if ($userId <= 0) {
            throw WorkflowActionException::invalid([
                $field => '目标用户 ID 必须为正整数 | target user ID must be positive',
            ]);
        }

        if (!$this->users()->existsInTenant($tenantId, $userId)) {
            throw WorkflowActionException::invalid([
                $field => '目标用户不存在或不属于当前租户 | target user does not exist in current tenant',
            ]);
        }

        return $userId;
    }

    // =====================================================================
    // 内部：参数与返回
    // =====================================================================

    /**
     * 组装引擎参数
     *
     * - 审批意见 → `tf_approval_comment`（引擎据此回填 `workflow_tasks.comment`）；
     * - 表单/产出 → `f_<键>`（引擎的表单字段口径，见 `ProcessFlowUtils` 对 `f_title` 的处理）；
     * - 驳回 → `submit_type = REJECT`（`EndProcessHandler` 据此把实例置为驳回态，缺了它就变成「正常结束」）。
     *
     * @param array<string, mixed> $payload
     */
    private function buildActionArgs(string $action, array $payload): Dict
    {
        $args = new Dict();

        $comment = $this->commentOf($payload);

        if ($comment !== null) {
            $args->put(self::ARG_APPROVAL_COMMENT, $comment);
        }

        foreach (['form_data', 'output'] as $field) {
            $values = $payload[$field] ?? null;

            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }

                $args->put(self::ARG_FORM_PREFIX . $key, $value);
            }
        }

        if ($action === self::ACTION_REJECT) {
            $args->put('submit_type', ProcessSubmitTypeEnum::REJECT->value);
        }

        return $args;
    }

    /**
     * 取审批意见（非字符串/空串一律视为「没填」）
     *
     * @param array<string, mixed> $payload
     */
    private function commentOf(array $payload): ?string
    {
        $comment = $payload['comment'] ?? null;

        if (!is_scalar($comment)) {
            return null;
        }

        $comment = trim((string) $comment);

        return $comment === '' ? null : mb_substr($comment, 0, 500);
    }

    /**
     * 引擎返回的「新产生的任务」归一化（引擎返回的是实体对象数组，不能直接 JSON 化）
     *
     * @param mixed $result 引擎 `executeProcessTask()` / `executeAndJumpToEnd()` 的返回值
     * @return array<int, array<string, mixed>>
     */
    private function normalizeNextTasks(mixed $result): array
    {
        if (!is_array($result)) {
            return [];
        }

        $next = [];

        foreach ($result as $task) {
            $data = null;

            try {
                if (is_object($task)) {
                    // 引擎实体（`AbstractRowEntity`）用 `toArray()` 取全部列；
                    // 注意它的 `getData()` 必须传键名（无参调用会抛 TypeError），故只作最后兜底。
                    if (method_exists($task, 'toArray')) {
                        $data = (array) $task->toArray();
                    } elseif (method_exists($task, 'getRow')) {
                        $data = (array) $task->getRow();
                    } elseif (method_exists($task, 'getData')) {
                        $data = ['id' => $task->getData('id')];
                    } elseif ($task instanceof \stdClass) {
                        $data = (array) $task;
                    }
                } elseif (is_array($task)) {
                    $data = $task;
                }
            } catch (Throwable) {
                // `next_tasks` 只是「本次推进产生了什么」的附赠信息：
                // 任何实体形态无法序列化时都只是少一条提示，**绝不能**把已成功的审批变成 500
                continue;
            }

            if ($data === null) {
                continue;
            }

            $next[] = [
                'id'            => isset($data['id']) ? (int) $data['id'] : null,
                'node_id'       => $data['node_id'] ?? null,
                'node_name'     => $data['node_name'] ?? null,
                'title'         => $data['title'] ?? null,
                'status'        => $data['status'] ?? null,
                'assignee'      => WorkflowActorMatch::assigneeOf($data),
                'candidate_users' => WorkflowActorMatch::normalizeUserIds($data['candidate_users'] ?? null),
            ];
        }

        return $next;
    }

    /**
     * 动作 → 「不能办理」语言键
     */
    private function cannotMessageKey(string $action): string
    {
        return match ($action) {
            self::ACTION_APPROVE => 'workflow.cannot_process_approval',
            self::ACTION_COMPLETE => 'workflow.cannot_complete_task',
            self::ACTION_FORM     => 'workflow.cannot_submit_form',
            default               => 'workflow.cannot_process_approval',
        };
    }

    /**
     * 动作 → 日志里的中文描述（只用于日志，不返回给客户端）
     */
    private function actionLabel(string $action): string
    {
        return match ($action) {
            self::ACTION_APPROVE  => '审批通过任务',
            self::ACTION_REJECT   => '驳回任务',
            self::ACTION_COMPLETE => '完成任务',
            self::ACTION_FORM     => '提交任务表单',
            default               => '执行任务动作',
        };
    }

    // =====================================================================
    // 内部：依赖
    // =====================================================================

    /**
     * 惰性取语言服务
     */
    private function t(string $key): string
    {
        $this->lang ??= new LanguageService();

        return $this->lang->trans($key);
    }

    private function tasks(): WorkflowTaskRepository
    {
        return $this->tasks ??= new WorkflowTaskRepository();
    }

    private function instances(): WorkflowInstanceRepository
    {
        return $this->instances ??= new WorkflowInstanceRepository();
    }

    private function events(): WorkflowEventLogRepository
    {
        return $this->events ??= new WorkflowEventLogRepository();
    }

    private function users(): WorkflowUserDirectoryRepository
    {
        return $this->users ??= new WorkflowUserDirectoryRepository();
    }

    /**
     * 引擎装配入口（进程级单例；见 WorkflowEngineBootstrap 的类注释）
     */
    private function bootstrap(): WorkflowEngineBootstrap
    {
        return WorkflowEngineBootstrap::instance();
    }

    private function engine(): \Alkaid\Workflow\Engine\interface\IProcessEngines
    {
        return $this->bootstrap()->engine();
    }

    private function taskService(): \Alkaid\Workflow\Engine\interface\services\IProcessTaskService
    {
        return $this->bootstrap()->taskService();
    }
}
