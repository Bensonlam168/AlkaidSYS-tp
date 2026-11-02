#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Environment Example Check | 环境配置模板门禁（R33 建立，后续扩展至生产模板）
 *
 * 覆盖两份文件：
 *   · `.env.example`            —— 键集合的单一真相源（检查 A / B / C / D）
 *   · `.env.production.example` —— 生产部署模板（检查 E）
 *
 * 背景（两类曾真实发生、且极难定位的故障）：
 *   1. parse_ini_file() 的 INI_SCANNER_RAW 模式会把 `#` 注释行**继续做词法分析**。
 *      注释里一旦出现半角括号/引号等字符，就直接抛语法错误并返回 false
 *      → think\Env::load() 拿到 false 后按 `?: []` 兜底
 *      → **整份 .env 的键全部静默失效**，表现为「容器正常启动，但连不上库」。
 *      （实测：`;` 开头的注释行不会被词法分析，括号在其中不影响解析；
 *        但 AGENTS.md 已把该规则统一适用于注释行，故本脚本仍纳入检查 C。）
 *   2. `#` 注释行里出现半角 `=` 会被解析成伪键（键名形如 `# 1. 生产类环境（APP_ENV`），
 *      既污染配置数组，也让真正的键名难以辨认。
 *   3. 半角竖线 / 与号 / 美元符号同样参与 INI 词法分析，在 `#` 注释里会让 parse_ini_file
 *      直接报语法错误（实测 PHP 8.2，详见检查 E 的 parse 分支提示）。
 *      它们不属于「括号/引号」，因此不在检查 C 的字符集里，但会被检查 A 与检查 E 的解析校验拦下。
 *
 * 检查项：
 *   A. 可解析：parse_ini_file(..., INI_SCANNER_RAW) 不得返回 false。
 *      失败时把 PHP 语法错误的**行号与原文**带出来 —— 只靠 error_get_last() 拿不到
 *      语法错误，必须用 set_error_handler() 捕获 warning。
 *   B. 无伪键：所有键名必须匹配 ^[A-Z][A-Z0-9_]*$（含分段配置里的键）。
 *      命中时给出键名与原行行号，方便直接定位并改写注释。
 *   C. 注释里无 ASCII 括号/引号：逐行检查 `#` 与 `;` 注释行。
 *      · `#` 行 + `( ) " [ { }` —— 实测会直接导致解析失败，计为**错误**（即 A 的成因，单独报更易定位）
 *      · `#` 行 + `'` 与 `]` —— 实测在 PHP 8.2 上单独出现尚不报错，计为**告警**（--strict 下同样拦截），
 *        因为 AGENTS.md 已把「注释行禁用 ASCII 括号与引号」定为硬性规则，
 *        且解析器的容忍度随 PHP 版本可能变化。
 *      · `;` 行 + 任意命中字符 —— `;` 注释不参与 INI 词法分析（实测不影响解析），
 *        同样按**告警**处理以保持规则一致性。
 *   D. 必需键存在：清单来自 `app\support\EnvRequirementCatalog` 的 `template_required` 维度
 *      （`DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` / `DB_CHARSET` / `JWT_SECRET`）。
 *      这些键缺失时进程启动**不会**失败，只在运行期以「连不上库 / 鉴权全挂」的形式暴露，
 *      因此必须显式登记。若某天真的要移除某个键，需改 catalog 的该维度（属显式决策，
 *      而非静默丢失）。
 *   E. 生产模板 `.env.production.example` 覆盖示例键集合（**单向**）：
 *      `.env.example` 里的每个键都必须出现在生产模板里，反向不要求。
 *      同时对生产模板做三项同源校验 —— 可解析、无伪键、注释行规范：
 *      「键都在但整份文件解析失败」在运行期的后果与缺键完全一样（全部键静默失效），
 *      只比对键名会把它误判为通过。
 *   F. （提示项，不参与门禁）本地 .env 若存在，做**只读**键集合对比：
 *      只比较键名，不读取也不打印任何值。.env 未跟踪且允许本地增补，故只提示不报错。
 *
 * 为什么新增检查 E（真实缺口，不是「为了整齐」）：
 *   本脚本原本只校验 `.env.example`，`.env.production.example` **完全没有门禁**（全仓库无引用），
 *   于是它可以长期缺少 12 个键而无人发现，其中三个直接对应生产事故：
 *     · 缺 DB_USER / DB_PASS → `env('DB_USER', 'root')` 缺省取值为 root、口令为空 → 生产以 root 连库；
 *     · 缺 DEFAULT_TENANT_ID → `TenantIdentify` 是全局中间件，fail-closed 抛异常
 *       → 所有不带 X-Tenant-ID 的请求（含 /healthz 探针）全部 500，容器永远 unhealthy；
 *     · 缺 TRUSTED_PROXIES → 不信任任何 XFF → 经反代且开启限流时全站共用一个 ip 桶，可能整体 429。
 *   而且当时的模板**连解析都过不去**（# 注释行里的半角括号与竖线），
 *   即「照抄模板上生产」会得到一份全部键静默失效的配置。
 *
 * 检查 E 的边界（为什么只有单向要求）：
 *   生产模板允许有示例里没有的键，例如 APP_ENV / CACHE_DRIVER / REDIS_* / CORS_ALLOWED_ORIGINS /
 *   ACCESS_LOG_ENABLED / SCHEMA_RUNTIME_DDL_* —— 它们要么在本地开发用不到，要么语义上只属于生产。
 *   反向强绑定会逼着开发者把这些键塞进 `.env.example`，反而污染本地开箱即用的默认值。
 *
 * 用法：
 *   php scripts/check-env-example.php              # 检查并输出报告
 *   php scripts/check-env-example.php --strict     # 告警也视为失败（CI 门禁）
 *
 * 退出码：
 *   0 - 通过
 *   1 - 存在错误（或 --strict 模式下存在告警）
 *
 * @package scripts
 */

