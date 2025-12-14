# Vben 权限集成方案最终技术决策报告

> 文档路径：`docs/report/vben-permission-integration-decision.md`
> 适用范围：AlkaidSYS（ThinkPHP 8 + Vben Admin 5.x, web-antd）

---

## 1. 执行摘要

本报告聚焦 `docs/todo/vben-backend-integration-plan.md` 中“权限码获取方案”框选部分，围绕以下三个关键决策问题进行分析并给出最终方案：

1. 权限码获取方式：
   - 选项 A：后端新增 `/v1/auth/codes` 接口
   - 选项 B：前端从 `/v1/auth/me` 返回数据中转换 / 推导
2. 权限格式规范：
   - 设计文档：`resource:action`（如 `user:create`）
   - 实际实现：`resource.action`（如 `forms.view`）
3. 权限对接策略：
   - 后端返回 AC_ 格式
   - 前端转换
   - Vben 直接使用 slug

在充分核实当前代码实现、/design 设计约束及 Vben Admin 官方权限模型后，本报告给出的**最终推荐方案**为：

- **权限“事实来源”与内部主键**：
  - 以数据库 `permissions.slug`（`resource.action`）作为**唯一规范化权限标识符**（canonical key），与当前 `app/middleware/Permission.php` 的实现保持一致；
  - `permissions.resource` 与 `permissions.action` 作为结构化字段，支撑后续与设计文档中 `resource:action` 概念对齐。
- **外部暴露与前端消费格式**：
  - 对外（API、文档、Vben accessCodes）统一采用**字符串型权限码**，推荐格式为 `resource:action`；
  - 从实现角度，通过 `resource + ':' + action` 组装该字符串，底层仍使用 `slug=resource.action` 存储与校验。
- **权限码获取方式**：
  - **主通道**：扩展现有 `GET /v1/auth/me` 接口，直接返回当前用户的 `permissions: string[]`（值为 `resource:action`）；
  - **兼容通道（可选）**：新增 `GET /v1/auth/codes` 作为 `/v1/auth/me` 的瘦包装，仅返回权限码数组（与 `me.permissions` 保持一致），提供给 Vben 等需要纯 codes 的客户端。
- **Vben 对接策略**：
  - 放弃 AC_ 前缀方案，不再额外引入一套“权限码编码”；
  - 由后端在 `/v1/auth/me` / `/v1/auth/codes` 中直接返回规范化字符串权限码；
  - 前端（Vben access store）直接将该权限码数组写入 `accessCodes` 并通过 `hasAccessByCodes` 使用。

该方案在**架构一致性、性能、可维护性、安全性和开发成本**五个维度上取得了最佳综合平衡，并与 `/design` 目录下的 API 设计、安全设计、前端状态管理设计保持一致或可平滑演进。

---

## 2. 问题陈述

### 2.1 当前实现与设计文档的差异

1. **后端认证与权限相关接口现状**  
   - 路由定义：`route/auth.php`
     - 已实现：
       - `POST /v1/auth/login`
       - `POST /v1/auth/register`
       - `POST /v1/auth/refresh`
       - `GET /v1/auth/me`（带 `\app\middleware\Auth` 中间件）
     - 未实现但在文档/前端中出现：
       - `GET /v1/auth/codes`
       - `POST /v1/auth/logout`
   - 中间件：`app/middleware/Permission.php`
     - 以 `permissions.slug`（如 `forms.view`）作为权限校验 key：
       - 通过 `slug` 查 `permissions.id`；
       - 通过 `role_permissions` 与用户角色进行匹配；
     - 未涉及 `AC_` 前缀或 `resource:action` 字符串格式。

2. **权限数据模型（数据库 & 种子数据）**  
   - 文件：`database/seeds/CorePlatformSeed.php`
   - 权限示例：
     - `slug = 'forms.view'`，`resource = 'forms'`，`action = 'view'` 等；
   - 数据库层面已经存在 resource/action 二元结构，slug 为 `resource.action`，这与安全设计文档中“`resource:action`”仅差一个分隔符。

