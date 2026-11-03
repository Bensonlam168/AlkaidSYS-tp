<?php

declare(strict_types=1);

namespace app\command\lowcode;

use app\command\base\LowcodeCommand;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\Schema\SchemaVersionService;
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
    /** @var int 无漂移退出码 */
    private const EXIT_OK = 0;

    /** @var int 检测到漂移退出码（CI Gate 使用） */
    private const EXIT_DRIFT = 4;

    /** @var int 命令运行异常退出码 */
    private const EXIT_ERROR = 10;

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
            ->addOption('tenant-id', null, Option::VALUE_OPTIONAL, 'Tenant ID (default: 0) | 租户ID（默认：0）', '0')
            ->addOption('format', null, Option::VALUE_OPTIONAL, 'Output format (text|json) | 输出格式（text|json）', 'text')
            ->addOption('audit-out', null, Option::VALUE_OPTIONAL, 'Audit JSON output file path | 审计JSON输出文件路径');
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
            $schemaVersionService = new SchemaVersionService();

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
                $diff = $this->checkCollectionDiff($collection, $schemaVersionService);
                $diffs[] = $diff;
                if (!empty($diff['changes'])) {
                    $hasDiff = true;
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

            $exitCode = $checkOnly && $hasDiff ? self::EXIT_DRIFT : self::EXIT_OK;

            // Structured output | 结构化输出
            $this->writeStructuredOutput([
                'migration_diff_report' => [
                    'generated_at' => date('Y-m-d H:i:s'),
                    'total_collections' => count($diffs),
                    'collections_with_diff' => count(array_filter($diffs, fn ($d) => !empty($d['changes']))),
                    'diffs' => $diffs,
                    'summary' => $this->buildSummary($diffs),
                ],
                'options' => [
                    'all' => (bool) $checkAll,
                    'check' => (bool) $checkOnly,
                    'out' => $outFile,
                    'report' => $reportFile,
                    'tenant_id' => $tenantId,
                ],
            ], $exitCode, $hasDiff ? 'Schema drift detected' : 'No schema drift');

            // Return exit code | 返回退出码
            if ($checkOnly && $hasDiff) {
                $this->warning('Schema drift detected!');
            }

            return $exitCode;
        } catch (\Throwable $e) {
            $this->handleException($e);
            return self::EXIT_ERROR;
        }
    }

    /**
     * Check collection diff | 检查Collection差异
     *
     * @param \Domain\Lowcode\Collection\Interfaces\CollectionInterface $collection Collection instance | Collection实例
     * @param SchemaVersionService $schemaVersionService Schema 版本服务
     * @return array Diff result | 差异结果
     */
    protected function checkCollectionDiff($collection, SchemaVersionService $schemaVersionService): array
    {
        $tableName = $collection->getTableName();
        $changes = [];
        $tenantId = $collection->getTenantId() ?? 0;

        // 先记录/刷新当前真源 Schema 版本（即使存在漂移也要写入审计表）
        $version = $schemaVersionService->recordCollection($collection);

        // Schema 哈希对比（真源 vs 当前记录）
        $computedHash = $schemaVersionService->computeHash($collection->toArray());
        $stored = $this->getStoredSchemaInfo($collection->getName(), $tenantId);
        if ($stored && ($stored['schema_hash'] ?? null) !== $computedHash) {
            $changes[] = [
                'type' => 'schema_hash_mismatch',
                'collection' => $collection->getName(),
                'table' => $tableName,
                'stored_hash' => $stored['schema_hash'] ?? null,
                'computed_hash' => $computedHash,
                'stored_version' => (int)($stored['schema_version'] ?? 0),
            ];
        }

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
            $dbIndexes = $this->getTableIndexes($tableName);
            $collectionFields = $collection->getFields();

            // 必备系统字段检查
            foreach (['tenant_id', 'site_id'] as $sysCol) {
                if (!isset($dbColumns[$sysCol])) {
                    $changes[] = [
                        'type' => 'add_column',
                        'table' => $tableName,
                        'column' => $sysCol,
                        'message' => "System column '{$sysCol}' missing in database",
                    ];
                }
            }

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
                    continue;
                }

                // 类型/可空性比对
                $dbType = strtolower((string) $dbColumns[$fieldName]['Type']);
                $expectedType = strtolower($field->getDbType());
                $nullable = strtolower((string) $dbColumns[$fieldName]['Null']) === 'yes';
                if (!$this->dbTypeMatches($expectedType, $dbType) || $nullable !== $field->isNullable()) {
                    $changes[] = [
                        'type' => 'modify_column',
                        'table' => $tableName,
                        'column' => $fieldName,
                        'expected_type' => $expectedType,
                        'db_type' => $dbType,
                        'expected_nullable' => $field->isNullable(),
                        'db_nullable' => $nullable,
                        'message' => "Column '{$fieldName}' definition differs",
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

            // Index 差异：至少需要 idx_tenant_id_id、idx_tenant_site
            $expectedIndexes = [
                'idx_tenant_id_id' => ['tenant_id', 'id'],
                'idx_tenant_site' => ['tenant_id', 'site_id'],
            ];
            foreach ($expectedIndexes as $name => $cols) {
                if (!isset($dbIndexes[$name])) {
                    $changes[] = [
                        'type' => 'add_index',
                        'table' => $tableName,
                        'index' => $name,
                        'columns' => $cols,
                        'message' => "Index '{$name}' missing in database",
                    ];
                    continue;
                }
                if ($dbIndexes[$name] !== $cols) {
                    $changes[] = [
                        'type' => 'modify_index',
                        'table' => $tableName,
                        'index' => $name,
                        'expected' => $cols,
                        'actual' => $dbIndexes[$name],
                        'message' => "Index '{$name}' columns differ",
                    ];
                }
            }
        }

        return [
            'collection' => $collection->getName(),
            'table' => $tableName,
            'tenant_id' => $tenantId,
            'computed_hash' => $computedHash,
            'stored_hash' => $stored['schema_hash'] ?? null,
            'stored_version' => $stored['schema_version'] ?? null,
            'recorded_version' => $version['version'] ?? null,
            'changes' => $changes,
        ];
    }

    protected function dbTypeMatches(string $expected, string $actual): bool
    {
        // 简化匹配：检查前缀，忽略长度大小写差异
        $expected = strtolower($expected);
        $actual = strtolower($actual);

        if (str_starts_with($actual, $expected)) {
            return true;
        }

        // 特殊：json 存储为 longtext/ text 时视为不匹配，需调整
        return false;
    }

    /**
     * 获取已存储的 schema_version/schema_hash
     */
    protected function getStoredSchemaInfo(string $collectionName, int $tenantId): ?array
    {
        // 兼容：部分测试/环境可能尚未执行新增列迁移（schema_version/schema_hash），此时跳过对比
        try {
            $columns = Db::query("SHOW COLUMNS FROM `lowcode_collections` LIKE 'schema_version'");
            $hasVersion = !empty($columns);
            $columns = Db::query("SHOW COLUMNS FROM `lowcode_collections` LIKE 'schema_hash'");
            $hasHash = !empty($columns);

            if (!$hasVersion || !$hasHash) {
                return null;
            }

            return Db::name('lowcode_collections')
                ->where('name', $collectionName)
                ->where('tenant_id', $tenantId)
                ->field(['schema_version', 'schema_hash'])
                ->find() ?: null;
        } catch (\Throwable) {
            return null;
        }
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
        $sql = '-- Migration Diff Generated at ' . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- 注意：以下 SQL 为幂等模板（IF EXISTS/IF NOT EXISTS），请审查后再执行，并准备回滚脚本。\n\n";

        foreach ($diffs as $diff) {
            if (empty($diff['changes'])) {
                continue;
            }

            $sql .= "-- Collection: {$diff['collection']}\n";
            $sql .= "-- Table: {$diff['table']}\n\n";

            foreach ($diff['changes'] as $change) {
                $sql .= $this->renderSqlForChange($change);
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
            'summary' => $this->buildSummary($diffs),
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

    /**
     * 获取表索引信息（返回 name => columns 数组，按顺序）
     */
    protected function getTableIndexes(string $tableName): array
    {
        $indexes = [];
        try {
            $rows = Db::query("SHOW INDEX FROM `{$tableName}`");
            foreach ($rows as $row) {
                $name = $row['Key_name'];
                if (!isset($indexes[$name])) {
                    $indexes[$name] = [];
                }
                $indexes[$name][(int)$row['Seq_in_index']] = $row['Column_name'];
            }
            // 确保列顺序按 Seq_in_index 排序
            foreach ($indexes as $name => $cols) {
                ksort($cols);
                $indexes[$name] = array_values($cols);
            }
        } catch (\Throwable) {
            // ignore
        }

        return $indexes;
    }

    /**
     * 汇总统计信息
     */
    protected function buildSummary(array $diffs): array
    {
        $summary = [
            'drift_collections' => 0,
            'changes' => [
                'create_table' => 0,
                'add_column' => 0,
                'modify_column' => 0,
                'remove_column' => 0,
                'add_index' => 0,
                'modify_index' => 0,
                'schema_hash_mismatch' => 0,
            ],
        ];

        foreach ($diffs as $diff) {
            if (!empty($diff['changes'])) {
                $summary['drift_collections']++;
            }
            foreach ($diff['changes'] as $change) {
                $type = $change['type'] ?? 'unknown';
                if (!isset($summary['changes'][$type])) {
                    $summary['changes'][$type] = 0;
                }
                $summary['changes'][$type]++;
            }
        }

        return $summary;
    }

    /**
     * 按差异类型生成简单 SQL 片段（带中文注释，便于人工完善）
     */
    protected function renderSqlForChange(array $change): string
    {
        $table = $change['table'] ?? '';
        $type = $change['type'] ?? 'unknown';
        $message = $change['message'] ?? '';

        $sql = "-- {$message}\n";
        $sql .= "-- change_type: {$type}\n";

        switch ($type) {
            case 'create_table':
                $sql .= sprintf(
                    "CREATE TABLE IF NOT EXISTS `%s` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',\n  `site_id` int unsigned NOT NULL DEFAULT 0 COMMENT '站点ID',\n  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,\n  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  PRIMARY KEY (`id`),\n  KEY `idx_tenant_id_id` (`tenant_id`,`id`),\n  KEY `idx_tenant_site` (`tenant_id`,`site_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n",
                    $table
                );
                break;
            case 'add_column':
                $expectedType = $change['field_type'] ?? ($change['expected_type'] ?? 'string');
                $sql .= sprintf(
                    "ALTER TABLE `%s` ADD COLUMN IF NOT EXISTS `%s` %s;\n\n",
                    $table,
                    $change['column'] ?? '',
                    strtoupper($expectedType)
                );
                break;
            case 'modify_column':
                $sql .= sprintf(
                    "ALTER TABLE `%s` MODIFY COLUMN `%s` %s %s;\n\n",
                    $table,
                    $change['column'] ?? '',
                    strtoupper((string)($change['expected_type'] ?? '')),
                    !empty($change['expected_nullable']) ? 'NULL' : 'NOT NULL'
                );
                break;
            case 'remove_column':
                $sql .= sprintf(
                    "ALTER TABLE `%s` DROP COLUMN `%s`;\n\n",
                    $table,
                    $change['column'] ?? ''
                );
                break;
            case 'add_index':
                $columns = isset($change['columns']) ? implode('`,`', (array)$change['columns']) : '';
                $sql .= sprintf(
                    "ALTER TABLE `%s` ADD INDEX `%s` (`%s`);\n\n",
                    $table,
                    $change['index'] ?? '',
                    $columns
                );
                break;
            case 'modify_index':
                $expectedCols = isset($change['expected']) ? implode('`,`', (array)$change['expected']) : '';
                $sql .= sprintf(
                    "ALTER TABLE `%s` DROP INDEX IF EXISTS `%s`, ADD INDEX `%s` (`%s`);\n\n",
                    $table,
                    $change['index'] ?? '',
                    $change['index'] ?? '',
                    $expectedCols
                );
                break;
            case 'schema_hash_mismatch':
            default:
                $sql .= "-- 请根据上方描述手工补充 DDL\n\n";
                break;
        }

        return $sql;
    }
}
