# 测试隔离机制说明

## 概述

为了提升测试的可靠性和可维护性，项目提供了三种测试隔离机制：

1. **数据库事务回滚**：自动回滚测试中的数据库更改
2. **缓存命名空间**：为每个测试提供独立的缓存空间
3. **测试数据工厂**：统一的测试数据创建和清理机制

## 1. 数据库事务回滚

### 功能说明

`DatabaseTransactions` Trait 为测试提供数据库事务隔离，测试结束后自动回滚所有数据库更改。

### 使用方法

```php
<?php

use Tests\ThinkPHPTestCase;
use Tests\Traits\DatabaseTransactions;

class MyTest extends ThinkPHPTestCase
{
    use DatabaseTransactions;

    public function testSomething()
    {
        // 创建测试数据
        $userId = Db::table('users')->insertGetId([
            'username' => 'test_user',
            'email' => 'test@test.com',
            // ...
        ]);

        // 执行测试
        // ...

        // 测试结束后，所有数据库更改会自动回滚
    }
}
```

### 优点

- ✅ 测试数据自动清理，无需手动删除
- ✅ 测试之间完全隔离，互不影响
- ✅ 测试失败时数据也会回滚
- ✅ 支持并行测试

### 注意事项

- ⚠️ 只对支持事务的数据库引擎有效（如 InnoDB）
- ⚠️ 如果测试需要真实提交数据，不要使用此 Trait
- ⚠️ 嵌套事务可能导致意外行为，请谨慎使用

### 禁用事务

如果某个测试需要真实提交数据，不要使用 `DatabaseTransactions` Trait：

```php
class MyTest extends ThinkPHPTestCase
{
    // 不使用 DatabaseTransactions Trait

    public function testRealCommit()
    {
        // 这里的数据会真实提交到数据库
        // 需要在 tearDown() 中手动清理
    }

    protected function tearDown(): void
    {
        // 手动清理数据
        Db::table('users')->where('username', 'test_user')->delete();
        parent::tearDown();
    }
}
```

## 2. 缓存命名空间

### 功能说明

`CacheNamespace` Trait 为每个测试提供独立的缓存命名空间，测试结束后自动清除该命名空间下的所有缓存。

### 使用方法

```php
<?php

use Tests\ThinkPHPTestCase;
use Tests\Traits\CacheNamespace;

class MyTest extends ThinkPHPTestCase
{
    use CacheNamespace;

    public function testSomething()
    {
        // 使用 Trait 提供的缓存方法（自动添加命名空间）
        $this->cacheSet('key', 'value');
        $value = $this->cacheGet('key');

        // 或者手动添加命名空间
        $key = $this->getCacheKey('my_key');
        Cache::set($key, 'value');

        // 测试结束后，缓存会自动清除
    }
}
```

### 提供的方法

- `getCacheKey(string $key): string` - 获取带命名空间的缓存键
- `cacheSet(string $key, $value, ?int $ttl = null): bool` - 设置缓存
- `cacheGet(string $key, $default = null)` - 获取缓存
- `cacheDelete(string $key): bool` - 删除缓存

### 优点

- ✅ 缓存数据自动清理
- ✅ 测试之间缓存完全隔离
- ✅ 避免缓存污染

### 注意事项

- ⚠️ 缓存命名空间只对使用 Trait 提供的方法有效
- ⚠️ 如果直接使用 `Cache::set()`，需要手动添加命名空间
- ⚠️ 如果测试需要访问全局缓存，不要使用此 Trait

## 3. 测试数据工厂

### 功能说明

`TestDataFactory` 提供统一的测试数据创建和清理机制，自动跟踪创建的数据并在测试结束后清理。

### 使用方法

```php
<?php

use Tests\Factories\TestDataFactory;
use Tests\ThinkPHPTestCase;

class MyTest extends ThinkPHPTestCase
{
    protected TestDataFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new TestDataFactory();
    }

    protected function tearDown(): void
    {
        $this->factory->cleanup();
        parent::tearDown();
    }

    public function testSomething()
    {
        // 创建用户
        $userId = $this->factory->createUser([
            'username' => 'test_user',
            'email' => 'test@test.com',
        ]);

        // 创建角色
        $roleId = $this->factory->createRole([
            'name' => 'Test Role',
            'slug' => 'test_role',
        ]);

        // 分配用户角色
        $this->factory->assignUserRole($userId, $roleId);

        // 执行测试
        // ...

        // tearDown() 会自动清理所有创建的数据
    }
}
```

### 提供的方法

#### 通用方法

- `create(string $table, array $data): int` - 创建单条数据
- `createMany(string $table, array $dataList): array` - 批量创建数据
- `cleanup(): void` - 清理所有创建的数据
- `getCreatedCount(): int` - 获取创建的记录数量

