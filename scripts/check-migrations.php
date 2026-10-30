#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Migration Safety Check | 迁移安全守卫（R6）
 *
 * 背景：
 *   vendor 版 `migrate:rollback` 无参时只回滚「版本号最大的那一个迁移」。
 *   本项目已用 app\command\migrate\RollbackCommand 改为「按批（batch）回滚」，
 *   但批次语义仍依赖两个前提：
 *     a. 迁移文件名（version）全局唯一 —— 否则 Phinx 直接抛异常 / 回滚目标错乱；
 *     b. 未应用的迁移 version 必须大于已应用的最大 version ——
 *        否则「最大 version」不是最新写的迁移，无参 rollback 会打错迁移。
 *
 * 检查项：
 *   A. database/migrations/ 下所有迁移文件名的时间戳前缀唯一；
 *   B. 未应用的迁移 version 必须大于已应用的最大 version（防倒挂）；
 *   C. 每个迁移类必须实现 up() 与 down()，且不得存在 change()
 *      （Phinx 检测到 change() 会忽略 down()，使 down 成为死代码）。
 *
 * 用法：
 *   php scripts/check-migrations.php              # 检查并输出报告
 *   php scripts/check-migrations.php --strict     # 有告警即失败（CI 门禁）
 *
 * 退出码：
 *   0 - 通过
 *   1 - 存在错误（或 --strict 模式下存在告警）
 *
 * @package scripts
 */

// ---------------------------------------------------------------------------
// 配置
// ---------------------------------------------------------------------------

/** 迁移目录（相对仓库根） */
const MIGRATION_DIR = 'database/migrations';

/** 仓库根目录 */
const REPO_ROOT = __DIR__ . '/..';

/**
 * 历史遗留：仅实现 change() 的迁移（基线豁免）
 *
 * 这些文件依赖 Phinx 的 change() 反向推导回滚，可用但不显式：
 * 一旦 change() 内部出现不可逆操作，down 就会静默失败。
 * 由于「禁止改动既有迁移」，此处按基线豁免；**新增迁移不得再进入本清单**。
 * 待后续统一改写为 up()/down() 后，应逐项从清单中移除。
 */
const LEGACY_CHANGE_ONLY = [
    '20251119000001_create_lowcode_collections_table.php',
    '20251119000002_create_lowcode_event_logs_table.php',
    '20251119090710_create_lowcode_fields_table.php',
    '20251119090713_create_lowcode_relationships_table.php',
    '20251119103901_create_lowcode_forms_table.php',
    '20251119210000_create_tenants_table.php',
    '20251119210100_create_sites_table.php',
    '20251119210200_create_users_table.php',
    '20251119210300_create_roles_table.php',
    '20251119210400_create_permissions_table.php',
    '20251119210500_create_role_permissions_table.php',
    '20251119210600_create_user_roles_table.php',
    '20251217000001_create_lowcode_schema_versions_table.php',
    '20251217000002_create_plugin_packages_and_installations_tables.php',
    '20251217000005_create_hook_monitor_tables.php',
    '20251219000001_create_depts_and_menus_tables.php',
    '20251220000003_create_event_metrics_and_dead_letters_tables.php',
    '20251220000004_create_event_alerts_table.php',
];

// ---------------------------------------------------------------------------
// 工具函数
// ---------------------------------------------------------------------------

/**
 * 终端着色（NO_COLOR 环境变量存在时禁用）
 */
function colorize(string $text, string $color): string
{
    $codes = ['red' => '31', 'green' => '32', 'yellow' => '33', 'blue' => '34', 'gray' => '90'];
    $code = $codes[$color] ?? null;
    if ($code === null || getenv('NO_COLOR') !== false) {
        return $text;
    }

    return "\033[{$code}m{$text}\033[0m";
}

/**
 * 格式化检查结果
 */
function formatResult(int $errors, int $warnings): string
{
    if ($errors === 0 && $warnings === 0) {
        return colorize('OK', 'green');
    }
    if ($errors === 0) {
        return colorize("{$warnings} 个告警", 'yellow');
    }

    return colorize("{$errors} 个错误" . ($warnings > 0 ? " / {$warnings} 个告警" : ''), 'red');
}

/**
 * 收集迁移文件
 *
 * @return array<int, array{file:string, base:string, version:int}>
 */
