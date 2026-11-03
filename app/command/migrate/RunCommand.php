<?php

declare(strict_types=1);

namespace app\command\migrate;

use think\Console;
use think\console\Input;
use think\console\Output;
use think\migration\command\migrate\Run as BaseRun;

/**
 * migrate:run 覆盖实现（R6）
 *
 * 目的：让**本次执行**新增的迁移行被标记为「最新批次」（MAX(batch) + 1），
 * 从而使无参 migrate:rollback 能按批次整体回滚。
 *
 * 为什么需要外部回填：vendor 的 PdoAdapter::migrated() 使用显式列清单 INSERT，
 * 不会写入 batch 列（走表默认值 0），因此批次号必须在此处补写。
 *
 * 注册位置：config/console.php（数字键）。注意**不能**用
 * `'migrate:run' => RunCommand::class` 的字符串键，原因见下方 configure() 的说明。
 */
class RunCommand extends BaseRun
{
    /**
     * {@inheritdoc}
     *
     * 说明（覆盖机制，勿删）：vendor 的 think\migration\Service 使用
     * think\Console::starting() 注册同名命令，而这些回调在 Console::start() 中执行，
     * **晚于** Console::loadCommands()（读取 config/console.php 的那一步）。
     * 因此仅靠 config/console.php 注册同名命令**无法**覆盖 vendor 命令（实测 vendor 仍生效）。
     * 这里借「本命令在 loadCommands() 期间被实例化」的时机，追加一个更晚的 starting 回调，
     * 在 vendor 之后重新登记本命令，从而实现覆盖（不修改 vendor、不修改框架）。
     */
    protected function configure()
    {
        parent::configure();

        static $registered = false;
        if (!$registered) {
            $registered = true;
            Console::starting(function (Console $console) {
                $console->addCommand(static::class);
            });
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(Input $input, Output $output)
    {
        // 0) 记录本次运行的起始时间：批次标记的兜底约束（见 markNewBatch 说明）
        $runStartedAt = date('Y-m-d H:i:s');

        // 1) 记录执行前的已应用版本集合
        $before = $this->appliedVersions();

        // 2) 交给 vendor 原逻辑执行迁移（保持 --target / --date 语义不变）
        parent::execute($input, $output);

        // 3) 收尾：把本次新增的迁移行标记为最新批次
        $this->markNewBatch($before, $runStartedAt, $output);
    }

    /**
     * 读取当前 migrations 表中已应用的版本列表
     *
     * 任何异常（表不存在 / 库不可用）都返回空数组，避免影响 migrate:run 主流程。
     *
     * @return int[]
     */
    private function appliedVersions(): array
    {
        try {
            $adapter   = $this->getAdapter();
            $tableName = $this->resolveTableName();

            if (!$adapter->hasTable($tableName)) {
                return [];
            }

            $rows = $adapter->fetchAll(sprintf('SELECT version FROM %s', $tableName));

            return array_map('intval', array_column($rows, 'version'));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 解析 migrations 表名
     *
     * Phinx 会用 TablePrefixAdapter 包装真实适配器，而该包装类没有转发
     * getSchemaTableName()，故需先解开包装链（实测未解包装会抛
     * “Call to undefined method TablePrefixAdapter::getSchemaTableName()”）。
     */
    private function resolveTableName(): string
    {
        $adapter = $this->getAdapter();

        while ($adapter instanceof \Phinx\Db\Adapter\WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }

        return $adapter->getSchemaTableName();
    }

    /**
     * 把本次新增的迁移行更新到最新批次
     *
     * 批次号在同一条 UPDATE 中通过子查询计算（MAX(batch) + 1），
     * 避免「先查后写」的竞态窗口。
     *
     * **start_time 双重约束（安全关键）**：`appliedVersions()` 在读取失败时会返回空数组，
     * 此时 `array_diff(执行后, [])` 等于「全部已应用版本」——若只用版本差集打批次，
     * 全部历史迁移会被重新归入同一个批次，之后一次无参 rollback 就会试图回滚**全部**迁移。
     * 因此额外要求 `start_time >= 本次运行起始时间`：只有本次运行真正写入的行才可能命中。
     *
     * **batch = 0 唯一约束（全新库场景，安全关键）**：全新库首次 `migrate:run` 时
     * 所有迁移在同一批执行，`start_time` 全部落在运行窗口内，上一条约束无法区分。
     * 而批次回填（`20251221000001`）已把先执行的行分好了批次——若此时仍按版本差集整体
     * UPDATE，会把它们从各自批次重打成同一批，导致首次无参 rollback 一次回滚近整个 schema。
     * 故只允许标记「从未打过批次」（`batch = 0`）的行。
     *
     * @param int[]  $before       执行前的已应用版本
     * @param string $runStartedAt 本次运行起始时间（Y-m-d H:i:s）
     * @param Output $output       命令输出
     */
    private function markNewBatch(array $before, string $runStartedAt, Output $output): void
    {
        try {
            $adapter   = $this->getAdapter();
            $tableName = $this->resolveTableName();

            // 迁移尚未提供 batch 列时无批次语义可言，跳过标记但不中断 migrate:run
            if (!$adapter->hasTable($tableName) || !$adapter->hasColumn($tableName, 'batch')) {
                $output->writeln(
                    '    <comment>提示：migrations 表缺少 batch 列（尚未执行批次迁移），本次跳过批次标记</comment>'
                );
                return;
            }

            $newVersions = array_values(array_diff($this->appliedVersions(), $before));
            if ([] === $newVersions) {
                return;
            }

            sort($newVersions);
            $versionList = implode(',', array_map('intval', $newVersions));

            // 单条语句内计算 MAX(batch) + 1：MySQL 不允许 UPDATE 直接子查询同表，
            // 故用派生表（派生表会物化），语义上仍是一次原子查询。
            // start_time 约束见方法注释：防止「读失败 → 全部重打批次」的灾难性误判。
            // $runStartedAt 由 date() 生成、格式固定，不存在注入面，故直接内联；
            // 这样可避免依赖 TablePrefixAdapter 未转发的 getConnection()/quote()。
            $sql = sprintf(
                "UPDATE %s SET batch = (SELECT next_batch FROM (SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM %s) AS calc) WHERE version IN (%s) AND start_time >= '%s' AND batch = 0",
                $tableName,
                $tableName,
                $versionList,
                $runStartedAt
            );
            $adapter->execute($sql);

            $row     = $adapter->fetchRow(sprintf(
                'SELECT batch FROM %s WHERE version IN (%s) ORDER BY version ASC LIMIT 1',
                $tableName,
                $versionList
            ));
            $batchNo = is_array($row) && isset($row['batch']) ? (int) $row['batch'] : 0;

            $output->writeln(sprintf(
                '    <info>批次标记</info>：%d 条新迁移已归入 batch %d（%s）',
                count($newVersions),
                $batchNo,
                $versionList
            ));
        } catch (\Throwable $e) {
            // 批次标记失败不应导致迁移结果不可用，仅提示
            $output->writeln('    <error>警告：批次标记失败 —— ' . $e->getMessage() . '</error>');
        }
    }
}
