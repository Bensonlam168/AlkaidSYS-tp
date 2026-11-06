<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Service;

use Alkaid\Workflow\Engine\interface\model\IProcessInstance;
use Infrastructure\I18n\LanguageService;
use Infrastructure\Workflow\Exception\WorkflowActionException;
use Infrastructure\Workflow\Repository\WorkflowDefinitionRepository;
use Infrastructure\Workflow\Repository\WorkflowEventLogRepository;
use Infrastructure\Workflow\Repository\WorkflowInstanceRepository;
use Infrastructure\Workflow\Repository\WorkflowTaskRepository;
use Infrastructure\Workflow\WorkflowEngineBootstrap;
use madong\helper\Dict;
use Throwable;

/**
 * 工作流实例应用服务（实例控制器的唯一业务入口）
 *
 * ## 分层位置
 *
 * 控制器只做「取参 → 调本服务 → 包统一响应」；本服务负责「归属校验 + 状态机 + 调引擎 + 落库」；
 * 数据访问一律走 `Infrastructure\Workflow\Repository\*`。
 *
 * ## 引擎哪些活由它干、哪些不归它干
 *
 * | 动作 | 谁执行 | 说明 |
 * | ---- | ------ | ---- |
 * | 启动实例 | **引擎** `startProcessInstanceById()` | 实例行 + 第一个任务 + `instance_started` 事件都由引擎写，宿主不重复 INSERT |
 * | 取消（终止） | 宿主 + 引擎协作 | 引擎 `interrupt()` **未实现**（抛 `BadMethodCallException`），故：在办任务用引擎 `abandonProcessTask()` 落 `cancelled`，实例状态由宿主写 |
 * | 挂起 / 恢复 | 宿主 | 引擎 `pending()` / `activate()` 同样**未实现**（语义未定），宿主按 `WorkflowStateMap` 已定义的口径落 `suspended` / `running` |
 * | 变量读写 | **引擎** `addVariable()` / `removeVariable()` | 引擎已实现且是变量的唯一写入点 |
 * | 轨迹 | **引擎** `timeline()` | 事件表由引擎与宿主共同写入，读取统一走引擎（它带租户作用域排序） |
 *
 * > 说明：本包**不改** `plugins/workflow-engine/src/`，因此「引擎未实现」的三个方法不能靠改插件补齐；
 * > 宿主实现对客户端语义是等价的（状态、时间戳、事件痕迹都落库），差异已写在交付报告里。
 *
 * ## 「查不到」与「不是你的」必须可区分
 *
 * 每个按 ID 访问的动作都先 `findByIdUnscoped()` 再判归属：
 * - 行不存在 → 404；
 * - 行存在但 `tenant_id` 不是当前租户 → 403。
 *
 * 不这样做（直接带租户条件查、查不到就 404）等于把「存在性」也藏起来 —— 那会让安全测试
 * 无法区分「隔离生效」与「根本没这条数据」，也掩盖了跨租户访问的事实。
 *
 * @package Infrastructure\Workflow\Service
 */
class WorkflowInstanceService
{
    /**
     * 实例终态（不可再被取消/挂起/改变量）
     *
     * @var string[]
     */
    private const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled'];

    /**
     * 可取消的状态（与 `workflow_instances.status` 的取值域一致）
     *
     * @var string[]
     */
    private const CANCELLABLE_STATUSES = ['pending', 'running', 'suspended'];

    private ?LanguageService $lang;

    private ?WorkflowInstanceRepository $instances;

    private ?WorkflowTaskRepository $tasks;

    private ?WorkflowEventLogRepository $events;

    private ?WorkflowDefinitionRepository $definitions;

    /**
     * @param LanguageService|null             $lang        语言服务（控制器传入同一个实例；null 时惰性新建）
     * @param WorkflowInstanceRepository|null  $instances   实例仓储
     * @param WorkflowTaskRepository|null      $tasks       任务仓储（取消时清理在办任务）
     * @param WorkflowEventLogRepository|null  $events      事件仓储（宿主动作留痕）
     * @param WorkflowDefinitionRepository|null $definitions 定义仓储（启动前归属/可执行性判定）
     */
    public function __construct(
        ?LanguageService $lang = null,
        ?WorkflowInstanceRepository $instances = null,
        ?WorkflowTaskRepository $tasks = null,
        ?WorkflowEventLogRepository $events = null,
        ?WorkflowDefinitionRepository $definitions = null
    ) {
        $this->lang        = $lang;
        $this->instances   = $instances;
        $this->tasks       = $tasks;
        $this->events      = $events;
        $this->definitions = $definitions;
    }

