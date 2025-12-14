# 登录 401 错误修复建议 | Login 401 Error Fix Recommendations

**日期 | Date**: 2025-11-25  
**关联诊断报告 | Related Diagnosis**: [login-401-error-diagnosis-2025-11-25.md](./login-401-error-diagnosis-2025-11-25.md)  
**优先级 | Priority**: 🔴 高 | High

---

## 修复方案概览 | Fix Solutions Overview

本文档提供三个修复方案，建议**同时实施方案 A 和方案 C**以彻底解决问题。

This document provides three fix solutions. It's recommended to **implement both Solution A and Solution C** to completely resolve the issue.

| 方案 | 描述 | 优先级 | 工作量 |
|------|------|--------|--------|
| **方案 A** | 更新前端测试数据 | 🔴 高 | 低 |
| **方案 B** | 更新后端测试数据 | 🟡 中 | 中 |
| **方案 C** | 增强前端错误处理 | 🔴 高 | 低 |

**推荐组合** | Recommended Combination:
- ✅ **方案 A + 方案 C**：快速修复，改善用户体验
- ⚠️ **方案 B + 方案 C**：保持前端代码不变，但需要数据库迁移

---

## 方案 A：更新前端测试数据（推荐）| Solution A: Update Frontend Test Data (Recommended)

### 优点 | Pros
- ✅ 快速修复，无需修改数据库
- ✅ 不影响现有后端逻辑
- ✅ 适合开发环境
- ✅ 无需数据库迁移

### 缺点 | Cons
- ⚠️ 需要修改前端代码
- ⚠️ 可能影响其他使用 MOCK_USER_OPTIONS 的地方

### 实施步骤 | Implementation Steps

#### 步骤 1：更新 MOCK_USER_OPTIONS

**文件** | File: `frontend/apps/web-antd/src/views/_core/authentication/login.vue`

```typescript
const MOCK_USER_OPTIONS: BasicOption[] = [
  {
    label: 'Test User',  // 改为实际的测试用户
    value: 'test@example.com',  // 使用数据库中的 email
  },
];
```

#### 步骤 2：更新表单依赖逻辑

**文件** | File: `frontend/apps/web-antd/src/views/_core/authentication/login.vue`

```typescript
dependencies: {
  trigger(values, form) {
    if (values.selectAccount) {
      const findUser = MOCK_USER_OPTIONS.find(
        (item) => item.value === values.selectAccount,
      );
      if (findUser) {
        form.setValues({
          password: 'password',  // 改为数据库中的密码
          username: findUser.value,  // 使用 email
        });
      }
    }
  },
  triggerFields: ['selectAccount'],
},
```

#### 步骤 3：更新默认值

**文件** | File: `frontend/apps/web-antd/src/views/_core/authentication/login.vue`

```typescript
{
  component: 'VbenSelect',
  componentProps: {
    options: MOCK_USER_OPTIONS,
    placeholder: $t('authentication.selectAccount'),
  },
  fieldName: 'selectAccount',
  label: $t('authentication.selectAccount'),
  rules: z
    .string()
    .min(1, { message: $t('authentication.selectAccount') })
    .optional()
    .default('test@example.com'),  // 改为新的默认值
},
```

### 验证步骤 | Verification Steps

1. 启动前端开发服务器：`cd frontend && pnpm dev`
2. 访问 `http://localhost:5666/auth/login`
3. 选择 "Test User" 账户
4. 验证自动填充的用户名为 `test@example.com`，密码为 `password`
5. 点击登录，应该成功

---

## 方案 B：更新后端测试数据 | Solution B: Update Backend Test Data

### 优点 | Pros
- ✅ 保持前端代码不变
- ✅ 可以创建多个测试用户
- ✅ 更符合真实场景

### 缺点 | Cons
- ⚠️ 需要数据库迁移或种子文件
- ⚠️ 需要重新运行迁移/种子
- ⚠️ 可能影响现有测试数据

### 实施步骤 | Implementation Steps

#### 步骤 1：创建数据库种子文件

**文件** | File: `database/seeds/TestUsersSeed.php`

```php
<?php

use think\migration\Seeder;

class TestUsersSeed extends Seeder
{
    public function run(): void
    {
        // Insert test users matching frontend MOCK_USER_OPTIONS
        $this->table('users')->insert([
            [
                'tenant_id' => 1,
                'username' => 'vben',
                'email' => 'vben@alkaidsys.local',
                'password' => password_hash('123456', PASSWORD_DEFAULT),
                'name' => 'Vben Admin',
                'status' => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'tenant_id' => 1,
                'username' => 'admin',
                'email' => 'admin@alkaidsys.local',
                'password' => password_hash('123456', PASSWORD_DEFAULT),
                'name' => 'Administrator',
                'status' => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'tenant_id' => 1,
                'username' => 'jack',
                'email' => 'jack@alkaidsys.local',
                'password' => password_hash('123456', PASSWORD_DEFAULT),
                'name' => 'Jack User',
                'status' => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ])->saveData();

        // Assign roles to users
        $this->table('user_roles')->insert([
            ['user_id' => 2, 'role_id' => 1, 'created_at' => date('Y-m-d H:i:s')], // vben -> admin
            ['user_id' => 3, 'role_id' => 1, 'created_at' => date('Y-m-d H:i:s')], // admin -> admin
            ['user_id' => 4, 'role_id' => 2, 'created_at' => date('Y-m-d H:i:s')], // jack -> user
        ])->saveData();
    }
}
```

#### 步骤 2：运行种子文件

```bash
docker exec alkaid-backend php think seed:run --seed TestUsersSeed
```

