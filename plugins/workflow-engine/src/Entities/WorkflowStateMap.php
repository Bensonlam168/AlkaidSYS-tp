<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Entities;

use Alkaid\Workflow\Engine\enums\ProcessInstanceStateEnum;
use Alkaid\Workflow\Engine\enums\ProcessTaskStateEnum;

/**
 * 状态口径映射：本仓库数据表状态字符串 ↔ 引擎状态枚举
 *
 * 为什么需要这一层：
 * - 引擎的状态是 **int 枚举**（`ProcessInstanceStateEnum` / `ProcessTaskStateEnum`，
 *   例如 DOING=10、FINISHED=20），引擎内部有 `getData('task_state') === DOING->value`
 *   这样的**严格比较**（见 src/Core/Execution.php:131、src/Core/ProcessEngines.php:186），
 *   所以实体必须能把 int 交回去；
 * - 本仓库的 `workflow_instances.status` / `workflow_tasks.status` 是**字符串枚举**
 *   （见 database/migrations/20251216000001_create_workflow_tables.php 的列注释），
 *   取值分别是 pending/running/completed/failed/cancelled/suspended 与
 *   pending/claimed/completed/rejected/cancelled/escalated。
 *
 * 两套枚举**不是一一对应**，映射是本轮明确拍板的口径（写在这里，避免散落在各处）：
 * - 表枚举比引擎枚举细（实例有 failed/suspended，任务有 claimed/rejected/escalated），
 *   读方向按「语义最接近」归并；
 * - 引擎**没有**实例级 REJECT，因此「拒绝」在实例侧落到 cancelled（终止类终态），
 *   任务侧才能表达 rejected；
 * - 写方向取「该语义在表里唯一确定的取值」，同一个引擎状态只能映射到一个表状态，
 *   保证回写后再次读回时状态不会来回漂移。
 *
 * @package Alkaid\Workflow\Engine\Entities
 */
final class WorkflowStateMap
{
    /**
     * 实例：表状态字符串 => 引擎状态 int
     *
     * @var array<string, int>
     */
    private const INSTANCE_TO_ENGINE = [
        'pending'   => ProcessInstanceStateEnum::DOING->value,
        'running'   => ProcessInstanceStateEnum::DOING->value,
        'completed' => ProcessInstanceStateEnum::FINISHED->value,
        'suspended' => ProcessInstanceStateEnum::PENDING->value,
        'failed'    => ProcessInstanceStateEnum::INTERRUPT->value,
        'cancelled' => ProcessInstanceStateEnum::WITHDRAW->value,
    ];

    /**
     * 实例：引擎状态 int => 表状态字符串
     *
     * @var array<int, string>
     */
    private const ENGINE_TO_INSTANCE = [
        ProcessInstanceStateEnum::DOING->value     => 'running',
        ProcessInstanceStateEnum::FINISHED->value  => 'completed',
        ProcessInstanceStateEnum::WITHDRAW->value  => 'cancelled',
        ProcessInstanceStateEnum::INTERRUPT->value => 'failed',
        // 引擎的「已拒绝」在实例表没有独立取值，按终态 cancelled 落库（见类注释）
        ProcessInstanceStateEnum::REJECT->value    => 'cancelled',
        ProcessInstanceStateEnum::PENDING->value   => 'suspended',
        ProcessInstanceStateEnum::ABANDON->value   => 'cancelled',
    ];

    /**
     * 任务：表状态字符串 => 引擎状态 int
     *
     * @var array<string, int>
     */
    private const TASK_TO_ENGINE = [
        'pending'   => ProcessTaskStateEnum::DOING->value,
        'claimed'   => ProcessTaskStateEnum::DOING->value,
        'escalated' => ProcessTaskStateEnum::DOING->value,
        'completed' => ProcessTaskStateEnum::FINISHED->value,
        'rejected'  => ProcessTaskStateEnum::WITHDRAW->value,
        'cancelled' => ProcessTaskStateEnum::ABANDON->value,
    ];

    /**
     * 任务：引擎状态 int => 表状态字符串
     *
     * @var array<int, string>
     */
    private const ENGINE_TO_TASK = [
        ProcessTaskStateEnum::DOING->value     => 'pending',
        ProcessTaskStateEnum::FINISHED->value  => 'completed',
        ProcessTaskStateEnum::WITHDRAW->value  => 'rejected',
        ProcessTaskStateEnum::INTERRUPT->value => 'cancelled',
        ProcessTaskStateEnum::PENDING->value   => 'pending',
        ProcessTaskStateEnum::ABANDON->value   => 'cancelled',
    ];

    /**
     * 实例表状态字符串 => 引擎状态 int
     *
     * 未知取值返回 null（不猜），由调用方决定如何处理。
     */
    public static function instanceToEngine(?string $status): ?int
    {
        if ($status === null) {
            return null;
        }

        return self::INSTANCE_TO_ENGINE[$status] ?? null;
    }

    /**
     * 引擎实例状态 int => 实例表状态字符串
     */
    public static function engineToInstance(int $state): string
    {
        return self::ENGINE_TO_INSTANCE[$state] ?? 'running';
    }

    /**
     * 任务表状态字符串 => 引擎状态 int
     *
     * 未知取值返回 null（不猜），由调用方决定如何处理。
     */
    public static function taskToEngine(?string $status): ?int
    {
        if ($status === null) {
            return null;
        }

        return self::TASK_TO_ENGINE[$status] ?? null;
    }

    /**
     * 引擎任务状态 int => 任务表状态字符串
     */
    public static function engineToTask(int $state): string
    {
        return self::ENGINE_TO_TASK[$state] ?? 'pending';
    }

    /**
     * 任务是否处于「可执行」状态（引擎 DOING）
     */
    public static function taskIsDoing(?string $status): bool
    {
        return self::taskToEngine($status) === ProcessTaskStateEnum::DOING->value;
    }
}
