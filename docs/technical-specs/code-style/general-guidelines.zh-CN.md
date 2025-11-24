# 代码风格指南

## 1. 概述
本文档定义了 AlkaidSYS 项目的编码标准和最佳实践。遵循这些指南可以确保代码的一致性、可读性和可维护性。

## 2. 后端（PHP）

### 2.1 通用标准
- 遵循 **PSR-12** 编码风格标准
- 使用 **PHP 8.2+** 特性（类型属性、构造器属性提升等）
- 新文件推荐使用严格类型声明（`declare(strict_types=1);`）

### 2.2 命名约定
- **类名**: `PascalCase`（例如：`UserController`、`ProductService`）
- **方法名**: `camelCase`（例如：`getUserById`、`saveOrder`）
- **变量名**: `camelCase`（例如：`$userId`、`$orderList`）
- **常量**: `UPPER_SNAKE_CASE`（例如：`MAX_RETRY_COUNT`）
- **数据库表名**: `snake_case` 使用复数（例如：`ecommerce_orders`）
- **数据库列名**: `snake_case`（例如：`created_at`、`user_id`）

### 2.3 目录结构
标准应用结构：
```
app/
├── controller/       # 控制器（Admin、API、Web）
├── model/            # Eloquent/ThinkORM 模型
├── service/          # 业务逻辑层
├── validate/         # 请求验证
├── middleware/       # HTTP 中间件
└── ...
```

### 2.4 控制器指南
- 保持控制器精简，将业务逻辑移至 **Service** 层
- API 控制器必须继承 `app\controller\ApiController`
- 使用依赖注入
- 返回标准化响应（参见 API 规范）

### 2.5 SOLID 设计原则

AlkaidSYS 项目遵循 **SOLID 原则**，以确保代码的可维护性、可扩展性和可测试性。

#### 单一职责原则（Single Responsibility Principle - SRP）
每个类应该只有一个改变的理由。

```php
// ❌ 不好：UserService 承担了太多职责
class UserService {
    public function createUser($data) { /* ... */ }
    public function sendWelcomeEmail($user) { /* ... */ }
    public function logActivity($user, $action) { /* ... */ }
}

// ✅ 好：将关注点分离到专注的服务中
class UserService {
    public function createUser($data) { /* ... */ }
}

class EmailService {
    public function sendWelcomeEmail($user) { /* ... */ }
}

class ActivityLogger {
    public function log($user, $action) { /* ... */ }
}
```

#### 开闭原则（Open/Closed Principle - OCP）
类应该对扩展开放，对修改关闭。

```php
// ❌ 不好：必须修改类来添加新的支付类型
class PaymentProcessor {
    public function process($type, $amount) {
        if ($type === 'credit_card') {
            // 处理信用卡
        } elseif ($type === 'paypal') {
            // 处理 PayPal
        }
    }
}

// ✅ 好：通过接口扩展
interface PaymentGateway {
    public function process(float $amount): bool;
}

class CreditCardGateway implements PaymentGateway {
    public function process(float $amount): bool { /* ... */ }
}

class PayPalGateway implements PaymentGateway {
    public function process(float $amount): bool { /* ... */ }
}

class PaymentProcessor {
    public function __construct(private PaymentGateway $gateway) {}
    
    public function process(float $amount): bool {
        return $this->gateway->process($amount);
    }
}
```

#### 里氏替换原则（Liskov Substitution Principle - LSP）
子类必须能够替换其基类。

```php
// ✅ 好：Rectangle 和 Square 正确实现 Shape
interface Shape {
    public function getArea(): float;
}

class Rectangle implements Shape {
    public function __construct(
        private float $width,
        private float $height
    ) {}
    
    public function getArea(): float {
        return $this->width * $this->height;
    }
}

class Square implements Shape {
    public function __construct(private float $side) {}
    
    public function getArea(): float {
        return $this->side ** 2;
    }
}
```

#### 接口隔离原则（Interface Segregation Principle - ISP）
不要强迫客户端依赖它们不使用的接口。

```php
// ❌ 不好：臃肿的接口强制实现不需要的方法
interface Worker {
    public function work(): void;
    public function eat(): void;
    public function sleep(): void;
}

// ✅ 好：隔离的接口
interface Workable {
    public function work(): void;
}

interface Eatable {
    public function eat(): void;
}

interface Sleepable {
    public function sleep(): void;
}

class Human implements Workable, Eatable, Sleepable {
    public function work(): void { /* ... */ }
    public function eat(): void { /* ... */ }
    public function sleep(): void { /* ... */ }
}

class Robot implements Workable {
    public function work(): void { /* ... */ }
}
```