3. **设计文档中的权限模型与前端期望**  
   - `docs/technical-specs/security/security-guidelines.md`
     - 明确提出使用 RBAC 模型，权限表达为 `resource:action`（如 `product:create`），并在多租户场景下扩展为 `tenant:{tenantId}:{resource}`；
   - `/design/06-frontend-design/17-admin-frontend-design.md`
     - 前端示例中大量使用 `user:create` 等 `resource:action` 风格的权限码；
     - 通过自定义指令 `v-permission` 和路由 `meta.permission` 实现按钮/路由级别控制；
   - `/design/06-frontend-design/20-frontend-state-management.md`
     - Auth Store 设计中约定：后端登录/获取用户信息接口应返回 `permissions: string[]`；
     - 登录成功后：`accessStore.setAccessCodes(result.user.permissions)`。

4. **Vben Admin 权限体系期望**  
   - 文档来源（外部）：
     - Vben 官方权限文档（中文）：https://doc.vben.pro/guide/in-depth/access.html
     - Vben 官方权限文档（英文）：https://doc.vben.pro/en/guide/in-depth/access.html
   - 关键要点：
     - 使用 `@vben/stores` 中的 `useAccessStore` 管理 `accessCodes`；
     - 使用 `@vben/effects/access` 中的 `useAccess()`，依赖 `hasAccessByCodes(codes: string[])` 进行权限判断；
     - 权限码本身为“前端自定义的字符串标签”，官方文档并未强制要求必须是 AC_ 编码，只给出了示例。

5. **现有前端实现（web-antd）**  
   - `frontend/packages/stores/src/modules/access.ts`
     - `accessCodes: string[]`，`setAccessCodes(codes: string[])` 将数组直接写入 store；
   - `frontend/packages/effects/access/src/use-access.ts`
     - `hasAccessByCodes` 通过 `Set(accessCodes)` 与传入 codes 取交集来做校验；
   - `frontend/apps/backend-mock/api/auth/codes.ts`
     - 实现了 mock 接口 `/auth/codes`，根据 username 返回权限字符串数组，如 `['forms.view']` 或其他 codes；
   - `frontend/apps/web-antd/src/api/core/auth.ts`
     - 存在 `getAccessCodesApi` 调用 `/auth/codes`，与真实后端路由不符，仅与 mock 对齐。

### 2.2 亟需决策的三个技术问题

1. **权限码获取方式**  
   - 新增 `/v1/auth/codes` 接口，由后端根据当前 userId 直接返回权限数组；
   - 或扩展 `/v1/auth/me`，在原有 `user + roles` 返回体中增加 `permissions` 字段，由前端在获取用户信息时同步写入 `accessCodes`；
   - 或上述两者兼容：`/v1/auth/codes` 为 `/v1/auth/me` 的瘦包装。

2. **权限格式规范**  
   - 设计规范使用 `resource:action`；
   - 数据库 slug 与中间件实现使用 `resource.action`；
   - Vben access 模块只依赖“任意字符串 array”，无强制格式。

3. **权限对接策略**  
   - 是否保留/引入 AC_ 风格权限码（如 `AC_100100`），作为后端与前端之间的“机器码”；
   - 还是由后端统一返回 slug 或 `resource:action` 字符串，前端直接作为 accessCodes 使用，不再进行二次转换；
   - 是否允许不同前端各自转换同一权限集合为不同本地编码（不推荐）。

---

## 3. 方案对比分析

本节从 **架构一致性、性能影响、可维护性、安全性、开发成本** 五个维度，对主要方案进行对比。

### 3.1 权限码获取方式

#### 3.1.1 方案 A：后端新增 `/v1/auth/codes`（单一入口）

- **描述**：
  - 在 `route/auth.php` 中新增 `GET /v1/auth/codes` 路由；
  - 由 `AuthController` 新增 `codes()` 方法，根据当前用户 ID 查询 `permissions`，并返回 `string[]`；
  - 前端 `web-antd` 通过 `getAccessCodesApi` 调用该接口并写入 `accessStore.accessCodes`。

- **架构一致性**：
  - ✅ 符合“权限由后端集中计算”的职责划分，前端只消费结果；
  - ⚠️ 与 `/design/06-frontend-design/20-frontend-state-management.md` 中“从登录/用户信息接口直接拿 permissions”略有偏离，需要在设计文档中补齐该接口说明。

