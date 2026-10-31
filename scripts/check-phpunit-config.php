#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PHPUnit Config Consistency Check | phpunit 配置一致性检查（R30）
 *
 * 背景：
 *   phpunit.xml 用 <env ... force="true"> 钉死「测试语义变量」（APP_ENV / ALLOW_RUNTIME_DDL /
 *   CACHE_FILE_PREFIX / RATELIMIT_*）。这些变量不加 force 时，shell 导出的同名变量会直接抢占
 *   （PHPUnit 11 PhpHandler::handleEnvVariables：`if ($force || getenv($name) === false)`），
 *   测试语义随机器漂移：本地通过、CI 或同事机器上随机失败。
 *
 *   变体配置（phpunit.t048.xml / phpunit.t050.xml）是从 phpunit.xml 分叉出来的。
 *   一旦基线新增了 force 项而变体没跟上，该变体下的用例就会**悄悄失去保护** ——
 *   这类回归不会让任何用例变红，只能靠本脚本拦截。
 *
 * 检查项：
 *   A. phpunit.xml 的 forced env 必须是每个变体 forced env 的子集（缺哪个点名哪个），
 *      且同名变量的**取值必须完全一致**（值漂移等于换了语义）；
 *      同时校验 force 属性只写 true/false —— PHPUnit 的 booleanFromString() 只认这两个值，
 *      写成 force="1" 会被静默当成 false（等于没 force），是最隐蔽的一种失效。
 *   B. DB_* 一律不得 force：连接参数必须跟随运行环境，理由见 phpunit.xml 的 A 段注释。
 *   C. 变体不得出现 phpunit.xml 没有的 forced env（防止某个变体偷偷钉死 APP_DEBUG 之类）。
 *      若有新增需求，必须先加到 phpunit.xml。
 *   D. 仓库根下所有 phpunit*.xml 都必须登记（纳入门禁或显式豁免），
 *      防止新增第四份变体配置绕过本脚本。
 *
 * 缺失配置文件一律**报错**而不是跳过：漏掉一个变体就等于漏检。
 *
 * 用法：
 *   php scripts/check-phpunit-config.php              # 检查并输出报告
 *   php scripts/check-phpunit-config.php --strict     # 告警也视为失败（CI 门禁）
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

/** 仓库根目录 */
const REPO_ROOT = __DIR__ . '/..';

/** 基线配置：所有 force 项的唯一真相源 */
const BASE_CONFIG = 'phpunit.xml';

/**
 * 受门禁约束的变体配置
 *
 * 新增变体必须同时登记到这里（或下方的豁免清单），否则检查 D 会报错。
 */
const VARIANT_CONFIGS = [
    'phpunit.t048.xml',
    'phpunit.t050.xml',
];

/**
 * 显式豁免的配置（basename => 豁免理由）
 *
 * 豁免只对检查 A / C 生效（变体覆盖关系）；检查 B（DB_* 不得 force）对**所有**配置生效，
 * 因为没有哪种配置有理由把连接参数钉死。
 */