#### 依赖倒置原则（Dependency Inversion Principle - DIP）
依赖于抽象，而不是具体实现。高层模块不应该依赖低层模块。

```php
// ❌ 不好：高层类依赖具体实现
class UserController {
    private MySQLUserRepository $repository;
    
    public function __construct() {
        $this->repository = new MySQLUserRepository();
    }
}

// ✅ 好：依赖接口抽象
interface UserRepository {
    public function find(int $id): ?User;
    public function save(User $user): bool;
}

class MySQLUserRepository implements UserRepository {
    public function find(int $id): ?User { /* ... */ }
    public function save(User $user): bool { /* ... */ }
}

class UserController {
    public function __construct(
        private UserRepository $repository
    ) {}
    
    public function show(int $id) {
        $user = $this->repository->find($id);
        return $this->success($user);
    }
}
```

### 2.6 文档规范（双语）

**PHPDoc 注释** - 重要注释应使用双语（中文和英文）：

```php
/**
 * 获取用户信息 / Get user information
 * 
 * @param int $userId 用户 ID / User ID
 * @return array 用户数据 / User data
 * @throws NotFoundException 用户不存在时 / When user not found
 */
public function getUserInfo(int $userId): array
{
    // 验证用户 ID / Validate user ID
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user ID');
    }
    
    return $this->userRepository->find($userId);
}
```

**指南**：
- 类和方法文档块：公共 API 使用双语
- 行内注释：复杂逻辑使用双语
- 参数/返回值文档：包含两种语言
- 简单自解释代码：注释使用双语

## 3. 前端（Vue 3 + TypeScript）

### 3.1 通用标准
- 使用 **Vue 3** 配合 **Composition API**（`<script setup lang="ts">`）
- 所有逻辑代码使用 **TypeScript**
- 使用 **ESLint** 和 **Prettier** 进行代码格式化

### 3.2 命名约定
- **组件**: `PascalCase`（例如：`UserList.vue`、`OrderDetail.vue`）
- **文件**: `kebab-case` 或 `PascalCase`（模块内保持一致）
- **变量/函数**: `camelCase`
- **接口/类型**: `PascalCase`（例如：`interface User { ... }`）

### 3.3 项目结构
```
src/
├── api/              # API 客户端函数
├── components/       # 共享组件
├── views/            # 页面组件
├── store/            # Pinia 状态管理
├── utils/            # 工具函数
└── ...
```

### 3.4 TypeScript 的 JSDoc

```typescript
/**
 * 获取用户列表 / Fetch user list
 * 
 * @param params - 查询参数 / Query parameters
 * @returns 用户列表响应 / User list response
 */
export async function getUserList(params: UserQueryParams): Promise<UserListResponse> {
  return request({
    url: '/api/users',
    method: 'get',
    params
  })
}
```

## 4. 数据库规范

- **存储引擎**: InnoDB
- **字符集**: `utf8mb4`
- **排序规则**: `utf8mb4_unicode_ci`
- **主键**: `id`（unsigned int/bigint）
- **外键**: 所有外键必须建立索引
- **时间戳**: 包含 `created_at` 和 `updated_at`（int 或 timestamp）
- **软删除**: 如需要，使用 `delete_time`（int）

## 5. Git 工作流

### 5.1 分支策略
- **分支**:
  - `main`: 稳定的生产代码
  - `develop`: 集成分支
  - `feature/*`: 新功能开发
  - `fix/*`: Bug 修复
  - `hotfix/*`: 紧急生产修复
  - `release/*`: 发布准备

### 5.2 提交信息规范（Conventional Commits）

**格式**: `<type>(<scope>): <subject>`

**类型**:
- `feat`: 新功能
- `fix`: Bug 修复
- `docs`: 文档变更
- `style`: 代码格式调整（不影响逻辑）
- `refactor`: 代码重构
- `perf`: 性能优化
- `test`: 添加/更新测试
- `chore`: 构建过程、工具变更

**示例**:
```bash
feat(auth): add JWT authentication
fix(user): fix user registration validation
docs(api): update API documentation
perf(query): optimize database query performance
refactor(service): extract user service methods
```

**提交信息指南**:
- 使用祈使语气（"add" 而非 "added"）
- 第一行最多 72 个字符
- 如需要提供详细的正文说明
- 引用相关 issue: `Closes #123`

### 5.3 Pull Request 指南
- 标题遵循提交规范
- 包含变更描述
- 关联相关 issue
- 确保所有测试通过
- 合并前请求代码审查
