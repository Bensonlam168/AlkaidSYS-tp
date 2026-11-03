<?php

declare(strict_types=1);

namespace Tests\Integration\Workflow;

use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流租户外键冒烟测试（R10）
 *
 * 验收迁移 20251223000001_align_workflow_tenant_id 的真实效果：
 *   1. 5 条 `fk_workflow_{表}_tenant_id` 外键存在，指向 tenants.id，
 *      且 ON DELETE / ON UPDATE 均为 CASCADE（与既有 fk_users_tenant_id 等保持一致）；
 *   2. 5 个 tenant_id 列的类型为 int unsigned，与 tenants.id 完全一致，
 *      否则 MySQL 3780 会再次让外键创建失败；
 *   3. 全库指向 tenants 的外键总数 ≥ 10（原有 5 条 + 本迁移新增 5 条），
 *      防止本迁移误删既有租户外键。
 *
 * 只读断言，不建表、不写数据、不改结构；表或列不存在时跳过，避免污染测试基线。
 */
class WorkflowTenantForeignKeyTest extends ThinkPHPTestCase
{
    /**
     * 期望存在的 5 条租户外键：[约束名, 表名]
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const EXPECTED_FOREIGN_KEYS = [
        ['fk_workflow_definitions_tenant_id', 'workflow_definitions'],
        ['fk_workflow_instances_tenant_id', 'workflow_instances'],
        ['fk_workflow_tasks_tenant_id', 'workflow_tasks'],
        ['fk_workflow_triggers_tenant_id', 'workflow_triggers'],
        ['fk_workflow_event_logs_tenant_id', 'workflow_event_logs'],
    ];

    /**
     * 期望为 int unsigned 的 5 个租户列
     *
     * @var array<int, string>
     */
    private const EXPECTED_UNSIGNED_TENANT_TABLES = [
        'workflow_definitions',
        'workflow_instances',
        'workflow_tasks',
        'workflow_triggers',
        'workflow_event_logs',
    ];

    /**
     * 指向 tenants 的外键最少条数：既有 5 条（depts/menus/roles/sites/users）+ 本迁移 5 条
     */
    private const MIN_TENANT_FOREIGN_KEYS = 10;

    /**
     * 表不存在时跳过，避免在未执行迁移的环境里污染测试基线
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->tableExists('workflow_definitions')) {
            $this->markTestSkipped('工作流表不存在：请先执行 `php think migrate:run`（含 20251223000001）');
        }
    }

    /**
     * 5 条租户外键必须存在，指向 tenants.id，且规则为 CASCADE / CASCADE
     */
    public function testTenantForeignKeysExistWithCascadeRules(): void
    {
        $existing = $this->tenantForeignKeys();

        $missing = [];
        $wrong = [];

        foreach (self::EXPECTED_FOREIGN_KEYS as [$constraint, $table]) {
            if (!isset($existing[$constraint])) {
                $missing[] = "{$constraint}（{$table}.tenant_id）";
                continue;
            }

            $actual = $existing[$constraint];

            if ($actual['table_name'] !== $table) {
                $wrong[] = "{$constraint} 的表应为 {$table}，实际 {$actual['table_name']}";
            }
            if ($actual['referenced_table_name'] !== 'tenants') {
                $wrong[] = "{$constraint} 应指向 tenants，实际 {$actual['referenced_table_name']}";
            }
            if ($actual['referenced_column_name'] !== 'id') {
                $wrong[] = "{$constraint} 应指向 tenants.id，实际 {$actual['referenced_column_name']}";
            }
            if ($actual['delete_rule'] !== 'CASCADE') {
                $wrong[] = "{$constraint} 的 ON DELETE 应为 CASCADE，实际 {$actual['delete_rule']}";
            }
            if ($actual['update_rule'] !== 'CASCADE') {
                $wrong[] = "{$constraint} 的 ON UPDATE 应为 CASCADE，实际 {$actual['update_rule']}";
            }
        }

        $this->assertSame(
            [],
            $missing,
            '缺少租户外键（请执行 `php think migrate:run`）：' . implode(', ', $missing)
        );
        $this->assertSame([], $wrong, '租户外键定义不符：' . implode('; ', $wrong));
    }

