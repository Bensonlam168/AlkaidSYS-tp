<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Tests\ThinkPHPTestCase;
use think\facade\Db;

// Load ThinkPHP helper functions | 加载 ThinkPHP 助手函数
if (!function_exists('json')) {
    require_once __DIR__ . '/../../../vendor/topthink/framework/src/helper.php';
}

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

        // 获取或创建测试权限
        // Get or create test permission
        $permission = Db::table('permissions')->where('slug', 'casbin.manage')->find();
        if (!$permission) {
            Db::table('permissions')->insert([
                'id' => $this->testPermissionId,
                'name' => 'Casbin Manage',
                'slug' => 'casbin.manage',
                'resource' => 'casbin',
                'action' => 'manage',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $this->testPermissionId = $permission['id'];
        }

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
        // 不删除 casbin.manage 权限，因为它是系统权限
        // Don't delete casbin.manage permission as it's a system permission
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
     * 测试手动刷新策略成功
     * Test manual policy reload success
     */
    public function testReloadPolicySuccess(): void
    {
        // 创建控制器实例
        // Create controller instance
        $casbinService = new \Infrastructure\Permission\Service\CasbinService();
        $controller = new \app\controller\admin\CasbinController($this->app(), $casbinService);

        // 创建模拟请求（使用 app\Request）
        // Create mock request (using app\Request)
        $request = new \app\Request();
        $request->withHeader([
            'X-Trace-Id' => 'test-trace-id-123',
        ]);

        // 模拟用户上下文
        // Mock user context
        $reflection = new \ReflectionClass($request);
        if ($reflection->hasProperty('userId')) {
            $userIdProperty = $reflection->getProperty('userId');
            $userIdProperty->setAccessible(true);
            $userIdProperty->setValue($request, $this->testUserId);
        }
        if ($reflection->hasProperty('tenantId')) {
            $tenantIdProperty = $reflection->getProperty('tenantId');
            $tenantIdProperty->setAccessible(true);
            $tenantIdProperty->setValue($request, 1);
        }

        // 使用反射设置 request 属性
        // Use reflection to set request property
        $controllerReflection = new \ReflectionClass($controller);
        $requestProperty = $controllerReflection->getProperty('request');
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

