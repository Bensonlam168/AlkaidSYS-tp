<?php

declare(strict_types=1);

namespace app\controller\workflow;

use app\controller\ApiController;
use think\App;
use think\facade\Db;
use think\Request;
use think\Response;

/**
 * Workflow Task API Controller | 工作流任务 API 控制器
 *
 * RESTful API for workflow task management.
 * 工作流任务管理的 RESTful API。
 *
 * Endpoints:
 * - GET    /workflow/tasks                    - List tasks | 列出任务
 * - GET    /workflow/tasks/pending            - Get pending tasks for current user | 获取当前用户待办任务
 * - GET    /workflow/tasks/{id}               - Get task details | 获取任务详情
 * - POST   /workflow/tasks/{id}/approve       - Approve task | 审批通过
 * - POST   /workflow/tasks/{id}/reject        - Reject task | 审批拒绝
 * - POST   /workflow/tasks/{id}/transfer      - Transfer task | 转办任务
 * - POST   /workflow/tasks/{id}/delegate      - Delegate task | 委托任务
 * - POST   /workflow/tasks/{id}/claim         - Claim task | 认领任务
 * - POST   /workflow/tasks/{id}/unclaim       - Unclaim task | 取消认领
 * - POST   /workflow/tasks/{id}/complete      - Complete task | 完成任务
 * - GET    /workflow/tasks/{id}/form          - Get task form | 获取任务表单
 * - POST   /workflow/tasks/{id}/form          - Submit task form | 提交任务表单
 *
 * @package app\controller\workflow
 */
class WorkflowTaskController extends ApiController
{
    /**
     * Task status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_TRANSFERRED = 'transferred';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Task type constants
     */
    public const TYPE_USER = 'user';
    public const TYPE_SERVICE = 'service';
    public const TYPE_SCRIPT = 'script';
    public const TYPE_APPROVAL = 'approval';
    public const TYPE_COUNTERSIGN = 'countersign';

    /**
     * In-memory storage for demo
     *
     * @var array
     */
    protected static array $tasks = [];

    /**
     * Task action history storage
     *
     * @var array
     */
    protected static array $taskHistory = [];

