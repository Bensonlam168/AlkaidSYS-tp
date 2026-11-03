<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\ThinkPHPTestCase;
use think\facade\Db;
use think\facade\Env;

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

        // 清除容器中可能被上一个用例污染的 Request 实例
        //
        // 背景：`tests/ThinkPHPTestCase` 复用同一个 App 实例，think-container 的 make()
        // 会缓存实例且 bind() 后不解绑，导致本用例拿到上一个用例的 Request（携带其
        // tenantId/siteId/userId/pathinfo），从而出现与本用例无关的 401/403。
        // 这是已知的测试隔离缺陷，此处做局部规避。
        try {
            $this->app()->delete(\think\Request::class);
        } catch (\Throwable $e) {
            // 容器未注册时忽略
        }

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
        // 清理注册租户配置，避免污染其他用例
        Env::set('REGISTRATION_TENANT_ID', '');
        $this->cleanupData();
        parent::tearDown();
    }

    /**
     * 核心请求辅助方法，复用自登录测试
     */
    protected function request(string $method, string $uri, array $data = [], array $headers = [])
    {
        // 直接构造全新的 app\Request，而非从容器 make()。
        //
        // 原因：容器中的 Request 实例会残留上一个用例的路由规则（rule），
        // 导致本请求被套用错误的路由中间件（例如误触发 Auth → 401）。
        // 这是已知的测试隔离缺陷；此处以「每次新建实例」方式规避。
        $request = new \app\Request();
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

        // 安全（C-4）：注册目标租户不再取自 X-Tenant-ID，
        // 改由服务端配置 REGISTRATION_TENANT_ID 指定。
        Env::set('REGISTRATION_TENANT_ID', (string) $this->tenantId);

        $response = $this->request(
            'POST',
            'v1/auth/register',
            [
                'username' => 'tenant_user_' . uniqid(),
                'email' => $email,
                'password' => 'secret123',
            ],
            []
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
