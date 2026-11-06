<?php

declare(strict_types=1);

namespace app\controller\workflow;

use app\controller\ApiController;
use app\Request;
use Closure;
use Infrastructure\Workflow\Exception\WorkflowActionException;
use Infrastructure\Workflow\Service\WorkflowTaskService;
use think\facade\Log;
use think\Response;
use Throwable;

/**
 * Workflow Task API Controller | 工作流任务 API 控制器
 *
 * 工作流任务管理的 RESTful API。
 *
 * ## 本控制器的职责（分层约定）
 *
 * 只做四件事：**取参 → 参数校验 → 调 Service → 包统一响应**。
 * 租户归属、任务状态机、三层授权（租户/宿主/引擎）、引擎调用与落库全部在
 * `Infrastructure\Workflow\Service\WorkflowTaskService`，数据访问在 `Infrastructure\Workflow\Repository\*`。
 *
 * ## 与旧实现的关系
 *
 * 旧版本是 `protected static array $tasks` / `$taskHistory` 的内存 Mock：
 * 12 个端点全都「跑得通」，但一个字节都没落库 —— 重启即清空，且永不真正推进流程。本版本改为真库 + 真引擎：
 *
 * | 端点 | 旧（Mock） | 新（真库 / 引擎） |
 * | ---- | ---------- | ----------------- |
 * | `index` | 内存数组过滤 | `workflow_tasks` 查询（强制 tenant 过滤 + 分页下推） |
 * | `pending` | 内存里算候选 | `searchPending()`（assignee / 候选用户 / 候选组下推到 SQL） |
 * | `read` | 内存对象 + `$taskHistory` | 库行 + `workflow_event_logs` |
 * | `approve` / `complete` | 改内存字段 | 引擎 `executeProcessTask()`（任务状态、result、事件由引擎写） |
 * | `reject` | 改内存字段 | 引擎 `executeAndJumpToEnd()`（实例进入驳回终态） |
 * | `claim` / `unclaim` | 改内存字段 | 参与者集合交给引擎 + 宿主落受理人/状态 + 事件 |
 * | `transfer` / `delegate` | 改内存字段（`delegated_to` 等不存在列） | 目标用户校验 + 受理人迁移 + 事件 |
 * | `getForm` / `submitForm` | 只读写内存 `form_data` | 读库 `form_data`；提交后**推进流程** |
 *
 * ## 操作人身份（安全不变量）
 *
 * 所有写操作的操作人**只**取 `$request->userId()`：它由 `Auth` 中间件从 JWT 的 `sub` 写入，
 * 请求头（`X-User-ID` 等）与请求体里的任何 `operator` / `user_id` 字段都不会被读取。
 * 这一点是硬性的：引擎的 `isAllowed()` 对 `flow_auto`、`flow.admin` 无条件放行，
 * 一旦允许 HTTP 请求自称这两个身份，等于把任何登录用户都变成超级管理员。
 *
 * @package app\controller\workflow
 */
class WorkflowTaskController extends ApiController
{
    /**
     * Task status constants | 任务状态常量
     *
     * 取值与 `workflow_tasks.status` 的列注释一致（保留常量是因为既有测试与调用方按类常量引用）。
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_TRANSFERRED = 'transferred';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Task type constants | 任务类型常量
     */
    public const TYPE_USER = 'user';
    public const TYPE_SERVICE = 'service';
    public const TYPE_SCRIPT = 'script';
    public const TYPE_APPROVAL = 'approval';
    public const TYPE_COUNTERSIGN = 'countersign';

    /**
     * 应用服务（惰性构造：控制器本身无状态）
     */
    private ?WorkflowTaskService $workflowTaskService = null;

    /**
     * List workflow tasks | 列出工作流任务
     *
     * GET /workflow/tasks
     *
     * Query Parameters:
     * - page / page_size
     * - instance_id: int
     * - status: string
     * - type: string（映射到 `task_type` 列）
     * - assignee: int
     * - node_id: string
     */
    public function index(Request $request): Response
    {
        $params   = $request->get();
        $page     = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['page_size'] ?? 20);

