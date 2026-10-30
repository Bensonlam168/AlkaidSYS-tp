<?php

declare(strict_types=1);

namespace Tests\Integration\Workflow;

use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流引擎表结构冒烟测试（A4）
 *
 * 仅断言「表 / 列是否存在」，不建表、不写数据，也不依赖 ORM 实体：
 * 期望 `php think migrate:run` 已执行 database/migrations/2025121600000{1,2,3,5}。
 * 若表不存在则整体跳过，避免在未执行迁移的环境里污染测试基线。
 */
class WorkflowTablesExistenceTest extends ThinkPHPTestCase
{
    /**
     * 迁移必须创建的工作流表
     *
     * @var string[]
     */
    private const EXPECTED_TABLES = [
        'workflow_definitions',
        'workflow_instances',
        'workflow_tasks',
        'workflow_triggers',
    ];

    /**
     * 表不存在时跳过，避免污染测试基线
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->tableExists('workflow_definitions')) {
            $this->markTestSkipped('工作流表不存在：请先执行 `php think migrate:run`（A4 迁移尚未应用）');
        }
    }

    /**
     * 4 张核心表必须存在（workflow_event_logs 属于附加表，不在此断言范围内）
     */
    public function testExpectedWorkflowTablesExist(): void
    {
        $missing = [];

        foreach (self::EXPECTED_TABLES as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }

        $this->assertSame(
            [],
            $missing,
            '缺少工作流表，请执行 `php think migrate:run`：' . implode(', ', $missing)
        );
    }

    /**
     * workflow_definitions 关键列：tenant_id / nodes_config / status（edges 见下方说明）
     */
    public function testWorkflowDefinitionsHasKeyColumns(): void
    {
        $columns = $this->columnsOf('workflow_definitions');

        foreach (['tenant_id', 'name', 'version', 'status', 'nodes_config'] as $column) {
            $this->assertArrayHasKey(
                $column,
                $columns,
                "workflow_definitions 缺少关键列 {$column}"
            );
        }

        // 记录：当前设计没有独立的 edges 列，连线关系由 nodes_config 的 JSON 结构承载；
        // 若后续新增独立 edges 列，这里同样通过，不会产生误报。
        if (array_key_exists('edges', $columns)) {
            $this->addToAssertionCount(1);
        } else {
            $this->assertSame(
                'json',
                strtolower((string) $columns['nodes_config']['type']),
                'workflow_definitions.nodes_config 应为 JSON 类型，用于承载节点与连线配置'
            );
        }
    }

    /**
     * workflow_instances 关键列：definition_id / tenant_id，且 definition_id 必须是整数类型
     */
    public function testWorkflowInstancesHasKeyColumns(): void
    {
        $columns = $this->columnsOf('workflow_instances');

        foreach (['definition_id', 'tenant_id', 'status'] as $column) {
            $this->assertArrayHasKey(
                $column,
                $columns,
                "workflow_instances 缺少关键列 {$column}"
            );
        }

        // definition_id 必须与 workflow_definitions.id（int unsigned）对齐，
        // 否则迁移 2 的外键创建会因类型不一致（MySQL 3780）失败。
        $this->assertStringStartsWith(
            'int',
            strtolower((string) $columns['definition_id']['type']),
            'workflow_instances.definition_id 应为整数类型，以匹配 workflow_definitions.id'
        );
    }

    /**
     * workflow_tasks 关键列
     */
    public function testWorkflowTasksHasKeyColumns(): void
    {
        $columns = $this->columnsOf('workflow_tasks');

        foreach (['instance_id', 'tenant_id', 'node_id', 'status'] as $column) {
            $this->assertArrayHasKey(
                $column,
                $columns,
                "workflow_tasks 缺少关键列 {$column}"
            );
        }
    }

    /**
     * workflow_triggers 关键列：definition_id / tenant_id
     */
    public function testWorkflowTriggersHasKeyColumns(): void
    {
        $columns = $this->columnsOf('workflow_triggers');

        foreach (['definition_id', 'tenant_id', 'name', 'type'] as $column) {
            $this->assertArrayHasKey(
                $column,
                $columns,
                "workflow_triggers 缺少关键列 {$column}"
            );
        }

        $this->assertStringStartsWith(
            'int',
            strtolower((string) $columns['definition_id']['type']),
            'workflow_triggers.definition_id 应为整数类型，以匹配 workflow_definitions.id'
        );
    }

    /**
     * 判断表是否存在
     *
     * 直接取 SHOW TABLES 全量结果做精确比较，避免 LIKE 中下划线被当成通配符导致误匹配。
     */
    private function tableExists(string $table): bool
    {
        static $tables = null;

        if ($tables === null) {
            $tables = [];
            foreach (Db::query('SHOW TABLES') as $row) {
                // 结果集列名形如 Tables_in_alkaid_sys，随库名变化，这里取第一列的值
                $tables[] = (string) reset($row);
            }
        }

        return in_array($table, $tables, true);
    }

    /**
     * 读取表的列定义：返回 [列名 => 列信息（Field/Type/Null/Key/Default/Extra）]
     *
     * @return array<string, array<string, mixed>>
     */
    private function columnsOf(string $table): array
    {
        $rows = Db::query(sprintf('SHOW COLUMNS FROM `%s`', $table));

        $columns = [];
        foreach ($rows as $row) {
            $normalized = array_change_key_case($row, CASE_LOWER);
            $columns[(string) $normalized['field']] = $normalized;
        }

        return $columns;
    }
}
