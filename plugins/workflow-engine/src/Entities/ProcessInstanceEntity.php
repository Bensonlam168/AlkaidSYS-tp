<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Entities;

use Alkaid\Workflow\Engine\interface\model\IProcessInstance;

/**
 * 工作流实例实体（持久化）
 *
 * 对应表：`workflow_instances`
 * （权威定义见 database/migrations/20251216000001_create_workflow_tables.php）。
 *
 * 引擎视角键名与表列的对应关系（依据是引擎的调用点，不是臆测）：
 *
 * | 引擎键                 | 本表列          | 依据 |
 * | ---------------------- | --------------- | ---- |
 * | `id`                   | `id`            | ProcessEngines:78/208、EndProcessHandler:73 |
 * | `process_define_id`    | `definition_id` | ProcessEngines:198 |
 * | `operator`             | `created_by`    | ProcessEngines:126/174（发起人即操作人；本表无 operator 列） |
 * | `variable`             | `variables`     | ProcessEngines:214 |
 * | `state`                | `status`        | 见 WorkflowStateMap |
 * | `business_no`          | `business_key`  | 语义等价（业务流程号） |
 * | `parent_id` / `parent_node_name` | 无对应列 | 子流程父子关系本表未建模，见下方说明 |
 *
 * ⚠️ 边界：`workflow_instances` **没有** `parent_id` / `parent_node_name` 列，
 * 因此子流程（EndProcessHandler 里的 parent 分支）在本仓库的表结构下无法持久化。
 * 本实体对该两个键返回 null（=「没有父实例」），并且在服务层拒绝写入带 parent 的实例
 * （fail-closed，见 ProcessInstanceService::createProcessInstance），
 * 而不是静默丢掉父流程关系。
 *
 * @package Alkaid\Workflow\Engine\Entities
 */
class ProcessInstanceEntity extends AbstractRowEntity implements IProcessInstance
{
    /**
     * 表列：JSON 列
     *
     * @return string[]
     */
    protected function jsonColumns(): array
    {
        return ['context', 'variables'];
    }

    /**
     * 引擎视角键名 => 表列名
     */
    protected function columnFor(string $key): string
    {
        return match ($key) {
            'process_instance_id' => 'id',
            'process_define_id'   => 'definition_id',
            'operator'            => 'created_by',
            'variable'            => 'variables',
            'business_no'         => 'business_key',
            // 子流程父子关系在本表未建模（见类注释），这两个键始终为 null
            'parent_id', 'parent_node_name' => 'parent_id',
            default                         => $key,
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
        // 表里没有 parent_id 列：按「无父实例」返回，不伪造
        if ($column === 'parent_id') {
            return null;
        }

        if ($column === 'state') {
            $status = $this->attributes['status'] ?? null;

            return WorkflowStateMap::instanceToEngine(is_string($status) ? $status : null);
        }

        if ($column === 'variable') {
            // variables 列可能是数组（已解码）或原始 JSON 字符串
            return $this->attributes['variables'] ?? [];
        }

        return parent::readColumn($column);
    }

    /**
     * 表状态字符串（pending/running/completed/failed/cancelled/suspended）
     */
    public function getStatus(): ?string
    {
        $status = $this->attributes['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * 转成可写库的列数据
     *
     * 必须剔除 `parent_id` / `parent_node_name`：它们只存在于「引擎视角」，
     * 本表没有对应列，写进 UPDATE 会直接报 Unknown column。
     *
     * @param string[] $only 只导出指定列（为空则导出全部）
     * @return array<string, mixed>
     */
    public function toPersistArray(array $only = []): array
    {
        $data = parent::toPersistArray($only);

        unset($data['parent_id'], $data['parent_node_name']);

        return $data;
    }
}
