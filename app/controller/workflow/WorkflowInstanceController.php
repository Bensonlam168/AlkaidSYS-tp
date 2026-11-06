<?php

declare(strict_types=1);

namespace app\controller\workflow;

use app\controller\ApiController;
use app\Request;
use Closure;
use Infrastructure\Workflow\Exception\WorkflowActionException;
use Infrastructure\Workflow\Service\WorkflowInstanceService;
use think\facade\Log;
use think\Response;
use Throwable;

/**
 * Workflow Instance API Controller | 工作流实例 API 控制器
 *
 * 工作流实例管理的 RESTful API。
 *
 * ## 本控制器的职责（分层约定）
 *
 * 只做四件事：**取参 → 参数校验 → 调 Service → 包统一响应**。
 * 归属校验、状态机、引擎调用、落库全部在 `Infrastructure\Workflow\Service\WorkflowInstanceService`，
 * 数据访问在 `Infrastructure\Workflow\Repository\*`。控制器里不出现 SQL、不出现引擎调用。
 *
 * ## 与旧实现的关系
 *
 * 旧版本是 `protected static array $instances` / `$history` 的内存 Mock：21 个端点看起来都在，
 * 实际一个字节都没落库，重启即清空。本版本改为真库 + 真引擎：
 *
 * | 端点 | 旧（Mock） | 新（真库 / 引擎） |
 * | ---- | ---------- | ----------------- |
 * | `index` | 内存数组过滤 | `workflow_instances` 查询（强制 tenant 过滤 + 分页下推） |
 * | `read` | 内存对象 | 库行 + 在办任务 + 引擎轨迹 |
 * | `save` | 手写数组塞内存 | 引擎 `startProcessInstanceById()`（实例/首个任务/事件都由引擎写） |
 * | `cancel` | 改数组字段 | 在办任务 `abandonProcessTask()` + 实例置终态 + 事件 |
 * | `suspend` / `resume` | 改数组字段 | 实例状态落库 + 事件 |
 * | `history` | 内存 `$history` | 引擎 `timeline()`（`workflow_event_logs`） |
 * | `getVariables` / `updateVariables` | 内存数组 | 库中 `variables` / 引擎 `addVariable()`、`removeVariable()` |
 *
 * ## 操作人身份
 *
 * 所有写操作的操作人**只**取 `$request->userId()`（由 `Auth` 中间件从 JWT 写入）。
 * 绝不从请求头 / 请求体读取用户身份：引擎的 `isAllowed()` 对 `flow_auto`、`flow.admin` 无条件放行，
 * 一旦让 HTTP 请求能自称这两个身份，等于把每个调用者都变成超级管理员。
 *
 * @package app\controller\workflow
 */
class WorkflowInstanceController extends ApiController
{
    /**
     * Instance status constants | 实例状态常量
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * 应用服务（惰性构造：控制器本身无状态，服务内部只为仓储/语言服务起惰性依赖）
     */
    private ?WorkflowInstanceService $instanceService = null;

    /**
     * List workflow instances | 列出工作流实例
     *
     * GET /workflow/instances
     *
     * Query Parameters:
     * - page: int（默认 1）
     * - page_size: int（默认 20，上限 100）
     * - definition_id: int（按定义筛选）
     * - status: string（pending|running|suspended|completed|failed|cancelled）
     * - started_by: int（按发起人筛选，落库列是 `created_by`）
     * - business_key: string
     * - date_from / date_to: date（按 `started_at` 过滤）
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

        // 租户ID 由中间件写入（缺失时由全局异常处理器统一处理），这里不可能为 null
        $tenantId = $request->tenantId();

        return $this->respond(function () use ($tenantId, $params, $page, $pageSize): Response {
            $result = $this->service()->paginate($tenantId, [
                'status'        => $params['status'] ?? null,
                'definition_id' => $params['definition_id'] ?? null,
                'started_by'    => $params['started_by'] ?? null,
                'business_key'  => $params['business_key'] ?? null,
                'date_from'     => $params['date_from'] ?? null,
                'date_to'       => $params['date_to'] ?? null,
            ], $page, $pageSize);

            return $this->paginate($result['list'], $result['total'], $page, $pageSize);
        });
    }

    /**
     * Get workflow instance details | 获取工作流实例详情
     *
     * GET /workflow/instances/{id}
     *
     * 返回值 = 实例行 + `current_tasks`（在办任务）+ `history`（引擎轨迹）。
     */
    public function read(string $id, Request $request): Response
    {
        $tenantId   = $request->tenantId();
        $instanceId = $id;

        return $this->respond(function () use ($tenantId, $instanceId): Response {
            return $this->success($this->service()->detail($tenantId, $this->parseId($instanceId)));
        });
    }

