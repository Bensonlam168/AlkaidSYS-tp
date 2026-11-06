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

        return array_map(static function (array $row): array {
            $data = $row['event_data'] ?? null;

            if (is_string($data) && $data !== '') {
                $decoded = json_decode($data, true);
                $data = is_array($decoded) ? $decoded : [];
            }

            $row['event_data'] = is_array($data) ? $data : [];

            return $row;
        }, $rows);
    }
}
