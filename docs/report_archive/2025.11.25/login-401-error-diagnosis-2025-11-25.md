# 登录 401 错误诊断报告 | Login 401 Error Diagnosis Report

**日期 | Date**: 2025-11-25  
**状态 | Status**: 🔍 已诊断 | Diagnosed  
**严重程度 | Severity**: 🔴 高 | High  
**影响范围 | Impact**: 前端登录功能完全不可用 | Frontend login completely broken

---

## 问题概述 | Problem Overview

### 错误现象 | Error Symptoms

当用户在浏览器中访问 `http://localhost:5666/` 并点击"登录"按钮后，出现以下错误：

When users visit `http://localhost:5666/` and click the "Login" button, the following errors occur:

#### 1. Vue 警告 | Vue Warning
```
[Vue warn]: Unhandled error during execution of component event handler 
  at <AuthenticationLogin form-schema=(4) [{…}, {…}, {…}, {…}] loading=false onSubmit=fn ... >
```

#### 2. Promise 拒绝 | Promise Rejection
```javascript
Uncaught (in promise) 
{
  code: 401,
  message: 'Invalid credentials',
  data: null,
  timestamp: 1764072683
}
```

### 复现步骤 | Reproduction Steps

1. 启动前端开发服务器：`cd frontend && pnpm dev`
2. 访问 `http://localhost:5666/`
3. 在登录表单中选择账户（如 "Super" / "vben"）
4. 自动填充用户名 `vben` 和密码 `123456`
5. 完成滑块验证
6. 点击"登录"按钮
7. **结果**：登录失败，返回 401 Unauthorized 错误

---

## 错误分析 | Error Analysis

### 前端错误堆栈分析 | Frontend Error Stack Analysis

#### 错误传播路径 | Error Propagation Path

```
login.vue:75 (handleSubmit)
    ↓
AuthenticationLogin 组件 emit('submit', values)
    ↓
authStore.authLogin(params)
    ↓
loginApi(params) → POST /v1/auth/login
    ↓
❌ 401 Unauthorized: "Invalid credentials"
    ↓
Promise 拒绝未被捕获 | Uncaught Promise Rejection
    ↓
Vue 警告：组件事件处理器中的未捕获错误
```

#### 关键代码位置 | Key Code Locations

**1. 前端登录表单** | Frontend Login Form  
文件 | File: `frontend/apps/web-antd/src/views/_core/authentication/login.vue`

```vue
<template>
  <AuthenticationLogin
    :form-schema="formSchema"
    :loading="authStore.loginLoading"
    @submit="authStore.authLogin"  <!-- 第 96 行：直接调用 authStore.authLogin -->
  />
</template>
```

**2. 表单提交处理** | Form Submit Handler  
文件 | File: `frontend/packages/effects/common-ui/src/ui/authentication/login.vue:75`

```typescript
async function handleSubmit() {
  const { valid } = await formApi.validate();
  const values = await formApi.getValues();
  if (valid) {
    localStorage.setItem(
      REMEMBER_ME_KEY,
      rememberMe.value ? values?.username : '',
    );
    emit('submit', values);  // 第 75 行：触发 submit 事件
  }
}
```

**3. 认证 Store** | Auth Store  
文件 | File: `frontend/apps/web-antd/src/store/auth.ts:28-76`

```typescript
async function authLogin(
  params: Recordable<any>,
  onSuccess?: () => Promise<void> | void,
) {
  let userInfo: null | UserInfo = null;
  try {
    loginLoading.value = true;
    const { accessToken } = await loginApi(params);  // 第 36 行：调用登录 API
    // ... 后续处理
  } finally {
    loginLoading.value = false;  // ❌ 错误未被捕获，直接传播
  }
}
```

**问题**：`authLogin` 方法中的 `try-finally` 块**没有 `catch` 子句**，导致 Promise 拒绝未被捕获。

---

### 后端 API 响应分析 | Backend API Response Analysis

#### 登录接口实现 | Login Endpoint Implementation

文件 | File: `app/controller/AuthController.php:60-117`

