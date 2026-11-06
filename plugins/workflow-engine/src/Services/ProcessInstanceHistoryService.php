<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Services;

use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceHistoryService;
use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Alkaid\Workflow\Engine\Multitenant\TenantScopeManager;
use Alkaid\Workflow\Engine\Services\Support\ResolvesWorkflowTenant;

/**
 * 流程实例历史服务（真实实现，落 `workflow_event_logs` 表）
 *
 * ── 一句话口径 ────────────────────────────────────────────────────────────
 * 「历史」= **事件时间线**，不是实例快照副本。实例状态本身由 `workflow_instances`
 * （status / started_at / completed_at / duration / tenant_id）唯一保存，
 * 本服务只追加「谁、在什么时候、把流程推到了哪一步」这四类事件：
 *
 * | event_type           | 含义           | 写入点 |
 * | -------------------- | -------------- | ------ |
 * | `instance_started`   | 流程启动       | {@see \Alkaid\Workflow\Engine\Core\ProcessEngines::startProcessInstanceById()} |
 * | `task_completed`     | 任务审批通过   | {@see ProcessTaskService::finishProcessTask()} |
 * | `instance_completed` | 流程正常结束   | {@see ProcessInstanceService::finishProcessInstance()} |
 * | `instance_rejected`  | 流程被拒绝终止 | {@see ProcessInstanceService::rejectProcessInstance()} |
 *
 * 三列的分工（不重叠、不塞大对象）：
 * - `event_type`：**机器可读**的稳定标识（上表四个常量），给代码判断用；
 * - `event_name`：**人可读**的中文标题，给轨迹页面直接渲染；
 * - `event_data`：**当时的快照**（启动存 `define_id`/`define_code`/`operator`/`status`，
 *   完成存 `status`/`duration`/`completed_at`/`last_task_id`），只放标量与短字符串。
 *
 * ── 租户：只认实例，缺失即失败 ───────────────────────────────────────────
 * 写事件的 `tenant_id` **一律取自实例本身**（`workflow_instances.tenant_id` /
 * 实例对象的 `tenant_id` 属性），**绝不回落默认租户 1**：
 * - `createHistoryProcessInstance()` 直接读实例对象；
 * - 另外三个方法只有实例ID，因此按 ID 反查实例行 —— 这一步刻意**不带**当前租户作用域，
 *   因为要取的就是「这个实例归属哪个租户」这个事实本身（读完立刻用该租户作用域写库）。
 * 若当前租户上下文存在且与实例租户不一致 → 抛跨租户异常（fail-closed，不做"错租户写入"）。
 * 读轨迹 `timeline()` 则相反：**必须**按当前租户过滤（租户 2 查不到租户 1 的轨迹）。
 *
 * ── 写入失败怎么办 ───────────────────────────────────────────────────────
 * 本服务的职责是「该失败就失败得清楚」：租户缺失、实例不存在、列越界一律抛异常。
 * 「不影响主流程」由调用方承担 —— 三处调用点都经
 * {@see Support\RecordsHistorySafely::recordHistorySafely()} 包裹，
 * 捕获异常并记 WARNING。分工是刻意的：服务若自己吞异常，就再也测不出「没写进去」。
 *
 * ── 刻意留空的两列 ───────────────────────────────────────────────────────
 * `ip_address` / `user_agent` 属于 HTTP 上下文信息，而引擎可能在 CLI / 队列 worker 里跑。
 * 为了不把 `think\Request` 依赖引进审计旁路（它取不到请求时会抛，等于让审计拖垮主流程），
 * 本层不采集这两列，也不伪造；P2 接「流程轨迹」页面时由 API 层在请求入口补齐。
 *
 * @package Alkaid\Workflow\Engine\Services
 */
class ProcessInstanceHistoryService implements IProcessInstanceHistoryService
{
    use ResolvesWorkflowTenant;

    /**
     * 事件日志表
     */
    private const TABLE = 'workflow_event_logs';

    /**
     * 实例表（反查事件所属租户）
     */
    private const INSTANCE_TABLE = 'workflow_instances';

    /**
     * 定义表（启动快照里的 define_code）
     */
    private const DEFINITION_TABLE = 'workflow_definitions';

    /** 事件：流程启动 */
    public const EVENT_INSTANCE_STARTED = 'instance_started';

    /** 事件：任务完成 */
    public const EVENT_TASK_COMPLETED = 'task_completed';

