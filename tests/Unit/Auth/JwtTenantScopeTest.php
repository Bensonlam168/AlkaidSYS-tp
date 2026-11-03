<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Firebase\JWT\JWT;
use Infrastructure\Auth\JwtService;
use Tests\ThinkPHPTestCase;
use think\facade\Env;

/**
 * 令牌租户上下文校验测试（缺陷 R19）
 *
 * 背景：
 *   `JwtService::generateAccessToken()` 原先**不做任何**租户/用户校验，直接把
 *   `user_id` / `tenant_id` 写进 payload；而 `validateAccessToken()` 只校验 `type === 'access'`。
 *   结果是 `tenant_id = 0` 的令牌能正常签发、正常通过鉴权，只在业务层被拒绝，
 *   表现为「登录成功却处处 403」，非常难定位；同时假想的「0 号租户」可能让按租户过滤的
 *   查询落入缺失隔离的分支。
 *
 * 本测试钉住两侧行为：
 *   1. **签发侧**：`user_id <= 0` 或 `tenant_id <= 0` 时抛 `InvalidArgumentException`；
 *   2. **校验侧**：即使令牌绕过签发侧（改造前签发的存量令牌、或持有同一密钥的伪造者），
 *      `validateAccessToken()` 也必须拒绝 `user_id <= 0` **或** `tenant_id <= 0` 的令牌
 *      （评审 M1：原先校验侧只判 tenant_id，与签发侧的维度不对称）；
 *   3. **反向对照**：`user_id` / `tenant_id` 均为正数的手工令牌仍然正常通过，防止把校验改死。
 *
 * 手工签名的取密钥方式（见 {@see self::signAccessTokenBypassingIssuerChecks()}）：
 *   反射读取 `JwtService` 的 protected `$secret` / `$issuer`，保证手工签出的令牌与
 *   `validateAccessToken()` 内部即将使用的密钥、issuer 完全一致（不硬编码 `.env` 值）。
 *
 * 测试 ID 段（评审 M5）：统一用 68xx，避开 `WorkflowTenantOwnershipTest` 占用的
 *   67xx 段（那里 6701/6702 是真实落库的 `users` 主键，同号会互相干扰）。
 *
 * @package Tests\Unit\Auth
 */
class JwtTenantScopeTest extends ThinkPHPTestCase
{
    private ?string $oldSecret = null;
    private ?string $oldIssuer = null;

    /**
     * 被测服务实例
     */
    private JwtService $service;

    /**
     * 手工签名用的密钥（与 $service 内部使用的完全一致）
     */
    private string $secret;

    /**
     * 手工签名用的 issuer（与 $service 内部校验的完全一致）
     */
    private string $issuer;

    protected function setUp(): void
    {
        parent::setUp();

        // 备份并固定测试专用 env，避免受 .env 与执行顺序影响
        $this->oldSecret = Env::get('JWT_SECRET');
        $this->oldIssuer = Env::get('JWT_ISSUER');
        Env::set('JWT_SECRET', 'tenant_scope_test_secret_0123456789abcdef0123456789');
        Env::set('JWT_ISSUER', 'https://issuer.tenant-scope.test');
        // 注意（评审 M4）：这里**不要**做缓存整目录清理（历史实现调的是 Cache facade 的 clear）。
        // 本测试全程不读缓存（jti 随机 → 黑名单必然 miss），清了也没用；
        // 而 File 驱动下整目录清理等价于 `rmdir(runtime/cache)`，会让并行执行的其它测试
        // 的删除/重建交叉进行，误删他人 key（R17 已修掉同款反模式）。

        $this->service = new JwtService();
        $this->secret  = $this->readProtectedString('secret');
        $this->issuer  = $this->readProtectedString('issuer');
    }

    protected function tearDown(): void
    {
        Env::set('JWT_SECRET', $this->oldSecret);
        Env::set('JWT_ISSUER', $this->oldIssuer);

        parent::tearDown();
    }

    // =====================================================================
    // 签发侧：无效的用户/租户上下文不得签发
    // =====================================================================

    /**
     * user_id = 0 时不得签发 Access Token
     */
    public function testRejectsZeroUserIdOnIssue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(JwtService::ERR_INVALID_TENANT_SCOPE);

