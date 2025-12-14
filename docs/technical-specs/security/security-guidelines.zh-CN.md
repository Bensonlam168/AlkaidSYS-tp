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

## 4. 数据安全

### 4.1 加密
- **传输加密**：强制使用 HTTPS（TLS 1.2+ 最低，推荐 TLS 1.3）
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
