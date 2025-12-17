# 前后端权限集成任务规划

**任务**：[P0] 前后端权限集成（PermissionService + /v1/auth/me + Auth Store）

**日期**：2025-01-24  
**优先级**：P0（阻塞性问题）  
**预估工时**：2-3 天

## 任务背景

**来源**：`docs/todo/development-backlog-2025-11-23.md` 第 76-86 行

**当前状态**：
- ❌ 后端 `PermissionService` 未实现
- ❌ `/v1/auth/me` 不返回 `permissions` 字段
- ❌ `/v1/auth/codes` 未实现
- ❌ 前端 `getUserInfoApi` 未处理 `permissions`
- ❌ Auth Store 仍使用 mock 数据

**设计文档**：
- `docs/todo/vben-backend-integration-plan.md`
- `docs/report_archive/2025.11.24/vben-permission-integration-decision.md`

## 权限格式规范

**内部实现**（数据库 & 中间件）：
- 格式：`resource.action`（点分隔）
- 示例：`forms.view`, `forms.create`
- 字段：`permissions.slug`

**外部暴露**（API & 前端）：
- 格式：`resource:action`（冒号分隔）
- 示例：`forms:view`, `forms:create`
- 用途：API 响应、Vben accessCodes

**转换规则**：
```
内部 slug: forms.view
外部 code: forms:view
转换方式: resource + ':' + action
```

## 实施计划

### 阶段 1：后端实现（Backend-P0）

#### 任务 1.1：创建 PermissionService

**文件**：`Infrastructure/Permission/Service/PermissionService.php`

**方法**：
```php
public function getUserPermissions(int $userId): array
```

**逻辑**：
1. 查询 `user_roles` 获取用户的所有角色 ID
2. 查询 `role_permissions` 获取角色的所有权限 ID
3. 查询 `permissions` 获取权限详情（resource, action）
4. 去重并转换为 `resource:action` 格式
5. 返回字符串数组

**验收标准**：
- ✅ 用户无角色时返回空数组
- ✅ 用户有多角色时正确去重
- ✅ 正确转换为 `resource:action` 格式
- ✅ 单元测试覆盖

#### 任务 1.2：扩展 AuthController::me()

**文件**：`app/controller/AuthController.php`

**修改**：
1. 注入 `PermissionService`
2. 调用 `getUserPermissions($userId)`
3. 在响应 data 中添加 `permissions` 字段

**响应格式**：
```json
{
  "code": 0,
  "message": "Success",
  "data": {
    "id": 1,
    "username": "admin",
    "email": "admin@example.com",
    "roles": ["admin"],
    "permissions": ["forms:view", "forms:create", "users:view"]
  },
  "timestamp": 1234567890,
  "trace_id": "xxx"
}
```

**验收标准**：
- ✅ API 返回 `permissions` 字段
- ✅ 权限格式为 `resource:action`
- ✅ 与 Permission 中间件行为一致
- ✅ 集成测试覆盖

#### 任务 1.3：实现 /v1/auth/codes（可选）

**文件**：
- `route/auth.php` - 添加路由
- `app/controller/AuthController.php` - 添加 `codes()` 方法

**方法**：
```php
public function codes(Request $request): Response
{
    $userId = $request->userId();
    $permissions = $this->permissionService->getUserPermissions($userId);
    
    return $this->success($permissions);
}
```

**响应格式**：
```json
{
  "code": 0,
  "message": "Success",
  "data": ["forms:view", "forms:create", "users:view"],
  "timestamp": 1234567890,
  "trace_id": "xxx"
}
```

**验收标准**：
- ✅ 返回与 `/v1/auth/me.permissions` 一致的数组
- ✅ 需要认证（Auth 中间件）
- ✅ 集成测试覆盖

### 阶段 2：前端实现（Frontend-P0）

#### 任务 2.1：调整 getUserInfoApi

**文件**：`frontend/apps/web-antd/src/api/core/user.ts`

**修改**：
1. 更新 TypeScript 类型定义，添加 `permissions` 字段
2. 处理 API 响应中的 `permissions` 字段

