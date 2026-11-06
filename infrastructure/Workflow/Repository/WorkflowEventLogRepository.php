<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Repository;

use think\facade\Db;

/**
 * 工作流事件日志仓储（`workflow_event_logs`）
 *
 * ## 为什么宿主也要写这张表（引擎已经在写）
 *
 * 引擎的 `ProcessInstanceHistoryService` 只写**四类**事件：
 * `instance_started` / `task_completed` / `instance_completed` / `instance_rejected`。
 * 而实例控制器的 cancel / suspend / resume / updateVariables，以及任务控制器的
 * claim / unclaim / transfer / delegate / submitForm 这些**宿主动作**在引擎里没有对应事件 ——
 * 它们若不落库，「谁在什么时候把实例挂起了」「任务被转给谁了」在轨迹里就完全消失。
 *
 * 因此本仓储只做一件引擎不做的事：**为宿主动作追加事件行**，取值来自 `HOST_EVENT_TYPES`，
 * 与引擎的四个事件类型**不重名、不覆盖**（同一张表、同一套列、同一套租户口径）。
 *
 * ## 租户口径
 *
 * `tenant_id` 必填且显式传入（调用方从**实例行本身**取，不取「当前上下文」猜测值），
 * 与引擎 `ProcessInstanceHistoryService::write()` 的做法一致：
 * 事件归属由它所属的实例决定，而不是由「谁触发的」决定。
 *
 * ## 为什么 ip/user_agent 留空
 *
 * 与引擎侧同一取舍：审计旁路不引入 `think\Request` 依赖（HTTP 上下文取不到时会抛，
 * 等于让审计拖垮主流程）。此处由调用方按需补，未补则写 NULL，不伪造。
 *
 * @package Infrastructure\Workflow\Repository
 */
class WorkflowEventLogRepository
{
    /**
     * 表名（无前缀）
     */
    protected string $table = 'workflow_event_logs';

    /**
     * 宿主动作的事件类型（与引擎四类事件区分开）
     *
     * @var array<string, string>
     */
    public const HOST_EVENT_TYPES = [
        'instance_cancelled'  => '流程取消',
        'instance_suspended'  => '流程挂起',
        'instance_resumed'    => '流程恢复',
        'variables_updated'   => '流程变量更新',
        'task_claimed'        => '任务认领',
        'task_unclaimed'      => '任务取消认领',
        'task_transferred'    => '任务转办',
        'task_delegated'      => '任务委托',
        'task_form_submitted' => '任务表单提交',
    ];

    /**
     * 触发器相关的事件类型（第三组，与引擎四类、实例/任务宿主事件都不重名）
     *
     * ## 为什么复用 `workflow_event_logs` 而不是新建 `trigger_logs` 表
     *
     * 1. **列语义本就吻合**：`instance_id` 可空（`SHOW COLUMNS` 实测 `Null=YES`）、
     *    `event_data` 是 json、`tenant_id` / `user_id` / `ip_address` / `user_agent` /
     *    `created_at` 全部具备，表注释就是「工作流事件日志表」—— 它是通用事件表，不是实例专属表。
     * 2. **触发事件天然携带实例**：真正执行成功/失败的那两条（`trigger_fired` / `trigger_failed`）
     *    一定伴随一个真实实例ID，写进 `instance_id` 后它同时出现在实例轨迹里 —— 这正是想要的
     *    （「这个实例是被哪个触发器拉起来的」）。
     * 3. **管理类事件用 NULL 实例**：created/updated/enabled/disabled/tested 这类没有实例，
     *    `instance_id` 写 NULL；触发器的归属靠 `event_data.trigger_id` 表达。
     *    引擎轨迹查询（`ProcessInstanceHistoryService::timeline()`）按 `instance_id` 过滤，
     *    因此这些管理事件**不会**污染任何实例的轨迹。
     * 4. 新建一张只有单写入者的表，等于把「审计读取」拆成两处，代价大于收益；
     *    若将来需要「触发器日志」独立留存期/独立索引，再迁移出去也不影响本方法签名。
     *
     * @var array<string, string>
     */
    public const TRIGGER_EVENT_TYPES = [
        'trigger_created'  => '触发器创建',
        'trigger_updated'  => '触发器更新',
        'trigger_enabled'  => '触发器启用',
        'trigger_disabled' => '触发器禁用',
        'trigger_tested'   => '触发器测试',
        // 删除必须先留痕再删行：日志行本身不挂外键，删掉触发器后这条审计仍然可读
        'trigger_deleted'  => '触发器删除',
        'trigger_fired'    => '触发器执行成功',
        'trigger_failed'   => '触发器执行失败',
    ];

    /**
     * 计入「执行统计」的事件类型（只有真实执行才算）
     *
     * @var string[]
     */
    public const TRIGGER_EXECUTION_EVENT_TYPES = ['trigger_fired', 'trigger_failed'];

