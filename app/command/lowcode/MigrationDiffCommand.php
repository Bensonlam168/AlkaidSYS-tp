<?php

declare(strict_types=1);

namespace app\command\lowcode;

use app\command\base\LowcodeCommand;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * Migration Diff Command | 迁移差异命令
 *
 * Compares database schema with Collection schema and generates migration diff.
 * 比较数据库Schema与Collection Schema并生成迁移差异。
 *
 * Usage: php think lowcode:migration:diff Product --out=migration.sql
 * 用法: php think lowcode:migration:diff Product --out=migration.sql
 *
 * @package app\command\lowcode
 */
class MigrationDiffCommand extends LowcodeCommand
{
    /**
     * Configure command | 配置命令
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('lowcode:migration:diff')
            ->setDescription('Generate migration diff between DB and Collection schema | 生成数据库与Collection Schema的差异')
            ->addArgument('collection', Argument::OPTIONAL, 'Collection name (empty for all) | Collection名称（留空表示全部）')
            ->addOption('out', 'o', Option::VALUE_OPTIONAL, 'Output SQL file path | 输出SQL文件路径')
            ->addOption('check', 'c', Option::VALUE_NONE, 'Check only, return non-zero if diff exists | 仅检查，存在差异返回非零')
            ->addOption('report', 'r', Option::VALUE_OPTIONAL, 'Output JSON report path | 输出JSON报告路径')
            ->addOption('all', 'a', Option::VALUE_NONE, 'Check all collections | 检查所有Collection')
            ->addOption('tenant-id', null, Option::VALUE_OPTIONAL, 'Tenant ID (default: 0) | 租户ID（默认：0）', '0');
    }

    /**
     * Execute command | 执行命令
     *
     * @param Input $input Input instance | 输入实例
     * @param Output $output Output instance | 输出实例
     * @return int Exit code | 退出码
     */
    protected function execute(Input $input, Output $output): int
    {
        try {
            $this->section('Checking Migration Diff | 检查迁移差异');

            $collectionName = $input->getArgument('collection');
            $checkAll = $input->getOption('all');
            $checkOnly = $input->getOption('check');
            $outFile = $input->getOption('out');
            $reportFile = $input->getOption('report');
            $tenantId = $this->getTenantId();

            $collectionManager = app()->make(CollectionManager::class);

            // Get collections to check | 获取要检查的Collection
            $collections = [];
            if ($checkAll || empty($collectionName)) {
                $this->info('Checking all collections...');
                $allCollections = $collectionManager->list($tenantId);
                foreach ($allCollections['list'] as $col) {
                    $collections[] = $collectionManager->get($col['name'], $tenantId);
                }
            } else {
                $collection = $collectionManager->get($collectionName, $tenantId);
                if (!$collection) {
                    $this->error("Collection '{$collectionName}' not found.");
                    return 1;
                }
                $collections[] = $collection;
            }

            if (empty($collections)) {
                $this->warning('No collections found.');
                return 0;
            }

            // Check each collection | 检查每个Collection
            $diffs = [];
            $hasDiff = false;

            foreach ($collections as $collection) {
                $diff = $this->checkCollectionDiff($collection);
                if (!empty($diff['changes'])) {
                    $hasDiff = true;
                    $diffs[] = $diff;
                    $this->warning("Diff found in collection: {$collection->getName()}");
                } else {
                    $this->success("No diff in collection: {$collection->getName()}");
                }
            }

            // Generate output | 生成输出
            if ($outFile && !$checkOnly) {
                $this->generateSqlFile($diffs, $outFile);
                $this->success("SQL diff written to: {$outFile}");
            }

            if ($reportFile) {
                $this->generateReportFile($diffs, $reportFile);
                $this->success("Report written to: {$reportFile}");
            }

            // Display summary | 显示摘要
            $this->displaySummary($diffs);

            // Return exit code | 返回退出码
            if ($checkOnly && $hasDiff) {
                $this->warning('Schema drift detected!');
                return 1;
            }

            return 0;
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Check collection diff | 检查Collection差异
     *
     * @param \Domain\Lowcode\Collection\Interfaces\CollectionInterface $collection Collection instance | Collection实例
     * @return array Diff result | 差异结果
     */
    protected function checkCollectionDiff($collection): array
    {
        $tableName = $collection->getTableName();
        $changes = [];

        // Check if table exists | 检查表是否存在
        $tableExists = $this->tableExists($tableName);

        if (!$tableExists) {
            $changes[] = [
                'type' => 'create_table',
                'table' => $tableName,
                'message' => "Table '{$tableName}' does not exist in database",
            ];
        } else {
            // Check field differences | 检查字段差异
            $dbColumns = $this->getTableColumns($tableName);
            $collectionFields = $collection->getFields();

            foreach ($collectionFields as $field) {
                $fieldName = $field->getName();
                if (!isset($dbColumns[$fieldName])) {
                    $changes[] = [
                        'type' => 'add_column',
                        'table' => $tableName,
                        'column' => $fieldName,
                        'field_type' => $field->getType(),
                        'message' => "Column '{$fieldName}' missing in database",
                    ];
                }
            }

            // Check for extra columns in DB | 检查数据库中的额外列
            $systemColumns = ['id', 'tenant_id', 'site_id', 'created_at', 'updated_at', 'deleted_at'];
            foreach ($dbColumns as $columnName => $columnInfo) {
                if (in_array($columnName, $systemColumns, true)) {
                    continue;
                }

                $found = false;
                foreach ($collectionFields as $field) {
                    if ($field->getName() === $columnName) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $changes[] = [
                        'type' => 'remove_column',
                        'table' => $tableName,
                        'column' => $columnName,
                        'message' => "Column '{$columnName}' exists in database but not in Collection",
                    ];
                }
            }
        }

        return [
            'collection' => $collection->getName(),
            'table' => $tableName,
            'changes' => $changes,
        ];
    }

    /**
     * Check if table exists | 检查表是否存在
     *
     * @param string $tableName Table name | 表名
     * @return bool Table exists | 表是否存在
     */
    protected function tableExists(string $tableName): bool
    {
        try {
            $result = Db::query("SHOW TABLES LIKE '{$tableName}'");
            return !empty($result);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get table columns | 获取表列
     *
     * @param string $tableName Table name | 表名
     * @return array Columns | 列
     */
    protected function getTableColumns(string $tableName): array
    {
        $columns = [];
        try {
            $result = Db::query("SHOW COLUMNS FROM `{$tableName}`");
            foreach ($result as $column) {
                $columns[$column['Field']] = $column;
            }
        } catch (\Throwable $e) {
            // Table doesn't exist | 表不存在
        }
        return $columns;
    }

    /**
     * Generate SQL file | 生成SQL文件
     *
     * @param array $diffs Diffs | 差异
     * @param string $outFile Output file | 输出文件
     * @return void
     */
    protected function generateSqlFile(array $diffs, string $outFile): void
    {
        $sql = '-- Migration Diff Generated at ' . date('Y-m-d H:i:s') . "\n\n";

        foreach ($diffs as $diff) {
            if (empty($diff['changes'])) {
                continue;
            }

            $sql .= "-- Collection: {$diff['collection']}\n";
            $sql .= "-- Table: {$diff['table']}\n\n";

            foreach ($diff['changes'] as $change) {
                $sql .= "-- {$change['message']}\n";
                $sql .= "-- TODO: Generate appropriate SQL for {$change['type']}\n\n";
            }
        }

        file_put_contents($outFile, $sql);
    }

    /**
     * Generate report file | 生成报告文件
     *
     * @param array $diffs Diffs | 差异
     * @param string $reportFile Report file | 报告文件
     * @return void
     */
    protected function generateReportFile(array $diffs, string $reportFile): void
    {
        $report = [
            'generated_at' => date('Y-m-d H:i:s'),
            'total_collections' => count($diffs),
            'collections_with_diff' => count(array_filter($diffs, fn ($d) => !empty($d['changes']))),
            'diffs' => $diffs,
        ];

        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Display summary | 显示摘要
     *
     * @param array $diffs Diffs | 差异
     * @return void
     */
    protected function displaySummary(array $diffs): void
    {
        $this->output->writeln('');
        $this->info('Summary:');
        $this->output->writeln('  Total collections checked: ' . count($diffs));

        $withDiff = array_filter($diffs, fn ($d) => !empty($d['changes']));
        $this->output->writeln('  Collections with diff: ' . count($withDiff));

        $totalChanges = array_sum(array_map(fn ($d) => count($d['changes']), $diffs));
        $this->output->writeln("  Total changes: {$totalChanges}");
        $this->output->writeln('');
    }
}