- **性能影响**：
  - ❌ 对典型登录流程意味着**多一次 HTTP 请求**：登录成功 → 获取用户信息（如有） → 额外请求 `/v1/auth/codes`；
  - 若后续引入 SSO 或多端并发登录，需要在访问控制前多做一次网络往返。

- **可维护性**：
  - ✅ 后端逻辑相对集中，未来扩展（如按租户、应用、空间过滤权限）简单；
  - ⚠️ 需要同时维护 `/v1/auth/me` 与 `/v1/auth/codes` 两个相关接口，存在文档与实现双处更新的风险。

- **安全性**：
  - ✅ 权限数据在后端统一生成，前端无法伪造；
  - ✅ 可在接口层引入缓存 / 频率限制；
  - ⚠️ 如前端仅用 `/v1/auth/codes`，而 `/v1/auth/me` 不返回 permissions，则调试/审计时需要同时查看两个接口返回体，增加排障复杂度。

- **开发成本**：
  - 后端：新增一组查询权限逻辑（可复用 Permission 中间件使用的 repository）；
  - 前端：保持现有 `getAccessCodesApi` 模式，mock → real 切换成本低；
  - 文档：需在 API 设计、安全设计、集成计划文档中增加 `/v1/auth/codes` 的说明。

#### 3.1.2 方案 B：扩展 `/v1/auth/me` 返回 `permissions`（推荐主通道）

- **描述**：
  - 修改 `AuthController@me`，在现有返回 `user + roles` 的基础上，增加 `permissions: string[]` 字段；
  - `permissions` 值通过 `permissions.slug` 或计算得到的 `resource:action` 数组；
  - 前端在调用 `/v1/auth/me` 时，将该数组写入 `accessStore.setAccessCodes`。

- **架构一致性**：
  - ✅ 与 `/design/06-frontend-design/20-frontend-state-management.md` 完全一致：Auth Store 登录成功后即从 `result.user.permissions` 同步到 `accessCodes`；
  - ✅ 符合 REST 与领域设计常规：`/me` 作为“当前登录主体快照”，包含权限信息是常见实践（参考 GitHub / GitLab 等 API 设计）。

- **性能影响**：
  - ✅ **不增加额外 HTTP 往返**：在原有 `/v1/auth/me` 响应体中附带权限集合；
  - 可以通过字段裁剪、分页/分块等方式应对未来权限数量较大场景。

- **可维护性**：
  - ✅ 登录/刷新/获取当前用户信息这一条“认证链路”清晰，便于调试和扩展；
  - ✅ 权限模型演进时，只需要更新 `/v1/auth/me` 的实现与文档即可，耦合点少；
  - 前端逻辑简单：`fetchUserInfo -> setUser + setAccessCodes`。

- **安全性**：
  - ✅ 权限数据仍由后端统一计算；
  - ✅ 与认证信息放在同一响应中，更利于在安全审计日志中进行端到端追踪；
  - ⚠️ 若权限数组过大，响应体体积增大，需要结合安全设计文档中的“最小权限原则”和“租户隔离”进行控制。

- **开发成本**：
  - 后端：在当前 `AuthController@me` 中增加一段查询权限的逻辑，基于已有 `user_roles` / `role_permissions` / `permissions` 表即可；
  - 前端：需要稍调 web-antd 的 Auth Store / 用户信息获取逻辑，使其使用 `/v1/auth/me.permissions` 作为 accessCodes 来源；
  - 文档：更新 API 设计、安全设计、集成计划文档中 `/v1/auth/me` 的响应结构。

#### 3.1.3 组合方案 C：`/v1/auth/me` + `/v1/auth/codes`（主从结构）

- **描述**：
  - 在方案 B 基础上新增 `/v1/auth/codes`：
    - 实现上可以直接调用内部同一服务方法，或重用 `/v1/auth/me` 的权限计算逻辑；
    - 返回体为 `string[]`，等价于 `me.permissions`。

- **综合评价**：
  - 架构一致性：
    - ✅ 主权威接口为 `/v1/auth/me`，`/v1/auth/codes` 仅为瘦包装，避免语义分裂；
  - 性能：
    - 对仅需要 codes 的客户端，可直接调用 `/v1/auth/codes`，无需额外 user 信息；
  - 可维护性：
    - ⚠️ 需要确保两者返回数据始终一致，需要在代码中明确复用逻辑（例如统一 `PermissionService::getUserPermissions`）；
  - 开发成本：
    - 较方案 B 略高，但可带来更灵活的前端接口选择；
  - 适用场景：
    - 当前 web-antd 项目已经存在 `getAccessCodesApi` 且期望有 `/auth/codes` 模式，
      采用组合方案 C 可以**在不破坏设计一致性的前提下，平滑兼容现有前端调用习惯**。

