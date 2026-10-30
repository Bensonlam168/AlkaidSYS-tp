<?php

declare(strict_types=1);

namespace app\controller\workflow;

use app\controller\ApiController;
use think\App;
use think\Request;
use think\Response;

/**
 * Workflow Instance API Controller | 工作流实例 API 控制器
 *
 * RESTful API for workflow instance management.
 * 工作流实例管理的 RESTful API。
 *
 * Endpoints:
 * - GET    /workflow/instances              - List instances | 列出实例
 * - GET    /workflow/instances/{id}         - Get instance | 获取实例
 * - POST   /workflow/instances              - Start instance | 启动实例
 * - POST   /workflow/instances/{id}/cancel  - Cancel instance | 取消实例
 * - POST   /workflow/instances/{id}/suspend - Suspend instance | 暂停实例
 * - POST   /workflow/instances/{id}/resume  - Resume instance | 恢复实例
 * - GET    /workflow/instances/{id}/history - Get execution history | 获取执行历史
 * - GET    /workflow/instances/{id}/variables - Get variables | 获取变量
 * - PUT    /workflow/instances/{id}/variables - Update variables | 更新变量
 *
 * @package app\controller\workflow
 */
class WorkflowInstanceController extends ApiController
{
    /**
     * Instance status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * In-memory storage for demo (replace with repository in production)
     *
     * @var array
     */
    protected static array $instances = [];

    /**
     * Instance history storage
     *
     * @var array
     */
    protected static array $history = [];

    /**
     * List workflow instances | 列出工作流实例
     *
     * GET /workflow/instances
     *
     * Query Parameters:
     * - page: int (default: 1)
     * - page_size: int (default: 20)
     * - definition_id: string (filter by definition)
     * - status: string (pending|running|suspended|completed|failed|cancelled)
     * - started_by: string (filter by starter)
     * - date_from: string (ISO 8601 date)
     * - date_to: string (ISO 8601 date)
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $params = $request->get();
        $page = (int)($params['page'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 20);

        // Validate pagination
        $paginationValidation = $this->validatePagination($page, $pageSize);
        if (!$paginationValidation['valid']) {
            return $this->validationError($paginationValidation['errors']);
        }

        // Get tenant ID
        $tenantId = $request->tenantId();

        // Filter instances
        $allInstances = array_values(self::$instances);

        // Apply tenant filter
        // 缺陷 P3-A：租户过滤必须严格匹配。DB 层 `workflow_instances.tenant_id` 是 NOT NULL，
        // 不存在「全局实例」，原实现的 `|| (... ?? null) === null` 是死分支，
        // 却会让「缺少 tenant_id 的脏数据」对所有租户可见（fail-open）。
        // 注：`app\Request::tenantId()` 返回 int（恒 >= 1），故不再需要 null 包裹判断。
        $allInstances = array_filter($allInstances, fn($inst) => ($inst['tenant_id'] ?? null) === $tenantId);

        // Apply status filter
        if (!empty($params['status'])) {
            $allInstances = array_filter($allInstances, fn($inst) =>
                $inst['status'] === $params['status']
            );
        }

        // Apply definition filter
        if (!empty($params['definition_id'])) {
            $allInstances = array_filter($allInstances, fn($inst) =>
                $inst['definition_id'] === $params['definition_id']
            );
        }

        // Apply started_by filter
        if (!empty($params['started_by'])) {
            $allInstances = array_filter($allInstances, fn($inst) =>
                $inst['started_by'] === $params['started_by']
            );
        }

        // Apply date filters
        if (!empty($params['date_from'])) {
            $dateFrom = strtotime($params['date_from']);
            $allInstances = array_filter($allInstances, fn($inst) =>
                strtotime($inst['started_at']) >= $dateFrom
            );
        }
        if (!empty($params['date_to'])) {
            $dateTo = strtotime($params['date_to']);
            $allInstances = array_filter($allInstances, fn($inst) =>
                strtotime($inst['started_at']) <= $dateTo
            );
        }

        // Re-index and sort by started_at desc
        $allInstances = array_values($allInstances);
        usort($allInstances, fn($a, $b) => strtotime($b['started_at']) - strtotime($a['started_at']));

        $total = count($allInstances);
        $offset = ($page - 1) * $pageSize;
        $list = array_slice($allInstances, $offset, $pageSize);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * Get workflow instance details | 获取工作流实例详情
     *
     * GET /workflow/instances/{id}
     *
     * @param string $id Instance ID
     * @param Request $request
     * @return Response
     */
    public function read(string $id, Request $request): Response
    {
        $instance = self::$instances[$id] ?? null;

        if ($instance === null) {
            return $this->notFound($this->langService->trans('workflow.instance_not_found'));
        }

        // Check tenant access | 租户归属校验（缺陷 R13：缺失 tenant_id 一律拒绝，不再对 null 宽免）
        $tenantId = $request->tenantId();
        $tenantError = $this->assertTenantOwnership($instance['tenant_id'] ?? null, $tenantId);
        if ($tenantError !== null) {
            return $tenantError;
        }

        return $this->success($instance);
    }