```php
public function login(\think\Request $request): Response
{
    $data = $request->post();

    // Validate input | 验证输入
    if (empty($data['email']) || empty($data['password'])) {
        return $this->error('Email and password are required');
    }

    // Get tenant ID | 获取租户ID
    $tenantId = $request->tenantId();

    try {
        // Find user by email | 通过邮箱查找用户
        $user = $this->userRepository->findByEmail($tenantId, $data['email']);

        if (!$user) {
            return $this->error('Invalid credentials', 401);  // ← 返回 401
        }

        // Verify password | 验证密码
        if (!$user->verifyPassword($data['password'])) {
            return $this->error('Invalid credentials', 401);  // ← 返回 401
        }
        // ...
    }
}
```

#### 错误响应格式 | Error Response Format

```json
{
  "code": 401,
  "message": "Invalid credentials",
  "data": null,
  "timestamp": 1764072683
}
```

---

### 网络请求详情 | Network Request Details

#### 请求 | Request

```http
POST /v1/auth/login HTTP/1.1
Host: localhost
Content-Type: application/json
Origin: http://localhost:5666

{
  "email": "vben",
  "password": "123456"
}
```

#### 响应 | Response

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json

{
  "code": 401,
  "message": "Invalid credentials",
  "data": null,
  "timestamp": 1764072683
}
```

---

## 根本原因 | Root Cause

### 🔴 主要问题：测试数据不匹配 | Primary Issue: Test Data Mismatch

**前端登录表单使用的凭证** | Frontend Login Form Credentials:
- Username: `vben`
- Password: `123456`

**数据库中的实际测试用户** | Actual Test User in Database:
- Email: `test@example.com`
- Password: `password`
- Status: `active`

**不匹配原因** | Mismatch Reason:
1. 前端表单使用 `MOCK_USER_OPTIONS` 定义的模拟用户（`vben`, `admin`, `jack`）
2. 后端数据库中只有一个测试用户（`test@example.com`）
3. 前端将 `username` 字段映射为 `email` 发送到后端（见 `auth.ts:33`）
4. 后端尝试通过 `email = "vben"` 查找用户，找不到，返回 401

### 🟡 次要问题：前端错误处理缺失 | Secondary Issue: Missing Frontend Error Handling

**问题代码** | Problematic Code:
```typescript
// frontend/apps/web-antd/src/store/auth.ts:28-76
async function authLogin(params: Recordable<any>, onSuccess?: () => Promise<void> | void) {
  let userInfo: null | UserInfo = null;
  try {
    loginLoading.value = true;
    const { accessToken } = await loginApi(params);
    // ...
  } finally {  // ❌ 缺少 catch 子句
    loginLoading.value = false;
  }
}
```

**后果** | Consequences:
1. 登录失败时，Promise 拒绝未被捕获
2. 用户看不到友好的错误提示
3. 浏览器控制台出现 Vue 警告和 Uncaught Promise 错误
4. 用户体验极差

---

## 影响范围 | Impact Scope

### 功能影响 | Functional Impact
- ❌ 前端登录功能完全不可用
- ❌ 用户无法登录系统
- ❌ 所有需要认证的功能无法访问

### 用户体验影响 | User Experience Impact
- ❌ 无友好的错误提示
- ❌ 浏览器控制台出现大量错误信息
- ❌ 用户不知道如何解决问题

### 开发体验影响 | Developer Experience Impact
- ❌ 前端开发者无法测试需要认证的功能
- ❌ E2E 测试可能失败
- ❌ 开发效率降低

---

## 修复建议 | Fix Recommendations

### 方案 A：更新前端测试数据（推荐）| Solution A: Update Frontend Test Data (Recommended)

**优点** | Pros:
- 快速修复，无需修改数据库
- 不影响现有后端逻辑
- 适合开发环境

**实施步骤** | Implementation Steps:

1. 更新 `frontend/apps/web-antd/src/views/_core/authentication/login.vue` 中的 `MOCK_USER_OPTIONS`：

```typescript
const MOCK_USER_OPTIONS: BasicOption[] = [
  {
    label: 'Test User',
    value: 'test@example.com',  // 改为数据库中的 email
  },
];
```

2. 更新表单依赖逻辑：

```typescript
dependencies: {
  trigger(values, form) {
    if (values.selectAccount) {
      form.setValues({
        password: 'password',  // 改为数据库中的密码
        username: values.selectAccount,  // 使用 email
      });
    }
  },
  triggerFields: ['selectAccount'],
},
```

### 方案 B：更新后端测试数据 | Solution B: Update Backend Test Data

**优点** | Pros:
- 保持前端代码不变
- 可以创建多个测试用户

**实施步骤** | Implementation Steps:

1. 创建数据库种子文件或迁移脚本
2. 插入前端期望的测试用户：

```sql
INSERT INTO users (tenant_id, username, email, password, name, status, created_at, updated_at)
VALUES
  (1, 'vben', 'vben@alkaidsys.local', PASSWORD_HASH('123456'), 'Vben Admin', 'active', NOW(), NOW()),
  (1, 'admin', 'admin@alkaidsys.local', PASSWORD_HASH('123456'), 'Administrator', 'active', NOW(), NOW()),
  (1, 'jack', 'jack@alkaidsys.local', PASSWORD_HASH('123456'), 'Jack User', 'active', NOW(), NOW());