use app\support\EnvRequirementCatalog;

// ---------------------------------------------------------------------------
// 配置
// ---------------------------------------------------------------------------

/** 仓库根目录（脚本位于 scripts/ 下） */
const REPO_ROOT = __DIR__ . '/..';

/** 被检查的文件（相对仓库根） */
const TARGET_FILE = '.env.example';

/**
 * 规则目录类（相对仓库根）—— 零依赖，可直接 require，无需 vendor/
 *
 * 「模板必需键清单」的**唯一真相源**。这里**曾经**有一份 6 键的 `REQUIRED_KEYS` 镜像副本，
 * 与 `EnvCheckCommand` / 预检脚本各写一套：只要有人只改其中一处，
 * 「模板必须有这个键」与「生产取值必须合格」就会悄悄错位，而两边各有各的门禁、谁也不会报错。
 * 现在两个维度都在 catalog 里（`production_*` 与 `template_required`），本脚本只消费后者。
 * 一致性由 `tests/Unit/Scripts/EnvExampleTemplateRequiredConsistencyTest` 钉住
 * （含「代码里再出现硬编码键清单就变红」与「运行时确实读该类」两条）。
 */
const CATALOG_FILE = 'app/support/EnvRequirementCatalog.php';

/**
 * 生产环境模板（相对仓库根）
 *
 * 运维从它复制出 `.env.production`，后者是 docker-compose.prod.yml 的 env_file。
 * 检查 E 负责保证它的键集合不落后于 `.env.example`。
 */
const PROD_TEMPLATE_FILE = '.env.production.example';

/** 本机真实配置（未跟踪，仅用于只读对比） */
const LOCAL_FILE = '.env';

/** 合法键名模式：大写字母开头，只含大写字母/数字/下划线 */
const KEY_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

/** 出现在注释行会直接让 parse_ini_file 返回 false 的字符（实测 PHP 8.2） */
const COMMENT_HARD_CHARS = ['(', ')', '"', '[', '{', '}'];

/** 当前 PHP 版本尚可容忍、但 AGENTS.md 明令禁止出现在注释里的字符 */
const COMMENT_SOFT_CHARS = ["'", ']'];

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
 * 读取文件为行数组（保留原始行文本，用于报错时展示原文）
 *
 * @return string[]
 */
function readLines(string $path): array
{
    $content = @file_get_contents($path);
    if ($content === false) {
        return [];
    }

    // 统一换行符，避免 CRLF 让行号或原文展示出现残留 \r
    $content = str_replace(["\r\n", "\r"], "\n", $content);

    return explode("\n", $content);
}

