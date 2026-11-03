<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Infrastructure\Auth\JwtService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;
use think\facade\Env;

/**
 * JwtService 单元测试：校验 env 配置与令牌签发/校验流程
 */
class JwtServiceTest extends ThinkPHPTestCase
{
    private ?string $oldSecret = null;
    private ?string $oldIssuer = null;

    protected function setUp(): void
    {
        parent::setUp();
        // 备份原始环境变量
        $this->oldSecret = Env::get('JWT_SECRET');
        $this->oldIssuer = Env::get('JWT_ISSUER');

        // 设置测试专用 env
        Env::set('JWT_SECRET', 'unit_test_secret');
        Env::set('JWT_ISSUER', 'https://issuer.unit.test');
        Cache::clear();
    }

    protected function tearDown(): void
    {
        // 恢复环境变量
        Env::set('JWT_SECRET', $this->oldSecret);
        Env::set('JWT_ISSUER', $this->oldIssuer);
        Cache::clear();
        parent::tearDown();
    }

    public function testGenerateAccessTokenUsesEnvSecretAndIssuer(): void
    {
        $service = new JwtService();
        $token = $service->generateAccessToken(1, 2, 3);

        // 使用 env 中的密钥解码，校验 claims
        $decoded = JWT::decode($token, new Key('unit_test_secret', 'HS256'));
        $payload = (array)$decoded;

        $this->assertSame('https://issuer.unit.test', $payload['iss']);
        $this->assertSame(1, $payload['user_id']);
        $this->assertSame(2, $payload['tenant_id']);
        $this->assertSame(3, $payload['site_id']);
        $this->assertSame('access', $payload['type']);
    }

    public function testValidateRefreshTokenRespectsRevocation(): void
    {
        $service = new JwtService();
        $token = $service->generateRefreshToken(10, 20, 0);

        // 首次校验应通过
        $payload = $service->validateRefreshToken($token);
        $this->assertSame('refresh', $payload['type']);
        $this->assertSame(10, $payload['user_id']);
        $this->assertSame(20, $payload['tenant_id']);

        // 使 Refresh Token 失效后再校验应抛出 TOKEN_REVOKED
        $jti = (string)($payload['jti'] ?? '');
        $service->invalidateRefreshToken(10, 20, $jti);

        $this->expectExceptionMessage('TOKEN_REVOKED');
        $service->validateRefreshToken($token);
    }
}
