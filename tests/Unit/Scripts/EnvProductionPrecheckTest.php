<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * `scripts/check-env-production.php` 的行为测试 | Production precheck script
 *
 * ## 这条用例钉住什么
 *
 * `env:check` 有一个结构性死角：它必须先启动整个 App，而生产类环境下
 * `config/cache.php` 强制 `cache.default = redis`、`app\provider\RedisHealthCheckService`
 * 在启动期做读写自检并 fail-closed。于是**最需要预检的时刻**（生产配置还没填对、
 * 依赖可能也不通）恰恰跑不了预检 —— 你只会看到 "Redis health check failed"。
 *
 * 本类断言 11 件事（与任务验收一一对应）：
 *   1. **Redis 完全不可达时脚本仍能跑**（真实进程），并附上 `env:check` 在同配置下起不来的对照；
 *   2. 合格的生产 env 文件 → exit 0；
 *   3. 占位符 `DEFAULT_TENANT_ID` → exit 1 且点名叫它；
 *   4. `DB_USER=root` → exit 1；
 *   5. `JWT_SECRET` 是占位符 → exit 1；
 *   6. `APP_DEBUG=true` → exit 1；
 *   7. 注释里的半角括号 / 竖线 → 解析失败 → exit 1（不得静默通过）；
 *   8. 文件不存在 → exit 1；
 *   9. 输出不含完整口令 / 密钥（脱敏：前 2 位 + 长度）；
 *  10. `--env-file=.env.production.example` 在当前仓库上应报错（模板本来就是占位符，预期失败）；
 *  11. `--strict` 把「条件必需项」的告警升格为失败（非 strict 时只告警、exit 0）。
 *
 * ## 为什么每条都拉真实子进程
 *
 * 被测对象的价值就是「不启动 App 也能跑」。如果在进程内 require 脚本，那么
 * 「不启动 App」这件事由 phpunit 的引导文件替它保证了 —— 断言就变成了自证。
 * 只有用 `php scripts/check-env-production.php` 起一个**干净的 PHP 进程**
 * （没有 vendor autoload、没有 App、没有容器），才是真的证明。
 *
 * ## 临时文件
 *
 * 全部落在 `sys_get_temp_dir()`（测试进程在容器内，所以是容器 /tmp），`tearDown()` 逐个删除。
 * 唯一落在项目根的是「对照用例」需要的那份 `.env.<name>`（`App::loadEnv()` 拼的是
 * `rootPath . '.env.' . $envName`，只能放项目根），它同样在 finally 里删除，
 * 且被 `.gitignore` 的 `.env.*` 覆盖。
 *
 * @package Tests\Unit\Scripts
 */
class EnvProductionPrecheckTest extends TestCase
{
    /**
     * 脚本相对路径（相对项目根）
     */
    private const SCRIPT = 'scripts/check-env-production.php';

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
    // 1. 核心：Redis 不可达时脚本仍能跑（并与 env:check 对照）
    // -----------------------------------------------------------------------

    /**
     * Redis 完全不可达（指向一个已关闭的本地端口）时，本脚本必须照常给出结论
     *
     * 这份 env 文件与下面 `testEnvCheckCannotStartWhenRedisIsUnreachable()` 用的是同一组取值，
     * 差别只在于：那个用例证明「App 起不来」，这个用例证明「预检照样能跑」。
     */
    public function testScriptRunsWhenRedisIsUnreachable(): void
    {
        $port = $this->closedLocalPort();
        $file = $this->makeEnvFile($this->compliantEnv([
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => (string) $port,
        ]));

        [$exitCode, $output] = $this->runScript(['--env-file=' . $file]);

        $this->assertSame(
            0,
            $exitCode,
            "Redis 不可达不得影响本脚本（它不启动 App）：\n{$output}"
        );
        $this->assertStringContainsString('✓ 全部检查通过', $output, "应给出通过结论，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '不依赖 Redis、不依赖 DB',
            $output,
            "输出里必须明确写出「不依赖 Redis / DB」，否则读者无法判断这条结论的适用范围：\n{$output}"
        );
        // 输入里确实带了不可达的 Redis 端口（否则本用例什么都没证明）
        $this->assertStringContainsString(
            '检查 A  文件存在且可解析',
            $output,
            "应完成文件解析，实际输出：\n{$output}"
        );
    }

