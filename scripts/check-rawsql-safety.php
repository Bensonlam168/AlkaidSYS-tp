#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Raw SQL Tenant Safety Check | 原生 SQL 多租户安全检查
 *
 * Scans the codebase for usages of raw SQL entry points such as
 * Db::table()/Db::name()/Db::query()/Db::execute() and prints a list of
 * occurrences for manual or rule-based review.
 *
 * 扫描代码仓库中 Db::table()/Db::name()/Db::query()/Db::execute() 等
 * 原生 SQL 入口的使用点，输出清单供人工或规则确认是否满足
 * tenant_id/site_id 过滤与审计要求。
 *
 * Exit codes | 退出码：
 *   0 - Scan completed (matches may still exist, please review output)
 *       扫描完成（可能存在原生 SQL 调用，请根据输出进行人工审查）
 *   1 - Strict mode with matches found | 严格模式下发现原生 SQL 调用
 *
 * Usage | 使用方法：
 *   php scripts/check-rawsql-safety.php
 *   php scripts/check-rawsql-safety.php --strict          # 严格模式，有匹配即返回 1
 *   php scripts/check-rawsql-safety.php --include-tests   # 额外扫描 tests/scripts 目录
 */

if (!function_exists('colorize')) {
    /**
     * Color output helper | 彩色输出辅助函数
     */
    function colorize(string $text, string $color): string
    {
        $colors = [
            'red' => "\033[0;31m",
            'green' => "\033[0;32m",
            'yellow' => "\033[0;33m",
            'blue' => "\033[0;34m",
            'reset' => "\033[0m",
        ];

        return ($colors[$color] ?? '') . $text . $colors['reset'];
    }
}

/**
 * Scan for raw SQL usage patterns.
 *
 * @param string   $rootDir    Project root directory | 项目根目录
 * @param string[] $dirsToScan Relative directories to scan | 需要扫描的相对目录
 * @param string[] $patterns   Substring patterns to search for | 要搜索的子串模式
 * @param string[] $allowList  Relative file paths to ignore | 允许列表（相对路径）
 *
 * @return array<int, array{file:string,line:int,pattern:string,code:string}>
 */
function scanRawSqlUsage(string $rootDir, array $dirsToScan, array $patterns, array $allowList = []): array
{
    $results = [];

    foreach ($dirsToScan as $dir) {
        $baseDir = $rootDir . DIRECTORY_SEPARATOR . $dir;
        if (!is_dir($baseDir)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getPathname();
            $relativePath = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $filePath);

            // 允许列表跳过
            if (in_array($relativePath, $allowList, true)) {
                continue;
            }

            $contents = @file_get_contents($filePath);
            if ($contents === false) {
                fwrite(STDERR, colorize("[WARN] Failed to read file: {$relativePath}\n", 'yellow'));
                continue;
            }

            $lines = explode("\n", $contents);
            foreach ($lines as $index => $line) {
                foreach ($patterns as $pattern) {
                    if (strpos($line, $pattern) !== false) {
                        $results[] = [
                            'file'    => $relativePath,
                            'line'    => $index + 1,
                            'pattern' => $pattern,
                            'code'    => trim($line),
                        ];
                    }
                }
            }
        }
    }

    return $results;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $rootDir = realpath(__DIR__ . '/..') ?: getcwd();

    // 默认仅扫描生产代码目录，必要时可通过参数包含 tests/scripts
    $dirsToScan = ['app', 'domain', 'infrastructure'];
    $patterns = [
        'Db::table(',
        'Db::name(',
        'Db::query(',
        'Db::execute(',
    ];

    $argv = $_SERVER['argv'] ?? [];
    $strict = in_array('--strict', $argv, true);
    $includeTests = in_array('--include-tests', $argv, true);
    if ($includeTests) {
        $dirsToScan[] = 'tests';
        $dirsToScan[] = 'scripts';
    }

    // 允许列表：系统级 DDL/元数据场景（非业务数据访问），或模板/生成器内的字符串片段
    $allowList = [
        'infrastructure/Schema/SchemaBuilder.php',
        'app/command/lowcode/MigrationDiffCommand.php',
        'infrastructure/Application/BaseApplication.php',
        // 低代码生成器：包含 Db::* 模板片段，实际写入文件时会再按租户过滤生成
        'infrastructure/Lowcode/Generator/ControllerGenerator.php',
        'infrastructure/Lowcode/Generator/CrudGenerator.php',
        'infrastructure/Lowcode/Generator/RouteGenerator.php',
        'infrastructure/Lowcode/Generator/TestGenerator.php',
    ];

    echo colorize("=== AlkaidSYS Raw SQL Tenant Safety Scan ===\n", 'blue');
    echo 'Project root: ' . $rootDir . "\n";
    echo 'Scanning directories: ' . implode(', ', $dirsToScan) . "\n";
    echo 'Patterns: ' . implode(', ', $patterns) . "\n\n";

    $results = scanRawSqlUsage($rootDir, $dirsToScan, $patterns, $allowList);

    if (empty($results)) {
        echo colorize("✓ No raw SQL usages found for configured patterns.\n", 'green') . "\n";
        exit(0);
    }

    echo colorize('✗ Found ' . count($results) . " raw SQL usage(s):\n\n", 'yellow');

    foreach ($results as $hit) {
        $file = $hit['file'];
        $line = $hit['line'];
        $pattern = $hit['pattern'];
        $code = $hit['code'];

        echo sprintf(
            '[MATCH] %s:%d: pattern "%s" => %s' . PHP_EOL,
            $file,
            $line,
            $pattern,
            $code
        );
    }

    echo "\n";

    if ($strict) {
        echo colorize("Strict mode enabled: returning exit code 1 due to raw SQL usages.\n", 'red');
        exit(1);
    }

    echo colorize("Scan completed. Please review the above raw SQL usages for tenant_id/site_id safety.\n", 'green') . "\n";
    exit(0);
}
