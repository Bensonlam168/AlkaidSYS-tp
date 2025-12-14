# E2E Auth Permission Tests Failure Diagnosis / 前端权限 E2E 测试失败诊断报告

> File: `docs/report/e2e-auth-permissions-failure-diagnosis-2025-11-25.md`
> Date: 2025-11-25
> Scope: `@vben/web-antd` Playwright E2E for `/v1/auth/me` permission integration

---

## 1. Problem Overview / 问题概述

- **Failing test file / 失败用例文件**  
  `frontend/apps/web-antd/tests/e2e/auth-permissions.spec.ts`
- **Failing test cases / 失败的测试用例：**
  - `Auth & permission integration (/v1/auth/me) › should login and receive permissions from /v1/auth/me`
  - `Auth & permission integration (/v1/auth/me) › should persist permissions into access store (localStorage)`
- **Error summary / 错误信息摘要：**
  - `Error: page.evaluate: AxiosError` (thrown from `common/auth.ts:14`, inside `authLogin(page)` helper)
  - `Error: page.waitForResponse: Test ended.`（因为用例提前抛错，`page.waitForResponse` 等待 `/v1/auth/me` 时测试已结束）
- **Impact scope / 影响范围：**
  - 仅影响 `@vben/web-antd` 中的权限集成 E2E 测试；  
    `@vben/playground` 的登录 E2E 用例全部通过，说明 Playwright 环境与后端基础链路正常。

---

## 2. Verified Working Parts / 已验证的正常工作部分

### 2.1 Backend Feature Tests for /v1/auth/*

- **Location / 位置：** `tests/Feature/Auth/`（特别是 `AuthPermissionIntegrationTest.php`）
- **Coverage / 覆盖点：**
  - `GET /v1/auth/me`：
    - HTTP 200
    - 响应结构：`{ code, message, data, timestamp, trace_id? }`
    - `data.permissions` 为字符串数组，元素为 `resource:action`（如 `lowcode:read`）
    - 权限数组来源于当前用户绑定的角色与权限表
  - `GET /v1/auth/codes`：
    - HTTP 200
    - `data` 为 `resource:action` 数组，与 `/v1/auth/me` 权限保持一致
- **Execution result / 执行结果：**
  - 在容器 `alkaid-backend` 内运行 PHPUnit：
    - `docker exec -it alkaid-backend ./vendor/bin/phpunit tests/Feature/Auth/`
  - 所有与认证和权限相关的 Feature Tests 通过（约 8 个测试，260+ 断言），仅存在 PHPUnit 弃用 warning，不影响语义正确性。

### 2.2 Backend API Manual Verification / 后端 API 手工验证

- **Command / 使用命令：**
  - 在宿主机仓库根目录执行：
    - 初次：
      ```bash
      curl -i -X POST http://localhost/v1/auth/login \
        -H 'Content-Type: application/json' \
        -d '{"email":"test@example.com","password":"password"}'
      ```
      返回：`HTTP/1.1 400 Bad Request`，JSON `code=401, message="Invalid credentials"`。
    - 运行测试数据脚本后再次执行：
      ```bash
      curl -i -X POST http://localhost/v1/auth/login \
        -H 'Content-Type: application/json' \
        -d '{"email":"test@example.com","password":"password"}'
      ```
      返回：`HTTP/1.1 200 OK`，JSON `code=0, message="Login successful"`，并带有 `access_token`、`refresh_token`、`user` 等字段。
- **Conclusion / 结论：**
  - 经测试数据脚本修正后，使用 `test@example.com / password` 能在 E2E 所依赖的真实环境中成功完成登录，后端登录接口本身工作正常。

### 2.3 Test Data Setup Script / 测试数据准备脚本

- **Script path / 脚本路径：** `tests/setup_test_data.php`
- **Core behavior / 核心行为：**
  - 确保 `users` 表中存在 `id=1` 的测试用户：
    - 初始插入：`username = test_user`, `email = test@example.com`, `password = password_hash('password')`, `tenant_id = 1` 等；
    - 若用户已存在，则执行一次 `UPDATE`，强制同步上述字段，保证测试账号凭据始终可用。
  - 创建/校验低代码权限：`lowcode.read`, `lowcode.write`, `lowcode.delete`（内部 slug `resource.action`）；
  - 创建/校验角色 `test_admin` 并授予上述权限；
  - 将角色绑定到测试用户 `user_id=1`。
- **Execution log / 执行日志摘要：**
  - 在 `alkaid-backend` 容器内执行：
    ```bash
    docker exec -it alkaid-backend php tests/setup_test_data.php
    ```
  - 最新一次执行输出（节选）：
    - `Test user already exists (id=1, username=admin)`
    - `Test user updated to ensure known credentials (email=test@example.com, password=password)`
    - `Test role already exists (slug=test_admin)`
    - `Permission already exists ...` / `Permission already assigned ...`
    - `Role already assigned to user`
