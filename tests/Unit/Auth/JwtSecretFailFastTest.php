<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Infrastructure\Auth\JwtService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ThinkPHPTestCase;
use think\facade\Env;

/**
 * JWT 密钥 fail-fast 测试（缺陷 C-5 / 独立评审问题 1、2、3）
 *
 * 背景：JwtService 原先在 JWT_SECRET 缺失时回退到仓库内公开的默认值
 * `CHANGE_THIS_IN_PRODUCTION`，任何知道该字符串的人都能伪造任意用户的 token。
 *
 * 目标行为（实现状态：已实现）：
 * - 生产类环境（production/prod/stage/staging，以及未设置 APP_ENV）且密钥为空、
 *   命中公开占位符、或长度不足 32 字节 → 构造 JwtService 时抛 RuntimeException。
 *   注意：JwtService 是惰性构造的（中间件/DI 中按需解析），因此**进程不会在启动期失败**，
 *   真实表现是「进程正常启动，之后每个需要鉴权的请求被拒绝」。
 * - 非生产环境（dev/local/testing）→ 允许使用占位符；若密钥为空则回退到内置开发密钥
 *   并记录 warning 日志，确保「签发的 token 一定能被自己校验」。
 */
class JwtSecretFailFastTest extends ThinkPHPTestCase
{
    /**
     * 仓库内公开的默认密钥（禁止用于生产）
     */
    private const PUBLIC_DEFAULT_SECRET = 'CHANGE_THIS_IN_PRODUCTION';

    /**
     * .env.example 中的 dev 专用占位值（同样属于公开占位符，生产环境必须拒绝）
     */
    private const DEV_PLACEHOLDER_SECRET = 'dev-only-insecure-secret-please-change-in-production-0123456789';