        $pagination = $this->validatePagination($page, $pageSize);

        if (!$pagination['valid']) {
            return $this->validationError($pagination['errors']);
        }

        $tenantId = $request->tenantId();

        return $this->respond(function () use ($tenantId, $params, $page, $pageSize): Response {
            $result = $this->service()->paginate($tenantId, [
                'status'      => $params['status'] ?? null,
                'instance_id' => $params['instance_id'] ?? null,
                'type'        => $params['type'] ?? null,
                'assignee'    => $params['assignee'] ?? null,
                'node_id'     => $params['node_id'] ?? null,
            ], $page, $pageSize);

            return $this->paginate($result['list'], $result['total'], $page, $pageSize);
        });
    }

    /**
     * Get pending tasks for current user | 获取当前用户待办任务
     *
     * GET /workflow/tasks/pending
     *
     * 「待办」= 在办状态 + （受理人是我 或 我在候选用户里 或 我的角色命中候选组）。
     * 判定下推到 SQL：只有先过滤再分页，`total` 才与 `list` 一致。
     */
    public function pending(Request $request): Response
    {
        $params   = $request->get();
        $page     = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['page_size'] ?? 20);

        $pagination = $this->validatePagination($page, $pageSize);

        if (!$pagination['valid']) {
            return $this->validationError($pagination['errors']);
        }

        $userId   = $request->userId();
        $tenantId = $request->tenantId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        // 角色 slug 必须走 app\Request::userGroups()：属性访问会落到 think\Request::__get()
        // → middleware('userGroups')，恒为 null（缺陷 R1 的成因）
        $userGroups = $request->userGroups();

        return $this->respond(function () use ($tenantId, $userId, $userGroups, $page, $pageSize): Response {
            $result = $this->service()->pending($tenantId, $userId, $userGroups, $page, $pageSize);

            return $this->paginate($result['list'], $result['total'], $page, $pageSize);
        });
    }

    /**
     * Get task details | 获取任务详情
     *
     * GET /workflow/tasks/{id}
     *
     * 返回任务行 + `history`（该任务的宿主动作事件）。
     */
    public function read(string $id, Request $request): Response
    {
        $tenantId = $request->tenantId();
        $taskId   = $id;

        return $this->respond(function () use ($tenantId, $taskId): Response {
            return $this->success($this->service()->detail($tenantId, $this->parseId($taskId)));
        });
    }

    /**
     * Approve task | 审批通过
     *
     * POST /workflow/tasks/{id}/approve
     *
     * Request Body: { "comment": "同意", "form_data": {...} }
     *
     * 走引擎 `executeProcessTask()`：任务状态、`result`、审批意见、`task_completed` 事件都由引擎写；
     * 返回值额外带 `next_tasks`（本次推进产生的新任务）。
     */
    public function approve(string $id, Request $request): Response
    {
        return $this->runTaskAction($id, $request, 'approve');
    }

    /**
     * Reject task | 审批拒绝
     *
     * POST /workflow/tasks/{id}/reject
     *
     * Request Body: { "comment": "金额超标" }
     *
     * 走引擎 `executeAndJumpToEnd()`：驳回 = 当前任务结束 + 跳到结束节点；
     * 引擎按 `submit_type = REJECT` 把实例置为驳回终态（`cancelled`），事件表留下 `instance_rejected`。
     */
    public function reject(string $id, Request $request): Response
    {
        return $this->runTaskAction($id, $request, 'reject');
    }

    /**
     * Transfer task | 转办任务
     *
     * POST /workflow/tasks/{id}/transfer
     *
     * Request Body: { "target_user": 102, "comment": "请他处理" }
     *
     * ⚠️ 表里没有 `delegated_*` / `transferred_*` 列：转办的真实落库形态是
     * 「`assignee` 改成目标用户 + 原受理人移出 `candidate_users` + `task_transferred` 事件」。
     */
    public function transfer(string $id, Request $request): Response
    {
        return $this->runTaskAction($id, $request, 'transfer');
    }

    /**
     * Delegate task | 委托任务
     *
     * POST /workflow/tasks/{id}/delegate
     *
     * Request Body: { "delegate_to": 103, "comment": "我出差，请你代办" }
     *
     * 与转办的唯一可落库差异：**原受理人保留在 `candidate_users`**（委派不等于彻底交出去）。
     */
    public function delegate(string $id, Request $request): Response
    {
        return $this->runTaskAction($id, $request, 'delegate');
    }

    /**
     * Claim task | 认领任务
     *
     * POST /workflow/tasks/{id}/claim
     *
     * 认领前必须确认：任务属于本租户、状态是 pending、尚未被受理、**我确实在候选范围内**
     * （`tests/Feature/Workflow/WorkflowTaskClaimAuthorizationTest.php` 钉的就是这条）。
     */
    public function claim(string $id, Request $request): Response
    {
        return $this->runTaskAction($id, $request, 'claim');
    }

    /**
     * Unclaim task | 取消认领
     *
     * POST /workflow/tasks/{id}/unclaim
     *
     * 只有受理人本人、且任务处于 claimed 状态才能归还；归还后任务回到 pending。
     */
    public function unclaim(string $id, Request $request): Response
    {
        return $this->runTaskAction($id, $request, 'unclaim');
    }

    /**
     * Complete task | 完成任务
     *
     * POST /workflow/tasks/{id}/complete
     *
     * Request Body: { "output": {...}, "comment": "已完成" }
     */
    public function complete(string $id, Request $request): Response
    {
        return $this->runTaskAction($id, $request, 'complete');
    }

    /**
     * Get task form | 获取任务表单
     *
     * GET /workflow/tasks/{id}/form
     *
     * 返回 `{task_id, form_schema, form_data, form_rules}`（与旧结构兼容）。
     * `workflow_tasks` 没有 `form_schema` / `form_rules` 列（表单模板在流程定义体上），
     * 因此这两项是**如实占位**（`null` / `[]`），不是静默丢掉字段。
     */
    public function getForm(string $id, Request $request): Response
    {
        $tenantId = $request->tenantId();
        $taskId   = $id;

        return $this->respond(function () use ($tenantId, $taskId): Response {
            return $this->success($this->service()->form($tenantId, $this->parseId($taskId)));
        });
    }

    /**
     * Submit task form | 提交任务表单
     *
     * POST /workflow/tasks/{id}/form
     *
     * Request Body: { "form_data": {...}, "comment": "补充说明" }
     *
     * ⚠️ 语义变化：旧实现只把 `form_data` 存进内存、流程原地不动；
     * 现在提交表单会**推进流程**（`executeProcessTask()`，表单键以 `f_` 前缀并入 args）。
     * 不推进的话，「提交表单」与「什么都没做」对流程而言无法区分，任务会永远停在待办。
     */
    public function submitForm(string $id, Request $request): Response
    {
        $taskId = $id;
        $data   = $request->post();
        $userId = $request->userId();
        $tenantId = $request->tenantId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        if (!isset($data['form_data']) || !is_array($data['form_data']) || $data['form_data'] === []) {
            return $this->validationError([
                'form_data' => $this->langService->trans('workflow.form_data_required'),
            ]);
        }

        $userGroups = $request->userGroups();

        return $this->respond(function () use ($tenantId, $userId, $userGroups, $taskId, $data): Response {
            $task = $this->service()->submitForm(
                $tenantId,
                $userId,
                $userGroups,
                $this->parseId($taskId),
                $data
            );

            return $this->success([
                'task_id'    => $task['id'] ?? null,
                'form_data'  => $task['form_data'] ?? [],
                'status'     => $task['status'] ?? null,
                'next_tasks' => $task['next_tasks'] ?? [],
            ], $this->langService->trans('workflow.form_submitted'));
        });
    }

    // =====================================================================
    // 内部
    // =====================================================================

    /**
     * 任务动作的统一入口（取身份 → 调服务 → 包响应）
     *
     * 为什么把 9 个动作收成一个入口：
     * 它们的前置条件完全相同（真实登录用户 + 租户上下文 + 任务ID），
     * 分散写 9 遍只会在某一份里漏掉 `userId === null` 的守卫。
     *
     * @param string $action approve|reject|complete|claim|unclaim|transfer|delegate
     */
    private function runTaskAction(string $id, Request $request, string $action): Response
    {
        $userId = $request->userId();

        // 操作人必须来自 JWT（Auth 中间件写入）；未认证 → 401，绝不退化成 'system' / 'flow_auto'
        if ($userId === null) {
            return $this->unauthorized();
        }

        $tenantId   = $request->tenantId();
        $taskId     = $id;
        $payload    = $request->post();
        $userGroups = $request->userGroups();

        return $this->respond(function () use ($action, $tenantId, $userId, $userGroups, $taskId, $payload): Response {
            $service   = $this->service();
            $resolvedId = $this->parseId($taskId);

            [$task, $messageKey] = match ($action) {
                'approve'  => [$service->approve($tenantId, $userId, $userGroups, $resolvedId, $payload), 'workflow.task_approved'],
                'reject'   => [$service->reject($tenantId, $userId, $userGroups, $resolvedId, $payload), 'workflow.task_rejected'],
                'complete' => [$service->complete($tenantId, $userId, $userGroups, $resolvedId, $payload), 'workflow.task_completed'],
                'claim'    => [$service->claim($tenantId, $userId, $userGroups, $resolvedId), 'workflow.task_claimed'],
                'unclaim'  => [$service->unclaim($tenantId, $userId, $resolvedId), 'workflow.task_unclaimed'],
                'transfer' => [$service->transfer($tenantId, $userId, $userGroups, $resolvedId, $payload), 'workflow.task_transferred'],
                'delegate' => [$service->delegate($tenantId, $userId, $userGroups, $resolvedId, $payload), 'workflow.task_delegated'],
                default    => throw WorkflowActionException::notFound($this->langService->trans('workflow.task_not_found')),
            };

            return $this->success($task, $this->langService->trans($messageKey));
        });
    }

    /**
     * 统一的「业务异常 → 响应」出口
     *
     * 引擎与数据库抛出的异常**不能原样返回给客户端**（会泄露 SQL、表结构、内部类名）：
     * 只有 `WorkflowActionException::publicMessage` 是「可以给用户看的话」，
     * 其余 `Throwable` 一律 500 + 通用文案，原始异常写日志。
     *
     * @param Closure(): Response $action
     */
    private function respond(Closure $action): Response
    {
        try {
            return $action();
        } catch (WorkflowActionException $e) {
            return $this->error($e->getPublicMessage(), $e->getBusinessCode(), $e->getErrors(), $e->getHttpStatus());
        } catch (Throwable $e) {
            Log::error('[WORKFLOW_TASK_FAILED] ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return $this->error(
                '工作流任务操作失败，请稍后重试或联系管理员 | workflow task operation failed',
                WorkflowActionException::CODE_SERVER_ERROR,
                [],
                500
            );
        }
    }

    /**
     * 解析路径上的任务ID
     *
     * 非数字 / 非正数 → 404（资源不存在），与请求体校验失败（422）区分开。
     *
     * @throws WorkflowActionException
     */
    private function parseId(string $id): int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            throw WorkflowActionException::notFound($this->langService->trans('workflow.task_not_found'));
        }

        return (int) $id;
    }

    /**
     * 应用服务（惰性构造，复用控制器的语言服务实例）
     */
    private function service(): WorkflowTaskService
    {
        return $this->workflowTaskService ??= new WorkflowTaskService($this->langService);
    }
}
