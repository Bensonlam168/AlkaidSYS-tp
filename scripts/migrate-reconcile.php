#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Migration Ledger Reconcile | 迁移账本协调工具（R50 / ER-03）
 *
 * 背景：
 *   `migrations` 表（Phinx 的账本）与 `database/migrations/` 下的文件可能失配，
 *   典型成因是**迁移文件被改名或删除**，而旧库里已经 applied 的记录没有同步清理：
 *     - 账本里有 `20251125`，仓库里只有改名后的 `20251126000001_...`；
 *     - 于是同一件事在账本里占两行：一行 up，一行永远 MISSING。
 *
 *   `php think migrate:status` 对这种记录只显示 MISSING，**退出码仍是 0**，
 *   不会阻断任何流程。本工具由 check-migrations.php 的**检查 F** 触发：
 *   检查 F 报 [MISSING] 时，按本工具的流程协调，而不是手工拼 SQL。
 *
 * 本工具只做一件事：
 *   删除**指定版本的单条** applied 记录（孤儿记录）。
 *   不批量猜测、不自动删除、不改文件、不改表结构。
 *
 * 三种运行形态：
 *   1. 无参        —— dry-run（默认）：只 SELECT + 打印两个清单，绝不写库
 *   2. --apply --version=X
 *                  —— 打印将执行的 SQL，但**缺 --yes 就拒绝执行**（二次确认）
 *   3. --apply --version=X --yes
 *                  —— 真正删除该条记录
 *
 * 安全约束（fail-closed）：
 *   a. `--apply` 必须显式给出 `--version`：不给版本号等于要求「批量清理」，本工具不做；
 *   b. 目标版本必须在账本里存在 —— 不存在就没有可删的东西，报错退出而不是静默成功；
 *   c. 目标版本必须**确实是孤儿**（仓库里找不到对应文件）—— 若文件还在，
 *      删除记录会让该迁移被当成「未应用」而重复执行，坚决拒绝；
 *   d. 必须有 `--yes`；缺了只打印计划并 exit 1，数据库不动分毫；
 *   e. 执行前后各打印一次目标库与记录数，便于事后核对。
 *
 * 用法：
 *   php scripts/migrate-reconcile.php                                   # dry-run（推荐先跑）
 *   php scripts/migrate-reconcile.php --apply --version=20251125        # 只打印计划，拒绝执行
 *   php scripts/migrate-reconcile.php --apply --version=20251125 --yes  # 真正执行
 *
 * 退出码：
 *   0 - dry-run 正常完成，或 --apply 成功删除了 1 条记录
 *   1 - 参数非法 / 连不上库 / 目标不是孤儿 / 缺 --yes / 删除未命中
 *
 * ⚠ 执行 --apply 之前请先备份 migrations 表（脚本本身也会提示）：
 *   docker exec alkaid-mysql mysqldump -u<user> -p <db> migrations > migrations.backup.sql
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
 * 收集迁移文件，返回 version(规范化字符串) => 文件名 的映射
 *
 * 版本号解析规则与 check-migrations.php 的检查 A/F 保持一致：
 * 文件名形如 `<8~14 位数字前缀>_<类名>.php`，前缀即 Phinx 的 version。
 * 这里按字符串比对（不转 int）：`migrations.version` 是 bigint，
 * 与文件名前缀的十进制写法本就一一对应，保持原样即可避免任何精度/前导零歧义。
 *
 * @return array<string, string>
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
        if (!preg_match('/^(\d{8,14})_[A-Za-z0-9_]+\.php$/', $base, $matches)) {
            continue;
        }
        $result[$matches[1]] = $base;
    }

    return $result;
}

/**
 * 解析命令行参数
 *
 * @param string[] $argv
 * @return array{apply:bool, yes:bool, version:?string, help:bool, unknown:string[]}
 */
function parseArgs(array $argv): array
{
    $options = ['apply' => false, 'yes' => false, 'version' => null, 'help' => false, 'unknown' => []];

    foreach (array_slice($argv, 1) as $arg) {
        if ('--apply' === $arg) {
            $options['apply'] = true;
            continue;
        }
        if ('--yes' === $arg) {
            $options['yes'] = true;
            continue;
        }
        if ('--help' === $arg || '-h' === $arg) {
            $options['help'] = true;
            continue;
        }
        if (str_starts_with($arg, '--version=')) {
            $options['version'] = substr($arg, strlen('--version='));
            continue;
        }
        $options['unknown'][] = $arg;
    }

    return $options;
}

