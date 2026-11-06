<?php

declare(strict_types=1);

namespace app\controller\workflow;

use app\controller\ApiController;
use app\Request;
use Closure;
use Infrastructure\Workflow\Exception\WorkflowActionException;
use Infrastructure\Workflow\Service\WorkflowDefinitionService;
use think\facade\Log;
use think\Response;
use Throwable;

/**
 * Workflow Definition API Controller | 工作流定义 API 控制器
 *
 * ## 本控制器的职责（分层约定）
 *
 * 只做四件事：**取参 → 参数校验 → 调 Service → 包统一响应**。
 * 版本/发布/归档状态机、定义体校验、归属判断、落库全部在
 * `Infrastructure\Workflow\Service\WorkflowDefinitionService`，
 * 数据访问在 `Infrastructure\Workflow\Repository\*`。控制器里不出现 SQL、不出现引擎调用。
 *
 * ## 与旧实现的关系（Mock → 真库/引擎）
 *
 * 旧版本是 `protected static array $definitions` 的内存 Mock：9 个端点看起来都在，
 * 实际一个字节都没落库，重启即清空；`version/status/is_active` 三列的组合语义也没人负责。
 *
 * | 端点 | 旧（Mock） | 新（真库 / 引擎） |
 * | ---- | ---------- | ----------------- |
 * | `index` | 内存过滤 + `usort` | `workflow_definitions` 查询（强制 tenant 过滤 + 分页下推） |
 * | `read` | 内存对象 | 库行（含 `nodes_config` 定义体） |
 * | `save` | `WorkflowBuilder` 拼对象塞内存 | 插 `version=1` 的草稿行；只给 BPMN 时经 `BpmnConverter` 导入 |
 * | `update` | 就地改字段 | 草稿原地改；**已发布 fork 出新版本草稿**；已归档 400 |
 * | `delete` | `unset($array[$id])` | 已发布 / 有实例一律拒绝（CASCADE 会删掉实例，见 Service 注释） |
 * | `publish` | `$definition->publish()` | 先跑引擎校验器 → `status=published`、`is_active=1`、同家族旧版降级 |
 * | `archive` | `$definition->archive()` | `status=archived`、`is_active=0`（终态，之后不可启动） |
 * | `validateAction` | `WorkflowValidator::validate()` | `WorkflowDefinitionValidator`：**真跑** `ModelParser::parse()` 与条件表达式编译 |
 * | `clone` | 内存里复制数组 | 插新行（新 name、`version=1`、草稿），不复制触发器 |
 *
 * ## 为什么响应里没有 `published_at`
 *
 * `workflow_definitions` **没有** `published_at` 列（`SHOW CREATE TABLE` 实测）。
 * 旧 Mock 每次 clone 都把 `published_at` 置 null，那个字段从来没落过库 ——
 * 与其继续返回一个永远为 null 的假字段，不如不返回。发布时间的可追溯口径是
 * `updated_at`（发布动作会刷新它）+ `workflow_event_logs`。
 *
 * @package app\controller\workflow
 */
class WorkflowDefinitionController extends ApiController
{
    /**
     * 应用服务（惰性构造：控制器本身无状态，服务内部只为仓储/语言服务起惰性依赖）
     */
    private ?WorkflowDefinitionService $definitionService = null;

    /**
     * List workflow definitions | 列出工作流定义
     *
     * GET /v1/workflow/definitions
     *
     * Query Parameters:
     * - page: int（默认 1）
     * - page_size: int（默认 20，上限 100）
     * - status: string（draft|published|archived）
     * - type: string（approval|business|automation|integration|hybrid）
     * - category: string
     * - name: string（按 name / display_name 模糊匹配）
     * - active: bool（`is_active` 过滤；列表默认返回**全部版本**）
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

        // 租户ID 由中间件写入（`app\Request::tenantId(): int`，恒 >= 1）
        $tenantId = $request->tenantId();

        return $this->respond(function () use ($tenantId, $params, $page, $pageSize): Response {
            $result = $this->service()->paginate($tenantId, [
                'status'   => $params['status'] ?? null,
                'type'     => $params['type'] ?? null,
                'category' => $params['category'] ?? null,
                'name'     => $params['name'] ?? null,
                'active'   => $params['active'] ?? null,
            ], $page, $pageSize);

            return $this->paginate($result['list'], $result['total'], $page, $pageSize);
        });
    }

    /**
     * Get workflow definition details | 获取工作流定义详情
     *
     * GET /v1/workflow/definitions/{id}
     */
    public function read(string $id, Request $request): Response
    {
        $tenantId     = $request->tenantId();
        $definitionId = $id;

        return $this->respond(function () use ($tenantId, $definitionId): Response {
            return $this->success(
                $this->service()->detail($tenantId, $this->parseId($definitionId))
            );
        });
    }

