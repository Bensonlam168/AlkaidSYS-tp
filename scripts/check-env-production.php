#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Production Environment Precheck | 生产配置预检（不启动 App）
 *
 * ## 为什么要有这个脚本（`env:check` 覆盖不到的那段时间）
 *
 * `php think env:check` 已经能按生产标准判定 `DEFAULT_TENANT_ID` / `DB_*` / `JWT_SECRET` /
 * `APP_DEBUG` 的取值，但它有一个**结构性的**硬限制：它是 ThinkPHP 命令，必须先启动整个 App。
 * 而 `config/cache.php` 在生产类环境**强制** `cache.default = redis`，
 * `app\provider\RedisHealthCheckService` 又在启动期做一次 set/get 自检并 fail-closed。
 *
 * 实测（照抄 `.env.production.example` 生成一份 `.env.production`，把 REDIS 指向一个关闭的端口）：
 *
 *     $ PHP_ENV_NAME=production php think env:check
 *     [RuntimeException]
 *     Redis health check failed (host=127.0.0.1, port=6399): Connection refused
 *
 * 也就是说：**最需要预检的时刻（生产配置还没填对、依赖可能也不通）恰恰跑不了预检**。
 * 而且这类故障会同时掩盖真正的问题 —— 你看到的是 Redis 连不上，
 * 而 `DEFAULT_TENANT_ID` 还是占位符这件事根本没人告诉你。
 *
 * 本脚本**不 new App()、不 initialize()、不 require vendor/autoload.php**，
 * 只做三件事：读文件 → 解析 → 套用规则。因此它**不依赖 Redis、不依赖 DB**，
 * 甚至在还没 `composer install` 的机器上也能跑（只 require 一个零依赖的类文件）。
 *
 * ## 检查项
 *
 *   A. 文件存在且可解析：`parse_ini_file(..., INI_SCANNER_RAW)` 不得返回 false。
 *      这与 `think\Env::load()` 的调用方式**完全一致**，而 `Env::load()` 的实现是
 *      `parse_ini_file(...) ?: []` —— 解析失败即**整份配置静默失效**
 *      （典型成因：`#` 注释行里的半角括号 / 引号 / 竖线 / 与号 / 美元符号）。
 *      因此这里必须 fail-closed：**解析失败 = 退出码 1**，不得伪装成通过。
 *   B. 生产必需项取值：套用 `app\support\EnvRequirementCatalog` 里的规则
 *      （与 `env:check` **同一份源码**，见下）。
 *   C. 条件必需项：`TRUSTED_PROXIES` 仅在 `RATELIMIT_ENABLED=true` 时告警（不阻断，
 *      `--strict` 下才失败）。理由见 `EnvCheckCommand::checkConditionalRequirements()`。
 *
 * ## 单一真相源
 *
 * 「哪些键是生产必需、各自什么规则、不合格怎么改」全部来自
 * `app/support/EnvRequirementCatalog.php`。本脚本**不复制任何规则**，
 * 只负责「从文件里取值」和「排版输出」。因此它与 `env:check` 不可能漂移。
 *
 * ## 边界（很重要，别把它当成「部署前万能检查」）
 *
 * 能做的事：判定**这一个文件里写的值**是否符合生产要求。
 *
 * **不能**做的事（逐条直说，避免误用）：
 *   1. **看不到运行期实际生效值**。`env()` 的取值优先级是「环境文件 > 进程环境」，
 *      且 `PHP_ENV_NAME` 会整体替换环境文件名。本脚本只读你指定的那一个文件；
 *      容器里真正生效的值可能来自 `docker compose` 的 `environment:` 或编排系统的 secret。
 *   2. **不检查连通性**。DB / Redis 能不能连、库名对不对，它一概不知道 ——
 *      那属于 `/readyz` 与启动期自检的职责（也正是「不启动 App」换来的代价）。
 *   3. **不检查语义存在性**。`DEFAULT_TENANT_ID=1` 只证明它是正整数，
 *      不证明 `tenants` 表里真有 id=1。
 *   4. **不比对键集合是否齐全**。它只判定自己规则表里的 8 个键；
 *      「模板少配了 12 个键」由 `php scripts/check-env-example.php --strict` 负责。
 *
 * 一句话：**它保证「这份配置的取值形态是对的」，不保证「这台机器现在能跑」。**
 *
 * 用法：
 *   php scripts/check-env-production.php                          # 默认读 .env.production
 *   php scripts/check-env-production.php --env-file=/path/to/.env.prod
 *   php scripts/check-env-production.php --env-file=.env.production.example   # 模板自检（预期失败）
 *   php scripts/check-env-production.php --strict                  # 条件必需项的告警也算失败
 *
 * 退出码：
 *   0 - 通过（`--strict` 下要求连告警都没有）
 *   1 - 存在错误；或文件不存在 / 无法解析（不伪装成通过）；或 --strict 下存在告警
 *
 * @package scripts
 */