> **结论（获取方式）**：
> - 从 `/design` 约束和通用 API 设计出发，**推荐以方案 B 为主**（扩展 `/v1/auth/me` 返回 permissions）；
> - 出于兼容性与灵活性考虑，**可以附加实现方案 C 中的 `/v1/auth/codes` 作为包装接口**，但必须保证逻辑与 `/v1/auth/me` 共享同一权限计算服务，以避免数据不一致。

---

### 3.2 权限格式规范

#### 3.2.1 `resource:action`（设计文档）

- 来源：
  - `docs/technical-specs/security/security-guidelines.md` 中的 RBAC 设计；
  - `/design/06-frontend-design/17-admin-frontend-design.md` 中的前端权限用例；
  - 与 PHP-Casbin 常用策略表达一致（`sub, obj, act`）。
- 优点：
  - 概念清晰，便于人类理解与文档表达；
  - 与“租户前缀 + 资源 + 行为”组合扩展自然，如 `tenant:1001:forms:create`；
  - 方便在日志与审计系统中直接使用。

#### 3.2.2 `resource.action`（当前 DB & 中间件实现）

- 来源：
  - `database/seeds/CorePlatformSeed.php`；
  - `app/middleware/Permission.php` 使用 `slug` 字段校验权限。
- 优点：
  - 已在数据库和中间件中全面使用，变更成本高；
  - 作为主键字符串，`.` 在 SQL/日志中都简单易用；
  - 与现有种子数据和中间件 100% 兼容。

#### 3.2.3 对比与折中

- 语义上，两者完全等价：
  - 都表示“资源 + 行为”的二元组；
  - 数据库层已经具备 `resource`、`action` 字段，因此是否用 `:` 或 `.` 仅是**视图层的展示问题**。
- 约束来自 `/design`：
  - 安全设计文档与前端设计文档中已经广泛使用 `resource:action` 示例，如 `user:create`；
  - 贸然改为 `resource.action` 会造成文档与代码示例大量不一致。
- 因此更合理的做法是：
  - 保留 DB 层与中间件使用 `slug=resource.action` 作为**内部主键**；
  - 在 API & 文档 & 前端层面统一展现为 `resource:action`（对人类友好、与设计保持一致）；
  - 二者通过 `resource` / `action` 字段**双向可逆映射**：
    - `slug -> resource:action`: `sprintf('%s:%s', $resource, $action)`；
    - `resource:action -> slug`: `explode(':', $code)` 再用 `.` 拼接或直接使用 `resource`/`action` 查表。

> **结论（格式规范）**：
> - **内部实现**：继续使用 `permissions.slug = resource.action`，不迁移存量数据；
> - **外部规范**：在 `/v1/auth/me`、`/v1/auth/codes` 返回体以及文档示例中，统一使用 `resource:action` 作为权限码展示格式；
> - **前后端约定**：任何地方如需将权限码转为内部 slug，应通过 `resource`/`action` 字段或统一工具函数进行转换，避免硬编码字符串替换。

---

### 3.3 权限对接策略（Backend vs Frontend vs Vben）

#### 3.3.1 Backend 返回 AC_ 编码

- 特点：
  - 为每个权限分配一个“机器可读”的 AC_ 前缀编号（如 `AC_100100`），向前端隐藏资源/动作语义；
  - 通常适用于**多语言/多领域复用**，但需要一套权限字典映射表。
- 问题：
  - 当前 DB 与中间件实现均基于 slug 和 resource/action，没有 AC_ 相关结构；
  - `/design` 文档中也未引入 AC_ 编码需求；
  - 会显著增加：
    - 数据库字段（需要新增 `code` 或映射表）；
    - 文档说明（权限码定义表）；
    - 前后端调试难度（人类难以从 AC_ 直接看出业务含义）。

