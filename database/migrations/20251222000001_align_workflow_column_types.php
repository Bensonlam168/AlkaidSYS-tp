<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * R5：工作流表外键列类型对齐（方案 A1 + B1）
 *
 * 背景：
 *   迁移 20251216000002_add_foreign_keys 声明了 10 条外键，实际只有 2 条建成
 *   （fk_workflow_instances_definition_id、fk_workflow_triggers_definition_id），
 *   其余 8 条都因「外键列与被引用列类型不一致」（MySQL 3780）被静默跳过。
 *   根因在建表迁移 20251216000001：
 *     - 指向 users.id（int unsigned）的列被声明为 bigint（有符号）；
 *     - 指向 workflow_instances.id / workflow_tasks.id（int unsigned）的列被声明为 varchar(50)。
 *
 * 本迁移只做两件事（不修改既有迁移文件，只在它们之后追加修正）：
 *   A1. 列类型对齐：8 个 bigint 列 + 3 个 varchar(50) 列 → int unsigned
 *       （保留原有可空性与中文注释）；
 *   B1. 补建 11 条外键：20251216000002 漏建的 8 条 + 从未声明过的 3 条
 *       （workflow_instances.assignee、workflow_definitions.created_by、workflow_definitions.updated_by）。
 *
 * 安全设计：
 *   1. varchar → int unsigned 之前先做数据体检：只要存在「非 NULL 且非数字」或
 *      「超出 int unsigned 上限」的值，立即抛出带清单的异常并中止，绝不静默丢数据；
 *   2. 每条外键独立 try/catch：单条失败只记日志，不影响其余外键与整体迁移；
 *   3. 同一张表的同一批列变更合并成一条 ALTER（MySQL 8 单条 DDL 原子生效，
 *      避免「改了一半」的中间态）；
 *   4. 刻意使用 up() + down()，不使用 change()：Phinx 检测到 change() 会忽略 down()，
 *      导致显式 down() 变成死代码（scripts/check-migrations.php 也会报错）。
 */
class AlignWorkflowColumnTypes extends Migrator
{
    /**
     * int unsigned 取值上限（varchar → int unsigned 数据体检用）
     */
    private const INT_UNSIGNED_MAX = '4294967295';

    /**
     * A 类：bigint（有符号）→ int unsigned，目标列全部指向 users.id（int unsigned）
     *
     * comment / nullable 取自 information_schema，用于 changeColumn 时原样保留。
     *
     * @var array<int, array{table: string, column: string, nullable: bool, comment: string}>
     */
    private const BIGINT_COLUMNS = [
        ['table' => 'workflow_definitions', 'column' => 'created_by', 'nullable' => true, 'comment' => '创建用户ID'],
        ['table' => 'workflow_definitions', 'column' => 'updated_by', 'nullable' => true, 'comment' => '更新用户ID'],
        ['table' => 'workflow_instances', 'column' => 'created_by', 'nullable' => true, 'comment' => '发起用户ID'],
        ['table' => 'workflow_instances', 'column' => 'assignee', 'nullable' => true, 'comment' => '当前处理人ID'],
        ['table' => 'workflow_tasks', 'column' => 'assignee', 'nullable' => true, 'comment' => '处理人ID'],
        ['table' => 'workflow_tasks', 'column' => 'created_by', 'nullable' => true, 'comment' => '创建人ID'],
        ['table' => 'workflow_triggers', 'column' => 'created_by', 'nullable' => true, 'comment' => '创建人ID'],
        ['table' => 'workflow_event_logs', 'column' => 'user_id', 'nullable' => true, 'comment' => '操作用户ID'],
    ];

    /**
     * B 类：varchar(50) → int unsigned
     *
     * 指向 workflow_instances.id / workflow_tasks.id（两者均为 int unsigned）。
     * 注意 workflow_tasks.instance_id 必须是 NOT NULL，转换后仍为 NOT NULL。
     *
     * @var array<int, array{table: string, column: string, nullable: bool, comment: string}>
     */
    private const VARCHAR_COLUMNS = [
        ['table' => 'workflow_tasks', 'column' => 'instance_id', 'nullable' => false, 'comment' => '工作流实例ID'],
        ['table' => 'workflow_event_logs', 'column' => 'instance_id', 'nullable' => true, 'comment' => '工作流实例ID'],
        ['table' => 'workflow_event_logs', 'column' => 'task_id', 'nullable' => true, 'comment' => '任务ID'],
    ];

