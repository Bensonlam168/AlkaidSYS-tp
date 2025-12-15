#!/usr/bin/env php
<?php
/**
 * Check Legacy Code Usage | Legacy 代码使用检查
 *
 * Scans the codebase for references to deprecated Legacy classes.
 * 扫描代码库以查找对已废弃 Legacy 类的引用。
 *
 * Exit codes | 退出码：
 *   0 - No legacy usage found | 未发现 Legacy 使用
 *   1 - Legacy usage found | 发现 Legacy 使用
 *
 * Usage | 使用方法：
 *   php scripts/check-legacy-usage.php
 *   php scripts/check-legacy-usage.php --fix  (future: auto-suggest replacements)
 */

declare(strict_types=1);

// Legacy classes to check | 要检查的 Legacy 类
$legacyClasses = [
    'Domain\\Model\\Collection' => 'Domain\\Lowcode\\Collection\\Model\\Collection',
    'Infrastructure\\Collection\\CollectionManager' => 'Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager',
    'Infrastructure\\Field\\FieldTypeRegistry' => 'Infrastructure\\Lowcode\\Collection\\Field\\FieldFactory',
];

// Directories to scan | 要扫描的目录
$scanDirs = [
    __DIR__ . '/../app',
    __DIR__ . '/../domain',
    __DIR__ . '/../infrastructure',
];

// Exclude patterns | 排除模式
$excludePatterns = [
    '/vendor/',
    '/node_modules/',
    '/runtime/',
    '/\.git/',
    '/tests/', // Temporarily exclude tests | 暂时排除测试
];

// Color output helpers | 彩色输出辅助函数
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

echo colorize("=== AlkaidSYS Legacy Code Usage Check ===\n", 'blue');
echo "Scanning for references to deprecated Legacy classes...\n\n";

$violations = [];
$fileCount = 0;

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

        // Check each legacy class | 检查每个 Legacy 类
        foreach ($legacyClasses as $legacyClass => $replacement) {
            // Check for use statements | 检查 use 语句
            $usePattern = '/^use\s+' . preg_quote($legacyClass, '/') . '\s*;/m';
            if (preg_match($usePattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                $lineNum = substr_count($content, "\n", 0, $matches[0][1]) + 1;
                $violations[] = [
                    'file' => $filePath,
                    'line' => $lineNum,
                    'legacy_class' => $legacyClass,
                    'replacement' => $replacement,
                    'type' => 'use statement',
                ];
            }

            // Check for new instantiation | 检查 new 实例化
            $newPattern = '/new\s+\\\\?' . preg_quote($legacyClass, '/') . '\s*\(/';
            if (preg_match_all($newPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $lineNum = substr_count($content, "\n", 0, $match[1]) + 1;
                    $violations[] = [
                        'file' => $filePath,
                        'line' => $lineNum,
                        'legacy_class' => $legacyClass,
                        'replacement' => $replacement,
                        'type' => 'instantiation',
                    ];
                }
            }

            // Check for static calls | 检查静态调用
            $staticPattern = '/\\\\?' . preg_quote($legacyClass, '/') . '::/';
            if (preg_match_all($staticPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $lineNum = substr_count($content, "\n", 0, $match[1]) + 1;
                    $violations[] = [
                        'file' => $filePath,
                        'line' => $lineNum,
                        'legacy_class' => $legacyClass,
                        'replacement' => $replacement,
                        'type' => 'static call',
                    ];
                }
            }
        }
    }
}

echo "Scanned {$fileCount} PHP files.\n\n";

// Report violations | 报告违规
if (empty($violations)) {
    echo colorize("✓ No legacy code usage found!\n", 'green');
    exit(0);
} else {
    echo colorize("✗ Found " . count($violations) . " legacy code usage(s):\n\n", 'red');

    $groupedByFile = [];
    foreach ($violations as $violation) {
        $relativePath = str_replace(__DIR__ . '/../', '', $violation['file']);
        if (!isset($groupedByFile[$relativePath])) {
            $groupedByFile[$relativePath] = [];
        }
        $groupedByFile[$relativePath][] = $violation;
    }

    foreach ($groupedByFile as $file => $fileViolations) {
        echo colorize("File: {$file}\n", 'yellow');
        foreach ($fileViolations as $violation) {
            echo sprintf(
                "  Line %d: %s - %s\n",
                $violation['line'],
                $violation['type'],
                $violation['legacy_class']
            );
            echo colorize("    → Replace with: {$violation['replacement']}\n", 'blue');
        }
        echo "\n";
    }

    echo colorize("\nAction Required:\n", 'red');
    echo "1. Replace legacy classes with their modern equivalents\n";
    echo "2. Run tests to ensure compatibility\n";
    echo "3. Re-run this script to verify all issues are resolved\n\n";

    echo colorize("Documentation:\n", 'blue');
    echo "- Legacy classes are deprecated and will be removed in v2.0\n";
    echo "- See docs/technical-specs/lowcode/lowcode-guidelines.md for migration guide\n\n";

    exit(1);
}
