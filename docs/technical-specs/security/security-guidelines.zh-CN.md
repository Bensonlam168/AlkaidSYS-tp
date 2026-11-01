# 安全设计规范

## 1. 概述
本文档概述了 AlkaidSYS 的安全架构和指南，涵盖认证、授权、数据保护和常见漏洞防护。

## 2. 认证（Authentication）

### 2.1 认证机制
- **双 Token 系统**：
  - **Access Token**：JWT，短生命周期（默认 2 小时）。用于 API 访问。
  - **Refresh Token**：JWT，长生命周期（默认 7 天）。用于获取新的 Access Token。
- **存储**：
  - 客户端应安全存储 Token（例如：HTTPOnly Cookie 或安全的本地存储）

### 2.2 JWT 配置
- **Issuer (`iss`)**：必须通过 `JWT_ISSUER` 环境变量配置
  - 格式：完全限定域名（例如：`https://api.alkaidsys.local`）
  - 多环境示例：
    - 开发环境：`https://api.alkaidsys.local`
    - 测试环境：`https://api.test.alkaidsys.com`
    - 生产环境：`https://api.alkaidsys.com`
- **验证**：所有 JWT 必须具有匹配的 `iss` 声明，否则将被拒绝

### 2.3 认证流程
1. **登录**：`POST /v1/auth/login` → 返回 Access Token、Refresh Token 和用户信息
2. **API 访问**：请求头中携带 `Authorization: Bearer <Access Token>`
3. **Token 刷新**：当 Access Token 过期（401）时，在请求头中携带 `Authorization: Bearer <Refresh Token>` 调用 `POST /v1/auth/refresh` 获取新的 Access/Refresh Token 对

### 2.4 Refresh Token 轮换机制与安全
- **强制轮换**：每次刷新操作都会返回新的 Refresh Token
- **旧 Token 失效**：
  - 旧 Refresh Token 从白名单中移除
  - 旧 Refresh Token 的 `jti` 加入黑名单
  - 旧 Token 立即失效（防止重放攻击）
- **重放检测**：使用已撤销/黑名单 Token 将触发 `code = 2007` 错误并记录审计日志
- **黑名单 TTL**：与 Refresh Token 生命周期一致（默认 7 天）

### 2.5 会话失效闭环（ER-04）

> 实现状态：**已实现**。实现位置：`app\middleware\Auth`、`app\controller\AuthController::login()/refresh()`、
> `Infrastructure\Auth\PrincipalStateChecker`、`Infrastructure\Tenant\Service\TenantUsability`。
> 回归测试：`tests/Feature/Auth/SessionInvalidationTest.php`（13 条，覆盖停用/锁定/删除/改租户/租户停用/租户过期/续期/登录/恢复）。

#### 2.5.1 缺口

JWT 只能证明「这张令牌在**签发时**是真的」，不能证明「令牌持有者**现在**仍然有效」。改造前：

- `app\middleware\Auth` 只做三件事：验签/过期/`type=access`、把 `user_id`/`tenant_id`/`site_id` 写进 Request、放行，
  **从不回查用户与租户的当前状态**；
- `AuthController::refresh()` 只校验 Refresh Token 自身（签名 + 白名单），**按旧声明继续签发**；
- 于是「用户被置 `inactive`/`locked`」「租户被置 `inactive`/`suspended`」「租户 `expires_at` 已过期」之后，
  **已签发的** Access Token 在 2 小时内、Refresh Token 在 7 天内仍然完全可用。

#### 2.5.2 判据（三条链路共用同一实现）

| 链路 | 校验项 | 位置 |
| --- | --- | --- |
| 每个受保护请求 | 用户仍存在于令牌声明的租户内 + `status = active` + 租户可用 | `app\middleware\Auth` → `PrincipalStateChecker::check()` |
| `POST /v1/auth/refresh` | 同上（续期前必须先验主体） | `AuthController::refresh()` |
| `POST /v1/auth/login` | 租户可用（用户状态原已由 `User::isActive()` 拦住） | `AuthController::login()` → `PrincipalStateChecker::checkTenant()` |

- 「租户可用」= 存在 + `status = active` + `expires_at` 未过期（空值视为不限期），
  判据集中在 `Infrastructure\Tenant\Service\TenantUsability`，`TenantIdentify` 也已改为委托同一实现。
