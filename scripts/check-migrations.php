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
 *   php scripts/check-migrations.php                    # 检查并输出报告
 *   php scripts/check-migrations.php --strict           # 有告警即失败（本地门禁 / 全量）
 *   php scripts/check-migrations.php --strict --no-db    # CI 专用：本环境没有 applied 状态，只跑 A/C
 *
 * 关于 `--no-db`（R41）：
 *   它的语义是「**本环境没有 applied 状态**」，**不是**「允许跳过检查」。
 *   区别在于是否如实报告覆盖范围：传了它，B/D/E 会显式打印「跳过（--no-db）」，
 *   日志能一眼看出「这次只验了静态部分」；不传它而又连不上库时，则仍然是
 *   「告警 + --strict 下 exit 1」——不会因为「反正 CI 无所谓」而把红变绿。
 *
 *   为什么 CI 必须用它：
 *     a. 检查 B/D/E 的判定基准是「哪些迁移已应用」；GitHub Actions 的全新 MySQL 里
 *        `migrations` 表是空的，B 无从判定、D 会把全部历史迁移当「未应用」重新审一遍（误报）；
 *     b. 本仓库新库全量 `migrate:run` 目前跑不通 —— `20251125_add_casbin_manage_permission.php`
 *        的 8 位版本号小于全部 14 位时间戳，Phinx 让它第一个执行，而它依赖尚未创建的
 *        `permissions` 表，第一条迁移就失败；因此 CI 也造不出真实的 applied 状态。
 *   所以 CI 覆盖的是 A/C（纯静态、与环境无关），B/D/E 必须**在本地**跑全量。
 *   传该开关时不会 require vendor/autoload.php，也不会建立任何数据库连接。
 *
 * 退出码：
 *   0 - 通过（`--no-db` 下仅代表 A/C 通过）
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

/**
 * 取出一对括号之间的完整实参文本（做括号配平，忽略字符串内的括号）
 *
 * @param string $code     源码
 * @param int    $openPos  左括号位置
 * @return string
 */
