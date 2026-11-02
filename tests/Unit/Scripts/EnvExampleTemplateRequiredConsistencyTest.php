<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use app\support\EnvRequirementCatalog;
use PHPUnit\Framework\TestCase;

/**
 * 「模板必需键」单一真相源的一致性测试 | Template-required keys have one source of truth
 *
 * ## 这条用例钉住什么
 *
 * `scripts/check-env-example.php` 曾自带一份 `REQUIRED_KEYS`（DB_HOST / DB_NAME / DB_USER /
 * DB_PASS / DB_CHARSET / JWT_SECRET），与 `EnvRequirementCatalog`（生产取值规则）、
 * `EnvCheckCommand`、两份模板共构成**四份清单**。任何一处单独改动都会让
 * 「模板里必须有这个键」与「生产取值必须合格」悄悄错位，而两边各有各的门禁，谁也不会报错。
 *
 * 本轮把第 4 份清单收进 catalog 的 `template_required` 维度。但「收进去了」是一句注释，
 * 只有测试能保证它**不会重新分裂**：
 *
 *   1. **键集合与顺序被钉死**：模板维度的清单属显式决策（多一个键会让合理的模板变红）；
 *   2. **每个生产必需键都必须显式表态**：新增生产键却没想清楚「模板要不要」时立刻红；
 *   3. **两份模板里确实都有这些键**：catalog 说的和仓库里的模板不能各说各话；
 *   4. **脚本里不能再出现硬编码键清单**：注释以外出现任何一个键名即视为「镜像副本复活」；
 *   5. **脚本确实在运行时消费 catalog**（不是「写了个调用但结果没被用」）：
 *      用一个把清单换成 `PROBE_ONLY_ABSENT_KEY` 的替身类**劫持** catalog，
 *      若脚本仍报 `DB_CHARSET` 缺键、或不报 `PROBE_ONLY_ABSENT_KEY`，说明它读的不是 catalog；
 *   6. **脚本在没有 vendor autoload 的干净进程里仍能跑通**：`require_once` 源文件这条路
 *      是「部署第一步（还没 composer install）」的前提，不能因为引入依赖而失效。
 *
 * 为什么用普通 `TestCase`：被测对象是**静态规则表 + 一个纯 PHP 脚本**，
 * 不需要 App、不需要 DB / Redis；起子进程是为了让「没有 vendor autoload」这件事真的成立。
 *
 * @package Tests\Unit\Scripts
 */
final class EnvExampleTemplateRequiredConsistencyTest extends TestCase
{
    /**
     * 模板必需键的**唯一**合法清单（顺序即检查 D 的报错顺序）
     *
     * 与 `.env.example` 的历史 `REQUIRED_KEYS` 完全一致 —— 本轮是**原样搬迁**，不是扩张范围。
     *
     * @var array<int, string>
     */
    private const EXPECTED_TEMPLATE_KEYS = [
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
        'DB_PASS',
        'DB_CHARSET',
        'JWT_SECRET',
    ];

    /**
     * 被检查的脚本（相对项目根）
     */
    private const SCRIPT = 'scripts/check-env-example.php';

    /**
     * 项目根目录（tests/Unit/Scripts 往上三层）
     */
    private string $rootPath;

    /**
     * 本用例创建过的临时文件（tearDown 兜底删除）
     *
     * @var array<int, string>
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootPath = dirname(__DIR__, 3);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // 1-2. catalog 侧：清单被钉死 + 每个键都显式表态
    // -----------------------------------------------------------------------

    /**
     * 用例 1：模板维度恰好是这 6 个键（顺序也钉住）
     */
    public function testTemplateRequiredKeysArePinned(): void
    {
        $this->assertSame(
            self::EXPECTED_TEMPLATE_KEYS,
            EnvRequirementCatalog::templateRequiredKeys(),
            '模板必需键属显式决策：改它必须同步改本用例、两份模板与 '
            . 'docs/technical-specs/deployment/environment-validation.zh-CN.md'
        );
    }