    /**
     * 追加一条事件（宿主动作）
     *
     * @param int                  $tenantId   实例归属租户
     * @param int                  $instanceId 实例ID
     * @param int|null             $taskId     任务ID（实例级动作为 null）
     * @param string               $eventType  事件类型（取自 HOST_EVENT_TYPES）
     * @param array<string, mixed> $eventData  事件快照（只放标量/短数组）
     * @param int|null             $userId     真实操作人（`users.id`，外键存在才可写）
     * @return int 自增主键（写入失败抛数据库异常，由调用方决定是否吞掉）
     */
    public function append(
        int $tenantId,
        int $instanceId,
        ?int $taskId,
        string $eventType,
        array $eventData = [],
        ?int $userId = null
    ): int {
        $row = [
            'instance_id' => $instanceId,
            'task_id'     => $taskId,
            'tenant_id'   => $tenantId,
            'event_type'  => $eventType,
            'event_name'  => mb_substr(self::HOST_EVENT_TYPES[$eventType] ?? $eventType, 0, 100),
            'event_data'  => json_encode($eventData, JSON_UNESCAPED_UNICODE),
            // user_id 上有 -> users.id 外键：只有真实用户ID 才写，系统身份写 NULL
            'user_id'     => $userId !== null && $userId > 0 ? $userId : null,
            'user_name'   => null,
            'ip_address'  => null,
            'user_agent'  => null,
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        return (int) Db::name($this->table)->insertGetId($row);
    }

    /**
     * 读取某任务的宿主动作事件（任务详情页的 history）
     *
     * @param int $tenantId 租户ID
     * @param int $taskId   任务ID
     * @param int $limit    上限
     * @return array<int, array<string, mixed>>
     */
    public function listByTaskId(int $tenantId, int $taskId, int $limit = 50): array
    {
        $rows = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->order(['created_at' => 'asc', 'id' => 'asc'])
            ->limit($limit)
            ->select()
            ->toArray();

        return array_map([$this, 'normalizeRow'], $rows);
    }

    // =====================================================================
    // 触发器事件（见 TRIGGER_EVENT_TYPES 的类注释）
    // =====================================================================

    /**
     * 触发器 id 在 `event_data` 里的提取表达式
     *
     * 触发器事件没有独立的外键列（表结构里没有 `trigger_id`），归属靠
     * `event_data.trigger_id` 表达。这里统一成 `UNSIGNED` 便于和 `int` 比较，
     * 避免 json 里出现字符串形态（`"12"`）时漏行。
     */
    private const TRIGGER_REF_SQL = "CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.trigger_id')) AS UNSIGNED)";

    /**
     * 追加一条触发器事件
     *
     * @param int                  $tenantId     触发器归属租户
     * @param int|null             $instanceId   真实启动出来的实例ID；管理类事件为 null
     *                                          （`instance_id` 可空 + FK CASCADE，SHOW COLUMNS 实测）
     * @param int                  $triggerId    触发器ID（写入 `event_data.trigger_id`）
     * @param int|null             $definitionId 定义ID（写入 `event_data.definition_id`，便于按定义聚合）
     * @param string               $eventType    事件类型（取自 TRIGGER_EVENT_TYPES）
     * @param array<string, mixed> $eventData    事件快照（只放标量/短数组；不允许覆盖 trigger_id）
     * @param int|null             $userId       真实操作人（`users.id`，外键存在才可写）
     * @param string|null          $ipAddress    客户端IP（webhook 场景记录）
     * @param string|null          $userAgent    客户端 UA
     * @return int 自增主键
     */
    public function appendTriggerEvent(
        int $tenantId,
        ?int $instanceId,
        int $triggerId,
        ?int $definitionId,
        string $eventType,
        array $eventData = [],
        ?int $userId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int {
        // 归属键由本方法注入，调用方不能覆盖（否则一条日志可能挂到别的触发器名下）
        $eventData['trigger_id'] = $triggerId;

        if ($definitionId !== null && $definitionId > 0) {
            $eventData['definition_id'] = $definitionId;
        }

        $row = [
            // 管理类事件没有实例：写 NULL（列可空），绝不用 0 或伪造ID冒充
            'instance_id' => $instanceId !== null && $instanceId > 0 ? $instanceId : null,
            'task_id'     => null,
            'tenant_id'   => $tenantId,
            'event_type'  => $eventType,
            'event_name'  => mb_substr(self::TRIGGER_EVENT_TYPES[$eventType] ?? $eventType, 0, 100),
            'event_data'  => json_encode($eventData, JSON_UNESCAPED_UNICODE),
            'user_id'     => $userId !== null && $userId > 0 ? $userId : null,
            'user_name'   => null,
            'ip_address'  => $ipAddress !== null && $ipAddress !== '' ? mb_substr($ipAddress, 0, 45) : null,
            'user_agent'  => $userAgent !== null && $userAgent !== '' ? $userAgent : null,
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        return (int) Db::name($this->table)->insertGetId($row);
    }

    /**
     * 分页读取某触发器的日志（按时间倒序）
     *
     * 只返回 `TRIGGER_EVENT_TYPES` 之内的事件：同租户的实例/任务事件不该出现在「触发器日志」里。
     *
     * @param int         $tenantId  租户ID
     * @param int         $triggerId 触发器ID
     * @param int         $page      页码（>=1）
     * @param int         $pageSize  每页条数（>=1）
     * @param string|null $eventType 可选的事件类型收窄
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function listByTriggerId(
        int $tenantId,
        int $triggerId,
        int $page,
        int $pageSize,
        ?string $eventType = null
    ): array {
        $query = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->whereIn('event_type', array_keys(self::TRIGGER_EVENT_TYPES))
            ->whereRaw(self::TRIGGER_REF_SQL . ' = ?', [$triggerId]);

        if ($eventType !== null && isset(self::TRIGGER_EVENT_TYPES[$eventType])) {
            $query->where('event_type', $eventType);
        }

        $total = (int) (clone $query)->count();

        $rows = $query
            ->order(['created_at' => 'desc', 'id' => 'desc'])
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'  => array_map([$this, 'normalizeRow'], $rows),
            'total' => $total,
        ];
    }

    /**
     * 批量统计触发器的执行情况（**派生值，不落库计数列**）
     *
     * 为什么不用 `execution_count` / `last_execution_at` 计数列：表上根本没有这些列
     * （`SHOW CREATE TABLE workflow_triggers` 实测），加列意味着一次迁移 + 一处可能与
     * 真实事件表不一致的冗余状态。事件表本身就是唯一真相，聚合一次只多一条查询。
     *
     * 口径：
     * - `execution_count`：`trigger_fired` + `trigger_failed` 的条数（管理类事件不计）；
     * - `last_execution_at`：其中最近一条的时间；
     * - `last_execution_status`：最近一条是 `trigger_fired` → `success`，否则 `failed`。
     *
     * @param int   $tenantId   租户ID
     * @param int[] $triggerIds 触发器ID列表
     * @return array<int, array{execution_count: int, last_execution_at: string|null, last_execution_status: string|null}>
     */
    public function statsByTriggerIds(int $tenantId, array $triggerIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $triggerIds),
            static fn (int $id): bool => $id > 0
        )));

