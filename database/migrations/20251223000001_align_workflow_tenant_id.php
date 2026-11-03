<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * R10：工作流 5 张表的 tenant_id 类型对齐 + 补建指向 tenants.id 的外键
 *
 * 背景：
 *   建表迁移 20251216000001_create_workflow_tables 把 5 张工作流表的 tenant_id
 *   声明为 `biginteger`（有符号 bigint），而 tenants.id 是 `int unsigned`。
 *   两者类型不一致，导致租户外键即使声明也会被 MySQL 3780 拒绝，
 *   多租户隔离实际只靠应用层拼 WHERE，没有任何数据库级兜底。
 *
 * 本迁移只做两件事（不修改任何既有迁移文件，只在它们之后追加修正）：
 *   1. 列类型对齐：5 个 `bigint`（有符号）→ `int unsigned`，与 tenants.id 完全一致；
 *      可空性与中文注释从 information_schema 实时读取后原样保留，不硬编码；
 *   2. 补建 5 条外键：`fk_{表名}_tenant_id` → `tenants.id`，ON DELETE CASCADE / ON UPDATE CASCADE
 *      （与项目既有 fk_users_tenant_id / fk_roles_tenant_id 等 5 条的惯例保持一致）。
 *
 * 安全设计：
 *   1. 改列前先做数据体检：只要存在 `tenant_id IS NULL` 或「在 tenants.id 里找不到」的值，
 *      立即抛出带清单的异常并中止 —— 既不强建外键导致失败，也不静默改数据；
 *   2. 列类型变更失败则整体中止（列类型没对齐就补外键毫无意义，FK 仍会 3780 失败）；
 *   3. 每条外键独立 try/catch：单条失败只记录，不影响其余外键，最后打印逐条汇总；
 *   4. 刻意使用 up() + down()，不使用 change()：Phinx 检测到 change() 会忽略 down()，
 *      让显式 down() 变成死代码（scripts/check-migrations.php 也会报错）；
 *   5. down() 先删外键再改列类型（顺序不可颠倒：列被外键引用时 MySQL 1832 会拒绝 MODIFY），
 *      并显式清理 MySQL 为外键自动创建的同名索引。
 */
class AlignWorkflowTenantId extends Migrator
{
    /**
     * 被引用表与列：租户主表
     */
    private const REF_TABLE = 'tenants';

    private const REF_COLUMN = 'id';

    /**
     * 待对齐的 5 列（= 全库所有 workflow% 表上的 tenant_id）
     *
     * nullable / comment 仅为兜底值：正常情况下以 information_schema 的实时读数为准，
     * 只有读不到元数据（表或列刚被并发删除等异常场景）时才用这里的兜底值。
     *
     * @var array<int, array{table: string, column: string, nullable: bool, comment: string}>
     */
    private const TENANT_COLUMNS = [
        ['table' => 'workflow_definitions', 'column' => 'tenant_id', 'nullable' => false, 'comment' => '租户ID'],
        ['table' => 'workflow_instances', 'column' => 'tenant_id', 'nullable' => false, 'comment' => '租户ID'],
        ['table' => 'workflow_tasks', 'column' => 'tenant_id', 'nullable' => false, 'comment' => '租户ID'],
        ['table' => 'workflow_triggers', 'column' => 'tenant_id', 'nullable' => false, 'comment' => '租户ID'],
        ['table' => 'workflow_event_logs', 'column' => 'tenant_id', 'nullable' => false, 'comment' => '租户ID'],
    ];

    /**
     * 租户外键统一规则：与既有 fk_users_tenant_id 等 5 条保持一致
     */
    private const ON_DELETE_RULE = 'CASCADE';

    private const ON_UPDATE_RULE = 'CASCADE';

