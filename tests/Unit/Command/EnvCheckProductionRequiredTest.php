<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use app\AppService;
use app\command\EnvCheckCommand;
use Tests\ThinkPHPTestCase;
use think\console\Input;
use think\console\Output;
use think\facade\Env;

/**
 * EnvCheckCommand 的「生产必需项」校验测试 | production_required checks
 *
 * ## 这条用例钉住什么
 *
 * `env:check` 的价值在于把「配置错了但进程能起来」的故障提前到**部署前**。
 * 这类故障的共同点是：进程正常启动，然后第一个业务请求才炸 ——
 *   · `DEFAULT_TENANT_ID` 缺失/非法 → `TenantIdentify` fail-closed，所有无头请求 500（ER-04）；
 *   · `JWT_SECRET` 仍是公开占位符 → 第一个鉴权请求被拒；
 *   · `DB_USER=root` / `DB_PASS` 仍是 dev 默认口令 → 等于没有口令；
 *   · `APP_DEBUG=true` → 错误页把堆栈、SQL、绝对路径回显给调用方。
 *
 * 因此本类断言 6 件事（与任务验收一一对应）：
 *   1. 生产环境 + `DEFAULT_TENANT_ID` 是占位符 → 失败，输出该键、原因与修复方式；
 *   2. 生产环境 + `DB_USER=root` → 失败并说明理由；
 *   3. 生产环境 + 全部合规 → 通过（无 ✗、无 ⚠）；
 *   4. 非生产环境 + 同样不合规的值 → **只提示不失败**（exit 0）；
 *   5. 口令/密钥**不出现在输出里**（脱敏只暴露前 2 位 + 长度）；
 *   6. 原有 26 个变量的检查结果不变（exit 0 / Errors 0 / 逐个键仍有 ✓ 或 ⚠）。
 *
 * ## 为什么在进程内跑（而不是像 R37 那样拉子进程）
 *
 * 本类要改变的是 `APP_ENV` / `DEFAULT_TENANT_ID` / `DB_USER` 等**取值**。
 * `Tests\Support\CliProcess` 的临时环境文件只接受 `DB_*` 覆写（非 DB_ 的覆写会被丢弃），
 * 而直接改真实 `.env` 又会污染本地配置。进程内 `Env::set()` 是唯一既能精确控制取值、
 * 又不留副作用的方式：`setUp()` 快照、`tearDown()` 逐一还原。
 *
 * 关于 DB_* 的安全边界：用例里会把 `DB_NAME` 换成 `alkaid_prod` 这类**不存在**的库名，
 * 只为让「生产合规」的判定成立。本类全程不触发任何数据库操作，且 tearDown 立刻还原，
 * 因此不会写入任何真实库（含开发库）。
 *
 * @package Tests\Unit\Command
 */
class EnvCheckProductionRequiredTest extends ThinkPHPTestCase
{
    /**
     * 用例执行前各键的原值（键 => 值），tearDown 时逐一还原
     *
     * @var array<string, mixed>
     */
    private array $envBackup = [];

    /**
     * 临时 env 文件路径（仅用于「.env 能否解析」这一段，避免依赖本机真实 .env）
     */
    private string $tempEnvPath = '';

