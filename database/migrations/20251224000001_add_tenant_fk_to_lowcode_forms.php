<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * P1-A：lowcode_forms.tenant_id 收口为「真实租户引用」——类型对齐 int unsigned + 补建租户外键
 *
 * 背景（审计实测）：
 *   1. `lowcode_forms.tenant_id` 当前是 `bigint unsigned NOT NULL` 且**无默认值**，
 *      按 `design/01-architecture-design/04-multi-tenant-design.md` §0 的「两条语义」，
 *      这属于「真实租户引用」，必须有 `fk_lowcode_forms_tenant_id → tenants.id`；
 *   2. 但它一直没有外键，是全库**唯一**违反该规则的例外，靠
 *      `scripts/check-migrations.php` 的 `KNOWN_TENANT_FK_EXCEPTIONS` 基线以 INFO 放行；
 *   3. 类型也对不上：`tenants.id` 是 `int unsigned`，`bigint unsigned` 直接建外键会被
 *      MySQL 3780 拒绝，因此必须先对齐列类型再补外键（与 20251223000001 处理 workflow 表同理）。
 *
 * 归属决策：本表**按真实租户引用处理**，不加 `DEFAULT 0`。
 *   理由：`FormSchemaRepository` 的 save / lookup / delete **全部强制要求 tenant_id**，
 *   代码事实上已把该列当租户引用使用；`tenant_id = 0` 在本表没有任何业务含义
 *   （低代码表单没有「系统模板」共享需求，模板能力由 `lowcode_collections` 承载）。
 *   配套改动：`app/command/lowcode/CreateFormCommand.php` 的 `--tenant-id` 默认值 0 → 1，
 *   且显式传 0 时直接给出业务错误，不再让它一路写库撞外键。
 *
 * 本迁移只做两件事（不修改任何既有迁移文件，只在它们之后追加修正）：
 *   1. 列类型对齐：`bigint unsigned` → `int unsigned`，与 `tenants.id` 完全一致；
 *      可空性与中文注释从 information_schema 实时读取后原样保留，不硬编码；
 *   2. 补建外键 `fk_lowcode_forms_tenant_id` → `tenants.id`，
 *      ON DELETE CASCADE / ON UPDATE CASCADE（与项目既有 fk_users_tenant_id 等惯例一致）。
 *
 * 安全设计：
 *   1. 改列前先做数据体检：只要存在 `tenant_id IS NULL` 或「在 tenants.id 里找不到」的值，
 *      立即抛出带清单的异常并中止 —— 既不强建外键导致失败，也不静默改数据；
 *   2. 刻意使用 up() + down()，不使用 change()：Phinx 检测到 change() 会忽略 down()，
 *      让显式 down() 变成死代码（scripts/check-migrations.php 的检查 C 也会报错）；
 *   3. down() 先删外键再改列类型（顺序不可颠倒：列被外键引用时 MySQL 1832 会拒绝 MODIFY），
 *      并显式清理 MySQL 为外键自动创建的同名索引（DROP FOREIGN KEY 不会连带删索引）。
 */
class AddTenantFkToLowcodeForms extends Migrator
{
    /**
     * 目标表与列
     */
    private const TABLE = 'lowcode_forms';

    private const COLUMN = 'tenant_id';

    /**
     * 被引用表与列：租户主表
     */
    private const REF_TABLE = 'tenants';

    private const REF_COLUMN = 'id';

    /**
     * 外键命名规则：fk_{表名}_{列名}（与 fk_users_tenant_id 等既有约束一致）
     */
    private const CONSTRAINT = 'fk_lowcode_forms_tenant_id';

    /**
     * 租户外键统一规则：与既有 10 条指向 tenants 的外键保持一致
     */
    private const ON_DELETE_RULE = 'CASCADE';

    private const ON_UPDATE_RULE = 'CASCADE';

    /**
     * 列可空性 / 注释的兜底值
     *
     * 正常情况下以 information_schema 的实时读数为准，
     * 只有读不到元数据（表或列刚被并发删除等异常场景）时才用这里的兜底值。
     */
    private const FALLBACK_NULLABLE = false;

    private const FALLBACK_COMMENT = '租户ID';

