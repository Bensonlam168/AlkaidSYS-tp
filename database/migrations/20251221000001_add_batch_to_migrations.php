<?php

use think\migration\Migrator;

/**
 * 为 migrations 表增加 batch 列并回填历史批次（R6）
 *
 * 背景：
 *   vendor 版 migrate:rollback 无参时只回滚「版本号最大的那一个迁移」，
 *   与 Laravel 的「回滚最近一批」语义不同，容易打错迁移。
 *   本迁移补齐 batch 列，并由 app\command\migrate\* 系列命令维护其取值。
 *
 * 注意：
 *   1. 本文件**必须**使用 up()/down()，不能使用 change()：
 *      Phinx 检测到 change() 会忽略 down()，导致回滚成为死代码。
 *   2. 本迁移自身那一行是在 up() 执行完之后才由 Phinx 插入的（batch 走默认 0），
 *      由 app\command\migrate\RunCommand 在 migrate:run 收尾时修正为最新批次。
 */
class AddBatchToMigrations extends Migrator
{
    /** 相邻两条 start_time 间隔超过该秒数即视为新批次（秒） */
    private const BATCH_GAP_SECONDS = 60;

    public function up(): void
    {
        $adapter   = $this->getAdapter();
        $tableName = $this->resolveTableName();

        // 首次部署时 migrations 表由 Phinx 自动创建，此处若不存在则无需处理
        if (!$adapter->hasTable($tableName)) {
            $this->writeLog(sprintf('表 %s 尚不存在，跳过 batch 列创建与回填', $tableName));
            return;
        }

        // 1) 加列（幂等：已存在则跳过）
        if (!$adapter->hasColumn($tableName, 'batch')) {
            $this->execute(sprintf(
                'ALTER TABLE %s ADD COLUMN batch INT UNSIGNED NOT NULL DEFAULT 0',
                $tableName
            ));
            $this->writeLog(sprintf('已为 %s 增加 batch 列', $tableName));
        } else {
            $this->writeLog(sprintf('%s 已存在 batch 列，跳过加列', $tableName));
        }

        // 2) 回填历史批次：按执行时间排序，与上一条间隔 > 60 秒则开新批次
        $rows = $this->fetchAll(sprintf(
            'SELECT version, start_time FROM %s ORDER BY start_time ASC, version ASC',
            $tableName
        ));

        $batch     = 0;
        $prevTs    = null;
        $summary   = [];
        $sumRanges = [];

        foreach ($rows as $row) {
            $ts = null !== $row['start_time'] ? (int) strtotime((string) $row['start_time']) : null;

            if (0 === $batch) {
                // 第一条：从批次 1 开始
                $batch = 1;
            } elseif (null !== $ts && null !== $prevTs && ($ts - $prevTs) > self::BATCH_GAP_SECONDS) {
                $batch++;
            }

            $version = (int) $row['version'];
            $this->execute(
                sprintf('UPDATE %s SET batch = ? WHERE version = ?', $tableName),
                [$batch, $version]
            );

            $summary[$batch] = ($summary[$batch] ?? 0) + 1;
            if (!isset($sumRanges[$batch])) {
                $sumRanges[$batch] = [$version, $version];
            } else {
                $sumRanges[$batch][0] = min($sumRanges[$batch][0], $version);
                $sumRanges[$batch][1] = max($sumRanges[$batch][1], $version);
            }

            if (null !== $ts) {
                $prevTs = $ts;
            }
        }

        // 3) 打印回填摘要
        $this->writeLog(sprintf(
            '历史批次回填完成：共 %d 条迁移，划分为 %d 个批次',
            count($rows),
            $batch
        ));
        foreach ($summary as $no => $count) {
            $this->writeLog(sprintf(
                '  batch %d：%d 条（%d ~ %d）',
                $no,
                $count,
                $sumRanges[$no][0],
                $sumRanges[$no][1]
            ));
        }
    }

    public function down(): void
    {
        $adapter   = $this->getAdapter();
        $tableName = $this->resolveTableName();

        // hasColumn 守卫：表或列不存在时不做任何事
        if ($adapter->hasTable($tableName) && $adapter->hasColumn($tableName, 'batch')) {
            $this->execute(sprintf('ALTER TABLE %s DROP COLUMN batch', $tableName));
            $this->writeLog(sprintf('已移除 %s.batch 列', $tableName));
        }
    }

    /**
     * 解析 migrations 表名
     *
     * Phinx 会用 TablePrefixAdapter 包装真实适配器，而该包装类没有转发
     * getSchemaTableName()，因此需要先解开包装链再取表名（此前实测会报
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
     * 输出一行迁移日志（迁移可能被无输出环境实例化，故做空值保护）
     */
    private function writeLog(string $message): void
    {
        if (null !== $this->output) {
            $this->output->writeln('    <comment>' . $message . '</comment>');
        }
    }
}