- 用户被删除、或被改到其它租户，一律按「**该用户在令牌声明的租户内已不存在**」处理（`user_not_found`）：
  跨租户的「这个 id 到底存不存在」查询不参与放行判断（与 `docs/technical-specs/multi-tenant/tenant-parameter-contract.md` 口径一致）。
- 上下文（`user_id`/`tenant_id`/`site_id`）在状态校验**之前**写入 Request：
  请求即使被拒，访问日志/审计日志仍能记录发起主体。

#### 2.5.3 拒绝语义

- HTTP **401** + 业务码 **2001**（`ErrorCode::UNAUTHORIZED`）。**不用 403**：
  会话失效属于认证层，前端应「清本地会话 → 重新登录」，而不是「权限不足」。
- 对外 message 统一为 `error.session_invalid`（「当前登录会话已失效，请重新登录」），
  **不区分**具体原因。可区分的原因（`user_not_found` / `user_inactive` / `user_locked` /
  `tenant_not_found` / `tenant_unusable` / `tenant_expired`）只写日志（含 `trace_id`）。
  取舍：区分原因便于排障，但会让持有合法签名令牌的一方能判断「该账号是否存在」「该租户是否被停用/欠费」，
  属于账号与运营状态泄露；而客户端对这几种情况的处置完全相同，排障需求由「日志 reason + 响应 trace_id」满足。
- refresh 拒绝时**同时作废**该 Refresh Token（白名单移除 + `jti` 入黑名单），重放将得到 `2007`。
- 主体状态校验自身失败（如数据库不可用）返回 **500**，不返回 401：
  把一次数据库故障说成「会话失效」会让全站被登出并掩盖真实故障；500 同样是 fail-closed（不放行）。
- 登录阶段租户不可用返回 **403 + 2002**，与 `TenantIdentify` 的既有口径一致（此时尚无会话可失效）。

#### 2.5.4 性能与缓存结论：**不缓存**

每个已认证请求新增 **2 次按主键的查询**，用户不可用时短路、不再查租户。实测（本机 Docker 环境）：

- **查询计数**（MySQL general log，单次 `GET /v1/auth/me`，共 6 条业务语句，其中前 2 条为本次新增）：

  | # | 语句 | 来源 |
  | --- | --- | --- |
  | 1 | `SELECT * FROM users WHERE id = ? AND tenant_id = ? LIMIT 1` | **新增**：主体状态校验 |
  | 2 | `SELECT * FROM tenants WHERE id = ? LIMIT 1` | **新增**：租户可用性校验 |
  | 3 | `SELECT * FROM users WHERE id = ? AND tenant_id = ? LIMIT 1` | 原有：`AuthController::me()` |
  | 4 | `SELECT tenant_id FROM users WHERE id = ? LIMIT 1` | 原有：`PermissionService::getTenantId()` |
  | 5 | `SELECT role_id FROM user_roles WHERE user_id = ?` | 原有：`getRoleIds()` |
  | 6 | `SELECT tenant_id FROM users WHERE id = ? LIMIT 1` | 原有：权限查询内的租户解析 |

- **索引与执行计划**：`EXPLAIN` 显示两条新查询均为 `type=const`、`key=PRIMARY`、`rows=1`、`filtered=100.00`；
  `EXPLAIN ANALYZE` 的 `actual time` 分别约 `0.0015ms`（users）与 `0.0012ms`（tenants），
  即 MySQL 侧开销可忽略，成本主要是「一次 TCP 往返」。
- **单次往返开销**（容器内 PDO，500 次均值）：`SELECT 1` 纯往返 ~1.40ms，
  `users` 主键查 ~2.21ms、`tenants` 主键查 ~2.41ms → **两条新增查询的边际成本约 1.6–2.0ms/请求**。
- **端到端 A/B**（临时切回改造前 `Auth.php` 对照，各 80 次经 nginx 的 `upstream_time`）：
  改造前 p50 `77.0ms` / min `36.0ms`，改造后 p50 `58.0ms` / min `36.0ms`，改造后复测 p50 `43.0ms` / min `34.0ms`
  —— **同一份代码两次测量可差 15ms 以上，约 2ms 的增量被本机噪声吞没**（Swoole 常驻进程 + 其它容器负载）。
  结论：以「+2 次主键查、约 +1.6–2.0ms/请求」为准，端到端差异在当前环境不可分辨。