    /** 事件：流程完成（走到 end 且状态 completed） */
    public const EVENT_INSTANCE_COMPLETED = 'instance_completed';

    /** 事件：流程拒绝（拒绝终态） */
    public const EVENT_INSTANCE_REJECTED = 'instance_rejected';

    /** 事件中文标题（event_name 列，varchar(100)） */
    private const EVENT_NAMES = [
        self::EVENT_INSTANCE_STARTED   => '流程启动',
        self::EVENT_TASK_COMPLETED     => '任务完成',
        self::EVENT_INSTANCE_COMPLETED => '流程完成',
        self::EVENT_INSTANCE_REJECTED  => '流程拒绝',
    ];

    /**
     * 记录「流程启动」事件
     *
     * @param object          $processInstance 刚落库的实例对象（`getData()` 至少能给出 id 与 tenant_id）
     * @param string|int|null $operator        发起人原始标识（`flow_auto` 这类系统身份也要留痕）
     */
    public function createHistoryProcessInstance(object $processInstance, string|int|null $operator = null): void
    {
        $operation = '记录流程启动事件';

        $instanceId = $this->requireInstanceId(
            $this->instanceValue($processInstance, 'id', $operation),
            $operation
        );

        // 租户只认实例对象本身：缺失即失败，绝不回落默认租户
        $tenantId = $this->tenantIdOfInstanceObject($processInstance, $operation);

        $definitionId = $this->numericId((string) ($this->instanceValue($processInstance, 'process_define_id', $operation) ?? ''));

        $snapshot = [
            'define_id'   => $definitionId,
            'define_code' => $definitionId === null ? null : $this->defineCode($definitionId, $tenantId),
            'operator'    => $this->operatorLabel($operator),
            'status'      => $this->scalarOrNull($this->instanceValue($processInstance, 'status', $operation)),
            'title'       => $this->scalarOrNull($this->instanceValue($processInstance, 'title', $operation)),
            'started_at'  => $this->scalarOrNull($this->instanceValue($processInstance, 'started_at', $operation)),
        ];

        $this->write(
            $tenantId,
            $instanceId,
            null,
            self::EVENT_INSTANCE_STARTED,
            $snapshot,
            $operator
        );
    }

    /**
     * 记录「任务完成」事件
     */
    public function recordTaskCompleted(
        string|int $processInstanceId,
        string|int $processTaskId,
        string|int|null $operator,
        array $extra = []
    ): void {
        $operation = '记录任务完成事件';

        $instanceId = $this->requireInstanceId($processInstanceId, $operation);
        $taskId     = $this->requireInstanceId($processTaskId, $operation);
        $tenantId   = $this->tenantIdByInstanceId($instanceId, $operation);

        $snapshot = [
            'node_name' => $this->scalarOrNull($extra['node_name'] ?? null),
            'title'     => $this->scalarOrNull($extra['title'] ?? null),
            'comment'   => $this->scalarOrNull($extra['comment'] ?? null),
            'status'    => $this->scalarOrNull($extra['status'] ?? null),
            'operator'  => $this->operatorLabel($operator),
        ];

        $this->write($tenantId, $instanceId, $taskId, self::EVENT_TASK_COMPLETED, $snapshot, $operator);
    }

    /**
     * 记录「流程完成」事件
     */
    public function recordInstanceCompleted(
        string|int $processInstanceId,
        string|int|null $operator,
        array $extra = []
    ): void {
        $operation = '记录流程完成事件';

        $instanceId = $this->requireInstanceId($processInstanceId, $operation);
        $tenantId   = $this->tenantIdByInstanceId($instanceId, $operation);

        $this->write(
            $tenantId,
            $instanceId,
            null,
            self::EVENT_INSTANCE_COMPLETED,
            $this->terminalSnapshot($extra, $operator),
            $operator
        );
    }

    /**
     * 记录「流程拒绝」事件
     */
    public function recordInstanceRejected(
        string|int $processInstanceId,
        string|int|null $operator,
        array $extra = []
    ): void {
        $operation = '记录流程拒绝事件';

        $instanceId = $this->requireInstanceId($processInstanceId, $operation);
        $tenantId   = $this->tenantIdByInstanceId($instanceId, $operation);

        $this->write(
            $tenantId,
            $instanceId,
            null,
            self::EVENT_INSTANCE_REJECTED,
            $this->terminalSnapshot($extra, $operator),
            $operator
        );
    }