**类型定义**：
```typescript
interface UserInfo {
  id: number;
  username: string;
  email: string;
  roles: string[];
  permissions: string[];  // 新增
}
```

#### 任务 2.2：实现 getAccessCodesFromMe

**文件**：`frontend/apps/web-antd/src/api/core/auth.ts`

**方法**：
```typescript
export async function getAccessCodesFromMe(): Promise<string[]> {
  const userInfo = await getUserInfoApi();
  return userInfo.permissions || [];
}
```

#### 任务 2.3：调整 Auth Store

**文件**：`frontend/apps/web-antd/src/store/auth.ts`

**修改**：
1. 在登录成功后调用 `getUserInfoApi()`
2. 从用户信息中提取 `permissions`
3. 调用 `accessStore.setAccessCodes(permissions)`

**流程**：
```
登录成功 → 获取用户信息 → 提取 permissions → 设置 accessCodes
```

### 阶段 3：测试（Test-P1）

#### 任务 3.1：PermissionService 单元测试

**文件**：`tests/Unit/Infrastructure/Permission/PermissionServiceTest.php`

**测试用例**：
1. 用户无角色时返回空数组
2. 用户有单个角色时返回正确权限
3. 用户有多个角色时正确去重
4. 权限格式转换正确（`forms.view` → `forms:view`）

#### 任务 3.2：AuthController 集成测试

**文件**：`tests/Feature/AuthControllerTest.php`

**测试用例**：
1. `/v1/auth/me` 返回 `permissions` 字段
2. `permissions` 格式为 `resource:action`
3. 权限与用户角色一致
4. 未认证时返回 401

#### 任务 3.3：/v1/auth/codes 集成测试（如果实现）

**文件**：`tests/Feature/AuthCodesTest.php`

**测试用例**：
1. 返回与 `/v1/auth/me.permissions` 一致的数组
2. 需要认证
3. 权限格式正确

#### 任务 3.4：前端集成测试

**测试内容**：
1. 登录后正确加载权限
2. accessStore 包含正确的权限码
3. 权限控制生效（按钮显隐、路由访问）

## 验收标准

### 后端验收标准

- [ ] PermissionService 实现完成
- [ ] `/v1/auth/me` 返回 `permissions` 字段
- [ ] `/v1/auth/codes` 实现完成（可选）
- [ ] 权限格式为 `resource:action`
- [ ] 所有后端测试通过（100%）
- [ ] 代码审查通过（≥ 90/100）

### 前端验收标准

- [ ] getUserInfoApi 处理 `permissions` 字段
- [ ] getAccessCodesFromMe 实现完成
- [ ] Auth Store 正确设置 accessCodes
- [ ] 权限控制生效
- [ ] 前端测试通过

### 集成验收标准

- [ ] 登录后正确加载权限
- [ ] 权限控制与后端一致
- [ ] 无权限时正确显示 403
- [ ] E2E 测试通过

## 风险评估

### 风险 1：权限格式不一致

**风险**：内部 `resource.action` 与外部 `resource:action` 转换错误

**缓解措施**：
- 统一使用 PermissionService 进行转换
- 添加单元测试验证转换逻辑
- 在代码审查中重点检查

### 风险 2：前后端权限不一致

**风险**：前端显示的权限与后端实际权限不一致

**缓解措施**：
- 权限只在后端计算，前端只消费
- 添加集成测试验证一致性
- 在 E2E 测试中覆盖典型场景

### 风险 3：性能问题

**风险**：每次请求都查询权限可能影响性能

**缓解措施**：
- 在 PermissionService 中添加缓存
- 使用 Redis 缓存用户权限
- 设置合理的缓存过期时间

## 参考文档

- `docs/todo/development-backlog-2025-11-23.md` 第 76-86 行
- `docs/todo/vben-backend-integration-plan.md`
- `docs/report_archive/2025.11.24/vben-permission-integration-decision.md`
- `design/04-security-performance/11-security-design.md`
- `design/06-frontend-design/20-frontend-state-management.md`
