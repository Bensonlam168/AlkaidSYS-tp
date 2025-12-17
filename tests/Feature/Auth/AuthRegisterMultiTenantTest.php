<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 多租户注册默认角色创建与绑定测试
 * 确保注册新租户用户时自动创建租户级默认角色并绑定权限/用户。
 */
class AuthRegisterMultiTenantTest extends ThinkPHPTestCase
{
    private int $tenantId = 9902;
    private ?int $createdRoleId = null;
    private ?int $createdUserId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 绑定自定义 Request，确保 tenantId 行为一致
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 加载认证路由
        $routeFile = $this->app()->getRootPath() . 'route/auth.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        $this->cleanupData();
        $this->ensureTenant();
    }

    protected function tearDown(): void
    {
        $this->cleanupData();
        parent::tearDown();
    }

    /**
     * 核心请求辅助方法，复用自登录测试
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

    public function testRegisterCreatesTenantScopedUserRoleAndPermissions(): void
    {
        $email = 'mt-user-' . uniqid() . '@test.com';

        $response = $this->request(
            'POST',
            'v1/auth/register',
            [
                'username' => 'tenant_user_' . uniqid(),
                'email' => $email,
                'password' => 'secret123',
            ],
            [
                'X-Tenant-ID' => (string) $this->tenantId,
            ]
        );

        $this->assertSame(200, $response->getCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(0, $body['code']);

        $user = $body['data']['user'] ?? [];
        $this->createdUserId = $user['id'] ?? null;
        $this->assertNotNull($this->createdUserId);
        $this->assertSame($this->tenantId, $user['tenant_id']);

        // 验证租户级默认角色被创建
        $role = Db::name('roles')
            ->where('tenant_id', $this->tenantId)
            ->where('slug', 'user')
            ->find();
        $this->assertNotNull($role, '租户级默认角色未创建');
        $this->createdRoleId = (int) $role['id'];

        // 验证角色权限已复制
        $rolePerms = Db::name('role_permissions')
            ->where('role_id', $this->createdRoleId)
            ->select()
            ->toArray();
        $this->assertNotEmpty($rolePerms, '默认角色未复制权限');

        // 验证用户已绑定该角色
        $binding = Db::name('user_roles')
            ->where('user_id', $this->createdUserId)
            ->where('role_id', $this->createdRoleId)
            ->find();
        $this->assertNotNull($binding, '用户未绑定租户级默认角色');
    }

    protected function ensureTenant(): void
    {
        $exists = Db::name('tenants')->where('id', $this->tenantId)->find();
        if ($exists) {
            return;
        }

        Db::name('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'MT Test Tenant',
            'slug' => 'mt-test-tenant',
            'status' => 'active',
            'config' => json_encode([]),
            'max_sites' => 5,
            'max_users' => 50,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function cleanupData(): void
    {
        if ($this->createdUserId) {
            Db::name('user_roles')->where('user_id', $this->createdUserId)->delete();
            Db::name('users')->where('id', $this->createdUserId)->delete();
        }

        if ($this->createdRoleId) {
            Db::name('role_permissions')->where('role_id', $this->createdRoleId)->delete();
            Db::name('roles')->where('id', $this->createdRoleId)->delete();
        } else {
            // 如果已有租户级默认角色残留，清理之（测试隔离）
            $role = Db::name('roles')
                ->where('tenant_id', $this->tenantId)
                ->where('slug', 'user')
                ->find();
            if ($role) {
                Db::name('role_permissions')->where('role_id', $role['id'])->delete();
                Db::name('user_roles')->where('role_id', $role['id'])->delete();
                Db::name('roles')->where('id', $role['id'])->delete();
            }
        }

        Db::name('tenants')->where('id', $this->tenantId)->delete();
    }
}