    // =====================================================================
    // 读
    // =====================================================================

    /**
     * 实例列表（强制租户过滤 + 分页 + 筛选）
     *
     * @param array<string, mixed> $filters
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(int $tenantId, array $filters, int $page, int $pageSize): array
    {
        return $this->instances()->search($tenantId, $filters, $page, $pageSize);
    }

    /**
     * 实例详情（实例行 + 当前在办任务 + 完整轨迹）
     *
     * 为什么要带轨迹：旧实现只有一个孤立的实例对象，前端拿到后仍要再发一次 history 请求；
     * 而轨迹本来就是「这条流程发生了什么」的直接答案，重复一次往返没有意义。
     *
     * @return array<string, mixed> 实例行 + `current_tasks` + `history`
     */
    public function detail(int $tenantId, int $instanceId): array
    {
        $instance = $this->requireOwned($tenantId, $instanceId);

        $instance['current_tasks'] = $this->tasks()->listDoingByInstance($tenantId, $instanceId);
        $instance['history']       = $this->historyEntries($instanceId);

        return $instance;
    }

    /**
     * 实例轨迹（对外结构兼容旧实现的 `{instance_id, entries, count}`）
     *
     * @return array{instance_id: int, entries: array<int, array<string, mixed>>, count: int}
     */
    public function history(int $tenantId, int $instanceId): array
    {
        $this->requireOwned($tenantId, $instanceId);

        $entries = $this->historyEntries($instanceId);

        return [
            'instance_id' => $instanceId,
            'entries'     => $entries,
            'count'       => count($entries),
        ];
    }

    /**
     * 实例变量
     *
     * @return array{instance_id: int, variables: array<string, mixed>}
     */
    public function variables(int $tenantId, int $instanceId): array
    {
        $instance = $this->requireOwned($tenantId, $instanceId);

        return [
            'instance_id' => $instanceId,
            'variables'   => is_array($instance['variables'] ?? null) ? $instance['variables'] : [],
        ];
    }

    // =====================================================================
    // 写
    // =====================================================================

    /**
     * 启动实例（**唯一合法入口**：调引擎，不自己 INSERT 实例与任务）
     *
     * @param array<string, mixed> $payload `definition_id` / `variables` / `title` / `business_key`
     * @return array<string, mixed> 落库后的实例行（以库为准，不用内存拼装）
     */
    public function start(int $tenantId, int $userId, array $payload): array
    {
        $rawDefinitionId = $payload['definition_id'] ?? null;

        if (!is_scalar($rawDefinitionId) || !ctype_digit((string) $rawDefinitionId) || (int) $rawDefinitionId <= 0) {
            throw WorkflowActionException::invalid([
                'definition_id' => $this->t('workflow.definition_id_required')
                    . ' | definition_id must be a numeric primary key',
            ]);
        }

        $definitionId = (int) $rawDefinitionId;
        $variables    = $payload['variables'] ?? [];

        if (!is_array($variables)) {
            throw WorkflowActionException::invalid([
                'variables' => $this->t('workflow.variables_required'),
            ]);
        }

        $definition = $this->requireOwnedDefinition($tenantId, $definitionId);

        if (!WorkflowDefinitionRepository::isExecutable($definition)) {
            // 定义体不是可执行结构（旧「节点表」形态/空图）：明确指出问题，而不是让引擎在
            // `$processModel->getStart()` 处崩成 500（那会把「定义数据不对」伪装成系统故障）
            throw WorkflowActionException::conflict(
                '该工作流定义的定义体不是可执行结构（缺少 LogicFlow 的 nodes/edges），无法启动实例'
                . ' | definition body is not executable (logicflow nodes/edges required)',
                ['definition_id' => (string) $definitionId]
            );
        }

        // 发布/归档语义的闸：只有「当前可启动的发布版本」才能启动新实例
        // （status=published 且 is_active=1，口径见 WorkflowDefinitionService 类注释）
        //
        // 引擎**完全不看** status / is_active（它只按 definition_id 读图），
        // 所以这道闸必须由宿主补：否则「草稿」「已归档」「已被新版本取代的旧发布版本」
        // 都能被直接启动 —— 草稿绕过发布审核、归档定义复活、旧版本又跑出新的在跑实例。
        if (!WorkflowDefinitionRepository::isStartable($definition)) {
            throw WorkflowActionException::conflict(
                '该工作流定义当前不可启动：仅「已发布且启用」的版本可启动新实例'
                . '（草稿、已归档、已被新版本取代的发布版本均不可启动）| '
                . 'definition is not startable (published + active required)',
                [
                    'definition_id' => (string) $definitionId,
                    'status'        => (string) ($definition['status'] ?? ''),
                    'is_active'     => (int) ($definition['is_active'] ?? 0),
                ]
            );
        }

        $args = $this->buildStartArgs($variables, $payload);

        try {
            $instance = $this->engine()->startProcessInstanceById(
                (string) $definitionId,
                // ⚠️ 必须是**真实登录用户**的ID（字符串形态）。绝不能传 'flow_auto' / 'flow.admin'：
                // 引擎的 isAllowed() 对这两个身份无条件放行，等于让每个 HTTP 调用者都变成超级管理员。
                (string) $userId,
                $args
            );
        } catch (Throwable $e) {
            throw WorkflowActionException::fromEngine($e, '启动流程实例');
        }

        $instanceId = $this->instanceIdOf($instance);

        if ($instanceId === null) {
            throw WorkflowActionException::internal('启动流程实例：引擎未返回有效实例ID', new \RuntimeException('missing instance id'));
        }

        $row = $this->instances()->findForTenant($tenantId, $instanceId);

        if ($row === null) {
            throw WorkflowActionException::internal('启动流程实例：实例行不可读', new \RuntimeException('instance row not readable'));
        }

        return $row;
    }