> 鉴于没有明显业务需求支撑，引入 AC_ 编码只会增加复杂度，本报告不建议采用该方案。

#### 3.3.2 前端从 slug/roles 推导权限（前端负责转换）

- 特点：
  - 后端仅返回角色列表或 slug 列表，由前端按自己规则将其映射到 accessCodes；
  - 极端情况下，前端甚至只拿到 roles，再按前端 hardcode 的“role->permissions”映射决定权限。
- 问题：
  - 违背 `/design/04-security-performance/11-security-design.md` 中强调的“权限在后端集中控制”的原则；
  - 多前端（web-antd、client-app 等）时无法保证权限一致性；
  - 安全风险显著：前端一旦出现 bug 或被篡改，权限判断将与后端真实授权不一致。

> 根据安全设计与企业级架构实践，前端**不应成为权限计算的权威方**，只应消费后端给出的结果。

#### 3.3.3 Vben 直接使用字符串权限码（推荐）

- 特点：
  - 由后端返回标准化字符串权限码数组（推荐 `resource:action`）；
  - 前端 Vben accessStore 直接将其写入 `accessCodes`，`useAccess().hasAccessByCodes` 直接基于字符串集合做判断；
  - 不需要 AC_ 编码，也不需要前端转换。
- 优点：
  - 与 Vben 官方设计高度契合：Vben 只要求“前端持有一组字符串权限码”；
  - 权限语义对开发/运维/测试人员一目了然；
  - 权限的**唯一事实来源在后端**，前端只做显示与校验，无推导逻辑；
  - 易于日志和审计。

> **结论（对接策略）**：
> - 放弃 AC_ 编码方案；
> - 由后端直接返回 `resource:action` 风格的权限码数组；
> - Vben 直接使用这些字符串作为 accessCodes，不做再转换；
> - 对于纯 slug（`resource.action`）场景，通过统一服务在后端完成转换后再返回给前端。

---

## 4. 技术验证

本节汇总关键事实证据：

1. **后端路由与中间件（获取方式 & 权限模型）**  
   - 路由定义：`route/auth.php` 仅存在 `/v1/auth/login|register|refresh|me`，未有 `/v1/auth/codes`、`/v1/auth/logout`；
   - `app/middleware/Permission.php` 使用 `permissions.slug` 及 `role_permissions` 校验权限，中间件签名形如 `->middleware(Permission::class . ':forms.view')`，证明 slug 是当前权限 key。  

2. **权限数据结构（格式规范）**  
   - `database/seeds/CorePlatformSeed.php` 中的 `permissions` 数组，包含 `name`、`slug`、`resource`、`action` 字段，slug 取值为 `forms.view` 等；
   - 通过该结构可以无损映射为 `forms:view` 或 `tenant:{id}:forms:view`。

3. **设计文档（安全 & 前端状态管理）**  
   - `docs/technical-specs/security/security-guidelines.md` 明确 “Permissions: `resource:action` (e.g., `product:create`)”；
   - `/design/06-frontend-design/17-admin-frontend-design.md` 中 `v-permission="'user:create'"` 等示例；
   - `/design/06-frontend-design/20-frontend-state-management.md` 中 Auth Store 设计使用 `result.user.permissions` 写入 accessCodes。

4. **前端实现（Vben access 机制）**  
   - `frontend/packages/stores/src/modules/access.ts`：`accessCodes: string[]`，`setAccessCodes(codes: string[])`；
   - `frontend/packages/effects/access/src/use-access.ts`：`hasAccessByCodes` 通过字符串集合求交集进行权限控制；
   - `frontend/apps/backend-mock/api/auth/codes.ts`：证明当前仅有 mock `/auth/codes`，实际后端未实现对应接口；
   - 结合 Vben 官方文档（见附录链接），确认 access 模块对权限码本身不强加格式限制。

5. **Vue & Pinia 官方实践（状态管理）**  
   - Vue 官方文档（/vuejs/docs）：推荐将跨组件共享的状态集中在 store 中；
   - Pinia 官方文档（/vuejs/pinia）：强调在 `actions` 中封装状态修改逻辑，通过 `defineStore` 维护全局状态；
   - 当前 `useAccessStore` 与 `use-auth` 等实现方式完全符合该推荐模式。