    /**
     * 执行迁移
     */
    public function up(): void
    {
        // a) 数据体检：NULL 与孤儿租户 ID 必须先清零，否则外键建不上
        $this->assertTenantIdsValid();

        // b) bigint unsigned → int unsigned（与 tenants.id 完全一致）
        $this->changeTenantColumn('integer', 'bigint unsigned → int unsigned');

        // c) 补建租户外键（幂等：同列已有外键则跳过）
        $this->addTenantForeignKey();
    }

    /**
     * 回滚迁移
     *
     * 顺序与 up() 完全相反：
     *   1. 删除租户外键（连同 MySQL 为外键自动创建的索引）；
     *   2. 列改回 bigint unsigned。
     */
    public function down(): void
    {
        // 1) 先删外键：列被外键引用时 MySQL 1832 拒绝 MODIFY COLUMN
        $this->dropTenantForeignKeySafely();

        // 2) int unsigned → bigint unsigned，可空性与注释同样按实时元数据还原
        $this->changeTenantColumn('biginteger', 'int unsigned → bigint unsigned');
    }

    // -----------------------------------------------------------------------
    // 数据体检
    // -----------------------------------------------------------------------

    /**
     * 数据体检：确认 lowcode_forms.tenant_id 无 NULL、无孤儿值
     *
     * 「孤儿值」= 在 tenants.id 里找不到的 tenant_id（典型是历史上的 tenant_id = 0）。
     * 由于 tenants.id 是 int unsigned（0 ~ 4294967295），任何负数或超上限的值
     * 都不可能出现在 tenants.id 中，因此孤儿检查同时也覆盖了「装不进 int unsigned」的情况。
     *
     * @throws \RuntimeException 发现 NULL 或孤儿值时抛出，异常信息带违规清单
     */
    protected function assertTenantIdsValid(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            // 表不存在时无需体检（后续改列 / 外键步骤也会各自跳过）
            $this->notice('数据体检跳过：表 ' . self::TABLE . ' 不存在');

            return;
        }

        if (!$this->table(self::TABLE)->hasColumn(self::COLUMN)) {
            $this->notice('数据体检跳过：列 ' . self::TABLE . '.' . self::COLUMN . ' 不存在');

            return;
        }

        $problems = [];

