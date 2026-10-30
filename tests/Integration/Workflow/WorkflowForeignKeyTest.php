<?php

declare(strict_types=1);

namespace Tests\Integration\Workflow;

use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流引擎外键约束冒烟测试（R5）
 *
 * 验收迁移 20251222000001_align_workflow_column_types 的真实效果：
 *   1. 13 条外键存在（原有 2 条 + 本迁移补建的 11 条）；
 *   2. 11 个外键列的类型为 int unsigned，与被引用列（users.id / workflow_instances.id /
 *      workflow_tasks.id）完全一致，否则 MySQL 3780 会再次让外键创建失败；
 *   3. workflow_tasks.instance_id 仍是 NOT NULL（列类型从 varchar(50) 改为 int unsigned 时
 *      不得意外放宽可空性）。
 *
 * 只读断言，不建表、不写数据、不改结构；表或列不存在时跳过，避免污染测试基线。
 */
class WorkflowForeignKeyTest extends ThinkPHPTestCase
{
    /**
     * 期望存在的 13 条外键定义：[约束名, 表名, 列名, 被引用表, 被引用列]
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const EXPECTED_FOREIGN_KEYS = [
        // 迁移 20251216000002 已建成的 2 条（基线条数）
        ['fk_workflow_instances_definition_id', 'workflow_instances', 'definition_id', 'workflow_definitions', 'id'],
        ['fk_workflow_triggers_definition_id', 'workflow_triggers', 'definition_id', 'workflow_definitions', 'id'],
        // 本迁移补建的 11 条
        ['fk_workflow_instances_created_by', 'workflow_instances', 'created_by', 'users', 'id'],
        ['fk_workflow_instances_assignee', 'workflow_instances', 'assignee', 'users', 'id'],
        ['fk_workflow_tasks_instance_id', 'workflow_tasks', 'instance_id', 'workflow_instances', 'id'],
        ['fk_workflow_tasks_assignee', 'workflow_tasks', 'assignee', 'users', 'id'],
        ['fk_workflow_tasks_created_by', 'workflow_tasks', 'created_by', 'users', 'id'],
        ['fk_workflow_triggers_created_by', 'workflow_triggers', 'created_by', 'users', 'id'],
        ['fk_workflow_event_logs_instance_id', 'workflow_event_logs', 'instance_id', 'workflow_instances', 'id'],
        ['fk_workflow_event_logs_task_id', 'workflow_event_logs', 'task_id', 'workflow_tasks', 'id'],
        ['fk_workflow_event_logs_user_id', 'workflow_event_logs', 'user_id', 'users', 'id'],
        ['fk_workflow_definitions_created_by', 'workflow_definitions', 'created_by', 'users', 'id'],
        ['fk_workflow_definitions_updated_by', 'workflow_definitions', 'updated_by', 'users', 'id'],
    ];

    /**
     * 期望为 int unsigned 的 11 个外键列：[表名, 列名]
     *
     * 注意：workflow_instances.definition_id / workflow_triggers.definition_id 早在
     * 20251216000001 建表时就是 int unsigned，不属于本迁移的改造范围。
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const EXPECTED_UNSIGNED_INT_COLUMNS = [
        // A 类：bigint（有符号）→ int unsigned，指向 users.id
        ['workflow_definitions', 'created_by'],
        ['workflow_definitions', 'updated_by'],
        ['workflow_instances', 'created_by'],
        ['workflow_instances', 'assignee'],
        ['workflow_tasks', 'assignee'],
        ['workflow_tasks', 'created_by'],
        ['workflow_triggers', 'created_by'],
        ['workflow_event_logs', 'user_id'],
        // B 类：varchar(50) → int unsigned
        ['workflow_tasks', 'instance_id'],
        ['workflow_event_logs', 'instance_id'],
        ['workflow_event_logs', 'task_id'],
    ];

    /**
     * 表不存在时跳过，避免在未执行迁移的环境里污染测试基线
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->tableExists('workflow_definitions')) {
            $this->markTestSkipped('工作流表不存在：请先执行 `php think migrate:run`（含 20251222000001）');
        }
    }

    /**
     * 13 条外键必须全部存在，且指向正确的表/列
     */
    public function testExpectedWorkflowForeignKeysExist(): void
    {
        $existing = $this->workflowForeignKeys();

        $missing = [];
        foreach (self::EXPECTED_FOREIGN_KEYS as [$constraint, $table, $column, $refTable, $refColumn]) {
            $key = $this->foreignKeyKey($constraint, $table, $column);

            if (!isset($existing[$key])) {
                $missing[] = "{$constraint}（{$table}.{$column} -> {$refTable}.{$refColumn}）";
                continue;
            }

            $actual = $existing[$key];
            $this->assertSame(
                $refTable,
                $actual['referenced_table_name'],
                "外键 {$constraint} 的被引用表应为 {$refTable}"
            );
            $this->assertSame(
                $refColumn,
                $actual['referenced_column_name'],
                "外键 {$constraint} 的被引用列应为 {$refColumn}"
            );
        }

        $this->assertSame([], $missing, '缺少外键（请执行 `php think migrate:run`）：' . implode(', ', $missing));

        // 刻意用「不少于」而不是「等于」：
        // 本用例的契约是「R5 这 13 条外键必须存在」，而不是「全表外键总数恰好 13」。
        // 曾经写成 assertCount(13) 是错的 —— R10 合法地补了 5 条 tenant_id 外键后，
        // 这个正确的改动反而让测试失败，把「表级集合快照」当成了「本次迁移的产物」。
        // 精确性由上面逐条 EXPECTED_FOREIGN_KEYS 的存在性断言保证。
        $this->assertGreaterThanOrEqual(
            count(self::EXPECTED_FOREIGN_KEYS),
            count($existing),
            'workflow% 表上的外键数不应少于 ' . count(self::EXPECTED_FOREIGN_KEYS) . ' 条，实际：'
            . implode(', ', array_keys($existing))
        );
    }

