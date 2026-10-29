# AlkaidSYS 实现指南 (Implementation Guidelines)

> 本文件为 **实现指南**，用于在相关会话中按需自动载入，指导 AI 的实现细节与风格选择。
> 若与 **核心规则**或官方文档冲突，以**核心规则**与文档为准。
> 当前实现与设计之间的已知偏差详见 `docs/report/design-compliance-audit-2025-12-14.md`，本指南描述的是目标规范；当代码实现与本指南不一致时，应优先视为待修复的偏差，而非以现状代码为准。

## 指导原则

### 统一规则体系优先级
1. **Level 1: Core Rules** (最高优先级) - 硬性约束，不可违反
2. **Level 2: Implementation Guidelines** (本文件) - 最佳实践和编码规范  
3. **Level 3: Process Workflow** - 开发流程和工具使用

---

## 1. 代码风格与命名

### 1.1 PHP 后端

#### 基础规范
- 遵守 `PSR-12`，新文件使用 `declare(strict_types=1);`。
- 所有 PHP 代码必须通过项目根目录的 `.php-cs-fixer.php` 配置进行格式检查。

#### 命名约定
- **类名**：`PascalCase`（`UserService`、`CollectionManager`）
- **方法/变量**：`camelCase`（`createUser`, `$userId`、`tenantId`）
- **常量**：`UPPER_SNAKE_CASE`（`MAX_RETRY_COUNT`、`DEFAULT_PAGE_SIZE`）
- **表名**：`snake_case` 复数（`lowcode_collections`、`collection_fields`）
- **字段名**：`snake_case`（`tenant_id`、`created_at`、`updated_at`）

#### 命名空间与目录结构
- 遵循 PSR-12：命名空间与目录结构对应
- 示例：`Infrastructure\Auth\JwtService`、`Domain\Lowcode\CollectionService`
- 插件/领域服务 Provider 统一继承 `think\Service` 或 `Domain\DI\ServiceProvider`

#### 注释规范
- 对外 Service/Controller 方法推荐中英双语 PHPDoc 注释
- Swoole 相关代码需添加中英文注释，说明协程/进程的作用以及与请求上下文的关系

```php
<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 用户服务类 / User service class
 * 
 * 提供用户相关的业务逻辑处理 / Provides business logic processing for user-related operations
 * 
 * @package App\Service
 */
class UserService
{
    /**
     * 创建用户 / Create user
     * 
     * @param array $userData 用户数据 / User data
     * @return int 用户ID / User ID
     * @throws \Exception 创建失败时抛出异常 / Throws exception when creation fails
     */
    public function createUser(array $userData): int
    {
        // 实现逻辑 / Implementation logic
    }
}
```

### 1.2 前端 Vue + TypeScript

#### 基础规范
- 使用 Vue 3 + TypeScript + Composition API
- 组件命名使用 `PascalCase`，文件命名在同一模块内保持风格一致

#### 状态管理
- 状态管理统一使用 Pinia + 持久化插件
- 敏感数据（token 等）不做明文持久化
- 推荐 `<script setup lang="ts">` + composables（`useAuth`, `useTenant` 等）

#### 组件结构
```vue
<template>
  <!-- 模板内容 -->
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAuth } from '@/composables/useAuth'
import type { User } from '@/types/user'

// 组件逻辑 / Component logic
const { user, hasPermission } = useAuth()
const loading = ref(false)

// 响应式数据 / Reactive data
const userList = ref<User[]>([])

// 计算属性 / Computed properties
const filteredUsers = computed(() => {
  return userList.value.filter(user => user.active)
})

// 生命周期 / Lifecycle
onMounted(() => {
  loadUsers()
})
</script>

<style scoped>
/* 组件样式 */
</style>
```

---

## 2. API 设计与文档

### 2.1 RESTful 设计

#### 路由规范
- 资源路由遵循 REST 命名：`GET /api/v1/users`、`POST /api/v1/users` 等
- API 版本必须通过路径管理（如 `/api/v1/`），不支持通过参数版本控制
- 禁止使用 `action` 查询参数承载 CRUD 语义（如 `?action=delete`）