    /**
     * 用例 2：每个生产必需键都必须有**显式**的模板维度表态（true / false 都算）
     *
     * 这条是「防止新增键时漏想」：只往 `REQUIREMENTS` 里加键、不往
     * `TEMPLATE_REQUIREMENTS` 里表态，测试立刻红 —— 而不是等到某次部署踩坑。
     */
    public function testEveryKeyHasAnExplicitTemplateDecision(): void
    {
        $decided = array_keys(EnvRequirementCatalog::templateRequirements());
        $productionKeys = EnvRequirementCatalog::names();

        // 生产维度的 8 个键 + 只在模板维度的 DB_CHARSET = 全部应有的 9 个键
        $expected = array_merge($productionKeys, ['DB_CHARSET']);
        sort($expected);
        sort($decided);

        $this->assertSame(
            $expected,
            $decided,
            '模板维度必须覆盖「8 个生产必需键 + DB_CHARSET」，并逐个写明 true/false：'
            . '漏登记的键意味着「没人想过它在模板里该不该有」'
        );

        foreach (EnvRequirementCatalog::templateRequirements() as $name => $requirement) {
            $this->assertIsBool($requirement['template_required'], "{$name} 的 template_required 必须是布尔值");
            $this->assertNotSame('', trim($requirement['template_note']), "{$name} 必须写明模板维度的理由（template_note）");
        }

        // 反向：DB_CHARSET 只能出现在模板维度，不能混进 production 的取值判定
        $this->assertNotContains(
            'DB_CHARSET',
            $productionKeys,
            'DB_CHARSET 有安全且通用的默认值，不应进入「取值判定」维度（见 catalog 类注释）'
        );
        $this->assertTrue(EnvRequirementCatalog::isTemplateRequired('DB_CHARSET'), 'DB_CHARSET 必须在模板维度');
    }

    // -----------------------------------------------------------------------
    // 3. 模板侧：两份模板里确实都有这些键
    // -----------------------------------------------------------------------

    /**
     * 用例 3：`.env.example` 与 `.env.production.example` 都包含全部模板必需键
     *
     * 只比对「键名是否存在」，不读任何取值 —— 模板里写占位符是**正确**的。
     */
    public function testBothTemplateFilesContainEveryTemplateRequiredKey(): void
    {
        foreach (['.env.example', '.env.production.example'] as $template) {
            $path = $this->rootPath . '/' . $template;
            $this->assertFileExists($path, "{$template} 必须随仓库存在");

            $data = parse_ini_file($path, true, INI_SCANNER_RAW);
            $this->assertIsArray($data, "{$template} 必须能被 parse_ini_file 解析（解析失败会让所有键静默失效）");

            $present = [];
            foreach ($this->flattenKeys($data) as $key) {
                $present[$key] = true;
            }

            foreach (EnvRequirementCatalog::templateRequiredKeys() as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $present,
                    "{$template} 缺少模板必需键 {$key}（清单来自 EnvRequirementCatalog::templateRequiredKeys()）"
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // 4. 脚本侧（静态）：不得再有硬编码键清单
    // -----------------------------------------------------------------------

    /**
     * 用例 4：脚本的**代码**（注释除外）里不得出现任何键名硬编码，且必须调用 catalog
     *
     * 为什么剥掉注释再判断：脚本头部要解释「清单来自哪里」，注释里出现键名是必要的；
     * 但**代码**里出现键名就意味着清单可能又长回来了。
     */
    public function testScriptHasNoHardCodedKeyList(): void
    {
        $source = (string) file_get_contents($this->rootPath . '/' . self::SCRIPT);
        $code = $this->stripComments($source);

        $this->assertStringContainsString(
            'EnvRequirementCatalog::templateRequiredKeys()',
            $code,
            '检查 D 必须调用 EnvRequirementCatalog::templateRequiredKeys()'
        );

        $this->assertStringNotContainsString(
            'REQUIRED_KEYS',
            $code,
            '脚本里不得再出现 REQUIRED_KEYS 常量（它就是被收敛掉的那份镜像清单）'
        );

        foreach (self::EXPECTED_TEMPLATE_KEYS as $key) {
            $this->assertStringNotContainsString(
                $key,
                $code,
                "脚本代码里不得硬编码键名 {$key}（键清单只能来自 catalog；写在注释里可以，写在代码里不行）"
            );
        }

        // fail-closed：类文件缺失时必须立刻失败，而不是「跳过检查 D 却 exit 0」
        $this->assertStringContainsString('is_file($catalogPath)', $code, '脚本必须有「类文件存在性」的 fail-closed 判断');
        $this->assertMatchesRegularExpression(
            '/致命错误:\s*规则目录/',
            $code,
            '脚本无法加载规则目录时必须打印致命错误（不能静默降级）'
        );
    }

    // -----------------------------------------------------------------------
    // 5-6. 脚本侧（运行时）：真的消费 catalog，且不依赖 vendor autoload
    // -----------------------------------------------------------------------

    /**
     * 用例 5：把 catalog 换成替身，脚本必须跟着变（证明运行时确实在消费它）
     *
     * 做法：`php -d auto_prepend_file=<shim>` —— shim 先声明 `app\support\EnvRequirementCatalog`，
     * 于是脚本里的 `class_exists()` 为真、`require_once` 被跳过，检查 D 拿到的是替身清单
     * （`DB_HOST` + `PROBE_ONLY_ABSENT_KEY`）。预期：只报 `PROBE_ONLY_ABSENT_KEY` 缺键，
     * 且**不再**提 `DB_CHARSET`（真清单里才有它）。
     */
    public function testScriptReportsMissingKeyFromCatalogAtRuntime(): void
    {
        $shim = $this->makeCatalogShim(<<<'PHP'
            namespace app\support;

            final class EnvRequirementCatalog
            {
                public static function templateRequiredKeys(): array
                {
                    // 替身清单必须凑到「下限」以上（见 scripts/check-env-example.php 的
                    // TEMPLATE_REQUIRED_MIN_KEYS），否则会被 [CATALOG-SHRUNK] 拦在检查 D 之前，
                    // 这条用例就测不到「脚本是否真的在消费 catalog」了。
                    // 组成：5 个真实存在的键 + 1 个模板里不存在的键（PROBE_ONLY_ABSENT_KEY）。
                    // 刻意**不含 DB_CHARSET**，以保留「脚本不再提它」那条断言。
                    return ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'JWT_SECRET', 'PROBE_ONLY_ABSENT_KEY'];
                }
            }
            PHP);

        [$exitCode, $output] = $this->runScript([], [], ['-d', 'auto_prepend_file=' . $shim]);

        $this->assertStringContainsString(
            '[MISSING-KEY] 缺少必需键 PROBE_ONLY_ABSENT_KEY',
            $output,
            "脚本必须按 catalog 返回的清单报缺键（说明它确实在运行时消费该类）：\n{$output}"
        );
        $this->assertStringNotContainsString(
            'DB_CHARSET',
            $output,
            "替身清单里没有 DB_CHARSET，脚本就不该再检查它 —— 出现即说明仍在用脚本自带的列表：\n{$output}"
        );
        $this->assertSame(1, $exitCode, "缺键必须让脚本以 1 退出，实际输出：\n{$output}");
    }

    /**
     * 用例 6：干净子进程（无 vendor autoload）里仍然通过
     *
     * 这条同时守住「引入 catalog 依赖后不能要求 composer install」这个前提：
     * 部署流水线的第一步就是本脚本，那时 vendor/ 可能还不存在。
     */
    public function testScriptPassesInCleanProcessWithoutAutoload(): void
    {
        [$exitCode, $output] = $this->runScript(['--strict']);

        $this->assertSame(0, $exitCode, "本仓库的模板应当通过 --strict 门禁，实际输出：\n{$output}");
        $this->assertStringContainsString('检查 D  必需键存在              OK', $output, "检查 D 应通过：\n{$output}");
        $this->assertStringNotContainsString('致命错误', $output, "不应触发规则目录加载失败：\n{$output}");
    }

    // -----------------------------------------------------------------------
    // 辅助
    // -----------------------------------------------------------------------

    /**
     * 递归展开 INI 分段，收集叶子键名
     *
     * @param array<mixed> $data
     * @return array<int, string>
     */
    private function flattenKeys(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                foreach ($this->flattenKeys($value) as $item) {
                    $keys[] = $item;
                }
                continue;
            }
            $keys[] = (string) $key;
        }

        return $keys;
    }

