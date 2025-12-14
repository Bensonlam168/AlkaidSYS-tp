# T0-T1 阶段整改复盘报告（AlkaidSYS-tp）

## 1. 执行摘要

- **总体结论**：
  - T0（三大对外契约：统一响应、认证授权、多租户上下文）已在代码与设计文档层面基本冻结，当前实现与约定高度一致，仅剩异常兜底与测试覆盖可进一步增强。
  - T1 核心基础设施任务中：T1-MW-ENABLE、T1-DDL-GUARD、T1-CACHE-REDIS、T1-SESSION-REDIS 已在本地与文档层面形成闭环；T1-DOMAIN-CLEANUP 仍处于规划阶段，尚未实质启动，是当前最主要的技术债来源之一。
  - Redis / Session / Swoole 相关隐患在本轮已集中修复，生产类环境仍需按剧本在 stage/prod 环境完成最终演练与记录。
- **关键风险与遗留问题（摘要）**：
  - 领域模型重叠（T1-DOMAIN-CLEANUP 未启动）导致低代码相关模块阅读与演进成本偏高。
  - 认证 / 多租户 / Guard 等关键路径缺少系统化 Feature/Unit Test 覆盖，主要依赖手工验证。
  - 少量调试与异常路径（如 /debug/session-redis、全局异常处理）在生产级安全性与一致性上仍有改进空间。

---

## 2. 按任务维度的复盘

### 2.1 T0-API-UNIFY：统一 API 响应规范

- **目标达成度**：
  - `app/controller/ApiController` 已实现统一响应结构 `{code,message,data,timestamp(,trace_id)}`，设计文档 `design/03-data-layer/10-api-design.md`、前端错误处理规范等与之对齐。
  - 认证 / 权限中间件等也遵循相同结构和错误码规划（2001/2002/5000）。
- **实现完整性**：
  - 统一响应方法：`success/paginate/error/validationError/notFound/unauthorized/forbidden` 等已封装在 ApiController 内，控制器可直接复用。
  - 设计文档明确要求“对外 API 控制器必须继承 ApiController，禁止直接 `return json([...])`”。
- **潜在技术债**：
  - 目前未对全仓进行静态检查来验证是否仍存在历史遗留的 `return json([...])` 或未继承 ApiController 的对外控制器。
  - 全局异常处理 `app/ExceptionHandle` 仍基本保留框架默认行为，部分异常路径可能返回非标准结构。
- **与其他任务依赖关系**：
  - 为 T0-AUTH-SPEC、T0-MT-CONTEXT、T1-MW-ENABLE 提供统一的响应基线，特别是认证 / 权限错误码与载荷结构。

### 2.2 T0-AUTH-SPEC：认证/授权契约

- **目标达成度**：
  - JwtService 已实现标准 Claim（`iat/exp/nbf/jti`），Access/Refresh Token 均内含 `user_id/tenant_id/site_id`。
  - `app/middleware/Auth` 和 `Permission` 统一采用业务码：2001（未认证）、2002（权限不足）、5000（内部权限错误），响应结构与 T0-API-UNIFY 一致。
  - `route/auth.php` 明确了 `login/register/refresh/me` 等基础认证路由，`me` 受 Auth 中间件保护。
- **实现完整性**：
  - 认证错误处理逻辑较为统一：缺少或无效 Token 均返回 HTTP 401 + `code=2001`。
  - JWT 生成与校验逻辑集中于 `infrastructure/Auth/JwtService`，设计文档 `design/04-security-performance/11-security-design.md` 与代码匹配。
- **潜在技术债**：
  - 全局异常处理尚未在所有与鉴权相关的异常分支（如仓库 / 服务层抛错）统一落到标准响应结构。
  - 针对登录 / 刷新 / 过期 / 撤销的自动化测试较少，依赖人工验证。
- **与其他任务依赖关系**：
  - 为 T0-MT-CONTEXT 提供 user/tenant/site 载荷来源；为 T1-MW-ENABLE 提供 Auth/Permission 中间件统一语义。

