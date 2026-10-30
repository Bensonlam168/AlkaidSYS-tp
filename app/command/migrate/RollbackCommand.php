<?php

declare(strict_types=1);

namespace app\command\migrate;

use Phinx\Migration\MigrationInterface;
use think\Console;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;
use think\migration\command\migrate\Rollback as BaseRollback;

/**
 * migrate:rollback 覆盖实现（R6）
 *
 * 语义约定：
 *   - 无参                    → 回滚**最近一个批次**内的全部迁移（Laravel 式批次语义）
 *   - --target / --date       → 完全沿用 vendor 语义（回滚到指定版本/日期）
 *   - --list                  → 仅预览无参会回滚哪些版本，不做任何变更
 *
 * 降级策略：
 *   migrations 表没有 batch 列，或全部 batch 均为默认 0（未启用批次）时，
 *   退回 vendor 原有语义并给出警告，避免行为静默改变。
 */
class RollbackCommand extends BaseRollback
{
    /**
     * 受保护的迁移版本：批次回滚**永不**自动回滚它们
     *
     * 为什么需要：`20251221000001_add_batch_to_migrations` 自己就是一个迁移，
     * 它必然是应用时那个批次的成员（实测为最后一批）。若允许批次回滚命中它，
     * 第一次无参 `migrate:rollback` 就会 DROP 掉 `migrations.batch` 列，
     * 导致批次语义**静默失效**，退回会打错迁移的 vendor 行为 —— 正是本次要消除的危险。
     *
     * 需要回滚它时，必须显式 `--target=20251221000001`（走 vendor 路径），
     * 以保证「拆掉批次能力」是一个明确的人工决定，而不是一次顺手回滚的副作用。
     *
     * @var array<int, int>
     */
    private const PROTECTED_VERSIONS = [20251221000001];

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

        $this->addOption(
            '--list',
            null,
            InputOption::VALUE_NONE,
            'List the migrations a batch rollback would revert (dry run, no changes)'
        );