**刻意不做任何缓存**（含 `static`）：

- `alkaid-backend` 是 Swoole 常驻进程，`static` 属性跨请求存活，缓存住 `active`
  等于「停用用户在 worker 重启前一直可用」——用一个进程内缓存把安全校验变成安全假象；
- 共享缓存（Redis）虽不跨进程，但必须 TTL + **全部写路径**显式失效才成立：
  用户/租户状态可经 `UserController::updateStatus`、`TenantController::update|toggleStatus`、
  甚至直接改库的运营动作变更，任何一处漏失效都会把「停用延迟」无限放大；
- 因此选择「停用即生效（延迟 **0**，无 TTL）」的正确性，用两次主键查询换。
- 若将来确需缓存，硬性要求：① TTL 写进本文件与该处注释（建议 ≤ 10s）；
  ② 在用户/租户状态写路径**显式失效**；③ 明确「最坏停用延迟 ≤ TTL」；④ 不得用 `static`。
- 同类判据已有先例：`app\middleware\SiteIdentify` 同样选择「不缓存，每次主键查」。
- 若确实需要把两次查询压成一次，可在仓储层加一条
  `SELECT u.status, t.status, t.expires_at FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.id = ? AND u.tenant_id = ?`
  的联表查询（本次**未做**：会引入「用户仓储依赖租户表」的耦合，收益仅 ~1ms）。

#### 2.5.5 逐 token 撤销 vs 会话版本号（评估，未实施）

- **路线 A（当前实现）**：逐请求状态校验。优点：停用/锁定/删除/改租户/租户停用/过期**立即**生效，
  无需改 schema、无需吊销传播；缺点：每请求 2 次主键查询，且「正常态」也要查。
- **路线 B**：`users.token_epoch`（或 `password_changed_at`）写进 JWT claim，变更时递增，校验侧比对。
  优点：可「一键撤销某用户所有令牌」且查询可减为 1 次（users 行同时承载 epoch 与 status）；
  缺点：需要迁移 + 改签发/校验两侧 + 所有「改密码/改状态/强制下线」路径都要递增 epoch，
  漏一处即是「撤销不生效」；且**仍然要读 users 行**才能比对 epoch，省不下那次查询。
- **推荐**：保持路线 A。语义上真正需要「撤销全部会话」时应做的是「吊销 + 强制重新登录」，
  那正是本节的 401 闭环；路线 B 的边际收益（一次 `users` 查询）与引入的 schema/契约改动不成比例。
  若后续出现「同一用户多端强制下线」的强需求，再按 ADR 流程评估路线 B，且必须与当前闭环并存。

#### 2.5.6 未覆盖范围（明确不做/待裁决）

- **未挂 `Auth` 中间件的路由不受本节保护**（例如 `POST /v1/auth/login|register|refresh`、
  `GET /v1/workflow/triggers/webhook/:key` 等 webhook）。这些入口必须自行做主体校验，
  其中登录/刷新已由本节的 `PrincipalStateChecker` 覆盖。
- 站点维度（`X-Site-ID` 的归属与状态）由 `SiteIdentify` 负责，不在本节范围。
- `AUTH_SKIP_MIDDLEWARE`（仅 `APP_ENV=dev` 生效）会整段跳过本中间件，
  等于跳过本节全部校验；**严禁在生产类环境配置该变量**（`JwtService` 的密钥强度检查不覆盖它）。

## 3. 授权（Authorization）

### 3.1 RBAC 模型

本项目的授权模型划分为两个阶段，两个阶段共享同一套对外权限码协定。

- **Phase 1（基础必需能力——基于数据库的 RBAC）**：
  - 后端必须基于 `roles`、`permissions`、`role_permissions` 三张表实现 RBAC 权限控制。
  - 在 Phase 1 中，`app/middleware/Permission.php` 中间件可以直接实现权限校验逻辑，但推荐通过独立的 `PermissionService` 进行封装，而不是在控制器中直接编写 SQL/查询逻辑。
  - 内部规范化主键为 `permissions.slug` 字段，格式为 `resource.action`（例如：`forms.view`）。同时，`permissions.resource` 与 `permissions.action` 字段保存了解构后的资源与动作（参见 `database/seeds/CorePlatformSeed.php`）。
  - 路由中间件声明必须继续使用该 slug 值，例如：`->middleware(Permission::class . ':forms.view')`。

