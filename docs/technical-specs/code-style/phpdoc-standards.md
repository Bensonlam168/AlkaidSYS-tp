# PHPDoc Standards | PHPDoc 规范

> Version: 1.0.0 | Last Updated: 2025-11-26

## 1. Overview | 概述

This document defines the PHPDoc documentation standards for AlkaidSYS-tp.
本文档定义 AlkaidSYS-tp 的 PHPDoc 文档规范。

## 2. Required Elements | 必需元素

### 2.1 Class Documentation | 类文档

```php
/**
 * Class Title (English) | 类标题（中文）
 *
 * Brief description of the class purpose.
 * 类用途的简要描述。
 *
 * @package namespace\path
 * @author Author Name <email@example.com>  // Optional
 * @since 1.0.0  // Optional, for new classes
 */
class ClassName
{
}
```

### 2.2 Method Documentation | 方法文档

```php
/**
 * Method title (English) | 方法标题（中文）
 *
 * Detailed description if needed.
 * 如有必要的详细描述。
 *
 * @param Type $paramName Parameter description | 参数描述
 * @param Type|null $optionalParam Optional parameter | 可选参数
 * @return ReturnType Description | 返回值描述
 * @throws ExceptionType When condition | 何时抛出异常
 */
public function methodName(Type $paramName, ?Type $optionalParam = null): ReturnType
{
}
```

### 2.3 Property Documentation | 属性文档

```php
/**
 * Property description | 属性描述
 *
 * @var Type
 */
protected Type $propertyName;
```

## 3. Bilingual Convention | 双语约定

AlkaidSYS uses bilingual documentation (English | Chinese).
AlkaidSYS 使用双语文档（英文 | 中文）。

### Format | 格式

```php
/**
 * English description | 中文描述
 */
```

### Examples | 示例

```php
/**
 * User Service | 用户服务
 *
 * Provides user management operations.
 * 提供用户管理操作。
 *
 * @param int $userId User ID | 用户ID
 * @return User|null User entity or null if not found | 用户实体或null
 */
```

## 4. Controller Standards | 控制器规范

### 4.1 API Controller Methods | API 控制器方法

```php
/**
 * List resources | 获取资源列表
 *
 * @api GET /api/v1/resources
 * @param Request $request HTTP request | HTTP请求
 * @return Response JSON response with paginated data | 分页JSON响应
 * @throws ValidationException When parameters invalid | 参数无效时
 */
public function index(Request $request): Response
{
}

/**
 * Create resource | 创建资源
 *
 * @api POST /api/v1/resources
 * @param Request $request HTTP request with resource data | 包含资源数据的HTTP请求
 * @return Response JSON response with created resource | 包含创建资源的JSON响应
 * @throws ValidationException When data invalid | 数据无效时
 */
public function store(Request $request): Response
{
}
```

## 5. Service Standards | 服务规范

```php
/**
 * User Service | 用户服务
 *
 * Business logic for user management.
 * 用户管理的业务逻辑。
 *
 * @package app\service
 */
class UserService
{
    /**
     * Find user by ID | 根据ID查找用户
     *
     * @param int $id User ID | 用户ID
     * @return User|null User entity or null if not found | 用户实体或未找到时返回null
     */
    public function findById(int $id): ?User
    {
    }
}
```

## 6. Type Hints | 类型提示

### 6.1 Use Native Types | 使用原生类型

```php
// ✅ Good - Native type hints | 好 - 原生类型提示
public function process(string $name, int $count): array
{
}

// ❌ Avoid - Only PHPDoc types | 避免 - 仅PHPDoc类型
/** @param string $name */
public function process($name)
{
}
```

### 6.2 Complex Types | 复杂类型

```php
/**
 * @param array<string, mixed> $config Configuration array | 配置数组
 * @return Collection<User> Collection of users | 用户集合
 */
```

## 7. Anti-Patterns | 反模式

### Avoid | 避免

```php
// ❌ Empty or meaningless docs | 空或无意义文档
/**
 * Method method
 */
public function method()
{
}

// ❌ Redundant type documentation | 冗余类型文档
/**
 * @param int $id  // Already in signature
 */
public function find(int $id): ?User
{
}

// ❌ Outdated documentation | 过时文档
/**
 * @param string $name  // Actually int $id
 */
public function find(int $id): ?User
{
}
```

## 8. Checklist | 检查清单

- [ ] All classes have documentation | 所有类有文档
- [ ] All public methods documented | 所有公共方法有文档
- [ ] Bilingual format used | 使用双语格式
- [ ] Type hints match @param/@return | 类型提示与注解一致
- [ ] Exception documentation for throws | 异常文档完整

---

**References | 参考资料**:
- [PSR-5: PHPDoc Standard (Draft)](https://github.com/php-fig/fig-standards/blob/master/proposed/phpdoc.md)
- [phpDocumentor](https://docs.phpdoc.org/)