        static $registered = false;
        if (!$registered) {
            $registered = true;
            Console::starting(function (Console $console) {
                $console->addCommand(static::class);
            });
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(Input $input, Output $output)
    {
        // --list：只预览，不执行任何回滚
        if ($input->getOption('list')) {
            $this->previewBatchRollback();
            return;
        }

        parent::execute($input, $output);
    }

    /**
     * 回滚指定版本或最近批次
     *
     * @param int|string|null $version 目标版本（null 表示未指定 --target）
     * @param bool            $force   是否忽略 breakpoint
     */
    protected function rollback($version = null, $force = false)
    {
        // 指定了 --target / --date 时保持 vendor 语义不变
        if (null !== $version) {
            parent::rollback($version, $force);
            return;
        }

        $state = $this->batchState();

        // 未启用批次语义：退回 vendor 行为并警告
        if (!$state['enabled']) {
            $this->output->writeln(sprintf(
                '<comment>Warning: %s</comment>',
                $state['reason']
            ));
            $this->output->writeln('<comment>已退回 vendor 语义：只回滚版本号最大的那一个迁移。</comment>');
            parent::rollback(null, $force);
            return;
        }

        if ([] === $state['rows']) {
            if ('' !== $state['note']) {
                $this->output->writeln(sprintf('<comment>Warning: %s</comment>', $state['note']));
                $this->output->writeln(sprintf(
                    '<error>No migrations to rollback</error>：batch %d 仅包含受保护迁移，已跳过。',
                    $state['max_batch']
                ));
                return;
            }

            $this->output->writeln('<error>No migrations to rollback</error>');
            return;
        }

        if ('' !== $state['note']) {
            $this->output->writeln(sprintf('<comment>Warning: %s</comment>', $state['note']));
        }

        $this->output->writeln(sprintf(
            '    <info>batch %d</info> 共 %d 条迁移待回滚（按执行逆序）',
            $state['max_batch'],
            count($state['rows'])
        ));

        $migrations = $this->getMigrations();
        $reverted   = 0;

        foreach ($state['rows'] as $row) {
            $targetVersion = (int) $row['version'];

            // 迁移文件缺失时无法回滚，明确提示而不是静默跳过
            if (!isset($migrations[$targetVersion])) {
                $this->output->writeln(sprintf(
                    '<comment>Warning: 版本 %d 的迁移文件不存在，已跳过</comment>',
                    $targetVersion
                ));
                continue;
            }

            // 保留 vendor 的 breakpoint 逻辑：遇到断点即停止后续回滚
            if (0 != $row['breakpoint'] && !$force) {
                $this->output->writeln('<error>Breakpoint reached. Further rollbacks inhibited.</error>');
                break;
            }

            $this->executeMigration($migrations[$targetVersion], MigrationInterface::DOWN);
            $reverted++;
        }

        $this->output->writeln(sprintf(
            '    <info>批次回滚完成</info>：已回滚 %d 条迁移',
            $reverted
        ));
    }

    /**
     * 预览无参会回滚哪些迁移（--list），不执行任何变更
     */
    private function previewBatchRollback(): void
    {
        $state = $this->batchState();

        if (!$state['enabled']) {
            $this->output->writeln(sprintf('<comment>Warning: %s</comment>', $state['reason']));
            $this->output->writeln('<comment>批次语义未启用，无参 rollback 将退回 vendor 语义（只回滚版本号最大的那一个迁移）。</comment>');

            // 仍给出 vendor 语义下的预览，便于对照
            $versions = array_keys($this->getVersionLog());
            sort($versions);
            if ([] !== $versions) {
                $this->output->writeln(sprintf(
                    '    退回 vendor 语义后将被回滚的版本：<info>%d</info>',
                    (int) end($versions)
                ));
            }
            return;
        }

        if ([] === $state['rows']) {
            if ('' !== $state['note']) {
                $this->output->writeln(sprintf('<comment>Warning: %s</comment>', $state['note']));
                $this->output->writeln(sprintf(
                    '<error>No migrations to rollback</error>：batch %d 仅包含受保护迁移，已跳过。',
                    $state['max_batch']
                ));
                return;
            }

            $this->output->writeln('<error>No migrations to rollback</error>');
            return;
        }

        if ('' !== $state['note']) {
            $this->output->writeln(sprintf('<comment>Warning: %s</comment>', $state['note']));
        }

        $this->output->writeln(sprintf(
            '无参 migrate:rollback 将回滚 <info>batch %d</info> 的全部 %d 条迁移（按执行逆序）：',
            $state['max_batch'],
            count($state['rows'])
        ));

        $migrations = $this->getMigrations();
        foreach ($state['rows'] as $index => $row) {
            $targetVersion = (int) $row['version'];
            $name          = isset($migrations[$targetVersion])
                ? $migrations[$targetVersion]->getName()
                : (string) ($row['migration_name'] ?? '');
            $this->output->writeln(sprintf(
                '    %d) %d  %s  （start_time: %s，breakpoint: %d）',
                $index + 1,
                $targetVersion,
                $name,
                (string) ($row['start_time'] ?? '-'),
                0 == $row['breakpoint'] ? 0 : 1
            ));
        }

        $this->output->writeln('');
        $this->output->writeln('<comment>--list 为预览模式，未执行任何回滚。</comment>');
    }

    /**
     * 采集批次状态
     *
     * 会剔除 `PROTECTED_VERSIONS` 中的受保护迁移：若最新批次全部由受保护迁移组成，
     * 则返回空的 rows 并给出 note 说明，**不会**退而回滚更早的批次
     * （那样会在用户以为在回滚最新改动时误删旧数据）。
     *
     * @return array{enabled:bool, reason:string, max_batch:int, rows:array, note:string}
     */
    private function batchState(): array
    {
        $state = ['enabled' => false, 'reason' => '', 'max_batch' => 0, 'rows' => [], 'note' => ''];

        try {
            $adapter   = $this->getAdapter();
            $tableName = $this->resolveTableName();

            if (!$adapter->hasTable($tableName)) {
                $state['reason'] = 'migrations 表不存在，未启用批次语义。';
                return $state;
            }

            if (!$adapter->hasColumn($tableName, 'batch')) {
                $state['reason'] = 'migrations 表缺少 batch 列，未启用批次语义（请先执行 migrate:run 应用批次迁移）。';
                return $state;
            }

            $row      = $adapter->fetchRow(sprintf('SELECT MAX(batch) AS max_batch FROM %s', $tableName));
            $maxBatch = is_array($row) && null !== ($row['max_batch'] ?? null) ? (int) $row['max_batch'] : 0;

            // 全为默认 0 表示批次尚未回填，不可据此回滚
            if ($maxBatch <= 0) {
                $state['reason'] = 'migrations 表的 batch 全为默认值 0（批次尚未回填），未启用批次语义。';
                return $state;
            }

            // 逆执行序：start_time DESC, version DESC，保证外键/依赖安全
            $rows = $adapter->fetchAll(sprintf(
                'SELECT version, migration_name, start_time, breakpoint FROM %s WHERE batch = %d ORDER BY start_time DESC, version DESC',
                $tableName,
                $maxBatch
            ));

            $state['max_batch'] = $maxBatch;
            $state['enabled']   = true;

            // 剔除受保护迁移
            $protected = [];
            $revertible = [];
            foreach ($rows as $item) {
                if ($this->isProtected((int) $item['version'])) {
                    $protected[] = (int) $item['version'];
                } else {
                    $revertible[] = $item;
                }
            }

            $state['rows'] = $revertible;

            if ([] !== $protected) {
                $state['note'] = sprintf(
                    '最新批次 batch %d 中的受保护迁移已跳过：%s（如需回滚它们，请显式使用 --target）',
                    $maxBatch,
                    implode(', ', $protected)
                );

                // 若最新批次被受保护迁移占满，提示下一个「真正可回滚」的批次，
                // 否则用户只知道「不能回滚」，不知道该怎么办。
                if ([] === $revertible) {
                    $hint = $this->nextRevertibleBatchHint($adapter, $tableName, $maxBatch);
                    if (null !== $hint) {
                        $state['note'] .= '；' . $hint;
                    }
                }
            }

            return $state;
        } catch (\Throwable $e) {
            $state['reason'] = '读取批次信息失败（' . $e->getMessage() . '），未启用批次语义。';
            return $state;
        }
    }

    /**
     * 判断迁移是否受保护（不允许被批次回滚自动命中）
     */
    private function isProtected(int $version): bool
    {
        return in_array($version, self::PROTECTED_VERSIONS, true);
    }

    /**
     * 找出「下一个不含受保护迁移的最高批次」，仅用于提示
     *
     * 刻意不自动回滚它：用户以为在撤销最新改动时误删更早的数据，
     * 与「打错迁移」属于同一类风险。这里只告诉他目标在哪。
     */
    private function nextRevertibleBatchHint($adapter, string $tableName, int $maxBatch): ?string
    {
        $rows = $adapter->fetchAll(sprintf(
            'SELECT version, batch FROM %s WHERE batch < %d ORDER BY batch DESC, version DESC',
            $tableName,
            $maxBatch
        ));

        $candidateBatch = null;
        $candidateRows  = [];
        foreach ($rows as $row) {
            $batch = (int) $row['batch'];
            if ($this->isProtected((int) $row['version'])) {
                continue;
            }
            if (null === $candidateBatch) {
                $candidateBatch = $batch;
            }
            if ($batch === $candidateBatch) {
                $candidateRows[] = (int) $row['version'];
            }
        }

        if (null === $candidateBatch || [] === $candidateRows) {
            return null;
        }

        sort($candidateRows);

        return sprintf(
            '下一个可回滚批次为 batch %d（%d 条：%d ~ %d）；如需回滚请显式使用 --target 指定目标版本',
            $candidateBatch,
            count($candidateRows),
            $candidateRows[0],
            end($candidateRows)
        );
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
}