use app\support\EnvRequirementCatalog;

// ---------------------------------------------------------------------------
// 配置
// ---------------------------------------------------------------------------

/** 仓库根目录（脚本位于 scripts/ 下） */
const REPO_ROOT = __DIR__ . '/..';

/** 默认检查的环境文件（相对仓库根） */
const DEFAULT_ENV_FILE = '.env.production';

/** 规则目录类（相对仓库根）—— 零依赖，可直接 require，无需 vendor/ */
const CATALOG_FILE = 'app/support/EnvRequirementCatalog.php';

/**
 * 条件必需项依赖的开关（与 env:check 的判定同源）
 *
 * 单独列成常量是为了让「为什么只在这一个条件下才检查 TRUSTED_PROXIES」在脚本里可读，
 * 而不是散落在 if 里。
 */
const RATELIMIT_SWITCH = 'RATELIMIT_ENABLED';

/** 条件必需项：经反向代理且开启限流时才必需 */
const CONDITIONAL_KEY = 'TRUSTED_PROXIES';

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
 * 格式化单项检查结果（与 check-migrations.php / check-env-example.php 同一风格）
 */
function formatResult(int $errors, int $warnings = 0): string
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
 * 输出一条检查明细：`   [TAG] 文本`
 */
function printIssue(string $tag, string $text, string $color = 'red'): void
{
    echo '   ' . colorize("[{$tag}]", $color) . " {$text}" . PHP_EOL;
}

/**
 * 输出明细的补充说明：`      label 文本`
 */
function printDetail(string $label, string $text): void
{
    echo '      ' . colorize($label . ':', 'gray') . ' ' . $text . PHP_EOL;
}

/**
 * 打印用法
 */
function printUsage(): void
{
    echo 'Usage | 用法:' . PHP_EOL;
    echo '  php scripts/check-env-production.php [--env-file=<path>] [--strict]' . PHP_EOL . PHP_EOL;
    echo '  --env-file=<path>  要检查的环境文件；相对路径先按仓库根解析，再按当前工作目录（默认 ' . DEFAULT_ENV_FILE . '）' . PHP_EOL;
    echo '  --strict           条件必需项的告警也算失败（CI 门禁模式）' . PHP_EOL;
    echo '  --help             显示本帮助' . PHP_EOL . PHP_EOL;
    echo '退出码: 0 通过 / 1 不合格（含文件不存在或无法解析）' . PHP_EOL;
}

/**
 * 手动解析参数
 *
 * 刻意不用 getopt()：它在「缺参数值」时会静默跳过，而本脚本的参数决定「检查哪个文件」——
 * 静默跳过等于去检查默认文件，然后给你一个关于**别的文件**的结论。
 * 两种写法（`--env-file=x` 与 `--env-file x`）都接受，其余任何未知参数一律报错。
 *
 * @param array<int, string> $argv
 * @return array{strict: bool, help: bool, envFile: ?string, error: ?string}
 */