function collectMigrationFiles(string $dir): array
{
    $result = [];

    if (!is_dir($dir)) {
        return $result;
    }

    $files = glob($dir . '/*.php');
    if ($files === false) {
        return $result;
    }
    sort($files);

    foreach ($files as $file) {
        $base = basename($file);
        // 迁移文件名格式：<时间戳前缀>_<类名>.php
        if (!preg_match('/^(\d{8,14})_[A-Za-z0-9_]+\.php$/', $base, $matches)) {
            continue;
        }
        $result[] = [
            'file'    => $file,
            'base'    => $base,
            'version' => (int) $matches[1],
        ];
    }

    return $result;
}

/**
 * 解析文件中第一个类声明的方法名集合（小写）
 *
 * 使用 token_get_all 而非正则，避免注释/字符串中的 "function up(" 造成误判。
 *
 * @return string[]
 */
function declaredMethods(string $code): array
{
    $tokens = token_get_all($code);
    $count  = count($tokens);

    // 1) 定位类声明（跳过 ::class 常量与匿名类）
    $index = 0;
    while ($index < $count) {
        $token = $tokens[$index];
        if (is_array($token) && $token[0] === T_CLASS) {
            $prev = $index > 0 ? $tokens[$index - 1] : null;
            if (is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_NEW], true)) {
                $index++;
                continue;
            }
            break;
        }
        $index++;
    }

    if ($index >= $count) {
        return [];
    }

    // 2) 扫描类体，收集 depth = 1 处的函数名
    $methods  = [];
    $depth    = 0;
    $inBody   = false;

    for (; $index < $count; $index++) {
        $token = $tokens[$index];
        $text  = is_array($token) ? $token[1] : $token;

        if (!$inBody) {
            if ($text === '{') {
                $inBody = true;
                $depth  = 1;
            }
            continue;
        }

        if ($text === '{' || $text === '}') {
            $depth += ($text === '{') ? 1 : -1;
            if ($depth === 0) {
                break;
            }
            continue;
        }

        if (1 === $depth && is_array($token) && $token[0] === T_FUNCTION) {
            for ($j = $index + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $methods[] = strtolower($tokens[$j][1]);
                    break;
                }
                // 匿名函数：函数名位置直接是 '('
                if ($tokens[$j] === '(') {
                    break;
                }
            }
        }
    }

    return $methods;
}

/**
 * 读取已应用的迁移版本（依赖框架引导，失败返回 null）
 *
 * @return int[]|null
 */
