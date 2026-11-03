<?php

declare(strict_types=1);

namespace Tests\Unit\User;

use Infrastructure\User\Repository\UserRepository;
use InvalidArgumentException;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * UserRepository 多租户隔离单元测试
 * 确保用户与角色的租户一致性、查询隔离。
 */
class UserRepositoryTest extends ThinkPHPTestCase
{
    protected UserRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new UserRepository();
        $this->prepareData();
    }

    protected function tearDown(): void
    {
        $this->cleanupData();
        parent::tearDown();
    }

    public function testAssignRoleRejectsCrossTenant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('禁止跨租户授权角色');

        $this->repo->assignRole(8101, 7202);
    }

    public function testAssignRoleAllowsSameTenant(): void
    {
        $this->repo->assignRole(8101, 7201);

        $exists = Db::name('user_roles')
            ->where('user_id', 8101)
            ->where('role_id', 7201)
            ->find();

        $this->assertNotNull($exists);
    }

    public function testFindByIdWithTenantScope(): void
    {
        $user = $this->repo->findById(8101, 1);
        $this->assertNotNull($user);

        $wrong = $this->repo->findById(8101, 2);
        $this->assertNull($wrong);
    }

    protected function prepareData(): void
    {
        $this->cleanupData();

        // 确保测试所需租户存在（避免外键约束导致插入失败）
        // Ensure required tenants exist (avoid FK insertion failures)
        $this->ensureTenantExists(1, 'Test Tenant 1', 'test-tenant-1');
        $this->ensureTenantExists(2, 'Test Tenant 2', 'test-tenant-2');

        Db::name('roles')->insertAll([
            ['id' => 7201, 'tenant_id' => 1, 'name' => 't1_admin', 'slug' => 't1_admin'],
            ['id' => 7202, 'tenant_id' => 2, 'name' => 't2_admin', 'slug' => 't2_admin'],
        ]);

        Db::name('users')->insert([
            'id' => 8101,
            'tenant_id' => 1,
            'username' => 'u1',
            'email' => 'u1@test.com',
            'password' => 'pwd',
            'status' => 'active',
        ]);
    }

    protected function ensureTenantExists(int $id, string $name, string $slug): void
    {
        $exists = Db::name('tenants')->where('id', $id)->find();
        if ($exists) {
            return;
        }

        Db::name('tenants')->insert([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'config' => json_encode([]),
            'max_sites' => 10,
            'max_users' => 100,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function cleanupData(): void
    {
        Db::name('user_roles')->where('user_id', 8101)->delete();
        Db::name('roles')->whereIn('id', [7201, 7202])->delete();
        Db::name('users')->where('id', 8101)->delete();
    }
}