function parseArguments(array $argv): array
{
    $options = ['strict' => false, 'help' => false, 'envFile' => null, 'error' => null];
    $count   = count($argv);

    for ($i = 1; $i < $count; $i++) {
        $arg = (string) $argv[$i];

        if ($arg === '--strict') {
            $options['strict'] = true;
            continue;
        }

        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--env-file=')) {
            $options['envFile'] = substr($arg, strlen('--env-file='));
            continue;
        }

        if ($arg === '--env-file') {
            $next = $argv[$i + 1] ?? '';
            if ($next === '') {
                $options['error'] = '--env-file 缺少取值（例如 --env-file=.env.production）';

                return $options;
            }
            $options['envFile'] = (string) $next;
            $i++;
            continue;
        }

        $options['error'] = "未知参数：{$arg}";

        return $options;
    }

    return $options;
}

/**
 * 把 `--env-file` 的取值解析成绝对路径
 *
 * 相对路径的解析顺序：仓库根 → 当前工作目录。之所以先按仓库根：
 * 本脚本最常被这样调用（`--env-file=.env.production.example` 做模板自检），
 * 而调用者可能站在任意工作目录（部署脚本、CI、容器内 `docker exec`）。
 *
 * @param string|null $given       命令行给的取值（null 表示用默认文件）
 * @return array{candidates: array<int, string>, resolved: ?string}
 */
function resolveEnvFilePath(?string $given): array
{
    $repoRoot = realpath(REPO_ROOT) ?: REPO_ROOT;

    if ($given === null || $given === '') {
        $path = $repoRoot . '/' . DEFAULT_ENV_FILE;

        return ['candidates' => [$path], 'resolved' => is_file($path) ? $path : null];
    }

    // 绝对路径（Unix 与 Windows 两种形态）原样使用
    if (str_starts_with($given, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $given) === 1) {
        return ['candidates' => [$given], 'resolved' => is_file($given) ? $given : null];
    }

    $candidates = [$repoRoot . '/' . $given];
    $resolved   = is_file($candidates[0]) ? $candidates[0] : null;

    if ($resolved === null) {
        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '' && $cwd !== $repoRoot) {
            $candidates[] = $cwd . '/' . $given;
            $resolved     = is_file($candidates[1]) ? $candidates[1] : null;
        }
    }

    return ['candidates' => $candidates, 'resolved' => $resolved];
}

/**
 * 读取并解析环境文件（捕获 PHP 语法错误，带回行号与出错行原文）
 *
 * 为什么不直接用 error_get_last()：parse_ini_file 的语法错误是一条 E_WARNING，
 * 而 error_get_last() 只能拿到「最近一次错误」，在脚本已有其它告警时会拿错。
 * 用 set_error_handler() 精确捕获本次调用产生的告警更可靠（与 check-env-example.php 同源）。
 *
 * @return array{ok: bool, message: string, line: ?int, data: array<string, mixed>}
 */
function parseEnvFile(string $path): array
{
    $result = ['ok' => false, 'message' => '文件不存在', 'line' => null, 'data' => []];

    if (!is_file($path)) {
        return $result;
    }

    $warning = null;
    set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
        // 只记录第一条告警：语法错误一条即足够定位
        $warning ??= $message;

        return true;
    });
    try {
        $data = parse_ini_file($path, true, INI_SCANNER_RAW);
    } finally {
        restore_error_handler();
    }

    if ($data === false) {
        // 语法错误告警可能带尾随换行，去掉避免输出多出空行
        $result['message'] = trim($warning ?? '解析失败（未产生 PHP 告警）');
        if ($warning !== null && preg_match('/on line (\d+)/', $warning, $m) === 1) {
            $result['line'] = (int) $m[1];
        }

        return $result;
    }

    $result['ok']   = true;
    $result['data'] = $data;

    return $result;
}