    /**
     * 同一份取值交给 `php think env:check`：启动期就死在 Redis 自检，命令体根本执行不到
     *
     * 这是「为什么需要本脚本」的证据，不是本脚本自身的功能。
     * 断言刻意不写死异常文案（那是框架侧的实现细节），而是钉住两个稳定事实：
     *   a. 输出里提到 Redis（失败发生在 Redis 自检，而不是别的阶段）；
     *   b. **命令体的输出一个都没出现**（没有 Summary、没有通过结论）—— 也就是「执行不到」。
     *
     * 刻意**不**断言退出码：实测（PHP 8.2 + ThinkPHP 8.0）这一路径下
     * `php think env:check` 打完 `[RuntimeException] Redis health check failed ...`
     * 之后**仍然以 0 退出**（框架只渲染异常，不设置退出码；AppService 只为「.env 解析失败」
     * 那条路径注册了 `exit(1)` 的 shutdown 钩子）。把 0 写进断言等于把一个已知缺陷钉死，
     * 修好它时反而要改测试；这里只钉「命令体没执行」这个与本脚本相关的事实。
     */
    public function testEnvCheckCannotStartWhenRedisIsUnreachable(): void
    {
        $port = $this->closedLocalPort();
        $envName = 'prodcheck_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $envPath = $this->rootPath . '/.env.' . $envName;

        $this->tempFiles[] = $envPath;
        file_put_contents($envPath, $this->compliantEnv([
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => (string) $port,
            // 子进程会连库（启动期只做懒连接，但为了绝对安全仍指向测试库）：
            // 库名必须含 _test，与 tests/Support/CliProcess 的 fail-closed 口径一致
            'DB_NAME'    => 'alkaid_sys_test',
        ]));

        [, $output] = $this->runPhp([$this->rootPath . '/think', 'env:check'], ['PHP_ENV_NAME' => $envName]);

        $this->assertMatchesRegularExpression(
            '/redis/i',
            $output,
            "对照用例的前提是「失败发生在 Redis 自检」，实际输出：\n{$output}"
        );
        $this->assertStringNotContainsString(
            '== Summary ==',
            $output,
            "env:check 的命令体不应执行到（这正是它跑不了预检的证据），实际输出：\n{$output}"
        );
        $this->assertStringNotContainsString(
            'Environment check passed',
            $output,
            "env:check 不应给出通过结论，实际输出：\n{$output}"
        );
    }

    // -----------------------------------------------------------------------
    // 2. 合格的生产 env 文件
    // -----------------------------------------------------------------------

    public function testCompliantProductionFilePasses(): void
    {
        $file = $this->makeEnvFile($this->compliantEnv());

        [$exitCode, $output] = $this->runScript(['--env-file=' . $file]);

        $this->assertSame(0, $exitCode, "合格配置应以 0 退出，实际输出：\n{$output}");
        $this->assertStringContainsString('✓ 全部检查通过', $output, "应输出通过，实际输出：\n{$output}");
        $this->assertStringContainsString('解析结果: ', $output, "应报告解析出的键数，实际输出：\n{$output}");
        $this->assertStringContainsString(
            'APP_ENV, APP_DEBUG, DEFAULT_TENANT_ID, DB_HOST, DB_NAME, DB_USER, DB_PASS, JWT_SECRET',
            $output,
            "应列出本次真正判定过的键（覆盖范围可见），实际输出：\n{$output}"
        );
    }

    // -----------------------------------------------------------------------
    // 3-6. 逐条不合格项
    // -----------------------------------------------------------------------

    /**
     * 用例 3：`DEFAULT_TENANT_ID` 是占位符 → exit 1 且点名
     */
    public function testPlaceholderDefaultTenantIdFails(): void
    {
        $file = $this->makeEnvFile($this->compliantEnv([
            'DEFAULT_TENANT_ID' => 'REPLACE_WITH_REAL_TENANT_ID',
        ]));

        [$exitCode, $output] = $this->runScript(['--env-file=' . $file]);

        $this->assertSame(1, $exitCode, "占位符租户 ID 必须以 1 退出，实际输出：\n{$output}");
        $this->assertStringContainsString('[REQ] DEFAULT_TENANT_ID', $output, "必须点名叫它，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '命中占位标记「REPLACE」',
            $output,
            "必须说明原因（占位符未替换），实际输出：\n{$output}"
        );
        $this->assertStringContainsString(
            '必须是正整数',
            $output,
            "占位符同时应报「不是正整数」，实际输出：\n{$output}"
        );
        $this->assertStringContainsString('修复:', $output, "必须给出怎么改，实际输出：\n{$output}");
        $this->assertStringContainsString(
            'TenantIdentify',
            $output,
            "修复提示要说明真实后果（fail-closed → 500），实际输出：\n{$output}"
        );
    }