> 综上，技术验证表明：
> - 使用 `/v1/auth/me` 返回 `permissions: string[]` 完全符合设计文档与现有架构；
> - Vben access 模块对权限码内容不做语义约束，我们可以安全地使用 `resource:action`；
> - 内部 slug=`resource.action` 与外部 `resource:action` 通过 DB 字段可逆映射，无数据不一致风险。

---

## 5. 最终决策

### 5.1 推荐方案概述

1. **权限格式规范**  
   - 内部（DB & 中间件）：继续使用 `permissions.slug = resource.action`；
   - 外部（API & 文档 & Vben）：统一使用 `resource:action` 作为权限码展示与交互格式；
   - 引入统一的权限服务/工具函数，负责在内部 slug 与外部 code 之间转换。

2. **权限获取方式**  
   - 扩展 `GET /v1/auth/me`：
     - 新增字段：`permissions: string[]`（每项为 `resource:action`）；
   - 可选新增 `GET /v1/auth/codes`：
     - 返回与 `me.permissions` 相同的数组；
     - 实现上重用相同的权限查询逻辑。

3. **前端对接策略（Vben）**  
   - web-antd：
     - 登录后和拉取用户信息时，从 `/v1/auth/me.permissions`（或 `/v1/auth/codes`）获取权限码，直接写入 `accessStore.setAccessCodes`；
     - 不再在前端层面进行权限派生或格式转换；
   - Vben access：依旧使用 `hasAccessByCodes(['forms:view', 'user:create'])` 等字符串数组进行权限判断。

### 5.2 决策依据（关键理由）

1. **架构一致性**：
   - 与 `/design/06-frontend-design/20-frontend-state-management.md` 中 Auth Store 方案严格对齐；
   - 保证“权限计算”在后端集中完成，前端仅消费，符合 `/design/04-security-performance/11-security-design.md` 的安全原则。

2. **性能**：
   - 通过扩展 `/v1/auth/me` 避免新增网络往返；
   - 可选的 `/v1/auth/codes` 只在需要时调用，不强制增加成本。

3. **可维护性与扩展性**：
   - 内部 slug 不变，避免 DB 与中间件大改；
   - 外部 `resource:action` 格式与设计文档一致，减少开发者心智负担；
   - 权限服务集中负责转换逻辑，未来扩展（多租户前缀、业务域拆分）有明确落点。

4. **安全性**：
   - 所有权限数据由后端统一生成和下发；
   - 前端不再自行推导权限，避免被绕过或逻辑不一致；
   - 统一的权限码便于审计与日志分析。

5. **开发成本与落地风险控制**：
   - **对后端**：仅需在现有接口与服务上增量扩展，无需大规模重构；
   - **对前端**：主要是调整 Auth Store 和权限获取 API，改动面集中且可通过单元测试与 E2E 测试覆盖；
   - 无需引入 AC_ 编码或额外权限字典，避免新增一整套维护成本。

### 5.3 需要修改的文件清单（不含新增文件）

> 以下为“高概率需要调整”的文件清单，具体改动需在实施阶段通过 `view`/`str-replace-editor` 精准落地：

- 后端：
  - `route/auth.php`：
    - 若实现 `/v1/auth/codes`，需新增路由定义；
  - `app/controller/AuthController.php`：
    - 扩展 `me()` 返回结构，增加 `permissions` 字段；
    - 新增 `codes()`（若采用组合方案 C）；
  - 可能新增：`app/service/PermissionService.php` 或类似服务类，用于计算用户的权限集合并做格式转换；
  - 如有 `infrastructure/Auth` 相关服务（如 `JwtService`），可视情况注入权限服务以支持以后 token 内嵌权限场景。

- 前端（web-antd）：
  - `frontend/apps/web-antd/src/api/core/auth.ts`：
    - 若 `/v1/auth/codes` 实现，调整 `getAccessCodesApi` 指向真实后端；
    - 或改为使用 `/v1/auth/me` 返回的 `permissions`；
  - `frontend/apps/web-antd/src/store/auth.ts`：
    - 登录成功、刷新 token、拉取用户信息流程中，将 accessCodes 的来源调整为 `me.permissions`（或 `codes` 接口返回值）。