/**
 * 解析 INI 并捕获 PHP 语法错误
 *
 * 为什么不直接用 error_get_last()：parse_ini_file 的语法错误是一条 E_WARNING，
 * 而 error_get_last() 只能拿到「最近一次错误」，在脚本已有其他告警（如 file_get_contents）
 * 时会拿错。用 set_error_handler 精确捕获本次调用产生的告警更可靠。
 *
 * @return array{ok:bool, message:string, line:?int, data:array<mixed>}
 */
function parseIni(string $path): array
{
    $result = ['ok' => false, 'message' => '', 'line' => null, 'data' => []];

    if (!is_file($path)) {
        $result['message'] = '文件不存在';

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

    $result['ok'] = true;
    $result['data'] = $data;

    return $result;
}

/**
 * 递归收集叶子键（分段配置展开为 section.key 形式）
 *
 * 数组值视为「配置分段」，不作为键名参与合法性判断，但其内部键需要递归检查。
 *
 * @param array<mixed> $data
 * @return array<int, array{key:string, path:string}>
 */
function collectLeafKeys(array $data, string $prefix = ''): array
{
    $keys = [];
    foreach ($data as $key => $value) {
        $label = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
        if (is_array($value)) {
            foreach (collectLeafKeys($value, $label) as $item) {
                $keys[] = $item;
            }
            continue;
        }
        $keys[] = ['key' => (string) $key, 'path' => $label];
    }

    return $keys;
}

/**
 * 定位某个键在文件中的原始行号
 *
 * 伪键的键名是「注释原文 = 之前的部分」，因此直接按「等号左侧文本」比对，
 * 既能找到 `# 测试 = 值` 这种行，也能找到正常键所在行。
 *
 * @param string[] $lines
 */
function locateKeyLine(array $lines, string $key): ?int
{
    foreach ($lines as $index => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || !str_contains($trimmed, '=')) {
            continue;
        }
        $left = trim(substr($trimmed, 0, (int) strpos($trimmed, '=')));
        if ($left === $key) {
            return $index + 1;
        }
    }

    return null;
}

/**
 * 检查 B：伪键（键名不符合 KEY_PATTERN）
 *
 * @param array{ok:bool, data:array<mixed>} $parsed
 * @param string[]                          $lines
 * @return array<int, array{key:string, path:string, line:?int, raw:string}>
 */
function checkPseudoKeys(array $parsed, array $lines): array
{
    $issues = [];
    foreach (collectLeafKeys($parsed['data']) as $item) {
        if (preg_match(KEY_PATTERN, $item['key']) === 1) {
            continue;
        }
        $line = locateKeyLine($lines, $item['key']);
        $issues[] = [
            'key'  => $item['key'],
            'path' => $item['path'],
            'line' => $line,
            'raw'  => $line === null ? '' : trim($lines[$line - 1]),
        ];
    }

    return $issues;
}

/**
 * 检查 C：注释行里的 ASCII 括号/引号
 *
 * 注释行判定：去空白后以 `#` 或 `;` 开头（与 INI 语法一致）。
 *
 * 严重度规则（全部基于 PHP 8.2 实测，见脚本头部说明）：
 *   · `#` 行 + `( ) " [ { }` → **错误**：parse_ini_file 直接返回 false（真实故障）
 *   · `#` 行 + `'` `]`        → **告警**：解析器当前容忍，但 AGENTS.md 明令禁止
 *   · `;` 行 + 任意命中字符    → **告警**：`;` 注释不参与 INI 词法分析，不影响解析；
 *                               但 AGENTS.md 的注释规则统一适用，--strict 下同样拦截
 *
 * @param string[] $lines
 * @return array{errors:array<int, array{line:int, marker:string, chars:string[], raw:string, note:string}>, warnings:array<int, array{line:int, marker:string, chars:string[], raw:string, note:string}>}
 */