        // 1) NULL 体检：该列在建表迁移里声明为 NOT NULL，正常应为 0 行
        $row = $this->fetchRow(sprintf(
            'SELECT COUNT(*) AS total FROM `%s` WHERE `%s` IS NULL',
            self::TABLE,
            self::COLUMN
        ));
        $row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];
        $nullCount = (int) ($row['total'] ?? 0);
        if ($nullCount > 0) {
            $problems[] = sprintf(
                '%s.%s 存在 %d 行 tenant_id IS NULL（该列理应为 NOT NULL）',
                self::TABLE,
                self::COLUMN,
                $nullCount
            );
        }

        // 2) 孤儿体检：NOT EXISTS 而非 NOT IN，避免 NULL 语义让违规行被静默跳过
        $orphans = $this->fetchAll(sprintf(
            'SELECT DISTINCT t.`%1$s` AS tenant_id FROM `%2$s` t'
            . ' WHERE t.`%1$s` IS NOT NULL AND NOT EXISTS ('
            . 'SELECT 1 FROM `%3$s` p WHERE p.`%4$s` = t.`%1$s`)'
            . ' ORDER BY t.`%1$s` LIMIT 20',
            self::COLUMN,
            self::TABLE,
            self::REF_TABLE,
            self::REF_COLUMN
        ));

        if ($orphans !== []) {
            $values = [];
            foreach ($orphans as $orphan) {
                $normalized = array_change_key_case($orphan, CASE_LOWER);
                $values[] = var_export((string) ($normalized['tenant_id'] ?? ''), true);
            }
            $problems[] = sprintf(
                '%s.%s 存在在 tenants.`id` 中不存在的 tenant_id：%s（tenant_id = 0 不是租户，tenants.id 从 1 开始）',
                self::TABLE,
                self::COLUMN,
                implode(', ', $values)
            );
        }

        // 3) 顺带记录：该列若已存在外键，MySQL 会拒绝 MODIFY（1832），提前告警便于定位
        if ($this->hasForeignKeyOnColumn(self::TABLE, self::COLUMN)) {
            $this->notice(sprintf(
                '提示 %s.%s 已存在外键约束，MySQL 可能拒绝 MODIFY COLUMN（错误 1832）',
                self::TABLE,
                self::COLUMN
            ));
        }

        if ($problems !== []) {
            // 中止而非静默改数据：孤儿租户 ID 属于业务数据问题，必须由人决定归属
            throw new \RuntimeException(
                '中止迁移：lowcode_forms.tenant_id 存在不满足外键约束的数据，请先人工清洗后再重试。' . PHP_EOL
                . implode(PHP_EOL, $problems)
            );
        }

        $this->notice(sprintf('数据体检通过 %s.%s', self::TABLE, self::COLUMN));
    }

    // -----------------------------------------------------------------------
    // 列类型变更
    // -----------------------------------------------------------------------

    /**
     * 变更 tenant_id 列类型（始终 unsigned，与 tenants.id 对齐）
     *
     * 可空性与注释按 information_schema 的实时读数还原：
     * `changeColumn` 不会自动继承原列的 COMMENT / NULL 属性（R5 已实测验证），
     * 必须显式传 `null` 与 `comment`，否则会被改成「可空 + 无注释」。
     *
     * @param string $type     Phinx 列类型（integer / biginteger）
     * @param string $logLabel 日志文案
     * @throws \RuntimeException 列类型未对齐成功时抛出（列类型不对齐就补外键毫无意义）
     */
    protected function changeTenantColumn(string $type, string $logLabel): void
    {
        if (!$this->hasTable(self::TABLE)) {
            $this->notice("跳过{$logLabel}：表 " . self::TABLE . ' 不存在');

            return;
        }

        if (!$this->table(self::TABLE)->hasColumn(self::COLUMN)) {
            $this->notice("跳过{$logLabel}：列 " . self::TABLE . '.' . self::COLUMN . ' 不存在');

            return;
        }

        $meta = $this->columnMeta();

        $this->table(self::TABLE)
            ->changeColumn(self::COLUMN, $type, [
                // 与 tenants.id 保持一致的 unsigned
                'signed' => false,
                // 保留原有可空性（该列是 NOT NULL，改完必须仍是 NOT NULL）
                'null' => $meta['nullable'],
                // 保留原有中文注释（'租户ID'），changeColumn 不会自动继承
                'comment' => $meta['comment'],
            ])
            ->save();

        $this->notice(sprintf(
            '已变更 %s.%s：%s（signed=false，null=%s，comment=%s）',
            self::TABLE,
            self::COLUMN,
            $logLabel,
            $meta['nullable'] ? 'true' : 'false',
            $meta['comment'] === '' ? '(空)' : $meta['comment']
        ));
    }

    /**
     * 从 information_schema 读取列的真实可空性与注释
     *
     * @return array{nullable: bool, comment: string}
     */
    protected function columnMeta(): array
    {
        $rows = $this->fetchAll(sprintf(
            'SELECT is_nullable, column_comment FROM information_schema.columns'
            . " WHERE table_schema = DATABASE() AND table_name = '%s' AND column_name = '%s'",
            str_replace("'", "''", self::TABLE),
            str_replace("'", "''", self::COLUMN)
        ));

        if ($rows === []) {
            $this->notice(sprintf(
                '读取 %s.%s 元数据失败，使用兜底值（null=false，comment=%s）',
                self::TABLE,
                self::COLUMN,
                self::FALLBACK_COMMENT
            ));

            return ['nullable' => self::FALLBACK_NULLABLE, 'comment' => self::FALLBACK_COMMENT];
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
     * 补建租户外键（幂等：该列已有外键则跳过）
     */
    protected function addTenantForeignKey(): void
    {
        $target = self::REF_TABLE . '.' . self::REF_COLUMN;

        if (!$this->hasTable(self::TABLE)) {
            $this->notice('跳过外键 ' . self::CONSTRAINT . " -> {$target}：表 " . self::TABLE . ' 不存在');

            return;
        }

        if (!$this->table(self::TABLE)->hasColumn(self::COLUMN)) {
            $this->notice(sprintf(
                '跳过外键 %s -> %s：列 %s.%s 不存在',
                self::CONSTRAINT,
                $target,
                self::TABLE,
                self::COLUMN
            ));

            return;
        }

        if (!$this->hasTable(self::REF_TABLE)) {
            $this->notice('跳过外键 ' . self::CONSTRAINT . " -> {$target}：被引用表 " . self::REF_TABLE . ' 不存在');

            return;
        }

        // 幂等保护：该列若已有外键（含其他迁移自动命名的外键），不重复创建
        if ($this->hasForeignKeyOnColumn(self::TABLE, self::COLUMN)) {
            $this->notice(sprintf(
                '跳过外键 %s -> %s：%s.%s 已有外键约束',
                self::CONSTRAINT,
                $target,
                self::TABLE,
                self::COLUMN
            ));

            return;
        }

        $this->table(self::TABLE)
            ->addForeignKey(self::COLUMN, self::REF_TABLE, self::REF_COLUMN, [
                'delete' => self::ON_DELETE_RULE,
                'update' => self::ON_UPDATE_RULE,
                // 显式命名：fk_{表名}_{列名}，与 fk_users_tenant_id 等既有约束保持一致
                'constraint' => self::CONSTRAINT,
            ])
            ->save();

        $this->notice(sprintf(
            '已创建外键 %s（%s.%s -> %s，ON DELETE %s / ON UPDATE %s）',
            self::CONSTRAINT,
            self::TABLE,
            self::COLUMN,
            $target,
            self::ON_DELETE_RULE,
            self::ON_UPDATE_RULE
        ));
    }

    /**
     * 安全删除租户外键：先按约定名删除，失败再按列名删除，均失败仅记录日志
     *
     * 外键删除后一并清理 MySQL 为其自动创建的同名索引，
     * 否则回滚会在表上留下一个多余的索引。
     */
    protected function dropTenantForeignKeySafely(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            $this->notice('跳过删除外键：表 ' . self::TABLE . ' 不存在');

            return;
        }

        if (!$this->table(self::TABLE)->hasColumn(self::COLUMN)) {
            $this->notice('跳过删除外键：列 ' . self::TABLE . '.' . self::COLUMN . ' 不存在');

            return;
        }

        if (!$this->hasForeignKeyOnColumn(self::TABLE, self::COLUMN)) {
            $this->notice(sprintf('跳过删除外键 %s：该列当前没有外键约束', self::CONSTRAINT));

            return;
        }

        $dropped = false;

        try {
            $this->table(self::TABLE)->dropForeignKey(self::COLUMN, self::CONSTRAINT)->save();
            $this->notice('已删除外键 ' . self::CONSTRAINT);
            $dropped = true;
        } catch (\Throwable $e) {
            $this->notice(sprintf(
                '按名称删除外键 %s 失败，尝试按列名删除：%s',
                self::CONSTRAINT,
                $e->getMessage()
            ));
        }

        if (!$dropped) {
            try {
                $this->table(self::TABLE)->dropForeignKey(self::COLUMN)->save();
                $this->notice('已按列名删除外键 ' . self::TABLE . '.' . self::COLUMN);
            } catch (\Throwable $e) {
                // 外键可能本就未创建成功，只记录不中断
                $this->notice('删除外键 ' . self::TABLE . '.' . self::COLUMN . ' 失败（已忽略）：' . $e->getMessage());
            }
        }

        $this->dropAutoIndexSafely(self::CONSTRAINT);
    }

    /**
     * 清理 MySQL 为外键自动生成的索引（索引名与约束名相同）
     *
     * 只在「索引名恰好等于本迁移的外键名」时删除，避免误删业务已有索引：
     * 本表已有以 tenant_id 打头的索引（uk_tenant_name / idx_tenant_site / idx_collection_name），
     * MySQL 会直接复用其中一个，正常情况下不会新建 fk_* 索引，这里因此多数情况是空操作。
     */
    protected function dropAutoIndexSafely(string $indexName): void
    {
        try {
            if (!$this->table(self::TABLE)->hasIndexByName($indexName)) {
                return;
            }

            $this->table(self::TABLE)->removeIndexByName($indexName)->save();
            $this->notice('已删除外键自动索引 ' . self::TABLE . '.' . $indexName);
        } catch (\Throwable $e) {
            $this->notice('删除外键自动索引 ' . self::TABLE . '.' . $indexName . ' 失败（已忽略）：' . $e->getMessage());
        }
    }

    /**
     * 判断某列上是否已存在外键约束
     *
     * 按「表 + 列」判断而不是按约束名：不同环境下同一列的外键约束名可能不同
     * （未显式命名时形如 lowcode_forms_ibfk_1），只按名字判断会把同一列的外键重复创建一遍。
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
     * 输出迁移日志
     */
    protected function notice(string $message): void
    {
        $output = $this->getOutput();
        if ($output !== null) {
            $output->writeln('[lowcode-forms-tenant] ' . $message);
        }
    }
}