    /**
     * List workflow tasks | 列出工作流任务
     *
     * GET /workflow/tasks
     *
     * Query Parameters:
     * - page: int
     * - page_size: int
     * - instance_id: string
     * - status: string
     * - type: string
     * - assignee: string
     * - candidate_users: string (comma-separated)
     * - candidate_groups: string (comma-separated)
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

        // Filter tasks
        $allTasks = array_values(self::$tasks);

        // Apply tenant filter
        // 缺陷 P3-A：租户过滤必须严格匹配。DB 层 `workflow_tasks.tenant_id` 是 NOT NULL，
        // 不存在「全局任务」，原实现的 `|| (... ?? null) === null` 是永不成立的死分支，
        // 却会让「缺少 tenant_id 的脏数据」对所有租户可见（fail-open）。
        // 同时 `app\Request::tenantId()` 的返回类型是 int（恒 >= 1），原先的
        // `if ($tenantId !== null)` 包裹同为死条件；去掉后即便将来返回 null，
        // 结果也只是「过滤掉全部记录」（fail-closed），而不是跳过过滤。
        $allTasks = array_filter($allTasks, fn($task) => ($task['tenant_id'] ?? null) === $tenantId);

        // Apply status filter
        if (!empty($params['status'])) {
            $allTasks = array_filter($allTasks, fn($task) => $task['status'] === $params['status']);
        }

        // Apply instance filter
        if (!empty($params['instance_id'])) {
            $allTasks = array_filter($allTasks, fn($task) => $task['instance_id'] === $params['instance_id']);
        }

        // Apply type filter
        if (!empty($params['type'])) {
            $allTasks = array_filter($allTasks, fn($task) => $task['type'] === $params['type']);
        }

        // Apply assignee filter
        if (!empty($params['assignee'])) {
            $allTasks = array_filter($allTasks, fn($task) => $task['assignee'] === $params['assignee']);
        }

        // Re-index and sort by created_at desc
        $allTasks = array_values($allTasks);
        usort($allTasks, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

        $total = count($allTasks);
        $offset = ($page - 1) * $pageSize;
        $list = array_slice($allTasks, $offset, $pageSize);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * Get pending tasks for current user | 获取当前用户待办任务
     *
     * GET /workflow/tasks/pending
     *
     * @param Request $request
     * @return Response
     */
    public function pending(Request $request): Response
    {
        $params = $request->get();
        $page = (int)($params['page'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 20);

        // Validate pagination
        $paginationValidation = $this->validatePagination($page, $pageSize);
        if (!$paginationValidation['valid']) {
            return $this->validationError($paginationValidation['errors']);
        }

        // Get current user and tenant
        $userId = $request->userId();
        $tenantId = $request->tenantId();
        // 说明（缺陷 R1）：历史代码用属性方式读取 userGroups，会落到 think\Request::__get()
        // → middleware('userGroups')，恒为 null；必须调用 app\Request::userGroups()，
        // 才能拿到当前用户在当前租户下的角色 slug 列表。
        $userGroups = $request->userGroups();

        if ($userId === null) {
            return $this->unauthorized();
        }

        // Filter pending tasks for current user
        $pendingTasks = array_filter(self::$tasks, function ($task) use ($userId, $tenantId, $userGroups) {
            // Check tenant | 租户归属校验（缺陷 R13）：缺失 tenant_id 的任务对任何租户都不可见
            // 过滤器内只关心「是否归属当前租户」，故以 helper 是否返回 null 作布尔短路
            if ($this->assertTenantOwnership($task['tenant_id'] ?? null, $tenantId) !== null) {
                return false;
            }

            // Must be pending or claimed by this user
            if (!in_array($task['status'], [self::STATUS_PENDING, self::STATUS_CLAIMED], true)) {
                return false;
            }

            // Check if user is assignee
            if ($task['assignee'] === $userId) {
                return true;
            }

            // Check if user is in candidate users
            if (in_array($userId, $task['candidate_users'] ?? [], true)) {
                return true;
            }

            // Check if user's groups intersect with candidate groups
            $candidateGroups = $task['candidate_groups'] ?? [];
            if (!empty(array_intersect($userGroups, $candidateGroups))) {
                return true;
            }

            return false;
        });

        // Sort by due date (urgent first) then by created_at
        $pendingTasks = array_values($pendingTasks);
        usort($pendingTasks, function ($a, $b) {
            // Priority by due date
            $aDue = $a['due_date'] ?? '9999-12-31';
            $bDue = $b['due_date'] ?? '9999-12-31';
            if ($aDue !== $bDue) {
                return strcmp($aDue, $bDue);
            }
            // Then by created_at
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        $total = count($pendingTasks);
        $offset = ($page - 1) * $pageSize;
        $list = array_slice($pendingTasks, $offset, $pageSize);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * Get task details | 获取任务详情
     *
     * GET /workflow/tasks/{id}
     *
     * @param string $id Task ID
     * @param Request $request
     * @return Response
     */
    public function read(string $id, Request $request): Response
    {
        $task = self::$tasks[$id] ?? null;

        if ($task === null) {
            return $this->notFound($this->langService->trans('workflow.task_not_found'));
        }

        // Check tenant access | 租户归属校验（缺陷 R13：缺失 tenant_id 一律拒绝，不再对 null 宽免）
        $tenantId = $request->tenantId();
        $tenantError = $this->assertTenantOwnership($task['tenant_id'] ?? null, $tenantId, $this->langService->trans('workflow.access_denied'));
        if ($tenantError !== null) {
            return $tenantError;
        }

        // Include history
        $task['history'] = self::$taskHistory[$id] ?? [];

        return $this->success($task);
    }

    /**
     * Approve task | 审批通过
     *
     * POST /workflow/tasks/{id}/approve
     *
     * Request Body:
     * {
     *   "comment": "Approval comment",
     *   "form_data": {...}
     * }
     *
     * @param string $id Task ID
     * @param Request $request
     * @return Response
     */
    public function approve(string $id, Request $request): Response
    {
        return $this->processApproval($id, $request, true);
    }

    /**
     * Reject task | 审批拒绝
     *
     * POST /workflow/tasks/{id}/reject
     *
     * Request Body:
     * {
     *   "comment": "Rejection reason",
     *   "form_data": {...}
     * }
     *
     * @param string $id Task ID
     * @param Request $request
     * @return Response
     */
    public function reject(string $id, Request $request): Response
    {
        return $this->processApproval($id, $request, false);
    }

    /**
     * Transfer task | 转办任务
     *
     * POST /workflow/tasks/{id}/transfer
     *
     * Request Body:
     * {
     *   "target_user": "user_id",
     *   "comment": "Transfer reason"
     * }
     *
     * @param string $id Task ID
     * @param Request $request
     * @return Response
     */
    public function transfer(string $id, Request $request): Response
    {
        $task = self::$tasks[$id] ?? null;

        if ($task === null) {
            return $this->notFound($this->langService->trans('workflow.task_not_found'));
        }

        // Check access
        $result = $this->checkTaskAccess($task, $request);
        if ($result !== null) {
            return $result;
        }

        // Validate status
        if (!in_array($task['status'], [self::STATUS_PENDING, self::STATUS_CLAIMED], true)) {
            return $this->error(
                $this->langService->trans('workflow.cannot_transfer_task'),
                400,
                ['current_status' => $task['status']],
                400
            );
        }

        $data = $request->post();

        if (empty($data['target_user'])) {
            return $this->validationError(['target_user' => $this->langService->trans('workflow.target_user_required')]);
        }

        // 缺陷 P2：赋值前必须校验目标用户。
        // 原实现把 `$data['target_user']` 原样写入 `assignee`，而 DB 层
        // `workflow_tasks.assignee` 是 int unsigned 且外键指向 `users.id`，
        // 非数字 → 1366、用户不存在 → 1452，把「用户输入错误」伪装成 500 系统故障。
        [$targetUserId, $targetUserError] = $this->resolveTargetUser($data['target_user'], $request->tenantId());
        if ($targetUserId === null) {
            return $this->validationError(['target_user' => $targetUserError]);
        }

        $userId = $request->userId() ?? 'system';
        $originalAssignee = $task['assignee'];

        // Update task
        $task['assignee'] = $targetUserId;
        $task['status'] = self::STATUS_PENDING; // Reset to pending for new assignee
        $task['updated_at'] = (new \DateTimeImmutable())->format('c');
        self::$tasks[$id] = $task;

        // Add history
        $this->addTaskHistory($id, 'TRANSFERRED', $userId, [
            'from_user' => $originalAssignee,
            'to_user' => $targetUserId,
            'comment' => $data['comment'] ?? null,
        ]);

        return $this->success($task, $this->langService->trans('workflow.task_transferred'));
    }

    /**
     * Delegate task | 委托任务
     *
     * POST /workflow/tasks/{id}/delegate
     *
     * Request Body:
     * {
     *   "delegate_to": "user_id",
     *   "comment": "Delegation reason"
     * }
     *
     * @param string $id Task ID
     * @param Request $request
     * @return Response
     */
    public function delegate(string $id, Request $request): Response
    {
        $task = self::$tasks[$id] ?? null;

        if ($task === null) {
            return $this->notFound($this->langService->trans('workflow.task_not_found'));
        }

        // Check access
        $result = $this->checkTaskAccess($task, $request);
        if ($result !== null) {
            return $result;
        }

        // Validate status
        if (!in_array($task['status'], [self::STATUS_PENDING, self::STATUS_CLAIMED], true)) {
            return $this->error(
                $this->langService->trans('workflow.cannot_delegate_task'),
                400,
                ['current_status' => $task['status']],
                400
            );
        }

        $data = $request->post();

        if (empty($data['delegate_to'])) {
            return $this->validationError(['delegate_to' => $this->langService->trans('workflow.delegate_user_required')]);
        }

        // 缺陷 P2：与 transfer 同理。`delegate_to` 落到 `delegated_to`（同样是对用户 ID 的引用），
        // 不做校验的话，任何字符串都会被写入，后续按用户 ID 匹配时会静默失效。
        [$delegateToUserId, $delegateToError] = $this->resolveTargetUser($data['delegate_to'], $request->tenantId());
        if ($delegateToUserId === null) {
            return $this->validationError(['delegate_to' => $delegateToError]);
        }

        $userId = $request->userId() ?? 'system';

        // Update task - keep original owner but set delegate
        $task['delegated_to'] = $delegateToUserId;
        $task['delegated_by'] = $userId;
        $task['delegated_at'] = (new \DateTimeImmutable())->format('c');
        $task['updated_at'] = (new \DateTimeImmutable())->format('c');
        self::$tasks[$id] = $task;

        // Add history
        $this->addTaskHistory($id, 'DELEGATED', $userId, [
            'delegate_to' => $delegateToUserId,
            'comment' => $data['comment'] ?? null,
        ]);

        return $this->success($task, $this->langService->trans('workflow.task_delegated'));
    }

    /**
     * Claim task | 认领任务
     *
     * POST /workflow/tasks/{id}/claim
     *
     * @param string $id Task ID
     * @param Request $request
     * @return Response
     */
    public function claim(string $id, Request $request): Response
    {
        $task = self::$tasks[$id] ?? null;

        if ($task === null) {
            return $this->notFound($this->langService->trans('workflow.task_not_found'));
        }

        // Check tenant access | 租户归属校验（缺陷 R13：缺失 tenant_id 一律拒绝，不再对 null 宽免）
        $tenantId = $request->tenantId();
        $tenantError = $this->assertTenantOwnership($task['tenant_id'] ?? null, $tenantId, $this->langService->trans('workflow.access_denied'));
        if ($tenantError !== null) {
            return $tenantError;
        }

        // Can only claim pending tasks
        if ($task['status'] !== self::STATUS_PENDING) {
            return $this->error(
                $this->langService->trans('workflow.cannot_claim_task'),
                400,
                ['current_status' => $task['status']],
                400
            );
        }

        // Check if already assigned
        if (!empty($task['assignee'])) {
            return $this->error(
                $this->langService->trans('workflow.task_already_assigned'),
                400,
                ['assignee' => $task['assignee']],
                400
            );
        }

        $userId = $request->userId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        // Check if user is eligible to claim (in candidate users/groups)
        // 说明（缺陷 R1）：必须调用 app\Request::userGroups()，属性访问恒为 null
        $userGroups = $request->userGroups();
        $candidateUsers = $task['candidate_users'] ?? [];
        $candidateGroups = $task['candidate_groups'] ?? [];

        $canClaim = in_array($userId, $candidateUsers, true) ||
                    !empty(array_intersect($userGroups, $candidateGroups));

        // 安全（缺陷 R1）：只要任务设置了**任一**候选约束（候选用户 或 候选组），
        // 当前用户不满足就必须拒绝。原实现要求「候选用户与候选组同时非空」才拦截，
        // 导致只设其一时守卫被跳过、任何登录用户都能认领（fail-open）。
        $hasCandidacy = !empty($candidateUsers) || !empty($candidateGroups);
        if (!$canClaim && $hasCandidacy) {
            return $this->forbidden($this->langService->trans('workflow.not_eligible_to_claim'));
        }

        // Claim the task
        $task['assignee'] = $userId;
        $task['status'] = self::STATUS_CLAIMED;
        $task['claimed_at'] = (new \DateTimeImmutable())->format('c');
        $task['updated_at'] = (new \DateTimeImmutable())->format('c');
        self::$tasks[$id] = $task;

        // Add history
        $this->addTaskHistory($id, 'CLAIMED', $userId, []);

        return $this->success($task, $this->langService->trans('workflow.task_claimed'));
    }

    /**
     * Unclaim task | 取消认领
     *
     * POST /workflow/tasks/{id}/unclaim
     *
     * @param string $id Task ID
     * @param Request $request
     * @return Response
     */
    public function unclaim(string $id, Request $request): Response
    {
        $task = self::$tasks[$id] ?? null;

        if ($task === null) {
            return $this->notFound($this->langService->trans('workflow.task_not_found'));
        }

        // Check access
        $result = $this->checkTaskAccess($task, $request);
        if ($result !== null) {
            return $result;
        }

        // Can only unclaim claimed tasks
        if ($task['status'] !== self::STATUS_CLAIMED) {
            return $this->error(
                $this->langService->trans('workflow.cannot_unclaim_task'),
                400,
                ['current_status' => $task['status']],
                400
            );
        }

        $userId = $request->userId() ?? 'system';

        // Unclaim the task
        $task['assignee'] = null;
        $task['status'] = self::STATUS_PENDING;
        $task['claimed_at'] = null;
        $task['updated_at'] = (new \DateTimeImmutable())->format('c');
        self::$tasks[$id] = $task;

        // Add history
        $this->addTaskHistory($id, 'UNCLAIMED', $userId, []);

        return $this->success($task, $this->langService->trans('workflow.task_unclaimed'));
    }

    /**
     * Complete task | 完成任务
     *
     * POST /workflow/tasks/{id}/complete
     *
     * Request Body:
     * {
     *   "output": {...},
     *   "comment": "Completion comment"
     * }
     *
     * @param string $id Task ID
     * @param Request $request
     * @return Response
     */
    public function complete(string $id, Request $request): Response
    {
        $task = self::$tasks[$id] ?? null;

        if ($task === null) {
            return $this->notFound($this->langService->trans('workflow.task_not_found'));
        }

        // Check access
        $result = $this->checkTaskAccess($task, $request);
        if ($result !== null) {
            return $result;
        }

        // Validate status
        if (!in_array($task['status'], [self::STATUS_PENDING, self::STATUS_CLAIMED], true)) {
            return $this->error(
                $this->langService->trans('workflow.cannot_complete_task'),
                400,
                ['current_status' => $task['status']],
                400
            );
        }

        $data = $request->post();
        $userId = $request->userId() ?? 'system';

        // Complete the task
        $task['status'] = self::STATUS_COMPLETED;
        $task['output'] = $data['output'] ?? [];
        $task['completed_at'] = (new \DateTimeImmutable())->format('c');
        $task['completed_by'] = $userId;
        $task['updated_at'] = (new \DateTimeImmutable())->format('c');
        self::$tasks[$id] = $task;

        // Add history
        $this->addTaskHistory($id, 'COMPLETED', $userId, [
            'comment' => $data['comment'] ?? null,
            'output' => $data['output'] ?? [],
        ]);

        return $this->success($task, $this->langService->trans('workflow.task_completed'));
    }

    /**
     * Get task form | 获取任务表单
     *
     * GET /workflow/tasks/{id}/form
     *
     * @param string $id Task ID
     * @param Request $request
     * @return Response
     */
    public function getForm(string $id, Request $request): Response
    {
        $task = self::$tasks[$id] ?? null;

        if ($task === null) {
            return $this->notFound($this->langService->trans('workflow.task_not_found'));
        }

        // Check tenant access | 租户归属校验（缺陷 R13：缺失 tenant_id 一律拒绝，不再对 null 宽免）
        $tenantId = $request->tenantId();
        $tenantError = $this->assertTenantOwnership($task['tenant_id'] ?? null, $tenantId, $this->langService->trans('workflow.access_denied'));
        if ($tenantError !== null) {
            return $tenantError;
        }

        return $this->success([
            'task_id' => $id,
            'form_schema' => $task['form_schema'] ?? null,
            'form_data' => $task['form_data'] ?? [],
            'form_rules' => $task['form_rules'] ?? [],
        ]);
    }

    /**
     * Submit task form | 提交任务表单
     *
     * POST /workflow/tasks/{id}/form
     *
     * Request Body:
     * {
     *   "form_data": {...}
     * }
     *
     * @param string $id Task ID
     * @param Request $request
     * @return Response
     */
    public function submitForm(string $id, Request $request): Response
    {
        $task = self::$tasks[$id] ?? null;

        if ($task === null) {
            return $this->notFound($this->langService->trans('workflow.task_not_found'));
        }

        // Check access
        $result = $this->checkTaskAccess($task, $request);
        if ($result !== null) {
            return $result;
        }

        // Validate status
        if (!in_array($task['status'], [self::STATUS_PENDING, self::STATUS_CLAIMED], true)) {
            return $this->error(
                $this->langService->trans('workflow.cannot_submit_form'),
                400,
                ['current_status' => $task['status']],
                400
            );
        }

        $data = $request->post();

        if (!isset($data['form_data'])) {
            return $this->validationError(['form_data' => $this->langService->trans('workflow.form_data_required')]);
        }

        $userId = $request->userId() ?? 'system';

        // Update task form data
        $task['form_data'] = $data['form_data'];
        $task['updated_at'] = (new \DateTimeImmutable())->format('c');
        self::$tasks[$id] = $task;

        // Add history
        $this->addTaskHistory($id, 'FORM_SUBMITTED', $userId, [
            'form_data' => $data['form_data'],
        ]);

        return $this->success([
            'task_id' => $id,
            'form_data' => $task['form_data'],
        ], $this->langService->trans('workflow.form_submitted'));
    }

    /**
     * Process approval (approve/reject) | 处理审批
     *
     * @param string $id Task ID
     * @param Request $request
     * @param bool $approved
     * @return Response
     */
    protected function processApproval(string $id, Request $request, bool $approved): Response
    {
        $task = self::$tasks[$id] ?? null;

        if ($task === null) {
            return $this->notFound($this->langService->trans('workflow.task_not_found'));
        }

        // Check access
        $result = $this->checkTaskAccess($task, $request);
        if ($result !== null) {
            return $result;
        }

        // Validate status
        if (!in_array($task['status'], [self::STATUS_PENDING, self::STATUS_CLAIMED], true)) {
            return $this->error(
                $this->langService->trans('workflow.cannot_process_approval'),
                400,
                ['current_status' => $task['status']],
                400
            );
        }

        $data = $request->post();
        $userId = $request->userId() ?? 'system';

        // Update task
        $task['status'] = $approved ? self::STATUS_COMPLETED : self::STATUS_REJECTED;
        $task['approval_result'] = $approved ? 'approved' : 'rejected';
        $task['approval_comment'] = $data['comment'] ?? null;
        $task['approval_time'] = (new \DateTimeImmutable())->format('c');
        $task['approved_by'] = $userId;
        $task['completed_at'] = (new \DateTimeImmutable())->format('c');
        $task['completed_by'] = $userId;
        $task['updated_at'] = (new \DateTimeImmutable())->format('c');

        if (isset($data['form_data'])) {
            $task['form_data'] = array_merge($task['form_data'] ?? [], $data['form_data']);
        }

        self::$tasks[$id] = $task;

        // Add history
        $this->addTaskHistory($id, $approved ? 'APPROVED' : 'REJECTED', $userId, [
            'comment' => $data['comment'] ?? null,
            'form_data' => $data['form_data'] ?? null,
        ]);

        $message = $approved
            ? $this->langService->trans('workflow.task_approved')
            : $this->langService->trans('workflow.task_rejected');

        return $this->success($task, $message);
    }

    /**
     * Check task access | 检查任务访问权限
     *
     * @param array $task
     * @param Request $request
     * @return Response|null Returns error response if access denied, null if allowed
     */
    protected function checkTaskAccess(array $task, Request $request): ?Response
    {
        // Check tenant access | 租户归属校验（缺陷 R13：缺失 tenant_id 一律拒绝，不再对 null 宽免）
        $tenantId = $request->tenantId();
        $tenantError = $this->assertTenantOwnership($task['tenant_id'] ?? null, $tenantId, $this->langService->trans('workflow.access_denied'));
        if ($tenantError !== null) {
            return $tenantError;
        }

        // Check user access
        $userId = $request->userId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        // Check if user can access this task
        $assignee = $task['assignee'] ?? null;
        $delegatedTo = $task['delegated_to'] ?? null;
        $candidateUsers = $task['candidate_users'] ?? [];
        $candidateGroups = $task['candidate_groups'] ?? [];
        // 说明（缺陷 R1）：必须调用 app\Request::userGroups()，属性访问恒为 null
        $userGroups = $request->userGroups();

        // 授权语义（缺陷 R1）：
        // 1. 任务已有受理人 → 仅受理人 / 被委托人可操作；
        // 2. 任务未认领但设置了候选约束（候选用户 或 候选组）→ 仅命中候选的用户可操作；
        // 3. 任务未认领且无任何候选约束（开放任务）→ 允许。
        // 原实现算出的 $canAccess 从未被使用，且把「候选用户非空」当作前提条件，
        // 使仅设候选组的未认领任务对任何人开放（fail-open）。
        if ($assignee !== null) {
            // 有受理人：候选身份不能越过受理人，只有受理人 / 被委托人可操作
            if ($assignee !== $userId && $delegatedTo !== $userId) {
                return $this->forbidden($this->langService->trans('workflow.not_task_assignee'));
            }

            return null;
        }

        // 未认领：命中候选用户或候选组才放行
        $hasCandidacy = !empty($candidateUsers) || !empty($candidateGroups);
        $isCandidate = in_array($userId, $candidateUsers, true) ||
                       !empty(array_intersect($userGroups, $candidateGroups));

        if ($hasCandidacy && !$isCandidate) {
            return $this->forbidden($this->langService->trans('workflow.not_eligible_to_claim'));
        }

        return null;
    }

    /**
     * 解析并校验「目标用户」入参 | Resolve & validate target user
     *
     * 缺陷 P2 的统一校验入口（transfer / delegate 共用），两条硬性要求：
     *   1. 必须是**纯数字**的用户 ID（int 或数字字符串）——入参最终落到
     *      `workflow_tasks.assignee` / `delegated_to`，二者都是 int unsigned；
     *   2. 该用户必须存在于**当前租户**——否则等价于允许把任务指派给其它租户的用户，
     *      造成跨租户数据泄漏。
     *
     * @param mixed $raw      客户端原始入参（可能是任意类型）
     * @param int   $tenantId 当前租户 ID（app\Request::tenantId() 恒返回 >= 1 的 int）
     * @return array{0: int|null, 1: string} [合法用户 ID, 错误消息]；合法时错误消息为空字符串
     */
    protected function resolveTargetUser(mixed $raw, int $tenantId): array
    {
        // 只接受 int 与字符串：数组/对象/布尔/null 一律拒绝（JSON 里的用户 ID 只会是前两者）
        if (!is_int($raw) && !is_string($raw)) {
            return [null, '目标用户 ID 必须为纯数字 | target user ID must be numeric'];
        }

        // ctype_digit 只接受 0-9 组成的字符串，天然拒绝 "12.5"、"-1"、"1e3"、"user_name"
        if (!ctype_digit((string) $raw)) {
            return [null, '目标用户 ID 必须为纯数字 | target user ID must be numeric'];
        }

        $userId = (int) $raw;
        if ($userId <= 0) {
            return [null, '目标用户 ID 必须为正整数 | target user ID must be positive'];
        }

        // 必须存在于当前租户：DB 层 users.tenant_id 为 NOT NULL，跨租户用户一律视为不存在
        $exists = Db::name('users')
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->count();

        if ((int) $exists === 0) {
            return [null, '目标用户不存在或不属于当前租户 | target user does not exist in current tenant'];
        }

        return [$userId, ''];
    }

    /**
     * Add task history entry | 添加任务历史记录
     *
     * @param string     $taskId
     * @param string     $action
     * @param int|string $userId 操作用户ID（来自 JWT 时为 int，系统操作时为 'system'）
     * @param array      $data
     * @return void
     */
    protected function addTaskHistory(string $taskId, string $action, int|string $userId, array $data): void
    {
        if (!isset(self::$taskHistory[$taskId])) {
            self::$taskHistory[$taskId] = [];
        }

        self::$taskHistory[$taskId][] = [
            'id' => 'hist_' . uniqid(),
            'task_id' => $taskId,
            'action' => $action,
            'user_id' => $userId,
            'data' => $data,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];
    }
}
