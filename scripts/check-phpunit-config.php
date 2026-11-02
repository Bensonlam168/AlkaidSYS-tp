#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PHPUnit Config Consistency Check | phpunit 配置一致性检查（R30 / R34 / R35）
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
 *   D. 仓库内（**递归扫描**，排除 vendor/node_modules/.git/runtime）所有 phpunit*.xml 都必须登记
 *      （纳入门禁或显式豁免），防止新增变体配置绕过本脚本。登记判定按**相对路径**，
 *      因为子目录里同名配置（如 plugins/xxx/phpunit.xml）与根层 phpunit.xml 不能混为一谈（R34）。
 *   E. 豁免必须带 review_by 到期日：缺失/格式非法/已过期一律报错，未到期则每次运行打印剩余天数
 *      （可见性，避免「设了豁免就再也不看」）（R35）。
 *      同时做**反向**检查：豁免条目必须对应仓库内真实存在的配置，否则报 [EXEMPT-STALE]
 *      （删除/改名/移走配置后豁免会变成僵尸条目，永远占着清单也不会有任何提示）（R38）。
 *   G. 根级「安全属性」与 testsuite 布局：
 *      G1. 三件套（failOnRisky / failOnWarning / failOnEmptyTestSuite）在**基线**里必须为 true，
 *          且**不跟随基线漂移**：有人把基线也改成 false 时同样报错 ——
 *          否则「先改基线、再改变体」两步就能合法化整条链的漂移；
 *      G2. 基线必须声明 Legacy 套件（守卫型用例的唯一收集入口），且其取样路径在盘上、
 *          目录里至少有一个 *Test.php；
 *      G3. 每份受管配置的每个安全属性（含 failOnDeprecation / beStrictAbout* 等）必须与基线**逐项一致**：
 *          安全属性是一条链，单独关掉 beStrictAboutOutputDuringTests 就等于让 failOnRisky 看不见
 *          「有输出但没断言」的用例 ——「变体绿」与「基线绿」不再是同一个绿。
 *          非法字面量（写成 1 / yes / 带空格）单独报错：PHPUnit 会静默回落默认值。
 *          唯一的例外通道是豁免条目里的 safety_exempt_allowed=true（仍受 review_by 约束），
 *          且例外配置每次运行都会把差异打印出来，不是「不显示」；
 *      G4. 受管配置声明的 testsuite 取样路径必须存在、且还能收集到用例 —— 这是
 *          failOnEmptyTestSuite 的**静态**版：运行期的空跑判定只在「真的跑了一次」时才生效，
 *          而变体配置（t048/t050）常年没人跑。
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
 * 递归扫描时跳过的目录名（任意层级生效）
 *
 * vendor/ 里躺着第三方自带的 phpunit*.xml（如 topthink/think-swoole、casbin/casbin），
 * 它们不受本仓库维护，纳入门禁只会制造噪音；node_modules / .git / runtime 同理。
 * 除 node_modules 外都是小写，统一转小写后比较以兼容大小写不敏感的文件系统。
 */
const SCAN_EXCLUDED_DIRS = ['vendor', 'node_modules', '.git', 'runtime'];

/**
 * 显式豁免的配置（**相对仓库根的路径** => 豁免元数据）
 *
 * 豁免只对检查 A / C 生效（变体覆盖关系）；检查 B（DB_* 不得 force）对**所有**配置生效，
 * 因为没有哪种配置有理由把连接参数钉死。
 *
 * review_by：豁免到期日（R35）。到期后检查 E 报错，强制重新复核 —— 否则「豁免」会变成
 * 永久的免检通道，某个豁免配置悄悄开始 force 变量也没人知道。
 *
 * safety_exempt_allowed（可选，默认 false）：是否允许该配置的**根级安全属性**与基线不一致（检查 G）。
 * 默认 false 是刻意的 fail-closed —— 新登记的豁免不会自动连「安全属性对齐」一起被豁免掉，
 * 必须显式写 true 表示「我看过这份配置，它确实属于另一条交付链，不走基线的安全口径」。
 * 写 true 时检查 G 仍会逐项打印它与基线的差异（灰色 [EXEMPT-SAFETY] 提示），
 * 让每次复核 review_by 的人都能看到自己在批准什么。
 *
 * 条目必须指向仓库内**真实存在**的配置（R38）：配置一旦删除/改名，豁免就会变成永远不会被
 * 看见、也不会被提醒清理的僵尸条目，检查 E 用 [EXEMPT-STALE] 报错要求删除。
 */
