#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Documentation Consistency Check | 文档一致性检查
 *
 * 检查 design/ 与 docs/ 下的 Markdown 文档，**以及 `SCAN_FILES` 登记的根目录文档**
 * （目前是 AGENTS.md / CLAUDE.md / README.md / README.local.md），覆盖四类问题：
 *   0. 扫描范围不完整（登记项不存在、根目录 md 既未纳入也未豁免）
 *   1. 相对链接失效（指向不存在的 .md 文件）
 *   2. 代码围栏不配平（奇数个 ``` 会导致后续内容被吞入代码块）
 *   3. 示例代码引用了不存在的类（设计阶段命名 vs 实际实现）
 *
 * 为什么要扫根目录：`AGENTS.md` 是 AI 助手的强制指令文件，此前因为落在扫描范围之外
 * 从**不被任何机器校验**，先后写下过两条过期事实。检查 0 保证「新加的根目录 md
 * 不会静默漏扫」—— 要么纳入 `SCAN_FILES`，要么写进 `SCAN_FILES_EXEMPT` 并说明原因。
 *
 * ⚠️ 覆盖边界（已知盲区，不要误以为「绿了=内容正确」）：
 *   本脚本只校验**结构**（链接目标存在、围栏成对、示例类存在），**不校验散文事实**。
 *   例如「`php think test` 支持指定文件」这类命令用法错误、或「某检查是两段式」这类
 *   流程描述过期，本脚本一律看不见，仍需人工与 PHPUnit 守卫覆盖。
 *   另外检查 1 只匹配 `](./x.md)` / `](../x.md)`，**裸相对链接** `](docs/x.md)` 不在其内。
 *
 * 用法：
 *   php scripts/check-docs.php              # 检查并输出报告
 *   php scripts/check-docs.php --strict     # 发现任何问题即返回非 0（用于 CI）
 *   php scripts/check-docs.php --skip-refs  # 跳过示例代码类引用检查
 *
 * 退出码：
 *   0 - 通过
 *   1 - 存在问题（--strict 模式）
 *
 * @package scripts
 */

// ---------------------------------------------------------------------------
// 配置
// ---------------------------------------------------------------------------

/**
 * 扫描根目录（相对仓库根）
 * report_archive/ 为历史归档，其链接天然失效，默认排除。
 */
const SCAN_ROOTS = ['design', 'docs'];

/**
 * 额外扫描的**单文件**（相对仓库根）
 *
 * 为什么需要它：`SCAN_ROOTS` 只接受目录，扫描范围之外的文件**完全不受校验**。
 * `AGENTS.md` 正是因此写下过两条过期事实（`php think test` 支持「指定文件」的错误说法、
 * `env-check` 的「两段式」描述）—— 没有任何机器检查会碰到根目录。
 *
 * 注意：这里**不能**用 `'.'` 代替 —— 那会把 `frontend/`、`plugins/` 下成千上万个 md
 * 一并扫进来（既慢又会产生大量与项目无关的失效链接）。
 *
 * 新增根目录 md 时请显式加入本清单；忘记加会被「检查 0」点名（见 SCAN_FILES_EXEMPT）。
 */
const SCAN_FILES = [
    'AGENTS.md',
    'CLAUDE.md',
    'README.md',
    'README.local.md',
];

/**
 * 根目录 md 的「不纳入扫描」豁免清单（键为文件名，值为原因）
 *
 * 只有**确有必要**时才登记，且必须写明原因。未登记也未纳入 `SCAN_FILES` 的根目录 md
 * 会被「检查 0」报错，从而逼迫提交者显式表态（纳入 / 豁免），避免新文件被静默漏掉。
 *
 * ⚠️ 豁免项**允许不存在**：被豁免的常常是只存在于本地的文件（如被 .gitignore 忽略的
 * 备份），CI 干净检出里本来就没有。因此本清单只用于「压掉未纳入扫描的报错」，
 * 不校验存在性 —— 否则 CI 会误红。
 */
const SCAN_FILES_EXEMPT = [
    // 本地合并备份，已被 .gitignore 忽略，不属于项目文档，内容会随本地合并变化
    'README.local-merge-backup.md' => '未纳入 git（见 .gitignore），本地人工合并备份，非项目文档',
];

/** 排除的路径片段 */
const EXCLUDE_PATTERNS = ['/docs/report_archive/', '/node_modules/', '/vendor/'];

/**
 * 命名空间 → 目录映射（设计阶段命名 → 实际路径）
 *
 * 该表用于判定示例代码中的 use 声明是否指向真实存在的类。
 * 与 docs/technical-specs/lowcode/lowcode-architecture-guidelines.md §5.3 保持一致。
 */
const NAMESPACE_MAP = [
    'Alkaid\\Workflow\\Engine\\' => 'plugins/workflow-engine/src/',
    'Alkaid\\'                   => 'plugins/workflow-engine/src/',
    'app\\'                      => 'app/',
    'Domain\\'                   => 'domain/',
    'Infrastructure\\'           => 'infrastructure/',
    'Tests\\'                    => 'tests/',
];

/**
 * 允许「不存在」的已登记例外
 *
 * 这些类在文档中是被**明确标注为不存在/已删除**的反例说明，
 * 不应计为文档缺陷。
 */
const KNOWN_ABSENT_OK = [
    'Infrastructure\\Field\\FieldTypeRegistry',
    'Infrastructure\\Collection\\CollectionManager',
    'Domain\\Model\\Collection',
];

// ---------------------------------------------------------------------------
// 工具函数
// ---------------------------------------------------------------------------

/**
 * 判断路径是否命中排除规则
 *
 * 目录递归与单文件**共用**这一处判断，保证两边语义一致。
 */
function isExcluded(string $path): bool
{
    $normalized = '/' . str_replace('\\', '/', $path);
    foreach (EXCLUDE_PATTERNS as $pattern) {
        if (str_contains($normalized, $pattern)) {
            return true;
        }
    }

    return false;
}

/**
 * 收集待检查的 .md 文件
 *
 * 顺序：先收 `SCAN_FILES` 里的单文件（不存在 → 记入 $errors，**不静默跳过**：
 * 配置里写了却不存在，说明配置已腐烂），再递归收 `SCAN_ROOTS` 的目录。
 * 结果去重后排序，保证输出稳定。
 *
 * @param string[] $roots
 * @param string[] $files 单文件清单（相对仓库根）
 * @param string[] $errors 出参：收集阶段的错误（登记项不存在 / 配置已腐烂）
 * @return string[]
 */
function collectMarkdownFiles(array $roots, array $files = [], array &$errors = []): array
{
    $collected = [];

    // --- 单文件 ---
    foreach ($files as $file) {
        if (isExcluded($file)) {
            continue;
        }
        if (!is_file($file)) {
            $errors[] = "SCAN_FILES 登记的文件不存在: {$file}（配置已腐烂，请修正清单）";
            continue;
        }
        // 误把非 .md 登记进来时不参与扫描，只留给「检查 0」报错，
        // 否则「扫描文件数」会被一个 json 文件污染，读数失真。
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'md') {
            continue;
        }
        $collected[] = $file;
    }

    // --- 目录递归 ---
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'md') {
                continue;
            }
            $path = $file->getPathname();
            if (isExcluded($path)) {
                continue;
            }
            $collected[] = $path;
        }
    }

    $collected = array_values(array_unique($collected));
    sort($collected);

    return $collected;
}

/**
 * 检查 0：扫描范围的完整性
 *
 * 两个方向都要盯：
 *   1. `SCAN_FILES` 登记的文件**必须存在**（配置腐烂；该项由 collectMarkdownFiles 报），
 *      且两者登记的项若存在则必须是 .md；
 *   2. 根目录的 md **必须**出现在两者之一里（覆盖缺口）。
 *
 * 第 2 条是为了让「将来新增根目录 md（如 CONTRIBUTING.md）」不会静默漏扫 ——
 * 若不做这件事，本清单会悄悄过期，门禁再次变成只覆盖 design/ + docs/。
 *
 * @return string[]
 */
function checkScanCoverage(): array
{
    $issues = [];

    // 1a) SCAN_FILES：存在性由收集阶段负责（避免同一条问题被报两遍），此处只校验扩展名
    foreach (SCAN_FILES as $file) {
        if (isExcluded($file) || !is_file($file)) {
            continue; // 排除规则优先；不存在的情况已在 collectMarkdownFiles 里报过
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'md') {
            $issues[] = "SCAN_FILES 登记的文件不是 .md: {$file}";
        }
    }

    // 1b) SCAN_FILES_EXEMPT：只校验「存在时才必须是 .md」，**不校验存在性**
    //
    // 为什么豁免项允许缺席：被豁免的往往是**只存在于本地**的文件（例：被 .gitignore
    // 忽略的 README.local-merge-backup.md）。若在这里要求文件必须存在，CI 的干净检出
    // 里没有这个文件 → 门禁直接误红。豁免清单的职责只是「别再对这个名字报未纳入扫描」，
    // 不承担「文件健康度」的担保。
    foreach (array_keys(SCAN_FILES_EXEMPT) as $file) {
        if (isExcluded($file) || !is_file($file)) {
            continue;
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'md') {
            $issues[] = "SCAN_FILES_EXEMPT 登记的文件不是 .md: {$file}";
        }
    }

    // 2) 根目录 md 必须被显式表态（只扫根目录这一层，不下钻）
    $rootFiles = glob('*.md') ?: [];
    sort($rootFiles);
    foreach ($rootFiles as $file) {
        if (isExcluded($file)) {
            continue;
        }
        if (in_array($file, SCAN_FILES, true)) {
            continue;
        }
        if (array_key_exists($file, SCAN_FILES_EXEMPT)) {
            continue;
        }
        $issues[] = "根目录 md 未纳入扫描: {$file}"
            . '（请加入 SCAN_FILES，或写进 SCAN_FILES_EXEMPT 并注明原因）';
    }

    return $issues;
}

/**
 * 读取文件为行数组
 *
 * @return string[]
 */
function readLines(string $path): array
{
    $content = @file_get_contents($path);
    if ($content === false) {
        return [];
    }

    return explode("\n", $content);
}

/**
 * 检查 1：相对链接有效性
 *
 * @param string[] $files
 * @return array<int, array{file:string, link:string}>
 */
function checkLinks(array $files): array
{
    $issues = [];
    foreach ($files as $file) {
        $dir = dirname($file);
        $lines = readLines($file);
        foreach ($lines as $line) {
            // 匹配 ](./x.md) 与 ](../x.md)，允许带 #锚点
            if (!preg_match_all('/\]\((\.{1,2}\/[^)#]+\.md)(?:#[^)]*)?\)/', $line, $matches)) {
                continue;
            }
            foreach ($matches[1] as $link) {
                $target = normalizePath($dir . '/' . $link);
                if (!is_file($target)) {
                    $issues[] = ['file' => $file, 'link' => $link];
                }
            }
        }
    }

    return $issues;
}

/**
 * 检查 2：代码围栏配平
 *
 * @param string[] $files
 * @return array<int, array{file:string, count:int}>
 */
function checkFences(array $files): array
{
    $issues = [];
    foreach ($files as $file) {
        $count = 0;
        foreach (readLines($file) as $line) {
            if (str_starts_with($line, '```')) {
                $count++;
            }
        }
        if ($count % 2 !== 0) {
            $issues[] = ['file' => $file, 'count' => $count];
        }
    }

    return $issues;
}