- **Conclusion / 结论：**
  - 测试脚本已保证：后端 Feature Test 与 E2E 共用的测试账号和权限数据是**一致且有效**的。

### 2.4 Playground E2E Status / playground 端到端状态

- **Command / 命令：**
  - 在 `frontend/` 目录执行：`pnpm test:e2e`
- **Result / 结果：**
  - `@vben/playground:test:e2e` 使用缓存重放日志，两个用例均通过：
    - `Login Page Tests › check title and page elements`
    - `Login Page Tests › should successfully login with valid credentials`
  - 说明：Playwright 安装正常、浏览器可用，后端 API 在同一测试环境下对 playground 应用工作正常。

---

## 3. Attempted Fixes (Chronological) / 已尝试的修复措施（按时间顺序）

> 本节按时间顺序列出已经执行过的修复动作，说明修改目标、涉及文件、预期效果以及实际结果。

1. **修复 Playwright webServer 端口不匹配 / Fix mismatched dev server port**  
   - File: `frontend/apps/web-antd/playwright.config.ts`  
   - Change: 将 `baseURL` 和 `webServer.port` 从 `http://localhost:5566` / `5566` 统一更新为 `http://localhost:5666` / `5666`，与 `.env.development` 中 `VITE_PORT=5666` 对齐。  
   - Expected: 避免 webServer 启动超时 (`webServer timeout`)，确保 E2E 能正确访问 `http://localhost:5666`.  
   - Result: webServer 超时问题解决，E2E 能加载登录页，但权限用例仍失败。

2. **修正 API 根路径配置 / Fix API base URL**  
   - File: `frontend/apps/web-antd/.env.development`  
   - Change: 将 `VITE_GLOB_API_URL` 从形如 `http://localhost:8000/v1` 修正为 `http://localhost`：
     ```dotenv
     VITE_PORT=5666
     VITE_BASE=/
     VITE_GLOB_API_URL=http://localhost
     VITE_NITRO_MOCK=false
     ```
   - Expected: 保证前端请求 `/v1/auth/login`, `/v1/auth/me` 等路径时不会出现 `/v1/v1/...` 之类的重复前缀；通过 Nginx 网关将 `http://localhost` 转发到后端 API。  
   - Result: 后续 E2E 日志中请求 URL 为 `POST http://localhost/v1/auth/login`，路径已正确；但权限用例依然因 `AxiosError` 失败。

3. **将 E2E 登录从 UI 驱动改为应用级登录 helper / From UI-driven to app-level login helper**  
   - Files:  
     - `frontend/apps/web-antd/src/bootstrap.ts`  
     - `frontend/apps/web-antd/tests/e2e/common/auth.ts`  
   - Changes:
     - 在 `bootstrap.ts` 中，在 `initStores` 之后、开发环境下挂载全局 helper：
       ```ts
       if (import.meta.env.DEV) {
         (window as any).__APP_AUTH_LOGIN__ = async (
           params: any,
           onSuccess?: () => Promise<void> | void,
         ) => {
           const authStore = useAuthStore();
           return authStore.authLogin(params, onSuccess);
         };
       }
       ```
     - 在 E2E helper `common/auth.ts` 中，不再操作表单/滑块，而是调用此 helper：
       ```ts
       await page.evaluate(async () => {
         const globalAny = window as any;
         const loginHelper = globalAny.__APP_AUTH_LOGIN__ as
           | ((params: { email: string; password: string }, onSuccess?: () => Promise<void> | void) => Promise<unknown>)
           | undefined;
         if (!loginHelper) throw new Error('__APP_AUTH_LOGIN__ is not defined on window');
         await loginHelper({ email: 'test@example.com', password: 'password' }, async () => {});
       });
       ```
   - Expected:  
     - 避免 UI 结构/滑块动画导致的脆弱性，聚焦于“真实后端接口 + Pinia store 状态”；
     - 成功触发 `POST /v1/auth/login` → `GET /v1/auth/me`；
     - 测试只关心权限返回与 access store 持久化。  
   - Result:  
     - 最新 E2E 日志表明 `POST http://localhost/v1/auth/login` 的请求已经发出；
     - 但 `authLogin` 内部抛出 `AxiosError`，导致 `page.evaluate` 失败；
     - `/v1/auth/me` 的响应未被 `page.waitForResponse` 等到，进而出现 `Test ended` 错误。

