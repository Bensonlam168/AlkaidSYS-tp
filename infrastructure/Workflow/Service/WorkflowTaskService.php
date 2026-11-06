<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Service;

use Alkaid\Workflow\Engine\enums\ProcessSubmitTypeEnum;
use Infrastructure\I18n\LanguageService;
use Infrastructure\Lowcode\FormDesigner\Service\FormValidatorGenerator;
use Infrastructure\Workflow\Exception\WorkflowActionException;
use Infrastructure\Workflow\Repository\WorkflowEventLogRepository;
use Infrastructure\Workflow\Repository\WorkflowInstanceRepository;
use Infrastructure\Workflow\Repository\WorkflowTaskRepository;
use Infrastructure\Workflow\Repository\WorkflowUserDirectoryRepository;
use Infrastructure\Workflow\Support\WorkflowActorMatch;
use Infrastructure\Workflow\Support\WorkflowTaskFormResolver;
use Infrastructure\Workflow\WorkflowEngineBootstrap;
use madong\helper\Dict;
use think\facade\Log;
use think\Validate;
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
 * 但「提交表单」还有一段**引擎不管、必须宿主来做**的前置工作：按表单模板里的 rules 校验
 * `form_data`（{@see self::validateFormData()}）。引擎只负责推进状态机，它既不认识低代码表单，
 * 也不会因为「金额是负数」而拒绝 —— 少了宿主这一段，后端的「唯一权威」就不存在。
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
 * - 「审批结果 / 完成产出」落在 `result`（引擎把整个 args JSON 写进去）与 `comment`（审批意见列）；
 * - 表单**模板**（`form_schema` / `form_rules` / `form_messages`）不在任务行上，而是沿
 *   `instance.definition_id → nodes_config → properties.form → lowcode_forms` 解析
 *   （{@see WorkflowTaskFormResolver}）；任务行上的 `form_data` 只是**已填的值**。
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

    private ?WorkflowTaskFormResolver $formResolver;

    private ?FormValidatorGenerator $formValidatorGenerator;

    /**
     * @param LanguageService|null              $lang         语言服务（控制器传入同一个实例；null 时惰性新建）
     * @param WorkflowTaskRepository|null       $tasks        任务仓储
     * @param WorkflowInstanceRepository|null   $instances    实例仓储（判断实例是否允许办理）
     * @param WorkflowEventLogRepository|null   $events       事件仓储（宿主动作留痕）
     * @param WorkflowUserDirectoryRepository|null $users     用户目录（转办/委派的目标用户校验 + 选人目录）
     * @param WorkflowTaskFormResolver|null     $formResolver 表单解析器（表单模板 → 低代码表单 + 校验规则）
     * @param FormValidatorGenerator|null       $validators   校验器生成器（与低代码表单接口**同一个类**，见 validateFormData()）
     */
    public function __construct(
        ?LanguageService $lang = null,
        ?WorkflowTaskRepository $tasks = null,
        ?WorkflowInstanceRepository $instances = null,
        ?WorkflowEventLogRepository $events = null,
        ?WorkflowUserDirectoryRepository $users = null,
        ?WorkflowTaskFormResolver $formResolver = null,
        ?FormValidatorGenerator $validators = null
    ) {
        $this->lang                   = $lang;
        $this->tasks                  = $tasks;
        $this->instances              = $instances;
        $this->events                 = $events;
        $this->users                  = $users;
        $this->formResolver           = $formResolver;
        $this->formValidatorGenerator = $validators;
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
     * 任务表单（模板 + 已提交值）
     *
     * ## 表单模板从哪来（改造要点）
     *
     * `workflow_tasks` **没有** `form_schema` / `form_rules` 列，模板在**流程定义体的节点配置**上。
     * 改造前这里恒返回 `form_schema: null` / `form_rules: []`，前端拿不到任何可渲染结构；
     * 现在改为沿
     * `task → instance.definition_id（具体版本行）→ nodes_config → 节点 properties.form →`
     * `lowcode_forms` 解析（实现与全部优先级说明见 {@see WorkflowTaskFormResolver}）。
     *
     * 版本正确性：`definition_id` 指向**具体版本行**，因此老实例永远读到它启动时那一版的表单模板，
     * 定义发布新版本不会让在跑实例的审批界面换掉表单。
     *
     * ## 返回契约（「没配」与「配了但找不到」必须可区分）
     *
     * `resolved = false` 时 `reason` 一定非空（`node_has_no_form` / `form_not_found` /
     * `definition_not_found` / `instance_not_found` / `node_not_found`），
     * **绝不**静默返回 `form_schema: null` 而不说明原因。
     *
     * `form_schema` 是 `SchemaParser` 产出的 `components`（与
     * `GET /v1/lowcode/forms/{name}?with_parsed=1` 的 `data.parsed.components` 同一个数组）；
     * `form_rules` / `form_messages` 来自同一套 `FormValidatorManager`（`data.validator.rules/messages`），
     * 因此前端「工作流里渲染 + 校验」与「低代码表单详情」用的是完全一致的规则。
     *
     * @return array{
     *     task_id: int,
     *     form_name: string|null,
     *     form_schema: array<int, array<string, mixed>>|null,
     *     form_rules: array<string, mixed>,
     *     form_messages: array<string, mixed>,
     *     form_data: array<string, mixed>,
     *     resolved: bool,
     *     reason: string|null
     * }
     */
    public function form(int $tenantId, int $taskId): array
    {
        $task = $this->requireOwned($tenantId, $taskId);

        $resolved = $this->formResolver()->resolve($tenantId, $task);

        return [
            'task_id'       => $taskId,
            'form_name'     => $resolved['form_name'],
            'form_schema'   => $resolved['form_schema'],
            'form_rules'    => $resolved['form_rules'],
            'form_messages' => $resolved['form_messages'],
            'form_data'     => is_array($task['form_data'] ?? null) ? $task['form_data'] : [],
            'resolved'      => $resolved['resolved'],
            'reason'        => $resolved['reason'],
        ];
    }

    /**
     * 本租户用户目录（转办 / 委派「选人」下拉框的数据源）
     *
     * 为什么放在任务服务上：目录的唯一用途是**选任务的处理人**（转办目标 / 委派目标 /
     * 按受理人筛选待办），属于任务域的读；授权口径也因此与任务端点一致（`workflow_task.read`）。
     *
     * 安全边界：仓储只回 `id` / `name` / `username` 三个字段，且强制 `tenant_id` 过滤 ——
     * 这是给下拉框用的接口，`password` / `email` / `phone` 这类字段一个都不能出现在这里
     * （多返回一个字段就是多一个泄露面，回归见
     * `tests/Feature/Workflow/WorkflowUserDirectoryEndpointTest`）。
     *
     * @param string|null $keyword 关键字（匹配 `name` / `username`，空串等价于不过滤）
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function searchUsers(int $tenantId, ?string $keyword, int $page, int $pageSize): array
    {
        return $this->users()->search($tenantId, $keyword, $page, $pageSize);
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
     * 提交表单（**先按后端规则校验 → 再落库 → 再推进流程**）
     *
     * ⚠️ 与旧实现相比是**语义变化**：旧实现只把 `form_data` 写进内存对象、流程原地不动；
     * 真库实现必须推进流程 —— 否则「提交表单」与「什么都没做」对流程而言无法区分，
     * 任务永远停在待办、后续节点永远不产生。因此这里同样是 `executeProcessTask()`，
     * 表单键以 `f_` 前缀并入 args。
     *
     * ## 为什么校验规则必须「借」而不是「另写一套」
     *
     * 改造前本方法只判断「`form_data` 是不是非空数组」，于是负数金额、超长文本、必填留空
     * 都能直接落库 —— AGENTS.md 约定的「后端是唯一权威」其实并不存在
     * （前端按 `GET /v1/lowcode/forms/{name}?with_validator=1` 的 rules 渲染与兜底校验）。
     *
     * 现在：沿 {@see WorkflowTaskFormResolver} 取出 `form_rules` / `form_messages`
     * （与 `GET /v1/workflow/tasks/{id}/form` 的 `form_rules`、以及
     * `GET /v1/lowcode/forms/{name}?with_validator=1` 的 `data.validator.rules` **同源**），
     * 再交给**同一个生成器**（{@see FormValidatorGenerator::buildFromDefinition()}）
     * 变成 ThinkPHP 验证器执行。
     *
     * 关键实现细节：**执行的就是 resolver 原样返回的那个数组**（不是拿 schema 重新生成一份）。
     * 因此「返回给前端的规则」与「后端实际执行的规则」是同一份内存数据，
     * 不存在「前端按 A 校验、后端按 B 校验」这种两套真相的漂移空间。
     * 宿主侧**不写**任何正则或规则表。
     *
     * ## 三种解析结果的行为（既不能一律放行，也不能一律拦）
     *
     * | resolver 结果 | 行为 | 理由 |
     * | ------------- | ---- | ---- |
     * | `resolved: true` | 按 rules 校验；不过 → 422，`data.errors` 按字段给文案 | 正常路径 |
     * | `node_has_no_form` | **不校验**，照常落库 | 纯审批节点本来就没有表单，没有规则可依 |
     * | `form_not_found` / `definition_not_found` / `instance_not_found` / `node_not_found` | **不拦**，落库 + 响应带标记 + WARNING 日志 | 配置错误不该变成业务死锁（拦了任务永远无法完成），但也绝不能静默（否则脏数据无人知晓） |
     *
     * 三条行为都落在 {@see self::validateFormData()} 里，本方法只做「结构」层的兜底检查。
     *
     * @param array<string, mixed> $payload `form_data`（必填）/ `comment`
     * @param string[]             $userGroupSlugs
     * @return array<string, mixed> 任务行 + `next_tasks` + `form_validation`（校验结论）
     */
    public function submitForm(int $tenantId, int $userId, array $userGroupSlugs, int $taskId, array $payload): array
    {
        $formData = $payload['form_data'] ?? null;

        // 结构校验：必须是**非空数组**（「有东西可提交」）。字段内容对不对由 validateFormData() 判，
        // 这里只挡「压根没传」与「传了个标量」—— 后者连逐字段校验都无从谈起。
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

        // 4）表单类动作：**先按后端规则校验 → 通过后才落库**
        //
        //    顺序不能反：先写 form_data 再校验失败，会在库里留下一行「脏表单 + 任务没推进」的数据，
        //    用户看到 422、重开表单却看到自己「没提交成功」的内容 —— 比直接拒绝更糟。
        //
        //    位置也刻意放在这里（三层授权之后、落库之前）而不是 submitForm() 入口：
        //    若放在入口，未登录/非受理人的请求能靠 422 的字段文案反推本节点的表单规则。
        $formValidation = null;

        if ($action === self::ACTION_FORM) {
            $formData = is_array($payload['form_data'] ?? null) ? $payload['form_data'] : [];

            $formValidation = $this->validateFormData($tenantId, $task, $formData);

            $this->tasks()->updateForTenant($tenantId, $taskId, [
                'form_data' => json_encode($formData, JSON_UNESCAPED_UNICODE),
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

        // 7）把表单校验结论一并回给前端（只有表单类动作才有该键）：
        //    `validated=false` 且 `needs_attention=true` 时必须让前端**能提示运维** ——
        //    「跳过校验」这件事一旦静默，配置错误就会一直烂在那里，直到有人发现批下来的单子金额是负数
        if ($formValidation !== null) {
            $refreshed['form_validation'] = $formValidation;
        }

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
     * 按**后端表单规则**校验 `form_data`（AGENTS.md 里「后端才是唯一权威」那句话的实现）
     *
     * ## 规则从哪来（这是本方法唯一允许的来源）
     *
     * `form_rules` / `form_messages` 由 {@see WorkflowTaskFormResolver::resolve()} 给出，
     * 与 `GET /v1/workflow/tasks/{id}/form` 返回给前端的 `form_rules` / `form_messages`
     * **是同一份数据来源**（该端点直接返回 resolver 的结果）。
     * 因此这里既不需要、也**不允许**另写正则或另建规则表 ——
     * 宿主侧一旦自己维护一份映射，就立刻变成「前端按 A 校验、后端按 B 校验」，
     * 而那类问题最难查（两边看起来都对）。
     *
     * ## 强制手段
     *
     * 校验不通过一律抛 422（{@see WorkflowActionException::invalid()}），
     * `data.errors` 是 `{字段: 文案}`，文案取自 `form_messages`（即表单设计器里配的 title，
     * 而不是 `require|max:200` 这种原始规则串）—— 前端据此能把红字标到对应输入框上。
     *
     * @param int                  $tenantId 当前租户（必须是请求上下文里的，不是任务行里的）
     * @param array<string, mixed> $task     任务行（调用方已完成租户归属与授权校验）
     * @param array<string, mixed> $formData 待校验的表单值
     * @return array{
     *     validated: bool,
     *     skipped: bool,
     *     reason: string|null,
     *     form_name: string|null,
     *     needs_attention: bool,
     *     message: string|null
     * } 校验结论（失败时本方法不返回，直接抛 422）
     */
    private function validateFormData(int $tenantId, array $task, array $formData): array
    {
        // 1）解出「这个节点该用哪张表单、它的规则与文案是什么」
        $resolved = $this->formResolver()->resolve($tenantId, $task);

        if (($resolved['resolved'] ?? false) !== true) {
            return $this->skipFormValidation($tenantId, $task, $resolved);
        }

        $rules    = is_array($resolved['form_rules'] ?? null) ? $resolved['form_rules'] : [];
        $messages = is_array($resolved['form_messages'] ?? null) ? $resolved['form_messages'] : [];
        $formName = is_string($resolved['form_name'] ?? null) ? $resolved['form_name'] : null;

        // 2）表单存在但没有任何可执行规则（schema 里没有 properties / required 的极端情况）：
        //    「没有规则」不等于「校验通过」，但也确实没有可拦的字段 —— 如实标记为未校验，
        //    而不是谎报 validated=true（那会让「后端校验过了」这句话失去意义）。
        if ($rules === []) {
            return [
                'validated'       => false,
                'skipped'         => true,
                'reason'          => null,
                'form_name'       => $formName,
                'needs_attention' => false,
                'message'         => null,
            ];
        }

        // 3）用**同一个生成器**把这份 rules/messages 还原成 ThinkPHP 验证器。
        //    `buildFromDefinition()` 正是 FormValidatorManager 命中缓存时走的那条路径
        //    （同一个类、同一份 rules、同一份 messages、连自定义规则 tenantMatch/compare 的注册方式都一样），
        //    所以「前端拿到的 rules」与「这里执行的 rules」是同一份内存数据，没有漂移空间。
        $validator = $this->formValidatorGenerator()->buildFromDefinition($rules, $messages, [
            // context 与 resolver 解析时的口径保持一致（tenant_id 供 tenantMatch 用；site_id 恒为 null）
            'tenant_id' => $tenantId,
            'site_id'   => null,
        ]);

        // 批量模式：一次把**所有**不合规字段收齐，而不是只报第一条 ——
        // 否则用户改完「金额」再提交、又发现「备注超长」，来回试错
        $validator->batch(true);

        if ($validator->check($formData)) {
            return [
                'validated'       => true,
                'skipped'         => false,
                'reason'          => null,
                'form_name'       => $formName,
                'needs_attention' => false,
                'message'         => null,
            ];
        }

        // 4）校验失败 → 422，`data.errors` 与 `ApiController::validationError()` 同构
        throw WorkflowActionException::invalid(
            $this->fieldErrorsOf($validator),
            $this->t('workflow.form_validation_failed')
        );
    }

    /**
     * 「模板没解出来」时的统一结论：分正常形态与配置错误两种，绝不一律放行、也绝不一律拦
     *
     * | reason | 处理 | 理由 |
     * | ------ | ---- | ---- |
     * | `node_has_no_form` | 不校验、不告警 | 纯审批 / 复核节点本来就没有表单，没有规则可依 |
     * | 其余（表单 / 定义 / 实例 / 节点找不到） | 不拦 + WARNING + 响应带标记 | 配置错误**不该变成业务死锁**，但**也不能静默** |
     *
     * 「不拦」是因为拦下来会让任务永远无法完成：改了表单名的那次发布会把在跑实例上的所有待办
     * 直接变成死数据，而修复路径（改定义、建表单）反而需要运维先动数据库。
     * 「不能静默」是因为放过一次未校验的提交就等于放过一行脏数据 ——
     * 日志给运维定位（哪条任务、哪个节点、配的哪张表单），响应标记给前端以提示用户。
     *
     * @param int                  $tenantId 当前租户
     * @param array<string, mixed> $task     任务行
     * @param array<string, mixed> $resolved resolver 的原始返回
     * @return array{
     *     validated: bool,
     *     skipped: bool,
     *     reason: string|null,
     *     form_name: string|null,
     *     needs_attention: bool,
     *     message: string|null
     * }
     */
    private function skipFormValidation(int $tenantId, array $task, array $resolved): array
    {
        $reason   = is_string($resolved['reason'] ?? null) ? $resolved['reason'] : '';
        $formName = is_string($resolved['form_name'] ?? null) ? $resolved['form_name'] : null;

        if ($reason === WorkflowTaskFormResolver::REASON_NODE_HAS_NO_FORM) {
            // 正常形态：没有表单就没有规则，提交的内容照常落库（例如「确认已阅」类节点）
            return [
                'validated'       => false,
                'skipped'         => true,
                'reason'          => $reason,
                'form_name'       => null,
                'needs_attention' => false,
                'message'         => null,
            ];
        }

        Log::warning('[WORKFLOW_FORM_TEMPLATE_MISSING] 任务表单模板缺失，已跳过表单校验并继续受理（配置错误不应变成业务死锁，但必须留痕）', [
            'tenant_id'   => $tenantId,
            'task_id'     => (int) ($task['id'] ?? 0),
            'instance_id' => (int) ($task['instance_id'] ?? 0),
            'node_id'     => $task['node_id'] ?? null,
            'node_name'   => $task['node_name'] ?? null,
            'form_name'   => $formName,
            'reason'      => $reason,
        ]);

        return [
            'validated'       => false,
            'skipped'         => true,
            'reason'          => $reason,
            'form_name'       => $formName,
            'needs_attention' => true,
            'message'         => $reason === WorkflowTaskFormResolver::REASON_FORM_NOT_FOUND
                ? $this->t('workflow.form_validation_skipped_form_not_found', ['form_name' => (string) $formName])
                : $this->t('workflow.form_validation_skipped', ['reason' => $reason]),
        ];
    }

    /**
     * 把验证器的错误归一成 `{字段: 文案}`
     *
     * `Validate::getError(true)` 返回的本来就是 `field => message`
     * （`checkItems()` 以字段名为键写入 `$this->error`），这里只做两件事：
     * 键强制转字符串、非字符串文案兜底 JSON 化。
     * **不重新拼文案** —— 否则会把 `form_messages` 里配好的可读文案覆盖成宿主自己编的一句话。
     *
     * @return array<string, string>
     */
    private function fieldErrorsOf(Validate $validator): array
    {
        $errors = $validator->getError(true);

        if (!is_array($errors)) {
            return ['form_data' => is_scalar($errors) ? (string) $errors : $this->t('workflow.form_validation_failed')];
        }

        $normalized = [];

        foreach ($errors as $field => $message) {
            $text = is_scalar($message) ? (string) $message : (string) json_encode($message, JSON_UNESCAPED_UNICODE);

            $normalized[(string) $field] = $text === '' ? $this->t('workflow.form_validation_failed') : $text;
        }

        return $normalized;
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
     *
     * @param array<string, string> $params 文案占位符（`:name` 形式，见 LanguageService::translate()）
     */
    private function t(string $key, array $params = []): string
    {
        $this->lang ??= new LanguageService();

        return $this->lang->trans($key, $params);
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
     * 表单解析器（惰性构造；依赖全部用默认实现，见 WorkflowTaskFormResolver 的类注释）
     */
    private function formResolver(): WorkflowTaskFormResolver
    {
        return $this->formResolver ??= new WorkflowTaskFormResolver();
    }

    /**
     * 校验器生成器：与 `FormValidatorManager` / 低代码表单接口**同一个类**
     *
     * 这里刻意只注入「生成器」而不是整个 Manager：`buildFromDefinition($rules, $messages, $context)`
     * 让宿主直接执行 resolver 返回的那份 rules —— 不需要 schema，也就没有「第二次生成、可能生成出别的规则」
     * 的可能性。Manager 额外做的只是缓存与从 schema 生成，宿主侧用不上也不该用。
     */
    private function formValidatorGenerator(): FormValidatorGenerator
    {
        return $this->formValidatorGenerator ??= new FormValidatorGenerator();
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