- **Phase 2（目标架构——基于 Casbin 的 RBAC，必须落地）**：
  - 长期授权引擎必须切换为运行在相同 `roles` / `permissions` 数据模型之上的 **PHP-Casbin**（详见 `design/04-security-performance/11-security-design.md`）。
  - 必须引入并保持一个 `PermissionService` 抽象层，作为所有授权检查的唯一入口，以便在不改变控制器和中间件签名的前提下，将底层实现从直接数据库查询平滑迁移为 Casbin 判定。
  - Casbin 策略中必须使用 `resource:action` 形式的权限码（例如：`forms:view`、`user:create`）。
  - 在多租户场景下，必须通过在资源前增加租户域前缀的方式建模：`tenant:{tenantId}:{resource}:{action}`（例如：`tenant:1001:forms:view`），与 3.2 节说明保持一致。

- **对外 API 与前端协定（适用于所有阶段）**：
  - 在 API 与客户端层面，统一使用 `resource:action` 作为权限码展示与交互格式（例如：`forms:view`、`user:create`），由 `resource` + `action` 字段组装得到。
  - 认证相关接口（例如 `GET /v1/auth/me`，以及可选的 `GET /v1/auth/codes`）必须以 `permissions: string[]` 的形式对外暴露权限集合，元素为 `resource:action` 字符串，方便前端（例如 Vben 的 access store）直接消费。
  - 该协定以及内部 slug 与外部权限码之间的映射规则在 `docs/report/vben-permission-integration-decision.md` 中有完整描述，必须视为后端与前端权限集成的权威来源。

- **内部 slug 与外部权限码的映射关系**：
  - 内部 slug 与外部权限码仅在分隔符上存在差异，且都由同一对 `resource` / `action` 字段推导而来：
    - 内部：`slug = resource . '.' . action`（例如：`forms.view`）
    - 外部：`code = resource . ':' . action`（例如：`forms:view`）
  - 新增代码不得在没有明确设计决策的前提下引入第三种权限编码格式（例如 `AC_xxx`），以避免权限体系碎片化。

#### 权限中间件参数格式

权限中间件 `app\\middleware\\Permission` 支持两种权限参数格式，以提供向后兼容性和灵活性：

1. **外部权限码格式（推荐）**：`resource:action`
   - 与 API 返回值和前端使用的权限码保持一致。
   - 示例：`forms:view`、`lowcode:read`。
   - 路由示例：`->middleware(\\app\\middleware\\Permission::class . ':forms:view')`。

2. **内部 slug 格式（仅用于兼容历史路由）**：`resource.action`
   - 与数据库字段 `permissions.slug` 保持一致。
   - 示例：`forms.view`、`lowcode.read`。
   - 路由示例：`->middleware(\\app\\middleware\\Permission::class . ':forms.view')`。

中间件内部会对两种格式做统一归一化处理：

- 当收到 `resource.action` 形式时，会先转换为 `resource:action`，再调用 `PermissionService::hasPermission()`。
- `PermissionService` 以及所有认证相关 API 始终使用 `resource:action` 形式作为对外权限码。
- 新增路由 **必须** 在中间件声明中使用 `resource:action` 形式；`resource.action` 仅用于兼容旧代码，禁止在新代码中继续引入。

### 3.2 租户隔离
- 所有数据访问必须按 `tenant_id` 进行范围限定
- 权限使用租户范围前缀：`tenant:{tenantId}:{resource}`
- 在授权层禁止跨租户访问

### 3.3 权限监控端点鉴权（`/v1/admin/permission-monitor`）

该组端点会暴露权限子系统内部状态（L1/L2 缓存命中率、TTL、P95 延迟、任意用户的权限集），
且包含两条**写**操作（清空指定用户 / 全部权限缓存）。因此**整组必须挂 Auth + Permission，不允许匿名访问**。