#### 查询参数
- 查询参数用于分页/过滤/排序/字段筛选：
  - `page`: 页码（从1开始）
  - `page_size`: 每页数量
  - `sort`: 排序字段和方向（如 `name:asc`）
  - `fields`: 字段筛选（如 `id,name,email`）
  - `include`: 关联资源包含（如 `roles,permissions`）

### 2.2 统一响应包装

#### 响应结构
- **成功响应**：HTTP 200/201 + `code = 0`，数据放在 `data` 字段
- **失败响应**：HTTP 4xx/5xx + `code != 0`，错误详情按文档放在 `data` 内相应字段
- **统一字段**：`code`、`message`、`data`、`timestamp`、`trace_id`（可选）

```php
// 成功响应示例 / Success response example
return response()->success([
    'id' => 1,
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);

// 失败响应示例 / Error response example  
return response()->error('Validation failed', 422, [
    'errors' => [
        'name' => ['Name is required'],
        'email' => ['Invalid email format']
    ]
]);
```

#### 控制器基类
- 推荐使用统一的控制器基类与 Helper 函数（`success`, `paginate`, `error` 等）
- 不得随意 `return json([...])`

### 2.3 OpenAPI / Swagger

#### 文档同步
- 新增或修改 API 时，同时维护 Swagger/OpenAPI 注解
- 前端 TS 类型优先通过 OpenAPI 生成，而不是手写重复类型
- 使用自动化工具生成 API 文档，确保文档与代码一致性

```php
/**
 * @OA\Get(
 *     path="/api/v1/users",
 *     summary="获取用户列表",
 *     tags={"Users"},
 *     @OA\Parameter(
 *         name="page",
 *         in="query",
 *         description="页码",
 *         required=false,
 *         @OA\Schema(type="integer", default=1)
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="成功",
 *         @OA\JsonContent(
 *             @OA\Property(property="code", type="integer", example=0),
 *             @OA\Property(property="message", type="string", example="Success"),
 *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/User"))
 *         )
 *     )
 * )
 */
public function index()
{
    // 实现逻辑
}
```

---

## 3. 权限与多租户集成

### 3.1 权限码映射习惯

#### 权限码格式
- **后端内部**：`resource.action`（DB 字段 `permissions.slug`），如 `forms.view`、`users.create`
- **面向前端/客户端**：`resource:action`，如 `forms:view`、`users:create`
- **路由中间件**：使用内部 slug（`resource.action`）
- **API 返回**：给前端使用 `resource:action` 格式

#### 权限检查
- 前端路由/按钮控制优先依赖统一的 `hasPermission('resource:action')` 工具方法
- 禁止引入新的权限编码体系（如 `AC_xxx` 或其他格式）

```php
// 后端权限检查 / Backend permission check
if (!$this->permissionService->hasPermission('users.create')) {
    return response()->error('Permission denied', 403);
}

// 前端权限检查 / Frontend permission check
const canCreateUser = hasPermission('users:create')
```

### 3.2 多租户上下文约定

#### 租户隔离
- 所有业务查询必须考虑 `tenant_id`（以及需要时的 `site_id`）
- 推荐通过统一上下文对象或基础模型自动注入租户过滤
- 手写 SQL 时务必显式带上租户条件

```php
// 自动注入租户过滤 / Automatic tenant filtering
class UserRepository extends BaseRepository
{
    public function findByEmail(string $email): ?User
    {
        return $this->model->where('email', $email)
            ->where('tenant_id', $this->getCurrentTenantId())
            ->first();
    }
}

// 手写 SQL 时的租户过滤 / Manual SQL with tenant filtering
$sql = "SELECT * FROM users WHERE email = :email AND tenant_id = :tenant_id";
$bindings = ['email' => $email, 'tenant_id' => $this->getCurrentTenantId()];
```

#### 前端多租户管理
- 前端需通过统一 store 管理 `tenant_id`/`tenant_code`
- 按规范在请求中携带租户信息

```typescript
// 前端租户管理 / Frontend tenant management
import { useTenantStore } from '@/stores/tenant'

const tenantStore = useTenantStore()

// 在请求中携带租户信息 / Carry tenant info in requests
const headers = {
  'X-Tenant-ID': tenantStore.currentTenantId,
  'X-Tenant-Code': tenantStore.currentTenantCode
}
```

---

## 4. 低代码与 Schema 工作流

### 4.1 Schema-first