    /**
     * Start workflow instance | 启动工作流实例
     *
     * POST /workflow/instances
     *
     * Request Body:
     * {
     *   "definition_id": 123,              // 必填，定义的数字主键（旧实现的 `wf_xxx` 字符串不再接受）
     *   "variables": {...},                // 可选，启动参数即实例变量
     *   "title": "报销单 2025-001",         // 可选，引擎用 auto_gen_title 作为实例标题
     *   "business_key": "BX-2025-001"      // 可选，引擎用 business_no 作为业务号
     * }
     *
     * 实例行、第一个任务、`instance_started` 事件**全部由引擎写**，宿主不重复 INSERT ——
     * 两份写入点必然分叉（引擎的任务参与者解析、首节点受理人规则都在引擎内部）。
     */
    public function save(Request $request): Response
    {
        $data     = $request->post();
        $tenantId = $request->tenantId();
        $userId   = $request->userId();

        // 引擎写 `created_by` / `assignee`（都带 users 外键），系统身份无法表示
        if ($userId === null) {
            return $this->unauthorized();
        }

        if (!isset($data['definition_id']) || $data['definition_id'] === '' || $data['definition_id'] === null) {
            return $this->validationError([
                'definition_id' => $this->langService->trans('workflow.definition_id_required'),
            ]);
        }

        return $this->respond(function () use ($tenantId, $userId, $data): Response {
            $instance = $this->service()->start($tenantId, $userId, $data);

            return $this->success(
                $instance,
                $this->langService->trans('workflow.instance_started'),
                0,
                201
            );
        });
    }

    /**
     * Cancel workflow instance | 取消工作流实例
     *
     * POST /workflow/instances/{id}/cancel
     *
     * Request Body: { "reason": "取消原因" }
     *
     * 取消原因写入事件快照（`event_data.reason`），**不写** `error_message`：
     * 后者是「引擎执行失败」的列，把人工取消的原因放进去会让失败排查无从区分。
     */
    public function cancel(string $id, Request $request): Response
    {
        $tenantId   = $request->tenantId();
        $instanceId = $id;
        $data       = $request->post();
        $userId     = $request->userId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        $reason = $data['reason'] ?? null;

        return $this->respond(function () use ($tenantId, $userId, $instanceId, $reason): Response {
            $instance = $this->service()->cancel(
                $tenantId,
                $userId,
                $this->parseId($instanceId),
                is_scalar($reason) ? (string) $reason : null
            );

            return $this->success($instance, $this->langService->trans('workflow.instance_cancelled'));
        });
    }

    /**
     * Suspend workflow instance | 暂停工作流实例
     *
     * POST /workflow/instances/{id}/suspend
     */
    public function suspend(string $id, Request $request): Response
    {
        $tenantId   = $request->tenantId();
        $instanceId = $id;
        $userId     = $request->userId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        return $this->respond(function () use ($tenantId, $userId, $instanceId): Response {
            $instance = $this->service()->suspend($tenantId, $userId, $this->parseId($instanceId));

            return $this->success($instance, $this->langService->trans('workflow.instance_suspended'));
        });
    }

    /**
     * Resume workflow instance | 恢复工作流实例
     *
     * POST /workflow/instances/{id}/resume
     */
    public function resume(string $id, Request $request): Response
    {
        $tenantId   = $request->tenantId();
        $instanceId = $id;
        $userId     = $request->userId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        return $this->respond(function () use ($tenantId, $userId, $instanceId): Response {
            $instance = $this->service()->resume($tenantId, $userId, $this->parseId($instanceId));

            return $this->success($instance, $this->langService->trans('workflow.instance_resumed'));
        });
    }

