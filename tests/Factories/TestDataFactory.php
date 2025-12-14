<?php

declare(strict_types=1);

namespace Tests\Factories;

use think\facade\Db;

/**
 * 测试数据工厂
 * Test Data Factory
 *
 * 提供统一的测试数据创建和清理机制，自动跟踪创建的数据并在测试结束后清理。
 * Provides unified test data creation and cleanup mechanism, automatically tracking created data and cleaning up after test completion.
 *
 * 使用方法 | Usage:
 * ```php
 * class MyTest extends ThinkPHPTestCase
 * {
 *     protected TestDataFactory $factory;
 *
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->factory = new TestDataFactory();
 *     }
 *
 *     protected function tearDown(): void
 *     {
 *         $this->factory->cleanup();
 *         parent::tearDown();
 *     }
 *
 *     public function testSomething()
 *     {
 *         $userId = $this->factory->create('users', ['username' => 'test']);
 *         // 测试代码
 *         // Test code
 *     }
 * }
 * ```
 *
 * 特性 | Features:
 * - 自动跟踪创建的数据
 * - Automatically track created data
 * - 支持级联删除（按创建顺序的逆序删除）
 * - Support cascade deletion (delete in reverse order of creation)
 * - 支持批量创建
 * - Support batch creation
 */
class TestDataFactory
{
    /**
     * 创建的数据记录
     * Created data records
     *
     * 格式：[['table' => 'users', 'id' => 1], ...]
     * Format: [['table' => 'users', 'id' => 1], ...]
     *
     * @var array
     */
    protected array $createdRecords = [];

    /**
     * 创建单条数据
     * Create single record
     *
     * @param string $table 表名 | Table name
     * @param array $data 数据 | Data
     * @return int 插入的 ID | Inserted ID
     */
    public function create(string $table, array $data): int
    {
        // 插入数据
        // Insert data
        $id = Db::table($table)->insertGetId($data);

        // 记录创建的数据
        // Record created data
        $this->createdRecords[] = [
            'table' => $table,
            'id' => $id,
        ];

        return $id;
    }

    /**
     * 批量创建数据
     * Batch create records
     *
     * @param string $table 表名 | Table name
     * @param array $dataList 数据列表 | Data list
     * @return array 插入的 ID 列表 | Inserted ID list
     */
    public function createMany(string $table, array $dataList): array
    {
        $ids = [];

        foreach ($dataList as $data) {
            $ids[] = $this->create($table, $data);
        }

        return $ids;
    }

    /**
     * 创建用户
     * Create user
     *
     * @param array $attributes 用户属性 | User attributes
     * @return int 用户 ID | User ID
     */
    public function createUser(array $attributes = []): int
    {
        $defaults = [
            'tenant_id' => 1,
            'username' => 'test_user_' . uniqid(),
            'email' => 'test_' . uniqid() . '@test.com',
            'password' => 'test_password',
            'status' => 'active',
        ];

        $data = array_merge($defaults, $attributes);

        return $this->create('users', $data);
    }

    /**
     * 创建角色
     * Create role
     *
     * @param array $attributes 角色属性 | Role attributes
     * @return int 角色 ID | Role ID
     */
    public function createRole(array $attributes = []): int
    {
        $defaults = [
            'tenant_id' => 1,
            'name' => 'Test Role ' . uniqid(),
            'slug' => 'test_role_' . uniqid(),
            'description' => 'Test Role',
        ];

        $data = array_merge($defaults, $attributes);

        return $this->create('roles', $data);
    }

    /**
     * 创建权限
     * Create permission
     *
     * @param array $attributes 权限属性 | Permission attributes
     * @return int 权限 ID | Permission ID
     */
    public function createPermission(array $attributes = []): int
    {
        $defaults = [
            'name' => 'Test Permission ' . uniqid(),
            'slug' => 'test_permission_' . uniqid(),
            'resource' => 'test_resource',
            'action' => 'test_action',
            'description' => 'Test Permission',
        ];

        $data = array_merge($defaults, $attributes);

        return $this->create('permissions', $data);
    }

    /**
     * 分配用户角色
     * Assign user role
     *
     * @param int $userId 用户 ID | User ID
     * @param int $roleId 角色 ID | Role ID
     * @return void
     */
    public function assignUserRole(int $userId, int $roleId): void
    {
        Db::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);

        // 记录创建的关联（使用复合键）
        // Record created association (using composite key)
        $this->createdRecords[] = [
            'table' => 'user_roles',
            'user_id' => $userId,
            'role_id' => $roleId,
        ];
    }

    /**
     * 分配角色权限
     * Assign role permission
     *
     * @param int $roleId 角色 ID | Role ID
     * @param int $permissionId 权限 ID | Permission ID
     * @return void
     */
    public function assignRolePermission(int $roleId, int $permissionId): void
    {
        Db::table('role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);

        // 记录创建的关联（使用复合键）
        // Record created association (using composite key)
        $this->createdRecords[] = [
            'table' => 'role_permissions',
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ];
    }

    /**
     * 清理所有创建的数据
     * Cleanup all created data
     *
     * 按创建顺序的逆序删除，以处理外键约束
     * Delete in reverse order of creation to handle foreign key constraints
     *
     * @return void
     */
    public function cleanup(): void
    {
        // 逆序删除
        // Delete in reverse order
        $records = array_reverse($this->createdRecords);

        foreach ($records as $record) {
            $table = $record['table'];

            // 根据表的不同使用不同的删除条件
            // Use different delete conditions based on table
            if (isset($record['id'])) {
                // 单主键表
                // Single primary key table
                Db::table($table)->where('id', $record['id'])->delete();
            } elseif (isset($record['user_id']) && isset($record['role_id'])) {
                // user_roles 表（复合主键）
                // user_roles table (composite primary key)
                Db::table($table)
                    ->where('user_id', $record['user_id'])
                    ->where('role_id', $record['role_id'])
                    ->delete();
            } elseif (isset($record['role_id']) && isset($record['permission_id'])) {
                // role_permissions 表（复合主键）
                // role_permissions table (composite primary key)
                Db::table($table)
                    ->where('role_id', $record['role_id'])
                    ->where('permission_id', $record['permission_id'])
                    ->delete();
            }
        }

        // 清空记录
        // Clear records
        $this->createdRecords = [];
    }

    /**
     * 获取创建的记录数量
     * Get count of created records
     *
     * @return int
     */
    public function getCreatedCount(): int
    {
        return count($this->createdRecords);
    }
}