    /**
     * Start workflow instance | 启动工作流实例
     *
     * POST /workflow/instances
     *
     * Request Body:
     * {
     *   "definition_id": "wf_xxx",
     *   "variables": {...},
     *   "trigger_type": "manual|webhook|schedule|data_event",
     *   "trigger_data": {...}
     * }
     *
     * @param Request $request
     * @return Response
     */
    public function save(Request $request): Response
    {
        $data = $request->post();

        // Validate required fields
        if (empty($data['definition_id'])) {
            return $this->validationError(['definition_id' => $this->langService->trans('workflow.definition_id_required')]);
        }

        // Get tenant ID and user info
        $tenantId = $request->tenantId();
        $userId = $request->userId() ?? 'system';

        // Generate instance ID
        $instanceId = 'inst_' . uniqid();

        // Create instance
        $instance = [
            'id' => $instanceId,
            'definition_id' => $data['definition_id'],
            'definition_version' => $data['definition_version'] ?? 1,
            'status' => self::STATUS_RUNNING,
            'current_node_id' => null,
            'variables' => $data['variables'] ?? [],
            'context' => [],
            'trigger_type' => $data['trigger_type'] ?? 'manual',
            'trigger_data' => $data['trigger_data'] ?? [],
            'started_by' => $userId,
            'started_at' => (new \DateTimeImmutable())->format('c'),
            'completed_at' => null,
            'tenant_id' => $tenantId,
            'error_message' => null,
        ];

        // Store instance
        self::$instances[$instanceId] = $instance;

        // Add history entry
        $this->addHistoryEntry($instanceId, 'STARTED', null, 'Instance started', $userId);

        return $this->success($instance, $this->langService->trans('workflow.instance_started'), 0, 201);
    }

    /**
     * Cancel workflow instance | 取消工作流实例
     *
     * POST /workflow/instances/{id}/cancel
     *
     * Request Body:
     * {
     *   "reason": "Cancellation reason"
     * }
     *
     * @param string $id Instance ID
     * @param Request $request
     * @return Response
     */
    public function cancel(string $id, Request $request): Response
    {
        $instance = self::$instances[$id] ?? null;

        if ($instance === null) {
            return $this->notFound($this->langService->trans('workflow.instance_not_found'));
        }

        // Check tenant access | 租户归属校验（缺陷 R13：缺失 tenant_id 一律拒绝，不再对 null 宽免）
        $tenantId = $request->tenantId();
        $tenantError = $this->assertTenantOwnership($instance['tenant_id'] ?? null, $tenantId);
        if ($tenantError !== null) {
            return $tenantError;
        }

        // Check if cancellable
        if (!in_array($instance['status'], [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_SUSPENDED], true)) {
            return $this->error(
                $this->langService->trans('workflow.cannot_cancel_instance'),
                400,
                ['current_status' => $instance['status']],
                400
            );
        }

        $data = $request->post();
        $reason = $data['reason'] ?? 'No reason provided';
        $userId = $request->userId() ?? 'system';

        // Update instance
        $instance['status'] = self::STATUS_CANCELLED;
        $instance['completed_at'] = (new \DateTimeImmutable())->format('c');
        $instance['error_message'] = $reason;
        self::$instances[$id] = $instance;

        // Add history entry
        $this->addHistoryEntry($id, 'CANCELLED', $instance['current_node_id'], $reason, $userId);

        return $this->success($instance, $this->langService->trans('workflow.instance_cancelled'));
    }