const EXEMPT_CONFIGS = [
    'phpunit.performance.xml' => '性能/压测专用配置，只声明不带 force 的 APP_DEBUG / PERFORMANCE_TEST，不属于 T-048/T-050 验收链',
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
 * 读取一份 phpunit 配置里的全部 <env> 声明
 *
 * 只解析 `<phpunit><php><env .../></php></phpunit>` 这一层，不做 schema 校验：
 * 本脚本关心的是「变量名 + 取值 + 是否 force」，其余结构交给 PHPUnit 自己校验。
 *
 * @return array{ok:bool, message:string, envs:array<int, array{name:string, value:string, force:bool, forceRaw:?string, line:int}>}
 */
function readEnvDeclarations(string $path): array
{
    $result = ['ok' => false, 'message' => '', 'envs' => []];

    if (!is_file($path)) {
        $result['message'] = '配置文件不存在';

        return $result;
    }

    $content = @file_get_contents($path);
    if ($content === false) {
        $result['message'] = '配置文件无法读取';

        return $result;
    }

    // 关闭外部实体加载（LIBXML_NONET），避免脚本被恶意 XML 拖慢
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($content, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded || $dom->documentElement === null) {
        $result['message'] = 'XML 解析失败';

        return $result;
    }

    foreach ($dom->documentElement->childNodes as $php) {
        if (!$php instanceof DOMElement || $php->nodeName !== 'php') {
            continue;
        }
        foreach ($php->childNodes as $env) {
            if (!$env instanceof DOMElement || $env->nodeName !== 'env') {
                continue;
            }
            // force 的语义与 PHPUnit 的 Xml\Loader::booleanFromString() 保持一致：
            // 只有 "true"（忽略大小写）才算强制，其他取值一律回落 false。
            $forceRaw = $env->hasAttribute('force') ? $env->getAttribute('force') : null;
            $result['envs'][] = [
                'name'     => $env->getAttribute('name'),
                'value'    => $env->getAttribute('value'),
                'force'    => $forceRaw !== null && strtolower(trim($forceRaw)) === 'true',
                'forceRaw' => $forceRaw,
                'line'     => $env->getLineNo(),
            ];
        }
    }

    $result['ok'] = true;

    return $result;
}

/**
 * 提取「被 force 的变量」，形如 name => value
 *
 * @param array<int, array{name:string, value:string, force:bool}> $envs
 * @return array<string, string>
 */
function forcedMap(array $envs): array
{
    $map = [];
    foreach ($envs as $env) {
        if ($env['force']) {
            $map[$env['name']] = $env['value'];
        }
    }
    ksort($map);

    return $map;
}

/**
 * 按文件路径读取配置；文件不在仓库根时返回「不存在」结构
 *
 * @return array{ok:bool, message:string, envs:array<int, array<string, mixed>>}
 */
function readConfig(string $basename): array
{
    return readEnvDeclarations(REPO_ROOT . '/' . $basename);
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

$strict = in_array('--strict', $argv, true);

echo colorize('PHPUnit Config Consistency Check | phpunit 配置一致性检查', 'blue') . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;

// 仓库根下实际存在的 phpunit*.xml（检查 D 用：未登记的配置不许存在）
$discovered = [];
foreach (glob(REPO_ROOT . '/phpunit*.xml') ?: [] as $file) {
    $discovered[] = basename($file);
}
sort($discovered);

$required = array_merge([BASE_CONFIG], VARIANT_CONFIGS);
$registered = array_merge($required, array_keys(EXEMPT_CONFIGS));

// 读取所有已发现的配置；required 里缺失的补占位，保证「缺失即报错」
$configs = [];
foreach ($discovered as $name) {
    $configs[$name] = readConfig($name);
}
foreach ($required as $name) {
    if (!isset($configs[$name])) {
        $configs[$name] = ['ok' => false, 'message' => '配置文件不存在', 'envs' => []];
    }
}

// 概要：每份配置的强制变量个数（可读性检查失败的不计入）
$summary = [];
foreach ($configs as $name => $info) {
    if ($info['ok']) {
        $summary[] = $name . '=' . count(forcedMap($info['envs']));
    }
}
echo '门禁配置: ' . BASE_CONFIG . '（基线） / ' . implode(' / ', VARIANT_CONFIGS) . '（变体）' . PHP_EOL;
echo '强制变量数: ' . ($summary === [] ? '（无可读配置）' : implode('  ', $summary)) . PHP_EOL . PHP_EOL;

$errorCount = 0;
$warningCount = 0;

// --- 检查 0：配置可读性（缺失/解析失败一律算错误） ---
$loadIssues = [];
foreach ($configs as $name => $info) {
    if ($info['ok']) {
        continue;
    }
    $hint = in_array($name, $required, true) ? '（门禁必需配置）' : '';
    $loadIssues[] = "{$name} {$info['message']}{$hint}";
}
printf("检查 0  配置可读性              %s\n", formatResult(count($loadIssues)));
foreach ($loadIssues as $issue) {
    echo '   ' . colorize('[CONFIG]', 'red') . " {$issue}" . PHP_EOL;
}
$errorCount += count($loadIssues);

$baseReady = ($configs[BASE_CONFIG]['ok'] ?? false) === true;
$baseForced = $baseReady ? forcedMap($configs[BASE_CONFIG]['envs']) : [];

// 缺失/不可读的变体：已由检查 0 计为错误，这里只负责在 A / C 的结果行上标注，
// 避免「变体根本不在检查范围内」却显示 OK 造成误读。
$missingVariants = [];
foreach (VARIANT_CONFIGS as $variant) {
    if (($configs[$variant]['ok'] ?? false) !== true) {
        $missingVariants[] = $variant;
    }
}
$missingSuffix = $missingVariants === []
    ? ''
    : colorize('（' . implode(', ', $missingVariants) . ' 不可读，未参与本项检查）', 'gray');

// --- 检查 A：force 集合包含关系 + 取值一致 + force 属性合法性 ---
if (!$baseReady) {
    echo '检查 A  强制变量覆盖关系        ' . colorize('SKIPPED（基线缺失）', 'gray') . PHP_EOL;
} else {
    $aErrors = [];
    $aWarnings = [];
    foreach (VARIANT_CONFIGS as $variant) {
        if (($configs[$variant]['ok'] ?? false) !== true) {
            // 缺失的变体已计入检查 0；此处不重复报错，避免同一问题刷屏
            continue;
        }
        $variantForced = forcedMap($configs[$variant]['envs']);
        foreach ($baseForced as $envName => $baseValue) {
            if (!array_key_exists($envName, $variantForced)) {
                $aErrors[] = "{$variant} 缺少基线里的强制变量 {$envName}={$baseValue}"
                    . '（不加 force 时 shell 导出值会抢占，测试语义漂移）';
                continue;
            }
            if ($variantForced[$envName] !== $baseValue) {
                $aErrors[] = "{$variant} 的 {$envName} 与基线取值不一致：基线={$baseValue} / 变体={$variantForced[$envName]}";
            }
        }
    }

    // force 属性只接受 true/false：写别的值会被 PHPUnit 静默当成 false（等于没 force）
    foreach ($required as $name) {
        if (($configs[$name]['ok'] ?? false) !== true) {
            continue;
        }
        foreach ($configs[$name]['envs'] as $env) {
            if ($env['forceRaw'] === null) {
                continue;
            }
            if (!in_array(strtolower(trim($env['forceRaw'])), ['true', 'false'], true)) {
                $aWarnings[] = "{$name}:{$env['line']} {$env['name']} 的 force=\"{$env['forceRaw']}\" 不是 true/false，"
                    . 'PHPUnit 会当作 false（该变量实际未被强制）';
            }
        }
    }

    printf("检查 A  强制变量覆盖关系        %s%s\n", formatResult(count($aErrors), count($aWarnings)), $missingSuffix);
    foreach ($aErrors as $issue) {
        echo '   ' . colorize('[MISSING]', 'red') . " {$issue}" . PHP_EOL;
    }
    foreach ($aWarnings as $issue) {
        echo '   ' . colorize('[FORCE-ATTR]', 'yellow') . " {$issue}" . PHP_EOL;
    }
    $errorCount += count($aErrors);
    $warningCount += count($aWarnings);
}

// --- 检查 B：DB_* 一律不得 force（对所有已发现配置生效，含豁免配置） ---
$bIssues = [];
foreach ($configs as $name => $info) {
    if (!$info['ok']) {
        continue;
    }
    foreach ($info['envs'] as $env) {
        if (!str_starts_with(strtoupper($env['name']), 'DB_')) {
            continue;
        }
        if (!$env['force']) {
            continue;
        }
        $bIssues[] = "{$name}:{$env['line']} {$env['name']} 被 force=\"{$env['forceRaw']}\""
            . '（连接参数必须跟随运行环境，加 force 会让干净检出/CLI 子进程连到不存在的库）';
    }
}
printf("检查 B  DB_* 不得 force         %s\n", formatResult(count($bIssues)));
foreach ($bIssues as $issue) {
    echo '   ' . colorize('[DB-FORCE]', 'red') . " {$issue}" . PHP_EOL;
}
$errorCount += count($bIssues);

// --- 检查 C：变体不得出现基线没有的 forced env ---
if (!$baseReady) {
    echo '检查 C  变体不得新增强制变量     ' . colorize('SKIPPED（基线缺失）', 'gray') . PHP_EOL;
} else {
    $cIssues = [];
    foreach (VARIANT_CONFIGS as $variant) {
        if (($configs[$variant]['ok'] ?? false) !== true) {
            continue;
        }
        foreach (forcedMap($configs[$variant]['envs']) as $envName => $value) {
            if (array_key_exists($envName, $baseForced)) {
                continue;
            }
            $cIssues[] = "{$variant} force 了基线没有的变量 {$envName}={$value}"
                . '（新增语义固定项必须先登记到 ' . BASE_CONFIG . '）';
        }
    }
    printf("检查 C  变体不得新增强制变量     %s%s\n", formatResult(count($cIssues)), $missingSuffix);
    foreach ($cIssues as $issue) {
        echo '   ' . colorize('[UNKNOWN-ENV]', 'red') . " {$issue}" . PHP_EOL;
    }
    $errorCount += count($cIssues);
}

// --- 检查 D：所有 phpunit*.xml 都必须登记（防新增变体绕过门禁） ---
$dIssues = [];
foreach ($discovered as $name) {
    if (in_array($name, $registered, true)) {
        continue;
    }
    $dIssues[] = "{$name} 未登记到门禁（请加入 VARIANT_CONFIGS，或加入 EXEMPT_CONFIGS 并写明豁免理由）";
}
printf("检查 D  phpunit 配置登记完整     %s\n", formatResult(count($dIssues)));
foreach ($dIssues as $issue) {
    echo '   ' . colorize('[UNREGISTERED]', 'red') . " {$issue}" . PHP_EOL;
}
foreach (EXEMPT_CONFIGS as $name => $reason) {
    if (in_array($name, $discovered, true)) {
        echo '   ' . colorize('[EXEMPT]', 'gray') . " {$name}：{$reason}" . PHP_EOL;
    }
}
$errorCount += count($dIssues);

echo str_repeat('-', 64) . PHP_EOL;

if ($errorCount === 0 && $warningCount === 0) {
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