### 2.3 T0-MT-CONTEXT：多租户/多站点上下文契约

- **目标达成度**：
  - `app/Request` 扩展 + `TenantIdentify/SiteIdentify/Auth` 中间件 + `app/model/BaseModel` 全局作用域，构成完整的“请求上下文 → 模型隔离”链路；设计文档 `design/01-architecture-design/04-multi-tenant-design.md`、`03-data-layer/12-multi-tenant-data-model-spec.md` 已定稿。
  - Request 方法 `tenantId/siteId/userId/isAuthenticated/getTenantContext` 已按文档约定实现，来源与优先级（中间件属性 > Header > 默认值）清晰。
- **实现完整性**：
  - `TenantIdentify`/`SiteIdentify` 当前实现采用 Header 直读并写入 Request，保留 TODO 针对真实 Tenant/Site 校验；
  - BaseModel（在设计文档中）定义了基于 `tenant_id/site_id` 的全局 Query Scope，与 Request API 匹配。
- **潜在技术债**：
  - 域名维度的租户 / 站点识别、租户存在性校验仍停留在设计文档示例，尚未全面落地。
  - 未见针对“跨租户 / 跨站点查询关闭 Scope”的审计机制实现，仅在文档中约定。
- **与其他任务依赖关系**：
  - 是 T1-MW-ENABLE、多租户数据建模及低代码数据层的前置基础；与 T0-AUTH-SPEC 双向关联。

### 2.4 T1-MW-ENABLE：中间件启用与路由分组

- **目标达成度**：
  - 全局中间件栈：`app/middleware.php` 已启用 Trace、SessionInit、TenantIdentify、SiteIdentify，符合设计中推荐顺序。
  - `config/middleware.php` 提供 `auth/permission` alias，路由层如 `route/lowcode.php` 使用数组中间件显式启用 Auth + Permission。
- **实现完整性**：
  - dev 环境下 `AUTH_SKIP_MIDDLEWARE` / `PERMISSION_SKIP_MIDDLEWARE` 提供安全可控的“放宽校验”开关。
  - 多处路由文件已按分组挂载中间件（如低代码、认证路由）。
- **潜在技术债**：
  - 尚无系统性的 Feature Test 验证各路由组是否都按设计挂载了 Auth/Permission/TenantIdentify/SiteIdentify（目前主要靠文档与代码审查）。
- **与其他任务依赖关系**：
  - 直接依赖 T0-AUTH-SPEC 与 T0-MT-CONTEXT 的协议冻结，是 T1 阶段保障安全与隔离的载体。

### 2.5 T1-DDL-GUARD：运行时 DDL 防护

- **目标达成度**：
  - `Infrastructure/Schema/SchemaBuilder::assertCanRunDDL()` 已实现 APP_ENV + 白名单 + 显式开关的组合防护策略；prod 环境强制阻断运行时 DDL，返回 403 + `code=5003`。
  - 设计文档 `design/03-data-layer/11-database-evolution-and-migration-strategy.md` 与 `.env.example` 中的 `SCHEMA_RUNTIME_DDL_ALLOWED_ENVS/ALLOW_RUNTIME_DDL` 注释与代码一致。
- **实现完整性**：
  - 所有运行时 DDL 入口统一通过 SchemaBuilder 包装，可被上述 Guard 控制；
  - 提供 CLI 命令 `php think test:ddl-guard` 用于在不同 APP_ENV/配置组合下验证行为；
  - 单元测试 `tests/Unit/Schema/SchemaBuilderTest` 覆盖了主要结构操作 API。
- **潜在技术债**：
  - SchemaBuilderTest 主要验证“能做什么”，对“在 prod-like 环境被 Guard 阻断”的自动化测试仍依赖 CLI 与人工验证；如需更高置信度，可在后续增加专门的 Guard 行为测试。
- **与其他任务依赖关系**：
  - 与低代码 DDL 能力紧密相关，是避免“低代码直改生产结构”的最后防线；与多租户建模规范文档强关联。