    /**
     * 本迁移补齐的 11 条外键
     *
     * 约束命名统一为 fk_{表名}_{列名}，与 20251216000002 已建成的 2 条保持一致。
     *
     * @var array<int, array{table: string, column: string, ref_table: string, ref_column: string, on_delete: string, on_update: string}>
     */
    private const FOREIGN_KEYS = [
        // 工作流实例 -> 用户（发起人 / 当前处理人）
        [
            'table' => 'workflow_instances',
            'column' => 'created_by',
            'ref_table' => 'users',
            'ref_column' => 'id',
            'on_delete' => 'SET NULL',
            'on_update' => 'CASCADE',
        ],
        [
            'table' => 'workflow_instances',
            'column' => 'assignee',
            'ref_table' => 'users',
            'ref_column' => 'id',
            'on_delete' => 'SET NULL',
            'on_update' => 'CASCADE',
        ],
        // 工作流任务 -> 工作流实例（NOT NULL 列，删除实例时级联删除任务）
        [
            'table' => 'workflow_tasks',
            'column' => 'instance_id',
            'ref_table' => 'workflow_instances',
            'ref_column' => 'id',
            'on_delete' => 'CASCADE',
            'on_update' => 'CASCADE',
        ],
        // 工作流任务 -> 用户（处理人 / 创建人）
        [
            'table' => 'workflow_tasks',
            'column' => 'assignee',
            'ref_table' => 'users',
            'ref_column' => 'id',
            'on_delete' => 'SET NULL',
            'on_update' => 'CASCADE',
        ],
        [
            'table' => 'workflow_tasks',
            'column' => 'created_by',
            'ref_table' => 'users',
            'ref_column' => 'id',
            'on_delete' => 'SET NULL',
            'on_update' => 'CASCADE',
        ],
        // 工作流触发器 -> 用户（创建人）
        [
            'table' => 'workflow_triggers',
            'column' => 'created_by',
            'ref_table' => 'users',
            'ref_column' => 'id',
            'on_delete' => 'SET NULL',
            'on_update' => 'CASCADE',
        ],
        // 工作流事件日志 -> 工作流实例 / 工作流任务 / 用户
        [
            'table' => 'workflow_event_logs',
            'column' => 'instance_id',
            'ref_table' => 'workflow_instances',
            'ref_column' => 'id',
            'on_delete' => 'CASCADE',
            'on_update' => 'CASCADE',
        ],
        [
            'table' => 'workflow_event_logs',
            'column' => 'task_id',
            'ref_table' => 'workflow_tasks',
            'ref_column' => 'id',
            'on_delete' => 'SET NULL',
            'on_update' => 'CASCADE',
        ],
        [
            'table' => 'workflow_event_logs',
            'column' => 'user_id',
            'ref_table' => 'users',
            'ref_column' => 'id',
            'on_delete' => 'SET NULL',
            'on_update' => 'CASCADE',
        ],
        // 工作流定义 -> 用户（创建人 / 更新人）
        [
            'table' => 'workflow_definitions',
            'column' => 'created_by',
            'ref_table' => 'users',
            'ref_column' => 'id',
            'on_delete' => 'SET NULL',
            'on_update' => 'CASCADE',
        ],
        [
            'table' => 'workflow_definitions',
            'column' => 'updated_by',
            'ref_table' => 'users',
            'ref_column' => 'id',
            'on_delete' => 'SET NULL',
            'on_update' => 'CASCADE',
        ],
    ];

    /**
     * 执行迁移
     */
    public function up(): void
    {
        // a) 数据体检：varchar → int unsigned 之前必须确认无脏数据
        $this->assertVarcharConvertible(self::VARCHAR_COLUMNS);

        // b) A 类：bigint（有符号）→ int unsigned
        $failures = $this->changeColumns(self::BIGINT_COLUMNS, 'integer', ['signed' => false], 'bigint → int unsigned');
        if ($failures !== []) {
            // 列类型没对齐就补外键毫无意义（FK 仍会 3780 失败），因此这里直接中止
            throw new \RuntimeException('列类型对齐失败，已中止迁移：' . implode('; ', $failures));
        }

        // c) B 类：varchar(50) → int unsigned
        $failures = $this->changeColumns(self::VARCHAR_COLUMNS, 'integer', ['signed' => false], 'varchar(50) → int unsigned');
        if ($failures !== []) {
            throw new \RuntimeException('列类型对齐失败，已中止迁移：' . implode('; ', $failures));
        }

        // d) 补建 11 条外键（逐条独立，失败只记录）
        $this->addForeignKeys();
    }

