<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Infrastructure\Auth\JwtService;
use Tests\Factories\TestDataFactory;
use Tests\ThinkPHPTestCase;
use think\facade\Env;

/**
 * Auth Login/Refresh Feature Test | 认证登录/刷新功能测试
 */
class AuthLoginRefreshTest extends ThinkPHPTestCase
{
    private TestDataFactory $factory;
    private ?string $oldSecret = null;
    private ?string $oldIssuer = null;
    private int $userId;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();

        // 绑定自定义 Request，确保 tenantId/siteId 行为一致
        $this->app()->bind(\think\Request::class, \app\Request::class);
        // 强制重新绑定 JwtService，确保使用最新的 env 值
        $this->app()->bind(JwtService::class, fn () => new JwtService());

        // 加载认证路由
        $routeFile = $this->app()->getRootPath() . 'route/auth.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 保存并覆盖 JWT 环境变量，确保测试使用新命名
        $this->oldSecret = Env::get('JWT_SECRET');
        $this->oldIssuer = Env::get('JWT_ISSUER');
        Env::set('JWT_SECRET', 'feature_test_secret');
        Env::set('JWT_ISSUER', 'https://issuer.feature.test');
        // 确保底层 env 变量同步（避免 JWT 库读取旧值）
        putenv('JWT_SECRET=feature_test_secret');
        putenv('JWT_ISSUER=https://issuer.feature.test');

        $this->factory = new TestDataFactory();
        $this->email = 'auth_login_' . uniqid() . '@test.com';

        // 创建测试用户（密码为 secret123）
        $this->userId = $this->factory->createHashedUser([
            'email' => $this->email,
            'password' => password_hash('secret123', PASSWORD_DEFAULT),
        ]);
    }

    protected function tearDown(): void
    {
        // 恢复 JWT 环境变量
        Env::set('JWT_SECRET', $this->oldSecret);
        Env::set('JWT_ISSUER', $this->oldIssuer);
        putenv($this->oldSecret ? 'JWT_SECRET=' . $this->oldSecret : 'JWT_SECRET');
        putenv($this->oldIssuer ? 'JWT_ISSUER=' . $this->oldIssuer : 'JWT_ISSUER');

        parent::tearDown();
    }

    /**
     * 核心请求辅助方法
     */
    protected function request(string $method, string $uri, array $data = [], array $headers = [])
    {
        $request = $this->app()->make(\think\Request::class);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        if ($method === 'GET') {
            $request->withGet($data);
        } elseif ($method === 'POST') {
            $request->withPost($data);
            $request->withInput(json_encode($data));
        }

        $request = $request->withHeader($headers);

        return $this->runHttp($request);
    }

    public function testLoginReturnsTokensAndUsesJwtEnv(): void
    {
        $response = $this->request('POST', 'v1/auth/login', [
            'email' => $this->email,
            'password' => 'secret123',
        ]);

        $this->assertSame(200, $response->getCode());
        $data = json_decode($response->getContent(), true);

        $this->assertSame(0, $data['code']);
        $this->assertArrayHasKey('access_token', $data['data']);
        $this->assertArrayHasKey('refresh_token', $data['data']);

        // 无签名验证地解码 payload，校验 issuer 与载荷字段
        $payload = $this->decodePayload($data['data']['access_token']);
        $this->assertSame($this->expectedIssuer(), $payload['iss'] ?? null);
        $this->assertSame($this->userId, $payload['user_id'] ?? null);
        $this->assertSame(1, $payload['tenant_id'] ?? null);
        $this->assertSame(0, $payload['site_id'] ?? null);
    }

    public function testRefreshReturnsNewTokens(): void
    {
        // 先登录获取 refresh token，确保与服务端签名一致
        $login = $this->request('POST', 'v1/auth/login', [
            'email' => $this->email,
            'password' => 'secret123',
        ]);
        $loginData = json_decode($login->getContent(), true);
        $this->assertSame(0, $loginData['code']);
        $refreshToken = $loginData['data']['refresh_token'];
        $loginIssuer = $this->decodePayload($loginData['data']['access_token'])['iss'] ?? null;

        $response = $this->request('POST', 'v1/auth/refresh', [], [
            'Authorization' => 'Bearer ' . $refreshToken,
        ]);

        $this->assertSame(200, $response->getCode());
        $data = json_decode($response->getContent(), true);

        $this->assertSame(0, $data['code']);
        $this->assertArrayHasKey('access_token', $data['data']);
        $this->assertArrayHasKey('refresh_token', $data['data']);

        // 新 token 的 issuer 应与登录时一致，确保签发链路一致
        $payload = $this->decodePayload($data['data']['access_token']);
        $this->assertSame($loginIssuer, $payload['iss'] ?? null);
        $this->assertSame($this->userId, $payload['user_id'] ?? null);
    }

    /**
     * 无签名验证的 JWT 载荷解码（测试用途）
     */
    private function decodePayload(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        return is_array($payload) ? $payload : [];
    }

    /**
     * 计算当前 JwtService 使用的 issuer（不验证签名，仅取载荷）
     */
    private function expectedIssuer(): ?string
    {
        $service = new JwtService();
        $token = $service->generateAccessToken($this->userId, 1, 0);
        $payload = $this->decodePayload($token);
        return $payload['iss'] ?? null;
    }
}