    /**
     * 剥掉 PHP 注释（行注释与块注释），只留可执行代码
     *
     * 用 `token_get_all()` 而不是正则：注释里出现的引号与注释结束符会让正则误判。
     */
    private function stripComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
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
     * 写一个「替身 catalog」到临时文件（命名空间与真实类相同）
     *
     * @param string $classBody `namespace` 之后的类定义（不含 `<?php`）
     */
    private function makeCatalogShim(string $classBody): string
    {
        $path = sys_get_temp_dir() . '/env-example-catalog-shim-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\n" . $classBody . "\n");
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * 以子进程执行被检查的脚本，返回 [退出码, 合并 stdout/stderr 的输出]
     *
     * 刻意注入 `NO_COLOR=1`（脚本按它禁用 ANSI 转义码），否则断言里必须夹带控制码，
     * 测试会因为「颜色实现变了」而红，而不是因为行为变了。
     *
     * @param array<int, string>    $args         脚本参数
     * @param array<string, string> $extraEnv     额外环境变量
     * @param array<int, string>    $phpOptions   PHP 选项（例如 `-d auto_prepend_file=...`）
     * @return array{0: int, 1: string}
     */
    private function runScript(array $args, array $extraEnv = [], array $phpOptions = []): array
    {
        $command = array_merge([PHP_BINARY], $phpOptions, [$this->rootPath . '/' . self::SCRIPT], $args);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            // stderr 合并进 stdout：脚本的致命错误与框架异常都走 stderr
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
        foreach ($extraEnv as $key => $value) {
            $childEnv[$key] = $value;
        }

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