function loadAppliedVersions(): ?array
{
    try {
        $autoload = REPO_ROOT . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            return null;
        }
        require_once $autoload;

        $app = new \think\App();
        $app->initialize();

        $pdo = $app->db->connect()->getPdo();
        $rows = $pdo->query('SELECT version FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $rows);
    } catch (\Throwable $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// 检查项
// ---------------------------------------------------------------------------

/**
 * 检查 A：迁移文件名的时间戳前缀唯一
 *
 * @param array<int, array{file:string, base:string, version:int}> $migrations
 * @return array<int, array{version:int, files:string[]}>
 */
function checkDuplicateVersions(array $migrations): array
{
    $grouped = [];
    foreach ($migrations as $migration) {
        $grouped[$migration['version']][] = $migration['base'];
    }

    $issues = [];
    foreach ($grouped as $version => $files) {
        if (count($files) > 1) {
            $issues[$version] = $files;
        }
    }

    return $issues;
}

/**
 * 检查 B：未应用的迁移 version 必须大于已应用的最大 version
 *
 * @param array<int, array{file:string, base:string, version:int}> $migrations
 * @param int[]                                                    $applied
 * @return array<int, array{base:string, version:int, max:int}>
 */
function checkVersionOrder(array $migrations, array $applied): array
{
    $issues = [];

    if ([] === $applied) {
        // 全新数据库：没有「已应用」概念，无从倒挂
        return $issues;
    }

    $maxApplied = max($applied);
    $appliedSet = array_flip($applied);

    foreach ($migrations as $migration) {
        if (isset($appliedSet[$migration['version']])) {
            continue;
        }
        if ($migration['version'] < $maxApplied) {
            $issues[] = [
                'base'    => $migration['base'],
                'version' => $migration['version'],
                'max'     => $maxApplied,
            ];
        }
    }

    return $issues;
}

/**
 * 检查 C：必须实现 up() / down()，且不得存在 change()
 *
 * 规则细则：
 *   1. 同时存在 change() 与 up()/down() → 错误：Phinx 只执行 change()，up()/down() 是死代码。
 *   2. 仅有 change() 且不在 LEGACY_CHANGE_ONLY 基线内 → 错误：新迁移必须显式写 up()/down()。
 *   3. 仅有 change() 且在基线内 → 放行（历史遗留，见 LEGACY_CHANGE_ONLY 说明）。
 *   4. 既无 change() 又缺少 up() 或 down() → 错误。
 *
 * @param array<int, array{file:string, base:string, version:int}> $migrations
 * @return array<int, array{base:string, problem:string}>
 */
function checkUpDownMethods(array $migrations): array
{
    $issues = [];

    foreach ($migrations as $migration) {
        $code = @file_get_contents($migration['file']);
        if ($code === false) {
            $issues[] = ['base' => $migration['base'], 'problem' => '文件不可读'];
            continue;
        }

        $methods   = declaredMethods($code);
        $hasUp     = in_array('up', $methods, true);
        $hasDown   = in_array('down', $methods, true);
        $hasChange = in_array('change', $methods, true);
        $isLegacy  = in_array($migration['base'], LEGACY_CHANGE_ONLY, true);

        if ($hasChange && ($hasUp || $hasDown)) {
            $issues[] = [
                'base'    => $migration['base'],
                'problem' => '同时存在 change() 与 up()/down()：Phinx 会忽略 up()/down()，down 成为死代码',
            ];
            continue;
        }

        if ($hasChange && !$isLegacy) {
            $issues[] = [
                'base'    => $migration['base'],
                'problem' => '新迁移不得使用 change()，请显式实现 up() 与 down()',
            ];
            continue;
        }

        if ($hasChange) {
            // 基线内的历史遗留文件：依赖 Phinx 反向推导，不参与 up/down 强制要求
            continue;
        }

        if (!$hasUp) {
            $issues[] = ['base' => $migration['base'], 'problem' => '缺少 up()'];
        }
        if (!$hasDown) {
            $issues[] = ['base' => $migration['base'], 'problem' => '缺少 down()'];
        }
    }

    return $issues;
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

$strict = in_array('--strict', $argv, true);

echo colorize('Migration Safety Check | 迁移安全守卫', 'blue') . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;

$migrations = collectMigrationFiles(REPO_ROOT . '/' . MIGRATION_DIR);
echo '扫描迁移文件数: ' . count($migrations) . PHP_EOL . PHP_EOL;

$errorCount   = 0;
$warningCount = 0;

// --- 检查 A：version 唯一 ---
$duplicateIssues = checkDuplicateVersions($migrations);
printf("检查 A  迁移版本唯一性          %s\n", formatResult(count($duplicateIssues), 0));
foreach ($duplicateIssues as $version => $files) {
    echo '   ' . colorize('[DUP]', 'red') . " version {$version} 被以下文件重复使用：" . implode(', ', $files) . PHP_EOL;
}
$errorCount += count($duplicateIssues);

// --- 检查 B：版本顺序不倒挂 ---
$applied = loadAppliedVersions();
if (null === $applied) {
    printf("检查 B  未应用迁移版本顺序      %s\n", formatResult(0, 1));
    echo '   ' . colorize('[WARN]', 'yellow') . ' 无法连接数据库读取 migrations 表，已跳过顺序检查' . PHP_EOL;
    $warningCount++;
} else {
    $orderIssues = checkVersionOrder($migrations, $applied);
    printf("检查 B  未应用迁移版本顺序      %s\n", formatResult(count($orderIssues), 0));
    foreach ($orderIssues as $issue) {
        echo '   ' . colorize('[ORDER]', 'red')
            . " 迁移顺序倒挂：{$issue['base']} 的 version 小于已应用的最大 version {$issue['max']}，"
            . '会让无参 rollback 打错迁移' . PHP_EOL;
    }
    $errorCount += count($orderIssues);
}

// --- 检查 C：up / down 必须存在，change 必须不存在 ---
$methodIssues = checkUpDownMethods($migrations);
printf("检查 C  up/down 与 change()      %s\n", formatResult(count($methodIssues), 0));
foreach ($methodIssues as $issue) {
    echo '   ' . colorize('[METHOD]', 'red') . " {$issue['base']}：{$issue['problem']}" . PHP_EOL;
}
$errorCount += count($methodIssues);

echo str_repeat('-', 64) . PHP_EOL;

if (0 === $errorCount && 0 === $warningCount) {
    echo colorize('✓ 全部检查通过', 'green') . PHP_EOL;
    exit(0);
}

echo sprintf(
    "%s（错误 %d 个，告警 %d 个）\n",
    colorize('检查未全部通过', 'red'),
    $errorCount,
    $warningCount
);

if ($errorCount > 0) {
    exit(1);
}

if ($strict) {
    echo colorize('--strict 模式下告警视为失败', 'red') . PHP_EOL;
    exit(1);
}

echo colorize('提示：使用 --strict 可在 CI 中作为门禁（存在告警即返回非 0）', 'gray') . PHP_EOL;
exit(0);