    /**
     * 用例 4：`DB_USER=root` → exit 1
     */
    public function testDbUserRootFails(): void
    {
        $file = $this->makeEnvFile($this->compliantEnv(['DB_USER' => 'root']));

        [$exitCode, $output] = $this->runScript(['--env-file=' . $file]);

        $this->assertSame(1, $exitCode, "生产环境用 root 连库必须失败，实际输出：\n{$output}");
        $this->assertStringContainsString('[REQ] DB_USER', $output, "必须列出 DB_USER，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '不允许使用 MySQL 超级账号 root',
            $output,
            "必须说明为什么 root 不合格，实际输出：\n{$output}"
        );
    }

    /**
     * 用例 5：`JWT_SECRET` 是占位符 → exit 1
     */
    public function testJwtSecretPlaceholderFails(): void
    {
        $file = $this->makeEnvFile($this->compliantEnv([
            'JWT_SECRET' => 'CHANGE_THIS_IN_PRODUCTION',
        ]));

        [$exitCode, $output] = $this->runScript(['--env-file=' . $file]);

        $this->assertSame(1, $exitCode, "占位密钥必须失败，实际输出：\n{$output}");
        $this->assertStringContainsString('[REQ] JWT_SECRET', $output, "必须列出 JWT_SECRET，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '命中占位标记「CHANGE_THIS」',
            $output,
            "必须说明命中的占位标记，实际输出：\n{$output}"
        );
        // 短密钥同样不合格（min_length:32）
        $shortFile = $this->makeEnvFile($this->compliantEnv(['JWT_SECRET' => 'too-short-secret']));
        [$shortExit, $shortOutput] = $this->runScript(['--env-file=' . $shortFile]);
        $this->assertSame(1, $shortExit, "短密钥必须失败，实际输出：\n{$shortOutput}");
        $this->assertStringContainsString('长度不足', $shortOutput, "应报长度不足，实际输出：\n{$shortOutput}");
    }

    /**
     * 用例 6：`APP_DEBUG=true` → exit 1
     */
    public function testAppDebugTrueFails(): void
    {
        $file = $this->makeEnvFile($this->compliantEnv(['APP_DEBUG' => 'true']));

        [$exitCode, $output] = $this->runScript(['--env-file=' . $file]);

        $this->assertSame(1, $exitCode, "APP_DEBUG=true 必须失败，实际输出：\n{$output}");
        $this->assertStringContainsString('[REQ] APP_DEBUG', $output, "必须列出 APP_DEBUG，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '生产环境必须为 false',
            $output,
            "必须说明为什么必须 false，实际输出：\n{$output}"
        );
    }

    /**
     * 附：`APP_ENV` 不是生产类取值 → exit 1（预检固定按生产标准判定）
     */
    public function testAppEnvMustBeProductionLike(): void
    {
        $file = $this->makeEnvFile($this->compliantEnv(['APP_ENV' => 'dev']));

        [$exitCode, $output] = $this->runScript(['--env-file=' . $file]);

        $this->assertSame(1, $exitCode, "APP_ENV=dev 时预检必须失败（它问的是「能不能上生产」）：\n{$output}");
        $this->assertStringContainsString('[REQ] APP_ENV', $output, "必须列出 APP_ENV，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '必须是生产类环境取值',
            $output,
            "必须说明允许哪些取值，实际输出：\n{$output}"
        );
    }

    // -----------------------------------------------------------------------
    // 7. 解析失败必须 fail-closed
    // -----------------------------------------------------------------------

    /**
     * 用例 7：注释里的半角括号 / 半角竖线 → parse_ini_file 报语法错误 → exit 1
     *
     * 这两种字符曾真实造成「整份 .env 的键静默失效」（`Env::load()` 的 `?: []` 兜底），
     * 因此它们在预检里必须是**错误**，不能是告警、更不能被当成「没有不合规项」。
     */
    public function testHalfWidthCharsInCommentMakeParseFail(): void
    {
        $cases = [
            '半角括号' => '# 生产环境 (prod) 注意替换',
            '半角竖线' => '# 语义：cache | session 均走 redis',
        ];

        foreach ($cases as $label => $commentLine) {
            $file = $this->makeEnvFile($this->compliantEnv([], $commentLine . "\n"));

            [$exitCode, $output] = $this->runScript(['--env-file=' . $file]);

            $this->assertSame(1, $exitCode, "{$label}：解析失败必须以 1 退出，实际输出：\n{$output}");
            $this->assertStringContainsString(
                '环境文件无法解析',
                $output,
                "{$label}：必须明确报「无法解析」，实际输出：\n{$output}"
            );
            $this->assertStringContainsString(
                $commentLine,
                $output,
                "{$label}：必须带出错行原文，实际输出：\n{$output}"
            );
            $this->assertStringContainsString(
                '已跳过逐键检查',
                $output,
                "{$label}：解析失败后应跳过逐键检查（否则会刷一堆误导性的「未配置或为空」），实际输出：\n{$output}"
            );
            $this->assertStringNotContainsString(
                '✓ 全部检查通过',
                $output,
                "{$label}：绝不能伪装成通过，实际输出：\n{$output}"
            );
        }
    }