/**
 * 取出文件第 N 行的原文（用于把「第几行出错」变成「哪一行出错」）
 *
 * 名字刻意不叫 readLine()：PHP 函数名大小写不敏感，`readLine` 会与内置的
 * `readline()`（readline 扩展）撞名，直接变成「Cannot redeclare」致命错误。
 */
function readEnvLine(string $path, ?int $lineNo): ?string
{
    if ($lineNo === null || $lineNo < 1) {
        return null;
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }

    try {
        $current = 0;
        while (($line = fgets($handle)) !== false) {
            $current++;
            if ($current === $lineNo) {
                return rtrim($line, "\r\n");
            }
        }
    } finally {
        fclose($handle);
    }

    return null;
}

/**
 * 把解析结果整理成「扁平大写键 => 字符串值」
 *
 * `[section]` 写法会产生嵌套数组。`.env` 是扁平键，出现分段说明文件被写坏了，
 * 因此**不计入可用键**（记成 null 即会由 not_empty 规则报「未配置或为空」，不会静默放过）。
 *
 * @param array<string, mixed> $data
 * @return array<string, string>
 */
function flattenEnvData(array $data): array
{
    $flat = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            continue;
        }

        // 键名统一成大写：`env()` 内部也是大写字（Env::set() 会 strtoupper）
        $flat[strtoupper((string) $key)] = is_scalar($value) ? (string) $value : '';
    }

    return $flat;
}

/**
 * 按规则目录检查一个键，返回明细行（已脱敏）
 *
 * @param string              $name   键名
 * @param array<string,string> $values 文件里的键值
 * @return array{name: string, errors: array<int, string>, notices: array<int, string>, configured: bool}
 */