    /**
     * Create workflow definition | 创建工作流定义
     *
     * POST /v1/workflow/definitions
     *
     * Request Body:
     * {
     *   "name": "my_workflow",              // 必填，`^[a-zA-Z][a-zA-Z0-9_]*$`，租户内唯一
     *   "display_name": "报销审批",
     *   "description": "...",
     *   "type": "approval",
     *   "category": "finance",
     *   "icon": "wallet",
     *   "nodes_config": {"nodes": [...], "edges": [...]},   // LogicFlow（唯一执行格式）
     *   "bpmn_xml": "<definitions>...</definitions>",      // 可选，仅在没有 nodes_config 时导入
     *   "triggers_config": {...},
     *   "variables_schema": {...}
     * }
     *
     * 新定义永远是 `version=1` 的**草稿**（`status=draft`、`is_active=0`）：
     * 客户端不能指定 `version`（版本号由服务端单调分配），也不能指定状态
     * （跳过发布门 = 让未审核的图直接可启动）。
     */
    public function save(Request $request): Response
    {
        // `post()` 在「请求体缺失 / 非 JSON」时返回 null，统一折成空数组再交给服务层校验
        $data     = $request->post();
        $data     = is_array($data) ? $data : [];
        $tenantId = $request->tenantId();
        $userId   = $request->userId();

        // `created_by` / `updated_by` 带 `users.id` 外键：系统身份无法表示
        if ($userId === null) {
            return $this->unauthorized();
        }

        return $this->respond(function () use ($tenantId, $userId, $data): Response {
            return $this->success(
                $this->service()->create($tenantId, $userId, $data),
                $this->langService->trans('workflow.definition_created'),
                0,
                201
            );
        });
    }

    /**
     * Update workflow definition | 更新工作流定义
     *
     * PUT /v1/workflow/definitions/{id}
     *
     * **语义按当前状态分三种**（口径见 `WorkflowDefinitionService` 类注释）：
     * - `draft`    → 原地更新，200；
     * - `published` → 产出（或复用）一份更高版本的草稿并打补丁，**201** +
     *   `data.forked = true`、`data.forked_from = 源版本ID`、`data.source_id`；
     * - `archived` → 400（终态不可改，要改请 `clone`）。
     *
     * 为什么不能对已发布行就地改：在跑实例的 `definition_id` 指着那一行，
     * 引擎在推进时按该行现读现解析图 —— 把图换掉等于让在跑的审批中途换道。
     */
    public function update(string $id, Request $request): Response
    {
        $tenantId     = $request->tenantId();
        $definitionId = $id;
        $data         = $request->put();
        $data         = is_array($data) ? $data : [];
        $userId       = $request->userId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        return $this->respond(function () use ($tenantId, $userId, $definitionId, $data): Response {
            $result = $this->service()->update($tenantId, $userId, $this->parseId($definitionId), $data);
            $row    = $result['row'];

            if ($result['forked']) {
                $row['forked']      = true;
                $row['forked_from'] = $result['source_id'];
                $row['source_id']   = $result['source_id'];

                return $this->success(
                    $row,
                    '已发布版本不可就地修改，已生成新版本草稿 | published definition forked into a new draft',
                    0,
                    201
                );
            }

            return $this->success($row, $this->langService->trans('workflow.definition_updated'));
        });
    }

    /**
     * Delete workflow definition | 删除工作流定义
     *
     * DELETE /v1/workflow/definitions/{id}
     *
     * 拒绝条件（详见 Service 注释）：
     * - `published` → 400 `cannot_delete_published`（先归档）；
     * - 任一实例引用 → **409**，响应 `data.errors.instance_count` 说明数量并建议改用归档。
     *
     * 为什么比旧 Mock 严格：`workflow_instances.definition_id` 是 `ON DELETE CASCADE`，
     * 物理删除会连带删掉实例、任务、轨迹 —— 那是不可恢复的数据丢失。
     */
    public function delete(string $id, Request $request): Response
    {
        $tenantId     = $request->tenantId();
        $definitionId = $id;

        return $this->respond(function () use ($tenantId, $definitionId): Response {
            $result = $this->service()->delete($tenantId, $this->parseId($definitionId));

            return $this->success($result, $this->langService->trans('workflow.definition_deleted'));
        });
    }

    /**
     * Publish workflow definition | 发布工作流定义
     *
     * POST /v1/workflow/definitions/{id}/publish
     *
     * 只有 `draft` 可以发布；发布前**真跑引擎校验器**，不通过 → 400，`data.errors` 里带
     * `issues`（结构化问题列表）与 `summary`。一条「能存进库但引擎跑不起来」的发布版本，
     * 等于把一个 500 留给所有启动实例的调用方。
     */
    public function publish(string $id, Request $request): Response
    {
        $tenantId     = $request->tenantId();
        $definitionId = $id;
        $userId       = $request->userId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        return $this->respond(function () use ($tenantId, $userId, $definitionId): Response {
            return $this->success(
                $this->service()->publish($tenantId, $userId, $this->parseId($definitionId)),
                $this->langService->trans('workflow.definition_published')
            );
        });
    }

