【状态更新（2025-12-14）】本计划所描述的前端权限集成工作已全部落地，详细实现与提交记录见 `permission-integration-frontend-commit-message` 及相关 summary 记忆。

# 前后端权限集成（前端部分）- 任务规划

**任务**：[P0] 前后端权限集成 - 前端部分  
**日期**：2025-01-24  
**依赖**：后端部分已完成（Commit: 51b23f7a0721088818d1ad4d4049874ab1da7b1c）

## 任务背景

**后端 API 已实现**：
- ✅ GET /v1/auth/me - 返回包含 permissions 字段的用户信息
- ✅ GET /v1/auth/codes - 返回权限码数组
- ✅ 权限格式：resource:action（如 forms:view, forms:create）

**前端当前状态**：
- ❌ getUserInfoApi() 调用 /user/info（错误的端点）
- ❌ getAccessCodesApi() 调用 /auth/codes（正确，但使用 mock 数据）
- ❌ auth store 分别调用 getUserInfoApi() 和 getAccessCodesApi()
- ❌ UserInfo 类型定义缺少 permissions 字段

## 代码结构分析

### 1. 类型定义

**BasicUserInfo**（`frontend/packages/@core/base/typings/src/basic.d.ts`）：
```typescript
interface BasicUserInfo {
  avatar: string;
  realName: string;
  roles?: string[];
  userId: string;
  username: string;
}
```

**UserInfo**（`frontend/packages/types/src/user.ts`）：
```typescript
interface UserInfo extends BasicUserInfo {
  desc: string;
  homePath: string;
  token: string;
}
```

**问题**：缺少 `permissions: string[]` 字段

### 2. API 层

**getUserInfoApi**（`frontend/apps/web-antd/src/api/core/user.ts`）：
```typescript
export async function getUserInfoApi() {
  return requestClient.get<UserInfo>('/user/info');
}
```

**问题**：
1. 端点错误：应该是 `/v1/auth/me` 而不是 `/user/info`
2. 返回的数据结构与后端不匹配

**getAccessCodesApi**（`frontend/apps/web-antd/src/api/core/auth.ts`）：
```typescript
export async function getAccessCodesApi() {
  return requestClient.get<string[]>('/auth/codes');
}
```

**问题**：端点应该是 `/v1/auth/codes`

### 3. Auth Store

**authLogin**（`frontend/apps/web-antd/src/store/auth.ts` 第 43-51 行）：
```typescript
const [fetchUserInfoResult, accessCodes] = await Promise.all([
  fetchUserInfo(),
  getAccessCodesApi(),
]);

userInfo = fetchUserInfoResult;

userStore.setUserInfo(userInfo);
accessStore.setAccessCodes(accessCodes);
```

**问题**：
- 分别调用两个 API
- 应该从 userInfo.permissions 获取权限码

## 实施方案

### 方案选择

**方案 A**：修改 getUserInfoApi 端点，从 /v1/auth/me 获取数据，并从中提取 permissions
- 优点：符合后端设计，一次请求获取所有数据
- 缺点：需要处理后端返回的嵌套结构（data.user, data.permissions）

**方案 B**：保持 getUserInfoApi 不变，修改 getAccessCodesApi 使用 /v1/auth/codes
- 优点：改动最小
- 缺点：需要两次请求

**决定**：采用**方案 A**，因为：
1. 符合后端设计（/v1/auth/me 是主要端点）
2. 减少 HTTP 请求次数
3. 数据一致性更好

### 详细实施计划

#### 任务 1：更新类型定义

**文件**：`frontend/packages/@core/base/typings/src/basic.d.ts`

**修改**：
```typescript
interface BasicUserInfo {
  avatar: string;
  realName: string;
  roles?: string[];
  userId: string;
  username: string;
  /**
   * 用户权限码（resource:action 格式）
   */
  permissions?: string[];  // 新增
}
```

#### 任务 2：修改 getUserInfoApi

**文件**：`frontend/apps/web-antd/src/api/core/user.ts`

**修改**：
```typescript
import type { UserInfo } from '@vben/types';

import { requestClient } from '#/api/request';

/**
 * 获取用户信息
 * 从 /v1/auth/me 获取用户信息和权限
 */
export async function getUserInfoApi() {
  // 调用后端 /v1/auth/me API
  const response = await requestClient.get<{
    user: {
      id: number;
      tenant_id: number;
      username: string;
      email: string;
      name?: string;
      avatar?: string;
      status: string;
    };
    roles: number[];
    permissions: string[];  // resource:action 格式
  }>('/v1/auth/me');
  
  // 转换为前端 UserInfo 格式
  const userInfo: UserInfo = {
    userId: String(response.user.id),
    username: response.user.username,
    realName: response.user.name || response.user.username,
    avatar: response.user.avatar || '',
    roles: response.roles.map(String),  // 转换为字符串数组
    permissions: response.permissions,  // 新增
    desc: '',
    homePath: '/dashboard',
    token: '',  // token 由 accessStore 管理
  };
  
  return userInfo;
}
```