#### 低代码优先原则
- 动态实体优先通过低代码 Schema 定义集合、字段和关系
- 数据库表结构与索引优先通过迁移脚本自动生成，避免手写 DDL
- 低代码 Schema（集合/字段/关系）是动态实体的"单一事实来源"
   - 表单校验一致性：前端获取低代码表单详情时调用 `GET /v1/lowcode/forms/{name}?with_validator=1&with_parsed=1`，使用返回的 `validator.rules/messages` 与 `parsed.components/permissions` 渲染与可见性控制，本地 zod 规则仅作兜底，禁止覆盖后端规则。

#### Schema 定义示例
```json
{
  "name": "users",
  "title": "用户",
  "description": "系统用户信息",
  "fields": [
    {
      "name": "name",
      "type": "string",
      "title": "姓名",
      "required": true,
      "validation": {
        "max_length": 100
      }
    },
    {
      "name": "email",
      "type": "email",
      "title": "邮箱",
      "required": true,
      "unique": true
    }
  ],
  "indexes": [
    {
      "name": "idx_users_email",
      "fields": ["email"],
      "unique": true
    }
  ]
}
```

### 4.2 漂移检测与同步

#### 推荐工作流
1. **修改低代码 Schema** → 在设计工具中更新 Schema 定义
2. **生成迁移 diff 报告** → 使用 `alkaid-dev-tools` 生成变更报告
3. **审核** → 开发者审核迁移脚本
4. **执行迁移** → 通过 `alkaid-dev-tools migrate:run` 执行

```bash
# 生成迁移差异报告 / Generate migration diff report
alkaid-dev-tools migrate:diff --schema="users" --output="migrations/diff_users.php"

# 执行迁移 / Execute migration
alkaid-dev-tools migrate:run
```

#### 迁移生成原则
- AI 生成迁移时应尽量保持可回滚与幂等设计
- 迁移脚本必须包含回滚策略和数据完整性检查

---

## 5. 安全、限流与审计

### 5.1 安全基线

#### 统一安全机制
- 尽量使用统一的认证/授权/限流中间件，不在业务代码内重复实现
- 涉及敏感操作（登录、支付、配置修改）优先考虑双因子验证、签名或防重放机制
- 高风险接口不得绕过限流或签名机制

#### 数据脱敏
- 日志信息需注意脱敏，避免输出明文敏感数据
- 错误响应中不得包含敏感信息（如完整密码、密钥等）

```php
// 敏感数据脱敏示例 / Sensitive data masking example
$logData = [
    'user_id' => $userId,
    'email' => $this->maskEmail($email), // 脱敏处理 / Masked
    'action' => 'login',
    'ip' => $request->ip(),
    'trace_id' => $this->getTraceId()
];
```

### 5.2 审计与日志

#### 操作日志
- 推荐在关键业务路径记录操作日志，带上 `trace_id/user_id/tenant_id/ip` 等上下文
- 审计日志必须不可篡改，支持长期存储和查询

```php
// 审计日志示例 / Audit log example
class AuditService
{
    public function logUserAction(string $action, array $context = []): void
    {
        $this->auditLogger->info([
            'action' => $action,
            'user_id' => $this->getCurrentUserId(),
            'tenant_id' => $this->getCurrentTenantId(),
            'ip' => request()->ip(),
            'trace_id' => $this->getTraceId(),
            'context' => $context,
            'timestamp' => now()->toISOString()
        ]);
    }
}
```

---

## 6. 测试与质量

### 6.1 测试优先

#### 测试策略
- 新增能力优先编写或补充单元测试/集成测试/特性测试
- 涉及权限、限流、认证、迁移等改动，推荐增加覆盖典型异常路径的测试用例
- 测试覆盖率不得低于 85%，关键路径必须达到 95% 以上

#### 测试命名
- 测试放在 `tests/**`，使用 `*Test.php` 命名
- 单元测试：`tests/Unit/`
- 特性测试：`tests/Feature/`
- 集成测试：`tests/Integration/`
- 端到端测试：`tests/E2E/`

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Service\UserService;