    /**
     * 取消（终止）实例
     *
     * 顺序刻意为「先废任务、后置实例终态」：反过来的话，一旦某个任务废弃失败，
     * 库里就留下「实例已取消、任务仍可办理」的不一致状态 —— 那种任务还能被审批推进。
     *
     * @return array<string, mixed> 更新后的实例行
     */
    public function cancel(int $tenantId, int $userId, int $instanceId, ?string $reason = null): array
    {
        $instance = $this->requireOwned($tenantId, $instanceId);

        if (!in_array((string) $instance['status'], self::CANCELLABLE_STATUSES, true)) {
            throw WorkflowActionException::conflict(
                $this->t('workflow.cannot_cancel_instance'),
                ['current_status' => (string) $instance['status']]
            );
        }

        // 1）把在办任务置为 cancelled（引擎的 abandonProcessTask 已实现，是任务的唯一合法写入点）
        foreach ($this->tasks()->listDoingByInstance($tenantId, $instanceId) as $task) {
            try {
                $this->taskService()->abandonProcessTask(
                    (string) (int) $task['id'],
                    (string) $userId,
                    new Dict()
                );
            } catch (Throwable $e) {
                throw WorkflowActionException::internal('取消实例：废弃在办任务失败', $e);
            }
        }

        // 2）实例置终态（引擎没有 interrupt 实现，见类注释）
        $this->instances()->updateForTenant($tenantId, $instanceId, [
            'status'       => 'cancelled',
            'completed_at' => date('Y-m-d H:i:s'),
            'duration'     => $this->durationMs($instance),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        // 3）留痕（引擎的四类事件里没有「取消」，由宿主补）
        $this->events()->append(
            $tenantId,
            $instanceId,
            null,
            'instance_cancelled',
            [
                'status' => 'cancelled',
                'reason' => $reason,
                'from'   => (string) $instance['status'],
            ],
            $userId
        );

        return $this->requireOwned($tenantId, $instanceId);
    }

    /**
     * 挂起实例
     *
     * 挂起**不改任务状态**：任务仍是「在办」，但所有任务动作都会在
     * `WorkflowTaskService::assertInstanceRunning()` 处被拒绝 ——
     * 引擎的 `execute()` 只看任务状态，不看实例状态，因此这道闸必须由宿主补。
     *
     * @return array<string, mixed>
     */
    public function suspend(int $tenantId, int $userId, int $instanceId): array
    {
        $instance = $this->requireOwned($tenantId, $instanceId);

        if ((string) $instance['status'] !== 'running') {
            throw WorkflowActionException::conflict(
                $this->t('workflow.cannot_suspend_instance'),
                ['current_status' => (string) $instance['status']]
            );
        }

        $this->instances()->updateForTenant($tenantId, $instanceId, [
            'status'       => 'suspended',
            'suspended_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->events()->append($tenantId, $instanceId, null, 'instance_suspended', ['status' => 'suspended'], $userId);

        return $this->requireOwned($tenantId, $instanceId);
    }

    /**
     * 恢复实例
     *
     * @return array<string, mixed>
     */
    public function resume(int $tenantId, int $userId, int $instanceId): array
    {
        $instance = $this->requireOwned($tenantId, $instanceId);

        if ((string) $instance['status'] !== 'suspended') {
            throw WorkflowActionException::conflict(
                $this->t('workflow.cannot_resume_instance'),
                ['current_status' => (string) $instance['status']]
            );
        }

        $this->instances()->updateForTenant($tenantId, $instanceId, [
            'status'       => 'running',
            'suspended_at' => null,
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->events()->append($tenantId, $instanceId, null, 'instance_resumed', ['status' => 'running'], $userId);

        return $this->requireOwned($tenantId, $instanceId);
    }

    /**
     * 更新实例变量（`merge=true` 合并；`merge=false` 全量替换）
     *
     * 替换语义的实现方式：先删掉库中现有的全部键，再写入新键 —— 这是引擎已有 API 的组合，
     * 不新增第二条变量写入路径（否则「谁才是变量的真相」立刻分叉）。
     *
     * @param array<string, mixed> $variables 新变量
     * @return array{instance_id: int, variables: array<string, mixed>}
     */
    public function updateVariables(int $tenantId, int $userId, int $instanceId, array $variables, bool $merge): array
    {
        $instance = $this->requireOwned($tenantId, $instanceId);

        if (in_array((string) $instance['status'], self::TERMINAL_STATUSES, true)) {
            throw WorkflowActionException::conflict(
                $this->t('workflow.cannot_modify_instance'),
                ['current_status' => (string) $instance['status']]
            );
        }

        if ($variables === []) {
            throw WorkflowActionException::invalid(['variables' => $this->t('workflow.variables_required')]);
        }

        $existing = is_array($instance['variables'] ?? null) ? $instance['variables'] : [];

        try {
            if (!$merge && $existing !== []) {
                $this->instanceService()->removeVariable((string) $instanceId, array_keys($existing));
            }

            $args = new Dict();
            $args->putAll($variables);
            $this->instanceService()->addVariable((string) $instanceId, $args);
        } catch (Throwable $e) {
            throw WorkflowActionException::fromEngine($e, '更新流程实例变量');
        }

        $this->events()->append(
            $tenantId,
            $instanceId,
            null,
            'variables_updated',
            ['merge' => $merge, 'keys' => array_keys($variables)],
            $userId
        );

        $refreshed = $this->requireOwned($tenantId, $instanceId);

        return [
            'instance_id' => $instanceId,
            'variables'   => is_array($refreshed['variables'] ?? null) ? $refreshed['variables'] : [],
        ];
    }

    // =====================================================================
    // 内部
    // =====================================================================

    /**
     * 读取实例并校验租户归属（404 / 403 严格区分）
     *
     * @return array<string, mixed>
     */
    private function requireOwned(int $tenantId, int $instanceId): array
    {
        if ($instanceId <= 0) {
            throw WorkflowActionException::notFound($this->t('workflow.instance_not_found'));
        }

        $row = $this->instances()->findByIdUnscoped($instanceId);

        if ($row === null) {
            throw WorkflowActionException::notFound($this->t('workflow.instance_not_found'));
        }

        if ((int) ($row['tenant_id'] ?? 0) !== $tenantId) {
            // 存在但归属别的租户：403（不是 404 —— 不能把「存在性」也藏起来）
            throw WorkflowActionException::forbidden($this->t('workflow.access_denied'));
        }

        return $row;
    }

    /**
     * 读取定义并校验租户归属
     *
     * @return array<string, mixed>
     */
    private function requireOwnedDefinition(int $tenantId, int $definitionId): array
    {
        $row = $this->definitions()->findByIdUnscoped($definitionId);

        if ($row === null) {
            throw WorkflowActionException::notFound($this->t('workflow.definition_not_found'));
        }

        if ((int) ($row['tenant_id'] ?? 0) !== $tenantId) {
            throw WorkflowActionException::forbidden($this->t('workflow.access_denied'));
        }

        return $row;
    }

    /**
     * 组装引擎启动参数
     *
     * 引擎口径：**启动参数即实例变量**（`createProcessInstance()` 直接把 args 落 `variables` 列），
     * 因此 `auto_gen_title` / `business_no` 这两个引擎自定义键也会出现在变量里 —— 这是引擎既有行为，
     * 宿主不另造一份「干净的变量表」（那会让 `${amount}` 一类表达式的求值来源分叉）。
     *
     * @param array<string, mixed> $variables 客户端变量
     * @param array<string, mixed> $payload   原始请求
     */
    private function buildStartArgs(array $variables, array $payload): Dict
    {
        $args = new Dict();
        $args->putAll($variables);

        $title = $payload['title'] ?? null;

        if (is_string($title) && trim($title) !== '') {
            // 引擎用 auto_gen_title 作为实例 title（否则退化成「{发起人}的{定义名}」）
            $args->put('auto_gen_title', mb_substr(trim($title), 0, 200));
        }

        $businessKey = $payload['business_key'] ?? null;

        if (is_scalar($businessKey) && (string) $businessKey !== '') {
            $args->put('business_no', mb_substr((string) $businessKey, 0, 100));
        }

        return $args;
    }

    /**
     * 引擎轨迹 → 旧实现对外结构（键名保持 `id/instance_id/event_type/node_id/description/user_id/timestamp`）
     *
     * `event_type` 用**引擎真实落库值**（`instance_started` / `task_completed` / …）而不是旧 Mock 的
     * 大写字面量：轨迹表里存的就是这些值，接口再翻译一次只会让「接口值 ≠ 库值」，
     * 排查时要在两套词汇之间来回对照。
     *
     * @return array<int, array<string, mixed>>
     */
    private function historyEntries(int $instanceId): array
    {
        try {
            $timeline = $this->historyService()->timeline((string) $instanceId);
        } catch (Throwable $e) {
            throw WorkflowActionException::fromEngine($e, '读取流程轨迹');
        }

        return array_map(static function (array $event): array {
            $data    = is_array($event['event_data'] ?? null) ? $event['event_data'] : [];
            $comment = $data['comment'] ?? null;
            $name    = (string) ($event['event_name'] ?? $event['event_type'] ?? '');

            return [
                'id'          => (string) ($event['id'] ?? ''),
                'instance_id' => (string) ($event['instance_id'] ?? ''),
                'task_id'     => $event['task_id'] ?? null,
                'event_type'  => (string) ($event['event_type'] ?? ''),
                'event_name'  => $name,
                // 事件表没有 node 列；任务事件的 event_data.node_name 就是节点标识
                // （本引擎 node_id == node_name，见 ProcessTaskService::buildTaskColumns）
                'node_id'     => $data['node_name'] ?? null,
                'description' => is_scalar($comment) && (string) $comment !== ''
                    ? $name . '：' . (string) $comment
                    : $name,
                'user_id'     => $event['user_id'] ?? null,
                'user_name'   => $event['user_name'] ?? null,
                'timestamp'   => (string) ($event['created_at'] ?? ''),
                'event_data'  => $data,
            ];
        }, $timeline);
    }

    /**
     * 实例执行时长（毫秒）；`started_at` 缺失时返回 null（不编造 0）
     *
     * @param array<string, mixed> $instance 实例行
     */
    private function durationMs(array $instance): ?int
    {
        $startedAt = $instance['started_at'] ?? null;

        if (!is_string($startedAt) || $startedAt === '') {
            return null;
        }

        $timestamp = strtotime($startedAt);

        return $timestamp === false ? null : max(0, (time() - $timestamp) * 1000);
    }

    /**
     * 从引擎实例对象取主键（`getData('id')` 是引擎侧的统一取数口）
     */
    private function instanceIdOf(?IProcessInstance $instance): ?int
    {
        if ($instance === null) {
            return null;
        }

        $raw = $instance->getData('id');

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /**
     * 惰性取语言服务
     */
    private function t(string $key): string
    {
        $this->lang ??= new LanguageService();

        return $this->lang->trans($key);
    }

    private function instances(): WorkflowInstanceRepository
    {
        return $this->instances ??= new WorkflowInstanceRepository();
    }

    private function tasks(): WorkflowTaskRepository
    {
        return $this->tasks ??= new WorkflowTaskRepository();
    }

    private function events(): WorkflowEventLogRepository
    {
        return $this->events ??= new WorkflowEventLogRepository();
    }

    private function definitions(): WorkflowDefinitionRepository
    {
        return $this->definitions ??= new WorkflowDefinitionRepository();
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

    private function instanceService(): \Alkaid\Workflow\Engine\interface\services\IProcessInstanceService
    {
        return $this->bootstrap()->instanceService();
    }

    private function taskService(): \Alkaid\Workflow\Engine\interface\services\IProcessTaskService
    {
        return $this->bootstrap()->taskService();
    }

    private function historyService(): \Alkaid\Workflow\Engine\interface\services\IProcessInstanceHistoryService
    {
        return $this->bootstrap()->historyService();
    }
}
