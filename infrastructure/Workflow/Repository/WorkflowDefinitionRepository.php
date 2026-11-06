<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Repository;

use think\facade\Db;

/**
 * 工作流定义仓储（只读，**仅用于实例启动前的归属与可执行性判定**）
 *
 * ## 为什么需要「无租户」读
 *
 * 启动实例时必须能区分两件事（二者对客户端的安全含义完全不同）：
 * - 定义**不存在** → 404；
 * - 定义存在但**属于别的租户** → 403（不能退化成 404，也不能退化成 500）。
 *
 * 引擎的 `ProcessDefineService::getById()` 是**租户作用域**查询，跨租户只会返回 null，
 * 无法区分上面两种情形。因此这里保留一个**仅供判定**的无租户读取，
 * 归属判断由调用方（`WorkflowInstanceService`）立刻完成，绝不在此类里做业务分支。
 *
 * 定义的写操作不在此列：定义控制器（`WorkflowDefinitionController`）仍走引擎/自身实现，
 * 本包不触碰它。
 *
 * @package Infrastructure\Workflow\Repository
 */
class WorkflowDefinitionRepository
{
    /**
     * 表名（无前缀）
     */
    protected string $table = 'workflow_definitions';

    /**
     * 按 ID 无租户读取（仅用于 404 / 403 区分与「定义体是否可执行」判定）
     *
     * @param int $id 定义ID
     * @return array<string, mixed>|null
     */
    public function findByIdUnscoped(int $id): ?array
    {
        $row = Db::name($this->table)
            ->field('id,tenant_id,name,display_name,status,version,is_active,nodes_config,bpmn_xml')
            ->where('id', $id)
            ->find();

        if (!is_array($row)) {
            return null;
        }

        $nodesConfig = $row['nodes_config'] ?? null;

        if (is_string($nodesConfig) && $nodesConfig !== '') {
            $decoded = json_decode($nodesConfig, true);
            $row['nodes_config'] = is_array($decoded) ? $decoded : null;
        } elseif (!is_array($nodesConfig)) {
            $row['nodes_config'] = null;
        }

        return $row;
    }

    /**
     * 定义体是否能被引擎的 `ModelParser` 解析（LogicFlow 结构：nodes + edges 两个数组）
     *
     * 判据与引擎 `ProcessDefineEntity::hasEngineReadableContent()` **完全一致**：
     * 只认 `{nodes: [...], edges: [...]}`。旧形态（`{start:{id,name}, ...}` 节点表）
     * 会被 `ModelParser::parse()` 静默解析成空模型，随后在 `$processModel->getStart()`
     * 处以 `Call to a member function on null` 崩成 500 —— 与其让它崩，不如在入口就明确拒绝。
     *
     * @param array<string, mixed> $definition 定义行
     */
    public static function isExecutable(array $definition): bool
    {
        $config = $definition['nodes_config'] ?? null;

        return is_array($config)
            && isset($config['nodes'], $config['edges'])
            && is_array($config['nodes'])
            && is_array($config['edges'])
            && $config['nodes'] !== [];
    }
}
