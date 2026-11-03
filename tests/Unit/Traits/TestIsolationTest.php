<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\Factories\TestDataFactory;
use Tests\ThinkPHPTestCase;
use Tests\Traits\DatabaseTransactions;
use think\facade\Db;

/**
 * 测试隔离功能测试
 * Test Isolation Feature Tests
 *
 * 测试数据库事务回滚、缓存命名空间和测试数据工厂的功能。
 * Test database transaction rollback, cache namespace, and test data factory features.
 *
 * 注意：本类**刻意保留**自定义的 setUp() / tearDown()（ER-16 回归场景）。
 * 旧实现把事务钩子写成 trait 内的 setUp()/tearDown()，会被这里的同名方法覆盖而静默失效；
 * 改用 #[Before] / #[After] 后，钩子与本类的 setUp()/tearDown() 并存且都生效。
 */
class TestIsolationTest extends ThinkPHPTestCase
{
    use DatabaseTransactions;

    protected TestDataFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new TestDataFactory();
    }

    protected function tearDown(): void
    {
        // 防空：#[Before] 若在 setUp() 之前就抛异常（例如连不上测试库），$factory 尚未赋值
        if (isset($this->factory)) {
            $this->factory->cleanup();
        }

        parent::tearDown();
    }

    /**
     * 测试数据库事务回滚
     * Test database transaction rollback
     *
     * 验证测试中创建的数据在测试结束后自动回滚。
     * Verify that data created in test is automatically rolled back after test completion.
     */
    public function testDatabaseTransactionRollback(): void
    {
        // 前置断言：事务钩子必须已经开启事务（#[Before] 早于方法体）
        // 若钩子被影子覆盖 / #[Before] 被删，这里先红，不必等到库里出现脏数据
        $this->assertTransactionIsActive('测试方法体开始时');

        // 创建测试用户
        // Create test user
        $userId = Db::table('users')->insertGetId([
            'tenant_id' => 1,
            'username' => 'transaction_test_user',
            'email' => 'transaction_test@test.com',
            'password' => 'test',
            'status' => 'active',
        ]);

        // 验证用户已创建
        // Verify user is created
        $user = Db::table('users')->where('id', $userId)->find();
        $this->assertNotNull($user);
        $this->assertEquals('transaction_test_user', $user['username']);

        // 证据：方法体执行期间，数据在**当前连接**里确实存在（count 为 1 而不是 0）
        // 结合验收要求的「跑前 0 / 跑后 0」，这证明写入发生过、并且只存在于事务内
        $this->assertSame(
            1,
            Db::table('users')->where('username', 'transaction_test_user')->count(),
            '事务内应当能读到刚插入的数据'
        );

        // 写入之后事务仍未结束（没有中途被隐式提交）
        $this->assertTransactionIsActive('测试方法体插入数据之后');

        // 测试结束后，数据会自动回滚
        // After test completion, data will be automatically rolled back
    }

    /**
     * 测试测试数据工厂
     * Test test data factory
     *
     * 验证测试数据工厂能够创建和清理数据。
     * Verify that test data factory can create and cleanup data.
     */
    public function testTestDataFactory(): void
    {
        $this->assertTransactionIsActive('测试方法体开始时');

        // 使用工厂创建用户
        // Create user using factory
        $userId = $this->factory->createUser([
            'username' => 'factory_test_user',
            'email' => 'factory_test@test.com',
        ]);

        // 验证用户已创建
        // Verify user is created
        $user = Db::table('users')->where('id', $userId)->find();
        $this->assertNotNull($user);
        $this->assertEquals('factory_test_user', $user['username']);

        // 创建角色
        // Create role
        $roleId = $this->factory->createRole([
            'name' => 'Factory Test Role',
            'slug' => 'factory_test_role',
        ]);

        // 分配用户角色
        // Assign user role
        $this->factory->assignUserRole($userId, $roleId);

        // 验证角色分配
        // Verify role assignment
        $userRole = Db::table('user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->find();
        $this->assertNotNull($userRole);

        // 验证工厂记录了创建的数据
        // Verify factory recorded created data
        $this->assertGreaterThan(0, $this->factory->getCreatedCount());

        // tearDown() 会自动清理数据
        // tearDown() will automatically cleanup data
    }

    /**
     * 测试批量创建
     * Test batch creation
     *
     * 验证测试数据工厂能够批量创建数据。
     * Verify that test data factory can batch create data.
     */
    public function testBatchCreation(): void
    {
        $this->assertTransactionIsActive('测试方法体开始时');

        // 批量创建用户
        // Batch create users
        $userIds = $this->factory->createMany('users', [
            [
                'tenant_id' => 1,
                'username' => 'batch_user_1',
                'email' => 'batch1@test.com',
                'password' => 'test',
                'status' => 'active',
            ],
            [
                'tenant_id' => 1,
                'username' => 'batch_user_2',
                'email' => 'batch2@test.com',
                'password' => 'test',
                'status' => 'active',
            ],
        ]);

        // 验证创建了 2 个用户
        // Verify 2 users are created
        $this->assertCount(2, $userIds);

        // 验证用户存在
        // Verify users exist
        foreach ($userIds as $userId) {
            $user = Db::table('users')->where('id', $userId)->find();
            $this->assertNotNull($user);
        }
    }

    /**
     * 测试级联删除
     * Test cascade deletion
     *
     * 验证测试数据工厂能够按正确的顺序删除数据（处理外键约束）。
     * Verify that test data factory can delete data in correct order (handle foreign key constraints).
     */
    public function testCascadeDeletion(): void
    {
        $this->assertTransactionIsActive('测试方法体开始时');

        // 创建用户、角色、权限
        // Create user, role, permission
        $userId = $this->factory->createUser();
        $roleId = $this->factory->createRole();
        $permissionId = $this->factory->createPermission();

        // 分配关联
        // Assign associations
        $this->factory->assignUserRole($userId, $roleId);
        $this->factory->assignRolePermission($roleId, $permissionId);

        // 验证数据存在
        // Verify data exists
        $this->assertNotNull(Db::table('users')->where('id', $userId)->find());
        $this->assertNotNull(Db::table('roles')->where('id', $roleId)->find());
        $this->assertNotNull(Db::table('permissions')->where('id', $permissionId)->find());

        // 手动清理（模拟 tearDown）
        // Manual cleanup (simulate tearDown)
        $this->factory->cleanup();

        // 验证数据已删除（由于使用了事务，这里实际上数据还在，但工厂记录已清空）
        // Verify data is deleted (due to transaction, data is actually still there, but factory records are cleared)
        $this->assertEquals(0, $this->factory->getCreatedCount());
    }

    /**
     * 结构性回归防线：trait 必须用 #[Before]/#[After] 挂载钩子，且不得再声明 setUp()/tearDown()
     *
     * 这一条防的是「钩子静默失效」这个失败模式本身，而不是它的后果：
     * 只要有人把 #[Before] 属性删掉、或把钩子改回 setUp()/tearDown() 的写法，本用例立即变红，
     * 不需要等到测试库里出现 transaction_test_user 之类的脏数据才发现。
     */
    public function testTraitDeclaresHookAttributes(): void
    {
        $beforeMethod = new ReflectionMethod(DatabaseTransactions::class, 'beginDatabaseTransactionHook');
        $afterMethod = new ReflectionMethod(DatabaseTransactions::class, 'finishDatabaseTransactionHook');

        $this->assertNotEmpty(
            $beforeMethod->getAttributes(Before::class),
            'beginDatabaseTransactionHook() 必须带 #[Before]，否则事务不会在测试方法体之前开启'
        );
        $this->assertNotEmpty(
            $afterMethod->getAttributes(After::class),
            'finishDatabaseTransactionHook() 必须带 #[After]，否则测试结束后不会回滚'
        );

        // final：防止测试类声明同名方法把钩子顶掉
        $this->assertTrue($beforeMethod->isFinal(), '钩子方法必须 final，避免被同名方法覆盖');
        $this->assertTrue($afterMethod->isFinal(), '钩子方法必须 final，避免被同名方法覆盖');

        // 禁止回到「trait 声明 setUp()/tearDown()」的旧写法：那条路径会被测试类的同名方法静默覆盖
        $traitReflection = new ReflectionClass(DatabaseTransactions::class);
        $this->assertFalse(
            $traitReflection->hasMethod('setUp'),
            'trait 不得声明 setUp()：它会被测试类自己的 setUp() 覆盖，导致事务钩子静默失效（ER-16）'
        );
        $this->assertFalse(
            $traitReflection->hasMethod('tearDown'),
            'trait 不得声明 tearDown()：它会被测试类自己的 tearDown() 覆盖，导致回滚钩子静默失效（ER-16）'
        );
    }

    /**
     * MySQL 隐式提交检测：事务被「脱离」后必须抛异常，而不是静默跳过回滚
     *
     * 用 Db::commit() 模拟 DDL 隐式提交后的状态：ThinkPHP 的 transTimes 仍记为 1，
     * 但 PDO/服务端已经不在事务中。真实 DDL 触发时走的就是这条路径。
     * 本用例不实际执行 DDL，避免真的污染测试库。
     */
    public function testImplicitlyCommittedTransactionIsDetected(): void
    {
        $this->assertTransactionIsActive('前提：钩子已开启事务');

        // 模拟隐式提交：直接提交掉当前事务（本用例没有写入，不会留下数据）
        Db::commit();
        $this->assertFalse(Db::getPdo()->inTransaction(), '前提：commit 之后连接已脱离事务');

        // 真实场景里这一步由 #[After] 自动执行，这里手动调用以便断言异常内容
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('隐式提交');

        $this->tearDownDatabaseTransaction();
    }

    /**
     * 断言当前连接确实处在事务中
     *
     * 刻意不调用 trait 内部的判定方法，而是就地用 PDO::inTransaction() 复核，
     * 避免「用实现验证实现」。
     *
     * @param string $context 断言发生的时机（出现在失败信息里，便于定位）
     */
    private function assertTransactionIsActive(string $context): void
    {
        $pdo = Db::getPdo();

        $this->assertInstanceOf(PDO::class, $pdo, $context . '：无法取得 PDO 连接');
        $this->assertTrue(
            $pdo->inTransaction(),
            $context . '：连接不在事务中 —— DatabaseTransactions 的事务隔离未生效（钩子没跑或被隐式提交）'
        );
    }
}