        $this->service->generateAccessToken(0, 1);
    }

    /**
     * tenant_id = 0 时不得签发 Access Token
     */
    public function testRejectsZeroTenantIdOnIssue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(JwtService::ERR_INVALID_TENANT_SCOPE);

        $this->service->generateAccessToken(1, 0);
    }

    /**
     * tenant_id 为负数时不得签发 Access Token
     */
    public function testRejectsNegativeTenantIdOnIssue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(JwtService::ERR_INVALID_TENANT_SCOPE);

        $this->service->generateAccessToken(1, -1);
    }

    // =====================================================================
    // 校验侧：绕过签发侧手工签发的令牌必须被拒
    // =====================================================================

    /**
     * tenant_id = 0 的存量令牌（绕过签发校验手工签名）必须被 validateAccessToken 拒绝
     */
    public function testValidateAccessTokenRejectsHandSignedZeroTenantToken(): void
    {
        $token = $this->signAccessTokenBypassingIssuerChecks(6801, 0);

        // 手工签名的令牌签名/iss 均合法，只有 tenant_id 不合法 → 必须被校验侧拦下
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(JwtService::ERR_INVALID_TENANT_SCOPE);

        $this->service->validateAccessToken($token);
    }

    /**
     * 负 tenant_id 同样必须被拒绝（防御 `<= 0` 写成 `=== 0` 的半吊子修法）
     */
    public function testValidateAccessTokenRejectsHandSignedNegativeTenantToken(): void
    {
        $token = $this->signAccessTokenBypassingIssuerChecks(6801, -1);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(JwtService::ERR_INVALID_TENANT_SCOPE);

        $this->service->validateAccessToken($token);
    }

    /**
     * 反向对照：tenant_id = 1 的手工令牌必须正常通过（防止把校验改死）
     */
    public function testValidateAccessTokenAcceptsHandSignedPositiveTenantToken(): void
    {
        $token   = $this->signAccessTokenBypassingIssuerChecks(6801, 1);
        $payload = $this->service->validateAccessToken($token);

        $this->assertSame(6801, $payload['user_id']);
        $this->assertSame(1, $payload['tenant_id']);
        $this->assertSame('access', $payload['type']);
    }

    /**
     * user_id = 0、tenant_id = 1 的令牌必须被拒绝（评审 M1）
     *
     * 背景：签发侧校验的是 `user_id` 与 `tenant_id` **两个**维度，校验侧原先只判 `tenant_id`，
     * 于是「租户合法、用户非法」的令牌能通过 `validateAccessToken()`：
     *   - 挂了 `Permission` 中间件的路由：查不到 0 号用户的权限 → 2002/403，尚能被挡；
     *   - 仅挂 `Auth` 中间件的路由：请求继续执行业务，以「0 号用户」身份运行，
     *     权限/审计/数据归属判定都落在未定义语义上（例如 created_by = 0 的脏数据）。
     * 修复后校验侧与签发侧维度对称，本用例钉住这一点。
     */
    public function testValidateAccessTokenRejectsHandSignedZeroUserToken(): void
    {
        // 租户维度合法（tenant_id = 1），只有 user_id 非法
        $token = $this->signAccessTokenBypassingIssuerChecks(0, 1);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(JwtService::ERR_INVALID_TENANT_SCOPE);

        $this->service->validateAccessToken($token);
    }

    // =====================================================================
    // 正常签发链路：租户 >= 1 不受影响
    // =====================================================================

    /**
     * 正常签发的令牌可以被正常解析（签发 + 校验往返一致）
     */
    public function testGeneratedTokenRoundTripKeepsTenantScope(): void
    {
        $token   = $this->service->generateAccessToken(6802, 1, 0);
        $payload = $this->service->validateAccessToken($token);

        $this->assertSame(6802, $payload['user_id']);
        $this->assertSame(1, $payload['tenant_id']);
        $this->assertSame(0, $payload['site_id']);
        $this->assertSame('access', $payload['type']);
    }

    // =====================================================================
    // 辅助方法
    // =====================================================================

    /**
     * 绕过签发侧校验，手工签发一个 access token（模拟「改造前签发的存量令牌」）
     *
     * 之所以能绕过：本方法不经过 `JwtService::generateAccessToken()`，
     * 而是直接用 firebase/php-jwt 的 `JWT::encode()` 组装 payload，因此
     * `tenant_id = 0` 也能签出一个签名合法、iss/exp 合法的令牌。
     *
     * 密钥取法：`$this->secret` / `$this->issuer` 由 {@see self::readProtectedString()}
     * 从 `JwtService` 实例的 protected 属性反射取得，与实例内部实际使用的值一致。
     *
     * @param int $userId   用户 ID
     * @param int $tenantId 租户 ID（可传 0 / 负数以构造非法令牌）
     * @param int $siteId   站点 ID
     */
    private function signAccessTokenBypassingIssuerChecks(int $userId, int $tenantId, int $siteId = 0): string
    {
        $now = time();

        return JWT::encode([
            'iss'       => $this->issuer,
            'iat'       => $now,
            'nbf'       => $now,
            'exp'       => $now + 300,
            'jti'       => bin2hex(random_bytes(8)),
            'type'      => 'access',
            'user_id'   => $userId,
            'tenant_id' => $tenantId,
            'site_id'   => $siteId,
        ], $this->secret, 'HS256');
    }

    /**
     * 反射读取 JwtService 的 protected 字符串属性
     *
     * JwtService 未对外暴露 secret / issuer 的 getter，测试里只能反射读取；
     * 不硬编码 `.env` 中的值，可避免「测试改了 env 但服务读的是另一份」的假绿。
     *
     * @param string $property 属性名（secret / issuer）
     */
    private function readProtectedString(string $property): string
    {
        $ref = new \ReflectionProperty(JwtService::class, $property);
        $ref->setAccessible(true);

        return (string) $ref->getValue($this->service);
    }
}
