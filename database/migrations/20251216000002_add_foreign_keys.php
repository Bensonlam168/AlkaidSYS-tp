<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 添加工作流引擎外键约束
 * Add workflow engine foreign key constraints
 *
 * 来源：plugins/workflow-engine/migrations/20251216000002_add_foreign_keys.php
 *
 * 本次改造点：
 * 1. 每个 addForeignKey 都包 try/catch (\Throwable)，单条外键失败只记录日志，
 *    不会中断整个迁移（原实现中 workflow_instances.definition_id 的外键失败会让迁移 aborts）；
 * 2. 外键显式命名，便于排查与回滚；
 * 3. 补充 down()，删除本迁移添加的外键；失败仅记录（表被删除时 MySQL 会自动清理外键）。
 */
class AddForeignKeys extends Migrator
{
    /**
     * 外键定义清单：[表名, 列名, 被引用表, 被引用列, 删除行为, 更新行为]
     *
     * 逐条独立执行，失败只告警；创建顺序无强依赖。
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private const FOREIGN_KEYS = [
        // 工作流实例 -> 工作流定义
        ['workflow_instances', 'definition_id', 'workflow_definitions', 'id', 'CASCADE', 'CASCADE'],
        // 工作流实例 -> 用户（发起人）
        ['workflow_instances', 'created_by', 'users', 'id', 'SET NULL', 'CASCADE'],
        // 工作流任务 -> 工作流实例
        ['workflow_tasks', 'instance_id', 'workflow_instances', 'id', 'CASCADE', 'CASCADE'],
        // 工作流任务 -> 用户（处理人、创建人）
        ['workflow_tasks', 'assignee', 'users', 'id', 'SET NULL', 'CASCADE'],
        ['workflow_tasks', 'created_by', 'users', 'id', 'SET NULL', 'CASCADE'],
        // 工作流触发器 -> 工作流定义
        ['workflow_triggers', 'definition_id', 'workflow_definitions', 'id', 'CASCADE', 'CASCADE'],
        // 工作流触发器 -> 用户（创建人）
        ['workflow_triggers', 'created_by', 'users', 'id', 'SET NULL', 'CASCADE'],
        // 工作流事件日志 -> 实例 / 任务 / 用户
        ['workflow_event_logs', 'instance_id', 'workflow_instances', 'id', 'CASCADE', 'CASCADE'],
        ['workflow_event_logs', 'task_id', 'workflow_tasks', 'id', 'SET NULL', 'CASCADE'],
        ['workflow_event_logs', 'user_id', 'users', 'id', 'SET NULL', 'CASCADE'],
    ];

    /**
     * 添加外键约束
     *
     * 刻意使用 up()/down() 而非 change()：Phinx 在 change() 存在时会忽略显式 down()，
     * 改为反向重放 change()，无法保证外键这种“可能创建失败”的操作能被正确撤销。
     */
    public function up(): void
    {
        foreach (self::FOREIGN_KEYS as $foreignKey) {
            [$tableName, $column, $referencedTable, $referencedColumn, $onDelete, $onUpdate] = $foreignKey;

            $this->addForeignKeySafely($tableName, $column, $referencedTable, $referencedColumn, $onDelete, $onUpdate);
        }
    }

    /**
     * 回滚迁移：删除本迁移添加的外键
     *
     * 删除失败只记录日志，不阻断回滚：表最终会被迁移 1 的 down() 删除，
     * MySQL 会随表自动清理其上的外键约束。
     */
    public function down(): void
    {
        foreach (array_reverse(self::FOREIGN_KEYS) as $foreignKey) {
            [$tableName, $column] = $foreignKey;

            $this->dropForeignKeySafely($tableName, $column);
        }
    }

    /**
     * 安全添加外键：失败仅记录日志
     */
    protected function addForeignKeySafely(
        string $tableName,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $onDelete,
        string $onUpdate
    ): void {
        if (!$this->hasTable($tableName)) {
            $this->notice("跳过外键 {$tableName}.{$column}：表 {$tableName} 不存在");

            return;
        }

        if (!$this->hasTable($referencedTable)) {
            $this->notice("跳过外键 {$tableName}.{$column}：被引用表 {$referencedTable} 不存在");

            return;
        }

        try {
            $this->table($tableName)
                ->addForeignKey(
                    $column,
                    $referencedTable,
                    $referencedColumn,
                    [
                        'delete' => $onDelete,
                        'update' => $onUpdate,
                        // 显式命名，便于 DBA 排查与回滚定位
                        'constraint' => $this->foreignKeyName($tableName, $column),
                    ]
                )
                ->save();

            $this->notice("已创建外键 {$tableName}.{$column} -> {$referencedTable}.{$referencedColumn}");
        } catch (\Throwable $e) {
            // 常见可容忍原因：列类型/符号不一致（MySQL 3780）、被引用列缺少索引、用户表 id 为 int unsigned 等
            $this->notice("创建外键 {$tableName}.{$column} 失败（已跳过）：" . $e->getMessage());
        }
    }

    /**
     * 安全删除外键：先按约定名删除，失败再按列名删除，均失败仅记录日志
     */
    protected function dropForeignKeySafely(string $tableName, string $column): void
    {
        if (!$this->hasTable($tableName)) {
            return;
        }

        try {
            $this->table($tableName)
                ->dropForeignKey($column, $this->foreignKeyName($tableName, $column))
                ->save();
            $this->notice("已删除外键 {$tableName}.{$column}");

            return;
        } catch (\Throwable $e) {
            $this->notice("按名称删除外键 {$tableName}.{$column} 失败，尝试按列名删除：" . $e->getMessage());
        }

        try {
            $this->table($tableName)->dropForeignKey($column)->save();
            $this->notice("已按列名删除外键 {$tableName}.{$column}");
        } catch (\Throwable $e) {
            // 外键可能本就未创建成功；表被 drop 时 MySQL 会自动清理，因此只记录
            $this->notice("删除外键 {$tableName}.{$column} 失败（已忽略，随表删除自动清理）：" . $e->getMessage());
        }
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
            $output->writeln('[workflow] ' . $message);
        }
    }
}