    private ?string $oldAppEnv = null;
    private ?string $oldSecret = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 备份原始环境变量，确保 tearDown 可完整恢复
        $oldAppEnv       = Env::get('APP_ENV');
        $oldSecret       = Env::get('JWT_SECRET');
        $this->oldAppEnv = is_string($oldAppEnv) ? $oldAppEnv : null;
        $this->oldSecret = is_string($oldSecret) ? $oldSecret : null;
    }

    protected function tearDown(): void
    {
        // 恢复环境变量，避免污染其他用例
        Env::set('APP_ENV', $this->oldAppEnv);
        Env::set('JWT_SECRET', $this->oldSecret);
        parent::tearDown();
    }

    /**
     * 生产环境 + 公开默认密钥 → 必须拒绝启动
     */
    public function testProductionWithPublicDefaultSecretThrows(): void
    {
        Env::set('APP_ENV', 'production');
        Env::set('JWT_SECRET', self::PUBLIC_DEFAULT_SECRET);

        $this->expectException(\RuntimeException::class);
        new JwtService();
    }

    /**
     * 生产环境 + 空密钥 → 必须拒绝启动
     */
    public function testProductionWithEmptySecretThrows(): void
    {
        Env::set('APP_ENV', 'production');
        Env::set('JWT_SECRET', '');

        $this->expectException(\RuntimeException::class);
        new JwtService();
    }

    /**
     * 生产环境 + 足够强的随机密钥 → 正常构造
     */
    public function testProductionWithStrongSecretConstructs(): void
    {
        Env::set('APP_ENV', 'production');
        Env::set('JWT_SECRET', 'a-sufficiently-long-random-secret-0123456789');

        $service = new JwtService();

        $this->assertInstanceOf(JwtService::class, $service);
    }

    /**
     * 非生产环境 + 公开默认密钥 → 允许（保护本地开发与现有测试）
     */
    public function testDevWithPublicDefaultSecretConstructs(): void
    {
        Env::set('APP_ENV', 'dev');
        Env::set('JWT_SECRET', self::PUBLIC_DEFAULT_SECRET);

        $service = new JwtService();

        $this->assertInstanceOf(JwtService::class, $service);
    }

    /**
     * 问题 1：dev 环境 + 空密钥 → 必须能完成「签发 → 校验」闭环
     *
     * 背景：firebase/php-jwt 拒绝空 key 解码。若空密钥被直接用于签名，
     * 会出现「登录返回 200 且带 token，但随后每个受保护接口都 401」的极难定位问题。
     */
    public function testDevWithEmptySecretCanGenerateAndValidateToken(): void
    {
        Env::set('APP_ENV', 'dev');
        Env::set('JWT_SECRET', '');

        $service = new JwtService();
        $token   = $service->generateAccessToken(1001, 1, 0);

        // 修复前：此处抛 INVALID_TOKEN_FORMAT: Key material must not be empty
        $payload = $service->validateAccessToken($token);

        $this->assertSame(1001, (int) $payload['user_id']);
        $this->assertSame(1, (int) $payload['tenant_id']);
    }

    /**
     * 问题 1：dev 环境 + 空密钥回退内置开发密钥时，必须记录 warning 日志，
     * 让开发者能意识到「当前使用的是公开的开发密钥」。
     */
    public function testDevWithEmptySecretLogsWarning(): void
    {
        Env::set('APP_ENV', 'dev');
        Env::set('JWT_SECRET', '');

        $records = $this->captureLogRecordsDuring(function (): void {
            new JwtService();
        });

        $warnings = array_values(array_filter(
            $records,
            static fn (array $record): bool => $record['type'] === 'warning'
                && str_contains($record['message'], 'JWT_SECRET')
        ));

        $this->assertNotEmpty(
            $warnings,
            '空密钥回退到内置开发密钥时应记录包含 JWT_SECRET 的 warning 日志，实际日志：'
            . json_encode($records, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 问题 2：生产类环境下的不安全密钥必须被拒绝（修复 5 条绕过路径）
     *
     * @param string $appEnv 环境名
     * @param string $secret 密钥
     * @param string $expectedReason 异常消息中必须出现的原因关键字
     */
    #[DataProvider('insecureProductionSecretProvider')]
    public function testInsecureProductionSecretThrows(
        string $appEnv,
        string $secret,
        string $expectedReason
    ): void {
        Env::set('APP_ENV', $appEnv);
        Env::set('JWT_SECRET', $secret);

        try {
            new JwtService();
            $this->fail(sprintf(
                'APP_ENV=%s 且 JWT_SECRET=%s 时 JwtService 应拒绝构造，但实际构造成功',
                $appEnv,
                var_export($secret, true)
            ));
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                $expectedReason,
                $e->getMessage(),
                '异常消息应说明具体原因，实际：' . $e->getMessage()
            );
        }
    }

    /**
     * 生产环境不安全密钥用例集合（原实现中被放行的组合）
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function insecureProductionSecretProvider(): array
    {
        return [
            'production + REPLACE_WITH 占位符' => [
                'production',
                'REPLACE_WITH_STRONG_RANDOM_SECRET_KEY_AT_LEAST_32_BYTES',
                'placeholder',
            ],
            'prod + 公开默认值' => ['prod', self::PUBLIC_DEFAULT_SECRET, 'placeholder'],
            'Production（大小写混合）+ 公开默认值' => ['Production', self::PUBLIC_DEFAULT_SECRET, 'placeholder'],
            'staging + 公开默认值' => ['staging', self::PUBLIC_DEFAULT_SECRET, 'placeholder'],
            'stage + 公开默认值' => ['stage', self::PUBLIC_DEFAULT_SECRET, 'placeholder'],
            'production + .env.example 的 dev 占位值' => [
                'production',
                self::DEV_PLACEHOLDER_SECRET,
                'placeholder',
            ],
            'production + 纯空格' => ['production', '   ', 'must not be empty'],
            'production + 过短密钥(3 字节)' => ['production', 'abc', 'too short'],
        ];
    }

    /**
     * 问题 2 反向用例：生产环境 + 足够长的随机密钥 → 不得误报
     */
    public function testProductionWithLongRandomSecretDoesNotThrow(): void
    {
        Env::set('APP_ENV', 'production');
        Env::set('JWT_SECRET', 'f3a9c1e7b25d8460fa1c3e5b7d9f2a4c6e8b0d2f4a6c8e0b2d4f6a8c0e2b4d6f');

        $service = new JwtService();

        $this->assertInstanceOf(JwtService::class, $service);
    }

    /**
     * 在回调执行期间捕获 ThinkPHP 日志记录
     *
     * 说明：测试运行于 CLI，日志通道 lazy=false 会即时落盘并清空内存记录，
     * 因此通过 LogWrite 事件监听捕获记录，而不是 Log::getLog()。
     * 监听器在 finally 中移除，避免污染同进程内的其他测试。
     *
     * @param callable $callback 需要被捕获日志的回调
     * @return array<int, array{type: string, message: string}>
     */
    private function captureLogRecordsDuring(callable $callback): array
    {
        $records = [];
        $app     = $this->app();

        \think\facade\Log::listen(function ($event) use (&$records): void {
            foreach ($event->log as $record) {
                $records[] = [
                    'type'    => (string) $record->type,
                    'message' => (string) $record->message,
                ];
            }
        });

        try {
            $callback();
            // 非 CLI（懒写入）场景下需显式 flush 才会触发 LogWrite 事件
            \think\facade\Log::save();
        } finally {
            // 移除本次注册的 LogWrite 监听，避免跨用例污染
            $app->event->remove(\think\event\LogWrite::class);
        }

        return $records;
    }
}
