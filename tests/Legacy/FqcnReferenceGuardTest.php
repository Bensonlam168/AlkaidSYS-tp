<?php

declare(strict_types=1);

namespace Tests\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * FQCN 静态审计守卫（类名引用指向不存在的文件时变红）
 *
 * 为什么需要它：
 *   `Foo\Bar::class` 在**编译期**就被折叠成一个字符串常量，完全不触发 autoload；
 *   只在 `use` 里导入、并未真正使用的类名同样不触发 autoload。于是命名空间或类名
 *   写错时：`php -l` 过、1042 + 811 个用例全绿、phpstan 不加级别也不报，
 *   一直要等到运行时 `new` / `ReflectionClass` / 容器解析才炸。
 *
 *   本仓库的活例子：插件 `plugins/workflow-engine/src/scheduling/` 下只有
 *   `SchedulerIProcessEventListener`，但 Container / ServiceFactory / 插件 TestCase
 *   里有 3 处（4 行）引用了并不存在的 `SchedulerProcessEventListener`。
 *   这类缺陷任何测试都抓不到，只能静态扫。
 *
 * 审计面（只审计项目自有命名空间）：
 *   前缀与目录的对应关系**从仓库根 `composer.json` 与 `plugins/*\/composer.json`
 *   里真实声明的 psr-4 读取**，不在本文件硬编码第二份映射 —— 前缀在 composer.json
 *   里改名后，守卫自动跟随；前缀被删掉则由「每个被扫描目录都必须有映射指向它」
 *   这条断言当场变红，而不是安静地少扫一半。
 *
 * 抽取的引用形态：
 *   1. 命名空间级 `use A\B\C;`（含 `as` 别名、`{A, B}` 组语法、逗号多项）；
 *   2. `A\B\C::class`（含全限定 `\A\B\C`、限定名 `A\B\C`、别名前缀 `Alias\C`）。
 *
 * 刻意不管的（否则必然误报）：
 *   - `self::class` / `static::class` / `parent::class`；
 *   - 非项目命名空间的 FQCN（ThinkPHP / PHP-DI / Symfony / PSR 等一律不管）；
 *   - 字符串字面量与注释里的内容（token 化天然隔离，不做文本 grep）；
 *   - 闭包 `use ($x)` 与 `use function` / `use const`；
 *   - 类体内的 trait `use`：不在命名空间层级，缺 trait 会在类加载时立刻致命，
 *     不属于「静默到运行时才炸」这一类，故不纳入本守卫。
 *
 * 两类「引用合法但没有独立文件」的豁免（都不是放宽判定，是本来就成立）：
 *   1. **同文件内声明的类**：PHP 允许一个文件里声明多个类，测试文件普遍用这种写法
 *      内联探针/桩类（如 `tests/Unit/Model/BaseModelTenantScopeGuardTest.php`
 *      末尾的 `BrokenColumnProbeModel`）。多命名空间的文件同样支持
 *      （`tests/Unit/Plugin/PluginManagerTest.php` 里既有 `plugin\sample_plugin`
 *      又有 `Tests\Unit\Plugin`）。
 *   2. **`class_exists()` 家族刻意守卫的探测**：`if (class_exists('\app\service\JwtService'))`
 *      后紧跟 `\app\service\JwtService::class` 是「可选集成」的显式契约 ——
 *      该类不存在属于设计内状态，代码已自行兜底。把这类引用判红，
 *      等于强迫作者删掉可选的探测分支。
 *
 *   已知盲区（刻意接受）：上述两类豁免是**按同一文件**放宽的，因此
 *   ① `class_exists('X')` 里 X 本身拼错，② 同文件里另一个命名空间的同名类，
 *   都抓不到。前者运行时本来就静默走 false 分支，后者不会炸。
 *   若将来要收紧，应改成「按语句块就近匹配」而不是本文件级的集合。
 *
 * 判定方式：
 *   由 FQCN 反推 psr-4 期望文件路径，先看文件是否存在，**再逐段比对大小写**。
 *   只靠 file_exists 是不够的：macOS / Windows 的文件系统大小写不敏感，
 *   大小写写错照样返回 true，于是只有 Linux 才会炸的问题在本地永远发现不了。
 *   命名空间前缀本身的大小写单独判（Composer 的 psr-4 查找区分大小写，
 *   把 `Tests\` 写成 `tests\` 在任何平台上都会 class not found）。
 */
final class FqcnReferenceGuardTest extends TestCase
{
    /**
     * 审计的目录（相对仓库根的 posix 路径）
     */
    private const SCAN_DIRECTORIES = [
        'app',
        'domain',
        'infrastructure',
        'tests',
        'plugins/workflow-engine/src',
        'plugins/workflow-engine/tests',
    ];

    /**
     * 本守卫自身（内含示例字符串，且不是项目运行代码）
     */
    private const SELF_RELATIVE_PATH = 'tests/Legacy/FqcnReferenceGuardTest.php';

    /**
     * 扫描文件数下限
     *
     * 只用于「防止守卫空转」，**不是第二份映射**：扫描目录被挪走、过滤器被写坏、
     * 递归深度被截断时，守卫会以 0 个文件「全绿」——那比没有守卫更糟
     * （报告里显示为「已纳入 CI」，实际一个字节都没扫）。当前实测约 635 个，
     * 取 400 作为宽松下限：真要缩到这个数以下，必须显式改这个常量，改动会出现在 diff 里。
     */
    private const MIN_SCANNED_FILES = 400;

    public function testProjectFqcnReferencesResolveToExistingClassFiles(): void
    {
        $projectRoot = dirname(__DIR__, 2);

        // ---- 1. 映射来源：composer.json 的 psr-4 ----
        $psr4Map = self::loadPsr4Map($projectRoot);
        $this->assertNotEmpty($psr4Map, 'psr-4 映射为空：守卫会静默空转，先检查 loadPsr4Map() 与 composer.json');

        // 每个被扫描目录都必须有 psr-4 映射指向它，否则该目录里的引用根本判定不了
        $mappedDirectories = array_map(
            static fn (array $entry): string => $entry['dir'],
            $psr4Map
        );
        foreach (self::SCAN_DIRECTORIES as $directory) {
            $this->assertContains(
                $projectRoot . '/' . $directory,
                $mappedDirectories,
                sprintf('composer.json 的 psr-4 里没有指向 %s 的映射，该目录已不在审计面内', $directory)
            );
        }

        // ---- 2. 扫描目标 ----
        $files = self::listPhpFiles($projectRoot);
        $this->assertGreaterThanOrEqual(
            self::MIN_SCANNED_FILES,
            count($files),
            sprintf(
                '只扫到 %d 个 PHP 文件（下限 %d）：扫描目录被挪走或过滤条件被写坏了',
                count($files),
                self::MIN_SCANNED_FILES
            )
        );

        // ---- 3. 审计 ----
        $result = self::audit($projectRoot, $psr4Map, $files);
        $this->assertGreaterThan(
            0,
            $result['references'],
            '一个项目命名空间的 FQCN 引用都没抽到：抽取逻辑失效，守卫等于不存在'
        );

        // ---- 4. 一次性断言 ----
        $this->assertSame(
            [],
            $result['violations'],
            "以下 FQCN 引用指向不存在的类文件。::class 在编译期折叠成字符串常量、不触发 autoload，\n"
            . "这类错误 php -l 与全部用例都抓不到，只有真正 new / 反射时才炸。请逐个修正类名或命名空间：\n"
            . implode("\n", $result['violations'])
        );
    }

    /**
     * 读取 psr-4 映射（根 composer.json + plugins/*\/composer.json）
     *
     * 每份 composer.json 的 psr-4 目录都相对于该文件所在目录解析，
     * 因此同一前缀（如 Alkaid\Workflow\Engine\Tests\）在两处声明会得到同一个绝对路径。
     *
     * @return list<array{prefix: string, dir: string}> 已按前缀长度倒序（最长前缀优先）
     */
    private static function loadPsr4Map(string $projectRoot): array
    {
        $composerFiles = [$projectRoot . '/composer.json'];
        foreach (glob($projectRoot . '/plugins/*/composer.json') ?: [] as $pluginComposer) {
            $composerFiles[] = $pluginComposer;
        }

        $map = [];
        foreach ($composerFiles as $composerFile) {
            $raw = @file_get_contents($composerFile);
            if ($raw === false) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            $baseDirectory = dirname($composerFile);
            foreach (['autoload', 'autoload-dev'] as $section) {
                $psr4 = $decoded[$section]['psr-4'] ?? null;
                if (!is_array($psr4)) {
                    continue;
                }
                foreach ($psr4 as $prefix => $paths) {
                    $prefix = rtrim((string) $prefix, '\\') . '\\';
                    foreach ((array) $paths as $path) {
                        $map[] = [
                            'prefix' => $prefix,
                            'dir' => rtrim($baseDirectory . '/' . trim((string) $path, '/'), '/'),
                        ];
                    }
                }
            }
        }

        // 最长前缀优先：Alkaid\Workflow\Engine\Tests\ 必须排在 Alkaid\Workflow\Engine\ 之前
        usort(
            $map,
            static fn (array $left, array $right): int => strlen($right['prefix']) <=> strlen($left['prefix'])
        );

        return $map;
    }

    /**
     * 列出审计范围内的全部 PHP 文件
     *
     * @return array<string, string> 相对路径（posix）=> 绝对路径
     */
    private static function listPhpFiles(string $projectRoot): array
    {
        $files = [];
        foreach (self::SCAN_DIRECTORIES as $directory) {
            $baseDirectory = $projectRoot . '/' . $directory;
            if (!is_dir($baseDirectory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDirectory, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $absolutePath = $file->getPathname();
                if (str_contains($absolutePath, '/vendor/')) {
                    continue;
                }
                $relativePath = str_replace($projectRoot . '/', '', $absolutePath);
                if ($relativePath === self::SELF_RELATIVE_PATH) {
                    continue;
                }
                $files[$relativePath] = $absolutePath;
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * 执行审计
     *
     * @param list<array{prefix: string, dir: string}> $psr4Map
     * @param array<string, string> $files 相对路径 => 绝对路径
     * @return array{violations: list<string>, references: int}
     */
    private static function audit(string $projectRoot, array $psr4Map, array $files): array
    {
        $violations = [];
        $referenceCount = 0;
        /** @var array<string, list<string>> $directoryCache scandir 结果缓存，避免重复读目录 */
        $directoryCache = [];

        foreach ($files as $relativePath => $absolutePath) {
            $code = @file_get_contents($absolutePath);
            if ($code === false || $code === '') {
                continue;
            }

            $parsed = self::extractReferences($code);

            foreach ($parsed['references'] as [$fqcn, $line, $kind]) {
                // 同文件内声明 / 被 class_exists() 家族守卫：见类注释里的两类豁免
                if (isset($parsed['declared'][strtolower($fqcn)]) || isset($parsed['guarded'][strtolower($fqcn)])) {
                    continue;
                }

                $mapped = self::mapToExpectedPath($psr4Map, $fqcn);
                if ($mapped === null) {
                    continue; // 非项目命名空间：ThinkPHP / PHP-DI / Symfony / PSR 等，一律不管
                }
                $referenceCount++;

                // 前缀本身的大小写错了，Composer 的 psr-4 查找是精确匹配，运行时必然 class not found
                if (!$mapped['prefixCaseExact']) {
                    $violations[] = [
                        'file' => $relativePath,
                        'line' => $line,
                        'message' => sprintf(
                            '%s:%d  引用 %s（%s）→ 命名空间前缀大小写与 composer.json 声明不符（声明为 %s），Composer 的 psr-4 查找区分大小写',
                            $relativePath,
                            $line,
                            $fqcn,
                            $kind,
                            $mapped['prefix']
                        ),
                    ];
                    continue;
                }

                $expectedPath = $mapped['path'];
                $expectedRelative = str_replace($projectRoot . '/', '', $expectedPath);

                if (!file_exists($expectedPath)) {
                    $violations[] = [
                        'file' => $relativePath,
                        'line' => $line,
                        'message' => sprintf(
                            '%s:%d  引用 %s（%s）→ 期望文件 %s  不存在',
                            $relativePath,
                            $line,
                            $fqcn,
                            $kind,
                            $expectedRelative
                        ),
                    ];
                    continue;
                }

                $caseIssue = self::detectCaseMismatch($expectedPath, $directoryCache);
                if ($caseIssue !== null) {
                    $violations[] = [
                        'file' => $relativePath,
                        'line' => $line,
                        'message' => sprintf(
                            '%s:%d  引用 %s（%s）→ 期望文件 %s  大小写不符：%s',
                            $relativePath,
                            $line,
                            $fqcn,
                            $kind,
                            $expectedRelative,
                            $caseIssue
                        ),
                    ];
                }
            }
        }

        usort(
            $violations,
            static fn (array $left, array $right): int => [$left['file'], $left['line']] <=> [$right['file'], $right['line']]
        );

        return [
            'violations' => array_values(array_unique(array_column($violations, 'message'))),
            'references' => $referenceCount,
        ];
    }

    /**
     * FQCN => psr-4 期望文件路径
     *
     * 前缀匹配**大小写不敏感**（PHP 的类名解析本身大小写不敏感），
     * 这样「前缀被写成 tests 而不是 Tests」也能被拉进审计，再由调用方判红。
     * 前缀之后的相对部分保留原样大小写，交给逐段比对判定。
     *
     * @param list<array{prefix: string, dir: string}> $psr4Map
     * @return array{path: string, prefix: string, prefixCaseExact: bool}|null
     */
    private static function mapToExpectedPath(array $psr4Map, string $fqcn): ?array
    {
        $fqcn = ltrim($fqcn, '\\');
        if ($fqcn === '') {
            return null;
        }

        foreach ($psr4Map as $entry) {
            $prefixLength = strlen($entry['prefix']);
            if (strncasecmp($fqcn, $entry['prefix'], $prefixLength) !== 0) {
                continue;
            }
            $relative = substr($fqcn, $prefixLength);
            if ($relative === '') {
                return null; // 前缀本身不是一个类
            }

            return [
                'path' => $entry['dir'] . '/' . str_replace('\\', '/', $relative) . '.php',
                'prefix' => $entry['prefix'],
                'prefixCaseExact' => strncmp($fqcn, $entry['prefix'], $prefixLength) === 0,
            ];
        }

        return null;
    }

    /**
     * 逐段比对路径大小写
     *
     * @param array<string, list<string>> $directoryCache
     * @return string|null null 表示逐段大小写完全一致
     */
    private static function detectCaseMismatch(string $absolutePath, array &$directoryCache): ?string
    {
        $segments = explode('/', str_replace('\\', '/', $absolutePath));
        $current = '';

        foreach ($segments as $position => $segment) {
            if ($position === 0) {
                $current = '/';
                continue;
            }
            if ($segment === '') {
                continue;
            }

            if (!array_key_exists($current, $directoryCache)) {
                $listed = @scandir($current);
                $directoryCache[$current] = $listed === false ? [] : array_values($listed);
            }

            if (!in_array($segment, $directoryCache[$current], true)) {
                $actual = null;
                foreach ($directoryCache[$current] as $entry) {
                    if (strcasecmp($entry, $segment) === 0) {
                        $actual = $entry;
                        break;
                    }
                }

                return $actual === null
                    ? sprintf('路径段「%s」在 %s 下不存在', $segment, $current)
                    : sprintf('路径段「%s」在磁盘上实际为「%s」', $segment, $actual);
            }

            $current = $current === '/' ? '/' . $segment : $current . '/' . $segment;
        }

        return null;
    }

    /**
     * 从一个 PHP 文件的源码里抽取「类引用」
     *
     * 用 token_get_all() 而不是正则：字符串字面量、注释、heredoc 里的内容天然不会被
     * 误当成引用，这是本守卫「不误报」的基础。
     *
     * @return array{
     *     references: list<array{0: string, 1: int, 2: string}>,
     *     declared: array<string, string>,
     *     guarded: array<string, string>
     * } references 为 [FQCN, 行号, 形态]；declared 为本文件内声明的类（小写 FQCN 为键）；
     *   guarded 为被 class_exists() 家族守卫的类（小写 FQCN 为键）
     */
    private static function extractReferences(string $code): array
    {
        $tokens = token_get_all($code);
        $total = count($tokens);

        $namespace = '';
        /** @var array<string, string> $imports 小写别名 => FQCN */
        $imports = [];
        $depth = 0;
        $namespaceDepth = 0;
        $references = [];
        /** @var array<string, string> $declared 本文件内声明的类，小写 FQCN 为键 */
        $declared = [];
        /** @var array<string, string> $guarded 被 class_exists() 家族守卫的类，小写 FQCN 为键 */
        $guarded = [];

        for ($index = 0; $index < $total; $index++) {
            $token = $tokens[$index];

            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                    // namespace X { ... } 块结束：回到全局命名空间
                    if ($namespaceDepth > 0 && $depth < $namespaceDepth) {
                        $namespace = '';
                        $imports = [];
                        $namespaceDepth = 0;
                    }
                }
                continue;
            }

            $tokenId = $token[0];
            $tokenLine = $token[2];

            // ---------- 同文件内的类/接口/trait/enum 声明 ----------
            if (in_array($tokenId, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $previousIndex = self::previousSignificant($tokens, $index - 1);
                $previous = $previousIndex === null ? null : $tokens[$previousIndex];
                if (is_array($previous) && ($previous[0] === T_DOUBLE_COLON || $previous[0] === T_NEW)) {
                    continue; // `Foo::class` 或匿名类 `new class { ... }`
                }

                $nameIndex = self::nextSignificant($tokens, $index + 1);
                if ($nameIndex === null) {
                    continue;
                }
                $nameToken = $tokens[$nameIndex];
                if (is_array($nameToken) && $nameToken[0] === T_STRING) {
                    $declaredFqcn = $namespace === '' ? $nameToken[1] : $namespace . '\\' . $nameToken[1];
                    $declared[strtolower($declaredFqcn)] = $declaredFqcn;
                }
                continue;
            }

            // ---------- class_exists('\A\B') / class_exists(\A\B::class) 守卫 ----------
            if (
                $tokenId === T_STRING
                && in_array(strtolower($token[1]), ['class_exists', 'interface_exists', 'trait_exists', 'enum_exists'], true)
            ) {
                $guardedFqcn = self::readGuardedFqcn($tokens, $index, $namespace, $imports);
                if ($guardedFqcn !== null) {
                    $guarded[strtolower($guardedFqcn)] = $guardedFqcn;
                }
                continue;
            }

            // ---------- 命名空间声明 ----------
            if ($tokenId === T_NAMESPACE) {
                $nextIndex = self::nextSignificant($tokens, $index + 1);
                if ($nextIndex !== null && $tokens[$nextIndex] === '\\') {
                    continue; // `namespace\Foo` 相对名，不是声明
                }

                $name = '';
                $cursor = $index + 1;
                for (; $cursor < $total; $cursor++) {
                    $current = $tokens[$cursor];
                    if (is_string($current)) {
                        if ($current === ';') {
                            break;
                        }
                        if ($current === '{') {
                            $namespaceDepth = $depth + 1;
                            $depth++;
                            break;
                        }
                        if ($current === '\\') {
                            $name .= '\\';
                        }
                        continue;
                    }
                    if (in_array($current[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $name .= $current[1];
                    }
                }
                $namespace = trim($name, '\\');
                $index = $cursor;
                continue;
            }

            // ---------- use 导入 ----------
            if ($tokenId === T_USE) {
                if ($depth !== $namespaceDepth) {
                    continue; // 类体内 trait use（缺 trait 会在类加载时立刻致命，不在本守卫目标内）
                }
                $cursor = self::nextSignificant($tokens, $index + 1);
                if ($cursor !== null && $tokens[$cursor] === '(') {
                    continue; // 闭包 use ($x)
                }

                $end = $index;
                $statement = self::readUseStatement($tokens, $index + 1, $end);
                foreach (self::parseUseStatement($statement) as [$fqcn, $alias]) {
                    $references[] = [$fqcn, $tokenLine, 'use'];
                    $imports[$alias] = $fqcn;
                }
                $index = $end;
                continue;
            }

            // ---------- Foo::class ----------
            if ($tokenId === T_DOUBLE_COLON) {
                $nextIndex = self::nextSignificant($tokens, $index + 1);
                if ($nextIndex === null || !is_array($tokens[$nextIndex]) || $tokens[$nextIndex][0] !== T_CLASS) {
                    continue;
                }
                $previousIndex = self::previousSignificant($tokens, $index - 1);
                if ($previousIndex === null) {
                    continue;
                }
                $previous = $tokens[$previousIndex];
                if (
                    !is_array($previous)
                    || !in_array(
                        $previous[0],
                        [T_STRING, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE],
                        true
                    )
                ) {
                    continue; // $object::class、$array[0]::class 等动态写法
                }

                $name = $previous[1];
                if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
                    continue;
                }
                $references[] = [self::resolveClassName($name, $namespace, $imports), $previous[2], '::class'];
            }
        }

        return [
            'references' => $references,
            'declared' => $declared,
            'guarded' => $guarded,
        ];
    }

    /**
     * 读取 class_exists() 家族的第一个实参所指向的类名
     *
     * 支持两种写法：字符串字面量 `'\app\service\JwtService'` 与 `\app\service\JwtService::class`。
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string> $imports 小写别名 => FQCN
     */
    private static function readGuardedFqcn(array $tokens, int $index, string $namespace, array $imports): ?string
    {
        $openParen = self::nextSignificant($tokens, $index + 1);
        if ($openParen === null || $tokens[$openParen] !== '(') {
            return null;
        }

        $argumentIndex = self::nextSignificant($tokens, $openParen + 1);
        if ($argumentIndex === null) {
            return null;
        }
        $argument = $tokens[$argumentIndex];

        if (is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING) {
            $literal = trim(substr($argument[1], 1, -1));

            return $literal === '' ? null : ltrim($literal, '\\');
        }

        if (
            is_array($argument)
            && in_array($argument[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
        ) {
            $doubleColon = self::nextSignificant($tokens, $argumentIndex + 1);
            if (
                $doubleColon !== null
                && is_array($tokens[$doubleColon])
                && $tokens[$doubleColon][0] === T_DOUBLE_COLON
            ) {
                return self::resolveClassName($argument[1], $namespace, $imports);
            }
        }

        return null;
    }

    /**
     * 读取一条 use 语句（到分号为止，组语法的花括号不截断）
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function readUseStatement(array $tokens, int $start, int &$end): string
    {
        $total = count($tokens);
        $buffer = '';
        $brace = 0;

        for ($cursor = $start; $cursor < $total; $cursor++) {
            $token = $tokens[$cursor];
            if (is_string($token)) {
                if ($token === ';' && $brace === 0) {
                    $end = $cursor;

                    return $buffer;
                }
                if ($token === '{') {
                    $brace++;
                } elseif ($token === '}') {
                    $brace--;
                }
                $buffer .= $token;
                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            if ($token[0] === T_WHITESPACE) {
                $buffer .= ' ';
                continue;
            }
            $buffer .= $token[1];
        }

        $end = $total - 1;

        return $buffer;
    }

    /**
     * 解析 use 语句，返回其中导入的类名
     *
     * 支持：`A\B\C`、`A\B\C as D`、`A\B, C\D`、`A\{B, C as D}`、`A\{B\C}`；
     * 跳过 `use function ...` / `use const ...` 以及组语法里的 function / const 项。
     *
     * @return list<array{0: string, 1: string}> [FQCN, 小写别名]
     */
    private static function parseUseStatement(string $statement): array
    {
        $statement = trim((string) preg_replace('/\s+/', ' ', $statement));
        if ($statement === '') {
            return [];
        }
        if (preg_match('/^(?:function|const)\s/i', $statement) === 1) {
            return [];
        }

        // 顶层按逗号切分，花括号内的逗号属于组语法
        $items = [];
        $buffer = '';
        $brace = 0;
        $length = strlen($statement);
        for ($cursor = 0; $cursor < $length; $cursor++) {
            $character = $statement[$cursor];
            if ($character === '{') {
                $brace++;
            } elseif ($character === '}') {
                $brace--;
            } elseif ($character === ',' && $brace === 0) {
                $items[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $character;
        }
        if (trim($buffer) !== '') {
            $items[] = trim($buffer);
        }

        $imports = [];
        foreach ($items as $item) {
            if ($item === '' || preg_match('/^(?:function|const)\s/i', $item) === 1) {
                continue;
            }

            $openBrace = strpos($item, '{');
            if ($openBrace === false) {
                $parsed = self::splitUseItem('', $item);
                if ($parsed !== null) {
                    $imports[] = $parsed;
                }
                continue;
            }

            $prefix = substr($item, 0, $openBrace);
            $inner = rtrim(substr($item, $openBrace + 1));
            if (str_ends_with($inner, '}')) {
                $inner = substr($inner, 0, -1);
            }
            foreach (explode(',', $inner) as $subItem) {
                $subItem = trim($subItem);
                if ($subItem === '' || preg_match('/^(?:function|const)\s/i', $subItem) === 1) {
                    continue;
                }
                $parsed = self::splitUseItem($prefix, $subItem);
                if ($parsed !== null) {
                    $imports[] = $parsed;
                }
            }
        }

        return $imports;
    }

    /**
     * 拆出单个 use 项（组语法的 prefix + 项名）的 FQCN 与别名
     *
     * @return array{0: string, 1: string}|null [FQCN, 小写别名]
     */
    private static function splitUseItem(string $prefix, string $item): ?array
    {
        $alias = '';
        if (preg_match('/^(.*?)\s+as\s+(\S+)$/i', $item, $matches) === 1) {
            $item = trim($matches[1]);
            $alias = trim($matches[2]);
        }

        $item = trim($item, " \t\n\r\0\x0B\\");
        if ($item === '') {
            return null;
        }

        $trimmedPrefix = trim($prefix, " \t\n\r\0\x0B\\");
        $fqcn = ltrim($trimmedPrefix === '' ? $item : $trimmedPrefix . '\\' . $item, '\\');
        if ($fqcn === '') {
            return null;
        }

        if ($alias === '') {
            $separator = strrpos($fqcn, '\\');
            $alias = $separator === false ? $fqcn : substr($fqcn, $separator + 1);
        }

        return [$fqcn, strtolower($alias)];
    }

    /**
     * 按 PHP 的名字解析规则把写法还原成绝对 FQCN
     *
     * 三种形态：`\A\B` 绝对名；`A\B` 限定名（首段先查 use 别名，再拼当前命名空间）；
     * `B` 非限定名（先查 use 别名，再拼当前命名空间）。
     *
     * @param array<string, string> $imports 小写别名 => FQCN
     */
    private static function resolveClassName(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        if (strncasecmp($name, 'namespace\\', strlen('namespace\\')) === 0) {
            $rest = substr($name, strlen('namespace\\'));

            return $namespace === '' ? $rest : $namespace . '\\' . $rest;
        }

        $separator = strpos($name, '\\');
        $firstSegment = $separator === false ? $name : substr($name, 0, $separator);
        if (isset($imports[strtolower($firstSegment)])) {
            return $imports[strtolower($firstSegment)] . ($separator === false ? '' : substr($name, $separator));
        }

        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextSignificant(array $tokens, int $index): ?int
    {
        $total = count($tokens);
        for (; $index < $total; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function previousSignificant(array $tokens, int $index): ?int
    {
        for (; $index >= 0; $index--) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }
}