    /**
     * Suspend workflow instance | 暂停工作流实例
     *
     * POST /workflow/instances/{id}/suspend
     *
     * @param string $id Instance ID
     * @param Request $request
     * @return Response
     */
    public function suspend(string $id, Request $request): Response
    {
        $instance = self::$instances[$id] ?? null;

        if ($instance === null) {
            return $this->notFound($this->langService->trans('workflow.instance_not_found'));
        }

        // Check tenant access | 租户归属校验（缺陷 R13：缺失 tenant_id 一律拒绝，不再对 null 宽免）
        $tenantId = $request->tenantId();
        $tenantError = $this->assertTenantOwnership($instance['tenant_id'] ?? null, $tenantId);
        if ($tenantError !== null) {
            return $tenantError;
        }

        // Can only suspend running instances
        if ($instance['status'] !== self::STATUS_RUNNING) {
            return $this->error(
                $this->langService->trans('workflow.cannot_suspend_instance'),
                400,
                ['current_status' => $instance['status']],
                400
            );
        }

        $userId = $request->userId() ?? 'system';

        // Update instance
        $instance['status'] = self::STATUS_SUSPENDED;
        self::$instances[$id] = $instance;

        // Add history entry
        $this->addHistoryEntry($id, 'SUSPENDED', $instance['current_node_id'], 'Instance suspended', $userId);

        return $this->success($instance, $this->langService->trans('workflow.instance_suspended'));
    }

    /**
     * Resume workflow instance | 恢复工作流实例
     *
     * POST /workflow/instances/{id}/resume
     *
     * @param string $id Instance ID
     * @param Request $request
     * @return Response
     */
    public function resume(string $id, Request $request): Response
    {
        $instance = self::$instances[$id] ?? null;

        if ($instance === null) {
            return $this->notFound($this->langService->trans('workflow.instance_not_found'));
        }

        // Check tenant access | 租户归属校验（缺陷 R13：缺失 tenant_id 一律拒绝，不再对 null 宽免）
        $tenantId = $request->tenantId();
        $tenantError = $this->assertTenantOwnership($instance['tenant_id'] ?? null, $tenantId);
        if ($tenantError !== null) {
            return $tenantError;
        }

        // Can only resume suspended instances
        if ($instance['status'] !== self::STATUS_SUSPENDED) {
            return $this->error(
                $this->langService->trans('workflow.cannot_resume_instance'),
                400,
                ['current_status' => $instance['status']],
                400
            );
        }

        $userId = $request->userId() ?? 'system';

        // Update instance
        $instance['status'] = self::STATUS_RUNNING;
        self::$instances[$id] = $instance;

        // Add history entry
        $this->addHistoryEntry($id, 'RESUMED', $instance['current_node_id'], 'Instance resumed', $userId);

        return $this->success($instance, $this->langService->trans('workflow.instance_resumed'));
    }

    /**
     * Get instance execution history | 获取实例执行历史
     *
     * GET /workflow/instances/{id}/history
     *
     * @param string $id Instance ID
     * @param Request $request
     * @return Response
     */
    public function history(string $id, Request $request): Response
    {
        $instance = self::$instances[$id] ?? null;

        if ($instance === null) {
            return $this->notFound($this->langService->trans('workflow.instance_not_found'));
        }

        // Check tenant access | 租户归属校验（缺陷 R13：缺失 tenant_id 一律拒绝，不再对 null 宽免）
        $tenantId = $request->tenantId();
        $tenantError = $this->assertTenantOwnership($instance['tenant_id'] ?? null, $tenantId);
        if ($tenantError !== null) {
            return $tenantError;
        }

        $historyEntries = self::$history[$id] ?? [];

        return $this->success([
            'instance_id' => $id,
            'entries' => $historyEntries,
            'count' => count($historyEntries),
        ]);
    }