    // -----------------------------------------------------------------------
    // 8. 文件不存在
    // -----------------------------------------------------------------------

    public function testMissingFileFails(): void
    {
        $missing = $this->rootPath . '/.env.prodcheck-not-exists-' . getmypid();

        [$exitCode, $output] = $this->runScript(['--env-file=' . $missing]);

        $this->assertSame(1, $exitCode, "文件不存在必须以 1 退出，实际输出：\n{$output}");
        $this->assertStringContainsString('[FILE] 环境文件不存在', $output, "应明确报文件缺失，实际输出：\n{$output}");
        $this->assertStringNotContainsString(
            '✓ 全部检查通过',
            $output,
            "文件缺失不得伪装成通过，实际输出：\n{$output}"
        );
    }

    /**
     * 默认路径（不带 --env-file）指向 `.env.production`，仓库里没有它 → exit 1
     */
    public function testDefaultPathIsDotEnvProduction(): void
    {
        if (is_file($this->rootPath . '/.env.production')) {
            $this->markTestSkipped('本机存在 .env.production（真实部署文件），跳过「默认路径不存在」的断言');
        }

        [$exitCode, $output] = $this->runScript([]);

        $this->assertSame(1, $exitCode, "默认路径不存在时应以 1 退出，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '环境文件: .env.production（默认）',
            $output,
            "必须显式说明默认检查的是哪个文件，实际输出：\n{$output}"
        );
    }

    // -----------------------------------------------------------------------
    // 9. 脱敏
    // -----------------------------------------------------------------------

    /**
     * 用例 9：输出不含完整口令 / 密钥
     *
     * 两种形态都要覆盖：
     *   a. 合规的强口令 —— 它不触发任何规则，因此**根本不应出现**在输出里；
     *   b. 不合格的口令 —— 必须出现，但只能是「前 2 位 + 长度」。
     */
    public function testSecretsAreNeverPrintedInCleartext(): void
    {
        $strongPassword = 'Wq7-a-strong-password-value-2026';
        $strongSecret   = str_repeat('a1b2c3d4', 8);

        $clean = $this->makeEnvFile($this->compliantEnv([
            'DB_PASS'    => $strongPassword,
            'JWT_SECRET' => $strongSecret,
        ]));

        [$exitCode, $output] = $this->runScript(['--env-file=' . $clean]);

        $this->assertSame(0, $exitCode, "合规配置应以 0 退出，实际输出：\n{$output}");
        $this->assertStringNotContainsString($strongPassword, $output, "合规口令不得出现在输出里，实际输出：\n{$output}");
        $this->assertStringNotContainsString($strongSecret, $output, "合规密钥不得出现在输出里，实际输出：\n{$output}");

        // b. 不合格的口令：必须脱敏成「前 2 位 + 长度」
        $weak = $this->makeEnvFile($this->compliantEnv([
            'DB_PASS'    => 'root',
            'JWT_SECRET' => 'short-but-supersecret-value',
        ]));

        [$weakExit, $weakOutput] = $this->runScript(['--env-file=' . $weak]);

        $this->assertSame(1, $weakExit, "两处不合格都应以失败退出，实际输出：\n{$weakOutput}");
        $this->assertStringNotContainsString(
            'short-but-supersecret-value',
            $weakOutput,
            "JWT_SECRET 的完整值绝不能出现在输出里，实际输出：\n{$weakOutput}"
        );
        $this->assertStringContainsString(
            '"sh"***',
            $weakOutput,
            "JWT_SECRET 必须按「前 2 位 + 长度」脱敏，实际输出：\n{$weakOutput}"
        );
        $this->assertStringContainsString(
            sprintf('共 %d 字符', strlen('short-but-supersecret-value')),
            $weakOutput,
            "脱敏后必须给出长度以便定位，实际输出：\n{$weakOutput}"
        );
        $this->assertStringContainsString('"ro"***', $weakOutput, "DB_PASS 必须脱敏，实际输出：\n{$weakOutput}");
    }