function checkCommentChars(array $lines): array
{
    $result = ['errors' => [], 'warnings' => []];

    foreach ($lines as $index => $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        $marker = $trimmed[0];
        if ($marker !== '#' && $marker !== ';') {
            continue;
        }

        $hard = [];
        foreach (COMMENT_HARD_CHARS as $char) {
            if (str_contains($trimmed, $char)) {
                $hard[] = $char;
            }
        }
        $soft = [];
        foreach (COMMENT_SOFT_CHARS as $char) {
            if (str_contains($trimmed, $char)) {
                $soft[] = $char;
            }
        }

        $chars = array_merge($hard, $soft);
        if ($chars === []) {
            continue;
        }

        $entry = [
            'line'   => $index + 1,
            'marker' => $marker,
            'chars'  => $chars,
            'raw'    => $trimmed,
            'note'   => '',
        ];

        if ($hard !== [] && $marker === '#') {
            $entry['note'] = '会让 parse_ini_file 直接失败 → Env::load() 按空数组兜底，整份 .env 的键静默失效';
            $result['errors'][] = $entry;
            continue;
        }
        if ($hard !== []) {
            $entry['note'] = '; 注释不参与 INI 词法分析，当前不影响解析；但 AGENTS.md 的注释规则统一适用，请改用全角括号';
            $result['warnings'][] = $entry;
            continue;
        }
        $entry['note'] = '当前 PHP 版本尚可解析，但 AGENTS.md 明令禁止，请改用全角括号';
        $result['warnings'][] = $entry;
    }

    return $result;
}

/**
 * 检查 F：本地 .env 键集合对比（只读、只比较键名、不打印值）
 *
 * @param string[] $exampleKeys
 * @return array{exists:bool, parsed:bool, onlyLocal:string[], onlyExample:string[]}
 */
function compareLocalEnv(array $exampleKeys): array
{
    $result = ['exists' => false, 'parsed' => false, 'onlyLocal' => [], 'onlyExample' => []];

    $localPath = REPO_ROOT . '/' . LOCAL_FILE;
    if (!is_file($localPath)) {
        return $result;
    }
    $result['exists'] = true;

    // 本地 .env 可能被改坏；本项只是提示，任何解析告警都静默忽略，绝不影响门禁结果
    $data = @parse_ini_file($localPath, true, INI_SCANNER_RAW);
    if ($data === false) {
        return $result;
    }
    $result['parsed'] = true;

    $localKeys = array_map(static fn (array $item): string => $item['key'], collectLeafKeys($data));
    $result['onlyLocal'] = array_values(array_diff($localKeys, $exampleKeys));
    $result['onlyExample'] = array_values(array_diff($exampleKeys, $localKeys));
    sort($result['onlyLocal']);
    sort($result['onlyExample']);

    return $result;
}

/**
 * 输出一条检查明细：`   [TAG] 文本`
 *
 * 与既有 A/B/C/D 的输出格式保持一致（缩进 + 方括号标签 + 着色）。
 *
 * @param string $tag   明细标签，例如 PROD-MISSING-KEY
 * @param string $text  明细正文
 * @param string $color 着色名，见 colorize()
 */
function printIssue(string $tag, string $text, string $color = 'red'): void
{
    echo '   ' . colorize("[{$tag}]", $color) . " {$text}" . PHP_EOL;
}

/**
 * 输出明细的补充说明：`      label 文本`
 *
 * @param string $label 说明标签，例如「原行:」「成因:」
 * @param string $text  说明正文
 */
function printDetail(string $label, string $text): void
{
    echo '      ' . colorize($label, 'gray') . ' ' . $text . PHP_EOL;
}

/**
 * 读取并解析一个环境模板（供检查 E 使用）
 *
 * 刻意复用 parseIni()/readLines()/collectLeafKeys()，避免生产模板与示例模板出现两套判定规则
 * —— 「同一个坑在两份文件里判法不同」正是这类门禁最典型的失效方式。
 *
 * @param string $relativePath 相对仓库根的路径
 * @return array{
 *     exists:bool,
 *     path:string,
 *     lines:string[],
 *     lineCount:int,
 *     parsed:array{ok:bool, message:string, line:?int, data:array<mixed>},
 *     validKeys:string[]
 * }
 */
function inspectTemplate(string $relativePath): array
{
    // 用 realpath 规整路径，让语法错误信息里不出现 `scripts/../` 这类前缀
    $path = realpath(REPO_ROOT . '/' . $relativePath) ?: (REPO_ROOT . '/' . $relativePath);
    $lines = readLines($path);

    // 末行换行会多切出一个空元素，展示行数时减掉，与 wc -l 口径一致
    $lineCount = count($lines);
    if ($lineCount > 0 && $lines[$lineCount - 1] === '') {
        $lineCount--;
    }

    $parsed = parseIni($path);
    $validKeys = [];
    if ($parsed['ok']) {
        foreach (collectLeafKeys($parsed['data']) as $item) {
            if (preg_match(KEY_PATTERN, $item['key']) === 1) {
                $validKeys[] = $item['key'];
            }
        }
    }

    return [
        'exists'    => is_file($path),
        'path'      => $path,
        'lines'     => $lines,
        'lineCount' => $lineCount,
        'parsed'    => $parsed,
        'validKeys' => $validKeys,
    ];
}