/**
 * 检查 3 的额外排除：审计报告类文档
 *
 * `docs/report/` 下的审查/分析报告会**引用有问题的代码片段作为证据**，
 * 这些引用本来就不应存在，属正常，故排除在本项检查之外。
 */
const REF_CHECK_EXCLUDE = ['/docs/report/'];

/**
 * 检查 3：示例代码引用的类是否存在
 *
 * **豁免规则**：
 *   1. 文档头部若已包含「命名空间声明」或「待实施方案」横幅 → 不参与本项检查
 *      （不匹配已被显式声明，不会误导读者）
 *   2. `docs/report/` 下的审计报告 → 不参与（它们引用错误示例作为证据）
 *
 * 该豁免**只对已声明的文档生效**，用于防止新增未声明文档再次引入错误引用。
 *
 * @param string[] $files
 * @return array<int, array{file:string, line:int, class:string}>
 */
function checkExampleClassRefs(array $files): array
{
    $issues = [];
    foreach ($files as $file) {
        $normalized = '/' . str_replace('\\', '/', $file);
        foreach (REF_CHECK_EXCLUDE as $pattern) {
            if (str_contains($normalized, $pattern)) {
                continue 2;
            }
        }
        if (hasDeclarationBanner($file)) {
            continue;
        }
        $lines = readLines($file);
        foreach ($lines as $index => $line) {
            if (!preg_match('/^\s*use\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*[;{]/', $line, $m)) {
                continue;
            }
            $class = $m[1];
            if (isKnownAbsentOk($class)) {
                continue;
            }
            $resolved = resolveClassPath($class);
            if ($resolved === null) {
                continue; // 非项目命名空间（框架/vendor 类），本脚本不校验
            }
            if (!is_file($resolved)) {
                $issues[] = ['file' => $file, 'line' => $index + 1, 'class' => $class];
            }
        }
    }

    return $issues;
}

