# PHP 8.2+ 现代特性使用指南

> Version: 1.0.0 | Last Updated: 2025-12-14

本文档定义 AlkaidSYS 项目中 PHP 8.2+ 现代特性的使用规范和最佳实践。

## 1. 项目要求

- **最低 PHP 版本**: 8.2
- **代码规范**: PSR-12
- **严格类型**: 新文件必须使用 `declare(strict_types=1);`

## 2. 推荐使用的现代特性

### 2.1 构造器属性提升（Constructor Property Promotion）

**适用场景**: 服务类、仓储类的依赖注入

```php
// ✅ 推荐
class UserService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CacheInterface $cache
    ) {}
}

// ❌ 避免
class UserService
{
    private UserRepository $userRepository;
    private CacheInterface $cache;
    
    public function __construct(UserRepository $repo, CacheInterface $cache)
    {
        $this->userRepository = $repo;
        $this->cache = $cache;
    }
}
```

**项目示例**:
- `infrastructure/Lowcode/Collection/Service/CollectionManager.php`
- `infrastructure/Lowcode/Collection/Service/RelationshipManager.php`

### 2.2 readonly 属性

**适用场景**: 依赖注入、配置值、不可变对象

```php
// ✅ 推荐：依赖注入使用 readonly
public function __construct(
    private readonly UserRepository $userRepository
) {}

// ✅ 推荐：配置类使用 readonly
final readonly class DatabaseConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database
    ) {}
}
```

### 2.3 match 表达式

**适用场景**: 模式匹配、枚举映射、多分支选择

```php
// ✅ 推荐
return match ($mode) {
    'CASBIN_ONLY' => $this->getUserPermissionsFromCasbin($userId),
    'DUAL_MODE' => $this->mergePermissions($db, $casbin),
    default => $this->getUserPermissionsFromDatabase($userId),
};

// ❌ 避免（冗长的 switch）
switch ($mode) {
    case 'CASBIN_ONLY':
        return $this->getUserPermissionsFromCasbin($userId);
    case 'DUAL_MODE':
        return $this->mergePermissions($db, $casbin);
    default:
        return $this->getUserPermissionsFromDatabase($userId);
}
```

**项目示例**:
- `infrastructure/Permission/Service/PermissionService.php`
- `infrastructure/Lowcode/Generator/MigrationManager.php`
- `infrastructure/Language/LanguageService.php`

### 2.4 联合类型（Union Types）

**适用场景**: 多类型返回值、可选参数

```php
// ✅ 推荐
public function find(int $id): User|null
{
    return $this->repository->find($id);
}

public function process(string|int $id): void
{
    // ...
}
```

### 2.5 Null Safe 操作符（?->）

**适用场景**: 链式调用中的空值检查

```php
// ✅ 推荐
$city = $user?->getAddress()?->getCity();

// ❌ 避免
$city = null;
if ($user !== null) {
    $address = $user->getAddress();
    if ($address !== null) {
        $city = $address->getCity();
    }
}
```

### 2.6 命名参数（Named Arguments）

**适用场景**: 可选参数多、提升可读性

```php
// ✅ 推荐：复杂配置
$response = $this->jsonResponse(
    data: $user->toArray(),
    message: 'Success',
    code: 200,
    headers: ['X-Custom' => 'value']
);

// ⚠️ 谨慎使用：简单调用无需命名参数
$this->find(id: 1); // 不必要
$this->find(1);     // 更简洁
```

### 2.7 Enums 枚举

**适用场景**: 有限状态集、类型安全的常量

```php
// ✅ 推荐
enum UserStatus: int
{
    case Active = 1;
    case Inactive = 0;
    case Pending = 2;
}

public function setStatus(UserStatus $status): void
{
    $this->status = $status->value;
}
```

## 3. 使用限制

### 3.1 避免过度使用

- **命名参数**: 仅在提升可读性时使用，简单调用不需要
- **readonly class**: 仅用于真正不可变的数据对象

### 3.2 兼容性考虑

- 公共 API 签名变更需要版本控制
- 第三方库兼容性需测试验证

## 4. 代码审查清单

见 `docs/developer-guides/code-review-checklist.md`

