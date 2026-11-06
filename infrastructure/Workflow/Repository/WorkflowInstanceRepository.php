<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Repository;

use think\facade\Db;

/**
 * 工作流实例仓储（`workflow_instances`）
 *
 * ## 职责边界
 *
 * 只做「按租户读写实例行」这一件事，**不含任何业务判断**：
 * - 状态是否允许取消/挂起由 `Infrastructure\Workflow\Service\WorkflowInstanceService` 判断；
 * - 实例与任务的推进由引擎服务（`ProcessInstanceService` / `ProcessEngines`）负责。
 *
 * ## 为什么宿主需要自己的仓储（引擎已有 `ProcessInstanceService::getById()`）
 *
 * 引擎实现里 `list/search/paginate` 全部是「未实现」（入参是未定义 DTO，直接抛
 * `BadMethodCallException`），而实例列表是本模块最常用的读端点。此外引擎**没有**
 * 「挂起 / 恢复 / 终止」的落库实现（`pending/activate/interrupt` 同样未实现），
 * 这三列（`status` / `suspended_at` / `completed_at`）只能由宿主写。
 * 因此本仓储承担两件事：
 *   1. **读**：列表（带 tenant 过滤 + 分页 + 筛选）、按 ID 读（含「存在但不是我租户」的区分）；
 *   2. **窄写**：只写引擎不建模的列（状态机相关），绝不代替引擎创建实例或任务。
 *
 * ## 租户口径（红线）
 *
 * 每个方法都把 `$tenantId` 作为**必填的显式参数**，没有默认值、没有「取当前上下文」的隐式回落 ——
 * 隐式回落正是历史上「忘了带租户条件」的温床。`findByIdUnscoped()` 是**唯一**不带租户条件的读，
 * 它的存在只为一个目的：区分「不存在（404）」与「存在但不是你的（403）」，
 * 调用方必须先做归属判断再使用它的返回。
 *
 * 表结构依据（`SHOW COLUMNS` 核对，见 database/migrations/20251216000001_create_workflow_tables.php）：
 * `id, definition_id, tenant_id, business_key, title, status, priority, current_node_id,
 *  current_node_name, context, variables, started_at, completed_at, suspended_at, duration,
 *  error_message, error_stack, created_at, updated_at, created_by, assignee`
 *
 * @package Infrastructure\Workflow\Repository
 */
class WorkflowInstanceRepository
{
    /**
     * 表名（无前缀；前缀由连接配置决定，见 config/database.php）
     */
    protected string $table = 'workflow_instances';

    /**
     * 允许通过列表接口筛选的状态（与列注释的取值域一致）
     *
     * @var string[]
     */
    public const STATUSES = ['pending', 'running', 'completed', 'failed', 'cancelled', 'suspended'];

    /**
     * 按 ID 无租户读取（**仅用于区分 404 / 403**）
     *
     * @param int $id 实例ID
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
     * @param int $tenantId 租户ID
     * @param int $id       实例ID
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
     * @param int                  $tenantId 租户ID
     * @param array<string, mixed> $filters  支持 status / definition_id / started_by / date_from / date_to / business_key
     * @param int                  $page     页码（>=1）
     * @param int                  $pageSize 每页条数（>=1）
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function search(int $tenantId, array $filters, int $page, int $pageSize): array
    {
        $query = Db::name($this->table)->where('tenant_id', $tenantId);

        $status = $filters['status'] ?? null;

        if (is_string($status) && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        $definitionId = $filters['definition_id'] ?? null;

        if (is_scalar($definitionId) && ctype_digit((string) $definitionId)) {
            $query->where('definition_id', (int) $definitionId);
        }

        $startedBy = $filters['started_by'] ?? null;

        if (is_scalar($startedBy) && ctype_digit((string) $startedBy)) {
            $query->where('created_by', (int) $startedBy);
        }

        $businessKey = $filters['business_key'] ?? null;

        if (is_string($businessKey) && $businessKey !== '') {
            $query->where('business_key', $businessKey);
        }

        $dateFrom = $filters['date_from'] ?? null;

        if (is_string($dateFrom) && ($timestamp = strtotime($dateFrom)) !== false) {
            $query->where('started_at', '>=', date('Y-m-d H:i:s', $timestamp));
        }

        $dateTo = $filters['date_to'] ?? null;

        if (is_string($dateTo) && ($timestamp = strtotime($dateTo)) !== false) {
            // 结束日期按「当天末尾」处理：`<= 2026-01-01` 若按 00:00:00 比较，
            // 会把当天的记录全部排除（用户期望的是「包含这一天」）
            $query->where(
                'started_at',
                '<=',
                date('Y-m-d H:i:s', strtotime(date('Y-m-d', $timestamp)) + 86399)
            );
        }

        $total = (int) (clone $query)->count();

        $rows = $query
            ->order(['started_at' => 'desc', 'id' => 'desc'])
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'  => array_map(fn (array $row): array => $this->normalize($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * 按租户更新实例列（窄写：只供状态机相关列使用）
     *
     * @param int                  $tenantId 租户ID
     * @param int                  $id       实例ID
     * @param array<string, mixed> $data     待更新列
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
     * 统计某定义下的实例数（**不带租户条件**，用于删除定义前的「有实例」判定）
     *
     * 为什么不带租户：`workflow_instances.definition_id` 上有 `ON DELETE CASCADE` 外键，
     * 删一行定义会连带删掉**该定义下所有租户的实例**。删除前的拦截必须按「物理上会被级联删掉的行数」
     * 计算，而不是按「我能看到的行数」计算 —— 否则跨租户实例会被静默抹掉。
     * 定义本身已按 `${tenant_id}` 限定（调用方先做归属校验），所以这里放开租户条件是安全的。
     *
     * @param int $definitionId 定义ID
     * @return int 实例总数
     */
    public function countByDefinition(int $definitionId): int
    {
        return (int) Db::name($this->table)
            ->where('definition_id', $definitionId)
            ->count();
    }

    /**
     * 行归一化：json 列解码成数组（`variables` / `context`）
     *
     * 保留一个原则：**不改列名、不改语义**，只把 json 字符串变成数组，
     * 避免调用方每次都要 `json_decode` 一遍（漏一处就是「变量看起来是空对象」）。
     *
     * @param array<string, mixed> $row 原始行
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        foreach (['variables', 'context'] as $column) {
            $row[$column] = $this->decodeJson($row[$column] ?? null);
        }

        return $row;
    }

    /**
     * 解码 json 列（字符串 / 数组 / null → 数组）
     *
     * @return array<string, mixed>
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
