<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * 根级安全属性清单与 PHPUnit XSD 的一致性测试 | Safety-root table matches PHPUnit schema
 *
 * ## 这条用例钉住什么
 *
 * `scripts/check-phpunit-config.php` 的检查 G 靠一张**硬编码**的属性表
 * （`SAFETY_ROOT_ATTRIBUTES` + `REQUIRED_TRUE_ROOT_ATTRIBUTES`）判断
 * 「变体的根级安全属性有没有偷偷漂移」。这张表本身有两个不容易被发现的失效方式：
 *
 *   1. **默认值写错**：未声明的属性按表里的默认值折算生效值。表里若把
 *      `failOnEmptyTestSuite` 的默认值写成 `true`，那么「变体漏写这一项」就会被判成
 *      「与基线一致」—— 检查 G 天天显示 OK，却正好漏掉它出生的那个缺陷；
 *   2. **清单过期**：PHPUnit 升级后新增 `failOnXxx` / 移除旧属性时，表不会自己跟着变，
 *      新属性等于没人管（默认值错误同理）。这类漂移在仓库里**没有任何运行期症状**。
 *
 * 所以这里把表与 `vendor/phpunit/phpunit/phpunit.xsd` 对齐：属性集合必须互为子集，
 * 默认值必须逐项一致，三个「必须为 true」的属性被钉死。升级 PHPUnit 后本用例变红，
 * 它要求的是「重新看一遍这张表」，而不是「改测试让它绿」。
 *
 * ## 为什么用普通 TestCase
 *
 * 被测对象是「一个纯 PHP 脚本里的静态规则表 + 脚本本身的退出码」，
 * 不需要 App、不需要 DB / Redis；起子进程是为了让门禁按真实入口跑一遍
 * （门禁脚本坏了、改名了、被删了，这里立刻红，而不是等某次 CI 才暴露）。
 *
 * @package Tests\Unit\Scripts
 */
final class PhpunitConfigSafetyRootConsistencyTest extends TestCase
{
    /** 被检查的门禁脚本（相对项目根） */
    private const SCRIPT = 'scripts/check-phpunit-config.php';

    /** PHPUnit 自带的 schema：属性清单与默认值的唯一真相源 */
    private const XSD = 'vendor/phpunit/phpunit/phpunit.xsd';

    /**
     * 必须显式为 true 的安全属性（与脚本里的 REQUIRED_TRUE_ROOT_ATTRIBUTES 逐字一致）
     *
     * 这三个是「问题必须变红」的地基：改它必须同步改本用例，并在提交信息里写清理由。
     *
     * @var array<int, string>
     */
    private const EXPECTED_REQUIRED_TRUE = [
        'failOnEmptyTestSuite',
        'failOnRisky',
        'failOnWarning',
    ];

