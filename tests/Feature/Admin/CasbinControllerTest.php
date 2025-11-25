<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * Casbin 管理控制器测试
 * Casbin Admin Controller Test
 *
 * 测试 Casbin 管理 API 的功能，包括手动刷新策略等。
 * Test Casbin admin API functions, including manual policy reload.
 */
class CasbinControllerTest extends ThinkPHPTestCase
{
    protected string $accessToken;
    protected int $testUserId = 88888;
    protected int $testRoleId = 88888;
    protected int $testPermissionId = 88888;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure app\Request is bound to think\Request
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // Load admin routes
        $routeFile = $this->app()->getRootPath() . 'route/admin.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 准备测试数据
        // Prepare test data
        $this->prepareTestData();

        // 生成测试用户的 Access Token
        // Generate access token for test user
        $this->accessToken = $this->generateAccessToken($this->testUserId);
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        // Clean up test data
        $this->cleanupTestData();

        parent::tearDown();
    }

    /**
     * 准备测试数据
     * Prepare test data
     */
    protected function prepareTestData(): void
    {
        // 创建测试用户
        // Create test user
        Db::table('users')->insert([
            'id' => $this->testUserId,
            'username' => 'test_casbin_admin',
            'email' => 'casbin_admin@test.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'tenant_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 创建测试角色
        // Create test role
        Db::table('roles')->insert([
            'id' => $this->testRoleId,
            'name' => 'Casbin Admin',
            'slug' => 'casbin_admin',
            'tenant_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 创建测试权限
        // Create test permission
        Db::table('permissions')->insert([
            'id' => $this->testPermissionId,
            'name' => 'Casbin Manage',
            'slug' => 'casbin.manage',
            'resource' => 'casbin',
            'action' => 'manage',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 分配角色给用户
        // Assign role to user
        Db::table('user_roles')->insert([
            'user_id' => $this->testUserId,
            'role_id' => $this->testRoleId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 分配权限给角色
        // Assign permission to role
        Db::table('role_permissions')->insert([
            'role_id' => $this->testRoleId,
            'permission_id' => $this->testPermissionId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 清理测试数据
     * Clean up test data
     */
    protected function cleanupTestData(): void
    {
        Db::table('role_permissions')->where('role_id', $this->testRoleId)->delete();
        Db::table('user_roles')->where('user_id', $this->testUserId)->delete();
        Db::table('permissions')->where('id', $this->testPermissionId)->delete();
        Db::table('roles')->where('id', $this->testRoleId)->delete();
        Db::table('users')->where('id', $this->testUserId)->delete();
    }

    /**
     * 生成 Access Token
     * Generate access token
     */
    protected function generateAccessToken(int $userId): string
    {
        $jwtService = new \Infrastructure\Auth\JwtService();
        return $jwtService->generateAccessToken($userId, 1, 0);
    }

    /**
     * Helper to make POST request
     */
    protected function post(string $uri, array $data = [], array $headers = [])
    {
        return $this->request('POST', $uri, $data, $headers);
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
     * 测试手动刷新策略成功
     * Test manual policy reload success
     */
    public function testReloadPolicySuccess(): void
    {
        // 创建控制器实例
        // Create controller instance
        $casbinService = new \Infrastructure\Permission\Service\CasbinService();
        $controller = new \app\controller\admin\CasbinController($this->app(), $casbinService);

        // 创建模拟请求
        // Create mock request
        $request = $this->app()->make(\think\Request::class);
        $request->withHeader([
            'X-Trace-Id' => 'test-trace-id-123',
        ]);

        // 使用反射设置 request 属性
        // Use reflection to set request property
        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        // 调用 reloadPolicy 方法
        // Call reloadPolicy method
        $response = $controller->reloadPolicy();

        // 验证响应状态码
        // Verify response status code
        $this->assertEquals(200, $response->getCode());

        // 解析响应 JSON
        // Parse response JSON
        $data = json_decode($response->getContent(), true);

        // 验证响应结构
        // Verify response structure
        $this->assertArrayHasKey('code', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('timestamp', $data);

        // 验证响应内容
        // Verify response content
        $this->assertEquals(0, $data['code']);
        $this->assertEquals('Policy reloaded successfully', $data['message']);

        // 验证 data 字段
        // Verify data field
        $this->assertArrayHasKey('execution_time_ms', $data['data']);
        $this->assertArrayHasKey('timestamp', $data['data']);
        $this->assertArrayHasKey('trace_id', $data['data']);

        // 验证执行时间是合理的（< 1000ms）
        // Verify execution time is reasonable (< 1000ms)
        $this->assertLessThan(1000, $data['data']['execution_time_ms']);
    }


}