    /**
     * 执行迁移
     */
    public function up(): void
    {
        // a) 数据体检：NULL 与孤儿租户 ID 必须先清零，否则外键建不上
        $this->assertTenantIdsValid();

        // b) bigint（有符号）→ int unsigned（与 tenants.id 完全一致）
        $failures = $this->changeTenantColumns('integer', ['signed' => false], 'bigint → int unsigned');
        if ($failures !== []) {
            // 列类型没对齐就补外键毫无意义，直接中止（MySQL DDL 非事务，已成功的表保持已改动状态）
            throw new \RuntimeException('tenant_id 列类型对齐失败，已中止迁移：' . implode('; ', $failures));
        }

        // c) 补建 5 条租户外键（逐条独立，失败只记录不中断）
        $this->addTenantForeignKeys();
    }

    /**
     * 回滚迁移
     *
     * 顺序与 up() 完全相反：
     *   1. 倒序删除 5 条外键（连同 MySQL 为外键自动创建的索引）；
     *   2. 5 列改回 bigint（有符号）。
     */
    public function down(): void
    {
        // 1) 倒序删除外键（必须先删外键：列被外键引用时 MySQL 1832 拒绝 MODIFY）
        foreach (array_reverse(self::TENANT_COLUMNS) as $definition) {
            $this->dropTenantForeignKeySafely($definition['table'], $definition['column']);
        }

        // 2) int unsigned → bigint（有符号），可空性与注释同样按实时元数据还原
        $failures = $this->changeTenantColumns('biginteger', ['signed' => true], 'int unsigned → bigint');

        // 列类型没改回去却报告回滚成功是假成功，必须抛出让 migrate:rollback 报错
        if ($failures !== []) {
            throw new \RuntimeException('tenant_id 列类型回退失败：' . implode('; ', $failures));
        }
    }

    // -----------------------------------------------------------------------
    // 数据体检
    // -----------------------------------------------------------------------

    /**
     * 数据体检：确认 5 张表的 tenant_id 无 NULL、无孤儿值
     *
     * 「孤儿值」= 在 tenants.id 里找不到的 tenant_id。
     * 由于 tenants.id 是 int unsigned（0 ~ 4294967295），任何负数或超上限的值
     * 都不可能出现在 tenants.id 中，因此孤儿检查同时也覆盖了「无法装进 int unsigned」的情况。
     *
     * @throws \RuntimeException 发现 NULL 或孤儿值时抛出，异常信息带违规清单
     */
    protected function assertTenantIdsValid(): void
    {
        $problems = [];

        foreach (self::TENANT_COLUMNS as $definition) {
            $tableName = $definition['table'];
            $columnName = $definition['column'];
            $label = "{$tableName}.{$columnName}";

            // 守卫：表 / 列不存在则跳过，避免在残缺环境下抛异常
            if (!$this->hasTable($tableName)) {
                $this->notice("数据体检跳过 {$label}：表不存在");
                continue;
            }

            if (!$this->table($tableName)->hasColumn($columnName)) {
                $this->notice("数据体检跳过 {$label}：列不存在");
                continue;
            }

            // 1) NULL 体检：这两列在 20251216000001 里声明为 NOT NULL，正常应为 0 行
            $row = $this->fetchRow(sprintf(
                'SELECT COUNT(*) AS total FROM `%s` WHERE `%s` IS NULL',
                $tableName,
                $columnName
            ));
            $row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];
            $nullCount = (int) ($row['total'] ?? 0);
            if ($nullCount > 0) {
                $problems[] = "{$label} 存在 {$nullCount} 行 tenant_id IS NULL（该列理应为 NOT NULL）";
            }

            // 2) 孤儿体检：NOT EXISTS 而非 NOT IN，避免 NULL 语义让违规行被静默跳过
            $orphans = $this->fetchAll(sprintf(
                'SELECT DISTINCT t.`%1$s` AS tenant_id FROM `%2$s` t'
                . ' WHERE t.`%1$s` IS NOT NULL AND NOT EXISTS ('
                . 'SELECT 1 FROM `%3$s` p WHERE p.`%4$s` = t.`%1$s`)'
                . ' ORDER BY t.`%1$s` LIMIT 20',
                $columnName,
                $tableName,
                self::REF_TABLE,
                self::REF_COLUMN
            ));

            if ($orphans !== []) {
                $values = [];
                foreach ($orphans as $orphan) {
                    $normalized = array_change_key_case($orphan, CASE_LOWER);
                    $values[] = var_export((string) ($normalized['tenant_id'] ?? ''), true);
                }
                $problems[] = "{$label} 存在在 tenants.`id` 中不存在的 tenant_id：" . implode(', ', $values);
                $this->notice("数据体检失败 {$label}：" . count($values) . ' 个孤儿租户ID');
                continue;
            }

            // 3) 顺带记录：该列若已存在外键，MySQL 会拒绝 MODIFY（1832），提前告警便于定位
            if ($this->hasForeignKeyOnColumn($tableName, $columnName)) {
                $this->notice("提示 {$label} 已存在外键约束，MySQL 可能拒绝 MODIFY COLUMN（错误 1832）");
            }

            $this->notice("数据体检通过 {$label}");
        }

