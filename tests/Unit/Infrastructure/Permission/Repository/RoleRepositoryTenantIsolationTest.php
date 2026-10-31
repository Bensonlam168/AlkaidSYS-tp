<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission\Repository;

use Domain\Permission\Exception\RoleWriteRejectedException;
use Domain\Permission\Model\Role;
use Infrastructure\Permission\Repository\RoleRepository;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * RoleRepository 租户隔离回归测试 | RoleRepository tenant isolation tests
 *
 * 这些用例是本次「租户隔离缺陷」修复的核心证据：
 *   1. findById 必须带租户，查不到别的租户的角色；
 *   2. delete 必须带租户，删不掉别的租户的角色（且该角色仍然存在）；
 *   3. save() 的更新分支必须按 id + tenant_id 双条件定位，跨租户更新要被拒绝并抛异常。
 *
 * 修复前 3 号用例会红（旧实现直接把 tenant_id 一起改掉），修复后全绿。
 */
final class RoleRepositoryTenantIsolationTest extends ThinkPHPTestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    protected RoleRepository $repository;

    /**
     * 本用例创建的角色 ID（tearDown 里兜底清理）
     *
     * @var int[]
     */
    protected array $createdRoleIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new RoleRepository();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRoleIds as $roleId) {
            Db::name('roles')->where('id', $roleId)->delete();
        }
        $this->createdRoleIds = [];

        parent::tearDown();
    }

    /**
     * 租户 B 用同一个 id 查询租户 A 的角色 -> 必须查不到
     */
    public function testFindByIdDoesNotCrossTenantBoundary(): void
    {
        $roleId = $this->createRoleForTenantA();

        // 同租户可查（正面对照，确认用例本身没写错）
        $own = $this->repository->findById($roleId, self::TENANT_A);
        $this->assertNotNull($own, '租户 A 应能查到自己的角色');
        $this->assertSame($roleId, $own->getId());

        // 跨租户查不到
        $this->assertNull(
            $this->repository->findById($roleId, self::TENANT_B),
            '租户 B 不得通过 findById 读到租户 A 的角色'
        );
    }

    /**
     * 租户 B 尝试删除租户 A 的角色 -> 必须不生效，且角色仍然存在
     */
    public function testDeleteDoesNotCrossTenantBoundary(): void
    {
        $roleId = $this->createRoleForTenantA();

        $deleted = $this->repository->delete($roleId, self::TENANT_B);

        $this->assertFalse($deleted, '租户 B 删除租户 A 的角色必须返回 false');
        $this->assertSame(
            1,
            Db::name('roles')->where('id', $roleId)->count(),
            '跨租户删除不得生效，角色必须仍然存在'
        );

        // 正面对照：本租户删除应当成功
        $this->assertTrue($this->repository->delete($roleId, self::TENANT_A));
        $this->assertSame(0, Db::name('roles')->where('id', $roleId)->count());
    }

    /**
     * 租户 B 用 save() 更新租户 A 的角色 -> 必须失败（抛异常），且数据库不得被改动
     */
    public function testSaveUpdateFromOtherTenantIsRejected(): void
    {
        $roleId = $this->createRoleForTenantA();
        $before = Db::name('roles')->where('id', $roleId)->find();

        // 租户 B 的写入上下文：实体 tenantId = 2，但 id 指向租户 A 的角色
        $intruder = new Role(self::TENANT_B, 'hijacked_by_tenant_b', 'hijacked_by_tenant_b');
        $intruder->setId($roleId);

        try {
            $this->repository->save($intruder);
            $this->fail('跨租户更新必须抛 RoleWriteRejectedException，不得静默成功');
        } catch (RoleWriteRejectedException $e) {
            $this->assertStringContainsString('禁止跨租户更新角色', $e->getMessage());
        }

        $after = Db::name('roles')->where('id', $roleId)->find();
        $this->assertSame($before['name'], $after['name'], '跨租户更新不得改动角色名');
        $this->assertSame($before['slug'], $after['slug'], '跨租户更新不得改动 slug');
        $this->assertSame(
            (int) $before['tenant_id'],
            (int) $after['tenant_id'],
            '跨租户更新不得把角色改挂到别的租户名下'
        );
    }

    /**
     * 更新一个不存在的角色 ID -> 同样要明确失败，不能当作成功
     */
    public function testSaveUpdateOfMissingRoleIsRejected(): void
    {
        $missingId = (int) Db::name('roles')->max('id') + 500000;
        $ghost = new Role(self::TENANT_A, 'ghost_role', 'ghost_role');
        $ghost->setId($missingId);

        $this->expectException(RoleWriteRejectedException::class);
        $this->expectExceptionMessage('不存在');

        $this->repository->save($ghost);
    }

    /**
     * 正面对照：同租户更新必须成功（防止修复把正常更新一起堵死）
     */
    public function testSaveUpdateInSameTenantSucceeds(): void
    {
        $roleId = $this->createRoleForTenantA();

        $role = new Role(self::TENANT_A, 'renamed_in_tenant_a', 'role_tenant_a');
        $role->setId($roleId);
        $savedId = $this->repository->save($role);

        $this->assertSame($roleId, $savedId);

        $row = Db::name('roles')->where('id', $roleId)->find();
        $this->assertSame('renamed_in_tenant_a', $row['name']);
        $this->assertSame(self::TENANT_A, (int) $row['tenant_id']);
    }

    /**
     * 正面对照：写入与原值完全相同的更新（MySQL 受影响行数为 0）不得被误判为越权
     */
    public function testSaveUpdateWithIdenticalValuesIsNotTreatedAsViolation(): void
    {
        $roleId = $this->createRoleForTenantA();

        $same = new Role(self::TENANT_A, 'role_tenant_a', 'role_tenant_a');
        $same->setId($roleId);

        // 不抛异常即通过；再确认数据确实没被改坏
        $this->assertSame($roleId, $this->repository->save($same));

        $row = Db::name('roles')->where('id', $roleId)->find();
        $this->assertSame('role_tenant_a', $row['name']);
        $this->assertSame(self::TENANT_A, (int) $row['tenant_id']);
    }

    /**
     * 造一条属于租户 A 的角色
     */
    private function createRoleForTenantA(): int
    {
        $roleId = (int) Db::name('roles')->insertGetId([
            'tenant_id' => self::TENANT_A,
            'name' => 'role_tenant_a',
            'slug' => 'role_tenant_a',
            'description' => 'belongs to tenant 1',
            'is_system' => false,
        ]);
        $this->createdRoleIds[] = $roleId;

        return $roleId;
    }
}
