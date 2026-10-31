<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use app\AppService;
use app\command\EnvCheckCommand;
use Tests\Support\CliProcess;
use Tests\ThinkPHPTestCase;
use think\console\Input;
use think\console\Output;

/**
 * EnvCheckCommand 测试（R37：`.env` 解析失败不再静默兜底）
 *
 * 背景：`think\Env::load()` 的实现是 `parse_ini_file($file, true, INI_SCANNER_RAW) ?: []`，
 * `.env` 一旦有语法错误（典型：注释行里出现半角括号），拿到的是空数组 —— 所有键静默失效。
 * 症状是「容器正常起来了，但连不上库 / 配置像完全没生效」，极难定位。
 *
 * 本用例钉住三件事：
 *   1. `env:check` 会先检查 `.env` 自身能否解析，解析失败时给出「行号 + 出错行原文」并以 1 退出；
 *   2. 解析失败时**不再**做逐变量检查（否则会因为所有键都读不到而刷出一堆误导性的「缺失」）；
 *   3. `.env` 不存在时必须放行（容器可以只用环境变量注入配置），不能被当成错误。
 *
 * 关于真实 `.env`：涉及「仓库根 .env」的用例会在 finally 里逐字节还原，并断言 sha256 与基线一致；
 * 另有一个 shutdown 兜底回调，防止断言失败把 `.env` 留在损坏状态。
 * 纯解析逻辑的用例一律用临时文件，不碰真实 `.env`。
 *
 * @package Tests\Unit\Command
 */
class EnvCheckCommandTest extends ThinkPHPTestCase
{
    /**
     * 项目根目录（tests/Unit/Command 往上三层）
     */
    private string $rootPath;

    /**
     * 真实 `.env` 路径
     */
    private string $envPath;

    /**
     * 用例集开始时的 `.env` 原文（用于任何异常路径下兜底还原；null 表示本来就没有 .env）
     */
    private static ?string $envBaseline = null;

    /**
     * 兜底还原回调是否已注册
     */
    private static bool $restoreGuardRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootPath = dirname(__DIR__, 3);
        $this->envPath  = $this->rootPath . '/.env';

        if (self::$envBaseline === null && is_file($this->envPath)) {
            self::$envBaseline = (string) file_get_contents($this->envPath);
        }