    // -----------------------------------------------------------------------
    // 10. 模板自检：预期失败
    // -----------------------------------------------------------------------

    /**
     * 用例 10：`--env-file=.env.production.example` 在当前仓库上**应该报错**
     *
     * 这是预期行为，不是缺陷：模板里写占位符是**正确做法**（模板就该写 `REPLACE_WITH_*`），
     * 所以任何「拿模板当配置」的用法都必须被拦下 —— 否则模板里那些占位符会原样上生产。
     * 本用例同时也验证了「相对路径按仓库根解析」这条约定。
     */
    public function testProductionTemplateIsRejectedAsExpected(): void
    {
        $template = $this->rootPath . '/.env.production.example';
        if (!is_file($template)) {
            $this->markTestSkipped('.env.production.example 不存在，本用例的前提不成立');
        }

        [$exitCode, $output] = $this->runScript(['--env-file=.env.production.example']);

        $this->assertSame(
            1,
            $exitCode,
            "模板里全是占位符，预检必须失败（这是预期结论，不是缺陷）：\n{$output}"
        );

        // 点出每个仍是占位符的键
        foreach (['DEFAULT_TENANT_ID', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'JWT_SECRET'] as $key) {
            $this->assertStringContainsString(
                "[REQ] {$key}",
                $output,
                "模板里的 {$key} 仍是占位符，必须被点名，实际输出：\n{$output}"
            );
        }

        // 模板的 APP_ENV 已经写成 production（所以它不该出现在不合格名单里）——
        // 这条断言同时证明「失败原因确实是占位符，而不是 APP_ENV 没写对」
        $this->assertStringNotContainsString(
            '[REQ] APP_ENV',
            $output,
            "模板里 APP_ENV=production 应当通过，实际输出：\n{$output}"
        );
        $this->assertStringNotContainsString(
            '[REQ] DB_PASS  当前值: "REPLACE_WITH_STRONG_DB_PASSWORD"',
            $output,
            "DB_PASS 是敏感键，占位符原文不得完整打印，实际输出：\n{$output}"
        );
    }

    // -----------------------------------------------------------------------
    // 11. --strict：条件必需项的告警升格为失败
    // -----------------------------------------------------------------------

    /**
     * `TRUSTED_PROXIES` 只在「RATELIMIT_ENABLED=true」时才被消费：缺它只告警
     *
     * 非 strict：exit 0（不阻断合理的客户端直连部署）；strict：exit 1（CI 门禁语义 = 我要求生产级配置）。
     */
    public function testConditionalWarningOnlyFailsInStrictMode(): void
    {
        $file = $this->makeEnvFile($this->compliantEnv([
            'RATELIMIT_ENABLED' => 'true',
            'TRUSTED_PROXIES'   => '',
        ]));

        [$exitCode, $output] = $this->runScript(['--env-file=' . $file]);
        $this->assertSame(0, $exitCode, "非 strict 下条件必需项缺失只告警，实际输出：\n{$output}");
        $this->assertStringContainsString('[COND] 未配置', $output, "必须给出告警明细，实际输出：\n{$output}");
        // 告警不阻断，但「退出码 0」必须与文案一致：通过 + 明确说出「另有告警、strict 会失败」
        $this->assertStringContainsString('✓ 全部检查通过', $output, "非 strict 下应给出通过结论，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '--strict 下会失败',
            $output,
            "必须提示 strict 模式会拦截，实际输出：\n{$output}"
        );

        [$strictExit, $strictOutput] = $this->runScript(['--env-file=' . $file, '--strict']);
        $this->assertSame(1, $strictExit, "--strict 下告警应视为失败，实际输出：\n{$strictOutput}");
        $this->assertStringContainsString(
            '--strict 模式下告警视为失败',
            $strictOutput,
            "必须说明失败原因来自 strict 模式，实际输出：\n{$strictOutput}"
        );
    }

