# Dependency Injection Guidelines | 依赖注入使用规范

> Version: 1.0.0 | Last Updated: 2025-11-26

## 1. Overview | 概述

This document defines the standard patterns for dependency injection (DI) in AlkaidSYS-tp.
本文档定义 AlkaidSYS-tp 中依赖注入的标准模式。

## 2. Recommended Patterns | 推荐模式

### 2.1 Constructor Injection (Preferred) | 构造函数注入（首选）

**Always prefer constructor injection** for required dependencies.
对于必需的依赖项，**始终首选构造函数注入**。

```php
// ✅ Recommended | 推荐
class UserService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CacheInterface $cache
    ) {}
    
    public function findById(int $id): ?User
    {
        return $this->userRepository->find($id);
    }
}
```

### 2.2 Facade Pattern (Acceptable) | 门面模式（可接受）

Use facades for **infrastructure services** like Cache, Log, Event.
对于缓存、日志、事件等**基础设施服务**使用门面。

```php
// ✅ Acceptable for infrastructure | 基础设施可接受
use think\facade\Cache;
use think\facade\Log;

class ProductService
{
    public function getProduct(int $id): ?Product
    {
        $cacheKey = "product:{$id}";
        
        return Cache::remember($cacheKey, 3600, function () use ($id) {
            return Product::find($id);
        });
    }
}
```

### 2.3 Container Resolution (Acceptable) | 容器解析（可接受）

Use `app()` helper when dependencies are **dynamic** or **optional**.
当依赖是**动态的**或**可选的**时，使用 `app()` 助手。

```php
// ✅ Acceptable for dynamic dependencies | 动态依赖可接受
class ReportGenerator
{
    public function generate(string $type): Report
    {
        // Dynamic resolution based on type | 基于类型动态解析
        $formatter = app("formatter.{$type}");
        return $formatter->format($this->getData());
    }
}
```

## 3. Anti-Patterns | 反模式

### 3.1 Direct Instantiation (Avoid) | 直接实例化（避免）

**Never** use `new` for service classes in business code.
在业务代码中**绝不**对服务类使用 `new`。

```php
// ❌ Bad - Hard to test, violates DI | 不好 - 难以测试，违反 DI
class OrderController
{
    public function create()
    {
        $service = new OrderService();  // ❌ Anti-pattern
        return $service->createOrder($data);
    }
}

// ✅ Good - Inject via constructor | 好 - 通过构造函数注入
class OrderController
{
    public function __construct(
        private readonly OrderService $orderService
    ) {}
    
    public function create()
    {
        return $this->orderService->createOrder($data);
    }
}
```

### 3.2 Service Locator in Business Logic | 业务逻辑中的服务定位器

**Avoid** using container as service locator deep in business logic.
**避免**在业务逻辑深处使用容器作为服务定位器。

```php
// ❌ Bad - Hidden dependencies | 不好 - 隐藏依赖
class InvoiceService
{
    public function generate(Order $order): Invoice
    {
        // Hidden dependency, hard to test | 隐藏依赖，难以测试
        $taxService = app(TaxService::class);  // ❌ 
        $tax = $taxService->calculate($order);
        // ...
    }
}

// ✅ Good - Explicit dependency | 好 - 显式依赖
class InvoiceService
{
    public function __construct(
        private readonly TaxService $taxService
    ) {}
    
    public function generate(Order $order): Invoice
    {
        $tax = $this->taxService->calculate($order);
        // ...
    }
}
```

## 4. Service Registration | 服务注册

### 4.1 Using ServiceProvider | 使用服务提供者

Register services in dedicated ServiceProvider classes.
在专用的 ServiceProvider 类中注册服务。

```php
// infrastructure/YourModule/YourModuleServiceProvider.php
class YourModuleServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(SomeInterface::class, SomeImplementation::class);
        
        $this->container->singleton(ConfiguredService::class, function ($container) {
            return new ConfiguredService(
                $container->get(DependencyA::class),
                config('some.config')
            );
        });
    }
}
```

### 4.2 Lazy Loading | 延迟加载

Use `$defer = true` for services that are not always needed.
对于并非总是需要的服务，使用 `$defer = true`。

```php
class HeavyServiceProvider extends AbstractServiceProvider
{
    protected bool $defer = true;
    
    protected array $provides = [HeavyService::class];
    
    public function register(): void
    {
        $this->container->singleton(HeavyService::class, function () {
            return new HeavyService(/* expensive setup */);
        });
    }
}
```

## 5. Testing Considerations | 测试考虑

### 5.1 Mock Injection | Mock 注入

Constructor injection makes testing straightforward.
构造函数注入使测试变得简单。

```php
class UserServiceTest extends TestCase
{
    public function testFindById(): void
    {
        // Create mock | 创建 Mock
        $mockRepo = $this->createMock(UserRepository::class);
        $mockRepo->method('find')->willReturn(new User(['id' => 1]));
        
        // Inject mock | 注入 Mock
        $service = new UserService($mockRepo, $this->createMock(CacheInterface::class));
        
        // Test | 测试
        $user = $service->findById(1);
        $this->assertEquals(1, $user->id);
    }
}
```

## 6. Summary | 总结

| Pattern | When to Use | Priority |
|---------|-------------|----------|
| Constructor Injection | Required dependencies | ⭐⭐⭐ First choice |
| Facades | Infrastructure (Cache, Log) | ⭐⭐ Acceptable |
| app() helper | Dynamic/Optional deps | ⭐⭐ Acceptable |
| Direct `new` | Value Objects, DTOs only | ⚠️ Limited use |

---

**References | 参考资料**:
- [SOLID Principles](https://en.wikipedia.org/wiki/SOLID)
- [Dependency Injection](https://en.wikipedia.org/wiki/Dependency_injection)
- [ThinkPHP Container](https://www.kancloud.cn/manual/thinkphp8_0/2968091)