    /**
     * 11 个外键列的类型必须是 int unsigned，与被引用列完全一致
     */
    public function testForeignKeyColumnsAreUnsignedInt(): void
    {
        $columns = $this->columnTypes();

        $wrong = [];
        foreach (self::EXPECTED_UNSIGNED_INT_COLUMNS as [$table, $column]) {
            $key = $table . '.' . $column;

            if (!isset($columns[$key])) {
                $this->markTestSkipped("列 {$key} 不存在：迁移 20251222000001 可能尚未执行");
            }

            if ($columns[$key] !== 'int unsigned') {
                $wrong[] = "{$key} = {$columns[$key]}";
            }
        }

        $this->assertSame(
            [],
            $wrong,
            '以下列类型不是 int unsigned，外键会因类型不一致（MySQL 3780）无法创建：' . implode(', ', $wrong)
        );
    }

    /**
     * workflow_tasks.instance_id 由 varchar(50) 改为 int unsigned 后必须仍为 NOT NULL
     */
    public function testWorkflowTasksInstanceIdRemainsNotNull(): void
    {
        $rows = Db::query(sprintf(
            "SELECT is_nullable FROM information_schema.columns"
            . " WHERE table_schema = DATABASE() AND table_name = 'workflow_tasks' AND column_name = 'instance_id'"
        ));

        if ($rows === []) {
            $this->markTestSkipped('workflow_tasks.instance_id 不存在：迁移 20251222000001 可能尚未执行');
        }

        $row = array_change_key_case($rows[0], CASE_LOWER);
        $this->assertSame(
            'NO',
            (string) ($row['is_nullable'] ?? ''),
            'workflow_tasks.instance_id 必须保持 NOT NULL'
        );
    }

    /**
     * 读取 workflow% 表上的全部外键
     *
     * 注意 `_` 在 LIKE 中是单字符通配符，这里靠 table_schema 与 referenced_table_name 双重限定，
     * 不会误伤其他业务表（不存在名字形如 workfloX 的表）。
     *
     * @return array<string, array{table_name: string, column_name: string, referenced_table_name: string, referenced_column_name: string}>
     */
    private function workflowForeignKeys(): array
    {
        $rows = Db::query(
            "SELECT constraint_name, table_name, column_name, referenced_table_name, referenced_column_name"
            . " FROM information_schema.key_column_usage"
            . " WHERE table_schema = DATABASE() AND table_name LIKE 'workflow%' AND referenced_table_name IS NOT NULL"
            . " ORDER BY table_name, column_name"
        );

        $result = [];
        foreach ($rows as $row) {
            $normalized = array_change_key_case($row, CASE_LOWER);
            $key = $this->foreignKeyKey(
                (string) $normalized['constraint_name'],
                (string) $normalized['table_name'],
                (string) $normalized['column_name']
            );
            $result[$key] = [
                'table_name' => (string) $normalized['table_name'],
                'column_name' => (string) $normalized['column_name'],
                'referenced_table_name' => (string) $normalized['referenced_table_name'],
                'referenced_column_name' => (string) $normalized['referenced_column_name'],
            ];
        }

        return $result;
    }

    /**
     * 外键唯一键：约束名 + 表 + 列
     */
    private function foreignKeyKey(string $constraint, string $table, string $column): string
    {
        return $constraint . '|' . $table . '|' . $column;
    }

    /**
     * 读取 11 个外键列的 column_type：[表.列 => column_type]
     *
     * @return array<string, string>
     */
    private function columnTypes(): array
    {
        $rows = Db::query(
            "SELECT table_name, column_name, column_type FROM information_schema.columns"
            . " WHERE table_schema = DATABASE() AND table_name LIKE 'workflow%'"
            . " AND column_name IN ('instance_id','task_id','created_by','updated_by','assignee','user_id')"
        );

        $result = [];
        foreach ($rows as $row) {
            $normalized = array_change_key_case($row, CASE_LOWER);
            $result[$normalized['table_name'] . '.' . $normalized['column_name']] = strtolower(
                (string) $normalized['column_type']
            );
        }

        return $result;
    }

    /**
     * 判断表是否存在
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
}
