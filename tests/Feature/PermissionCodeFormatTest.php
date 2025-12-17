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
 * Permission Code Format Test | 权限码格式测试
 *
 * Ensures that permission codes exposed by auth APIs follow the external
 * `resource:action` format, with only lowercase letters, digits and
 * underscores on both sides of the colon.
 *
 * 确保认证相关 API 返回的权限码统一为 `resource:action` 形式，
 * 并且冒号两侧仅由小写字母、数字和下划线组成。
 */
class PermissionCodeFormatTest extends ThinkPHPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure app\Request is bound to think\Request | 将 app\Request 绑定为 think\Request 实现
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // Load auth routes | 加载认证相关路由
        $routeFile = $this->app()->getRootPath() . 'route/auth.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }
    }

    /**
     * Helper to make GET request.
     */
    protected function get(string $uri, array $headers = [])
    {
        return $this->request('GET', $uri, [], $headers);
    }

    /**
     * Core request helper using ThinkPHP HTTP kernel.
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
     * All permission codes from /v1/auth/me must follow `resource:action` pattern.
     *
     * /v1/auth/me 返回的 permissions 数组中，每一项都必须是 `resource:action` 形式，
     * 冒号两侧仅允许小写字母、数字和下划线。
     */
    public function testAuthMePermissionsUseResourceActionPattern(): void
    {
        $token = AuthHelper::generateTestToken(userId: 1, tenantId: 1, siteId: 0);

        $response = $this->get('v1/auth/me', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $this->assertEquals(200, $response->getCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(0, $data['code']);
        $this->assertArrayHasKey('permissions', $data['data']);

        $permissions = $data['data']['permissions'];
        $this->assertIsArray($permissions);
        $this->assertNotEmpty($permissions);

        foreach ($permissions as $permission) {
            $this->assertIsString($permission);
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+:[a-z0-9_]+$/', $permission);
        }
    }
}