### 验证步骤 | Verification Steps

1. 查询数据库验证用户已创建：
   ```bash
   docker exec alkaid-mysql mysql -u root -proot alkaid_sys -e "SELECT id, username, email FROM users WHERE username IN ('vben', 'admin', 'jack');"
   ```
2. 启动前端，使用 `vben` / `123456` 登录
3. 应该成功

---

## 方案 C：增强前端错误处理（必须）| Solution C: Enhance Frontend Error Handling (Required)

### 重要性 | Importance

**无论选择方案 A 还是 B，都必须实施方案 C！**

**Regardless of choosing Solution A or B, Solution C MUST be implemented!**

### 问题 | Problem

当前 `authLogin` 方法缺少 `catch` 子句，导致：
- Promise 拒绝未被捕获
- 用户看不到错误提示
- 浏览器控制台出现大量错误

Current `authLogin` method lacks `catch` clause, causing:
- Uncaught Promise rejection
- No error message shown to user
- Browser console flooded with errors

### 实施步骤 | Implementation Steps

#### 步骤 1：更新 authLogin 方法

**文件** | File: `frontend/apps/web-antd/src/store/auth.ts`

```typescript
async function authLogin(
  params: Recordable<any>,
  onSuccess?: () => Promise<void> | void,
) {
  let userInfo: null | UserInfo = null;
  try {
    loginLoading.value = true;
    const { accessToken } = await loginApi(params);

    if (accessToken) {
      accessStore.setAccessToken(accessToken);
      userInfo = await fetchUserInfo();
      userStore.setUserInfo(userInfo);
      accessStore.setAccessCodes(userInfo.permissions || []);

      if (accessStore.loginExpired) {
        accessStore.setLoginExpired(false);
      } else {
        onSuccess
          ? await onSuccess?.()
          : await router.push(
              userInfo.homePath || preferences.app.defaultHomePath,
            );
      }

      if (userInfo?.realName) {
        notification.success({
          description: `${$t('authentication.loginSuccessDesc')}:${userInfo?.realName}`,
          duration: 3,
          message: $t('authentication.loginSuccess'),
        });
      }
    }
  } catch (error: any) {
    // 捕获登录错误 | Catch login errors
    console.error('[Auth] Login failed:', error);

    // 提取错误信息 | Extract error message
    const errorMessage =
      error?.response?.data?.message ||
      error?.data?.message ||
      error?.message ||
      $t('authentication.loginFailed');

    // 显示错误通知 | Show error notification
    notification.error({
      description: errorMessage,
      duration: 5,
      message: $t('authentication.loginFailedTitle') || 'Login Failed',
    });

    // 重新抛出错误，让调用者处理 | Re-throw error for caller to handle
    throw error;
  } finally {
    loginLoading.value = false;
  }

  return {
    userInfo,
  };
}
```

#### 步骤 2：添加国际化文本

**文件** | File: `frontend/packages/locales/src/langs/zh-CN/authentication.json`

```json
{
  "loginFailed": "登录失败",
  "loginFailedTitle": "登录失败",
  "loginFailedDesc": "用户名或密码错误，请重试"
}
```

**文件** | File: `frontend/packages/locales/src/langs/en-US/authentication.json`

```json
{
  "loginFailed": "Login failed",
  "loginFailedTitle": "Login Failed",
  "loginFailedDesc": "Invalid username or password, please try again"
}
```

### 验证步骤 | Verification Steps

1. 使用错误的凭证登录
2. 应该看到友好的错误提示通知
3. 浏览器控制台不应有 Uncaught Promise 错误
4. 用户体验良好

---

## 测试验证计划 | Test Verification Plan

### 1. 单元测试 | Unit Tests

创建 `frontend/apps/web-antd/src/store/__tests__/auth.spec.ts`:

```typescript
import { describe, it, expect, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useAuthStore } from '../auth';
import * as authApi from '#/api/core/auth';

describe('Auth Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('should handle login error gracefully', async () => {
    const authStore = useAuthStore();
    
    // Mock loginApi to throw 401 error
    vi.spyOn(authApi, 'loginApi').mockRejectedValue({
      response: {
        data: {
          code: 401,
          message: 'Invalid credentials',
        },
      },
    });

    // Attempt login
    await expect(
      authStore.authLogin({ username: 'wrong', password: 'wrong' })
    ).rejects.toThrow();

    // Verify loading state is reset
    expect(authStore.loginLoading).toBe(false);
  });
});
```

### 2. E2E 测试 | E2E Tests

已创建：`frontend/apps/web-antd/tests/e2e/login-401-error.spec.ts`

运行测试：
```bash
cd frontend && pnpm --filter=@vben/web-antd test:e2e login-401-error.spec.ts
```

---

## 总结 | Summary

### 推荐实施方案 | Recommended Implementation

✅ **方案 A + 方案 C**（推荐）

**理由** | Rationale:
1. 快速修复，无需数据库迁移
2. 改善用户体验，增加错误处理
3. 工作量小，风险低
4. 适合开发环境

### 实施顺序 | Implementation Order

1. **第一步**：实施方案 C（增强错误处理）
   - 确保用户看到友好的错误提示
   - 改善开发体验

2. **第二步**：实施方案 A（更新前端测试数据）
   - 修复凭证不匹配问题
   - 使登录功能可用

3. **第三步**：运行测试验证
   - 单元测试
   - E2E 测试
   - 手动测试

### 预期结果 | Expected Outcome

- ✅ 用户可以成功登录
- ✅ 登录失败时显示友好的错误提示
- ✅ 无浏览器控制台错误
- ✅ 良好的用户体验
- ✅ 所有测试通过