function inspectRequirement(string $name, array $values): array
{
    $configured = array_key_exists($name, $values);
    $value      = $configured ? $values[$name] : null;

    // 脚本的立场固定是「按生产标准判定」：它存在的理由就是「你准备上生产了，先看看」。
    // 因此这里刻意不看 APP_ENV 来决定要不要检查（那会让「APP_ENV 写错」顺带把其它键全放过），
    // APP_ENV 自身由 production_env 规则判定。
    $evaluated = EnvRequirementCatalog::evaluate($name, $value, true);

    return [
        'name'       => $name,
        'errors'     => $evaluated['errors'],
        'notices'    => $evaluated['notices'],
        'configured' => $configured,
    ];
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

$options = parseArguments($argv);

if ($options['error'] !== null) {
    fwrite(STDERR, colorize('参数错误: ', 'red') . $options['error'] . PHP_EOL . PHP_EOL);
    printUsage();
    exit(1);
}

if ($options['help']) {
    printUsage();
    exit(0);
}

// 规则目录：优先走 composer autoload（若已注册），否则直接 require 源文件。
// 直接 require 是刻意设计的：本类零依赖，因此本脚本在 `composer install` 之前也能跑。
if (!class_exists(EnvRequirementCatalog::class)) {
    require_once __DIR__ . '/../' . CATALOG_FILE;
}

echo colorize('Production Environment Precheck | 生产配置预检（不启动 App）', 'blue') . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;

if (!class_exists(EnvRequirementCatalog::class, false)) {
    // 类都没加载成功时任何「逐键判定」都不可信，必须立刻失败（fail-closed）
    echo colorize('致命错误: 无法加载规则目录 ' . CATALOG_FILE, 'red') . PHP_EOL;
    echo '不启动 App 的预检依赖该类；它缺失时本脚本无法给出任何可信结论。' . PHP_EOL;
    exit(1);
}

$strict = $options['strict'];
$target = resolveEnvFilePath($options['envFile']);

echo '仓库根: ' . (realpath(REPO_ROOT) ?: REPO_ROOT) . PHP_EOL;
echo '环境文件: ' . ($options['envFile'] === null ? DEFAULT_ENV_FILE . '（默认）' : (string) $options['envFile']) . PHP_EOL;
echo '运行方式: 不 new App() / 不 initialize() —— 因此不依赖 Redis、不依赖 DB' . PHP_EOL;
echo '规则来源: ' . CATALOG_FILE . '（与 php think env:check 同一份，零依赖）' . PHP_EOL;
if ($strict) {
    echo colorize('模式: --strict（条件必需项的告警也视为失败）', 'yellow') . PHP_EOL;
}
echo PHP_EOL;

$errorCount   = 0;
$warningCount = 0;

// --- 检查 A：文件存在且可解析 ---
$aErrors = [];
$values  = [];
$path    = $target['resolved'];
$parsed  = $path === null ? null : parseEnvFile($path);

if ($path === null) {
    $aErrors[] = '文件不存在';
} elseif (!$parsed['ok']) {
    $aErrors[] = '无法解析';
} else {
    $values = flattenEnvData($parsed['data']);
}

printf("检查 A  文件存在且可解析          %s\n", formatResult(count($aErrors)));

if ($path === null) {
    $printPath = $target['candidates'][0] ?? DEFAULT_ENV_FILE;
    printIssue('FILE', "环境文件不存在：{$printPath}");
    printDetail('说明', '本预检不跳过缺失文件 —— 没有文件就没有任何可判定的取值，'
        . '此时「通过」是假的');
    if (count($target['candidates']) > 1) {
        printDetail('已尝试', implode(' / ', $target['candidates']));
    }
    printDetail('修复', '先按 .env.production.example 生成 .env.production 并按实际环境替换占位符');
} elseif ($aErrors !== []) {
    $line = $parsed['line'];
    $raw  = readEnvLine($path, $line);

    printIssue('PARSE', '环境文件无法解析' . ($line === null ? '' : "（第 {$line} 行）")
        . '：' . $parsed['message']);
    printDetail('文件', $path);
    if ($raw !== null) {
        printDetail('出错行原文', $raw);
    }
    printDetail('常见成因', '注释行里的半角括号 / 引号 / 竖线 / 与号 / 美元符号'
        . '（它们都参与 INI 词法分析，会直接让 parse_ini_file 报语法错误）');
    printDetail('后果', 'think\Env::load() 的实现是 parse_ini_file(...) ?: []，'
        . '解析失败会把整份配置按空数组兜底 → 所有键静默失效'
        . '（容器起来了但连不上库 / 鉴权全挂）');
    printDetail('说明', '已跳过逐键检查 —— 一个键都读不到，继续检查只会刷出一堆误导性的「未配置或为空」');
    printDetail('修复', '.env 注释里不要出现半角 ASCII 括号与引号，改用全角（），见 AGENTS.md Configuration & Security');
} else {
    printDetail('目标文件', $path);
    echo '解析结果: ' . count($values) . ' 个键' . PHP_EOL;
}

$errorCount += count($aErrors);
echo PHP_EOL;

// 解析失败 / 文件缺失时不再逐键判定：结论一定不可信，且会把真正的原因埋在噪音里
if ($aErrors === []) {
    // --- 检查 B：生产必需项取值（规则来自单一真相源） ---
    $requirements = EnvRequirementCatalog::requirements();
    $bErrors      = 0;
    $details      = [];

    foreach ($requirements as $name => $requirement) {
        $result = inspectRequirement($name, $values);
        if ($result['errors'] !== []) {
            $details[] = $result;
        }
    }

    printf("检查 B  生产必需项取值            %s\n", formatResult(count($details)));
    printDetail('覆盖', sprintf(
        '%d 个键（%s）',
        count($requirements),
        implode(', ', EnvRequirementCatalog::names())
    ));

    foreach ($details as $result) {
        $name  = $result['name'];
        $value = array_key_exists($name, $values) ? $values[$name] : null;

        printIssue('REQ', sprintf('%s  当前值: %s', $name, EnvRequirementCatalog::describeValue($name, $value)));
        foreach ($result['errors'] as $reason) {
            echo '      · ' . $reason . PHP_EOL;
        }

        $hint = (string) (EnvRequirementCatalog::requirement($name)['production_hint'] ?? '');
        if ($hint !== '') {
            printDetail('修复', $hint);
        }
        $bErrors++;
    }

    if ($details === []) {
        printIssue('OK', '全部生产必需项的取值形态合格（占位符 / 正整数 / 长度 / root / bool 均已判定）', 'green');
    }

    $errorCount += $bErrors;
    echo PHP_EOL;

    // --- 检查 C：条件必需项 ---
    $ratelimitRaw     = $values[RATELIMIT_SWITCH] ?? null;
    $ratelimitEnabled = filter_var($ratelimitRaw, FILTER_VALIDATE_BOOL);
    $conditionalRaw   = $values[CONDITIONAL_KEY] ?? null;
    $conditionalText  = trim(EnvRequirementCatalog::valueToString($conditionalRaw));

    if (!$ratelimitEnabled) {
        printf("检查 C  条件必需项                %s\n", colorize('跳过（条件不成立）', 'gray'));
        printIssue('INFO', sprintf(
            '%s 未开启（当前取值: %s），本项不被消费',
            RATELIMIT_SWITCH,
            EnvRequirementCatalog::describeValue(RATELIMIT_SWITCH, $ratelimitRaw)
        ), 'gray');
    } elseif ($conditionalText === '') {
        printf("检查 C  条件必需项                %s\n", formatResult(0, 1));
        printIssue('COND', '未配置：经反向代理且开启限流时，所有客户端会共用同一个 IP 限流桶（可能整站 429）', 'yellow');
        printDetail('条件', '仅当「请求经反向代理」且「' . RATELIMIT_SWITCH . '=true」时必需；客户端直连可忽略本条');
        printDetail('修复', '填真实反向代理 / 负载均衡器地址（逗号分隔，支持 IPv4 / IPv6 / CIDR），例如 10.0.0.5');
        $warningCount++;
    } else {
        $entries = array_filter(
            array_map('trim', explode(',', $conditionalText)),
            static fn (string $item): bool => $item !== ''
        );

        printf("检查 C  条件必需项                %s\n", formatResult(0, 0));
        printDetail('已配置', sprintf('%s（%d 个条目）', CONDITIONAL_KEY, count($entries)));
    }

    echo PHP_EOL;
}

// --- 边界声明 + 汇总 ---
echo str_repeat('-', 64) . PHP_EOL;
echo colorize('覆盖范围: 只读环境文件的内容；不启动 App，不连 Redis / DB', 'gray') . PHP_EOL;
echo colorize('不可见: 运行期实际生效值（进程环境 / PHP_ENV_NAME 覆盖）、依赖连通性、'
    . '租户是否存在、键集合是否齐全', 'gray') . PHP_EOL;

// 结论文案必须与退出码一致：不出现「exit 0 但显示检查未通过」这种自相矛盾的输出
// （门禁输出一旦与退出码对不上，读者只能靠猜——那比没有输出更糟）。
if ($errorCount > 0) {
    echo sprintf(
        "%s（错误 %d 个，告警 %d 个）\n",
        colorize('检查未全部通过 | Some checks failed', 'red'),
        $errorCount,
        $warningCount
    );
    exit(1);
}

if ($warningCount > 0) {
    if ($strict) {
        echo sprintf(
            "%s（错误 0 个，告警 %d 个）\n",
            colorize('检查未全部通过 | Some checks failed', 'red'),
            $warningCount
        );
        echo colorize('--strict 模式下告警视为失败', 'red') . PHP_EOL;
        exit(1);
    }

    echo colorize('✓ 全部检查通过 | All checks passed', 'green')
        . colorize(sprintf('（另有 %d 个告警，未阻断；--strict 下会失败）', $warningCount), 'yellow') . PHP_EOL;
    exit(0);
}

echo colorize('✓ 全部检查通过 | All checks passed', 'green') . PHP_EOL;
exit(0);