function balancedArgs(string $code, int $openPos): string
{
    $len   = strlen($code);
    $depth = 0;
    $quote = null;

    for ($i = $openPos; $i < $len; $i++) {
        $ch = $code[$i];

        if ($quote !== null) {
            if ($ch === '\\') {
                $i++;
                continue;
            }
            if ($ch === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($ch === "'" || $ch === '"') {
            $quote = $ch;
            continue;
        }

        if ($ch === '(' || $ch === '[') {
            $depth++;
            continue;
        }

        if ($ch === ')' || $ch === ']') {
            $depth--;
            if (0 === $depth) {
                return substr($code, $openPos + 1, $i - $openPos - 1);
            }
        }
    }

    return substr($code, $openPos + 1);
}

/**
 * 检查 tenant_id 的列声明是否合规
 *
 * 规则（与 design/01-architecture-design/04-multi-tenant-design.md 的
 * 「tenant_id 两条语义」一致）：
 *   - 真实租户引用：`integer` + `'signed' => false`（或 `biginteger` 同理），且**无默认值**
 *   - 系统模板空间：允许 `DEFAULT 0`，此时不应建外键（本检查只看类型，不看默认值）
 *   - 一律禁止：`'string'`、未标 unsigned 的 `integer`/`biginteger`、
 *     以及 `Column::integer('tenant_id')`（默认有符号）
 *
 * 只检查**未应用**的迁移：已应用的迁移无法回改，历史遗留不参与判定。
 *
 * @param array<int, array{file:string, base:string, version:int}> $migrations
 * @param int[]|null                                               $applied
 * @return array<int, array{base:string, problem:string}>
 */
function checkTenantIdDeclaration(array $migrations, ?array $applied): array
{
    $issues = [];

    if (null === $applied) {
        return $issues;
    }

    // 全新库（applied 为空）时全部文件都是"未应用"，应当全查
    $appliedSet = array_flip($applied);

    foreach ($migrations as $migration) {
        if (isset($appliedSet[$migration['version']])) {
            continue;
        }

        $code = @file_get_contents($migration['file']);
        if (false === $code) {
            continue;
        }

        // 形如 addColumn('tenant_id', 'biginteger', [...])
        if (preg_match_all('/addColumn\s*\(\s*[\'"]tenant_id[\'"]/', $code, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$matchText, $offset]) {
                $openPos = strpos($code, '(', $offset);
                if (false === $openPos) {
                    continue;
                }

                $args = balancedArgs($code, $openPos);

                // 形如 'tenant_id', 'biginteger', [...] —— 取紧随其后的第二个字符串作为类型
                if (!preg_match('/[\'"]tenant_id[\'"]\s*,\s*[\'"]([a-zA-Z]+)[\'"]/', $args, $typeMatch)) {
                    continue;
                }
                $type = strtolower($typeMatch[1]);

                if ('string' === $type) {
                    $issues[] = [
                        'base'    => $migration['base'],
                        'problem' => 'tenant_id 不得声明为 string，应为 integer + signed => false',
                    ];
                    continue;
                }

                if (!in_array($type, ['integer', 'biginteger'], true)) {
                    continue;
                }

                if (!preg_match('/[\'"]signed[\'"]\s*=>\s*false/', $args)) {
                    $issues[] = [
                        'base'    => $migration['base'],
                        'problem' => "tenant_id 声明为 {$type} 但未标 signed => false（会与 tenants.id(int unsigned) 不匹配）",
                    ];
                }
            }
        }

        // 形如 Column::integer('tenant_id')
        if (preg_match_all('/Column::integer\s*\(\s*[\'"]tenant_id[\'"]/', $code, $columnMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($columnMatches[0] as [$matchText, $offset]) {
                $openPos = strpos($code, '(', $offset);
                if (false === $openPos) {
                    continue;
                }

                $args = balancedArgs($code, $openPos);

                if (!str_contains($args, 'setUnsigned(true)')) {
                    $issues[] = [
                        'base'    => $migration['base'],
                        'problem' => 'Column::integer(\'tenant_id\') 默认有符号，需链式调用 setUnsigned(true)',
                    ];
                }
            }
        }
    }

    return $issues;
}

/**
 * 检查 E 用的已知例外基线
 *
 * 保留机制，供将来复用；**当前为空**：`lowcode_forms` 已于迁移
 * `20251224000001_add_tenant_fk_to_lowcode_forms` 收口 ——
 *   1. 列类型 bigint unsigned → int unsigned（与 tenants.id 一致）；
 *   2. 补建外键 fk_lowcode_forms_tenant_id → tenants.id（CASCADE/CASCADE）；
 *   3. 低代码 CLI `lowcode:create-form` 的 --tenant-id 默认值 0 → 1，
 *      且显式传 0 时直接给出业务错误（0 = 系统模板空间，该表要求真实租户）。
 *
 * 因此全库「无默认值却不带租户外键」的表为 0 张，检查 E 已无例外需要放行。
 * 若将来确需临时接受某张表，请在此登记表名并写清归属待决的原因与去向。
 *
 * @var string[]
 */
const KNOWN_TENANT_FK_EXCEPTIONS = [];

/**
 * 检查 E：无默认值的 tenant_id 必须有 tenant_id → tenants.id 外键
 *
 * 依据：审计实测 224 张含 tenant_id 的表，无默认值的 11 张里 10 张有外键，
 * 有默认值的 213 张零外键 —— 二分法零例外。本条检查守住「无默认值」这一侧。
 *
 * @return array<int, string>|null 违规表名列表；无法连库时返回 null
 */
function loadTenantFkViolations(): ?array
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

        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!$schema) {
            return null;
        }

        $sql = <<<'SQL'
            SELECT c.table_name
            FROM information_schema.columns c
            LEFT JOIN information_schema.key_column_usage k
                   ON k.table_schema = c.table_schema
                  AND k.table_name   = c.table_name
                  AND k.column_name  = 'tenant_id'
                  AND k.referenced_table_name = 'tenants'
            WHERE c.table_schema = ?
              AND c.column_name  = 'tenant_id'
              AND c.column_default IS NULL
              AND k.constraint_name IS NULL
            ORDER BY c.table_name
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$schema]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

$strict = in_array('--strict', $argv, true);

// R41：`--no-db` = 「本环境没有 applied 状态」（CI 专用），不是「允许跳过检查」。
// 传了它就完全不碰 vendor/autoload.php、不建数据库连接：B/D/E 显式跳过，A/C 照常判定。
$noDb = in_array('--no-db', $argv, true);

echo colorize('Migration Safety Check | 迁移安全守卫', 'blue') . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;