| 方法 | 路径 | 所需权限 | 说明 |
| --- | --- | --- | --- |
| GET | `/v1/admin/permission-monitor/cache-stats` | `permission.read` | 缓存统计（含命中率 / TTL） |
| GET | `/v1/admin/permission-monitor/performance` | `permission.read` | 性能指标（P95 / 命中率目标） |
| GET | `/v1/admin/permission-monitor/health` | `permission.read` | 健康检查（内部状态，非探活入口） |
| GET | `/v1/admin/permission-monitor/user/<userId>/permissions` | `permission.read` | 目标用户必须属于当前租户，否则 404 |
| GET | `/v1/admin/permission-monitor/user/<userId>/check/<permission>` | `permission.read` | 同上；`:permission` 允许 `resource:action` |
| DELETE | `/v1/admin/permission-monitor/cache/user/<userId>` | `permission.write` | 同上；清缓存是对权限子系统的写操作 |
| DELETE | `/v1/admin/permission-monitor/cache` | `permission.write` | 只清权限缓存（白名单前缀），**不再** `Cache::clear()`，见 3.4 |

约束与结论：

- **`health` 不单独开口子**：它返回的是内部缓存/延迟状态，不是「进程是否存活」。
  进程级探活应使用不返回业务数据的独立端点；把本端点匿名化会把刚修掉的未鉴权读取重新打开。
- **跨租户一律 404（不是 403）**：`PermissionService` 的按用户查询是「以被查用户自己的 `tenant_id` 为准」，
  不含请求租户维度，因此控制器必须先校验「目标用户属于当前请求租户」再委托。
  用 404 而非 403 是为了不泄露「该 userId 存在但属于别家」。
- **路由中间件参数格式**：`route/**` 中的中间件声明使用内部 slug 形式（`permission.read`），
  因为 `scripts/check-permission-codes.php` 明确禁止在路由层使用 `resource:action`（含 `:`）；
  两种形式在中间件内部会被归一化，语义等价。

### 3.4 权限缓存失效策略（R1 / ER-06）

> 实现任务：R1（`clearAllCache` 误清整库）、ER-06（撤权未接缓存失效）

#### 3.4.1 键空间与 TTL

| 键（逻辑名，落 Redis 时驱动会再加 `alkaid:` 前缀） | TTL | 生产者 |
| --- | --- | --- |
| `l1:permission:tenant:{t}:user:{u}:permissions` | 60s | `PermissionCacheService` |
| `l2:permission:tenant:{t}:user:{u}:permissions` | 300s | `PermissionCacheService` |
| `permission:tenant:{t}:user:{u}:check:{md5(code)}` | 30s | `PermissionCacheService::hasPermission()` |
| `casbin:check:{u}:{t}:{resource}:{action}` | 300s（`CASBIN_CACHE_TTL`） | `CasbinService::check()` |
| `casbin:policy:full` | 300s（`policy_cache_enabled` 默认关闭） | `DatabaseAdapter` |

实测落库形态（phpredis `SCAN` 取样）：`alkaid:l1:permission:tenant:9:user:9:permissions`、
`alkaid:session:verify-r1-abc123`、`alkaid:rl:dev:user:verify-r1:token_bucket`。

#### 3.4.2 前缀白名单（安全边界）

清除操作**只允许**命中下列前缀族，其它前缀（含空串）一律抛异常拒绝执行：

`l1:permission:` / `l2:permission:` / `permission:` / `casbin:check:` / `casbin:policy:`

这条边界是结构性的：所有权限相关键都含 `permission:` 段或 `casbin:` 段，
而 `session:`（会话）、`rl:`（限流）、`lowcode:`（低代码）、`casbin_last_reload`
都不含 —— 因此「清权限缓存」在**定义上**不可能误删会话与限流计数。

**禁止**再用 `Cache::clear()` 清权限缓存：ThinkPHP 的 Redis 驱动把它实现为 `flushDB()`，
清的是整个库（会话与缓存同库）；File 驱动则是 `rmdir(缓存目录)`。
同理禁止用 `KEYS pattern`（O(N) 且阻塞单线程 Redis），只允许 `SCAN` + 批量 `DEL`。

#### 3.4.3 驱动适配