        if ($problems !== []) {
            // 中止而非静默改数据：孤儿租户 ID 属于业务数据问题，必须由人决定归属
            throw new \RuntimeException(
                '中止迁移：workflow 表 tenant_id 存在不满足外键约束的数据，请先人工清洗后再重试。' . PHP_EOL
                . implode(PHP_EOL, $problems)
            );
        }
    }

    // -----------------------------------------------------------------------
    // 列类型变更
    // -----------------------------------------------------------------------

    /**
     * 批量变更 tenant_id 列类型：同一张表的列合并为一条 ALTER（MySQL 8 单条 DDL 原子生效）
     *
     * 可空性与注释按 information_schema 的实时读数逐列还原，
     * 因为 changeColumn 不会自动继承原列的 COMMENT / NULL 属性（R5 已实测验证）。
     *
     * @param string               $type       Phinx 列类型（integer / biginteger）
     * @param array<string, mixed> $typeOptions 额外列选项（signed 等）
     * @param string               $logLabel   日志文案
     * @return string[] 失败清单（空数组表示全部成功）
     */
    protected function changeTenantColumns(string $type, array $typeOptions, string $logLabel): array
    {
        $failures = [];
        $grouped = [];

        // 守卫：表 / 列不存在则跳过，避免在残缺环境下抛异常
        foreach (self::TENANT_COLUMNS as $definition) {
            $tableName = $definition['table'];
            $columnName = $definition['column'];

            if (!$this->hasTable($tableName)) {
                $this->notice("跳过{$logLabel} {$tableName}.{$columnName}：表不存在");
                continue;
            }

            if (!$this->table($tableName)->hasColumn($columnName)) {
                $this->notice("跳过{$logLabel} {$tableName}.{$columnName}：列不存在");
                continue;
            }

            $grouped[$tableName][] = $definition;
        }

        foreach ($grouped as $tableName => $tableDefinitions) {
            try {
                $table = $this->table($tableName);
                foreach ($tableDefinitions as $definition) {
                    $columnName = $definition['column'];
                    $meta = $this->columnMeta($tableName, $columnName, $definition);

                    $table->changeColumn(
                        $columnName,
                        $type,
                        array_merge($typeOptions, [
                            // 保留原有可空性（这 5 列均为 NOT NULL，改完必须仍是 NOT NULL）
                            'null' => $meta['nullable'],
                            // 保留原有中文注释（'租户ID'），changeColumn 不会自动继承
                            'comment' => $meta['comment'],
                        ])
                    );

                    $this->notice(sprintf(
                        '准备变更 %s.%s：%s（null=%s，comment=%s）',
                        $tableName,
                        $columnName,
                        $logLabel,
                        $meta['nullable'] ? 'true' : 'false',
                        $meta['comment'] === '' ? '(空)' : $meta['comment']
                    ));
                }
                $table->save();

                foreach ($tableDefinitions as $definition) {
                    $this->notice("已变更 {$tableName}.{$definition['column']}：{$logLabel}");
                }
            } catch (\Throwable $e) {
                $message = "{$tableName} 变更 {$logLabel} 失败：" . $e->getMessage();
                $this->notice($message);
                $failures[] = $message;
            }
        }

        return $failures;
    }

    /**
     * 从 information_schema 读取列的真实可空性与注释
     *
     * @param array{table: string, column: string, nullable: bool, comment: string} $fallback 兜底值
     * @return array{nullable: bool, comment: string}
     */
    protected function columnMeta(string $tableName, string $columnName, array $fallback): array
    {
        $rows = $this->fetchAll(sprintf(
            'SELECT is_nullable, column_comment FROM information_schema.columns'
            . " WHERE table_schema = DATABASE() AND table_name = '%s' AND column_name = '%s'",
            str_replace("'", "''", $tableName),
            str_replace("'", "''", $columnName)
        ));

        if ($rows === []) {
            $this->notice("读取 {$tableName}.{$columnName} 元数据失败，使用兜底值（null=false，comment=租户ID）");

            return ['nullable' => $fallback['nullable'], 'comment' => $fallback['comment']];
        }

        $row = array_change_key_case($rows[0], CASE_LOWER);

        return [
            'nullable' => strtoupper((string) ($row['is_nullable'] ?? 'NO')) === 'YES',
            'comment' => (string) ($row['column_comment'] ?? ''),
        ];
    }

    // -----------------------------------------------------------------------
    // 外键
    // -----------------------------------------------------------------------

    /**
     * 补建 5 条租户外键，并输出逐条结果汇总（成功 X / 跳过 Y / 失败 Z）
     */
    protected function addTenantForeignKeys(): void
    {
        $created = [];
        $skipped = [];
        $failed = [];

        foreach (self::TENANT_COLUMNS as $definition) {
            $tableName = $definition['table'];
            $column = $definition['column'];
            $constraint = $this->foreignKeyName($tableName, $column);
            $target = self::REF_TABLE . '.' . self::REF_COLUMN;

            if (!$this->hasTable($tableName)) {
                $skipped[] = "{$tableName}.{$column}（表不存在）";
                $this->notice("跳过外键 {$constraint} -> {$target}：表 {$tableName} 不存在");
                continue;
            }

            if (!$this->table($tableName)->hasColumn($column)) {
                $skipped[] = "{$tableName}.{$column}（列不存在）";
                $this->notice("跳过外键 {$constraint} -> {$target}：列 {$tableName}.{$column} 不存在");
                continue;
            }

            if (!$this->hasTable(self::REF_TABLE)) {
                $skipped[] = "{$tableName}.{$column}（被引用表不存在）";
                $this->notice("跳过外键 {$constraint} -> {$target}：被引用表 " . self::REF_TABLE . ' 不存在');
                continue;
            }

            // 幂等保护：该列若已有外键（含插件迁移自动命名的重复外键），不重复创建
            if ($this->hasForeignKeyOnColumn($tableName, $column)) {
                $skipped[] = "{$tableName}.{$column}（该列已有外键）";
                $this->notice("跳过外键 {$constraint} -> {$target}：{$tableName}.{$column} 已有外键约束");
                continue;
            }

            try {
                $this->table($tableName)
                    ->addForeignKey($column, self::REF_TABLE, self::REF_COLUMN, [
                        'delete' => self::ON_DELETE_RULE,
                        'update' => self::ON_UPDATE_RULE,
                        // 显式命名：fk_{表名}_{列名}，与 fk_users_tenant_id 等既有 5 条保持一致
                        'constraint' => $constraint,
                    ])
                    ->save();

                $created[] = "{$constraint}（{$tableName}.{$column} -> {$target}，ON DELETE "
                    . self::ON_DELETE_RULE . ' / ON UPDATE ' . self::ON_UPDATE_RULE . '）';
                $this->notice("已创建外键 {$constraint} -> {$target}");
            } catch (\Throwable $e) {
                // 常见原因：列类型/符号仍不一致（MySQL 3780）、表引擎非 InnoDB、被引用列缺索引
                $message = "{$constraint}（{$tableName}.{$column} -> {$target}）：" . $e->getMessage();
                $failed[] = $message;
                $this->notice("创建外键 {$constraint} 失败（已记录，不中断）：" . $e->getMessage());
            }
        }

        $this->notice(sprintf(
            '租户外键补建汇总：成功 %d 条，跳过 %d 条，失败 %d 条',
            count($created),
            count($skipped),
            count($failed)
        ));

        foreach ($created as $item) {
            $this->notice("  [成功] {$item}");
        }
        foreach ($skipped as $item) {
            $this->notice("  [跳过] {$item}");
        }
        foreach ($failed as $item) {
            $this->notice("  [失败] {$item}");
        }
    }

    /**
     * 安全删除租户外键：先按约定名删除，失败再按列名删除，均失败仅记录日志
     *
     * 外键删除后，一并清理 MySQL 为其自动创建的同名索引，
     * 否则回滚会在 5 张表上各留下一个多余的索引。
     */
    protected function dropTenantForeignKeySafely(string $tableName, string $column): void
    {
        if (!$this->hasTable($tableName)) {
            $this->notice("跳过删除外键 {$tableName}.{$column}：表不存在");

            return;
        }

        if (!$this->table($tableName)->hasColumn($column)) {
            $this->notice("跳过删除外键 {$tableName}.{$column}：列不存在");

            return;
        }

        $constraint = $this->foreignKeyName($tableName, $column);
        $dropped = false;

        try {
            $this->table($tableName)->dropForeignKey($column, $constraint)->save();
            $this->notice("已删除外键 {$constraint}");
            $dropped = true;
        } catch (\Throwable $e) {
            $this->notice("按名称删除外键 {$constraint} 失败，尝试按列名删除：" . $e->getMessage());
        }

        if (!$dropped) {
            try {
                $this->table($tableName)->dropForeignKey($column)->save();
                $this->notice("已按列名删除外键 {$tableName}.{$column}");
            } catch (\Throwable $e) {
                // 外键可能本就未创建成功，只记录不中断
                $this->notice("删除外键 {$tableName}.{$column} 失败（已忽略）：" . $e->getMessage());
            }
        }

        $this->dropAutoIndexSafely($tableName, $constraint);
    }

    /**
     * 清理 MySQL 为外键自动生成的索引（索引名与约束名相同）
     *
     * 只在「索引名恰好等于本迁移的外键名」时删除，避免误删业务已有索引：
     * 这 5 张表本来就有单列索引 idx_tenant(tenant_id)，MySQL 会直接复用，
     * 正常情况下不会新建 fk_* 索引，这里因此多数情况是空操作。
     */
    protected function dropAutoIndexSafely(string $tableName, string $indexName): void
    {
        try {
            if (!$this->table($tableName)->hasIndexByName($indexName)) {
                return;
            }

            $this->table($tableName)->removeIndexByName($indexName)->save();
            $this->notice("已删除外键自动索引 {$tableName}.{$indexName}");
        } catch (\Throwable $e) {
            $this->notice("删除外键自动索引 {$tableName}.{$indexName} 失败（已忽略）：" . $e->getMessage());
        }
    }

    /**
     * 判断某列上是否已存在外键约束
     *
     * 按「表 + 列」判断而不是按约束名：不同环境下同一列的外键约束名可能不同
     * （插件目录同名迁移未显式命名时形如 workflow_tasks_ibfk_1），
     * 只按名字判断会把同一列的外键重复创建一遍。
     */
    protected function hasForeignKeyOnColumn(string $tableName, string $column): bool
    {
        $rows = $this->fetchAll(sprintf(
            'SELECT constraint_name FROM information_schema.key_column_usage'
            . " WHERE table_schema = DATABASE() AND table_name = '%s' AND column_name = '%s'"
            . ' AND referenced_table_name IS NOT NULL',
            str_replace("'", "''", $tableName),
            str_replace("'", "''", $column)
        ));

        return $rows !== [];
    }

    /**
     * 外键命名规则：fk_{表名}_{列名}
     */
    protected function foreignKeyName(string $tableName, string $column): string
    {
        return 'fk_' . $tableName . '_' . $column;
    }

    /**
     * 输出迁移日志
     */
    protected function notice(string $message): void
    {
        $output = $this->getOutput();
        if ($output !== null) {
            $output->writeln('[workflow-tenant] ' . $message);
        }
    }
}