    /**
     * 读取流程轨迹
     *
     * 排序键是 `created_at, id` 而不是只有 `created_at`：created_at 是 datetime（秒级），
     * 同一秒内产生的事件（启动 → 一步到底 → 完成）只有靠自增 id 才能定序。
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeline(string $processInstanceId): array
    {
        $operation  = '读取流程轨迹';
        $instanceId = $this->requireInstanceId($processInstanceId, $operation);

        // 强制租户过滤：scoped() 取不到当前租户就抛（fail-closed），
        // 并且把 where tenant_id = ? 直接写进 SQL，其他租户查同一实例只能拿到空数组。
        $rows = $this->scoped(self::TABLE, $operation)
            ->query()
            ->where('instance_id', (string) $instanceId)
            ->order(['created_at' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();

        return array_map(static function (array $row): array {
            $eventData = $row['event_data'] ?? null;

            if (is_string($eventData) && $eventData !== '') {
                $decoded = json_decode($eventData, true);
                $eventData = is_array($decoded) ? $decoded : [];
            }

            return [
                'id'          => (int) ($row['id'] ?? 0),
                'instance_id' => (int) ($row['instance_id'] ?? 0),
                'task_id'     => isset($row['task_id']) && $row['task_id'] !== null ? (int) $row['task_id'] : null,
                'event_type'  => (string) ($row['event_type'] ?? ''),
                'event_name'  => (string) ($row['event_name'] ?? ''),
                'event_data'  => is_array($eventData) ? $eventData : [],
                'user_id'     => isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'user_name'   => isset($row['user_name']) && $row['user_name'] !== null ? (string) $row['user_name'] : null,
                'created_at'  => (string) ($row['created_at'] ?? ''),
            ];
        }, $rows);
    }

    // -----------------------------------------------------------------
    // 内部实现
    // -----------------------------------------------------------------

    /**
     * 组装终态事件的快照（完成 / 拒绝共用）
     *
     * @param array           $extra    调用方给的快照字段
     * @param string|int|null $operator 操作人
     * @return array<string, mixed>
     */
    private function terminalSnapshot(array $extra, string|int|null $operator): array
    {
        return [
            'status'       => $this->scalarOrNull($extra['status'] ?? null),
            'completed_at' => $this->scalarOrNull($extra['completed_at'] ?? null),
            'duration'     => isset($extra['duration']) && is_numeric($extra['duration']) ? (int) $extra['duration'] : null,
            'last_task_id' => isset($extra['last_task_id']) && is_numeric($extra['last_task_id']) ? (int) $extra['last_task_id'] : null,
            'operator'     => $this->operatorLabel($operator),
        ];
    }