    /**
     * Archive workflow definition | 归档工作流定义
     *
     * POST /v1/workflow/definitions/{id}/archive
     *
     * 归档是**终态**：`status=archived` + `is_active=0`。之后
     * ① 不能启动新实例（`WorkflowInstanceService::start()` 的闸会拒）；
     * ② 不能编辑（PUT → 400）；③ 不能重复归档（400）。
     * 但在跑实例不受影响 —— 它们仍然按归档前那一版的图跑完。
     */
    public function archive(string $id, Request $request): Response
    {
        $tenantId     = $request->tenantId();
        $definitionId = $id;
        $userId       = $request->userId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        return $this->respond(function () use ($tenantId, $userId, $definitionId): Response {
            return $this->success(
                $this->service()->archive($tenantId, $userId, $this->parseId($definitionId)),
                $this->langService->trans('workflow.definition_archived')
            );
        });
    }

    /**
     * Validate workflow definition | 验证工作流定义
     *
     * POST /v1/workflow/definitions/{id}/validate
     *
     * 命名说明（独立评审问题 4）：本动作方法曾命名为 `validate()`，与父类
     * `app\BaseController::validate()`（ThinkPHP 数据验证，签名为
     * `validate(array $data, string|array $validate, array $message = [], bool $batch = false)`）同名。
     * 上一轮为让类能加载，把签名放宽成 `mixed` 并回退到 `$this->request`，副作用是：
     * 客户端只要传 `?message=x`，框架按参数名绑定就会触发
     * `TypeError: Argument #3 ($message) must be of type array, string given` → 可控 500。
     *
     * 现保持 `validateAction()` 的**类型化签名**（`string $id, Request $request`）；
     * 路由 URL 保持不变，`route/workflow.php` 中的目标方法名也是 `@validateAction`。
     *
     * 语义：本端点是**查询式**报告（前端设计器要拿到 issues 逐条高亮），因此
     * 「图不合法」仍然返回 **200**，靠 `data.valid = false` + `data.issues` 表达；
     * 真正拿校验当门的是 `publish`（那里失败是 400）。
     *
     * 返回结构（`data`）：
     * ```
     * {
     *   "valid": bool,                      // 没有 error 级 issue
     *   "errors": string[],                 // 兼容旧契约
     *   "warnings": string[],               // 兼容旧契约
     *   "info": string[],                   // 兼容旧契约
     *   "issues": [{code, severity, path, message}],
     *   "summary": {"errors": int, "warnings": int},
     *   "source": "logicflow"|"bpmn_xml"|"none",
     *   "engine": {"declared_nodes", "parsed_nodes", "has_start"},
     *   "definition_id": int, "name": string, "version": int, "status": string
     * }
     * ```
     */
    public function validateAction(string $id, Request $request): Response
    {
        $tenantId     = $request->tenantId();
        $definitionId = $id;

        return $this->respond(function () use ($tenantId, $definitionId): Response {
            return $this->success($this->service()->validate($tenantId, $this->parseId($definitionId)));
        });
    }

    /**
     * Clone workflow definition | 克隆工作流定义
     *
     * POST /v1/workflow/definitions/{id}/clone
     *
     * Request Body:
     * {
     *   "name": "new_workflow_name",       // 可选，默认 `{源name}_copy`
     *   "display_name": "新流程"            // 可选，默认 `{源display_name} (Copy)`
     * }
     *
     * 克隆产出的是 `version=1` 的**草稿**（不可启动），并且**不复制触发器**：
     * 复制一个 active 的 webhook 触发器指向草稿，会让外部回调直接 400 ——
     * 那是「看起来配好了、其实一调就错」的配置。
     */
    public function clone(string $id, Request $request): Response
    {
        $tenantId     = $request->tenantId();
        $definitionId = $id;
        $data         = $request->post();
        $data         = is_array($data) ? $data : [];
        $userId       = $request->userId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        return $this->respond(function () use ($tenantId, $userId, $definitionId, $data): Response {
            return $this->success(
                $this->service()->clone($tenantId, $userId, $this->parseId($definitionId), $data),
                $this->langService->trans('workflow.definition_cloned'),
                0,
                201
            );
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
            Log::error('[WORKFLOW_DEFINITION_FAILED] ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return $this->error(
                '工作流定义操作失败，请稍后重试或联系管理员 | workflow definition operation failed',
                WorkflowActionException::CODE_SERVER_ERROR,
                [],
                500
            );
        }
    }

    /**
     * 解析路径上的定义ID
     *
     * 非数字 / 非正数 → 404（路径参数不合法等价于「这条资源不存在」，
     * 不是客户端的参数格式错误 —— 那是请求体校验（422）的语义）。
     *
     * @throws WorkflowActionException
     */
    private function parseId(string $id): int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            throw WorkflowActionException::notFound($this->langService->trans('workflow.definition_not_found'));
        }

        return (int) $id;
    }

    /**
     * 应用服务（惰性构造，复用控制器的语言服务实例）
     */
    private function service(): WorkflowDefinitionService
    {
        return $this->definitionService ??= new WorkflowDefinitionService($this->langService);
    }
}