/**
 * 兜底：确保「脚本自己崩了」不会被报成成功
 *
 * think\App::initialize() 会注册全局异常处理器，未捕获异常被它渲染之后 PHP 会以
 * **exit 0** 结束（实测：initialize() 之后执行 `strlen([])`，输出 TypeError 后 `$?` 仍为 0）。
 * 对本工具尤其危险：协调工具中途崩溃却返回 0，会让人以为「已经清理干净了」。
 * 因此在 initialize() 之后把处理器换回「打印到 STDERR + exit 1」。
 */
function enforceFailClosedOnError(): void
{
    set_exception_handler(static function (\Throwable $e): void {
        fwrite(STDERR, '[FATAL] ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
        fwrite(STDERR, '脚本异常中断；数据库是否被修改未知，请核对 migrations 表' . PHP_EOL);
        exit(1);
    });
}

/**
 * 建立数据库连接并返回 PDO
 *
 * 复用框架引导（与 check-migrations.php 同一路径），保证读的是 `config/database.php`
 * 解析出的同一个库 —— 不在这里另写一套 DSN，避免「检查连 A 库、协调改 B 库」。
 */
function connectPdo(): PDO
{
    $autoload = REPO_ROOT . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('找不到 vendor/autoload.php，请先 docker exec alkaid-backend composer install');
    }
    require_once $autoload;

    $app = new \think\App();
    $app->initialize();
    enforceFailClosedOnError();

    return $app->db->connect()->getPdo();
}

/**
 * 打印「执行前请备份」的提示
 *
 * 刻意在 dry-run 与 --apply 两个路径都打印：真正危险的时刻是复制粘贴命令的那一刻，
 * 而不是读脚本源码的那一刻。
 */
function printBackupHint(): void
{
    echo colorize('⚠ 执行前请先备份 migrations 表', 'yellow') . PHP_EOL;
    echo '   docker exec alkaid-mysql mysqldump -u<user> -p <db> migrations > migrations.backup.sql' . PHP_EOL;
    echo '   备份后请先确认文件非空（SELECT COUNT(*) 与备份里的行数应一致），再执行 --apply' . PHP_EOL;
}

/**
 * 打印用法
 */