- 文档：
  - `docs/todo/vben-backend-integration-plan.md`：
    - 更新“权限码获取方案”章节的推荐方案与接口定义；
  - `docs/technical-specs/security/security-guidelines.md`：
    - 补充说明 `resource:action` 与 `resource.action` 的关系与转换规则；
  - `/design/06-frontend-design/17-admin-frontend-design.md`、`/design/06-frontend-design/20-frontend-state-management.md`：
    - 在状态管理与权限用法示例中，明确 backend 返回 `permissions: string[]` 的接口路径与格式。

### 5.4 风险评估与缓解措施

1. **风险：权限格式迁移导致前后端不一致**  
   - 缓解：
     - 在后端引入统一的 `PermissionFormatter`/`PermissionService`，禁止散落的字符串拼接；
     - 在单元测试中增加“slug 与 code 互转”的测试用例；
     - 在 E2E 测试中覆盖典型权限场景（按钮显隐、菜单访问、API 403）。

2. **风险：老接口/旧前端仍依赖旧行为**  
   - 缓解：
     - 在引入 `/v1/auth/codes` 时保证与 `/v1/auth/me` 一致，避免双标准；
     - 若暂不实现 `/v1/auth/codes`，则在文档中标记为“规划中/废弃”，避免新客户端依赖。

3. **风险：权限数量较大时的性能与响应体大小**  
   - 缓解：
     - 在短期内，通过合理的角色设计控制权限数量；
     - 从长期看，可以引入“按功能模块/租户懒加载权限”的机制，但不属于本次决策范围。

4. **风险：实现偏离设计文档**  
   - 缓解：
     - 将本报告作为 `/docs/report` 下的正式决策文档，
     - 并反向更新 `/design` 与 `docs/todo/vben-backend-integration-plan.md`，形成闭环。

---

## 6. 实施路线图

### 6.1 阶段一：后端实现与验证

1. **Task 1：实现权限查询服务**  
   - 内容：
     - 新增 `PermissionService::getUserPermissions(int $userId): array`；
     - 内部基于 `user_roles`、`role_permissions` 与 `permissions` 表查询用户拥有的权限；
     - 返回值形式为 `resource:action` 数组（通过 `resource`、`action` 字段组装）。
   - 验收标准：
     - 单元测试覆盖：
       - 用户无角色时返回空数组；
       - 用户有多角色时去重后的权限集合；
       - `forms.view` 等示例权限正确映射为 `forms:view`。

2. **Task 2：扩展 `/v1/auth/me` 接口**  
   - 内容：
     - 在 `AuthController@me` 中注入并调用 `PermissionService`，将权限集合添加到响应体：`permissions: string[]`；
   - 验收标准：
     - API 文档中更新 `/v1/auth/me` 响应示例；
     - Postman/HTTPie 等工具调用 `/v1/auth/me`，能看到正确的 `permissions` 字段；
     - 与 Permission 中间件的行为一致（拥有权限的接口不返回 403，缺乏权限的接口返回 403）。

3. **Task 3（可选）：实现 `/v1/auth/codes` 包装接口**  
   - 内容：
     - 在 `route/auth.php` 中新增 `GET /v1/auth/codes`；
     - 在 `AuthController` 中新增 `codes()` 方法，内部直接调用 `PermissionService`；
   - 验收标准：
     - `/v1/auth/codes` 返回值与 `/v1/auth/me.permissions` 完全一致；
     - API 文档中增加接口说明与示例。

### 6.2 阶段二：前端集成（web-antd）

1. **Task 4：调整 Auth API**  
   - 内容：
     - 修正 `frontend/apps/web-antd/src/api/core/auth.ts` 中的接口定义：
       - 若采用 `/v1/auth/me`：确保有 `getCurrentUserApi` 或等价接口返回 `permissions` 字段；
       - 若采用 `/v1/auth/codes`：将 `getAccessCodesApi` 指向真实后端 `/v1/auth/codes`。
   - 验收标准：
     - TypeScript 类型定义中包含 `permissions: string[]`（或 `codes: string[]`）；
     - 与后端 Swagger/文档保持一致。

