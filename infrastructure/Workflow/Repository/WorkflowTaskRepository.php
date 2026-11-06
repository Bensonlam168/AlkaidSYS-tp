<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Repository;

use Infrastructure\Workflow\Support\WorkflowActorMatch;
use think\facade\Db;

/**
 * 工作流任务仓储（`workflow_tasks`）
 *
 * ## 职责边界
 *
 * 只做「按租户读写任务行」，不含授权判断本身（授权口径见
 * {@see WorkflowActorMatch}，由 `WorkflowTaskService` 调用）。
 * 本类里唯一与授权相关的是**查询下推**：把「我是不是候选人」翻译成 SQL，
 * 否则待办列表只能全表拉回内存再过滤（既慢又容易漏）。
 *
 * ## 「待办」为什么必须下推到 SQL
 *
 * 待办列表是用户可见的最高频端点，且**分页必须在库里做**：
 * 若先按租户取全部在办任务、再在 PHP 里过滤候选人，分页会变成「先切页再过滤」，
 * 结果是「第 1 页只剩 2 条、总数却是 200」这类错乱。因此候选判定被翻译成：
 *
 * ```sql
 * assignee = :me
 *   OR JSON_CONTAINS(candidate_users, :me_as_number)
 *   OR JSON_CONTAINS(candidate_users, :me_as_string)
 *   OR JSON_CONTAINS(candidate_groups, '"role:<slug>"')
 *   OR JSON_CONTAINS(candidate_groups, '"<slug>"')
 * ```
 *
 * 为什么要两种形态的 `JSON_CONTAINS`：引擎写 `candidate_users` 时是
 * `json_encode(array_map('string', $ids))` → `["6102"]`（字符串），
 * 而测试/人工数据里可能是 `[6102]`（数字）。只认一种就会「看起来配了候选人却匹配不上」。
 *
 * ## 表结构依据（`SHOW COLUMNS` 核对）
 *
 * `id, instance_id, tenant_id, node_id, node_name, task_type, task_sub_type, title, description,
 *  status, priority, assignee, assignee_name, candidate_users, candidate_groups, form_data,
 *  result, comment, due_date, reminder_times, completed_at, rejected_at, cancelled_at,
 *  created_at, updated_at, created_by`
 *
 * ⚠️ **没有** `delegated_to` / `delegated_by` / `delegated_at` / `claimed_at` / `type` / `output`
 * 这些旧 Mock 字段（见交付报告「契约冲突」一节）：委托只能落在
 * `candidate_users`（保留委托人）+ `workflow_event_logs`（记录委托事实）。
 *
 * @package Infrastructure\Workflow\Repository
 */
class WorkflowTaskRepository
{
    /**
     * 表名（无前缀）
     */
    protected string $table = 'workflow_tasks';

    /**
     * 「在办」任务状态（与引擎 `ProcessTaskService::DOING_STATUSES` 一致）
     *
     * @var string[]
     */
    public const DOING_STATUSES = ['pending', 'claimed', 'escalated'];

    /**
     * 允许通过列表接口筛选的状态（与列注释的取值域一致）
     *
     * @var string[]
     */
    public const STATUSES = ['pending', 'claimed', 'completed', 'rejected', 'cancelled', 'escalated'];

    /**
     * 组引用合法字符集（角色 slug 的实际取值域：字母/数字/下划线/中划线/冒号/点）
     *
     * 为什么要过滤：组引用会拼进 `JSON_CONTAINS` 的字符串字面量（无法用预处理参数），
     * 含引号的非法引用必须有明确处置 —— 这里**丢弃**而不是转义后拼入：
     * 非法 slug 在本仓库的角色表里本来就不存在，丢弃等价于「匹配不上」，不会放宽授权。
     */
    private const GROUP_REF_PATTERN = '/^[A-Za-z0-9_.:\-]+$/';

