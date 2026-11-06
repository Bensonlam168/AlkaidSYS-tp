<?php

declare(strict_types=1);

namespace Tests\Traits;

use think\facade\Db;

/**
 * 工作流「可执行夹具」Trait
 *
 * ## 为什么需要它
 *
 * 实例/任务控制器改成「真库 + 真引擎」后，测试再也不能靠反射往内存数组里塞一条假任务：
 * - `workflow_tasks.instance_id` / `tenant_id` 都有外键，任务行必须挂在一个真实实例上；
 * - 一旦真的执行任务动作，引擎会按任务行的 `node_name` 回查**流程定义里的节点模型**，
 *   定义体必须是引擎能解析的 LogicFlow `{nodes, edges}`，否则执行链会在
 *   `$processModel->getNode(...)` 处失败。
 *
 * 因此这里把「造一条可执行定义 / 挂在它下面的实例 / 任务行」收成一处，
 * 各测试类只需要关心自己要断言的那一条语义。
 *
 * ## 清理约定（务必遵守）
 *
 * 所有夹具行都以调用方给出的**名字前缀**标识，清理时按前缀删除 ——
 * 绝不按 ID 区间乱删（真库里还有别的东西）。删除顺序必须满足外键：
 * 事件 → 任务 → 实例 → 定义。
 *
 * @package Tests\Traits
 */
trait WorkflowExecutableFixture
{
    /**
     * 构造一条最小可执行图：start → apply(人工) → review(人工) → end
     *
     * @param string $name 图名（同时作为定义名，便于排查）
     * @return array<string, mixed>
     */
    protected function approvalGraph(string $name): array
    {
        return [
            'name'         => $name,
            'display_name' => '可执行夹具流程',
            'type'         => 'approval',
            'nodes'        => [
                $this->logicFlowNode('start', 'start', 200, 160, '开始'),
                $this->logicFlowNode('apply', 'task', 420, 160, '填写申请'),
                $this->logicFlowNode('review', 'task', 640, 160, '审批'),
                $this->logicFlowNode('end', 'end', 860, 160, '结束'),
            ],
            'edges'        => [
                $this->logicFlowEdge('flow_start_apply', 'start', 'apply'),
                $this->logicFlowEdge('flow_apply_review', 'apply', 'review'),
                $this->logicFlowEdge('flow_review_end', 'review', 'end'),
            ],
        ];
    }

    /**
     * 构造 LogicFlow 节点
     *
     * @return array<string, mixed>
     */
    protected function logicFlowNode(string $id, string $parserKey, int $x, int $y, string $displayName): array
    {
        return [
            'id'         => $id,
            'type'       => 'ingenious:' . $parserKey,
            'x'          => $x,
            'y'          => $y,
            'text'       => ['value' => $displayName],
            'properties' => [
                '_alkaid_node_class' => match ($parserKey) {
                    'start' => 'StartNode',
                    'end'   => 'EndNode',
                    default => 'TaskNode',
                },
                '_alkaid_node_type'  => $parserKey,
            ],
        ];
    }

    /**
     * 构造 LogicFlow 边
     *
     * @return array<string, mixed>
     */
    protected function logicFlowEdge(string $id, string $source, string $target): array
    {
        return [
            'id'           => $id,
            'type'         => 'polyline',
            'sourceNodeId' => $source,
            'targetNodeId' => $target,
            'text'         => ['value' => ''],
            'properties'   => ['_alkaid_type' => 'sequence'],
        ];
    }

