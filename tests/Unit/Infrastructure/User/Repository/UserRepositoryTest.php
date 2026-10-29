<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\User\Repository;

use Domain\User\Model\User;
use Infrastructure\User\Repository\UserRepository;
use InvalidArgumentException;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * UserRepository 单元测试
 * Unit tests for UserRepository
 */
class UserRepositoryTest extends ThinkPHPTestCase
{
    protected UserRepository $repository;
    protected int $testTenantId = 1;
    protected array $createdUserIds = [];
    protected array $createdRoleIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new UserRepository();
    }

    protected function tearDown(): void
    {
        // 清理测试数据 | Clean up test data
        foreach ($this->createdUserIds as $userId) {
            Db::name('user_roles')->where('user_id', $userId)->delete();
            Db::name('users')->where('id', $userId)->delete();
        }

        foreach ($this->createdRoleIds as $roleId) {
            Db::name('roles')->where('id', $roleId)->delete();
        }

        parent::tearDown();
    }

    /**
     * 创建测试用户
     * Create test user
     */
    protected function createTestUser(string $username = 'testuser', string $email = 'test@example.com'): User
    {
        $user = new User(
            $this->testTenantId,
            $username,
            $email,
            password_hash('password123', PASSWORD_BCRYPT)
        );
        $user->setName('Test User');
        $user->setStatus('active');

        $userId = $this->repository->save($user);
        $this->createdUserIds[] = $userId;

        return $this->repository->findById($userId, $this->testTenantId);
    }

    /**
     * 创建测试角色
     * Create test role
     */
    protected function createTestRole(string $name = 'TestRole', string $slug = 'test_role'): int
    {
        $roleId = Db::name('roles')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'name' => $name,
            'slug' => $slug,
            'description' => 'Test role description',
            'is_system' => false,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->createdRoleIds[] = $roleId;

        return $roleId;
    }

    // ==================== save() 测试 ====================

    /**
     * @test
     */
    public function testSaveCreatesNewUser(): void
    {
        $user = new User(
            $this->testTenantId,
            'newuser_' . uniqid(),
            'newuser_' . uniqid() . '@example.com',
            password_hash('password123', PASSWORD_BCRYPT)
        );
        $user->setName('New User');

        $userId = $this->repository->save($user);
        $this->createdUserIds[] = $userId;

        $this->assertGreaterThan(0, $userId);

        $savedUser = $this->repository->findById($userId, $this->testTenantId);
        $this->assertNotNull($savedUser);
        $this->assertEquals($user->getUsername(), $savedUser->getUsername());
        $this->assertEquals($user->getEmail(), $savedUser->getEmail());
    }

    /**
     * @test
     */
    public function testSaveUpdatesExistingUser(): void
    {
        $user = $this->createTestUser('updatetest_' . uniqid(), 'updatetest_' . uniqid() . '@example.com');
        $originalId = $user->getId();

        $user->setName('Updated Name');
        $this->repository->save($user);

        $updatedUser = $this->repository->findById($originalId, $this->testTenantId);
        $this->assertEquals('Updated Name', $updatedUser->getName());
    }

    // ==================== findById() 测试 ====================

    /**
     * @test
     */
    public function testFindByIdReturnsUserWhenExists(): void
    {
        $user = $this->createTestUser('findbyid_' . uniqid(), 'findbyid_' . uniqid() . '@example.com');

        $foundUser = $this->repository->findById($user->getId(), $this->testTenantId);

        $this->assertNotNull($foundUser);
        $this->assertEquals($user->getId(), $foundUser->getId());
        $this->assertEquals($user->getUsername(), $foundUser->getUsername());
    }

    /**
     * @test
     */
    public function testFindByIdReturnsNullWhenNotExists(): void
    {
        $foundUser = $this->repository->findById(999999, $this->testTenantId);

        $this->assertNull($foundUser);
    }

    /**
     * @test
     */
    public function testFindByIdRespectsTenatIsolation(): void
    {
        $user = $this->createTestUser('tenanttest_' . uniqid(), 'tenanttest_' . uniqid() . '@example.com');

        // 使用不同的租户ID查询，应该返回 null
        $foundUser = $this->repository->findById($user->getId(), 999);

        $this->assertNull($foundUser);
    }

    // ==================== findByEmail() 测试 ====================

    /**
     * @test
     */
    public function testFindByEmailReturnsUserWhenExists(): void
    {
        $email = 'findbyemail_' . uniqid() . '@example.com';
        $user = $this->createTestUser('findbyemail_' . uniqid(), $email);

        $foundUser = $this->repository->findByEmail($this->testTenantId, $email);

        $this->assertNotNull($foundUser);
        $this->assertEquals($email, $foundUser->getEmail());
    }

    /**
     * @test
     */
    public function testFindByEmailReturnsNullWhenNotExists(): void
    {
        $foundUser = $this->repository->findByEmail($this->testTenantId, 'nonexistent@example.com');

        $this->assertNull($foundUser);
    }

    // ==================== findByUsername() 测试 ====================

    /**
     * @test
     */
    public function testFindByUsernameReturnsUserWhenExists(): void
    {
        $username = 'findbyusername_' . uniqid();
        $user = $this->createTestUser($username, 'findbyusername_' . uniqid() . '@example.com');

        $foundUser = $this->repository->findByUsername($this->testTenantId, $username);

        $this->assertNotNull($foundUser);
        $this->assertEquals($username, $foundUser->getUsername());
    }

    /**
     * @test
     */
    public function testFindByUsernameReturnsNullWhenNotExists(): void
    {
        $foundUser = $this->repository->findByUsername($this->testTenantId, 'nonexistent_user');

        $this->assertNull($foundUser);
    }

    // ==================== list() 测试 ====================

    /**
     * @test
     */
    public function testListReturnsUsersWithPagination(): void
    {
        // 创建测试用户
        $prefix = 'listtest_' . uniqid() . '_';
        $this->createTestUser($prefix . '1', $prefix . '1@example.com');
        $this->createTestUser($prefix . '2', $prefix . '2@example.com');
        $this->createTestUser($prefix . '3', $prefix . '3@example.com');

        $result = $this->repository->list($this->testTenantId, ['keyword' => $prefix], 1, 2);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('page_size', $result);
        $this->assertLessThanOrEqual(2, count($result['items']));
        $this->assertEquals(1, $result['page']);
        $this->assertEquals(2, $result['page_size']);
    }

    /**
     * @test
     */
    public function testListFiltersUsersByKeyword(): void
    {
        $uniquePrefix = 'keywordtest_' . uniqid() . '_';
        $this->createTestUser($uniquePrefix . 'user1', $uniquePrefix . 'user1@example.com');
        $this->createTestUser($uniquePrefix . 'user2', $uniquePrefix . 'user2@example.com');

        $result = $this->repository->list($this->testTenantId, ['keyword' => $uniquePrefix], 1, 100);

        $this->assertGreaterThanOrEqual(2, $result['total']);
        foreach ($result['items'] as $user) {
            $this->assertStringContainsString($uniquePrefix, $user->getUsername());
        }
    }

    /**
     * @test
     */
    public function testListFiltersUsersByStatus(): void
    {
        $prefix = 'statustest_' . uniqid() . '_';
        $activeUser = $this->createTestUser($prefix . 'active', $prefix . 'active@example.com');
        $inactiveUser = $this->createTestUser($prefix . 'inactive', $prefix . 'inactive@example.com');

        // 更新一个用户为 inactive
        $this->repository->updateStatus($inactiveUser->getId(), $this->testTenantId, 'inactive');

        $result = $this->repository->list($this->testTenantId, ['keyword' => $prefix, 'status' => 'active'], 1, 100);

        foreach ($result['items'] as $user) {
            $this->assertEquals('active', $user->getStatus());
        }
    }

    // ==================== delete() 测试 ====================

    /**
     * @test
     */
    public function testDeleteRemovesUser(): void
    {
        $user = $this->createTestUser('deletetest_' . uniqid(), 'deletetest_' . uniqid() . '@example.com');
        $userId = $user->getId();

        $result = $this->repository->delete($userId, $this->testTenantId);

        $this->assertTrue($result);

        $foundUser = $this->repository->findById($userId, $this->testTenantId);
        $this->assertNull($foundUser);

        // 从追踪列表中移除，因为已经删除
        $this->createdUserIds = array_filter($this->createdUserIds, fn ($id) => $id !== $userId);
    }

    /**
     * @test
     */
    public function testDeleteRespectsTenatIsolation(): void
    {
        $user = $this->createTestUser('deletetenanttest_' . uniqid(), 'deletetenanttest_' . uniqid() . '@example.com');

        // 使用不同的租户ID删除，应该失败
        $result = $this->repository->delete($user->getId(), 999);

        $this->assertFalse($result);

        // 用户应该仍然存在
        $foundUser = $this->repository->findById($user->getId(), $this->testTenantId);
        $this->assertNotNull($foundUser);
    }

    // ==================== setRoles() 测试 ====================

    /**
     * @test
     */
    public function testSetRolesAssignsRolesToUser(): void
    {
        $user = $this->createTestUser('setroles_' . uniqid(), 'setroles_' . uniqid() . '@example.com');
        $roleId1 = $this->createTestRole('SetRolesTest1_' . uniqid(), 'set_roles_test1_' . uniqid());
        $roleId2 = $this->createTestRole('SetRolesTest2_' . uniqid(), 'set_roles_test2_' . uniqid());

        $this->repository->setRoles($user->getId(), [$roleId1, $roleId2]);

        $roleIds = $this->repository->getRoleIds($user->getId());

        $this->assertCount(2, $roleIds);
        $this->assertContains($roleId1, $roleIds);
        $this->assertContains($roleId2, $roleIds);
    }

    /**
     * @test
     */
    public function testSetRolesReplacesExistingRoles(): void
    {
        $user = $this->createTestUser('replaceroles_' . uniqid(), 'replaceroles_' . uniqid() . '@example.com');
        $roleId1 = $this->createTestRole('ReplaceTest1_' . uniqid(), 'replace_test1_' . uniqid());
        $roleId2 = $this->createTestRole('ReplaceTest2_' . uniqid(), 'replace_test2_' . uniqid());
        $roleId3 = $this->createTestRole('ReplaceTest3_' . uniqid(), 'replace_test3_' . uniqid());

        // 先设置两个角色
        $this->repository->setRoles($user->getId(), [$roleId1, $roleId2]);

        // 再设置一个新角色，应该替换原有角色
        $this->repository->setRoles($user->getId(), [$roleId3]);

        $roleIds = $this->repository->getRoleIds($user->getId());

        $this->assertCount(1, $roleIds);
        $this->assertContains($roleId3, $roleIds);
        $this->assertNotContains($roleId1, $roleIds);
        $this->assertNotContains($roleId2, $roleIds);
    }

    /**
     * @test
     * @group integration
     * Note: This test requires a real tenant with ID 999 to exist due to FK constraint
     * 注意：由于外键约束，此测试需要数据库中存在 tenant_id=999 的租户
     */
    public function testSetRolesThrowsExceptionForCrossTenantRole(): void
    {
        $this->markTestSkipped('Requires integration test environment with multiple tenants');
    }

    /**
     * @test
     */
    public function testSetRolesThrowsExceptionForNonExistentUser(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('用户不存在');

        $this->repository->setRoles(999999, [1]);
    }

    // ==================== removeRole() 测试 ====================

    /**
     * @test
     */
    public function testRemoveRoleRemovesSingleRole(): void
    {
        $user = $this->createTestUser('removerole_' . uniqid(), 'removerole_' . uniqid() . '@example.com');
        $roleId1 = $this->createTestRole('RemoveRoleTest1_' . uniqid(), 'remove_role_test1_' . uniqid());
        $roleId2 = $this->createTestRole('RemoveRoleTest2_' . uniqid(), 'remove_role_test2_' . uniqid());

        $this->repository->setRoles($user->getId(), [$roleId1, $roleId2]);

        $result = $this->repository->removeRole($user->getId(), $roleId1);

        $this->assertTrue($result);

        $roleIds = $this->repository->getRoleIds($user->getId());
        $this->assertCount(1, $roleIds);
        $this->assertNotContains($roleId1, $roleIds);
        $this->assertContains($roleId2, $roleIds);
    }

    // ==================== emailExists() 测试 ====================

    /**
     * @test
     */
    public function testEmailExistsReturnsTrueWhenExists(): void
    {
        $email = 'emailexists_' . uniqid() . '@example.com';
        $this->createTestUser('emailexists_' . uniqid(), $email);

        $result = $this->repository->emailExists($this->testTenantId, $email);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testEmailExistsReturnsFalseWhenNotExists(): void
    {
        $result = $this->repository->emailExists($this->testTenantId, 'nonexistent_' . uniqid() . '@example.com');

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testEmailExistsExcludesSpecificUser(): void
    {
        $email = 'emailexcludes_' . uniqid() . '@example.com';
        $user = $this->createTestUser('emailexcludes_' . uniqid(), $email);

        // 排除该用户后，应该返回 false
        $result = $this->repository->emailExists($this->testTenantId, $email, $user->getId());

        $this->assertFalse($result);
    }

    // ==================== usernameExists() 测试 ====================

    /**
     * @test
     */
    public function testUsernameExistsReturnsTrueWhenExists(): void
    {
        $username = 'usernameexists_' . uniqid();
        $this->createTestUser($username, 'usernameexists_' . uniqid() . '@example.com');

        $result = $this->repository->usernameExists($this->testTenantId, $username);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testUsernameExistsReturnsFalseWhenNotExists(): void
    {
        $result = $this->repository->usernameExists($this->testTenantId, 'nonexistent_' . uniqid());

        $this->assertFalse($result);
    }

    // ==================== updatePassword() 测试 ====================

    /**
     * @test
     */
    public function testUpdatePasswordChangesPassword(): void
    {
        $user = $this->createTestUser('updatepwd_' . uniqid(), 'updatepwd_' . uniqid() . '@example.com');
        $newHashedPassword = password_hash('newpassword123', PASSWORD_BCRYPT);

        $result = $this->repository->updatePassword($user->getId(), $this->testTenantId, $newHashedPassword);

        $this->assertTrue($result);

        $updatedUser = $this->repository->findById($user->getId(), $this->testTenantId);
        $this->assertTrue(password_verify('newpassword123', $updatedUser->getPassword()));
    }

    // ==================== updateStatus() 测试 ====================

    /**
     * @test
     */
    public function testUpdateStatusChangesStatus(): void
    {
        $user = $this->createTestUser('updatestatus_' . uniqid(), 'updatestatus_' . uniqid() . '@example.com');

        $result = $this->repository->updateStatus($user->getId(), $this->testTenantId, 'inactive');

        $this->assertTrue($result);

        $updatedUser = $this->repository->findById($user->getId(), $this->testTenantId);
        $this->assertEquals('inactive', $updatedUser->getStatus());
    }

    /**
     * @test
     */
    public function testUpdateStatusThrowsExceptionForInvalidStatus(): void
    {
        $user = $this->createTestUser('invalidstatus_' . uniqid(), 'invalidstatus_' . uniqid() . '@example.com');

        $this->expectException(InvalidArgumentException::class);

        $this->repository->updateStatus($user->getId(), $this->testTenantId, 'invalid_status');
    }

    // ==================== findWithRoles() 测试 ====================

    /**
     * @test
     */
    public function testFindWithRolesReturnsUserAndRoles(): void
    {
        $user = $this->createTestUser('findwithroles_' . uniqid(), 'findwithroles_' . uniqid() . '@example.com');
        $roleId = $this->createTestRole('FindWithRolesTest_' . uniqid(), 'find_with_roles_' . uniqid());

        $this->repository->setRoles($user->getId(), [$roleId]);

        $result = $this->repository->findWithRoles($user->getId(), $this->testTenantId);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('roles', $result);
        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertIsArray($result['roles']);
        $this->assertCount(1, $result['roles']);
    }

    /**
     * @test
     */
    public function testFindWithRolesReturnsNullWhenUserNotExists(): void
    {
        $result = $this->repository->findWithRoles(999999, $this->testTenantId);

        $this->assertNull($result);
    }

    // ==================== assignRole() 测试 ====================

    /**
     * @test
     */
    public function testAssignRoleAddsRoleToUser(): void
    {
        $user = $this->createTestUser('assignrole_' . uniqid(), 'assignrole_' . uniqid() . '@example.com');
        $roleId = $this->createTestRole('AssignRoleTest_' . uniqid(), 'assign_role_' . uniqid());

        $this->repository->assignRole($user->getId(), $roleId);

        $roleIds = $this->repository->getRoleIds($user->getId());
        $this->assertContains($roleId, $roleIds);
    }

    /**
     * @test
     */
    public function testAssignRoleDoesNotDuplicateRoles(): void
    {
        $user = $this->createTestUser('assignroledup_' . uniqid(), 'assignroledup_' . uniqid() . '@example.com');
        $roleId = $this->createTestRole('AssignRoleDupTest_' . uniqid(), 'assign_role_dup_' . uniqid());

        // 分配两次同一个角色
        $this->repository->assignRole($user->getId(), $roleId);
        $this->repository->assignRole($user->getId(), $roleId);

        $roleIds = $this->repository->getRoleIds($user->getId());
        $this->assertCount(1, $roleIds);
    }

    /**
     * @test
     * @group integration
     * Note: This test requires a real tenant with ID 999 to exist due to FK constraint
     * 注意：由于外键约束，此测试需要数据库中存在 tenant_id=999 的租户
     */
    public function testAssignRoleThrowsExceptionForCrossTenantRole(): void
    {
        $this->markTestSkipped('Requires integration test environment with multiple tenants');
    }
}