    /**
     * 按 ID 无租户读取（**仅用于区分 404 / 403**）
     *
     * @param int $id 任务ID
     * @return array<string, mixed>|null
     */
    public function findByIdUnscoped(int $id): ?array
    {
        $row = Db::name($this->table)->where('id', $id)->find();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * 按 ID + 租户读取
     *
     * @return array<string, mixed>|null
     */
    public function findForTenant(int $tenantId, int $id): ?array
    {
        $row = Db::name($this->table)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->find();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * 列表查询（强制租户过滤）
     *
     * @param array<string, mixed> $filters 支持 status / instance_id / type / assignee / node_id
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function search(int $tenantId, array $filters, int $page, int $pageSize): array
    {
        $query = Db::name($this->table)->where('tenant_id', $tenantId);

        $status = $filters['status'] ?? null;

        if (is_string($status) && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        $instanceId = $filters['instance_id'] ?? null;

        if (is_scalar($instanceId) && ctype_digit((string) $instanceId)) {
            $query->where('instance_id', (int) $instanceId);
        }

        // 旧 Mock 的参数名是 `type`，真实列是 `task_type`：这里做一次显式映射，
        // 而不是让调用方去猜「到底该传哪个名字」
        $type = $filters['type'] ?? $filters['task_type'] ?? null;

        if (is_string($type) && $type !== '') {
            $query->where('task_type', $type);
        }

        $assignee = $filters['assignee'] ?? null;

        if (is_scalar($assignee) && ctype_digit((string) $assignee)) {
            $query->where('assignee', (int) $assignee);
        }

        $nodeId = $filters['node_id'] ?? null;

        if (is_string($nodeId) && $nodeId !== '') {
            $query->where('node_id', $nodeId);
        }

        $total = (int) (clone $query)->count();

        $rows = $query
            ->order(['created_at' => 'desc', 'id' => 'desc'])
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'  => array_map(fn (array $row): array => $this->normalize($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * 待办查询：当前用户在办的任务（assignee = 我 或 我在候选人/候选组里）
     *
     * @param int      $tenantId       租户ID
     * @param int      $userId         当前用户ID
     * @param string[] $userGroupSlugs 当前用户的角色 slug（`app\Request::userGroups()`）
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function searchPending(int $tenantId, int $userId, array $userGroupSlugs, int $page, int $pageSize): array
    {
        $query = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->whereIn('status', self::DOING_STATUSES);

        [$sql, $bind] = $this->actorPredicate($userId, $userGroupSlugs);

        $query->whereRaw($sql, $bind);

        $total = (int) (clone $query)->count();

        $rows = $query
            // 到期时间优先（无到期时间的排最后），再按创建时间倒序
            ->orderRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END ASC, due_date ASC, created_at DESC, id DESC')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'  => array_map(fn (array $row): array => $this->normalize($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * 某实例下「在办」的任务（实例详情页的 current_tasks）
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDoingByInstance(int $tenantId, int $instanceId): array
    {
        $rows = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('instance_id', $instanceId)
            ->whereIn('status', self::DOING_STATUSES)
            ->order(['id' => 'asc'])
            ->select()
            ->toArray();

        return array_map(fn (array $row): array => $this->normalize($row), $rows);
    }

    /**
     * 某实例下的全部任务（含已完成），按 id 升序
     *
     * @param int $limit 上限（防止超大流程一次拉爆内存）
     * @return array<int, array<string, mixed>>
     */
    public function listByInstance(int $tenantId, int $instanceId, int $limit = 200): array
    {
        $rows = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('instance_id', $instanceId)
            ->order(['id' => 'asc'])
            ->limit($limit)
            ->select()
            ->toArray();

        return array_map(fn (array $row): array => $this->normalize($row), $rows);
    }

    /**
     * 按租户更新任务列
     *
     * @param array<string, mixed> $data 待更新列
     * @return int 受影响行数（0 表示不存在 / 不属于该租户 / 值未变化）
     */
    public function updateForTenant(int $tenantId, int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');

        return (int) Db::name($this->table)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->update($data);
    }

    /**
     * 行归一化：json 列解码成数组
     *
     * @param array<string, mixed> $row 原始行
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        foreach (['candidate_users', 'candidate_groups', 'form_data', 'result', 'reminder_times'] as $column) {
            $row[$column] = $this->decodeJson($row[$column] ?? null);
        }

        return $row;
    }

    /**
     * 构造「我是参与者」的 SQL 谓词（唯一实现，待办列表与单条校验共用同一口径）
     *
     * @param string[] $userGroupSlugs
     * @return array{0: string, 1: array<int, mixed>} [SQL, 绑定参数]
     */
    private function actorPredicate(int $userId, array $userGroupSlugs): array
    {
        $conditions = ['assignee = ?'];
        $bind       = [$userId];

        // candidate_users：数字与字符串两种 JSON 形态都要认（见类注释）
        $conditions[] = 'JSON_CONTAINS(candidate_users, ?)';
        $bind[]       = (string) $userId;

        $conditions[] = 'JSON_CONTAINS(candidate_users, ?)';
        $bind[]       = '"' . $userId . '"';

        foreach (WorkflowActorMatch::groupSlugs($userGroupSlugs) as $slug) {
            foreach ([$slug, WorkflowActorMatch::PREFIX_ROLE . $slug] as $ref) {
                if (preg_match(self::GROUP_REF_PATTERN, $ref) !== 1) {
                    // 非法字符直接跳过：匹配不上，绝不拼进 SQL
                    continue;
                }

                $conditions[] = 'JSON_CONTAINS(candidate_groups, ?)';
                // json_encode 产出带引号的合法 JSON 字符串字面量（如 "role:admin"）
                $bind[] = (string) json_encode($ref, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return ['(' . implode(' OR ', $conditions) . ')', $bind];
    }

    /**
     * 解码 json 列
        *
     * @return array<int|string, mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