/**
 * 判断文档是否已声明「示例使用设计阶段命名」或「待实施方案」
 *
 * 仅检查文档前 80 行，避免正文引用误判。
 */
function hasDeclarationBanner(string $file): bool
{
    $lines = readLines($file);
    $head = array_slice($lines, 0, 80);
    foreach ($head as $line) {
        if (str_contains($line, '命名空间声明') || str_contains($line, '待实施方案')) {
            return true;
        }
    }

    return false;
}

/**
 * 判断类是否属于「文档已标注不存在」的例外
 */
function isKnownAbsentOk(string $class): bool
{
    return in_array($class, KNOWN_ABSENT_OK, true);
}

/**
 * 将项目命名空间解析为文件路径；非项目命名空间返回 null
 */
function resolveClassPath(string $class): ?string
{
    foreach (NAMESPACE_MAP as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $rest = substr($class, strlen($prefix));
        if ($rest === '') {
            return null;
        }

        return $base . str_replace('\\', '/', $rest) . '.php';
    }

    return null;
}

/**
 * 规范化路径（处理 . 与 ..）
 */
function normalizePath(string $path): string
{
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }

    return implode('/', $parts);
}

/**
 * 终端着色
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

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

$strict = in_array('--strict', $argv, true);
$skipRefs = in_array('--skip-refs', $argv, true);

echo colorize('Documentation Consistency Check | 文档一致性检查', 'blue') . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;

$collectErrors = [];
$files = collectMarkdownFiles(SCAN_ROOTS, SCAN_FILES, $collectErrors);
echo '扫描文件数: ' . count($files) . PHP_EOL;

// --- 检查 0：扫描范围完整性（依赖收集阶段的结果，故单独输出）---
$coverageIssues = array_merge($collectErrors, checkScanCoverage());
printf("检查 0  扫描范围完整性        %s\n", formatResult(count($coverageIssues)));
foreach ($coverageIssues as $issue) {
    echo '   ' . colorize('[COVERAGE]', 'yellow') . " {$issue}" . PHP_EOL;
}
echo PHP_EOL;

$totalIssues = count($coverageIssues);

// --- 检查 1：链接 ---
$linkIssues = checkLinks($files);
printf("检查 1  相对链接有效性        %s\n", formatResult(count($linkIssues)));
foreach ($linkIssues as $issue) {
    echo '   ' . colorize('[LINK]', 'yellow') . " {$issue['file']} -> {$issue['link']}" . PHP_EOL;
}
$totalIssues += count($linkIssues);

// --- 检查 2：围栏 ---
$fenceIssues = checkFences($files);
printf("检查 2  代码围栏配平          %s\n", formatResult(count($fenceIssues)));
foreach ($fenceIssues as $issue) {
    echo '   ' . colorize('[FENCE]', 'yellow') . " {$issue['file']} ({$issue['count']} 个围栏)" . PHP_EOL;
}
$totalIssues += count($fenceIssues);

// --- 检查 3：示例类引用 ---
if ($skipRefs) {
    echo '检查 3  示例代码类引用        ' . colorize('SKIPPED', 'gray') . PHP_EOL;
} else {
    $refIssues = checkExampleClassRefs($files);
    printf("检查 3  示例代码类引用        %s\n", formatResult(count($refIssues)));
    foreach ($refIssues as $issue) {
        echo '   ' . colorize('[REF]', 'yellow')
            . " {$issue['file']}:{$issue['line']} -> {$issue['class']}" . PHP_EOL;
    }
    $totalIssues += count($refIssues);
}

echo str_repeat('-', 64) . PHP_EOL;

if ($totalIssues === 0) {
    echo colorize('✓ 全部检查通过', 'green') . PHP_EOL;
    exit(0);
}

echo colorize("发现 {$totalIssues} 个问题", 'red') . PHP_EOL;
if ($strict) {
    exit(1);
}

echo colorize('提示：使用 --strict 可在 CI 中作为门禁（存在问题即返回非 0）', 'gray') . PHP_EOL;
exit(0);

/**
 * 格式化检查结果
 */
function formatResult(int $count): string
{
    return $count === 0
        ? colorize('OK', 'green')
        : colorize("{$count} 个问题", 'red');
}