const EXEMPT_CONFIGS = [
    'phpunit.performance.xml' => [
        'reason'                => '性能/压测专用配置，只声明不带 force 的 APP_DEBUG / PERFORMANCE_TEST，不属于 T-048/T-050 验收链',
        'review_by'             => '2027-04-30',
        // 该配置目前缺 failOnEmptyTestSuite / beStrictAboutOutputDuringTests（历史形态，改动
        // 会牵连性能套件的既有行为）；它不参与验收链，因此允许与基线不同，
        // 但每次运行都会把差异打印出来，下次复核时评估是否对齐。
        'safety_exempt_allowed' => true,
    ],
    'plugins/workflow-engine/phpunit.xml' => [
        'reason'                => '插件独立测试域（自带 testsuites，bootstrap 指向插件自己的 vendor/autoload.php，独立运行插件测试），env 仅剩不带 force 的 APP_ENV（原 DB_* 与 WORKFLOW_* 全是死配置，已于 2026-11-02 删除），不参与根级强制变量策略',
        'review_by'             => '2027-04-30',
        // 插件自带一份 PHPUnit 11.0 配置，failOn* 三项全未开启：它有自己的交付链与发布节奏，
        // 由插件维护者按插件规则管理；这里只要求「差异可见」，不要求与根基线一致。
        'safety_exempt_allowed' => true,
    ],
];

/**
 * 根级「安全属性」清单（检查 G）
 *
 * 只收两类：
 *   1. failOn*：把缺陷（risky / warning / deprecation / 空跑 …）折算成非 0 退出码；
 *   2. beStrictAbout*：把「有输出」「没断言」这类噪音升级成问题 —— 否则 failOnRisky / failOnWarning
 *      看不见它们，前一类等于少了一半的入口。
 *
 * 取值 = `vendor/phpunit/phpunit/phpunit.xsd`（PHPUnit 11.5）里该属性的 `xs:attribute default`，
 * 与 `Loader::parseBooleanAttribute()` 传的默认值一致。**未声明的属性按这里的默认值折算**，
 * 所以这张表写错会让「变体漏声明」被判成「与基线一致」—— 表与 XSD 是否脱节由
 * `tests/Unit/Scripts/PhpunitConfigSafetyRootConsistencyTest.php` 守着（PHPUnit 升级改了默认值
 * 或加了新的 failOn* 属性时，那个用例会变红要求重新登记）。
 *
 * @var array<string, bool>
 */
const SAFETY_ROOT_ATTRIBUTES = [
    'failOnAllIssues'                         => false,
    'failOnDeprecation'                       => false,
    'failOnEmptyTestSuite'                    => false,
    'failOnIncomplete'                        => false,
    'failOnNotice'                            => false,
    'failOnPhpunitDeprecation'                => false,
    'failOnPhpunitWarning'                    => true,
    'failOnRisky'                             => false,
    'failOnSkipped'                           => false,
    'failOnWarning'                           => false,
    'beStrictAboutChangesToGlobalState'       => false,
    'beStrictAboutCoverageMetadata'           => false,
    'beStrictAboutOutputDuringTests'          => false,
    'beStrictAboutTestsThatDoNotTestAnything' => true,
];

/**
 * 每份受管配置都必须**显式为 true** 的安全属性（检查 G）
 *
 * 这三个是「问题必须变红」的地基，而且**不跟随基线漂移**：即使有人把基线也改成 false，
 * 检查 G 仍报错 —— 否则「先改基线、再改变体」两步就能合法化整条链的漂移。
 *
 * 值是该属性缺失时的解释文案（拼进错误信息，说明「关掉它等于放过什么」）。
 *
 * @var array<string, string>
 */
const REQUIRED_TRUE_ROOT_ATTRIBUTES = [
    'failOnRisky'          => 'risky 用例（没有任何断言 / 断言未执行）会被当成通过',
    'failOnWarning'        => 'warning 会被当成通过',
    'failOnEmptyTestSuite' => '一个用例都没跑（filter 写错 / 套件名写错 / 目录被挪走 / 用例被删空）仍然以 0 退出',
];

/** 基线必须声明的 testsuite 名：守卫型用例（tests/Legacy）的唯一收集入口（检查 G） */
const REQUIRED_BASE_TESTSUITE = 'Legacy';

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
 * 加载 XML 并返回根元素；缺失 / 不可读 / 解析失败都返回 ok=false
 *
 * 三种失败各自的文案与历史行为逐字一致（「配置文件不存在」/「配置文件无法读取」/「XML 解析失败」），
 * 因为调用方会把 message 原样拼进检查 0 的报错里。
 *
 * @return array{ok:bool, message:string, root:?DOMElement}
 */
