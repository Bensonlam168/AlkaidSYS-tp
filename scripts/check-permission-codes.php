#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Check Permission Code Formats in Routes | 路由权限码格式检查
 *
 * Scans the route/ directory for Permission middleware usage and verifies
 * that all permission codes use the internal slug format `resource.action`.
 *
 * 扫描 route/ 目录中 Permission 中间件的使用，验证所有权限码均使用
 * 内部 slug 格式 `resource.action`，避免在路由层混入 `resource:action` 外部格式。
 *
 * Exit codes | 退出码：
 *   0 - All permission codes are valid | 所有权限码格式合法
 *   1 - Invalid permission codes found | 发现不合法的权限码
 *
 * Usage | 使用方法：
 *   php scripts/check-permission-codes.php
 */

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

$rootDir = realpath(__DIR__ . '/..');
$routeDir = $rootDir . '/route';

if (!is_dir($routeDir)) {
    fwrite(STDERR, colorize("[ERROR] route directory not found: {$routeDir}\n", 'red'));
    exit(1);
}

echo colorize("=== AlkaidSYS Permission Code Format Check ===\n", 'blue');
echo "Scanning route files in {$routeDir} ...\n\n";

$errors = [];
$totalFiles = 0;
$totalPermissions = 0;

/**
 * Validate a single permission identifier used in routes.
 *
 * @param string $permission Permission identifier from route definition
 * @param string $relativePath Relative file path
 * @param int    $line        Line number
 * @param array  $errors      Error list (by reference)
 */
function validateRoutePermission(string $permission, string $relativePath, int $line, array &$errors): void
{
    // Route layer must not use external `resource:action` format
    if (strpos($permission, ':') !== false) {
        $errors[] = sprintf(
            '[ERROR] %s:%d: Found external-style permission code "%s" in route; expected internal slug "resource.action".',
            $relativePath,
            $line,
            $permission
        );

        return;
    }

    // Basic slug pattern: resource.action | 基本 slug 模式：resource.action
    if (!preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $permission)) {
        $errors[] = sprintf(
            '[ERROR] %s:%d: Permission slug "%s" does not match expected pattern "resource.action".',
            $relativePath,
            $line,
            $permission
        );
    }
}

$routeFiles = glob($routeDir . '/*.php') ?: [];

foreach ($routeFiles as $filePath) {
    $totalFiles++;
    $content = file_get_contents($filePath);
    if ($content === false) {
        fwrite(STDERR, colorize("[WARN] Failed to read file: {$filePath}\n", 'yellow'));
        continue;
    }

    $relativePath = str_replace($rootDir . '/', '', $filePath);

    // Pattern A: ->middleware(\app\middleware\Permission::class, 'lowcode.read')
    $patternDirect = '/Permission::class\s*,\s*([\'\"])([^\'\"]+)\\1/';

    if (preg_match_all($patternDirect, $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[2] as $index => $match) {
            $permission = $match[0];
            $offset = $match[1];
            $line = substr_count($content, "\n", 0, $offset) + 1;

            $totalPermissions++;
            validateRoutePermission($permission, $relativePath, $line, $errors);
        }
    }

    // Pattern B: Permission::class . ':casbin.manage'
    $patternConcat = '/Permission::class\s*\.\s*([\'\"]):([^\'\"]+)\\1/';

    if (preg_match_all($patternConcat, $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[2] as $index => $match) {
            $permission = $match[0]; // without leading ':'
            $offset = $match[1];
            $line = substr_count($content, "\n", 0, $offset) + 1;

            $totalPermissions++;
            validateRoutePermission($permission, $relativePath, $line, $errors);
        }
    }
}

echo "Scanned {$totalFiles} route file(s).\n";
echo "Found {$totalPermissions} permission definition(s) in routes.\n\n";

if (empty($errors)) {
    echo colorize("✓ All permission codes in routes use internal slug format (resource.action).\n", 'green');
    exit(0);
}

echo colorize('✗ Found ' . count($errors) . " invalid permission code(s) in routes:\n\n", 'red');

foreach ($errors as $error) {
    echo $error . "\n";
}

exit(1);