| 驱动 | 策略 | 说明 |
| --- | --- | --- |
| Redis（phpredis） | `SCAN` 游标迭代 + 分批 `DEL`（500/批） | 生产路径。按前缀首段分组，同组共用一轮 `SCAN` |
| File | 键索引精确删除 | File 驱动把键名 `md5()` 后落盘（`runtime/cache/ab/cdef….php`），**无法反推**，因此写入时登记「写过哪些键」，清除时按索引逐键删 |
| 其它（Predis / Memory / 自定义） | 同上（索引） | **绝不**退化为 `Cache::clear()`；索引为空时只记 warning 并返回 0 |

#### 3.4.4 失效时机与覆盖范围

失效一律发生在**写操作提交之后**（提交前清缓存会让并发请求把未提交的旧数据重新填满）：

| 变更 | 失效对象 | 钩子位置 |
| --- | --- | --- |
| 角色权限变更（`syncPermissions`） | 该角色下**所有用户** | `RoleRepository::syncPermissions()` |
| 角色删除 | 该角色的成员 | `RoleRepository::delete()`（删除前先取成员） |
| 用户角色变更（`setRoles`） | 该用户 | `UserController::setRoles()` |
| 用户状态变更（`updateStatus` / `update`） | 该用户 | `UserController::updateStatus()/update()` |
| 用户删除 | 该用户 | `UserController::delete()` |
| 权限定义变更/删除 | 全部权限缓存 | `PermissionRepository::save()/delete()` |
| 管理端「清全部权限缓存」 | 全部权限缓存 | `PermissionCacheService::clearAllCache()` |

影响用户数超过 100 时自动升级为「整族清除」：一次 `SCAN` 胜过上千次逐用户拼键。

#### 3.4.5 失败处理

缓存失效是**尽力而为**：`PermissionCacheInvalidator` 全部方法内部 `catch (Throwable)`，
失败只记 `warning` 并返回 `success=false`，**不向上抛**、不回滚业务写入。
清缓存失败让一次成功的撤权回滚是本末倒置 —— 最坏后果是旧权限多活一个 TTL。

#### 3.4.6 已知降级（File 驱动，仅开发/测试）

键索引有上限（5 万条）与 TTL（1 小时）。超限时丢弃最旧记录并记 warning，
被丢弃的键只能等 TTL 自然过期。生产环境强制 Redis（`config/cache.php` 对
`production/prod/stage/staging` 强制 `redis`），不受此限制。

## 4. 数据安全

### 4.1 加密- **传输加密**：强制使用 HTTPS（TLS 1.2+ 最低，推荐 TLS 1.3）
- **存储加密**：
  - 密码：使用 `bcrypt` 或 `Argon2` 加密
  - 敏感数据（PII）：静态数据使用 AES-256 加密

### 4.2 输入验证
- 使用验证类验证所有输入
- 清理用户输入以防止 XSS 攻击
- 使用参数化查询（PDO/ORM）防止 SQL 注入

## 5. API 安全

### 5.1 限流

本项目的限流模型分为两个阶段，两个阶段共用相同的错误响应约定（HTTP `429` + 业务 `code = 429`）。

- **Phase 1（基线要求——固定时间窗口实现）**：
  - `app/middleware/RateLimit.php` 中间件必须基于配置的缓存存储（通常为 Redis）实现**固定时间窗口计数**。
  - 必须支持多维度限流：`user`、`tenant`、`ip`、`route`，并允许通过 `config/ratelimit.php` 为特定路由覆盖默认规则。
  - 必须支持 IP / 用户 / 租户白名单；当缓存出现故障时，中间件必须记录告警并降级为放行（fail-open）。
  - 命中限流时，必须返回 HTTP `429`，业务 `code = 429`。
  - 响应体的 `data` 字段必须包含 `scope`、`limit`、`period`、`current`、`identifier` 等信息，便于诊断。
  - 响应头至少必须包含：
    - `Retry-After`：剩余的惩罚窗口秒数。
    - `X-Rate-Limited`：命中限流时为 `"1"`。
    - `X-RateLimit-Scope`：触发限流的维度（如 `user`、`tenant`、`ip`、`route`）。