        // 兜底：若用例在「临时替换/改名 .env」期间异常终止，进程结束前把 .env 写回基线。
        // 注意：覆盖不到 SIGKILL 这类硬杀进程，那种情况只能靠人工还原（报告中已说明）。
        if (!self::$restoreGuardRegistered) {
            self::$restoreGuardRegistered = true;
            register_shutdown_function(static function (): void {
                if (self::$envBaseline === null) {
                    return;
                }
                $path = dirname(__DIR__, 3) . '/.env';
                if (!is_file($path) || file_get_contents($path) !== self::$envBaseline) {
                    file_put_contents($path, self::$envBaseline);
                }
            });
        }
    }

    /**
     * 正常 `.env`：真实 CLI 下 `env:check` 退出码 0，且不改动 .env
     */
    public function testNormalEnvFilePassesEndToEnd(): void
    {
        if (!is_file($this->envPath)) {
            $this->markTestSkipped('仓库根目录没有 .env（干净检出）：跳过端到端用例，进程内用例已覆盖解析逻辑');
        }

        $hashBefore = hash_file('sha256', $this->envPath);

        [$exitCode, $output] = $this->runCli(['env:check']);

        $this->assertSame(0, $exitCode, "正常 .env 下 env:check 应以 0 退出，实际输出：\n{$output}");
        $this->assertStringContainsString('✓ .env parsed', $output, "应输出 .env 解析通过，实际输出：\n{$output}");
        $this->assertStringContainsString(
            'Environment check passed',
            $output,
            "正常 .env 下应整体通过，实际输出：\n{$output}"
        );
        $this->assertSame(
            $hashBefore,
            hash_file('sha256', $this->envPath),
            '本用例不得改动真实 .env'
        );
    }

    /**
     * 注入半角括号：真实 CLI 下 `env:check` 以 1 退出，并给出行号与出错行原文；用完立即还原
     *
     * 注：`.env` 是本地真实配置，注入内容只在用例执行期间存在，
     * finally 里逐字节还原，并用 sha256 + 全文比对双重证明。
     */
    public function testBrokenEnvFileFailsWithLineNumberAndIsRestored(): void
    {
        if (!is_file($this->envPath)) {
            $this->markTestSkipped('仓库根目录没有 .env（干净检出）：跳过端到端用例，进程内用例已覆盖解析逻辑');
        }

        $original     = (string) file_get_contents($this->envPath);
        $baselineHash = hash('sha256', $original);
        // 这一行会被 parse_ini_file 判为语法错误（注释里的半角括号）
        $badLine = '# EnvCheckInjected (v2)';

        try {
            file_put_contents($this->envPath, $original . "\n" . $badLine . "\n");
            clearstatcache(true, $this->envPath);

            [$exitCode, $output] = $this->runCli(['env:check']);

            $this->assertSame(1, $exitCode, "解析失败时 env:check 应以 1 退出，实际输出：\n{$output}");
            $this->assertStringContainsString('✗ .env', $output, "应报 .env 解析失败，实际输出：\n{$output}");
            $this->assertMatchesRegularExpression(
                '/行号: \d+/u',
                $output,
                "错误信息必须带行号，实际输出：\n{$output}"
            );
            $this->assertStringContainsString(
                $badLine,
                $output,
                "错误信息必须带出错行原文，实际输出：\n{$output}"
            );
            $this->assertStringContainsString(
                '已跳过逐变量检查',
                $output,
                "解析失败后应跳过变量检查（避免一堆误导性的「缺失」），实际输出：\n{$output}"
            );
            $this->assertStringContainsString(
                'Total variables checked: 0',
                $output,
                "解析失败后实际检查的变量数应为 0，实际输出：\n{$output}"
            );
        } finally {
            file_put_contents($this->envPath, $original);
            clearstatcache(true, $this->envPath);
        }

        // 还原证明
        $this->assertSame($baselineHash, hash_file('sha256', $this->envPath), '.env 必须被还原（sha256 一致）');
        $this->assertSame($original, file_get_contents($this->envPath), '.env 必须逐字节还原');
    }

    /**
     * `.env` 不存在：不得被当成错误（容器可以只用环境变量注入配置）
     *
     * 这里的验证方式是「临时改名」而不是构造临时路径，因为要覆盖的是**真实启动路径**：
     * 运行期守卫与命令都从项目根目录取 .env，只有真的没有这个文件才算验证到位。
     */
    public function testMissingEnvFileIsSkippedNotAnError(): void
    {
        if (!is_file($this->envPath)) {
            $this->markTestSkipped('仓库根目录本就没有 .env：已在「无 .env」状态下，本用例的前提不成立');
        }

        $baselineHash = hash_file('sha256', $this->envPath);
        $backupPath   = $this->envPath . '.envcheck-bak-' . getmypid();

        try {
            rename($this->envPath, $backupPath);
            clearstatcache(true, $this->envPath);

            // 1) 命令：跳过文件解析检查，而不是报错
            // 注意：不在这里断言退出码 —— 没有 .env 时逐变量检查会因为 DB_NAME 等变量缺失而
            // 以 1 退出，那是既有行为，与本用例要钉的「文件不存在不是解析错误」无关。
            [$envCheckExit, $output] = $this->runCli(['env:check']);
            $this->assertStringContainsString(
                '未找到 .env，跳过文件解析检查',
                $output,
                "文件不存在应给出跳过提示，实际输出：\n{$output}"
            );
            $this->assertStringNotContainsString(
                '✗ .env',
                $output,
                "文件不存在不得被当成解析错误，实际输出：\n{$output}"
            );
            $this->assertStringContainsString(
                '== Summary ==',
                $output,
                "文件不存在时应继续做变量检查，实际输出：\n{$output}"
            );

            // 2) 运行期守卫：必须放行（否则任何只用环境变量的容器都起不来）
            [$listExit, $listOutput] = $this->runCli(['list']);
            $this->assertSame(0, $listExit, "没有 .env 时运行期守卫必须放行，实际输出：\n{$listOutput}");
            $this->assertStringNotContainsString(
                '.env 文件存在但无法解析',
                $listOutput,
                "没有 .env 时守卫不得抛错，实际输出：\n{$listOutput}"
            );

            $this->assertContains(
                $envCheckExit,
                [0, 1],
                "没有 .env 时命令允许因变量缺失以 1 退出，但不应出现 255 之类的异常退出，实际输出：\n{$output}"
            );
        } finally {
            if (is_file($backupPath)) {
                rename($backupPath, $this->envPath);
                clearstatcache(true, $this->envPath);
            }
        }

        $this->assertSame($baselineHash, hash_file('sha256', $this->envPath), '.env 必须被还原（sha256 一致）');
    }

    /**
     * 解析失败时命令必须跳过变量检查（进程内直接调用，指向临时文件）
     */
    public function testCommandReportsParseErrorAndSkipsVariableChecks(): void
    {
        $tempPath = $this->makeTempEnvFile("APP_ENV = dev\n# 注释里带括号 (v2)\nDB_HOST = mysql\n");

        try {
            $command = $this->makeCommandPointingTo($tempPath);
            [$exitCode, $output] = $this->runCommand($command);

            $this->assertSame(1, $exitCode, "解析失败时命令应以 1 退出，实际输出：\n{$output}");
            $this->assertStringContainsString('✗ .env', $output, "应报 .env 解析失败，实际输出：\n{$output}");
            $this->assertStringContainsString('行号: 2', $output, "应给出出错行号，实际输出：\n{$output}");
            $this->assertStringContainsString(
                '# 注释里带括号 (v2)',
                $output,
                "应给出出错行原文，实际输出：\n{$output}"
            );
            $this->assertStringContainsString(
                'Total variables checked: 0',
                $output,
                "解析失败时应跳过变量检查，实际输出：\n{$output}"
            );
            $this->assertStringNotContainsString(
                'Required but not set',
                $output,
                "解析失败时不得再刷变量级「必需但未设置」（会掩盖真正原因），实际输出：\n{$output}"
            );
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * `.env` 不存在时命令只是跳过文件解析检查，并继续做变量检查
     */
    public function testCommandSkipsFileCheckWhenEnvFileMissing(): void
    {
        $tempPath = $this->rootPath . '/.env.not-exists-' . getmypid();

        $command = $this->makeCommandPointingTo($tempPath);
        [, $output] = $this->runCommand($command);

        $this->assertStringContainsString(
            '未找到 .env，跳过文件解析检查',
            $output,
            "文件不存在应给出跳过提示，实际输出：\n{$output}"
        );
        $this->assertStringNotContainsString('✗ .env', $output, "文件不存在不得被当成解析错误，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '== Summary ==',
            $output,
            "文件不存在时应继续做变量检查，实际输出：\n{$output}"
        );
    }

    /**
     * 共享诊断逻辑：能取到行号、出错行原文与解析错误原文
     */
    public function testInspectEnvFileReportsLineNumberAndRawLine(): void
    {
        $tempPath = $this->makeTempEnvFile("APP_ENV = dev\n# 注释里带括号 (v2)\nDB_HOST = mysql\n");

        try {
            $report = AppService::inspectEnvFile($tempPath);

            $this->assertTrue($report['exists'], '临时文件存在，exists 应为 true');
            $this->assertFalse($report['parsed'], '含半角括号的注释会让 parse_ini_file 语法错误');
            $this->assertSame(2, $report['line'], '应解析出出错行号');
            $this->assertSame('# 注释里带括号 (v2)', $report['raw'], '应回读出错行原文');
            $this->assertStringContainsString('syntax error', (string) $report['error'], '应保留原始错误消息');
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * 共享诊断逻辑：文件不存在时 exists=false 且视为「无需检查」（调用方据此放行）
     */
    public function testInspectEnvFileTreatsMissingFileAsSkipped(): void
    {
        $report = AppService::inspectEnvFile($this->rootPath . '/.env.not-exists-' . getmypid());

        $this->assertFalse($report['exists'], '文件不存在时 exists 应为 false');
        $this->assertTrue($report['parsed'], '文件不存在应视为「无需检查」，不是解析失败');
        $this->assertNull($report['line']);
        $this->assertNull($report['raw']);
    }

    /**
     * 共享诊断逻辑：空文件是合法的 ini（空数组），不是语法错误
     */
    public function testInspectEnvFileAcceptsEmptyFile(): void
    {
        $tempPath = $this->makeTempEnvFile('');

        try {
            $report = AppService::inspectEnvFile($tempPath);

            $this->assertTrue($report['exists']);
            $this->assertTrue($report['parsed'], '空文件 parse_ini_file 返回空数组，属于解析成功');
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * 运行期守卫的异常消息必须可直接定位问题（行号 + 出错行原文 + 修复提示）
     */
    public function testRuntimeGuardMessageIsActionable(): void
    {
        $tempPath = $this->makeTempEnvFile("APP_ENV = dev\n# 注释里带括号 (v2)\n");

        try {
            $report = AppService::inspectEnvFile($tempPath);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/出错行号：2/u');
            $this->expectExceptionMessageMatches('/# 注释里带括号 \(v2\)/u');
            $this->expectExceptionMessageMatches('/全角括号/u');
            $this->expectExceptionMessageMatches('/AGENTS\.md Configuration & Security/u');

            AppService::throwEnvFileParseError($report);
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * 生成项目外的临时 ini 文件
     *
     * @param string $content 文件内容
     * @return string 临时文件绝对路径
     */
    private function makeTempEnvFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/envcheck-' . getmypid() . '-' . uniqid() . '.env';
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * 构造一个把 `.env` 路径指向临时文件的命令替身
     *
     * 目的：绝大多数用例不该去动仓库根的真实 `.env`，只有端到端用例才需要。
     *
     * @param string $envFilePath 供诊断使用的 ini 文件路径
     * @return EnvCheckCommand
     */
    private function makeCommandPointingTo(string $envFilePath): EnvCheckCommand
    {
        $command = new class ($envFilePath) extends EnvCheckCommand {
            public function __construct(private string $envFilePath)
            {
                parent::__construct();
            }

            /**
             * 覆盖真实路径（父类实现用的是 $this->app->getRootPath() . '.env'）
             *
             * @return array{path: string, exists: bool, parsed: bool, line: int|null, raw: string|null, error: string|null}
             */
            protected function inspectEnvFile(): array
            {
                return AppService::inspectEnvFile($this->envFilePath);
            }
        };

        $command->setApp(self::$app);

        return $command;
    }

    /**
     * 进程内执行命令并回收「退出码 + 缓冲输出」
     *
     * 不用 `Console::call()`：它把 `Command::run()` 的退出码丢掉了
     * （vendor/topthink/framework/src/think/Console.php 里 call() 只 return $output）。
     *
     * 输出会先剥掉 `<info>` / `<error>` 这类控制台格式标签：
     * buffer 驱动的 `fetch()` 返回的是未渲染的原文，而真实终端会把标签转成颜色后消失，
     * 断言必须对着「用户实际看到的文字」而不是标签。
     *
     * @param EnvCheckCommand      $command    待执行命令
     * @param array<int, string>   $parameters 命令行 token（不含命令名本身）
     * @return array{0: int, 1: string} [退出码, 剥掉格式标签后的输出]
     */
    private function runCommand(EnvCheckCommand $command, array $parameters = []): array
    {
        $output   = new Output('buffer');
        $exitCode = $command->run(new Input($parameters), $output);
        $rendered = (string) $output->fetch();

        return [$exitCode, (string) preg_replace('/<\/?(?:info|error|comment|question)>/u', '', $rendered)];
    }

    /**
     * 以子进程方式执行 `php think <args>`，返回 [退出码, 输出]
     *
     * 为什么端到端用例要走子进程：这条链路关键在于「真实启动一次应用」
     * （App::initialize → Env::load('.env') → AppService::boot 守卫 → 命令执行），
     * 进程内跑会被 PHPUnit 进程里已经初始化过的 Env / 静态缓存遮蔽。
     *
     * 子进程统一走 Tests\Support\CliProcess：该助手会生成临时环境文件把子进程隔离到测试库。
     * 这里的断言对象仍是「仓库根 .env」的解析结论（env:check 直接读该文件），
     * 隔离只影响变量取值，不影响被测行为。
     *
     * @param array<int, string> $args think 之后的参数（如 `['env:check']`）
     * @return array{0: int, 1: string} [退出码, 合并 stderr 后的输出]
     */
    private function runCli(array $args): array
    {
        $result = CliProcess::think($args);

        return [$result->exitCode, $result->output];
    }
}