4. **修正测试账号数据 / Fix test account credentials**  
   - File: `tests/setup_test_data.php`  
   - Changes: 当 `id=1` 的用户已存在时，增加一次 `UPDATE`，确保其邮箱和密码为预期值：
     ```php
     Db::table('users')
         ->where('id', 1)
         ->update([
             'username' => 'test_user',
             'email' => 'test@example.com',
             'password' => password_hash('password', PASSWORD_DEFAULT),
             'tenant_id' => 1,
             'status' => 1,
             'updated_at' => date('Y-m-d H:i:s'),
         ]);
     ```
   - Expected: 无论之前是何种初始种子数据，运行脚本后 `test@example.com / password` 都是**后端与 E2E 共用**的标准测试账号。  
   - Result: 通过 curl 复测登录接口，已确认返回 200，说明账号凭据在真实环境下有效；但 E2E 中 `authLogin` 仍报 `AxiosError`。

---

## 4. Current Stack & Key Config / 当前技术栈与关键配置

### 4.1 Frontend Stack / 前端技术栈

- Vue 3 + TypeScript + Vite
- Pinia + `pinia-plugin-persistedstate` + SecureLS 持久化（`@vben/stores`）
- UI: Ant Design Vue + Vben Admin 组件体系
- E2E: Playwright（通过 `pnpm test:e2e` 统一调度）

### 4.2 Backend Stack / 后端技术栈

- PHP + ThinkPHP 风格应用（Swoole HTTP）
- Docker 容器：`alkaid-backend` 负责运行 PHP 应用与测试脚本
- Nginx 网关（参见 `deploy/nginx/alkaid.api.conf`），本地通过 `http://localhost` 访问 API

### 4.3 Key Config Files / 关键配置文件

1. **`.env.development`（web-antd）**  
   - Path: `frontend/apps/web-antd/.env.development`  
   - Key entries / 关键字段：
     ```dotenv
     VITE_PORT=5666
     VITE_BASE=/
     VITE_GLOB_API_URL=http://localhost
     VITE_NITRO_MOCK=false
     ```
   - 含义：Vite dev server 运行在 5666 端口；前端统一通过 `http://localhost` 访问后端 API，版本前缀 `/v1` 由后端自身路由控制。

2. **Playwright Config（web-antd）**  
   - Path: `frontend/apps/web-antd/playwright.config.ts`  
   - Highlights:
     - `baseURL: 'http://localhost:5666'`
     - `webServer.command: 'pnpm dev -- --port 5666'`
     - `port: 5666`
     - `testDir: './tests/e2e'`
   - 意味着：E2E 测试在本地会自动启动 web-antd dev server，并使用 `/auth/login` 等相对路径访问应用页面。

3. **Vite Config**  
   - Path: `frontend/apps/web-antd/vite.config.mts`  
   - 当前对 `/api` 做了 proxy 到 `http://localhost:5320/api`，但登录与权限接口直接使用 `VITE_GLOB_API_URL`（`http://localhost`），不依赖此 proxy；这与 E2E 报错之间的相关性较低。

4. **Bootstrap & Auth Store**  
   - `frontend/apps/web-antd/src/bootstrap.ts`：
     - 初始化组件、表单、i18n、Pinia stores；
     - 在 DEV 环境下挂载 `window.__APP_AUTH_LOGIN__`，包装 `useAuthStore().authLogin`。  
   - `frontend/apps/web-antd/src/store/auth.ts`：
     - `authLogin` 调用 `loginApi(params)` → 设置 `accessToken` → 调用 `fetchUserInfo()`（`GET /v1/auth/me`）→ 更新 `userStore` & `accessStore.accessCodes`。

---

## 5. AxiosError Root Cause Hypothesis / AxiosError 根因分析假设

基于现有信息，可以推断：

1. **请求已成功发出但在页面上下文中抛错 / Requests are sent but throw in page context**  
   - E2E 日志中多次出现：`[E2E][auth-request] POST http://localhost/v1/auth/login`；  
   - 结合 curl 手工验证，后端接口在相同 URL 下返回 200；
   - 说明：前端在 Playwright 环境中成功向正确的 API 发出请求。

2. **错误发生在 `page.evaluate` 里的 `authLogin` 逻辑 / Error originated inside authLogin**  
   - 报错堆栈集中在 `common/auth.ts:14`（`page.evaluate` 内部）；
   - `authLogin` 包裹着完整的登录与获取用户信息逻辑，任何一个 Axios 请求失败都会导致 Promise reject，并透传为 `AxiosError`；
   - 该错误未在浏览器上下文内被捕获，从而直接导致 Playwright 认为 `page.evaluate` 抛异常。