if ($noDb) {
    // 把「本次覆盖范围」写在最显眼的位置：避免 CI 日志被误读成「全量检查通过」
    echo colorize('模式: --no-db（无 applied 状态，仅检查 A/C；本地请跑全量）', 'yellow') . PHP_EOL;
}

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
// `--no-db` 时不调用 loadAppliedVersions()：既不 require vendor/autoload.php，也不建库连接
$applied = $noDb ? null : loadAppliedVersions();
if (null === $applied) {
    if ($noDb) {
        printf("检查 B  未应用迁移版本顺序      %s\n", colorize('跳过（--no-db）', 'gray'));
        echo '   ' . colorize('[SKIP]', 'gray')
            . ' --no-db：本环境没有 applied 状态，无从判定「未应用迁移的 version 是否倒挂」' . PHP_EOL;
    } else {
        printf("检查 B  未应用迁移版本顺序      %s\n", formatResult(0, 1));
        echo '   ' . colorize('[WARN]', 'yellow') . ' 无法连接数据库读取 migrations 表，已跳过顺序检查' . PHP_EOL;
        $warningCount++;
    }
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

// --- 检查 D：tenant_id 列声明必须与 tenants.id 类型一致 ---
// 注意：D 同样依赖 applied 集合（只审「未应用」的迁移），所以 --no-db 的覆盖范围是 A/C，
// **不是** A/C/D —— 没有 applied 状态时，把全部历史迁移当未应用重审只会得到一堆无法回改的误报。
if ($noDb) {
    printf("检查 D  tenant_id 列声明        %s\n", colorize('跳过（--no-db）', 'gray'));
    echo '   ' . colorize('[SKIP]', 'gray')
        . ' --no-db：检查 D 需要 applied 集合来圈定「未应用的迁移」，本环境没有' . PHP_EOL;
} else {
    $tenantIssues = checkTenantIdDeclaration($migrations, $applied);
    printf("检查 D  tenant_id 列声明        %s\n", formatResult(count($tenantIssues), 0));
    foreach ($tenantIssues as $issue) {
        echo '   ' . colorize('[TENANT]', 'red') . " {$issue['base']}：{$issue['problem']}" . PHP_EOL;
    }
    $errorCount += count($tenantIssues);
}

// --- 检查 E：无默认值的 tenant_id 必须有租户外键 ---
// `--no-db` 时不调用 loadTenantFkViolations()：不 require vendor/autoload.php，也不查询 information_schema
$fkViolations = $noDb ? null : loadTenantFkViolations();
if (null === $fkViolations) {
    if ($noDb) {
        printf("检查 E  租户外键一致性          %s\n", colorize('跳过（--no-db）', 'gray'));
        echo '   ' . colorize('[SKIP]', 'gray')
            . ' --no-db：检查 E 依赖真实表结构（information_schema），本环境没有数据库' . PHP_EOL;
    } else {
        printf("检查 E  租户外键一致性          %s\n", formatResult(0, 1));
        echo '   ' . colorize('[WARN]', 'yellow') . ' 无法连接数据库读取表结构，已跳过租户外键检查' . PHP_EOL;
        $warningCount++;
    }
} else {
    $fkErrors = [];
    $fkWarnings = [];
    foreach ($fkViolations as $tableName) {
        if (in_array($tableName, KNOWN_TENANT_FK_EXCEPTIONS, true)) {
            $fkWarnings[$tableName] = true;
        } else {
            $fkErrors[] = $tableName;
        }
    }

    printf("检查 E  租户外键一致性          %s\n", formatResult(count($fkErrors), 0));
    foreach ($fkErrors as $tableName) {
        echo '   ' . colorize('[FK]', 'red')
            . " {$tableName}.tenant_id 无默认值却没有 tenant_id → tenants.id 外键"
            . '（按「无默认值 = 真实租户引用」规则应当有）' . PHP_EOL;
    }
    // 已知例外只做灰色提示，不计入告警：
    // 否则 --strict 会因这条待决项永久变红，门禁失去意义
    // （与检查 C 对 LEGACY_CHANGE_ONLY 的处理保持一致）。
    foreach (array_keys($fkWarnings) as $tableName) {
        echo '   ' . colorize('[INFO]', 'gray')
            . " {$tableName}.tenant_id 属已知例外（归属待决，见 KNOWN_TENANT_FK_EXCEPTIONS）" . PHP_EOL;
    }

    $errorCount += count($fkErrors);
}

echo str_repeat('-', 64) . PHP_EOL;

if ($noDb) {
    // 覆盖范围提醒：读 CI 日志的人不应该把这次运行当成「全量通过」
    echo colorize(
        '提示：--no-db 只覆盖 A/C（纯静态）。B/D/E 需要 applied 状态，请在本地跑全量：'
        . 'php scripts/check-migrations.php --strict',
        'gray'
    ) . PHP_EOL;
}

if (0 === $errorCount && 0 === $warningCount) {
    if ($noDb) {
        echo colorize('✓ 静态检查（A/C）通过；B/D/E 已跳过（--no-db）', 'green') . PHP_EOL;
    } else {
        echo colorize('✓ 全部检查通过', 'green') . PHP_EOL;
    }
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