- **Phase 2（目标算法——基于 Redis 的令牌桶，必须落地）**：
  - API 限流的长期目标算法必须切换为基于 Redis 的令牌桶，以支持更精细的限流与更平滑的突发流量削峰。
  - 在 Phase 2 落地后，中间件应当补充标准限流响应头，例如：
    - `X-RateLimit-Limit`：当前窗口允许的最大请求数。
    - `X-RateLimit-Remaining`：当前窗口剩余请求数。
    - `X-RateLimit-Reset`：当前限流窗口的重置时间戳。
  - 具体的令牌桶配置与迁移方案应记录在设计文档与部署手册中；当 Phase 2 算法在生产环境生效时，本节必须同步更新以反映实际行为。

### 5.2 API 签名验证（高安全级别 - 可选）
- 用于需要增强安全性的关键/第三方 API
- 必需的请求头：
  - `X-App-Key`：应用密钥（平台分配）
  - `X-Timestamp`：请求时间戳（±300秒容差）
  - `X-Nonce`：唯一随机字符串（16+ 字节，CSPRNG）
  - `X-Signature`：签名值（默认 HMAC-SHA256）
  - `X-Signature-Algorithm`（可选）：算法（hmac-sha256/rsa-sha256/ed25519）
- **签名字符串**：
  ```
  plain = method + '|' + path_with_query + '|' + timestamp + '|' + nonce + '|' + body
  signature = HMAC_SHA256(plain, app_secret)
  ```
- **防重放**：Nonce 在时间窗口内缓存，重复 Nonce 被拒绝

## 6. 错误处理

- 生产环境不得暴露堆栈跟踪信息（`APP_DEBUG=false`）
- 使用标准错误码以保证客户端处理一致性

### 6.1 完整安全错误码矩阵

| 业务码 | HTTP 状态 | 场景 | 触发来源 | 示例消息 |
|--------|-----------|------|----------|----------|
| 2001 | 401 | 未授权：Token 缺失/无效/过期 | Auth 中间件；Permission 中间件（未认证） | "Unauthorized: Token is missing, invalid, or expired" |
| 2002 | 403 | 禁止访问：权限不足 | Permission 中间件（已认证但无权限） | "Forbidden: Insufficient permissions" |
| 2003 | 401 | Refresh Token 格式无效/签名错误 | `/v1/auth/refresh`（JwtService 验证） | "Invalid refresh token format or signature" |
| 2004 | 401 | Refresh Token 已过期 | `/v1/auth/refresh`（过期检查） | "Refresh token has expired" |
| 2005 | 401 | Token issuer 不匹配 | `/v1/auth/refresh`（issuer 验证） | "Token issuer mismatch" |
| 2006 | 401 | 错误的 Token 类型（用 Access 调用 Refresh 接口） | `/v1/auth/refresh`（类型检查） | "Expected refresh token, got access token" |
| 2007 | 401 | Refresh Token 已撤销/重放 | `/v1/auth/refresh`（白名单/黑名单检查） | "Refresh token has been revoked or invalidated" |
| 5000 | 500 | 服务器内部错误 | 全局异常处理器 | "Internal Server Error" |

> **参考**：完整错误处理设计见 `design/04-security-performance/11-security-design.md`

## 7. 安全最佳实践

### 7.1 密码策略
- 最小长度：8 个字符
- 必须包含：大小写字母、数字、特殊字符
- 定期提醒用户更新密码

### 7.2 会话管理
- Session 数据存储在 Redis（生产环境禁止使用文件存储）
- Session ID 使用加密安全的随机数生成
- 设置合理的会话超时时间

### 7.3 审计日志
- 记录所有敏感操作（登录、权限变更、数据修改等）
- 日志字段：user_id、tenant_id、操作类型、时间戳、IP 地址、trace_id
- 定期审查异常行为

## 8. 安全检查清单（部署前）

- [ ] 所有敏感配置已从代码中移除，使用环境变量
- [ ] 数据库连接使用强密码
- [ ] Redis 启用密码认证
- [ ] HTTPS 证书已配置且有效
- [ ] 防火墙规则已配置（仅开放必要端口）
- [ ] 生产环境 `APP_DEBUG=false`
- [ ] 定期备份策略已建立
- [ ] 安全漏洞扫描已完成
- [ ] 生产环境强制使用 Redis（禁用文件缓存）