    /**
     * 本类接管的键
     *
     * @var array<int, string>
     */
    private const MANAGED_KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'DEFAULT_TENANT_ID',
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
        'DB_PASS',
        'JWT_SECRET',
        'RATELIMIT_ENABLED',
        'TRUSTED_PROXIES',
        'CORS_ALLOWED_ORIGINS',
    ];

    /**
     * 改动前 `$envDefinitions` 里的 26 个键（回归基线）
     *
     * 用途：证明「新增生产规则」没有把任何一个旧键从输出里弄丢。
     * `DEFAULT_TENANT_ID` 是本次**新增**的第 27 个键，不在下面这张表里。
     *
     * @var array<int, string>
     */
    private const LEGACY_KEYS = [
        'APP_ENV', 'APP_DEBUG', 'DEFAULT_LANG',
        'DB_TYPE', 'DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET', 'DB_PREFIX',
        'JWT_SECRET', 'JWT_ISSUER',
        'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_DB', 'CACHE_DRIVER',
        'CASBIN_ENABLED', 'CASBIN_MODE', 'CASBIN_LOG_ENABLED', 'CASBIN_CACHE_ENABLED',
        'RATELIMIT_ENABLED', 'RATELIMIT_STORE',
        'CORS_ALLOWED_ORIGINS',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::MANAGED_KEYS as $key) {
            $this->envBackup[$key] = env($key);
        }

        // 只放一行注释即可：本类关心的是「解析正常」这条分支，变量取值全部由 Env::set() 提供
        $this->tempEnvPath = sys_get_temp_dir() . '/envcheck-prod-' . getmypid() . '-' . uniqid() . '.env';
        file_put_contents($this->tempEnvPath, "# temp env for EnvCheckProductionRequiredTest\n");
    }

    protected function tearDown(): void
    {
        // 先还原环境变量，再交给父类做容器/请求级清理
        foreach ($this->envBackup as $key => $value) {
            Env::set($key, $value);
        }
        $this->envBackup = [];

        if ($this->tempEnvPath !== '' && is_file($this->tempEnvPath)) {
            @unlink($this->tempEnvPath);
        }

        parent::tearDown();
    }

    /**
     * 用例 1：生产环境 + `DEFAULT_TENANT_ID` 是占位符 → 失败，并给出键、原因、修复方式
     */
    public function testProductionFailsWhenDefaultTenantIdIsPlaceholder(): void
    {
        $this->setEnv(array_merge($this->compliantProductionEnv(), [
            'DEFAULT_TENANT_ID' => 'REPLACE_WITH_REAL_TENANT_ID',
        ]));

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode, "生产必需项不合格时必须以 1 退出，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '✗ DEFAULT_TENANT_ID',
            $output,
            "必须逐条列出不合格的键，实际输出：\n{$output}"
        );
        $this->assertStringContainsString(
            '命中占位标记「REPLACE」',
            $output,
            "必须说明不合格的原因（占位符未替换），实际输出：\n{$output}"
        );
        $this->assertStringContainsString(
            '必须是正整数',
            $output,
            "占位符同时也应报「不是正整数」，实际输出：\n{$output}"
        );
        $this->assertStringContainsString('修复:', $output, "必须给出怎么改，实际输出：\n{$output}");
        $this->assertStringContainsString(
            'TenantIdentify',
            $output,
            "修复提示要说明缺失的真实后果（fail-closed → 500），实际输出：\n{$output}"
        );
        $this->assertStringContainsString(
            'Environment check failed',
            $output,
            "整体结论应为失败，实际输出：\n{$output}"
        );
    }

    /**
     * 用例 2：生产环境 + `DB_USER=root` → 失败并说明理由
     */
    public function testProductionFailsWhenDbUserIsRoot(): void
    {
        $this->setEnv(array_merge($this->compliantProductionEnv(), [
            'DB_USER' => 'root',
        ]));

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode, "生产环境用 root 连库必须失败，实际输出：\n{$output}");
        $this->assertStringContainsString('✗ DB_USER', $output, "必须列出 DB_USER，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '不允许使用 MySQL 超级账号 root',
            $output,
            "必须说明为什么 root 不合格，实际输出：\n{$output}"
        );
    }

    /**
     * 用例 3：生产环境 + 全部合规 → 通过（既无 ✗ 也无 ⚠）
     */
    public function testProductionPassesWithCompliantValues(): void
    {
        $this->setEnv($this->compliantProductionEnv());

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode, "全部合规时应以 0 退出，实际输出：\n{$output}");
        $this->assertStringContainsString(
            'Environment check passed',
            $output,
            "全部合规时应输出通过，实际输出：\n{$output}"
        );
        $this->assertStringContainsString('Errors: 0', $output, "不应有错误，实际输出：\n{$output}");
        $this->assertStringContainsString('Warnings: 0', $output, "不应有告警，实际输出：\n{$output}");
        $this->assertStringNotContainsString('✗', $output, "全部合规时不应出现任何 ✗，实际输出：\n{$output}");
        $this->assertStringNotContainsString('⚠', $output, "全部合规时不应出现任何 ⚠，实际输出：\n{$output}");
    }

    /**
     * 用例 4：非生产环境 + 同样不合规的值 → 只提示不失败（exit 0）
     *
     * 「只提示」由 `production_rules` 的第二个维度控制：`warn` 级规则在非生产环境输出告警，
     * `ignore` 级规则（dev 里 `DB_USER=root` / `APP_DEBUG=true` 这类文档化默认值）完全静默。
     */
    public function testNonProductionOnlyWarnsAndExitsZero(): void
    {
        $this->setEnv(array_merge($this->compliantProductionEnv(), [
            'APP_ENV'           => 'dev',
            'APP_DEBUG'         => 'true',
            'DB_USER'           => 'root',
            'DB_PASS'           => 'root',
            'JWT_SECRET'        => 'short',
            'DEFAULT_TENANT_ID' => 'REPLACE_WITH_REAL_TENANT_ID',
        ]));

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode, "非生产环境不得因为生产必需项不合格而失败，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '⚠ DEFAULT_TENANT_ID',
            $output,
            "占位符属于「哪个环境都错」的问题，非生产环境也应提示，实际输出：\n{$output}"
        );
        $this->assertStringContainsString(
            '仅提示',
            $output,
            "告警文案要说明「非生产环境仅提示」，实际输出：\n{$output}"
        );
        $this->assertStringContainsString(
            'Environment check passed',
            $output,
            "非生产环境整体结论仍是通过，实际输出：\n{$output}"
        );
        // dev 的文档化默认值（root / root、APP_DEBUG=true、短 dev 密钥）不应刷告警
        $this->assertStringNotContainsString('⚠ DB_USER', $output, "dev 的 DB_USER=root 不应告警，实际输出：\n{$output}");
        $this->assertStringNotContainsString('⚠ DB_PASS', $output, "dev 的 DB_PASS=root 不应告警，实际输出：\n{$output}");
        $this->assertStringNotContainsString('⚠ APP_DEBUG', $output, "dev 的 APP_DEBUG=true 不应告警，实际输出：\n{$output}");
    }

    /**
     * 用例 5：口令/密钥绝不出现在输出里（脱敏：前 2 位 + 长度）
     *
     * 为什么这条是硬要求：`env:check` 的输出会原样进 CI 日志。
     * 一旦打印完整口令，等于把生产口令写进所有能读日志的人手里。
     */
    public function testSecretsNeverAppearInCleartext(): void
    {
        $weakPassword = 'root';
        $shortSecret  = 'short-but-supersecret-value';

        $this->setEnv(array_merge($this->compliantProductionEnv(), [
            'DB_PASS'    => $weakPassword,
            'JWT_SECRET' => $shortSecret,
        ]));

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode, "两处不合格都应以失败退出，实际输出：\n{$output}");

        // 断言的是「完整值不出现」，而不是「什么都没打印」——脱敏后的形态必须存在
        $this->assertStringNotContainsString(
            $shortSecret,
            $output,
            "JWT_SECRET 的完整值绝不能出现在输出里，实际输出：\n{$output}"
        );
        $this->assertStringContainsString(
            '"sh"***',
            $output,
            "JWT_SECRET 必须按「前 2 位 + 长度」脱敏，实际输出：\n{$output}"
        );
        $this->assertStringContainsString(
            sprintf('共 %d 字符', strlen($shortSecret)),
            $output,
            "脱敏后必须给出长度以便定位，实际输出：\n{$output}"
        );
        $this->assertStringContainsString(
            '"ro"***',
            $output,
            "DB_PASS 必须按「前 2 位 + 长度」脱敏，实际输出：\n{$output}"
        );
        // 合规基线里的强口令同样不得泄露（它没触发规则，但一旦回归就会被打印）
        $this->assertStringNotContainsString(
            (string) $this->compliantProductionEnv()['DB_PASS'],
            $output,
            "未触发规则的 DB_PASS 也不得出现在输出里，实际输出：\n{$output}"
        );
    }

    /**
     * 用例 6：原有 26 个变量的检查结果不变（回归基线）
     *
     * 期望：26 个键全部仍被检查；`Errors: 0`；dev 下只有 `JWT_SECRET` 从 ✓ 变 ⚠
     * （新增的占位符规则，仅提示不阻断 —— 这是本轮唯一有意改变的非生产输出）。
     */
    public function testLegacyVariablesKeepTheirBehaviour(): void
    {
        // 用「dev 默认配置」这一组确定值，避免断言随本机 .env 漂移
        $this->setEnv([
            'APP_ENV'            => 'dev',
            'APP_DEBUG'          => 'true',
            'DEFAULT_TENANT_ID'  => '1',
            'DB_HOST'            => 'mysql',
            'DB_NAME'            => 'alkaid_sys',
            'DB_USER'            => 'root',
            'DB_PASS'            => 'root',
            'JWT_SECRET'         => 'CHANGE_THIS_IN_PRODUCTION',
            'RATELIMIT_ENABLED'  => 'true',
            'TRUSTED_PROXIES'    => '172.18.0.6',
        ]);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode, "dev 环境仍必须 exit 0，实际输出：\n{$output}");
        $this->assertStringContainsString('Errors: 0', $output, "dev 环境不应有错误，实际输出：\n{$output}");
        // 26 个旧键 + 本轮新增的 DEFAULT_TENANT_ID
        $this->assertStringContainsString('Total variables checked: 27', $output, "变量总数应为 27，实际输出：\n{$output}");

        foreach (self::LEGACY_KEYS as $key) {
            $this->assertStringContainsString($key, $output, "旧键 {$key} 不应从检查里消失，实际输出：\n{$output}");
        }

        // 旧键里唯一变化的一个：占位符 JWT_SECRET 由 ✓ 降级为 ⚠（仅提示）
        $this->assertStringContainsString('⚠ JWT_SECRET', $output, "JWT_SECRET 占位符应告警，实际输出：\n{$output}");
        $this->assertStringNotContainsString('✓ JWT_SECRET', $output, "JWT_SECRET 不应再显示 ✓，实际输出：\n{$output}");

        // dev 的文档化默认值不告警：除 JWT_SECRET 外不应有其它 ⚠
        $this->assertSame(
            1,
            substr_count($output, '⚠'),
            "dev 默认配置下应只有 1 条告警（JWT_SECRET 占位符），实际输出：\n{$output}"
        );
    }

    /**
     * 生产环境的合规基线（各键取值都能通过生产规则）
     *
     * @return array<string, string>
     */
    private function compliantProductionEnv(): array
    {
        return [
            'APP_ENV'           => 'production',
            'APP_DEBUG'         => 'false',
            'DEFAULT_TENANT_ID' => '1',
            'DB_HOST'           => 'db-primary.internal',
            'DB_NAME'           => 'alkaid_prod',
            'DB_USER'           => 'alkaid_app',
            'DB_PASS'           => 'Wq7-stronger-than-any-default-2026',
            // 64 个字符（≥32 字节），且不含任何占位标记
            'JWT_SECRET'        => str_repeat('a1b2c3d4', 8),
            'RATELIMIT_ENABLED' => 'true',
            'TRUSTED_PROXIES'   => '10.0.0.5',
            // 历史字段 production_warning 要求生产环境显式设置（与本轮新增规则无关）
            'CORS_ALLOWED_ORIGINS' => 'https://admin.alkaidsys.com',
        ];
    }

    /**
     * 覆写一组环境变量（`setUp()` 已快照，`tearDown()` 会还原）
     *
     * @param array<string, string> $values
     */
    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            Env::set($key, $value);
        }
    }

    /**
     * 进程内执行 `env:check` 并回收「退出码 + 剥掉格式标签的输出」
     *
     * 不用 `Console::call()`：它把 `Command::run()` 的退出码丢掉了
     * （见 vendor/topthink/framework/src/think/Console.php）。
     *
     * `.env` 路径指向临时文件：本类只关心变量取值，不该依赖本机真实 `.env` 是否存在。
     *
     * @param array<int, string> $parameters 命令行 token（不含命令名本身）
     * @return array{0: int, 1: string} [退出码, 剥掉格式标签后的输出]
     */
    private function runCommand(array $parameters = []): array
    {
        $envFilePath = $this->tempEnvPath;

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

        $output   = new Output('buffer');
        $exitCode = $command->run(new Input($parameters), $output);
        $rendered = (string) $output->fetch();

        return [$exitCode, (string) preg_replace('/<\/?(?:info|error|comment|question)>/u', '', $rendered)];
    }
}