3. **潜在原因方向 / Potential underlying causes**  
   - **Network / Proxy / CORS**：当前通过 `http://localhost` 访问本地 Nginx → `alkaid-backend:8080`，同源场景下 CORS 可能性较低；但若 E2E 运行时后端临时不可用、超时或出现 5xx，都可能被 Axios 视为错误。  
   - **Auth / Tenant / Site Context**：后端 `AuthController@login` 依赖 `tenantId()` 和 `siteId()`，其中 `tenantId` 默认为 1（可从 header `X-Tenant-ID` 覆盖）。若环境中存在异常 header 或多租户上下文不一致，可能导致登录或 `/v1/auth/me` 返回业务错误码，被 Axios 作为异常处理。  
   - **Response Interceptors**：`@vben/request` 的 `RequestClient` 默认使用 `defaultResponseInterceptor` 等中间件，如果后端返回 `code != 0` 或 4xx/5xx，会抛出 `AxiosError`，并且可能在 `authenticateResponseInterceptor` 中触发登出逻辑。  
   - **Timing / Initialization**：虽然在 `authLogin` 前已经调用了 `page.waitForLoadState('networkidle')`，但若 `__APP_AUTH_LOGIN__` 尚未挂载（例如某些异步初始化未完成），也会导致 `loginHelper` 为 undefined。不过当前错误不是 `__APP_AUTH_LOGIN__ is not defined`，而是 AxiosError，因此更可能是网络/后端返回逻辑错误。

鉴于目前尚未在 E2E 中捕获和打印 `AxiosError.response` 的详细内容，**真正的后端响应状态码与业务错误码仍未知**，这是当前诊断中最大的信息缺口。

---

## 6. Pending Diagnostic Directions / 待验证的诊断方向

为了进一步缩小问题范围，建议按以下方向补充诊断信息：

1. **增强 E2E helper 中的错误捕获与日志输出 / Enhance error logging in E2E helper**  
   - 在 `common/auth.ts` 的 `page.evaluate` 内使用 `try/catch` 包裹 `loginHelper` 调用：
     - 捕获 `AxiosError`，打印 `error.message`、`error.response?.status`、`error.response?.data` 到浏览器 console；
     - 通过 `page.on('console', ...)` 在 Playwright 端收集这些日志，并写入测试输出。

2. **查看 Playwright trace 与浏览器 console / Inspect Playwright trace & console**  
   - 使用命令：
     ```bash
     pnpm exec playwright show-trace \
       apps/web-antd/node_modules/.e2e/test-results/auth-permissions-.../trace.zip
     ```
   - 在 trace viewer 中查看：
     - `Network` 面板中 `/v1/auth/login` 与 `/v1/auth/me` 的请求/响应状态；
     - `Console` 面板中是否已经有 Axios 报错堆栈或业务错误信息。

3. **对比 playground 与 web-antd 的 loginApi 配置 / Compare loginApi between playground and web-antd**  
   - 检查 `#/api/core/auth.ts` 在两端的实现是否完全一致，尤其是：
     - 使用的 `RequestClient` baseURL 是否均为 `VITE_GLOB_API_URL`；
     - 是否存在仅在 web-antd 中额外启用的拦截器或错误处理。

4. **检查后端日志中 E2E 期间的请求 / Check backend logs during E2E runs**  
   - 在 E2E 执行期间通过：
     ```bash
     docker logs -f alkaid-backend
     ```
     观察是否有重复的登录错误、权限拒绝、JWT 校验失败或多租户校验失败的日志记录。

5. **必要时在后端临时增加针对 E2E 的详细日志 / Add temporary backend logging if needed**  
   - 在 `AuthController@login` / `AuthController@me` 内增加针对测试用户请求的 debug 日志（记录 header、tenant_id、site_id、user_id 等），用于确认从前端 E2E 发出的请求在后端侧的真实处理路径。

---

## 7. Recommended Next Actions / 建议的后续行动计划

按照优先级从高到低，建议采取以下后续步骤：

1. **最高优先：在 E2E helper 中捕获并输出 AxiosError 详情**  
   - 目标：获取 `AxiosError` 对应的 HTTP 状态码与后端 `code/message`，判定是认证/权限/多租户/环境配置哪一环节出现问题。  
   - 这是当前缺失的关键信息，将直接决定下一步是修改前端请求配置还是调整后端行为。

2. **使用 Playwright trace viewer 结合后端日志进行双向对照**  
   - 同时观察：E2E 期间的浏览器网络请求与后端容器日志；  
   - 确认在 `authLogin` 报错前，`/v1/auth/login` 与 `/v1/auth/me` 的真实返回状态与内容。

3. **根据 AxiosError 结果进行有针对性的修复**  
   - 若是 401/403：检查 JWT 配置、`X-Tenant-ID`、权限中间件；  
   - 若是 5xx 或超时：排查后端 Swoole/Nginx 配置与性能问题；  
   - 若是业务 `code != 0`：根据后端错误码矩阵，调整前端请求或测试数据脚本。

4. **修复后重新执行 `pnpm test:e2e` 并更新本报告为“通过版本”**  
   - 在权限用例全部通过后，追加一节“Fix Summary / 修复摘要”，记录最终变更点与验证命令；
   - 将本报告与安全规范文档一并作为权限链路回归测试的参考标准。

---

*End of report / 报告结束*