    /**
     * 回滚迁移
     *
     * 顺序与 up() 完全相反：
     *   1. 倒序删除 11 条外键（连同 MySQL 为外键自动创建的索引）；
     *   2. 把 11 列改回原类型（B 类 → varchar(50)，A 类 → bigint 有符号）。
     */
    public function down(): void
    {
        // 1) 倒序删除外键
        foreach (array_reverse(self::FOREIGN_KEYS) as $foreignKey) {
            $this->dropForeignKeySafely($foreignKey['table'], $foreignKey['column']);
        }

        $failures = [];

        // 2) B 类列改回 varchar(50)（先改 B 类，与 up() 顺序相反）
        $failures = array_merge(
            $failures,
            $this->changeColumns(self::VARCHAR_COLUMNS, 'string', ['limit' => 50], 'int unsigned → varchar(50)')
        );

        // 3) A 类列改回 bigint（有符号）
        $failures = array_merge(
            $failures,
            $this->changeColumns(self::BIGINT_COLUMNS, 'biginteger', ['signed' => true], 'int unsigned → bigint')
        );

        // 列类型没改回去就标记回滚成功是假成功，必须抛出让 migrate:rollback 报错
        if ($failures !== []) {
            throw new \RuntimeException('列类型回退失败：' . implode('; ', $failures));
        }
    }

    // -----------------------------------------------------------------------
    // 列类型变更
    // -----------------------------------------------------------------------