class UserServiceTest extends TestCase
{
    private UserService $userService;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->userService = new UserService();
    }
    
    public function testCreateUserWithValidData(): void
    {
        // Arrange / 准备
        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ];
        
        // Act / 执行
        $userId = $this->userService->createUser($userData);
        
        // Assert / 断言
        $this->assertIsInt($userId);
        $this->assertGreaterThan(0, $userId);
    }
    
    public function testCreateUserWithInvalidEmail(): void
    {
        // 测试异常路径 / Test exception path
        $this->expectException(\InvalidArgumentException::class);
        
        $userData = [
            'name' => 'John Doe',
            'email' => 'invalid-email'
        ];
        
        $this->userService->createUser($userData);
    }
}
```

### 6.2 持续集成

#### CI 检查项目
- 建议在 CI 中执行：代码风格检查、静态分析、单元测试、API 文档校验等
- 高风险改动应在 PR 中明确列出测试范围与结果
- 使用 `alkaid-dev-tools` 统一工具链进行 CI 检查

```yaml
# CI 配置示例 / CI configuration example
name: Quality Checks

on: [push, pull_request]

jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Setup Environment
        run: |
          docker compose up -d
          docker exec -it alkaid-backend composer install
      
      - name: Code Style Check
        run: |
          alkaid-dev-tools check:style --dry-run --report-format="github"
      
      - name: Static Analysis
        run: |
          alkaid-dev-tools check:security --scan="dependencies,code"
      
      - name: Run Tests
        run: |
          alkaid-dev-tools test:run --suite="all" --coverage --report
```

---

## 7. Git 与 PR 习惯

### 7.1 分支与提交信息

#### 分支命名
- **功能分支**：`feature/<scope>-<summary>`（如 `feature/auth-user-login`）
- **修复分支**：`fix/<scope>-<summary>`（如 `fix/user-profile-validation`）
- **热修复分支**：`hotfix/<scope>-<summary>`（如 `hotfix/security-token-leak`）

#### 提交信息规范
- 提交信息推荐采用 Conventional Commits：
  - `feat(auth): 添加用户登录功能 (T-066)`
  - `fix(api): 修复用户列表分页问题`
  - `docs(readme): 更新安装文档`
  - `refactor(user): 重构用户服务代码结构`
  - `test(auth): 增加认证相关单元测试`
  - `chore(deps): 更新依赖包版本`
  - `perf(api): 优化用户查询性能`
  - `style(php): 修复代码格式问题`

- 提交信息 header ≤ 72 字符
- 必须包含关联的 issue/任务编号

### 7.2 PR 粒度

#### PR 最佳实践
- 优先拆分为若干小而清晰的 PR，每个 PR 聚焦一个明确主题
- PR 描述中简要列出：
  - **变更点**：具体修改了什么
  - **涉及规范条目**：遵循了哪些规则和标准
  - **测试情况**：测试覆盖率和测试结果
  - **截图**：有 `frontend/` UI 变更需附截图

#### PR 模板
```markdown
## 变更描述 / Description
简要描述本次变更的内容和目的

## 变更类型 / Type
- [ ] 新功能 / New feature
- [ ] 问题修复 / Bug fix
- [ ] 重构 / Refactor
- [ ] 文档 / Documentation
- [ ] 测试 / Test
- [ ] 其他 / Other

## 测试 / Testing
- [ ] 单元测试通过 / Unit tests pass
- [ ] 集成测试通过 / Integration tests pass
- [ ] 手动测试通过 / Manual tests pass

## 检查清单 / Checklist
- [ ] 代码符合 PSR-12 规范
- [ ] 测试覆盖率达到 85% 以上
- [ ] 通过安全漏洞扫描
- [ ] API 文档已更新
- [ ] 遵循低代码优先原则
```

---

## 8. 工具使用指南

### 8.1 统一开发工具

#### alkaid-dev-tools 使用
- 优先使用 `alkaid-dev-tools` 统一工具链替代直接命令执行
- 所有工具命令都在 `alkaid-backend` 容器内执行

```bash
# 项目初始化 / Project initialization
alkaid-dev-tools init --project="新模块" --template="lowcode-crud"

# 代码生成 / Code generation
alkaid-dev-tools generate:scaffold --type="lowcode" --entity="User"
alkaid-dev-tools generate:test --entity="User" --coverage="85%"

# 质量检查 / Quality checks
alkaid-dev-tools check:style --fix
alkaid-dev-tools check:security --scan="dependencies,code"
alkaid-dev-tools check:performance --baseline="main"