#### 任务 3：实现 getAccessCodesFromMe

**文件**：`frontend/apps/web-antd/src/api/core/auth.ts`

**修改**：
```typescript
/**
 * 从用户信息中获取权限码
 * 这是推荐的方式，避免额外的 HTTP 请求
 */
export async function getAccessCodesFromMe(): Promise<string[]> {
  const userInfo = await getUserInfoApi();
  return userInfo.permissions || [];
}
```

**保留 getAccessCodesApi**（向后兼容）：
```typescript
/**
 * 获取用户权限码
 * 直接调用 /v1/auth/codes API
 * 注意：推荐使用 getAccessCodesFromMe() 以减少 HTTP 请求
 */
export async function getAccessCodesApi() {
  return requestClient.get<string[]>('/v1/auth/codes');
}
```

#### 任务 4：修改 Auth Store

**文件**：`frontend/apps/web-antd/src/store/auth.ts`

**修改 authLogin 方法**（第 43-51 行）：
```typescript
// 修改前
const [fetchUserInfoResult, accessCodes] = await Promise.all([
  fetchUserInfo(),
  getAccessCodesApi(),
]);

userInfo = fetchUserInfoResult;

userStore.setUserInfo(userInfo);
accessStore.setAccessCodes(accessCodes);

// 修改后
userInfo = await fetchUserInfo();

userStore.setUserInfo(userInfo);
// 从用户信息中提取权限码
accessStore.setAccessCodes(userInfo.permissions || []);
```

**说明**：
- 移除 `getAccessCodesApi()` 调用
- 直接从 `userInfo.permissions` 获取权限码
- 减少一次 HTTP 请求

#### 任务 5：同步修改其他应用

需要同步修改以下应用（保持一致性）：
1. `frontend/apps/web-ele/`
2. `frontend/apps/web-naive/`
3. `frontend/apps/web-tdesign/`
4. `frontend/playground/`

**修改内容**：
- `src/api/core/user.ts` - 修改 getUserInfoApi
- `src/api/core/auth.ts` - 添加 getAccessCodesFromMe
- `src/store/auth.ts` - 修改 authLogin 方法

## 验收标准

### 功能验收

- [ ] 登录成功后，用户信息包含 permissions 字段
- [ ] accessStore.accessCodes 包含正确的权限码
- [ ] 权限码格式为 resource:action
- [ ] 权限控制生效（按钮显隐、路由访问）
- [ ] 无权限时正确显示 403 或隐藏功能

### 技术验收

- [ ] TypeScript 类型定义正确
- [ ] API 调用正确（/v1/auth/me）
- [ ] 数据转换正确（后端格式 → 前端格式）
- [ ] 减少了 HTTP 请求次数（从 2 次减少到 1 次）
- [ ] 代码符合前端项目规范

### 测试验收

- [ ] 手动测试：登录后验证权限正确加载
- [ ] 手动测试：权限控制生效
- [ ] 手动测试：无权限时正确处理
- [ ] （可选）E2E 测试通过

## 风险评估

### 风险 1：后端响应格式不匹配

**风险**：后端 /v1/auth/me 返回的数据结构与预期不符

**缓解措施**：
- 先测试后端 API，确认响应格式
- 添加类型检查和错误处理
- 在开发环境中验证

### 风险 2：前端类型定义冲突

**风险**：修改 BasicUserInfo 可能影响其他使用该类型的代码

**缓解措施**：
- permissions 字段设为可选（`permissions?: string[]`）
- 向后兼容，不破坏现有代码
- 在代码审查中检查影响范围

### 风险 3：多应用同步问题

**风险**：有 5 个应用需要同步修改，可能遗漏

**缓解措施**：
- 使用 codebase-retrieval 查找所有需要修改的文件
- 逐个应用修改并测试
- 在代码审查中确认所有应用都已修改

## 参考文档

- `docs/todo/vben-backend-integration-plan.md`
- `docs/report_archive/2025.11.24/vben-permission-integration-decision.md`
- `design/06-frontend-design/20-frontend-state-management.md`
- 后端 commit: 51b23f7a0721088818d1ad4d4049874ab1da7b1c

## 预估工时

- 类型定义修改：0.5 小时
- API 层修改：1 小时
- Auth Store 修改：0.5 小时
- 其他应用同步：1 小时
- 测试验证：1 小时
- **总计**：4 小时（约 0.5 天）

## 下一步

1. ⏳ 开始开发阶段
2. ⏳ 修改类型定义
3. ⏳ 修改 API 层
4. ⏳ 修改 Auth Store
5. ⏳ 同步其他应用
6. ⏳ 测试验证
