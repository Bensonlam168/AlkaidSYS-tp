# Code Style Guide

## 1. Overview
This document defines the coding standards and best practices for the AlkaidSYS project. Adherence to these guidelines ensures code consistency, readability, and maintainability.

## 2. Backend (PHP)

### 2.1 General Standards
- Follow **PSR-12** coding style.
- Use **PHP 8.2+** features (typed properties, constructor promotion, etc.).
- Strict typing (`declare(strict_types=1);`) is recommended for new files.

### 2.2 Naming Conventions
- **Classes**: `PascalCase` (e.g., `UserController`, `ProductService`)
- **Methods**: `camelCase` (e.g., `getUserById`, `saveOrder`)
- **Variables**: `camelCase` (e.g., `$userId`, `$orderList`)
- **Constants**: `UPPER_SNAKE_CASE` (e.g., `MAX_RETRY_COUNT`)
- **Database Tables**: `snake_case` with plural names (e.g., `ecommerce_orders`)
- **Database Columns**: `snake_case` (e.g., `created_at`, `user_id`)

### 2.3 Directory Structure
Standard application structure:
```
app/
├── controller/       # Controllers (Admin, API, Web)
├── model/            # Eloquent/ThinkORM Models
├── service/          # Business Logic Layer
├── validate/         # Request Validation
├── middleware/       # HTTP Middleware
└── ...
```

### 2.4 Controller Guidelines
- Keep controllers thin. Move business logic to **Services**.
- Always extend `app\controller\ApiController` for APIs.
- Use Dependency Injection.
- Return standardized responses (see API Spec).

### 2.5 SOLID Design Principles

The AlkaidSYS project follows **SOLID principles** for maintainable, scalable, and testable code.

#### Single Responsibility Principle (SRP)
Each class should have only one reason to change.

```php
// ❌ Bad: UserService handles too many responsibilities
class UserService {
    public function createUser($data) { /* ... */ }
    public function sendWelcomeEmail($user) { /* ... */ }
    public function logActivity($user, $action) { /* ... */ }
}

// ✅ Good: Separate concerns into focused services
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

#### Open/Closed Principle (OCP)
Classes should be open for extension but closed for modification.

```php
// ❌ Bad: Must modify class to add new payment types
class PaymentProcessor {
    public function process($type, $amount) {
        if ($type === 'credit_card') {
            // Process credit card
        } elseif ($type === 'paypal') {
            // Process PayPal
        }
    }
}

// ✅ Good: Extend via interfaces
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

#### Liskov Substitution Principle (LSP)
Subclasses must be substitutable for their base classes.

```php
// ✅ Good: Rectangle and Square properly implement Shape
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

#### Interface Segregation Principle (ISP)
Don't force clients to depend on interfaces they don't use.

```php
// ❌ Bad: Fat interface forces unused methods
interface Worker {
    public function work(): void;
    public function eat(): void;
    public function sleep(): void;
}

// ✅ Good: Segregated interfaces
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

#### Dependency Inversion Principle (DIP)
Depend on abstractions, not concretions. High-level modules should not depend on low-level modules.

```php
// ❌ Bad: High-level class depends on concrete implementation
class UserController {
    private MySQLUserRepository $repository;
    
    public function __construct() {
        $this->repository = new MySQLUserRepository();
    }
}

// ✅ Good: Depend on interface abstraction
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

### 2.6 Documentation Standards (Bilingual)

**PHPDoc Comments** - Important comments should be bilingual (Chinese & English):

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

**Guidelines**:
- Class and method docblocks: Bilingual for public APIs
- Inline comments: Bilingual for complex logic
- Parameter/Return documentation: Include both languages
- Simple self-explanatory code: Comments optional

## 3. Frontend (Vue 3 + TypeScript)

### 3.1 General Standards
- Use **Vue 3** with **Composition API** (`<script setup lang="ts">`).
- Use **TypeScript** for all logic.
- Use **ESLint** and **Prettier** for formatting.

### 3.2 Naming Conventions
- **Components**: `PascalCase` (e.g., `UserList.vue`, `OrderDetail.vue`)
- **Files**: `kebab-case` or `PascalCase` (consistent within module).
- **Variables/Functions**: `camelCase`.
- **Interfaces/Types**: `PascalCase` (e.g., `interface User { ... }`).

### 3.3 Project Structure
```
src/
├── api/              # API Client functions
├── components/       # Shared components
├── views/            # Page components
├── store/            # Pinia stores
├── utils/            # Helper functions
└── ...
```

### 3.4 JSDoc for TypeScript

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

## 4. Database

- **Engine**: InnoDB
- **Charset**: `utf8mb4`
- **Collation**: `utf8mb4_unicode_ci`
- **Primary Keys**: `id` (unsigned int/bigint)
- **Foreign Keys**: Index all foreign keys.
- **Timestamps**: Include `created_at` and `updated_at` (int or timestamp).
- **Soft Deletes**: Use `delete_time` (int) if needed.

## 5. Git Workflow

### 5.1 Branch Strategy
- **Branches**:
  - `main`: Stable production code.
  - `develop`: Integration branch.
  - `feature/*`: New features.
  - `fix/*`: Bug fixes.
  - `hotfix/*`: Critical production fixes.
  - `release/*`: Release preparation.

### 5.2 Commit Message Convention (Conventional Commits)

**Format**: `<type>(<scope>): <subject>`

**Types**:
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, no logic change)
- `refactor`: Code refactoring
- `perf`: Performance improvements
- `test`: Adding/updating tests
- `chore`: Build process, tooling changes

**Examples**:
```bash
feat(auth): add JWT authentication
fix(user): fix user registration validation
docs(api): update API documentation
perf(query): optimize database query performance
refactor(service): extract user service methods
```

**Commit Message Guidelines**:
- Use imperative mood ("add" not "added")
- First line max 72 characters
- Provide detailed body if needed
- Reference issues: `Closes #123`

### 5.3 Pull Request Guidelines
- Title follows commit convention
- Include description of changes
- Link related issues
- Ensure all tests pass
- Request code review before merging