# 测试执行 / Test execution
alkaid-dev-tools test:run --suite="unit,integration"
alkaid-dev-tools test:coverage --report="html"

# 文档生成 / Documentation generation
alkaid-dev-tools docs:generate --type="api" --output="docs/api/"
alkaid-dev-tools docs:update --section="architecture"
```

### 8.2 IDE 配置

#### VS Code 配置
- 推荐安装 PHP、Vue、TypeScript 相关扩展
- 配置 `.vscode/settings.json` 使用项目格式化工具

```json
{
  "php.validate.executablePath": "/usr/bin/php",
  "php.suggest.basic": false,
  "editor.formatOnSave": true,
  "editor.codeActionsOnSave": {
    "source.fixAll.eslint": true
  },
  "[php]": {
    "editor.defaultFormatter": "bmewburns.php-cs-fixer"
  },
  "[typescript]": {
    "editor.defaultFormatter": "esbenp.prettier-vscode"
  },
  "[vue]": {
    "editor.defaultFormatter": "esbenp.prettier-vscode"
  }
}
```

---

## 9. 性能优化指南

### 9.1 数据库优化

#### 查询优化
- 所有查询必须包含适当的索引使用
- 禁止 N+1 查询问题，必须使用预加载或 JOIN 优化
- 大数据量操作必须使用分页或流式处理

```php
// 避免 N+1 查询 / Avoid N+1 queries
// 错误方式 / Wrong way
$users = User::all();
foreach ($users as $user) {
    $user->roles; // N+1 查询
}

// 正确方式 / Correct way
$users = User::with('roles')->get(); // 预加载
```

#### 缓存策略
- 使用缓存策略减少数据库访问
- 合理设置缓存过期时间
- 使用 Redis 作为缓存存储

```php
// 缓存使用示例 / Cache usage example
class UserService
{
    public function getUserById(int $id): ?User
    {
        $cacheKey = "user:{$id}";
        
        return Cache::remember($cacheKey, 3600, function () use ($id) {
            return User::find($id);
        });
    }
}
```

### 9.2 前端性能

#### 组件优化
- 使用 Vue 3 的 Composition API 提高性能
- 合理使用 `computed` 和 `watch`
- 避免不必要的响应式数据

```typescript
// 性能优化示例 / Performance optimization example
import { computed, ref, watch } from 'vue'

// 使用 computed 缓存计算结果 / Use computed to cache calculation results
const expensiveValue = computed(() => {
  return heavyCalculation(sourceData.value)
})

// 使用 watch 的 immediate 选项 / Use immediate option with watch
watch(sourceData, (newValue) => {
  updateDerivedData(newValue)
}, { immediate: true })
```

---

## 10. 部署与运维

### 10.1 环境配置

#### 环境变量规范
- 统一采用 **全大写 + 下划线** 风格：`APP_ENV`、`DB_HOST`、`JWT_ISSUER`、`JWT_SECRET`
- 禁止在新代码中使用 `foo.bar` 形式的小写点分隔 key
- `.env.example` 作为 env 命名の"单一真相源"

#### 配置管理
- 使用 `alkaid-dev-tools` 统一工具进行配置管理
- 敏感配置通过安全渠道配置，不得提交到代码仓库

```bash
# 配置检查 / Configuration check
alkaid-dev-tools config:validate
alkaid-dev-tools config:diff --env="production"
```

### 10.2 部署流程

#### 自动化部署
- 使用 `alkaid-dev-tools` 进行自动化部署
- 部署前自动执行质量检查和测试
- 支持自动回滚机制

```bash
# 部署命令 / Deployment commands
alkaid-dev-tools deploy --env="staging" --rollback-enabled
alkaid-dev-tools deploy --env="production" --health-check
```

#### 监控与告警
- 关键业务指标必须实时监控，设置合理告警阈值
- 系统资源使用率（CPU、内存、磁盘）必须监控
- 错误率必须监控，超过阈值时自动告警

---

**核心原则**：本实现指南提供了详细的最佳实践和编码规范，所有开发者应严格遵循。当与核心规则冲突时，以核心规则为准。通过统一的工具链和规范，确保代码质量、可维护性和团队协作效率。