    /**
     * Get instance execution history | 获取实例执行历史
     *
     * GET /workflow/instances/{id}/history
     *
     * 返回 `{instance_id, entries, count}`（与旧结构兼容）；`entries[*].event_type`
     * 是**库里真实值**（`instance_started` / `task_completed` / `instance_rejected` / `instance_cancelled` …），
     * 不再是大写 Mock 词汇（`STARTED` / `CANCELLED`）。
     */
    public function history(string $id, Request $request): Response
    {
        $tenantId   = $request->tenantId();
        $instanceId = $id;

        return $this->respond(function () use ($tenantId, $instanceId): Response {
            return $this->success($this->service()->history($tenantId, $this->parseId($instanceId)));
        });
    }

    /**
     * Get instance variables | 获取实例变量
     *
     * GET /workflow/instances/{id}/variables
     */
    public function getVariables(string $id, Request $request): Response
    {
        $tenantId   = $request->tenantId();
        $instanceId = $id;

        return $this->respond(function () use ($tenantId, $instanceId): Response {
            return $this->success($this->service()->variables($tenantId, $this->parseId($instanceId)));
        });
    }

    /**
     * Update instance variables | 更新实例变量
     *
     * PUT /workflow/instances/{id}/variables
     *
     * Request Body:
     * {
     *   "variables": { "key1": "value1" },
     *   "merge": true      // true=合并（默认）；false=全量替换
     * }
     */
    public function updateVariables(string $id, Request $request): Response
    {
        $tenantId   = $request->tenantId();
        $instanceId = $id;
        $data       = $request->put();
        $userId     = $request->userId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        if (!isset($data['variables']) || !is_array($data['variables'])) {
            return $this->validationError([
                'variables' => $this->langService->trans('workflow.variables_required'),
            ]);
        }

        // 只有显式 false 才走「全量替换」；字符串 "false" 之类的一律按默认合并处理，避免误解语义
        $merge = ($data['merge'] ?? true) !== false;

        return $this->respond(function () use ($tenantId, $userId, $instanceId, $data, $merge): Response {
            $result = $this->service()->updateVariables(
                $tenantId,
                $userId,
                $this->parseId($instanceId),
                $data['variables'],
                $merge
            );

            return $this->success($result, $this->langService->trans('workflow.variables_updated'));
        });
    }

    // =====================================================================
    // 内部
    // =====================================================================

    /**
     * 统一的「业务异常 → 响应」出口
     *
     * 为什么要收在一处：
     * 1. 引擎与数据库抛出的异常**不能原样吐给客户端**（会泄露表结构、SQL、内部类名）；
     *    只有 `WorkflowActionException` 的 `publicMessage` 是「可以给用户看的话」；
     * 2. 其余 `Throwable` 一律 500 + 通用文案，原始异常写日志（带操作名，便于定位）。
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
            Log::error('[WORKFLOW_INSTANCE_FAILED] ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return $this->error(
                '工作流实例操作失败，请稍后重试或联系管理员 | workflow instance operation failed',
                WorkflowActionException::CODE_SERVER_ERROR,
                [],
                500
            );
        }
    }

    /**
     * 解析路径上的实例ID
     *
     * 非数字 / 非正数 → 404（路径参数不合法等价于「这条资源不存在」，
     * 不是客户端的参数格式错误 —— 那是请求体校验（422）的语义）。
     *
     * @throws WorkflowActionException
     */
    private function parseId(string $id): int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            throw WorkflowActionException::notFound($this->langService->trans('workflow.instance_not_found'));
        }

        return (int) $id;
    }

    /**
     * 应用服务（惰性构造，复用控制器的语言服务实例）
     */
    private function service(): WorkflowInstanceService
    {
        return $this->instanceService ??= new WorkflowInstanceService($this->langService);
    }
}
