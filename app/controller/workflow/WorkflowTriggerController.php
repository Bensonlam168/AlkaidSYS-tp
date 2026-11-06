<?php

declare(strict_types=1);

namespace app\controller\workflow;

use app\controller\ApiController;
use app\Request;
use Closure;
use Infrastructure\Workflow\Exception\WorkflowActionException;
use Infrastructure\Workflow\Service\WorkflowTriggerService;
use think\facade\Log;
use think\Response;
use Throwable;

/**
 * Workflow Trigger API Controller | 工作流触发器 API 控制器
 *
 * ## 本控制器的职责（分层约定）
 *
 * 只做四件事：**取参 → 参数校验 → 调 Service → 包统一响应**。
 * 归属校验、配置校验、引擎调用、留痕、负载映射全部在
 * `Infrastructure\Workflow\Service\WorkflowTriggerService`，
 * 数据访问在 `Infrastructure\Workflow\Repository\*`。控制器里不出现 SQL。
 *
 * Endpoints（11 个动作 + 1 个受保护的状态切换助手 `setTriggerStatus()`）：
 * - GET    /v1/workflow/triggers                - index    列出触发器
 * - GET    /v1/workflow/triggers/types          - types    获取可用触发器类型（静态元数据）
 * - GET    /v1/workflow/triggers/{id}           - read     触发器详情（含 recent_logs）
 * - POST   /v1/workflow/triggers                - save     创建触发器
 * - PUT    /v1/workflow/triggers/{id}           - update   更新触发器
 * - DELETE /v1/workflow/triggers/{id}           - delete   删除触发器
 * - POST   /v1/workflow/triggers/{id}/enable    - enable   启用触发器
 * - POST   /v1/workflow/triggers/{id}/disable   - disable  禁用触发器
 * - POST   /v1/workflow/triggers/{id}/test      - test     测试触发器
 * - GET    /v1/workflow/triggers/{id}/logs      - logs     触发器日志
 * - ANY    /v1/workflow/triggers/webhook/{key}  - webhook  外部回调入口（无 Auth）
 *
 * ## 与旧实现的关系（Mock → 真库/引擎）
 *
 * 旧版本是 `protected static array $triggers` / `$triggerLogs` 的内存 Mock，并依赖一批
 * **库里并不存在的列**（`description` / `status` / `webhook_key` / `execution_count` /
 * `last_execution_*`）。真库只有
 * `id / definition_id / tenant_id / name / type / config / is_active / created_at / updated_at / created_by`。
 *
 * | 端点 | 旧（Mock） | 新（真库 / 引擎） |
 * | ---- | ---------- | ----------------- |
 * | `index` | 内存过滤 + `usort` | `workflow_triggers` 查询 + 执行统计从事件表派生 |
 * | `types` | 静态数组 | **不变**（纯静态元数据 + 语言包，无需落库） |
 * | `read` | 内存对象 + 内存日志 | 库行 + `workflow_event_logs` 最近 10 条 |
 * | `save` | 塞数组、随机 `trig_xxx` | 插真行；`definition_id` 必须是本租户的数字主键；webhook 生成真实 key |
 * | `update` | 就地改字段 | 更新真行 + 引擎 `validateConfig()` 复校 |
 * | `delete` | `unset` | 硬删（触发器没有下游级联数据），日志保留 |
 * | `enable`/`disable` | 改 `status` 字段 | 改 `is_active`（1/0）+ 留痕 |
 * | `test` | 假 `would_trigger` | 真注册到 `TriggerManager` + 真 `shouldTrigger()`；非 dry-run 真启动实例 |
 * | `webhook` | 生成假 `inst_xxx` | 真校验（方法/IP/签名）→ `WorkflowInstanceService::start()` 真实例 |
 * | `logs` | 内存数组 | `workflow_event_logs` 真行（`event_data.trigger_id` 归属） |
 *
 * ## webhook 为什么没有 Auth
 *
 * 外部回调不可能带 JWT。鉴权靠 ① 路径里的 `webhook_key`（32 位十六进制）；
 * ② `config.secret` 的 `X-Webhook-Signature`（HMAC-SHA256 over **raw body**）；
 * ③ 可选 `allowed_ips` / `http_methods` 白名单。租户上下文来自全局 `TenantIdentify`
 * （`X-Tenant-ID` 或 `DEFAULT_TENANT_ID`）—— **绝不跨租户搜 key**。
 *
 * @package app\controller\workflow
 */
class WorkflowTriggerController extends ApiController
{
    /**
     * Trigger status constants | 触发器状态常量
     *
     * 与库里的 `is_active` 一一对应：`enabled` ⇄ `1`，`disabled` ⇄ `0`。
     */
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    /**
     * Trigger type constants | 触发器类型常量
     */
    public const TYPE_MANUAL = 'manual';
    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_SCHEDULE = 'schedule';
    public const TYPE_DATA_EVENT = 'data_event';