    /**
     * 5 个 tenant_id 列的类型必须是 int unsigned，与 tenants.id 完全一致
     */
    public function testTenantIdColumnsAreUnsignedInt(): void
    {
        $columns = $this->tenantColumnTypes();

        $wrong = [];
        foreach (self::EXPECTED_UNSIGNED_TENANT_TABLES as $table) {
            $key = $table . '.tenant_id';

            if (!isset($columns[$key])) {
                $this->markTestSkipped("列 {$key} 不存在：迁移 20251223000001 可能尚未执行");
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
     * 全库指向 tenants 的外键总数必须 ≥ 10，确认已有 5 条未被误删
     */
    public function testTotalTenantForeignKeysAtLeastTen(): void
    {
        $rows = Db::query(
            'SELECT COUNT(*) AS total FROM information_schema.key_column_usage'
            . " WHERE table_schema = DATABASE() AND referenced_table_name = 'tenants'"
        );

        if ($rows === []) {
            $this->markTestSkipped('无法统计指向 tenants 的外键数量');
        }

        $row = array_change_key_case($rows[0], CASE_LOWER);
        $total = (int) ($row['total'] ?? 0);

        $this->assertGreaterThanOrEqual(
            self::MIN_TENANT_FOREIGN_KEYS,
            $total,
            '指向 tenants 的外键总数应至少 10 条（既有 5 条 + 工作流 5 条），实际 ' . $total . ' 条'
        );
    }

    /**
     * 读取以 fk_workflow_*_tenant_id 命名的租户外键（含被引用列与级联规则）
     *
     * @return array<string, array{table_name: string, column_name: string, referenced_table_name: string, referenced_column_name: string, delete_rule: string, update_rule: string}>
     */
    private function tenantForeignKeys(): array
    {
        $rows = Db::query(
            'SELECT k.constraint_name, k.table_name, k.column_name, k.referenced_table_name,'
            . ' k.referenced_column_name, r.delete_rule, r.update_rule'
            . ' FROM information_schema.key_column_usage k'
            . ' JOIN information_schema.referential_constraints r'
            . '   ON r.constraint_schema = k.table_schema'
            . '  AND r.constraint_name = k.constraint_name'
            . '  AND r.table_name = k.table_name'
            . " WHERE k.table_schema = DATABASE() AND k.table_name LIKE 'workflow\\_%'"
            . '   AND k.column_name = \'tenant_id\' AND k.referenced_table_name = \'tenants\''
        );

        $result = [];
        foreach ($rows as $row) {
            $normalized = array_change_key_case($row, CASE_LOWER);
            $result[(string) $normalized['constraint_name']] = [
                'table_name' => (string) $normalized['table_name'],
                'column_name' => (string) $normalized['column_name'],
                'referenced_table_name' => (string) $normalized['referenced_table_name'],
                'referenced_column_name' => (string) $normalized['referenced_column_name'],
                'delete_rule' => strtoupper((string) $normalized['delete_rule']),
                'update_rule' => strtoupper((string) $normalized['update_rule']),
            ];
        }

        return $result;
    }

    /**
     * 读取 5 张工作流表 tenant_id 的 column_type：[表.列 => column_type]
     *
     * 注意 `_` 在 LIKE 中为单字符通配符，这里用 `\\_` 转义，避免匹配到 workfloX 之类表名。
     *
     * @return array<string, string>
     */
    private function tenantColumnTypes(): array
    {
        $rows = Db::query(
            'SELECT table_name, column_name, column_type FROM information_schema.columns'
            . ' WHERE table_schema = DATABASE() AND table_name IN ('
            . implode(', ', array_map(static fn (string $t): string => "'" . $t . "'", self::EXPECTED_UNSIGNED_TENANT_TABLES))
            . ") AND column_name = 'tenant_id'"
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