function printUsage(): void
{
    echo <<<'TXT'
用法：
  php scripts/migrate-reconcile.php                                   # dry-run（默认，不写库）
  php scripts/migrate-reconcile.php --apply --version=20251125        # 只打印计划，缺 --yes 会拒绝
  php scripts/migrate-reconcile.php --apply --version=20251125 --yes  # 删除该版本的单条 applied 记录
  php scripts/migrate-reconcile.php --help

说明：
  本工具只删除「有 applied 记录但没有对应迁移文件」的孤儿记录，且必须显式指定版本号。
  它不批量清理、不改迁移文件、不改表结构。

TXT;
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

$options = parseArgs($argv);

echo colorize('Migration Ledger Reconcile | 迁移账本协调工具', 'blue') . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;

if ($options['help']) {
    printUsage();
    exit(0);
}

if ([] !== $options['unknown']) {
    echo colorize('[ERROR]', 'red') . ' 无法识别的参数: ' . implode(' ', $options['unknown']) . PHP_EOL;
    echo '本工具只接受 --apply / --version=<ver> / --yes / --help（不接受位置参数）' . PHP_EOL . PHP_EOL;
    printUsage();
    exit(1);
}

// 模式声明放在最显眼处：避免把 --apply 的输出误读成 dry-run（或反之）
echo '模式: ' . ($options['apply']
    ? colorize('--apply（会修改 migrations 表）', 'yellow')
    : colorize('dry-run（只读，不会修改数据库）', 'green')) . PHP_EOL;

// --- 1) 读迁移文件 ---
$migrationFiles = collectMigrationFiles(REPO_ROOT . '/' . MIGRATION_DIR);
echo '迁移文件数: ' . count($migrationFiles) . PHP_EOL;

// --- 2) 读账本（唯一的数据库读取点；--apply 之外不会出现任何写操作） ---
try {
    $pdo = connectPdo();
    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $rows = $pdo->query('SELECT version, migration_name FROM migrations ORDER BY version')
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo colorize('[ERROR]', 'red') . ' 无法连接数据库读取 migrations 表：' . $e->getMessage() . PHP_EOL;
    echo '提示：本工具必须在 alkaid-backend 容器内执行：'
        . 'docker exec alkaid-backend php scripts/migrate-reconcile.php' . PHP_EOL;
    exit(1);
}

$applied = [];
foreach ($rows as $row) {
    // version 是 bigint，统一转成十进制字符串参与比对
    $version = (string) $row['version'];
    $applied[$version] = (string) ($row['migration_name'] ?? '');
}

echo '目标数据库: ' . colorize($dbName, 'blue') . PHP_EOL;
echo 'applied 记录数: ' . count($applied) . PHP_EOL . PHP_EOL;

// --- 3) 两个方向的失配清单 ---
// 方向 1：账本 -> 文件（孤儿记录，需要处置）
$orphans = [];
foreach ($applied as $version => $name) {
    // 数字字符串作数组下标时 PHP 会自动转成 int，这里转回字符串再参与比较与展示
    $version = (string) $version;
    if (isset($migrationFiles[$version])) {
        continue;
    }
    $orphans[] = ['version' => $version, 'name' => $name];
}

// 方向 2：文件 -> 账本（未应用，通常属正常状态）
$unapplied = [];
foreach ($migrationFiles as $version => $base) {
    $version = (string) $version;
    if (isset($applied[$version])) {
        continue;
    }
    $unapplied[] = ['version' => $version, 'base' => $base];
}

echo '[1] applied 有记录但无对应文件（孤儿记录）：' . count($orphans) . ' 条' . PHP_EOL;
foreach ($orphans as $orphan) {
    $name = '' !== $orphan['name'] ? "（{$orphan['name']}）" : '';
    echo '    - ' . colorize($orphan['version'], 'yellow') . "{$name}" . PHP_EOL;
    echo '      账本里有这条 applied 记录，但 ' . MIGRATION_DIR . "/ 下没有版本 {$orphan['version']} 的迁移文件" . PHP_EOL;
    echo '      处置：确认它确属「改名/删除迁移文件」的遗留后，执行' . PHP_EOL;
    echo "            php scripts/migrate-reconcile.php --apply --version={$orphan['version']} --yes" . PHP_EOL;
}
echo PHP_EOL;

echo '[2] 有文件但未 applied：' . count($unapplied) . ' 条' . PHP_EOL;
foreach ($unapplied as $item) {
    echo '    - ' . colorize($item['version'], 'gray') . "  {$item['base']}" . PHP_EOL;
}
if ([] !== $unapplied) {
    // 强调它「不是问题」：否则看到清单的人会以为也需要清理
    echo '      （属正常状态：新写的迁移在部署前必然处于未应用状态，无需处理）' . PHP_EOL;
}
echo PHP_EOL;

// --- 4) dry-run 到此结束 ---
if (!$options['apply']) {
    if (null !== $options['version']) {
        // 给了 --version 但没给 --apply：只提示目标当前状态，仍然不写库
        echo '[计划] 指定版本 ' . colorize($options['version'], 'yellow') . ' 的当前状态：' . PHP_EOL;
        if (!isset($applied[$options['version']])) {
            echo '    账本里没有该版本的 applied 记录 —— 无需删除' . PHP_EOL;
        } elseif (isset($migrationFiles[$options['version']])) {
            echo '    账本里有记录且仓库里有对应文件 —— 这是正常迁移，**不允许**删除' . PHP_EOL;
        } else {
            echo '    账本里有记录但仓库里没有对应文件 —— 是孤儿记录，可用 --apply 删除' . PHP_EOL;
        }
        echo PHP_EOL;
    }

    printBackupHint();
    echo PHP_EOL;
    echo colorize('本次为 dry-run，未对数据库做任何修改。', 'green') . PHP_EOL;
    exit(0);
}

// --- 5) --apply：参数与安全性校验（全部 fail-closed） ---
$targetVersion = $options['version'];

if (null === $targetVersion || '' === $targetVersion) {
    echo colorize('[REFUSED]', 'red') . ' --apply 必须显式指定 --version=<ver>' . PHP_EOL;
    echo '本工具不做批量猜测：一次只删一条记录，删哪条必须由人指定。' . PHP_EOL;
    echo '先运行 php scripts/migrate-reconcile.php 查看孤儿记录清单。' . PHP_EOL;
    exit(1);
}

if (preg_match('/^\d{8,14}$/', $targetVersion) !== 1) {
    echo colorize('[REFUSED]', 'red') . " --version={$targetVersion} 不是合法的迁移版本号（应为 8~14 位数字）" . PHP_EOL;
    exit(1);
}

if (!isset($applied[$targetVersion])) {
    echo colorize('[REFUSED]', 'red') . " 账本里没有 version={$targetVersion} 的 applied 记录，无可删除" . PHP_EOL;
    echo '（请先用 php scripts/migrate-reconcile.php 确认孤儿记录清单）' . PHP_EOL;
    exit(1);
}

if (isset($migrationFiles[$targetVersion])) {
    echo colorize('[REFUSED]', 'red') . " version={$targetVersion} 在仓库里有对应迁移文件："
        . $migrationFiles[$targetVersion] . PHP_EOL;
    echo '它不是孤儿记录。删除它的 applied 记录会让该迁移被当成「未应用」而重复执行，' . PHP_EOL;
    echo '本工具拒绝这种操作。若确实要重跑该迁移，请走 migrate:rollback / migrate:run 的正规流程。' . PHP_EOL;
    exit(1);
}

$targetName = '' !== $applied[$targetVersion] ? "（{$applied[$targetVersion]}）" : '';
$deleteSql  = "DELETE FROM `migrations` WHERE `version` = {$targetVersion};";

echo '[计划] 将要执行的操作：' . PHP_EOL;
echo '    目标记录：' . colorize($targetVersion . $targetName, 'yellow') . PHP_EOL;
echo '    执行 SQL：' . $deleteSql . PHP_EOL;
echo '    影响范围：migrations 表 1 行（主键 version，最多命中 1 条）' . PHP_EOL;
echo '    不会改动：迁移文件、任何业务表、任何表结构' . PHP_EOL . PHP_EOL;

printBackupHint();
echo PHP_EOL;

// 二次确认：没有 --yes 就只打印计划并退出非 0（数据库此时仍未修改）
if (!$options['yes']) {
    echo colorize('[REFUSED]', 'red') . ' 缺少二次确认参数 --yes，已拒绝执行（数据库未被修改）' . PHP_EOL;
    echo '确认已备份后，重新执行：' . PHP_EOL;
    echo "    php scripts/migrate-reconcile.php --apply --version={$targetVersion} --yes" . PHP_EOL;
    exit(1);
}

// --- 6) 真正执行（本脚本唯一的写操作） ---
$beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();

try {
    // 用预处理占位符而不是字符串拼接：版本号已通过纯数字校验，这里是第二道防线
    $stmt = $pdo->prepare('DELETE FROM `migrations` WHERE `version` = ?');
    $stmt->execute([(int) $targetVersion]);
    $affected = $stmt->rowCount();
} catch (Throwable $e) {
    echo colorize('[ERROR]', 'red') . ' 删除失败：' . $e->getMessage() . PHP_EOL;
    echo '数据库可能处于中间状态，请核对 migrations 表后再决定是否重试。' . PHP_EOL;
    exit(1);
}

$afterCount = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();

echo '删除结果：命中 ' . $affected . ' 行；migrations 记录数 ' . $beforeCount . ' -> ' . $afterCount . PHP_EOL;

if (1 !== $affected) {
    // 主键删除命中 0 行说明状态在读到删之间变了（或版本号写法有差异），不能报成功
    echo colorize('[ERROR]', 'red') . ' 期望命中 1 行，实际命中 ' . $affected . ' 行，请人工核对' . PHP_EOL;
    exit(1);
}

echo colorize('✓ 已删除孤儿记录 ' . $targetVersion . '；请重跑全量检查确认账本一致：', 'green') . PHP_EOL;
echo '    docker exec alkaid-backend php scripts/check-migrations.php --strict' . PHP_EOL;
echo '若检查 F 已无 [MISSING]，请把 scripts/check-migrations.php 里' . PHP_EOL;
echo 'ORPHAN_APPLIED_EXEMPTIONS 中对应版本的豁免条目删除，避免留下僵尸豁免。' . PHP_EOL;

exit(0);
