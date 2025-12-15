#!/usr/bin/env php
<?php
/**
 * Check Raw SQL Tenant Safety | 原生 SQL 多租户安全检查
 *
 * Scans the codebase for raw SQL usage and checks for potential tenant isolation issues.
 * 扫描代码库中的原生 SQL 使用，检查潜在的租户隔离问题。
 *
 * Exit codes | 退出码：
 *   0 - All raw SQL appears safe or review completed | 所有原生 SQL 看起来安全或审查完成
 *   1 - Found raw SQL requiring review | 发现需要审查的原生 SQL
 *
 * Usage | 使用方法：
 *   php scripts/check-raw-sql-tenant.php
 *   php scripts/check-raw-sql-tenant.php --strict  (fail on any raw SQL)
 */

declare(strict_types=1);

// Raw SQL patterns to detect | 要检测的原生 SQL 模式
$rawSqlPatterns = [
    'Db::table' => '/Db::table\s*\(/i',
    'Db::name' => '/Db::name\s*\(/i',
    'Db::query' => '/Db::query\s*\(/i',
    'Db::execute' => '/Db::execute\s*\(/i',
];

// Tenant safety patterns (presence suggests proper filtering) | 租户安全模式（存在表示正确过滤）
$tenantSafetyPatterns = [
    '/->where\s*\(\s*[\'"]tenant_id[\'"]\s*,/i',
    '/->where\s*\(\s*[\'"]site_id[\'"]\s*,/i',
    '/whereRaw\s*\(.*?tenant_id/is',
    '/whereIn\s*\(\s*[\'"]tenant_id[\'"]\s*,/i',
];

// Known safe locations (DDL operations) | 已知安全位置（DDL 操作）
$safeLocations = [
    'infrastructure/Schema/SchemaBuilder.php',
    'database/migrations/',
    'database/seeds/',
];

// Directories to scan | 要扫描的目录
$scanDirs = [
    __DIR__ . '/../app',
    __DIR__ . '/../infrastructure',
];

// Exclude patterns | 排除模式
$excludePatterns = [
    '/vendor/',
    '/node_modules/',
    '/runtime/',
    '/\.git/',
    '/tests/', // Consider including tests later | 考虑以后包含测试
];

function colorize(string $text, string $color): string
{
    $colors = [
        'red' => "\033[0;31m",
        'green' => "\033[0;32m",
        'yellow' => "\033[0;33m",
        'blue' => "\033[0;34m",
        'cyan' => "\033[0;36m",
        'reset' => "\033[0m",
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

function isSafeLocation(string $filePath, array $safeLocations): bool
{
    foreach ($safeLocations as $safeLoc) {
        if (str_contains($filePath, $safeLoc)) {
            return true;
        }
    }
    return false;
}

function hasTenantSafety(string $code, array $safetyPatterns): bool
{
    foreach ($safetyPatterns as $pattern) {
        if (preg_match($pattern, $code)) {
            return true;
        }
    }
    return false;
}

echo colorize("=== AlkaidSYS Raw SQL Tenant Safety Check ===\n", 'blue');
echo "Scanning for raw SQL usage and checking tenant isolation...\n\n";

$findings = [];
$fileCount = 0;
$totalRawSql = 0;
$safeCount = 0;
$warningCount = 0;

// Scan each directory | 扫描每个目录
foreach ($scanDirs as $scanDir) {
    if (!is_dir($scanDir)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scanDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $filePath = $file->getRealPath();

        // Check if file should be excluded | 检查文件是否应被排除
        $shouldExclude = false;
        foreach ($excludePatterns as $pattern) {
            if (str_contains($filePath, $pattern) || preg_match($pattern, $filePath)) {
                $shouldExclude = true;
                break;
            }
        }

        if ($shouldExclude) {
            continue;
        }

        $fileCount++;
        $content = file_get_contents($filePath);

        // Check each raw SQL pattern | 检查每个原生 SQL 模式
        foreach ($rawSqlPatterns as $patternName => $pattern) {
            if (preg_match_all($pattern, $content, $matches,PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $lineNum = substr_count($content, "\n", 0, $match[1]) + 1;
                    $totalRawSql++;

                    // Extract surrounding context (5 lines before and after) | 提取周围上下文
                    $lines = explode("\n", $content);
                    $contextStart = max(0, $lineNum - 6);
                    $contextEnd = min(count($lines), $lineNum + 4);
                    $context = implode("\n", array_slice($lines, $contextStart, $contextEnd - $contextStart));

                    // Determine safety level | 确定安全级别
                    $isSafe = isSafeLocation($filePath, $safeLocations);
                    $hasSafety = hasTenantSafety($context, $tenantSafetyPatterns);

                    if ($isSafe) {
                        $level = 'safe';
                        $reason = 'DDL/Migration/Seed operation';
                        $safeCount++;
                    } elseif ($hasSafety) {
                        $level = 'safe';
                        $reason = 'Contains tenant_id/site_id filtering';
                        $safeCount++;
                    } else {
                        $level = 'warning';
                        $reason = 'No tenant filtering detected';
                        $warningCount++;
                    }

                    $findings[] = [
                        'file' => $filePath,
                        'line' => $lineNum,
                        'pattern' => $patternName,
                        'level' => $level,
                        'reason' => $reason,
                        'context' => $context,
                    ];
                }
            }
        }
    }
}

echo "Scanned {$fileCount} PHP files.\n";
echo "Found {$totalRawSql} raw SQL usage(s).\n\n";

// Group findings by level | 按级别分组
$safeSql = array_filter($findings, fn($f) => $f['level'] === 'safe');
$warningSql = array_filter($findings, fn($f) => $f['level'] === 'warning');

if (!empty($safeSql)) {
    echo colorize("✓ Safe raw SQL usage: " . count($safeSql) . "\n", 'green');
}

if (!empty($warningSql)) {
    echo colorize("⚠ Raw SQL requiring review: " . count($warningSql) . "\n\n", 'yellow');

    $groupedByFile = [];
    foreach ($warningSql as $finding) {
        $relativePath = str_replace(__DIR__ . '/../', '', $finding['file']);
        if (!isset($groupedByFile[$relativePath])) {
            $groupedByFile[$relativePath] = [];
        }
        $groupedByFile[$relativePath][] = $finding;
    }

    foreach ($groupedByFile as $file => $fileFindings) {
        echo colorize("File: {$file}\n", 'yellow');
        foreach ($fileFindings as $finding) {
            echo sprintf(
                "  Line %d: %s - %s\n",
                $finding['line'],
                $finding['pattern'],
                $finding['reason']
            );

            // Show a snippet of context | 显示上下文片段
            $contextLines = explode("\n", $finding['context']);
            $relevantLines = array_slice($contextLines, 0, 3); // Show first 3 lines
            foreach ($relevantLines as $contextLine) {
                echo colorize("    " . trim($contextLine) . "\n", 'cyan');
            }
            echo "\n";
        }
    }

    echo colorize("\nAction Required:\n", 'yellow');
    echo "1. Review each flagged raw SQL usage\n";
    echo "2. Ensure tenant_id and/or site_id filtering is applied where appropriate\n";
    echo "3. Document any intentional cross-tenant queries\n";
    echo "4. See docs/technical-specs/database/raw-sql-multitenant-checklist.md for guidelines\n\n";

    exit(1);
} else {
    echo colorize("\n✓ All raw SQL appears safe!\n", 'green');
    echo "However, manual review is still recommended for critical paths.\n\n";
    exit(0);
}