    /**
     * `RATELIMIT_ENABLED` 未开启时，条件必需项显式标记为「跳过」而不是静默消失
     */
    public function testConditionalIsSkippedVisiblyWhenRatelimitDisabled(): void
    {
        $file = $this->makeEnvFile($this->compliantEnv([
            'RATELIMIT_ENABLED' => 'false',
            'TRUSTED_PROXIES'   => '',
        ]));

        [$exitCode, $output] = $this->runScript(['--env-file=' . $file]);

        $this->assertSame(0, $exitCode, "限流未开启时不应失败，实际输出：\n{$output}");
        $this->assertStringContainsString(
            '跳过（条件不成立）',
            $output,
            "条件不成立时应显式标记跳过（可见性优先），实际输出：\n{$output}"
        );
    }

    // -----------------------------------------------------------------------
    // 辅助方法
    // -----------------------------------------------------------------------

    /**
     * 生产环境的合规基线（各键取值都能通过生产规则，且不产生任何告警）
     *
     * @param array<string, string> $overrides 覆写项
     * @param string                $extraLines 追加到文件末尾的额外行（用于制造语法错误）
     */
    private function compliantEnv(array $overrides = [], string $extraLines = ''): string
    {
        $values = array_merge([
            'APP_ENV'            => 'production',
            'APP_DEBUG'          => 'false',
            'DEFAULT_TENANT_ID'  => '1',
            'DB_HOST'            => 'db-primary.internal',
            'DB_NAME'            => 'alkaid_prod',
            'DB_USER'            => 'alkaid_app',
            'DB_PASS'            => 'Wq7-stronger-than-any-default-2026',
            // 64 个字符（≥32 字节），且不含任何占位标记
            'JWT_SECRET'         => str_repeat('a1b2c3d4', 8),
            'RATELIMIT_ENABLED'  => 'true',
            'TRUSTED_PROXIES'    => '10.0.0.5',
        ], $overrides);

        $lines = ['# 生产配置预检用例的临时文件（由 ' . self::class . ' 生成，用完即删）'];
        foreach ($values as $key => $value) {
            $lines[] = "{$key}={$value}";
        }
        if ($extraLines !== '') {
            // 去掉末尾换行，保持「一行一条」的形态由调用方决定
            $lines[] = rtrim($extraLines, "\n");
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * 写一个临时 env 文件（路径在 sys_get_temp_dir()，tearDown 删除）
     */
    private function makeEnvFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/envprodcheck-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.env';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * 选一个「当前没有进程监听」的本地端口
     *
     * 做法：让内核临时分配一个端口（bind 到 0），记下端口号后**立刻关闭**。
     * 这样得到的端口在本机几乎不可能被占用，比写死 6399 更稳（CI 上可能真有服务）。
     */
    private function closedLocalPort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("无法创建临时 socket 以挑选空闲端口：{$errstr}（{$errno}）");
        }

        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port, '应能解析出端口号');

        return $port;
    }

    /**
     * 以子进程执行本脚本，返回 [退出码, 合并 stdout/stderr 的输出]
     *
     * 刻意注入 `NO_COLOR=1`：脚本会按它禁用 ANSI 转义码（与 check-migrations.php /
     * check-env-example.php 同一约定）。否则断言里必须夹带 `\033[31m` 这类控制码，
     * 测试会因为「颜色实现变了」而红，而不是因为行为变了。
     *
     * @param array<int, string> $args 脚本参数
     */
    private function runScript(array $args): array
    {
        return $this->runPhp(array_merge([$this->rootPath . '/' . self::SCRIPT], $args), ['NO_COLOR' => '1']);
    }

    /**
     * 以子进程执行一个 PHP 入口（脚本或 `think`），返回 [退出码, 合并输出]
     *
     * 刻意用 `proc_open()` + argv 数组（不经过 shell）：参数里的空格/引号无需手工转义。
     * 子进程工作目录固定为项目根，环境变量以当前进程为底再叠加 `$env`。
     *
     * @param array<int, string>    $argv 完整 argv（不含 PHP 可执行文件本身）
     * @param array<string, string> $env  额外环境变量
     * @return array{0: int, 1: string}
     */
    private function runPhp(array $argv, array $env = []): array
    {
        $command = array_merge([PHP_BINARY], $argv);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            // stderr 合并进 stdout：PHP 的致命错误与框架异常都走 stderr
            2 => ['redirect', 1],
        ];

        // proc_open 传 env 数组是「整体替换」，必须先把当前进程环境全部拷贝过来
        $childEnv = [];
        foreach (getenv() as $key => $value) {
            if (is_string($value)) {
                $childEnv[(string) $key] = $value;
            }
        }
        foreach ($env as $key => $value) {
            $childEnv[$key] = $value;
        }

        $pipes   = [];
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