        $stats = [];

        foreach ($ids as $id) {
            $stats[$id] = [
                'execution_count'       => 0,
                'last_execution_at'     => null,
                'last_execution_status' => null,
            ];
        }

        if ($ids === []) {
            return $stats;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = Db::name($this->table)
            ->fieldRaw(
                self::TRIGGER_REF_SQL . ' AS trigger_ref, event_type, COUNT(*) AS event_count,'
                . ' MAX(created_at) AS latest_at, MAX(id) AS latest_id'
            )
            ->where('tenant_id', $tenantId)
            ->whereIn('event_type', self::TRIGGER_EXECUTION_EVENT_TYPES)
            ->whereRaw(self::TRIGGER_REF_SQL . ' IN (' . $placeholders . ')', $ids)
            ->group('trigger_ref, event_type')
            ->select()
            ->toArray();

        // 同一触发器可能同时有 fired 与 failed 两组，需要按「最大 id」判断最近一次是哪种
        $latest = [];

        foreach ($rows as $row) {
            $ref = (int) ($row['trigger_ref'] ?? 0);

            if ($ref <= 0 || !isset($stats[$ref])) {
                continue;
            }

            $stats[$ref]['execution_count'] += (int) ($row['event_count'] ?? 0);

            $latestAt = (string) ($row['latest_at'] ?? '');

            if ($latestAt !== '' && ($stats[$ref]['last_execution_at'] === null || $latestAt > $stats[$ref]['last_execution_at'])) {
                $stats[$ref]['last_execution_at'] = $latestAt;
            }

            $latestId = (int) ($row['latest_id'] ?? 0);

            if ($latestId > ($latest[$ref]['id'] ?? 0)) {
                $latest[$ref] = ['id' => $latestId, 'type' => (string) ($row['event_type'] ?? '')];
            }
        }

        foreach ($latest as $ref => $info) {
            $stats[$ref]['last_execution_status'] = $info['type'] === 'trigger_fired' ? 'success' : 'failed';
        }

        return $stats;
    }

    /**
     * 行归一化：`event_data` 解码成数组（其余列原样）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function normalizeRow(array $row): array
    {
        $data = $row['event_data'] ?? null;

        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        $row['event_data'] = is_array($data) ? $data : [];

        return $row;
    }
}