    /**
     * 插入一条可执行定义（`nodes_config` 为 LogicFlow 图）
     *
     * @param string             $name     定义名（清理时按它圈定，建议带测试专用前缀）
     * @param int                $tenantId 归属租户
     * @param array|null         $graph    自定义图；null 时用 {@see approvalGraph()}
     * @param int                $version  版本号（默认 1；「发新版本」类用例传 2，
     *                                     用于验证「在跑实例锁在旧版本的图/表单上」）
     */
    protected function insertExecutableDefinition(
        string $name,
        int $tenantId = 1,
        ?array $graph = null,
        int $version = 1
    ): int {
        $now = date('Y-m-d H:i:s');

        return (int) Db::table('workflow_definitions')->insertGetId([
            'tenant_id'        => $tenantId,
            'name'             => $name,
            'display_name'     => '可执行夹具流程',
            'description'      => '工作流测试夹具（用例结束后删除）',
            'type'             => 'approval',
            'category'         => 'finance',
            'icon'             => 'fixture-icon',
            'version'          => $version,
            'status'           => 'published',
            'nodes_config'     => json_encode($graph ?? $this->approvalGraph($name), JSON_UNESCAPED_UNICODE),
            'triggers_config'  => json_encode([], JSON_UNESCAPED_UNICODE),
            'variables_schema' => json_encode([], JSON_UNESCAPED_UNICODE),
            'is_active'        => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    /**
     * 插入一条实例行（**不经过引擎**，只用于「动作前置校验」类断言）
     *
     * 需要真实推进流程的用例应当用 HTTP 启动（引擎写实例），而不是用本方法 ——
     * 手插的实例没有引擎生成的第一个任务，`node_name` 也不一定在定义里。
     */
    protected function seedWorkflowInstanceRow(int $tenantId, int $definitionId, int $createdBy): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) Db::table('workflow_instances')->insertGetId([
            'definition_id' => $definitionId,
            'tenant_id'     => $tenantId,
            'title'         => '工作流测试夹具实例',
            'status'        => 'running',
            'priority'      => 0,
            'variables'     => json_encode([], JSON_UNESCAPED_UNICODE),
            'context'       => json_encode([], JSON_UNESCAPED_UNICODE),
            'started_at'    => $now,
            'created_by'    => $createdBy,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    /**
     * 插入一条任务行（**不经过引擎**）
     *
     * @param array<string, mixed> $overrides 覆盖列（assignee / candidate_users / candidate_groups / status ...）
     */
    protected function seedWorkflowTaskRow(int $tenantId, int $instanceId, array $overrides = []): int
    {
        $now = date('Y-m-d H:i:s');

        $row = array_merge([
            'instance_id'      => $instanceId,
            'tenant_id'        => $tenantId,
            'node_id'          => 'apply',
            'node_name'        => 'apply',
            'task_type'        => 'user_task',
            'task_sub_type'    => 'approval',
            'title'            => '工作流测试夹具任务',
            'status'           => 'pending',
            'priority'         => 0,
            'assignee'         => null,
            'candidate_users'  => json_encode([], JSON_UNESCAPED_UNICODE),
            'candidate_groups' => json_encode([], JSON_UNESCAPED_UNICODE),
            'form_data'        => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at'       => $now,
            'updated_at'       => $now,
        ], $overrides);

        // 候选用户/组允许传 PHP 数组，这里统一编码成 JSON 列
        foreach (['candidate_users', 'candidate_groups', 'form_data'] as $jsonColumn) {
            if (is_array($row[$jsonColumn])) {
                $row[$jsonColumn] = json_encode($row[$jsonColumn], JSON_UNESCAPED_UNICODE);
            }
        }

        return (int) Db::table('workflow_tasks')->insertGetId($row);
    }

    /**
     * 按定义名前缀清理夹具（外键顺序：事件 → 任务 → 实例 → 定义）
     *
     * @param string $namePrefix 定义名前缀（与 insertExecutableDefinition() 传入的名字对应）
     */
    protected function purgeWorkflowFixturesByNamePrefix(string $namePrefix): void
    {
        $definitionIds = Db::table('workflow_definitions')
            ->whereLike('name', $namePrefix . '%')
            ->column('id');

        if ($definitionIds === []) {
            return;
        }

        $instanceIds = Db::table('workflow_instances')->whereIn('definition_id', $definitionIds)->column('id');

        if ($instanceIds !== []) {
            Db::table('workflow_tasks')->whereIn('instance_id', $instanceIds)->delete();
            Db::table('workflow_event_logs')->whereIn('instance_id', $instanceIds)->delete();
            Db::table('workflow_instances')->whereIn('id', $instanceIds)->delete();
        }

        Db::table('workflow_definitions')->whereIn('id', $definitionIds)->delete();
    }
}