    /**
     * 批量变更列类型：同一张表的列合并为一条 ALTER（MySQL 8 单条 DDL 原子生效）
     *
     * 可空性与注释按 COLUMN 常量逐列还原，避免 changeColumn 丢注释 / 丢 NOT NULL。
     *
     * @param array<int, array{table: string, column: string, nullable: bool, comment: string}> $definitions 列定义清单
     * @param string                                                                          $type        Phinx 列类型
     * @param array<string, mixed>                                                            $typeOptions 额外列选项（signed / limit 等）
     * @param string                                                                          $logLabel    日志文案
     * @return string[] 失败清单（空数组表示全部成功）
     */
    protected function changeColumns(array $definitions, string $type, array $typeOptions, string $logLabel): array
    {
        $failures = [];
        $grouped = [];

        // 守卫：表 / 列不存在则跳过，避免在残缺环境下抛异常
        foreach ($definitions as $definition) {
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
                    $table->changeColumn(
                        $definition['column'],
                        $type,
                        array_merge($typeOptions, [
                            // 保留原有可空性（workflow_tasks.instance_id 必须仍为 NOT NULL）
                            'null' => $definition['nullable'],
                            // 保留原有中文注释，changeColumn 不会自动继承
                            'comment' => $definition['comment'],
                        ])
                    );
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

    // -----------------------------------------------------------------------
    // varchar → int 数据体检
    // -----------------------------------------------------------------------

    /**
     * 数据体检：确认待转换的 varchar 列里没有「非 NULL 且不可安全转为 int unsigned」的值
     *
     * 判定不通过的两类值：
     *   1. 非纯数字（如 'abc'、'1a'、' 1'、'1.5'）—— 直接转 int 会静默变成 0 或被截断；
     *   2. 超出 int unsigned 上限（4294967295）—— 转换会报 1264，或（非严格模式下）被截断。
     *
     * @param array<int, array{table: string, column: string, nullable: bool, comment: string}> $definitions 列定义清单
     * @throws \RuntimeException 发现不可转换数据时抛出，异常信息带违规值清单
     */
    protected function assertVarcharConvertible(array $definitions): void
    {
        $problems = [];

        foreach ($definitions as $definition) {
            $tableName = $definition['table'];
            $columnName = $definition['column'];

            if (!$this->hasTable($tableName)) {
                $this->notice("数据体检跳过 {$tableName}.{$columnName}：表不存在");
                continue;
            }

            if (!$this->table($tableName)->hasColumn($columnName)) {
                $this->notice("数据体检跳过 {$tableName}.{$columnName}：列不存在");
                continue;
            }

            // 非空且（非纯数字 或 超出 int unsigned 上限）的值，最多取 20 个用于报错展示
            $rows = $this->fetchAll(sprintf(
                'SELECT `%1$s` AS value FROM `%2$s`'
                . ' WHERE `%1$s` IS NOT NULL'
                . ' AND (`%1$s` NOT REGEXP \'^[0-9]+$\' OR CAST(`%1$s` AS DECIMAL(30, 0)) > %3$s)'
                . ' ORDER BY `%1$s` LIMIT 20',
                $columnName,
                $tableName,
                self::INT_UNSIGNED_MAX
            ));

            if ($rows !== []) {
                $values = [];
                foreach ($rows as $row) {
                    $values[] = var_export((string) $row['value'], true);
                }
                $problems[] = "{$tableName}.{$columnName} 存在不可转换为 int unsigned 的值：" . implode(', ', $values);
                $this->notice("数据体检失败 {$tableName}.{$columnName}：" . count($values) . ' 个异常值');
                continue;
            }

            $this->notice("数据体检通过 {$tableName}.{$columnName}");
        }

        if ($problems !== []) {
            // 中止而非静默丢数据：转 int 丢掉的业务主键无法事后还原
            throw new \RuntimeException(
                '中止迁移：varchar → int unsigned 存在不可转换的数据，请先人工清洗后再重试。' . PHP_EOL
                . implode(PHP_EOL, $problems)
            );
        }
    }

    // -----------------------------------------------------------------------
    // 外键
    // -----------------------------------------------------------------------

    /**
     * 补建本迁移声明的 11 条外键，并输出逐条结果汇总
     */
    protected function addForeignKeys(): void
    {
        $created = [];
        $skipped = [];
        $failed = [];

        foreach (self::FOREIGN_KEYS as $foreignKey) {
            $tableName = $foreignKey['table'];
            $column = $foreignKey['column'];
            $constraint = $this->foreignKeyName($tableName, $column);
            $target = "{$foreignKey['ref_table']}.{$foreignKey['ref_column']}";

            if (!$this->hasTable($tableName)) {
                $skipped[] = "{$tableName}.{$column}（表不存在）";
                $this->notice("跳过外键 {$constraint} -> {$target}：表 {$tableName} 不存在");
                continue;
            }

            if (!$this->hasTable($foreignKey['ref_table'])) {
                $skipped[] = "{$tableName}.{$column}（被引用表不存在）";
                $this->notice("跳过外键 {$constraint} -> {$target}：被引用表 {$foreignKey['ref_table']} 不存在");
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
                    ->addForeignKey($column, $foreignKey['ref_table'], $foreignKey['ref_column'], [
                        'delete' => $foreignKey['on_delete'],
                        'update' => $foreignKey['on_update'],
                        // 显式命名：fk_{表名}_{列名}，便于 DBA 排查与回滚定位
                        'constraint' => $constraint,
                    ])
                    ->save();

                $created[] = "{$constraint}（{$tableName}.{$column} -> {$target}，ON DELETE {$foreignKey['on_delete']}）";
                $this->notice("已创建外键 {$constraint} -> {$target}");
            } catch (\Throwable $e) {
                // 常见原因：列类型/符号仍不一致（MySQL 3780）、被引用列缺索引、表引擎非 InnoDB
                $message = "{$constraint}（{$tableName}.{$column} -> {$target}）：" . $e->getMessage();
                $failed[] = $message;
                $this->notice("创建外键 {$constraint} 失败（已跳过）：" . $e->getMessage());
            }
        }

        $this->notice(sprintf(
            '外键补建汇总：成功 %d 条，跳过 %d 条，失败 %d 条',
            count($created),
            count($skipped),
            count($failed)
        ));

        foreach ($skipped as $item) {
            $this->notice("  [跳过] {$item}");
        }
        foreach ($failed as $item) {
            $this->notice("  [失败] {$item}");
        }
    }

    /**
     * 安全删除外键：先按约定名删除，失败再按列名删除，均失败仅记录日志
     *
     * 外键删除后，一并清理 MySQL 为其自动创建的同名索引，
     * 否则回滚会留下 5 个多余的索引（created_by / updated_by / user_id 等列原本没有索引）。
     */
    protected function dropForeignKeySafely(string $tableName, string $column): void
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
     * 只在「索引名恰好等于本迁移的外键名」时删除，避免误删业务已有索引；
     * 若该列本来就有可用索引（如 idx_created_by），MySQL 不会额外建索引，这里自然跳过。
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
     * 按「表 + 列」判断而不是按约束名：插件目录下的同名迁移
     * （plugins/workflow-engine/migrations/20251216000002）没有显式命名约束，
     * 在跑过插件迁移的环境里外键名形如 workflow_tasks_ibfk_1，
     * 只按名字判断会把同一列的外键重复创建一遍。
     */
    protected function hasForeignKeyOnColumn(string $tableName, string $column): bool
    {
        $rows = $this->fetchAll(sprintf(
            "SELECT constraint_name FROM information_schema.key_column_usage"
            . " WHERE table_schema = DATABASE() AND table_name = '%s' AND column_name = '%s'"
            . " AND referenced_table_name IS NOT NULL",
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
            $output->writeln('[workflow-align] ' . $message);
        }
    }
}
