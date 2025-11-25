<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\ThinkPHPTestCase;
use Tests\Helpers\AuthHelper;

// Load ThinkPHP helper functions | 加载 ThinkPHP 助手函数
if (!function_exists('env')) {
    require_once __DIR__ . '/../../vendor/topthink/framework/src/helper.php';
}

/**
 * Auth Permission Integration Test | 认证权限集成测试
 *
 * Tests the integration of permissions in auth endpoints.
 * 测试认证端点中的权限集成。
 *
 * @package Tests\Feature
 */
class AuthPermissionIntegrationTest extends ThinkPHPTestCase
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

        // Set request data
        if ($method === 'GET') {
            $request->withGet($data);
        } elseif ($method === 'POST') {
            $request->withPost($data);
            $request->withInput(json_encode($data));
        }

        // Set headers
        $request = $request->withHeader($headers);

        return $this->runHttp($request);
    }

    /**
     * Test /v1/auth/me returns permissions field | 测试 /v1/auth/me 返回 permissions 字段
     */
    public function testAuthMeReturnsPermissionsField(): void
    {
        // Generate token for admin user (ID: 1) | 为管理员用户生成 token
        $token = AuthHelper::generateTestToken(userId: 1, tenantId: 1, siteId: 0);

        // Make request to /v1/auth/me | 请求 /v1/auth/me
        $response = $this->get('v1/auth/me', [
            'Authorization' => 'Bearer ' . $token
        ]);

        // Debug: print response if not 200
        if ($response->getCode() !== 200) {
            echo "\nResponse Code: " . $response->getCode() . "\n";
            echo "Response Content: " . $response->getContent() . "\n";
        }

        // Assert HTTP status code is 200 | 断言 HTTP 状态码为 200
        $this->assertEquals(200, $response->getCode());
        
        // Parse JSON response | 解析 JSON 响应
        $data = json_decode($response->getContent(), true);
        
        // Assert response structure | 断言响应结构
        $this->assertArrayHasKey('code', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals(0, $data['code']);
        
        // Assert permissions field exists | 断言 permissions 字段存在
        $this->assertArrayHasKey('permissions', $data['data']);
        
        // Assert permissions is an array | 断言 permissions 是数组
        $this->assertIsArray($data['data']['permissions']);
        $this->assertNotEmpty($data['data']['permissions']);
    }

    /**
     * Test permissions format is resource:action | 测试权限格式为 resource:action
     */
    public function testPermissionsFormatIsResourceAction(): void
    {
        $token = AuthHelper::generateTestToken(userId: 1, tenantId: 1, siteId: 0);

        $response = $this->get('v1/auth/me', [
            'Authorization' => 'Bearer ' . $token
        ]);
        
        $data = json_decode($response->getContent(), true);
        $permissions = $data['data']['permissions'];
        
        // Check each permission format | 检查每个权限的格式
        foreach ($permissions as $permission) {
            $this->assertIsString($permission);
            $this->assertStringContainsString(':', $permission);
            
            // Split and verify format | 分割并验证格式
            $parts = explode(':', $permission);
            $this->assertCount(2, $parts);
            $this->assertNotEmpty($parts[0]);  // resource
            $this->assertNotEmpty($parts[1]);  // action
        }
    }

    /**
     * Test permissions match user roles | 测试权限与用户角色匹配
     */
    public function testPermissionsMatchUserRoles(): void
    {
        $token = AuthHelper::generateTestToken(userId: 1, tenantId: 1, siteId: 0);

        $response = $this->get('v1/auth/me', [
            'Authorization' => 'Bearer ' . $token
        ]);
        
        $data = json_decode($response->getContent(), true);
        $permissions = $data['data']['permissions'];
        
        // Admin should have forms permissions | 管理员应该有 forms 权限
        $this->assertContains('forms:view', $permissions);
        $this->assertContains('forms:create', $permissions);
        $this->assertContains('forms:update', $permissions);
        $this->assertContains('forms:delete', $permissions);
    }

    /**
     * Test /v1/auth/me without authentication | 测试未认证时访问 /v1/auth/me
     */
    public function testAuthMeWithoutAuthentication(): void
    {
        // Make request without token | 不带 token 请求
        $response = $this->get('v1/auth/me');
        
        // Should return 401 | 应该返回 401
        $this->assertEquals(401, $response->getCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals(2001, $data['code']);
    }

    /**
     * Test /v1/auth/codes returns permission codes | 测试 /v1/auth/codes 返回权限码
     */
    public function testAuthCodesReturnsPermissionCodes(): void
    {
        $token = AuthHelper::generateTestToken(userId: 1, tenantId: 1, siteId: 0);

        // Make request to /v1/auth/codes | 请求 /v1/auth/codes
        $response = $this->get('v1/auth/codes', [
            'Authorization' => 'Bearer ' . $token
        ]);
        
        // Assert HTTP status code is 200 | 断言 HTTP 状态码为 200
        $this->assertEquals(200, $response->getCode());
        
        // Parse JSON response | 解析 JSON 响应
        $data = json_decode($response->getContent(), true);
        
        // Assert response structure | 断言响应结构
        $this->assertArrayHasKey('code', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals(0, $data['code']);
        
        // Assert data is an array of permission codes | 断言 data 是权限码数组
        $this->assertIsArray($data['data']);
        $this->assertNotEmpty($data['data']);
    }

    /**
     * Test /v1/auth/codes matches /v1/auth/me.permissions | 测试 /v1/auth/codes 与 /v1/auth/me.permissions 一致
     */
    public function testAuthCodesMatchesAuthMePermissions(): void
    {
        $token = AuthHelper::generateTestToken(userId: 1, tenantId: 1, siteId: 0);

        // Get permissions from /v1/auth/me | 从 /v1/auth/me 获取权限
        $meResponse = $this->get('v1/auth/me', [
            'Authorization' => 'Bearer ' . $token
        ]);
        $meData = json_decode($meResponse->getContent(), true);
        $mePermissions = $meData['data']['permissions'];

        // Get permissions from /v1/auth/codes | 从 /v1/auth/codes 获取权限
        $codesResponse = $this->get('v1/auth/codes', [
            'Authorization' => 'Bearer ' . $token
        ]);
        $codesData = json_decode($codesResponse->getContent(), true);
        $codesPermissions = $codesData['data'];
        
        // Should be identical | 应该完全一致
        sort($mePermissions);
        sort($codesPermissions);
        $this->assertEquals($mePermissions, $codesPermissions);
    }

    /**
     * Test /v1/auth/codes without authentication | 测试未认证时访问 /v1/auth/codes
     */
    public function testAuthCodesWithoutAuthentication(): void
    {
        // Make request without token | 不带 token 请求
        $response = $this->get('v1/auth/codes');
        
        // Should return 401 | 应该返回 401
        $this->assertEquals(401, $response->getCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals(2001, $data['code']);
    }

    /**
     * Test permissions include trace_id | 测试权限响应包含 trace_id
     */
    public function testPermissionsIncludeTraceId(): void
    {
        $token = AuthHelper::generateTestToken(userId: 1, tenantId: 1, siteId: 0);

        // Set trace_id in request header | 在请求头中设置 trace_id
        $response = $this->get('v1/auth/me', [
            'Authorization' => 'Bearer ' . $token,
            'X-Trace-Id' => 'test-trace-id-permissions'
        ]);
        
        $data = json_decode($response->getContent(), true);
        
        // Should include trace_id | 应该包含 trace_id
        $this->assertArrayHasKey('trace_id', $data);
        $this->assertEquals('test-trace-id-permissions', $data['trace_id']);
    }
}