function loadXmlRoot(string $absolutePath): array
{
    $result = ['ok' => false, 'message' => '', 'root' => null];

    if (!is_file($absolutePath)) {
        $result['message'] = '配置文件不存在';

        return $result;
    }

    $content = @file_get_contents($absolutePath);
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

    $result['ok'] = true;
    $result['root'] = $dom->documentElement;

    return $result;
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

    $loaded = loadXmlRoot($path);
    if (!$loaded['ok']) {
        $result['message'] = $loaded['message'];

        return $result;
    }

    foreach ($loaded['root']->childNodes as $php) {
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
 * 读取一份配置的「根级安全属性原始值」+「<testsuites> 声明」（检查 G）
 *
 * 只取 SAFETY_ROOT_ATTRIBUTES 里登记的属性；**未声明的记 null**，由 effectiveSafetyValue()
 * 折算成 PHPUnit 的默认值 —— 「没写」与「写了 false」在 PHPUnit 眼里是一回事，
 * 但在报告里必须能区分，否则「属性被删掉」会看起来像「值没变」。
 *
 * testsuites 只收 `<testsuites><testsuite>` 下的一层 directory / file / exclude；
 * phpunit.performance.xml 的 `<source><include><directory>` 不在 <testsuites> 下面，不会被误收。
 * 同名套件后一个覆盖前一个（PHPUnit 本身会把两者都收集，此处只用于取「Legacy 在不在」，
 * 不参与「有几个套件」的判定）。
 *
 * @return array{ok:bool, message:string, attributes:array<string, ?string>, suites:array<string, array{line:int, paths:array<int, array{type:string, value:string, line:int}>}>}
 */
function readRootStructure(string $path): array
{
    $result = ['ok' => false, 'message' => '', 'attributes' => [], 'suites' => []];

    $loaded = loadXmlRoot($path);
    if (!$loaded['ok']) {
        $result['message'] = $loaded['message'];

        return $result;
    }

    $root = $loaded['root'];

    foreach (array_keys(SAFETY_ROOT_ATTRIBUTES) as $attribute) {
        $result['attributes'][$attribute] = $root->hasAttribute($attribute)
            ? $root->getAttribute($attribute)
            : null;
    }

    foreach ($root->childNodes as $testsuites) {
        if (!$testsuites instanceof DOMElement || $testsuites->nodeName !== 'testsuites') {
            continue;
        }
        foreach ($testsuites->childNodes as $suite) {
            if (!$suite instanceof DOMElement || $suite->nodeName !== 'testsuite') {
                continue;
            }
            $paths = [];
            foreach ($suite->childNodes as $entry) {
                if (!$entry instanceof DOMElement) {
                    continue;
                }
                if (!in_array($entry->nodeName, ['directory', 'file', 'exclude'], true)) {
                    continue;
                }
                $paths[] = [
                    'type'  => $entry->nodeName,
                    'value' => trim($entry->textContent),
                    'line'  => $entry->getLineNo(),
                ];
            }
            $result['suites'][$suite->getAttribute('name')] = [
                'line'  => $suite->getLineNo(),
                'paths' => $paths,
            ];
        }
    }

    $result['ok'] = true;

    return $result;
}

/**
 * 把根级属性的原始文本折算成 PHPUnit **实际生效**的布尔值
 *
 * 与 `Xml\Loader::booleanFromString()` 逐字一致：只有 true / false（大小写不敏感，**不做 trim**）
 * 会被采纳，其它取值一律回落给该属性的默认值。于是 `failOnRisky="1"` 等于 false ——
 * 这是最隐蔽的一种「以为开了其实没开」，所以 literal=false 必须被单独报出来，
 * 不能只按生效值比较（那样它会被当成「值是 false」，与基线不一致时报的信息会误导人）。
 *
 * @return array{value:bool, literal:bool} literal=false 表示字面量非法（已回落默认值）
 */
function effectiveSafetyValue(?string $raw, bool $default): array
{
    if ($raw === null) {
        return ['value' => $default, 'literal' => true];
    }

    $normalized = strtolower($raw);
    if ($normalized === 'true') {
        return ['value' => true, 'literal' => true];
    }
    if ($normalized === 'false') {
        return ['value' => false, 'literal' => true];
    }

    return ['value' => $default, 'literal' => false];
}

/**
 * 把「原始值 + 生效值」渲染成人类可读的一段话，用于报错与豁免提示
 *
 * 「未声明」与「写了 false」都生效为 false，但成因完全不同（漏写 vs 明确关闭），
 * 报错时必须能一眼看出来，否则修的人会去改一个根本不存在的属性。
 */
function describeSafetyValue(?string $raw, bool $effective): string
{
    $flag = $effective ? 'true' : 'false';

    return $raw === null ? "未声明（生效 {$flag}）" : "\"{$raw}\"（生效 {$flag}）";
}

/**
 * 统计目录下 *Test.php 的数量（检查 G 的静态空跑判定用）
 *
 * 默认后缀取自 PHPUnit 的 testSuffixes 默认值（`Test.php`）。不跟随符号链接。
 * 目录不存在时返回 0 —— 由调用方先判 file_exists，不在这里制造两种失败。
 */
function countTestFiles(string $absolutePath): int
{
    if (!is_dir($absolutePath)) {
        return 0;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS)
        );
    } catch (UnexpectedValueException $e) {
        return 0;
    }

    $count = 0;
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
            $count++;
        }
    }

    return $count;
}

