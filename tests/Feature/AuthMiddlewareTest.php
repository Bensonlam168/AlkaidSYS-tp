<?php

declare(strict_types=1);

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Infrastructure\Auth\JwtService;
use Tests\ThinkPHPTestCase;
use think\facade\Env;

// Load ThinkPHP helper functions | 加载 ThinkPHP 助手函数
if (!function_exists('env')) {
    require_once __DIR__ . '/../../vendor/topthink/framework/src/helper.php';
}

/**
 * Auth Middleware Error Code Test | 认证中间件错误码测试
 *
 * Verifies that all access token failures return the correct error code and HTTP status.
 * 验证所有 Access Token 失败场景均返回正确的业务错误码和 HTTP 状态码。
 */
class AuthMiddlewareTest extends ThinkPHPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure app\Request is bound to think\Request
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // Load auth routes
        $routeFile = $this->app()->getRootPath() . 'route/auth.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }
    }

    /**
     * Helper to make GET request
     */
    protected function get(string $uri, array $headers = [])
    {
        return $this->request('GET', $uri, [], $headers);
    }

    /**
     * Core request helper
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

    /**
     * Generate an expired access token for tests | 生成已过期的 Access Token
     */
    protected function generateExpiredAccessToken(): string
    {
        $secret = (string) Env::get('JWT_SECRET', 'CHANGE_THIS_IN_PRODUCTION');
        $issuer = (string) Env::get('JWT_ISSUER', 'https://api.alkaidsys.local');

        $now = time() - 3600;

        $payload = [
            'iss'       => $issuer,
            'iat'       => $now - 10,
            'nbf'       => $now - 10,
            'exp'       => $now - 5,
            'jti'       => bin2hex(random_bytes(16)),
            'type'      => 'access',
            'user_id'   => 1,
            'tenant_id' => 1,
            'site_id'   => 0,
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * Generate a revoked access token for tests | 生成已被吊销的 Access Token
     */
    protected function generateRevokedAccessToken(JwtService $jwtService): string
    {
        $token = $jwtService->generateAccessToken(1, 1, 0);

        $payload = $jwtService->decodeTokenPayload($token);
        $this->assertIsArray($payload);

        $jti = (string) ($payload['jti'] ?? '');
        $this->assertNotSame('', $jti);

        // Put token into blacklist to simulate revocation | 将 token 加入黑名单以模拟吊销
        $jwtService->revokeToken($jti);

        return $token;
    }

    public function test_invalid_token_returns_401_and_unauthorized_code(): void
    {
        $response = $this->get('v1/auth/me', [
            'Authorization' => 'Bearer invalid.jwt.token',
            'X-Trace-Id'    => 'test-trace-id-invalid-token',
        ]);

        if ($response->getCode() !== 401) {
            echo "\nResponse Code (invalid token): " . $response->getCode() . "\n";
            echo 'Response Content: ' . $response->getContent() . "\n";
        }

        $this->assertSame(401, $response->getCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame(2001, $data['code']);
        $this->assertArrayHasKey('trace_id', $data);
        $this->assertSame('test-trace-id-invalid-token', $data['trace_id']);
    }

    public function test_expired_token_returns_401_and_unauthorized_code(): void
    {
        $token = $this->generateExpiredAccessToken();

        $response = $this->get('v1/auth/me', [
            'Authorization' => 'Bearer ' . $token,
            'X-Trace-Id'    => 'test-trace-id-expired-token',
        ]);

        if ($response->getCode() !== 401) {
            echo "\nResponse Code (expired token): " . $response->getCode() . "\n";
            echo 'Response Content: ' . $response->getContent() . "\n";
        }

        $this->assertSame(401, $response->getCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame(2001, $data['code']);
        $this->assertArrayHasKey('trace_id', $data);
        $this->assertSame('test-trace-id-expired-token', $data['trace_id']);
    }

    public function test_revoked_token_returns_401_and_unauthorized_code(): void
    {
        $jwtService = new JwtService();
        $token      = $this->generateRevokedAccessToken($jwtService);

        $response = $this->get('v1/auth/me', [
            'Authorization' => 'Bearer ' . $token,
            'X-Trace-Id'    => 'test-trace-id-revoked-token',
        ]);

        if ($response->getCode() !== 401) {
            echo "\nResponse Code (revoked token): " . $response->getCode() . "\n";
            echo 'Response Content: ' . $response->getContent() . "\n";
        }

        $this->assertSame(401, $response->getCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame(2001, $data['code']);
        $this->assertArrayHasKey('trace_id', $data);
        $this->assertSame('test-trace-id-revoked-token', $data['trace_id']);
    }
}