    /**
     * 真正写库（一行一个事件）
     *
     * @param int             $tenantId  实例归属租户（调用方已从实例解析，不会回落默认值）
     * @param int             $instanceId 实例ID
     * @param int|null        $taskId    任务ID（实例级事件为 null）
     * @param string          $eventType 事件类型（稳定机器标识）
     * @param array           $eventData 事件快照
     * @param string|int|null $operator  操作人原始标识
     */
    private function write(
        int $tenantId,
        int $instanceId,
        ?int $taskId,
        string $eventType,
        array $eventData,
        string|int|null $operator
    ): void {
        $userId = $this->userIdOrNull($operator);

        // json 列按本仓库既有口径显式 json_encode（同 ProcessTaskService::finishProcessTask），
        // 不依赖查询构造器对数组的隐式处理。
        $row = [
            'instance_id' => $instanceId,
            'task_id'     => $taskId,
            'tenant_id'   => $tenantId,
            'event_type'  => $eventType,
            'event_name'  => mb_substr(self::EVENT_NAMES[$eventType] ?? $eventType, 0, 100),
            'event_data'  => json_encode($eventData, JSON_UNESCAPED_UNICODE),
            // user_id 上有 -> users.id 外键（20251222000001）：非数字身份写 NULL 才不会撞 1452
            'user_id'     => $userId,
            // 数字身份已经落在 user_id；非数字身份（flow_auto / flow.admin）放 user_name 留痕，
            // 否则「谁触发的」在库里彻底消失
            'user_name'   => $userId === null ? $this->operatorLabel($operator) : null,
            'ip_address'  => null,
            'user_agent'  => null,
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        TenantScopeManager::forTable(self::TABLE)->forTenant($tenantId)->insert($row);
    }

    /**
     * 从实例对象取租户（fail-closed）
     */
    private function tenantIdOfInstanceObject(object $processInstance, string $operation): int
    {
        $raw = $this->instanceValue($processInstance, 'tenant_id', $operation);

        if (!is_numeric($raw) || (int) $raw <= 0) {
            throw TenantIsolationException::missingTenantContext(sprintf(
                '%s：实例对象没有可用的 tenant_id（收到 %s）。本服务不回落默认租户',
                $operation,
                get_debug_type($raw)
            ));
        }

        return (int) $raw;
    }

    /**
     * 按实例ID反查其归属租户（fail-closed）
     *
     * 为什么这里要 `withoutScope()`：要取的事实就是「实例属于哪个租户」。
     * 用当前租户去查等于「先假定答案是当前租户」，那多租户校验就变成了自证。
     * 查到的租户随即用于**所有**后续读写的作用域（见 write() / timeline()）。
     */
    private function tenantIdByInstanceId(int $instanceId, string $operation): int
    {
        $row = TenantScopeManager::forTable(self::INSTANCE_TABLE)
            ->withoutScope()
            ->find($instanceId);

        if ($row === null) {
            throw new LFlowException(sprintf('%s失败：流程实例 #%d 不存在', $operation, $instanceId));
        }

        $tenantId = (int) ($row['tenant_id'] ?? 0);

        if ($tenantId <= 0) {
            throw TenantIsolationException::missingTenantContext(sprintf(
                '%s失败：流程实例 #%d 的 tenant_id 为空，本服务不回落默认租户',
                $operation,
                $instanceId
            ));
        }

        // 跨租户保护：上下文存在且不一致时拒绝写入（不静默改租户，也不静默放弃）
        $currentTenantId = TenantContext::getTenantId();

        if ($currentTenantId !== null && $currentTenantId !== $tenantId) {
            throw TenantIsolationException::crossTenantAccess(
                $currentTenantId,
                $tenantId,
                self::TABLE,
                (string) $instanceId
            );
        }

        return $tenantId;
    }

    /**
     * 读启动快照里的 define_code（= workflow_definitions.name）
     *
     * 取不到就返回 null，不猜：定义行被删（外键已拦）或列名为空时，宁可空着。
     */
    private function defineCode(int $definitionId, int $tenantId): ?string
    {
        $row = TenantScopeManager::forTable(self::DEFINITION_TABLE)
            ->forTenant($tenantId)
            ->find($definitionId);

        $name = $row['name'] ?? null;

        return is_scalar($name) && (string) $name !== '' ? (string) $name : null;
    }

    /**
     * 读取实例对象上的一个值（引擎视角键名，由实例实体负责映射到表列）
     *
     * @return mixed
     */
    private function instanceValue(object $processInstance, string $key, string $operation): mixed
    {
        if (!method_exists($processInstance, 'getData')) {
            throw new LFlowException(sprintf(
                '%s失败：实例对象 %s 没有 getData() 方法，无法取得 %s',
                $operation,
                get_debug_type($processInstance),
                $key
            ));
        }

        return $processInstance->getData($key);
    }

    /**
     * 收敛实例主键（写操作，非法主键直接报错而不是静默跳过）
     *
     * @param mixed  $raw       原始主键
     * @param string $operation 操作描述
     */
    private function requireInstanceId(mixed $raw, string $operation): int
    {
        $id = is_scalar($raw) ? $this->numericId((string) $raw) : null;

        if ($id === null) {
            throw new LFlowException(sprintf(
                '%s失败：ID「%s」不是合法的整数主键',
                $operation,
                is_scalar($raw) ? (string) $raw : get_debug_type($raw)
            ));
        }

        return $id;
    }

    /**
     * 非数字的操作人标识（`flow_auto` / `flow.admin` / 空）→ 可写入 user_name 的短字符串
     *
     * 数字身份不重复写（它已经落在 user_id）；超长截断到 varchar(100)。
     */
    private function operatorLabel(string|int|null $operator): ?string
    {
        if ($operator === null) {
            return null;
        }

        $value = trim((string) $operator);

        if ($value === '' || ctype_digit($value)) {
            return null;
        }

        return mb_substr($value, 0, 100);
    }

    /**
     * 标量 → 可写入快照的值；非标量一律 null（快照里不塞对象/数组）
     *
     * @param mixed $value 原始值
     * @return string|int|float|bool|null
     */
    private function scalarOrNull(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }
}
