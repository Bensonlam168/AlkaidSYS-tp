#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Documentation Consistency Check | 文档一致性检查
 *
 * 检查 design/ 与 docs/ 下的 Markdown 文档，覆盖三类问题：
 *   1. 相对链接失效（指向不存在的 .md 文件）
 *   2. 代码围栏不配平（奇数个 ``` 会导致后续内容被吞入代码块）
 *   3. 示例代码引用了不存在的类（设计阶段命名 vs 实际实现）
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
 * 递归收集 .md 文件
 *
 * @param string[] $roots
 * @return string[]
 */
function collectMarkdownFiles(array $roots): array
{
    $files = [];
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
            $normalized = '/' . str_replace('\\', '/', $path);
            foreach (EXCLUDE_PATTERNS as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    continue 2;
                }
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
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

$files = collectMarkdownFiles(SCAN_ROOTS);
echo '扫描文件数: ' . count($files) . PHP_EOL . PHP_EOL;

$totalIssues = 0;

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