/**
 * 校验一份配置里某个 testsuite 的取样路径（检查 G 的静态部分）
 *
 * 为什么静态再查一遍：failOnEmptyTestSuite 只在「真的跑了一次」时才把空跑变红，
 * 而变体配置（t048/t050）常年没人跑 —— 只在报覆盖率时被想起来。路径被挪走、用例被删空时，
 * 静态布局检查是唯一会在日常 PR 上触发的那层。
 *
 * 相对路径的解析基准是**配置文件所在目录**（与 PHPUnit 一致），不是仓库根：
 * 子目录里的配置（plugins/xxx/phpunit.xml）写 `<directory>tests</directory>`
 * 指的是插件自己的 tests，按仓库根解析会误报。
 *
 * @param array{line:int, paths:array<int, array{type:string, value:string, line:int}>} $suite
 * @return array<int, array{tag:string, msg:string}>
 */
function auditSuitePaths(string $configName, string $suiteName, array $suite): array
{
    $configDir = dirname($configName);
    $prefix = ($configDir === '.' || $configDir === '') ? '' : $configDir . '/';
    $issues = [];

    $collectable = 0;
    if ($suite['paths'] === []) {
        $issues[] = [
            'tag' => 'SUITE-EMPTY',
            'msg' => "{$configName}:{$suite['line']} 套件「{$suiteName}」没有声明任何 directory / file，"
                . '永远收集不到用例（PHPUnit 只会打印 No tests executed!）',
        ];

        return $issues;
    }

    foreach ($suite['paths'] as $path) {
        if ($path['type'] === 'exclude') {
            continue;
        }
        $isAbsolute = str_starts_with($path['value'], '/');
        $absolute = $isAbsolute ? $path['value'] : REPO_ROOT . '/' . $prefix . $path['value'];
        $display = $isAbsolute ? $path['value'] : $prefix . $path['value'];

        if (!file_exists($absolute)) {
            $issues[] = [
                'tag' => 'SUITE-PATH',
                'msg' => "{$configName}:{$path['line']} 套件「{$suiteName}」的 {$path['type']} {$path['value']} "
                    . "不存在（按配置所在目录解析为 {$display}）：该套件的取样范围已经空了",
            ];

            continue;
        }

        if ($path['type'] === 'file' || countTestFiles($absolute) > 0) {
            $collectable++;
        }
    }

    if ($collectable === 0 && $issues === []) {
        $issues[] = [
            'tag' => 'SUITE-NO-TEST',
            'msg' => "{$configName}:{$suite['line']} 套件「{$suiteName}」声明的路径里没有任何 *Test.php："
                . '路径都在，但用例已经空了（运行期只能靠 failOnEmptyTestSuite 才发现）',
        ];
    }

    return $issues;
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
 * 按相对仓库根的路径读取配置；文件缺失时返回「不存在」结构
 *
 * @param string $relativePath 形如 phpunit.xml 或 plugins/workflow-engine/phpunit.xml
 * @return array{ok:bool, message:string, envs:array<int, array<string, mixed>>}
 */
function readConfig(string $relativePath): array
{
    return readEnvDeclarations(REPO_ROOT . '/' . $relativePath);
}

/**
 * 递归发现仓库内全部 phpunit*.xml，返回**相对仓库根**的路径（统一用 / 分隔，已排序）（R34）
 *
 * 为什么不能继续用 `glob(REPO_ROOT . '/phpunit*.xml')`：glob 只扫根层，
 * `plugins/workflow-engine/phpunit.xml` 是一份真实、被 git 跟踪、含 10 个 <env> 的插件配置，
 * 它靠「根层扫不到」侥幸绕过了检查 B/D —— 那不是通过，而是没被看见。
 *
 * 排除规则：任意层级的 vendor / node_modules / .git / runtime 目录整棵剪掉（见 SCAN_EXCLUDED_DIRS），
 * 以免把第三方自带的 phpunit*.xml 卷进来。目录剪枝用 RecursiveCallbackFilterIterator 做，
 * 未匹配的目录直接不进入，不会为了排除 vendor 而先遍历一遍 vendor（该目录有数万文件）。
 *
 * 不跟随符号链接（RecursiveDirectoryIterator 默认行为）：避免软链成环导致无限递归。
 *
 * @return array<int, string>
 */
function discoverConfigs(string $root): array
{
    $rootReal = realpath($root);
    if ($rootReal === false || !is_dir($rootReal)) {
        return [];
    }

    try {
        $iterator = new RecursiveDirectoryIterator(
            $rootReal,
            FilesystemIterator::SKIP_DOTS
        );
    } catch (UnexpectedValueException $e) {
        // 根目录不可读：返回空集，让检查 0 的「门禁必需配置缺失」去报错，而不是在这里抛异常
        return [];
    }

    // 目录剪枝：命中排除名单的目录不进入（返回 false 时 RecursiveIteratorIterator 不会 descend）
    $filtered = new RecursiveCallbackFilterIterator(
        $iterator,
        static function (SplFileInfo $current): bool {
            if ($current->isDir()) {
                return !in_array(strtolower($current->getFilename()), SCAN_EXCLUDED_DIRS, true);
            }

            return true;
        }
    );

    $found = [];
    foreach (new RecursiveIteratorIterator($filtered) as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        $name = $file->getFilename();
        // 与 glob 的 'phpunit*.xml' 语义等价：以 phpunit 开头、以 .xml 结尾
        if (!str_starts_with($name, 'phpunit') || !str_ends_with($name, '.xml')) {
            continue;
        }
        $absolute = $file->getPathname();
        // 统一成正斜杠，保证 Windows / macOS / Linux 下报告与登记表可读且可比
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($rootReal) + 1));
        $found[] = $relative;
    }

    sort($found, SORT_STRING);

    return array_values(array_unique($found));
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