#### 便捷方法

- `createUser(array $attributes = []): int` - 创建用户
- `createRole(array $attributes = []): int` - 创建角色
- `createPermission(array $attributes = []): int` - 创建权限
- `assignUserRole(int $userId, int $roleId): void` - 分配用户角色
- `assignRolePermission(int $roleId, int $permissionId): void` - 分配角色权限

### 优点

- ✅ 统一的数据创建接口
- ✅ 自动跟踪创建的数据
- ✅ 自动清理数据（按创建顺序的逆序删除）
- ✅ 支持级联删除（处理外键约束）
- ✅ 提供便捷方法简化常见操作

### 注意事项

- ⚠️ 必须在 `tearDown()` 中调用 `cleanup()`
- ⚠️ 如果使用了 `DatabaseTransactions` Trait，数据会被事务回滚，`cleanup()` 可以省略

## 组合使用

可以组合使用多个隔离机制：

```php
<?php

use Tests\Factories\TestDataFactory;
use Tests\ThinkPHPTestCase;
use Tests\Traits\CacheNamespace;
use Tests\Traits\DatabaseTransactions;

class MyTest extends ThinkPHPTestCase
{
    use DatabaseTransactions;  // 数据库事务回滚
    use CacheNamespace;        // 缓存命名空间

    protected TestDataFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new TestDataFactory();
    }

    protected function tearDown(): void
    {
        // 由于使用了 DatabaseTransactions，数据会自动回滚
        // 这里的 cleanup() 可以省略，但保留也无害
        $this->factory->cleanup();
        parent::tearDown();
    }

    public function testSomething()
    {
        // 创建数据（会被事务回滚）
        $userId = $this->factory->createUser();

        // 使用缓存（会被自动清除）
        $this->cacheSet('user_id', $userId);

        // 执行测试
        // ...
    }
}
```

## 最佳实践

### 1. 优先使用 DatabaseTransactions

对于大多数测试，推荐使用 `DatabaseTransactions` Trait：

```php
class MyTest extends ThinkPHPTestCase
{
    use DatabaseTransactions;

    // 测试代码
}
```

### 2. 使用 TestDataFactory 简化数据创建

使用 `TestDataFactory` 可以简化测试数据的创建：

```php
// 不推荐：手动创建数据
$userId = Db::table('users')->insertGetId([...]);
$roleId = Db::table('roles')->insertGetId([...]);
Db::table('user_roles')->insert([...]);

// 推荐：使用工厂
$userId = $this->factory->createUser();
$roleId = $this->factory->createRole();
$this->factory->assignUserRole($userId, $roleId);
```

### 3. 缓存测试使用 CacheNamespace

对于涉及缓存的测试，使用 `CacheNamespace` Trait：

```php
class CacheTest extends ThinkPHPTestCase
{
    use CacheNamespace;

    public function testCache()
    {
        $this->cacheSet('key', 'value');
        $this->assertEquals('value', $this->cacheGet('key'));
    }
}
```

### 4. 避免过度隔离

不是所有测试都需要隔离机制。对于简单的单元测试（不涉及数据库和缓存），不需要使用这些 Trait：

```php
class SimpleUnitTest extends TestCase  // 使用 PHPUnit 的 TestCase
{
    public function testSomething()
    {
        // 纯逻辑测试，不需要隔离机制
    }
}
```

## 故障排查

### 问题 1：事务回滚不生效

**症状**：测试数据没有被回滚

**原因**：
- 数据库引擎不支持事务（如 MyISAM）
- 测试中手动提交了事务

**解决方案**：
- 确保使用支持事务的数据库引擎（如 InnoDB）
- 不要在测试中手动调用 `Db::commit()`

### 问题 2：缓存清除不完全

**症状**：测试之间缓存相互影响

**原因**：
- 直接使用 `Cache::set()` 而不是 `$this->cacheSet()`
- 缓存键没有使用命名空间

**解决方案**：
- 使用 Trait 提供的缓存方法
- 或者手动添加命名空间：`$key = $this->getCacheKey('my_key')`

### 问题 3：测试数据清理失败

**症状**：`cleanup()` 报错或数据没有被删除

**原因**：
- 外键约束导致删除失败
- 删除顺序不正确

**解决方案**：
- 工厂会按创建顺序的逆序删除，通常能处理外键约束
- 如果仍然失败，考虑使用 `DatabaseTransactions` Trait

## 参考

- [PHPUnit 文档](https://phpunit.de/documentation.html)
- [ThinkPHP 6.x 文档](https://www.kancloud.cn/manual/thinkphp6_0/)
- [数据库事务](https://www.kancloud.cn/manual/thinkphp6_0/1037577)