```

### 方案 C：增强前端错误处理（必须）| Solution C: Enhance Frontend Error Handling (Required)

**无论选择方案 A 还是 B，都必须修复前端错误处理！**

**实施步骤** | Implementation Steps:

更新 `frontend/apps/web-antd/src/store/auth.ts`:

```typescript
async function authLogin(params: Recordable<any>, onSuccess?: () => Promise<void> | void) {
  let userInfo: null | UserInfo = null;
  try {
    loginLoading.value = true;
    const { accessToken } = await loginApi(params);
    // ... 成功处理逻辑
  } catch (error: any) {
    // 捕获登录错误
    const errorMessage = error?.response?.data?.message || error?.message || $t('authentication.loginFailed');
    
    notification.error({
      description: errorMessage,
      duration: 5,
      message: $t('authentication.loginFailedTitle'),
    });
    
    // 可选：记录错误日志
    console.error('[Auth] Login failed:', error);
    
    throw error;  // 重新抛出，让调用者处理
  } finally {
    loginLoading.value = false;
  }
}
```

---

## 测试验证计划 | Test Verification Plan

### 1. 单元测试 | Unit Tests
- [ ] 测试 `authLogin` 方法的错误处理
- [ ] 测试 `loginApi` 的参数映射

### 2. 集成测试 | Integration Tests
- [ ] 测试完整的登录流程（成功场景）
- [ ] 测试登录失败场景（401 错误）
- [ ] 测试错误提示是否正确显示

### 3. E2E 测试 | E2E Tests
- [ ] 使用 Playwright 测试登录流程
- [ ] 验证错误提示的可见性
- [ ] 验证网络请求和响应

### 4. 手动测试 | Manual Tests
- [ ] 在浏览器中测试登录成功场景
- [ ] 在浏览器中测试登录失败场景
- [ ] 验证用户体验是否友好

---

## 附录 | Appendix

### A. 相关代码文件 | Related Code Files

- `frontend/apps/web-antd/src/views/_core/authentication/login.vue`
- `frontend/apps/web-antd/src/store/auth.ts`
- `frontend/apps/web-antd/src/api/core/auth.ts`
- `frontend/packages/effects/common-ui/src/ui/authentication/login.vue`
- `app/controller/AuthController.php`
- `domain/User/Model/User.php`

### B. 数据库查询结果 | Database Query Results

```sql
SELECT id, username, email, status FROM users WHERE id=1;
```

| id | username  | email              | status |
|----|-----------|-------------------|--------|
| 1  | test_user | test@example.com  | active |

密码哈希 | Password Hash: `$2y$10$VWg4MU0oVwngnXLPOhDoAuyC.RhkEtNjLaWUuNCWPfa./tFdIsPLK`  
明文密码 | Plain Password: `password`

---

## 总结 | Summary

**根本原因** | Root Cause:
- 前端使用的测试凭证（`vben` / `123456`）与数据库中的实际用户（`test@example.com` / `password`）不匹配

**修复优先级** | Fix Priority:
1. 🔴 **高优先级**：增强前端错误处理（方案 C）
2. 🟡 **中优先级**：同步前后端测试数据（方案 A 或 B）

**预期结果** | Expected Outcome:
- ✅ 用户可以成功登录
- ✅ 登录失败时显示友好的错误提示
- ✅ 无浏览器控制台错误
- ✅ 良好的用户体验