$strict = in_array('--strict', $argv, true);

echo colorize('PHPUnit Config Consistency Check | phpunit 配置一致性检查', 'blue') . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;

// 仓库内实际存在的 phpunit*.xml（递归扫描，已排除第三方目录）；检查 D 用：未登记的配置不许存在
$discovered = discoverConfigs(REPO_ROOT);

$required = array_merge([BASE_CONFIG], VARIANT_CONFIGS);
$registered = array_merge($required, array_keys(EXEMPT_CONFIGS));

// 读取所有已发现的配置；required 里缺失的补占位，保证「缺失即报错」
$configs = [];
$structures = [];   // 检查 G 用：每份配置的根级安全属性 + testsuites 声明
foreach ($discovered as $name) {
    $configs[$name] = readConfig($name);
    $structures[$name] = readRootStructure(REPO_ROOT . '/' . $name);
}
foreach ($required as $name) {
    if (!isset($configs[$name])) {
        $configs[$name] = ['ok' => false, 'message' => '配置文件不存在', 'envs' => []];
        $structures[$name] = ['ok' => false, 'message' => '配置文件不存在', 'attributes' => [], 'suites' => []];
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
// 判定按**相对路径**：递归扫描后可能同时存在 phpunit.xml（根层）与 plugins/xxx/phpunit.xml，
// 只看 basename 会把两者混为一谈，等于给子目录配置开了后门。
$dIssues = [];
foreach ($discovered as $name) {
    if (in_array($name, $registered, true)) {
        continue;
    }
    $dIssues[] = "{$name} 未登记到门禁（请加入 VARIANT_CONFIGS，或加入 EXEMPT_CONFIGS 并写明豁免理由与 review_by）";
}
printf("检查 D  phpunit 配置登记完整     %s\n", formatResult(count($dIssues)));
foreach ($dIssues as $issue) {
    echo '   ' . colorize('[UNREGISTERED]', 'red') . " {$issue}" . PHP_EOL;
}
foreach (EXEMPT_CONFIGS as $name => $meta) {
    if (in_array($name, $discovered, true)) {
        echo '   ' . colorize('[EXEMPT]', 'gray') . " {$name}：" . ($meta['reason'] ?? '（缺少 reason）') . PHP_EOL;
    }
}
$errorCount += count($dIssues);

// --- 检查 E：豁免元数据有效性（review_by 到期 + 陈旧条目反向检查）（R35 / R38） ---
// 目的：豁免不是永久免检通道。到期后必须有人真的看一遍那份配置，
// 要么续期（更新 review_by），要么移除豁免纳入门禁。
// 错误用三种标签区分：EXEMPT-META（元数据缺失/格式非法，写的人的问题）
// 与 EXEMPT-EXPIRED（真的到期了，需要复核决策）、EXEMPT-STALE（豁免指向的文件已不存在），
// 便于 CI 日志快速定位。
$eIssues = [];   // list<array{tag:string, msg:string}>
$eNotices = [];
$today = new DateTimeImmutable('today'); // 服务器当天零点，按日期粒度比较

// 反向检查（R38）：豁免清单 → 真实文件。
// 检查 D 只管「文件 → 登记表」这一个方向（发现未登记的配置），反方向过去是空的：
// 某份被豁免的配置一旦被删除 / 改名 / 整体移走，条目会**静默**留在清单里 ——
// A/B/C 都看不到它（它已不在 $configs 里），D 又因为「已登记」而不吭声，
// 于是这条僵尸豁免会永远占着清单，且 review_by 续期时还会被当成一条「有效豁免」复核。
// 判定基准是递归扫描结果 $discovered（与检查 D 同一个集合），保证「能豁免的」与「能触发 D 的」口径一致：
// 扫描排除目录（vendor/node_modules/.git/runtime）里的文件本就不受门禁管辖，
// 给它们登记豁免既无意义也不会生效，同样按陈旧处理。
$staleExempts = [];   // list<string>：陈旧的豁免条目，后续不再打印有效期提示
foreach (array_keys(EXEMPT_CONFIGS) as $name) {
    if (in_array($name, $discovered, true)) {
        continue;
    }
    $staleExempts[] = $name;
    // 区分两种成因，报告措辞不同：文件真的没了 vs 文件在但不在扫描范围内（豁免从未生效）
    $existsOnDisk = is_file(REPO_ROOT . '/' . $name);
    $eIssues[] = [
        'tag' => 'EXEMPT-STALE',
        'msg' => $existsOnDisk
            ? "{$name} 的豁免条目已失效（文件存在但不在递归扫描范围内：可能位于 vendor 等排除目录，"
                . '或文件名不符合 phpunit*.xml），这条豁免从未生效，请删除该豁免'
            : "{$name} 的豁免条目已失效（文件不存在），请删除该豁免",
    ];
}

foreach (EXEMPT_CONFIGS as $name => $meta) {
    if (!is_array($meta)) {
        // 兼容被误改回旧格式（字符串理由）的情况：直接报结构错误，避免 review_by 静默丢失
        $eIssues[] = [
            'tag' => 'EXEMPT-META',
            'msg' => "{$name} 的豁免元数据必须是数组 ['reason' => ..., 'review_by' => 'YYYY-MM-DD']",
        ];

        continue;
    }

    $reviewBy = $meta['review_by'] ?? null;
    if (!is_string($reviewBy) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewBy) !== 1) {
        $eIssues[] = [
            'tag' => 'EXEMPT-META',
            'msg' => "{$name} 的 review_by 缺失或格式非法（应为 YYYY-MM-DD，实际="
                . (is_string($reviewBy) ? $reviewBy : gettype($reviewBy)) . '）',
        ];

        continue;
    }

    $deadline = DateTimeImmutable::createFromFormat('!Y-m-d', $reviewBy);
    // createFromFormat 会把 2027-02-30 溢出成 2027-03-02，回写比对才能识别出「不存在的日期」
    if ($deadline === false || $deadline->format('Y-m-d') !== $reviewBy) {
        $eIssues[] = [
            'tag' => 'EXEMPT-META',
            'msg' => "{$name} 的 review_by={$reviewBy} 不是真实存在的日期",
        ];

        continue;
    }

    // %r 带出符号：负值表示已过期
    $daysLeft = (int) $today->diff($deadline)->format('%r%a');
    if ($daysLeft < 0) {
        $eIssues[] = [
            'tag' => 'EXEMPT-EXPIRED',
            'msg' => "{$name} 的豁免已到期（review_by={$reviewBy}，已过期 " . abs($daysLeft) . ' 天）'
                . '：请复核后更新 review_by，或移除豁免并纳入门禁',
        ];

        continue;
    }

    // 未到期：每次运行都打印，制造可见性（不然「设了就不管」）
    // 陈旧条目（文件已不存在）不打印这条：它的豁免已无对象，再写「剩余 N 天」会误导成「还在有效期内」，
    // 上面已经有 EXEMPT-STALE 错误要求删除
    if (!in_array($name, $staleExempts, true)) {
        $eNotices[] = "{$name} 豁免有效期至 {$reviewBy}，剩余 {$daysLeft} 天";
    }
}

printf("检查 E  豁免条目有效性          %s\n", formatResult(count($eIssues)));
foreach ($eIssues as $issue) {
    echo '   ' . colorize("[{$issue['tag']}]", 'red') . " {$issue['msg']}" . PHP_EOL;
}
foreach ($eNotices as $notice) {
    echo '   ' . colorize('[EXEMPT-REVIEW]', 'gray') . " {$notice}" . PHP_EOL;
}
$errorCount += count($eIssues);

// --- 检查 G：根级安全属性 + testsuite 布局 ---
// 事故背景：上一轮给 phpunit.xml 开了 failOnEmptyTestSuite 并新增 Legacy 套件，两份任务变体没跟上。
// 由此有两个同源的静默退化，A~E 全都看不见（它们只解析 <php><env>）：
//   1. 变体的 <testsuite> 一旦指向被挪走 / 改名的目录，PHPUnit 只打印 No tests executed! 就 exit 0，
//      「T-048/T-050 覆盖率 ≥80%」的验收样本可以在一个用例都没跑的情况下照常出数；
//   2. Legacy 套件若从基线里被删掉，两个守卫会重新变回没人跑的死文件。
$gIssues = [];    // list<array{tag:string, msg:string}>
$gNotices = [];   // list<string>

// 受「与基线逐项一致」约束的配置：门禁链（基线 + 变体）+ **未**显式声明安全属性例外的豁免配置。
// safety_exempt_allowed !== true 的豁免条目按门禁配置对待 —— 这是 fail-closed：
// 新登记的豁免不会连「安全属性对齐」一起被默默豁免掉，必须显式写 true 才算表过态。
$safetyGated = array_merge([BASE_CONFIG], VARIANT_CONFIGS);
foreach (EXEMPT_CONFIGS as $name => $meta) {
    if (!is_array($meta) || ($meta['safety_exempt_allowed'] ?? false) !== true) {
        $safetyGated[] = $name;
    }
}

$baseStructure = $structures[BASE_CONFIG]
    ?? ['ok' => false, 'message' => '未读取', 'attributes' => [], 'suites' => []];
$baseSafety = [];   // attribute => array{value:bool, literal:bool}
$baseSuites = [];

if (($baseStructure['ok'] ?? false) !== true) {
    // 基线读不出来时检查 0 已计过错误，但 G 不能因此显示 OK（那会被读成「已校验」）
    $gIssues[] = [
        'tag' => 'BASE-STRUCT',
        'msg' => BASE_CONFIG . ' 根级结构读取失败（' . ($baseStructure['message'] ?? '未知原因')
            . '）：检查 G 无法比较',
    ];
} else {
    foreach (SAFETY_ROOT_ATTRIBUTES as $attribute => $default) {
        $baseSafety[$attribute] = effectiveSafetyValue($baseStructure['attributes'][$attribute], $default);
    }
    $baseSuites = $baseStructure['suites'];

    // G1：三件套在基线里必须为 true（**不跟随基线漂移**：先改基线再改变体不能合法化漂移）
    foreach (REQUIRED_TRUE_ROOT_ATTRIBUTES as $attribute => $consequence) {
        if ($baseSafety[$attribute]['value'] === true) {
            continue;
        }
        $gIssues[] = [
            'tag' => 'BASELINE-SAFETY',
            'msg' => BASE_CONFIG . " 的 {$attribute} 未开启（"
                . describeSafetyValue($baseStructure['attributes'][$attribute], $baseSafety[$attribute]['value'])
                . "）：{$consequence}",
        ];
    }

    // G2：基线必须声明 Legacy 套件，且它的取样路径真的还能收集到用例
    if (!array_key_exists(REQUIRED_BASE_TESTSUITE, $baseSuites)) {
        $gIssues[] = [
            'tag' => 'BASE-SUITE',
            'msg' => BASE_CONFIG . ' 缺少 testsuite「' . REQUIRED_BASE_TESTSUITE . '」：'
                . 'tests/Legacy 的守卫型用例不会被任何套件收集，php think test 与 CI 都会静默跳过它们'
                . '（PHPUnit 不报「套件不存在」，只跑 0 个用例）。现有套件：'
                . ($baseSuites === [] ? '（一个都没有）' : implode(' / ', array_keys($baseSuites))),
        ];
    } else {
        foreach (auditSuitePaths(BASE_CONFIG, REQUIRED_BASE_TESTSUITE, $baseSuites[REQUIRED_BASE_TESTSUITE]) as $issue) {
            $gIssues[] = $issue;
        }
    }
}

foreach ($safetyGated as $name) {
    if ($name === BASE_CONFIG) {
        continue;
    }
    if (($structures[$name]['ok'] ?? false) !== true) {
        // 不可读：门禁必需配置由检查 0 计错，陈旧豁免由检查 E 报错。
        // 这里不重复报同一个问题，结果行用 missingSuffix 标注「未参与本项检查」。
        continue;
    }
    $structure = $structures[$name];

    // G3：安全属性与基线逐项一致（含「基线开了、本配置没声明」这种最隐蔽的一类）
    if ($baseSafety !== []) {
        foreach (SAFETY_ROOT_ATTRIBUTES as $attribute => $default) {
            $raw = $structure['attributes'][$attribute];
            $current = effectiveSafetyValue($raw, $default);

            if (!$current['literal']) {
                $gIssues[] = [
                    'tag' => 'SAFETY-ATTR',
                    'msg' => "{$name} 的 {$attribute}=\"{$raw}\" 不是 true/false，PHPUnit 会回落默认值 "
                        . ($default ? 'true' : 'false') . ' 而不是按字面量理解（等于悄悄换掉了本项语义）',
                ];

                continue;
            }

            $base = $baseSafety[$attribute];
            if ($current['value'] === $base['value']) {
                continue;
            }
            $gIssues[] = [
                'tag' => 'SAFETY-DRIFT',
                'msg' => "{$name} 的 {$attribute} 与基线不一致："
                    . BASE_CONFIG . '=' . describeSafetyValue($baseStructure['attributes'][$attribute], $base['value'])
                    . ' / 本配置=' . describeSafetyValue($raw, $current['value'])
                    . '（安全属性属于测试语义，漂移后「变体绿」与「基线绿」不再是同一个绿）',
            ];
        }
    }

    // G4：套件取样路径必须存在且还能收集到用例（failOnEmptyTestSuite 的静态版 ——
    // 运行期判定只在「真的跑了一次」时生效，而变体配置常年没人跑）
    if ($structure['suites'] === []) {
        $gIssues[] = [
            'tag' => 'SUITE-ABSENT',
            'msg' => "{$name} 没有声明任何 <testsuites>：跑起来一个用例都没有",
        ];
    }
    foreach ($structure['suites'] as $suiteName => $suite) {
        foreach (auditSuitePaths($name, (string) $suiteName, $suite) as $issue) {
            $gIssues[] = $issue;
        }
    }
}

// 豁免配置（safety_exempt_allowed=true）：不判错，但把与基线的差异逐项打印出来 ——
// 复核 review_by 的人必须能看见自己在批准什么，而不是「豁免了所以什么都不显示」。
foreach (EXEMPT_CONFIGS as $name => $meta) {
    if (!is_array($meta) || ($meta['safety_exempt_allowed'] ?? false) !== true) {
        continue;
    }
    if (($structures[$name]['ok'] ?? false) !== true || $baseSafety === []) {
        continue;   // 文件不存在由 EXEMPT-STALE 报；基线不可用已在上面报
    }
    $deltas = [];
    foreach (SAFETY_ROOT_ATTRIBUTES as $attribute => $default) {
        $raw = $structures[$name]['attributes'][$attribute];
        $current = effectiveSafetyValue($raw, $default);
        if (!$current['literal']) {
            $deltas[] = "{$attribute}=\"{$raw}\"（非法值，按默认 " . ($default ? 'true' : 'false') . ' 处理）';

            continue;
        }
        if ($current['value'] === $baseSafety[$attribute]['value']) {
            continue;
        }
        $deltas[] = $attribute . '（基线=' . ($baseSafety[$attribute]['value'] ? 'true' : 'false')
            . ' / 本配置=' . describeSafetyValue($raw, $current['value']) . '）';
    }
    if ($deltas === []) {
        continue;
    }
    $gNotices[] = "{$name} 与基线的安全属性差异（已由豁免覆盖，review_by="
        . ($meta['review_by'] ?? '缺失') . '）：' . implode('；', $deltas);
}

printf("检查 G  根级安全属性与套件      %s%s\n", formatResult(count($gIssues)), $missingSuffix);
foreach ($gIssues as $issue) {
    echo '   ' . colorize("[{$issue['tag']}]", 'red') . " {$issue['msg']}" . PHP_EOL;
}
foreach ($gNotices as $notice) {
    echo '   ' . colorize('[EXEMPT-SAFETY]', 'gray') . " {$notice}" . PHP_EOL;
}
$errorCount += count($gIssues);

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