/**
 * 检查 E：生产模板必须覆盖示例键集合，且自身可解析、无伪键、注释规范
 *
 * 单向语义：`$exampleKeys` 里的每个键都必须出现在生产模板里；反向不要求
 * —— 生产模板允许有示例里没有的键（APP_ENV / CACHE_DRIVER / REDIS_* / CORS_* 等）。
 *
 * @param string[]                                                                          $exampleKeys 示例模板的合法键名
 * @param array{exists:bool, lines:string[], lineCount:int, parsed:array, validKeys:string[]} $prod
 * @return array{
 *     errors:array<int, array{tag:string, text:string, raw:string}>,
 *     warnings:array<int, array{tag:string, text:string, raw:string}>,
 *     extra:string[]
 * }
 */
function checkProdTemplate(array $exampleKeys, array $prod): array
{
    $result = ['errors' => [], 'warnings' => [], 'extra' => []];

    // 文件缺失不是「跳过」：生产模板是部署必需产物，缺了等于没有任何键可对照
    if (!$prod['exists']) {
        $result['errors'][] = [
            'tag'  => 'PROD-MISSING-FILE',
            'text' => '生产模板 ' . PROD_TEMPLATE_FILE . ' 不存在（本门禁不跳过缺失文件）',
            'raw'  => '',
        ];

        return $result;
    }

    // 解析失败时直接返回：此时「缺哪些键」的结论全部不可信，继续报只会刷出一堆误导性噪音
    if (!$prod['parsed']['ok']) {
        $line = $prod['parsed']['line'];
        $where = $line === null ? '' : "（第 {$line} 行）";
        $result['errors'][] = [
            'tag'  => 'PROD-PARSE',
            'text' => '生产模板无法解析' . $where . '：' . $prod['parsed']['message'],
            'raw'  => $line !== null && isset($prod['lines'][$line - 1]) ? trim($prod['lines'][$line - 1]) : '',
        ];
        $result['errors'][] = [
            'tag'  => 'PROD-PARSE',
            'text' => '常见成因：注释行里的半角括号 / 引号 / 竖线 / 与号 / 美元符号'
                . '（它们都参与 INI 词法分析，会直接让 parse_ini_file 报语法错误）',
            'raw'  => '',
        ];
        $result['errors'][] = [
            'tag'  => 'PROD-PARSE',
            'text' => '后果：think\Env::load() 会把整份配置按空数组兜底'
                . '（parse_ini_file 失败即回落空数组）→ 所有键静默失效，'
                . '照抄模板上生产等于「容器起得来但连不上库 / 鉴权全挂」',
            'raw'  => '',
        ];

        return $result;
    }

    // 伪键：注释行里的半角等号会被解析成「# 某段文字」这样的键名
    // 复用 checkPseudoKeys()：它会把键名回填到原始行号与原文，报错才好定位
    foreach (checkPseudoKeys($prod['parsed'], $prod['lines']) as $issue) {
        $where = $issue['line'] === null ? '' : "（第 {$issue['line']} 行）";
        $result['errors'][] = [
            'tag'  => 'PROD-PSEUDO-KEY',
            'text' => "非法键名 \"{$issue['path']}\"{$where}；成因：注释行里的半角等号会被当成键值对",
            'raw'  => $issue['raw'],
        ];
    }

    // 注释规范：与检查 C 同一套规则（# 注释里的半角括号/引号同样会让整份文件失效）
    $commentIssues = checkCommentChars($prod['lines']);
    foreach ($commentIssues['errors'] as $issue) {
        $result['errors'][] = [
            'tag'  => 'PROD-COMMENT',
            'text' => "第 {$issue['line']} 行（{$issue['marker']} 注释）含 " . implode(' ', $issue['chars'])
                . '；' . $issue['note'],
            'raw'  => $issue['raw'],
        ];
    }
    foreach ($commentIssues['warnings'] as $issue) {
        $result['warnings'][] = [
            'tag'  => 'PROD-COMMENT',
            'text' => "第 {$issue['line']} 行（{$issue['marker']} 注释）含 " . implode(' ', $issue['chars'])
                . '；' . $issue['note'],
            'raw'  => $issue['raw'],
        ];
    }

    // 核心断言：示例键 → 生产模板键（单向）
    $missing = array_values(array_diff($exampleKeys, $prod['validKeys']));
    sort($missing);
    foreach ($missing as $key) {
        $result['errors'][] = [
            'tag'  => 'PROD-MISSING-KEY',
            'text' => "生产模板缺少示例键 {$key}"
                . '（.env.example 是键集合的单一真相源；若该键确属 dev 专用，应把它从 .env.example 移除，'
                . '而不是让它只存在于示例里）',
            'raw'  => '',
        ];
    }

    // 反向差额只作提示：生产模板可以有示例里没有的键
    $extra = array_values(array_diff($prod['validKeys'], $exampleKeys));
    sort($extra);
    $result['extra'] = $extra;

    return $result;
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

$strict = in_array('--strict', $argv, true);

// 规则目录：优先走 composer autoload（若已注册），否则直接 require 源文件。
// 直接 require 是刻意设计的：该 catalog 零依赖（不 import ThinkPHP 类、不调 env()、不碰容器），
// 因此本脚本在 `composer install` 之前、在没有 vendor/autoload.php 的 CI 环境里同样能跑。
// 与 scripts/check-env-production.php 完全同一套加载方式（同一个类、同一份规则）。
$catalogPath = __DIR__ . '/../' . CATALOG_FILE;
if (!class_exists(EnvRequirementCatalog::class)) {
    if (!is_file($catalogPath)) {
        // fail-closed：类文件都没了就别继续 —— 检查 D 的键清单无从得知，
        // 「跳过检查 D 却仍然 exit 0」会让这条 required 门禁变成摆设。
        echo colorize('致命错误: 规则目录不存在 ' . CATALOG_FILE, 'red') . PHP_EOL;
        echo '本脚本的「必需键」清单来自该类的 template_required 维度，缺失时无法给出可信结论。' . PHP_EOL;
        exit(1);
    }
    require_once $catalogPath;
}
if (!class_exists(EnvRequirementCatalog::class, false)) {
    // require 成功但类仍不存在（命名空间/类名被改写）同样必须立刻失败
    echo colorize('致命错误: 无法加载规则目录 ' . CATALOG_FILE, 'red') . PHP_EOL;
    echo '本脚本的「必需键」清单来自该类的 template_required 维度，缺失时无法给出可信结论。' . PHP_EOL;
    exit(1);
}

echo colorize('Environment Example Check | 环境配置模板门禁（.env.example + .env.production.example）', 'blue') . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;

// 用 realpath 规整路径：parse_ini_file 的语法错误信息会带上传入的路径，
// 不做处理会输出 `/var/www/html/scripts/../.env.example` 这种难读的形式
$targetPath = realpath(REPO_ROOT . '/' . TARGET_FILE) ?: (REPO_ROOT . '/' . TARGET_FILE);
$lines = readLines($targetPath);

// 末行换行会多切出一个空元素，展示行数时减掉，与 wc -l 口径一致
$displayLineCount = count($lines);
if ($displayLineCount > 0 && $lines[$displayLineCount - 1] === '') {
    $displayLineCount--;
}

echo '仓库根: ' . (realpath(REPO_ROOT) ?: REPO_ROOT) . PHP_EOL;
echo '示例模板: ' . TARGET_FILE . '（' . $displayLineCount . ' 行，检查 A / B / C / D）' . PHP_EOL;
echo '生产模板: ' . PROD_TEMPLATE_FILE . '（检查 E 校验其可解析、无伪键、注释规范与键覆盖）' . PHP_EOL . PHP_EOL;

$errorCount = 0;
$warningCount = 0;

// --- 检查 A：可解析 ---
$parsed = parseIni($targetPath);
$aErrors = [];
if (!$parsed['ok']) {
    $aErrors[] = $parsed['message'];
}
printf("检查 A  INI 文件可解析          %s\n", formatResult(count($aErrors)));
foreach ($aErrors as $issue) {
    echo '   ' . colorize('[PARSE]', 'red') . " {$issue}" . PHP_EOL;
    if ($parsed['line'] !== null && isset($lines[$parsed['line'] - 1])) {
        echo '   ' . colorize('[PARSE]', 'red') . ' 出错行内容: '
            . trim($lines[$parsed['line'] - 1]) . PHP_EOL;
    }
    $parseHint = $issue === '文件不存在'
        ? '文件缺失：被检查的示例配置必须随仓库存在（本门禁不跳过缺失文件）'
        : '后果：Env::load() 会把整份文件按空数组兜底 → 所有键静默失效（容器起得来但连不上库）';
    echo '   ' . colorize('[PARSE]', 'red') . ' ' . $parseHint . PHP_EOL;
}
$errorCount += count($aErrors);

$leafKeys = $parsed['ok'] ? collectLeafKeys($parsed['data']) : [];
echo '解析结果: ' . ($parsed['ok'] ? count($leafKeys) . ' 个键' : '（解析失败，无键可用）') . PHP_EOL . PHP_EOL;

// 合法示例键集合：检查 E（生产模板键覆盖）与检查 F（本地 .env 对比）共用。
// 只取合法键名 —— 伪键已由检查 B 报错，带进来只会让下游报错互相遮蔽。
$exampleKeys = [];
foreach ($leafKeys as $item) {
    if (preg_match(KEY_PATTERN, $item['key']) === 1) {
        $exampleKeys[] = $item['key'];
    }
}

// --- 检查 B：无伪键（依赖解析结果） ---
if (!$parsed['ok']) {
    echo '检查 B  键名无伪键              ' . colorize('SKIPPED（解析失败）', 'gray') . PHP_EOL;
} else {
    $bIssues = checkPseudoKeys($parsed, $lines);
    printf("检查 B  键名无伪键              %s\n", formatResult(count($bIssues)));
    foreach ($bIssues as $issue) {
        $where = $issue['line'] === null ? '' : "第 {$issue['line']} 行";
        echo '   ' . colorize('[PSEUDO-KEY]', 'red')
            . " 非法键名 \"{$issue['path']}\"（{$where}）" . PHP_EOL;
        if ($issue['raw'] !== '') {
            echo '      ' . colorize('原行:', 'gray') . ' ' . $issue['raw'] . PHP_EOL;
        }
        echo '      ' . colorize('成因: 注释行里的半角 = 会被 parse_ini_file 当成键值对', 'gray') . PHP_EOL;
    }
    $errorCount += count($bIssues);
}

// --- 检查 C：注释行里的 ASCII 括号/引号（不依赖解析结果，逐行扫描） ---
$cIssues = checkCommentChars($lines);
printf(
    "检查 C  注释无 ASCII 括号引号    %s\n",
    formatResult(count($cIssues['errors']), count($cIssues['warnings']))
);
foreach ($cIssues['errors'] as $issue) {
    echo '   ' . colorize('[COMMENT]', 'red')
        . " 第 {$issue['line']} 行（{$issue['marker']} 注释）含 " . implode(' ', $issue['chars']) . PHP_EOL;
    echo '      ' . colorize('原因:', 'gray') . ' ' . $issue['note'] . PHP_EOL;
    echo '      ' . colorize('原行:', 'gray') . ' ' . $issue['raw'] . PHP_EOL;
}
foreach ($cIssues['warnings'] as $issue) {
    echo '   ' . colorize('[COMMENT]', 'yellow')
        . " 第 {$issue['line']} 行（{$issue['marker']} 注释）含 " . implode(' ', $issue['chars']) . PHP_EOL;
    echo '      ' . colorize('原因:', 'gray') . ' ' . $issue['note'] . PHP_EOL;
    echo '      ' . colorize('原行:', 'gray') . ' ' . $issue['raw'] . PHP_EOL;
}
$errorCount += count($cIssues['errors']);
$warningCount += count($cIssues['warnings']);

// --- 检查 D：必需键存在（依赖解析结果） ---
if (!$parsed['ok']) {
    echo '检查 D  必需键存在              ' . colorize('SKIPPED（解析失败）', 'gray') . PHP_EOL;
} else {
    $present = [];
    foreach ($leafKeys as $item) {
        $present[$item['key']] = true;
    }
    $dIssues = [];
    // 键清单来自 catalog 的 template_required 维度（顺序即报错顺序），本脚本不再自带副本
    foreach (EnvRequirementCatalog::templateRequiredKeys() as $key) {
        if (!isset($present[$key])) {
            $dIssues[] = $key;
        }
    }
    printf("检查 D  必需键存在              %s\n", formatResult(count($dIssues)));
    foreach ($dIssues as $key) {
        echo '   ' . colorize('[MISSING-KEY]', 'red')
            . " 缺少必需键 {$key}（缺失不会让进程启动失败，只在运行期以连接/鉴权故障暴露）" . PHP_EOL;
    }
    $errorCount += count($dIssues);
}

// --- 检查 E：生产模板覆盖示例键集合（纯静态，不连库） ---
// 示例模板解析失败时无法确定「应有的键集合」，此时标记 SKIPPED 而不是静默通过：
// 检查 A 已经报错，门禁整体仍然是失败态。
if (!$parsed['ok']) {
    echo '检查 E  生产模板覆盖示例键集合  ' . colorize('SKIPPED（示例模板解析失败，键集合未知）', 'gray') . PHP_EOL;
} else {
    $prod = inspectTemplate(PROD_TEMPLATE_FILE);
    $eResult = checkProdTemplate($exampleKeys, $prod);

    printf(
        "检查 E  生产模板覆盖示例键集合  %s\n",
        formatResult(count($eResult['errors']), count($eResult['warnings']))
    );
    printDetail('目标文件:', PROD_TEMPLATE_FILE . '（' . $prod['lineCount'] . ' 行，' . count($prod['validKeys']) . ' 个键）');
    foreach ($eResult['errors'] as $issue) {
        printIssue($issue['tag'], $issue['text']);
        if ($issue['raw'] !== '') {
            printDetail('原行:', $issue['raw']);
        }
    }
    foreach ($eResult['warnings'] as $issue) {
        printIssue($issue['tag'], $issue['text'], 'yellow');
        if ($issue['raw'] !== '') {
            printDetail('原行:', $issue['raw']);
        }
    }
    // 反向差额只作提示：生产模板允许出现示例里没有的键，这是单向绑定的刻意留白
    if ($eResult['errors'] === [] && $eResult['warnings'] === []) {
        printIssue(
            'INFO',
            '生产模板额外键（允许，反向不要求）: '
            . ($eResult['extra'] === [] ? '无' : implode(', ', $eResult['extra'])),
            'gray'
        );
    }
    $errorCount += count($eResult['errors']);
    $warningCount += count($eResult['warnings']);
}

// --- 检查 F：本地 .env 键集合对比（提示项，不参与门禁） ---
if (!$parsed['ok']) {
    // 示例文件本身没解析出来时，任何对比结果都是噪音（本地键会全部显示为「额外」）
    echo '检查 F  本地 .env 键集合对比     ' . colorize('SKIPPED（示例文件解析失败，无法对比）', 'gray') . PHP_EOL;
} else {
    $local = compareLocalEnv($exampleKeys);
    if (!$local['exists']) {
        echo '检查 F  本地 .env 键集合对比     ' . colorize('SKIPPED（无 .env）', 'gray') . PHP_EOL;
    } elseif (!$local['parsed']) {
        echo '检查 F  本地 .env 键集合对比     ' . colorize('提示：.env 无法解析，已跳过对比', 'yellow') . PHP_EOL;
    } else {
        echo '检查 F  本地 .env 键集合对比     ' . colorize('仅提示（不参与门禁）', 'gray') . PHP_EOL;
        echo '   ' . colorize('[INFO]', 'gray')
            . ' 只比较键名，不读取也不打印任何值' . PHP_EOL;
        if ($local['onlyExample'] !== []) {
            echo '   ' . colorize('[INFO]', 'gray')
                . ' 本地 .env 缺少（将回落到默认值或直接报错）: ' . implode(', ', $local['onlyExample']) . PHP_EOL;
        }
        if ($local['onlyLocal'] !== []) {
            echo '   ' . colorize('[INFO]', 'gray')
                . ' 本地 .env 额外（允许，本地增补）: ' . implode(', ', $local['onlyLocal']) . PHP_EOL;
        }
        if ($local['onlyExample'] === [] && $local['onlyLocal'] === []) {
            echo '   ' . colorize('[INFO]', 'gray') . ' 键集合与 .env.example 完全一致' . PHP_EOL;
        }
    }
}

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