### 2.6 T1-CACHE-REDIS：生产环境 Redis 缓存

- **目标达成度**：
  - `config/cache.php` 在生产类环境（production/prod/stage/staging）强制 `cache.default='redis'`，否则通过 `CacheEnvironmentGuardService` 在启动期直接抛错。
  - `.env.example` 与 `design/05-deployment-testing/14-deployment-guide.md` 明确了本地 Docker / 外部 Redis 的多环境配置策略与示例。
- **实现完整性**：
  - 非生产环境可通过 `CACHE_DRIVER` 切换 file/redis，便于开发调试；
  - Redis 连接参数（host/port/password/db）统一从 `REDIS_*` 读取，并通过 `RedisHealthCheckService` 在启动期做 set/get 自检。
- **潜在技术债**：
  - `config/swoole.php` 中 websocket.room.redis 默认 host 为 `127.0.0.1`，若未来启用 WebSocket 房间功能，需要与统一 Redis 拓扑对齐，当前视为“外围配置一致性风险”。
- **与其他任务依赖关系**：
  - 为 T1-SESSION-REDIS 提供 Redis store 基础；与 DDL Guard、低代码缓存测试部分共享 Redis 基础设施。

### 2.7 T1-SESSION-REDIS：Session 迁移 Redis + 启动期校验

- **目标达成度**：
  - Session 驱动已配置为 `type='cache'`、`store='redis'`，key 形态为 `alkaid:session:{session_id}`。
  - 生产类环境通过 `SessionEnvironmentGuardService` 强制禁止回退到 file 驱动；
  - `RedisHealthCheckService` 保证 Redis 连接在启动期 fail-fast；
  - CLI 命令 `php think test:session-redis` + HTTP `/debug/session-redis` 调试接口 + Redis CLI（KEYS/GET/MONITOR）在本地 Docker（HTTP+Swoole）下完成端到端验证。
- **实现完整性**：
  - 全局中间件栈已启用 `	hinkramework\SessionInit`，保证 Session 在请求生命周期结束时落盘；
  - 部署文档中已给出针对 stage/prod 的 Session/Redis 验收剧本与签字模板。
- **潜在技术债**：
  - stage/prod 环境尚未真实执行 `/debug/session-redis` + Redis MONITOR 的验收流程，当前仅有“本地验证 + 文档剧本”；
  - `/debug/session-redis` 本身在生产环境的访问控制（环境开关 / IP 白名单 / 鉴权）仍待加强。
- **与其他任务依赖关系**：
  - 建立在 T1-CACHE-REDIS 的 Redis 拓扑之上，与 T1-MW-ENABLE 中的 SessionInit、中间件顺序紧密耦合。

### 2.8 T1-DOMAIN-CLEANUP：领域模型清理

- **目标达成度**：
  - 目前仅在 `docs/todo/refactoring-plan.md` 与多处架构/低代码设计文档中提出目标与方向，尚未在代码中看到大规模重构动作；验收 checklist 完全未打勾。
- **实现完整性**：
  - 代码层面存在两套职责相近的 Collection 模型：`domain/Model/Collection` 与 `domain/Lowcode/Collection/Model/Collection`，以及对应 Relationship 模型等，是典型的领域重叠问题。
  - 无统一的“领域模型 vs ORM 模型”分层落地实践，多处仍遵循历史演进路径。
- **潜在技术债**：
  - 阅读与维护成本较高，新人上手需要理解两套模型体系及其调用方；
  - 未来在多租户 / 低代码演进（如更复杂的 SchemaBuilder、跨服务复用）上可能形成约束。
- **与其他任务依赖关系**：
  - 与低代码框架、SchemaBuilder、多租户数据建模紧密相关，但与 T0 契约、T1 中间件 / Redis 基建耦合度相对较低，可作为后续单独专题推进。

---

## 3. 交叉维度观察

### 3.1 文档与代码一致性