    /**
     * 应用服务（惰性构造）
     */
    private ?WorkflowTriggerService $triggerService = null;

    /**
     * List triggers | 列出触发器
     *
     * GET /v1/workflow/triggers
     *
     * Query Parameters:
     * - page: int（默认 1）
     * - page_size: int（默认 20，上限 100）
     * - definition_id: int（按定义筛选）
     * - type: string（manual|webhook|schedule|data_event|message_event）
     * - status: string（enabled|disabled；落库列是 `is_active`）
     * - name: string（模糊匹配）
     *
     * 每条记录都带**派生**的 `execution_count` / `last_execution_at` /
     * `last_execution_status`（来源是 `workflow_event_logs`，不是计数列）。
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
                'definition_id' => $params['definition_id'] ?? null,
                'type'          => $params['type'] ?? null,
                'status'        => $params['status'] ?? null,
                'name'          => $params['name'] ?? null,
            ], $page, $pageSize);

            return $this->paginate($result['list'], $result['total'], $page, $pageSize);
        });
    }

    /**
     * Get available trigger types | 获取可用触发器类型
     *
     * GET /v1/workflow/triggers/types
     *
     * 纯静态元数据（类型 + 配置 JSON Schema），**不读库**：
     * 这份 schema 是「前端表单怎么画」的唯一来源，与具体触发器实例无关。
     */
    public function types(Request $request): Response
    {
        $types = [
            [
                'type' => self::TYPE_MANUAL,
                'name' => $this->langService->trans('workflow.trigger_type_manual'),
                'description' => $this->langService->trans('workflow.trigger_type_manual_desc'),
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'allowed_users' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'allowed_roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'require_confirmation' => ['type' => 'boolean', 'default' => false],
                    ],
                ],
            ],
            [
                'type' => self::TYPE_WEBHOOK,
                'name' => $this->langService->trans('workflow.trigger_type_webhook'),
                'description' => $this->langService->trans('workflow.trigger_type_webhook_desc'),
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'secret' => ['type' => 'string'],
                        'allowed_ips' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'http_methods' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['POST']],
                        'payload_mapping' => ['type' => 'object'],
                    ],
                ],
            ],
            [
                'type' => self::TYPE_SCHEDULE,
                'name' => $this->langService->trans('workflow.trigger_type_schedule'),
                'description' => $this->langService->trans('workflow.trigger_type_schedule_desc'),
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'cron_expression' => ['type' => 'string'],
                        'timezone' => ['type' => 'string', 'default' => 'UTC'],
                        'start_date' => ['type' => 'string', 'format' => 'date-time'],
                        'end_date' => ['type' => 'string', 'format' => 'date-time'],
                        'max_executions' => ['type' => 'integer'],
                    ],
                    'required' => ['cron_expression'],
                ],
            ],
            [
                'type' => self::TYPE_DATA_EVENT,
                'name' => $this->langService->trans('workflow.trigger_type_data_event'),
                'description' => $this->langService->trans('workflow.trigger_type_data_event_desc'),
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'collection' => ['type' => 'string'],
                        'event_type' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                        'field_conditions' => ['type' => 'array'],
                        'filter_expression' => ['type' => 'string'],
                    ],
                    'required' => ['collection', 'event_type'],
                ],
            ],
        ];

        return $this->success([
            'types' => $types,
            'count' => count($types),
        ]);
    }

    /**
     * Get trigger details | 获取触发器详情
     *
     * GET /v1/workflow/triggers/{id}
     *
     * 额外带 `recent_logs`（最近 10 条，时间正序）—— 与旧契约字段名一致。
     */
    public function read(string $id, Request $request): Response
    {
        $tenantId  = $request->tenantId();
        $triggerId = $id;

        return $this->respond(function () use ($tenantId, $triggerId): Response {
            return $this->success($this->service()->detail($tenantId, $this->parseId($triggerId)));
        });
    }

    /**
     * Create trigger | 创建触发器
     *
     * POST /v1/workflow/triggers
     *
     * Request Body:
     * {
     *   "definition_id": 123,               // 必填，**本租户的数字主键**（旧 `wf_xxx` 字符串不再接受）
     *   "name": "报销回调",                  // 必填，租户内唯一（`uk_tenant_name`）
     *   "type": "webhook",
     *   "config": {...},                    // 引擎配置；`config._alkaid_meta` 由服务独占维护
     *   "description": "...",               // 可选，存进 `config._alkaid_meta.description`
     *   "enabled": true,                    // 可选，默认 true → `is_active = 1`
     *   "webhook_key": "..."                // 可选（仅 webhook），不合法/缺省时自动生成
     * }
     */
    public function save(Request $request): Response
    {
        // `post()` 在「请求体缺失 / 非 JSON」时返回 null，统一折成空数组再交给服务层校验
        $data     = $request->post();
        $data     = is_array($data) ? $data : [];
        $tenantId = $request->tenantId();
        $userId   = $request->userId();

        // `created_by` 带 `users.id` 外键：系统身份无法表示
        if ($userId === null) {
            return $this->unauthorized();
        }

        return $this->respond(function () use ($tenantId, $userId, $data): Response {
            return $this->success(
                $this->service()->create($tenantId, $userId, $data),
                $this->langService->trans('workflow.trigger_created'),
                0,
                201
            );
        });
    }

    /**
     * Update trigger | 更新触发器
     *
     * PUT /v1/workflow/triggers/{id}
     *
     * 注意两处真校验（旧 Mock 都没有）：
     * 1. 改名会检查本租户内唯一（并发靠 `uk_tenant_name` 兜底）；
     * 2. 改 `type` 或 `config` 都会重新跑引擎的 `validateConfig()`
     *    —— 否则把 manual 改成 schedule 而配置里没有 cron，会造出一条永不触发的触发器。
     */
    public function update(string $id, Request $request): Response
    {
        $tenantId  = $request->tenantId();
        $triggerId = $id;
        $data      = $request->put();
        $data      = is_array($data) ? $data : [];
        $userId    = $request->userId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        return $this->respond(function () use ($tenantId, $userId, $triggerId, $data): Response {
            return $this->success(
                $this->service()->update($tenantId, $userId, $this->parseId($triggerId), $data),
                $this->langService->trans('workflow.trigger_updated')
            );
        });
    }

    /**
     * Delete trigger | 删除触发器
     *
     * DELETE /v1/workflow/triggers/{id}
     *
     * 硬删：触发器没有下游级联数据（实例挂在**定义**上，不挂触发器）。
     * 历史日志保留（审计需要「这个入口曾经存在过」），只是本资源的 `logs` 端点之后会 404。
     */
    public function delete(string $id, Request $request): Response
    {
        $tenantId  = $request->tenantId();
        $triggerId = $id;
        $userId    = $request->userId();

        return $this->respond(function () use ($tenantId, $userId, $triggerId): Response {
            return $this->success(
                $this->service()->delete($tenantId, $userId, $this->parseId($triggerId)),
                $this->langService->trans('workflow.trigger_deleted')
            );
        });
    }

    /**
     * Enable trigger | 启用触发器
     *
     * POST /v1/workflow/triggers/{id}/enable
     */
    public function enable(string $id, Request $request): Response
    {
        return $this->setTriggerStatus($id, $request, true);
    }

    /**
     * Disable trigger | 禁用触发器
     *
     * POST /v1/workflow/triggers/{id}/disable
     */
    public function disable(string $id, Request $request): Response
    {
        return $this->setTriggerStatus($id, $request, false);
    }

    /**
     * Test trigger | 测试触发器
     *
     * POST /v1/workflow/triggers/{id}/test
     *
     * Request Body:
     * {
     *   "test_data": {...},
     *   "dry_run": true          // 默认 true
     * }
     *
     * `dry_run=false` 且条件满足 → **真启动一个实例**并返回真实 `instance_id`；
     * 若此时触发器是禁用的 → 400 `trigger_disabled`（不是「测试失败」，而是「它本来就不会执行」）。
     */
    public function test(string $id, Request $request): Response
    {
        $tenantId  = $request->tenantId();
        $triggerId = $id;
        $data      = $request->post();
        $data      = is_array($data) ? $data : [];
        $userId    = $request->userId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        return $this->respond(function () use ($tenantId, $userId, $triggerId, $data): Response {
            return $this->success(
                $this->service()->test($tenantId, $userId, $this->parseId($triggerId), $data)
            );
        });
    }

    /**
     * Webhook endpoint | Webhook 端点
     *
     * ANY /v1/workflow/triggers/webhook/{key}
     *
     * 失败语义（全部由 `WorkflowTriggerService::handleWebhook()` 抛出并映射）：
     * - key 在**本租户**下不存在 → 404（不泄露别租户的 key 是否存在）
     * - 触发器被禁用 → 400 `trigger_disabled`
     * - 方法不在白名单 → 405
     * - IP 不在白名单 → 403
     * - `config.secret` 已配置但签名缺失/不符 → 401
     * - 定义不可启动（非 published+active）或引擎失败 → 上游的 400/500
     */
    public function webhook(string $key, Request $request): Response
    {
        $payload = $request->post();

        if (!is_array($payload) || $payload === []) {
            $decoded = json_decode((string) $request->getContent(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $context = [
            // webhook 无 Auth：租户来自全局 TenantIdentify（X-Tenant-ID 或 DEFAULT_TENANT_ID）
            'tenant_id'  => $request->tenantId(),
            'method'     => strtoupper((string) $request->method()),
            'ip'         => (string) $request->ip(),
            'user_agent' => (string) $request->header('User-Agent', ''),
            'signature'  => (string) $request->header('X-Webhook-Signature', ''),
            'raw_body'   => (string) $request->getContent(),
        ];

        return $this->respond(function () use ($key, $context, $payload): Response {
            return $this->success(
                $this->service()->handleWebhook($key, $context, $payload),
                $this->langService->trans('workflow.webhook_processed')
            );
        });
    }

    /**
     * Get trigger logs | 获取触发器日志
     *
     * GET /v1/workflow/triggers/{id}/logs
     *
     * Query Parameters:
     * - page: int（默认 1）
     * - page_size: int（默认 50，上限 100）
     * - event_type: string（可选，收窄到某一类）
     *
     * 数据来源是 `workflow_event_logs`（见 `WorkflowEventLogRepository::TRIGGER_EVENT_TYPES`）。
     * 每条同时给出：
     * - `event`：旧契约的大写词汇（`CREATED` / `UPDATED` / `ENABLED` / `DISABLED` /
     *   `TESTED` / `EXECUTED` / `FAILED`）；
     * - `event_type`：库里真实值（`trigger_created` / …）；
     * - `instance_id`：执行类事件是真实实例ID，管理类事件是 `null`。
     */
    public function logs(string $id, Request $request): Response
    {
        $tenantId  = $request->tenantId();
        $triggerId = $id;
        $params    = $request->get();
        $page      = (int) ($params['page'] ?? 1);
        $pageSize  = (int) ($params['page_size'] ?? 50);

        // 日志端点的默认页大小是 50（旧契约），上限沿用全局的 100
        if ($pageSize > 100) {
            $pageSize = 100;
        }

        $pagination = $this->validatePagination($page, $pageSize);

        if (!$pagination['valid']) {
            return $this->validationError($pagination['errors']);
        }

        $eventType = isset($params['event_type']) && is_scalar($params['event_type']) && (string) $params['event_type'] !== ''
            ? (string) $params['event_type']
            : null;

        return $this->respond(function () use ($tenantId, $triggerId, $page, $pageSize, $eventType): Response {
            $result = $this->service()->logs(
                $tenantId,
                $this->parseId($triggerId),
                $page,
                $pageSize,
                $eventType
            );

            return $this->paginate($result['list'], $result['total'], $page, $pageSize);
        });
    }

    /**
     * Set trigger status | 设置触发器状态（enable / disable 的共用实现）
     *
     * 保留为受保护方法（而非各自内联）：旧实现里两个动作共享同一段状态改写 + 留痕逻辑，
     * 这个结构值得保留 —— 单点修改才不会出现「启用走了 A 路径、禁用走了 B 路径」的分叉。
     */
    protected function setTriggerStatus(string $id, Request $request, bool $enabled): Response
    {
        $tenantId  = $request->tenantId();
        $triggerId = $id;
        $userId    = $request->userId();

        if ($userId === null) {
            return $this->unauthorized();
        }

        return $this->respond(function () use ($tenantId, $userId, $triggerId, $enabled): Response {
            $row = $this->service()->setEnabled($tenantId, $userId, $this->parseId($triggerId), $enabled);

            $message = $enabled
                ? $this->langService->trans('workflow.trigger_enabled')
                : $this->langService->trans('workflow.trigger_disabled');

            return $this->success($row, $message);
        });
    }

    // =====================================================================
    // 内部
    // =====================================================================

    /**
     * 统一的「业务异常 → 响应」出口
     *
     * 1. `WorkflowActionException` 带自己的业务码 / HTTP 状态 / 字段级错误；
     * 2. 其余 `Throwable` 一律 500 + 通用文案（原始异常只进日志）。
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
            Log::error('[WORKFLOW_TRIGGER_FAILED] ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return $this->error(
                '工作流触发器操作失败，请稍后重试或联系管理员 | workflow trigger operation failed',
                WorkflowActionException::CODE_SERVER_ERROR,
                [],
                500
            );
        }
    }

    /**
     * 解析路径上的触发器ID
     *
     * 非数字 / 非正数 → 404（路径参数不合法等价于「这条资源不存在」）。
     *
     * @throws WorkflowActionException
     */
    private function parseId(string $id): int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            throw WorkflowActionException::notFound($this->langService->trans('workflow.trigger_not_found'));
        }

        return (int) $id;
    }

    /**
     * 应用服务（惰性构造，复用控制器的语言服务实例）
     */
    private function service(): WorkflowTriggerService
    {
        return $this->triggerService ??= new WorkflowTriggerService($this->langService);
    }
}