2. **Task 5：调整 Auth Store 与 accessStore 对接**  
   - 内容：
     - 在登录成功/刷新 token/拉取用户信息的逻辑中：
       - 从 `/v1/auth/me.permissions`（或 `/v1/auth/codes`）中获取权限码数组；
       - 调用 `useAccessStore().setAccessCodes(permissions)`；
     - 确保登出时清空 `accessCodes`。
   - 验收标准：
     - 单元测试/组件测试中覆盖如下场景：
       - 登录拥有 `forms:view` 权限的用户时，对应菜单项/按钮可见；
       - 登录无权限用户时，对应控件不可见；
       - 切换账号后，权限 UI 状态正确更新。

3. **Task 6：联调与回归测试**  
   - 内容：
     - 启动后端 + 前端，进行端到端权限场景测试；
     - 重点覆盖 forms/view 等已有权限样例。
   - 验收标准：
     - 无 500/未授权/跨域等异常；
     - UI 权限表现与 DB 中角色-权限配置一致。

### 6.3 阶段三：文档更新与知识沉淀

1. **Task 7：更新集成计划文档**  
   - 内容：
     - 修改 `docs/todo/vben-backend-integration-plan.md` 中“权限码获取方案”部分，按本报告最终决策进行调整；
   - 验收标准：
     - 文档中不再出现 AC_ 编码的推荐方案；
     - `/v1/auth/me` 与 `/v1/auth/codes` 的关系与字段结构描述清晰。

2. **Task 8：更新安全与设计文档**  
   - 内容：
     - `docs/technical-specs/security/security-guidelines.md`：说明 `resource:action` 与 `resource.action` 之间的关系与转换；
     - `/design/06-frontend-design/17-admin-frontend-design.md`、`20-frontend-state-management.md`：将权限获取部分具体到 `/v1/auth/me` / `/v1/auth/codes`；
   - 验收标准：
     - 各文档之间无明显冲突；
     - 新加入的示例代码与实际实现一致。

---

## 7. 附录

### 7.1 项目内部参考文档与代码

- API 设计与安全设计：
  - `design/03-data-layer/10-api-design.md`
  - `design/04-security-performance/11-security-design.md`
  - `docs/technical-specs/security/security-guidelines.md`
- 前端设计与状态管理：
  - `design/06-frontend-design/17-admin-frontend-design.md`
  - `design/06-frontend-design/20-frontend-state-management.md`
- 实际代码：
  - 后端：
    - `route/auth.php`
    - `app/controller/AuthController.php`
    - `app/middleware/Auth.php`
    - `app/middleware/Permission.php`
    - `database/seeds/CorePlatformSeed.php`
    - `infrastructure/Auth/JwtService.php`（如存在）
  - 前端与 Vben 相关：
    - `frontend/packages/stores/src/modules/access.ts`
    - `frontend/packages/effects/access/src/use-access.ts`
    - `frontend/apps/backend-mock/api/auth/codes.ts`
    - `frontend/apps/web-antd/src/api/core/auth.ts`
    - `frontend/apps/web-antd/src/store/auth.ts`

### 7.2 外部参考资料

- Vue 3 官方文档（状态管理与组合式 API）：
  - https://vuejs.org/guide/scaling-up/state-management.html
- Pinia 官方文档：
  - https://pinia.vuejs.org/
- Vben Admin 权限控制文档：
  - 中文：https://doc.vben.pro/guide/in-depth/access.html
  - 英文：https://doc.vben.pro/en/guide/in-depth/access.html

### 7.3 关键代码示例（伪代码级别）

- 后端权限服务示例：

  ```php
  class PermissionService
  {
      public function getUserPermissions(int $userId): array
      {
          // 1) 通过 user_roles 查出角色
          // 2) 通过 role_permissions + permissions 查出 slug/resource/action
          // 3) 返回 ["{$resource}:{$action}", ...]
      }
  }
  ```

- `/v1/auth/me` 响应示例：

  ```json
  {
    "code": 0,
    "message": "OK",
    "data": {
      "user": {
        "id": 1,
        "username": "admin",
        "roles": ["admin"],
        "permissions": ["forms:view", "forms:create"]
      }
    }
  }
  ```

- 前端写入 accessCodes 示例（web-antd）：

  ```ts
  const authStore = useAuthStore();
  const accessStore = useAccessStore();

  const result = await getCurrentUserApi(); // /v1/auth/me
  authStore.setUser(result.user);
  accessStore.setAccessCodes(result.user.permissions);
  ```