    /**
     * Get instance variables | 获取实例变量
     *
     * GET /workflow/instances/{id}/variables
     *
     * @param string $id Instance ID
     * @param Request $request
     * @return Response
     */
    public function getVariables(string $id, Request $request): Response
    {
        $instance = self::$instances[$id] ?? null;

        if ($instance === null) {
            return $this->notFound($this->langService->trans('workflow.instance_not_found'));
        }

        // Check tenant access | 租户归属校验（缺陷 R13：缺失 tenant_id 一律拒绝，不再对 null 宽免）
        $tenantId = $request->tenantId();
        $tenantError = $this->assertTenantOwnership($instance['tenant_id'] ?? null, $tenantId);
        if ($tenantError !== null) {
            return $tenantError;
        }

        return $this->success([
            'instance_id' => $id,
            'variables' => $instance['variables'] ?? [],
        ]);
    }

    /**
     * Update instance variables | 更新实例变量
     *
     * PUT /workflow/instances/{id}/variables
     *
     * Request Body:
     * {
     *   "variables": {
     *     "key1": "value1",
     *     "key2": "value2"
     *   },
     *   "merge": true  // If true, merge with existing; if false, replace
     * }
     *
     * @param string $id Instance ID
     * @param Request $request
     * @return Response
     */
    public function updateVariables(string $id, Request $request): Response
    {
        $instance = self::$instances[$id] ?? null;

        if ($instance === null) {
            return $this->notFound($this->langService->trans('workflow.instance_not_found'));
        }

        // Check tenant access | 租户归属校验（缺陷 R13：缺失 tenant_id 一律拒绝，不再对 null 宽免）
        $tenantId = $request->tenantId();
        $tenantError = $this->assertTenantOwnership($instance['tenant_id'] ?? null, $tenantId);
        if ($tenantError !== null) {
            return $tenantError;
        }

        // Cannot modify completed/failed/cancelled instances
        if (in_array($instance['status'], [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            return $this->error(
                $this->langService->trans('workflow.cannot_modify_instance'),
                400,
                ['current_status' => $instance['status']],
                400
            );
        }

        $data = $request->put();

        if (!isset($data['variables']) || !is_array($data['variables'])) {
            return $this->validationError(['variables' => $this->langService->trans('workflow.variables_required')]);
        }

        $merge = $data['merge'] ?? true;

        if ($merge) {
            $instance['variables'] = array_merge($instance['variables'] ?? [], $data['variables']);
        } else {
            $instance['variables'] = $data['variables'];
        }

        self::$instances[$id] = $instance;

        $userId = $request->userId() ?? 'system';
        $this->addHistoryEntry($id, 'VARIABLES_UPDATED', null, 'Variables updated', $userId);

        return $this->success([
            'instance_id' => $id,
            'variables' => $instance['variables'],
        ], $this->langService->trans('workflow.variables_updated'));
    }

    /**
     * Add history entry | 添加历史记录
     *
     * @param string $instanceId
     * @param string $eventType
     * @param string|null $nodeId
     * @param string $description
     * @param int|string $userId 操作用户ID（来自 JWT 时为 int，系统/未认证操作时为 'system'）
     * @return void
     */
    protected function addHistoryEntry(
        string $instanceId,
        string $eventType,
        ?string $nodeId,
        string $description,
        int|string $userId
    ): void {
        if (!isset(self::$history[$instanceId])) {
            self::$history[$instanceId] = [];
        }

        self::$history[$instanceId][] = [
            'id' => 'hist_' . uniqid(),
            'instance_id' => $instanceId,
            'event_type' => $eventType,
            'node_id' => $nodeId,
            'description' => $description,
            'user_id' => $userId,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];
    }
}