    /** 项目根目录（tests/Unit/Scripts 往上三层） */
    private string $rootPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootPath = dirname(__DIR__, 3);
    }

    // -----------------------------------------------------------------------
    // 1. 属性集合：XSD 里的 failOn* / beStrictAbout* 必须一个不漏地登记
    // -----------------------------------------------------------------------

    /**
     * 用例 1：XSD 的严格性属性集合与脚本里的表**互为子集**
     *
     * 两个方向都要查：
     *   - XSD 有、表里没有 → PHPUnit 升级新增了安全属性，但没人决定它算不算「测试语义」；
     *   - 表里有、XSD 没有 → 属性名拼错，或 PHPUnit 已删除该属性（表成了化石）。
     */
    public function testSafetyTableCoversEveryStrictnessAttributeInPhpunitSchema(): void
    {
        $table = $this->safetyTable();
        $schema = $this->schemaStrictnessAttributes();

        $missing = array_values(array_diff(array_keys($schema), array_keys($table)));
        $unknown = array_values(array_diff(array_keys($table), array_keys($schema)));

        $this->assertSame(
            [],
            $missing,
            'PHPUnit XSD 里这些安全属性没有登记到 SAFETY_ROOT_ATTRIBUTES：' . implode(', ', $missing)
            . '（新增 failOn* / beStrictAbout* 必须先决定它是否属于「测试语义」，否则变体可以靠它悄悄换口径）'
        );
        $this->assertSame(
            [],
            $unknown,
            'SAFETY_ROOT_ATTRIBUTES 里这些属性在 PHPUnit XSD 中不存在：' . implode(', ', $unknown)
            . '（拼错或 PHPUnit 已移除 —— 无论哪种，检查 G 都在比较一个不存在的属性）'
        );
    }

    // -----------------------------------------------------------------------
    // 2. 默认值：未声明时的折算基准必须与 XSD 一致
    // -----------------------------------------------------------------------

    /**
     * 用例 2：每个属性的默认值与 XSD 的 `xs:attribute default` 逐项一致
     *
     * 这是本用例最核心的一条：检查 G 用「基线生效值」当基准，
     * 若默认值写错，**两边会一起错**，于是「变体漏写」被误判成「与基线一致」。
     */
    public function testSafetyDefaultsMatchPhpunitSchemaDefaults(): void
    {
        $table = $this->safetyTable();
        $schema = $this->schemaStrictnessAttributes();

        foreach ($table as $attribute => $default) {
            if (!array_key_exists($attribute, $schema)) {
                // 属性名本身不存在的问题由用例 1 报，这里不重复
                continue;
            }

            $this->assertSame(
                $schema[$attribute],
                $default,
                "{$attribute} 的默认值必须与 PHPUnit XSD 一致：脚本用它折算「配置里没写该属性」时的生效值，"
                . '写错会让「变体漏写」被判成「与基线一致」（检查 G 会原地保持绿色）'
            );
        }
    }

    // -----------------------------------------------------------------------
    // 3. 三件套：必须为 true 的属性被钉死
    // -----------------------------------------------------------------------

    /**
     * 用例 3：REQUIRED_TRUE_ROOT_ATTRIBUTES 恰好是这三个，且都在安全属性表里
     *
     * 允许悄悄缩小这个集合，等于允许「空跑 / risky / warning 再次变成绿色」。
     */
    public function testRequiredTrueAttributesArePinned(): void
    {
        $code = $this->scriptCodeWithoutComments();

        $matched = preg_match('/const\s+REQUIRED_TRUE_ROOT_ATTRIBUTES\s*=\s*\[(.*?)\];/s', $code, $matches);
        $this->assertSame(
            1,
            $matched,
            self::SCRIPT . ' 必须声明 REQUIRED_TRUE_ROOT_ATTRIBUTES（检查 G 的「必须为 true」清单）'
        );

        preg_match_all("/'([A-Za-z0-9_]+)'\s*=>/", $matches[1], $entries);
        $actual = $entries[1];
        sort($actual);

        $expected = self::EXPECTED_REQUIRED_TRUE;
        sort($expected);

        $this->assertSame(
            $expected,
            $actual,
            '必须为 true 的安全属性就是这三项：改它等于改「什么算失败」的底线，必须同步改本用例'
        );

        $table = $this->safetyTable();
        foreach ($actual as $attribute) {
            $this->assertArrayHasKey($attribute, $table, "{$attribute} 必须在 SAFETY_ROOT_ATTRIBUTES 里，否则报错文案拿不到默认值");
        }
    }

    // -----------------------------------------------------------------------
    // 4. 脚本本身：按真实入口跑一遍，必须绿
    // -----------------------------------------------------------------------

    /**
     * 用例 4：`php scripts/check-phpunit-config.php --strict` 在当前仓库里 exit 0
     *
     * 防的是「门禁脚本自己被改坏」：语法错误、检查 G 误伤现有配置、异常路径写错……
     * 这些在 CI 里要等 phpunit-config-check 跑才知道，本地 `php think test` 顺手就能发现。
     */
    public function testGateScriptPassesOnCurrentRepository(): void
    {
        [$exitCode, $output] = $this->runScript(['--strict']);

        $this->assertSame(
            0,
            $exitCode,
            "php scripts/check-phpunit-config.php --strict 必须 exit 0，实际输出：\n" . $output
        );
        $this->assertMatchesRegularExpression(
            '/检查 G\s+根级安全属性与套件\s+OK/',
            $output,
            '检查 G 必须真的跑过并报 OK（而不是 SKIPPED）'
        );
        $this->assertStringNotContainsString('SAFETY-DRIFT', $output, '当前仓库不应存在安全属性漂移');
        $this->assertStringNotContainsString('BASE-SUITE', $output, '基线必须仍然声明 Legacy 套件');
    }

    // -----------------------------------------------------------------------
    // 内部工具
    // -----------------------------------------------------------------------

    /**
     * 从脚本源码里取出 SAFETY_ROOT_ATTRIBUTES（属性名 => 默认值）
     *
     * 只做文本解析，不 include 脚本：它是 CLI 入口，include 会直接执行并把进程带走。
     *
     * @return array<string, bool>
     */
    private function safetyTable(): array
    {
        $code = $this->scriptCodeWithoutComments();

        $matched = preg_match('/const\s+SAFETY_ROOT_ATTRIBUTES\s*=\s*\[(.*?)\];/s', $code, $matches);
        $this->assertSame(
            1,
            $matched,
            self::SCRIPT . ' 必须声明 SAFETY_ROOT_ATTRIBUTES（检查 G 的属性清单）'
        );

        $table = [];
        preg_match_all("/'([A-Za-z0-9_]+)'\s*=>\s*(true|false)/", $matches[1], $entries, PREG_SET_ORDER);
        foreach ($entries as $entry) {
            $table[$entry[1]] = $entry[2] === 'true';
        }

        $this->assertNotEmpty($table, 'SAFETY_ROOT_ATTRIBUTES 不得为空（空表等于检查 G 什么都不查）');

        return $table;
    }

    /**
     * 读取脚本源码并剥掉注释（token_get_all 保证注释里的示例不会被当成声明）
     */
    private function scriptCodeWithoutComments(): string
    {
        $path = $this->rootPath . '/' . self::SCRIPT;
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertIsString($source);

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    /**
     * 从 PHPUnit 自带的 XSD 里读出全部 failOn* / beStrictAbout* 属性及其默认值
     *
     * XSD 里这些属性都用 `type="xs:boolean"` + `default="true|false"` 声明，
     * Loader::parseBooleanAttribute() 传入的默认值与之一致（已逐项核对）。
     *
     * @return array<string, bool>
     */
    private function schemaStrictnessAttributes(): array
    {
        $path = $this->rootPath . '/' . self::XSD;
        $this->assertFileExists($path, 'PHPUnit 自带的 XSD 必须存在：本用例用它校验属性清单与默认值');

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->load($path, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, 'PHPUnit XSD 解析失败：' . $path);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');

        $attributes = [];
        foreach ($xpath->query('//xs:attribute') as $node) {
            $name = $node->getAttribute('name');
            if (preg_match('/^(failOn|beStrictAbout)/', $name) !== 1) {
                continue;
            }

            $default = $node->getAttribute('default');
            $this->assertContains(
                $default,
                ['true', 'false'],
                "XSD 里 {$name} 的 default 必须是 true/false，实际=" . ($default === '' ? '（空）' : $default)
            );
            $attributes[$name] = $default === 'true';
        }

        $this->assertNotEmpty($attributes, 'XSD 里没有找到任何 failOn* / beStrictAbout* 属性，解析口径可能已经变了');

        return $attributes;
    }

    /**
     * 以子进程执行门禁脚本，返回 [退出码, 合并 stdout/stderr 的输出]
     *
     * 刻意注入 `NO_COLOR=1`（脚本按它禁用 ANSI 转义码），否则断言里必须夹带控制码。
     *
     * @param array<int, string> $args 脚本参数
     * @return array{0: int, 1: string}
     */
    private function runScript(array $args): array
    {
        $command = array_merge([PHP_BINARY, $this->rootPath . '/' . self::SCRIPT], $args);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            // stderr 合并进 stdout：脚本的致命错误与异常路径都走 stderr
            2 => ['redirect', 1],
        ];

        // proc_open 传 env 数组是「整体替换」，必须先把当前进程环境全部拷贝过来
        $childEnv = [];
        foreach (getenv() as $key => $value) {
            if (is_string($value)) {
                $childEnv[(string) $key] = $value;
            }
        }
        $childEnv['NO_COLOR'] = '1';

        $pipes = [];
        $process = proc_open($command, $descriptorSpec, $pipes, $this->rootPath, $childEnv);

        if (!is_resource($process)) {
            $this->fail('无法启动子进程（proc_open 失败）：' . implode(' ', $command));
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}