- 优点：
  - 多数设计文档（API 设计、安全设计、多租户设计、数据演进、部署指南）都明确标注与实际代码文件的一一对应关系（类名/方法名），目前抽样检查的关键点（ApiController、Auth/Permission、Request、多租户、SchemaBuilder、Redis/Session Guard 等）与实现基本一致。
- 风险点：
  - 个别说明仍处于“规划状态”而非实现状态，例如 TenantIdentify/SiteIdentify 的域名识别、租户存在性校验、领域模型统一方案，需要在后续迭代中避免“文档先于代码过久”。

### 3.2 测试覆盖与质量基线

- 现有测试集中在：低代码表单/Schema、Validator 生成、事件系统等领域；
- 针对以下关键路径的自动化测试仍明显不足：
  - 认证 / 授权（登录、刷新、过期、撤销、权限不足）；
  - 多租户上下文传递与 BaseModel 全局作用域行为；
  - 中间件链路与 Guard 行为（Auth/Permission/TenantIdentify/SiteIdentify + Cache/Session/DDL Guard）；
  - Session/Redis 在 Swoole/HTTP 不同运行模式下的行为（目前主要依赖手工 CLI + curl + Redis CLI）。

### 3.3 安全与运维视角

- 安全：
  - 认证 / 权限中间件与错误码矩阵较为规范，Trace 中间件为后续审计与排错打下基础；
  - `/debug/session-redis` 等调试接口在生产环境的访问控制与开关机制仍需实装（仅在文档中提示风险）。
- 运维：
  - T1-DDL-GUARD、T1-CACHE-REDIS、T1-SESSION-REDIS 已将关键环境变量和操作流程写入 `design/05-deployment-testing/14-deployment-guide.md`，为 stage/prod 验收提供清晰剧本；
  - 尚缺少对认证 / 多租户 / 低代码关键接口的统一健康检查与监控指标定义，可在 T2/T3 阶段补充。

---

## 4. 主要遗留问题与优先级

1. **领域模型重叠（T1-DOMAIN-CLEANUP）** – 高优先级 / 中高工作量
   - 行动建议：完整列出 Collection/Field/Relationship 等模型及其调用方，设计统一的领域模型命名与层次，分阶段迁移，严控一次性大爆炸式重构。
2. **关键路径测试覆盖不足（对应 T3-TEST-COVERAGE）** – 高优先级 / 可并行推进
   - 行动建议：以认证、多租户、DDL Guard、Redis/Session Guard、低代码核心流为主，补充 Feature/Unit Test 并接入 CI。
3. **调试接口生产安全治理** – 中优先级 / 低工作量
   - 行动建议：为 `/debug/*` 路由增加环境变量开关（如 `DEBUG_ROUTES_ENABLED`）、IP 白名单或 Auth 保护；在部署剧本中要求验收后关闭。
4. **中间件与 Guard 自动化验证** – 中优先级 / 中等工作量
   - 行动建议：为典型路由组添加 Integration Test，验证在不同 APP_ENV、开关组合下中间件与 Guard 行为符合预期。

---

## 5. 建议的后续行动路线

1. **短期（当前迭代内可执行）**：
   - 将本地已形成的 Session/Redis + stage/prod 验收 Checklist 真正运用于至少一个非生产环境，并沉淀实际验收记录。
   - 为 `/debug/session-redis` 增加环境开关与访问控制逻辑，减少生产暴露风险。
2. **中期（T1 尾声 ~ T2 起点）**：
   - 启动 T1-DOMAIN-CLEANUP，先从 Collection/Relationship 体系梳理和调用方清单入手，形成详细重构方案与分批计划。
   - 选取 2–3 条关键业务链路（如登录 + 多租户访问 + 低代码表单 CRUD）编写端到端 Feature Test，为后续测试体系搭建样板。
3. **长期（与 T2/T3 联动）**：
   - 将当前的 Guard / 中间件 / 多租户上下文机制纳入统一 Observability 方案：结构化日志、trace_id 透传、健康检查接口等。
   - 根据未来 Vben Admin 对接与多环境部署需求，持续检视 T0/T1 契约是否需要非破坏性扩展，并保持文档与实现同步演进。

