<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Entities;

use Alkaid\Workflow\Engine\interface\model\IProcessTask;

/**
 * 工作流任务实体（持久化）
 *
 * 对应表：`workflow_tasks`
 * （权威定义见 database/migrations/20251216000001_create_workflow_tables.php）。
 *
 * 引擎视角键名与表列的对应关系（依据是引擎的调用点）：
 *
 * | 引擎键                | 本表列                                       | 依据 |
 * | --------------------- | -------------------------------------------- | ---- |
 * | `id`                  | `id`                                         | ProcessEngines:184、CountersignHandler:115 |
 * | `task_state`          | `status`（映射成 int）                       | Execution:131、ProcessEngines:186 是 `=== DOING->value` 的严格比较 |
 * | `task_name`           | `node_name`                                  | ProcessEngines:99（按节点名取节点模型） |
 * | `process_instance_id` | `instance_id`                                | ProcessEngines:196 |
 * | `assignee`            | `assignee`                                   | 同名，语义一致 |
 * | `candidate_users`     | `candidate_users`                            | 本表用以承载「任务参与者」集合 |
 *
 * ⚠️ 边界：本仓库**没有** `workflow_task_actors`（任务参与者）表，
 * 「参与者」只能落在 `workflow_tasks.assignee` + `candidate_users`（json）两列上，
 * 见 ProcessTaskService::addTaskActor() 的说明。
 *
 * @package Alkaid\Workflow\Engine\Entities
 */
class ProcessTaskEntity extends AbstractRowEntity implements IProcessTask
{
    /**
     * 表列：JSON 列
     *
     * @return string[]
     */
    protected function jsonColumns(): array
    {
        return ['candidate_users', 'candidate_groups', 'form_data', 'result', 'reminder_times'];
    }

    /**
     * 引擎视角键名 => 表列名
     */
    protected function columnFor(string $key): string
    {
        return match ($key) {
            'task_name'           => 'node_name',
            'process_instance_id' => 'instance_id',
            'process_task_id'     => 'id',
            default               => $key,
        };
    }

    /**
     * 读取列值（含引擎需要的派生值）
     *
     * @param string $column 列名
     * @return mixed
     */
    protected function readColumn(string $column): mixed
    {
        if ($column === 'task_state') {
            $status = $this->attributes['status'] ?? null;

            return WorkflowStateMap::taskToEngine(is_string($status) ? $status : null);
        }

        if ($column === 'candidate_users') {
            return $this->attributes['candidate_users'] ?? [];
        }

        return parent::readColumn($column);
    }

    /**
     * 表状态字符串（pending/claimed/completed/rejected/cancelled/escalated）
     */
    public function getStatus(): ?string
    {
        $status = $this->attributes['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * 任务参与者列表（assignee 优先，其余取 candidate_users）
     *
     * 只在 `candidate_users` 是「用户ID 列表」时生效；非数字项（例如表达式、角色标识）
     * 原样保留在 candidate_users 里，但不作为可执行参与者返回。
     *
     * @return string[]
     */
    public function getActorIds(): array
    {
        $actors = [];

        $assignee = $this->attributes['assignee'] ?? null;
        if ($assignee !== null && $assignee !== '') {
            $actors[] = (string) $assignee;
        }

        $candidates = $this->attributes['candidate_users'] ?? [];
        if (is_array($candidates)) {
            foreach ($candidates as $candidate) {
                if (is_scalar($candidate) && (string) $candidate !== '') {
                    $actors[] = (string) $candidate;
                }
            }
        }

        return array_values(array_unique($actors));
    }
}
