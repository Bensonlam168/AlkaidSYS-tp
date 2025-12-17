# AlkaidSYS 设计契合度审查报告（Design Compliance Audit）

> 报告日期：2025-12-14  
> 审查对象：AlkaidSYS（后端 PHP / ThinkPHP，多租户 + 低代码平台）  
> 审查范围：架构分层、API 与错误码、数据模型与多租户、认证与权限、低代码框架、多租户隔离、代码规范与测试

---

## 1. 执行摘要（Executive Summary）

本次审查以 `design/` 与 `docs/technical-specs/` 目录下的设计与规范文档为基线，对当前代码实现（Phase 1）进行了系统对比，重点关注：

- 架构分层与职责边界
- API 设计、统一响应与错误码矩阵
- 数据模型与多租户（含低代码动态表）
- 认证与权限（JWT 双 Token、权限码格式、中间件）
- 低代码框架（Schema-first、Legacy 兼容层、动态表）
- 运行时多租户隔离（Request 上下文、BaseModel 作用域）
- 代码规范与测试

### 1.1 总体结论（Summary）

- **整体契合度较高**：核心架构、多租户上下文、JWT 双 Token、权限模型、低代码 Collection 管理等关键设计均已在 Phase 1 得到较完整落地。
- **低代码与多租户为亮点**：低代码 CollectionManager + SchemaBuilder 负责动态表生命周期，配合迁移脚本完成了对历史低代码表的 `tenant_id/site_id` 补齐与索引加固；BaseModel + Request 上下文实现了多租户行级隔离的基础。
- **安全与可观测性存在少量设计偏差**：认证错误码部分与 Always Rules 规定不完全一致，trace_id 在错误 JSON 体中注入存在接口不匹配风险，影响问题排查与监控闭环。
- **低代码多租户仍处于 Phase 1 过渡阶段**：Collection 元数据和缓存目前仍以“系统模板租户（tenant_id=0）”为主，部分 API 对真实租户维度的支持尚未完全统一，需要在 Phase 2 中按设计文档补齐。
- **代码规范整体良好但历史代码存在技术债**：新代码基本遵守 PSR-12 与命名规范，并引入 php-cs-fixer 配置；Legacy 域模型与早期代码风格不完全统一，需要在后续重构批次中逐步清理。

### 1.2 各维度完成度与契合度概览

> 说明：完成度 = 相对设计文档中规划能力的实现比例；契合度 = 已实现部分与设计意图的一致性。评分基于抽样审查与规则对照，主观范围 ±10%。

| 维度 | 完成度 | 契合度 | 备注 |
| ---- | ------ | ------ | ---- |
| 架构分层与模块职责 | ~85% | ~85% | 分层与依赖方向基本遵守，Legacy 域模型仍存在但已降级为兼容层 |
| API 设计与错误码矩阵 | ~80% | ~80% | 统一响应与路由大体符合，认证错误码与 trace_id 注入存在偏差 |
| 数据模型与多租户数据层 | ~90% | ~85% | 迁移脚本与表结构基本符合，多租户索引设计略有简化 |
| 运行时多租户隔离 | ~85% | ~80% | Request 上下文与 BaseModel 作用域已实现，原生 SQL 覆盖情况有不确定性 |
| 认证与权限机制 | ~90% | ~90% | JWT 双 Token、PermissionService、权限码格式总体对齐，个别错误码语义混用 |
| 低代码框架（Schema & 动态表） | ~75% | ~80% | Collection/Field/Relationship 管理与动态表落地良好，多租户元数据仍在演进中 |
| 代码规范与测试 | ~70% | ~75% | 新代码遵循规范并有测试基础，历史代码与测试覆盖仍有提升空间 |

整体来看，AlkaidSYS 在 **安全多租户 + 低代码平台基础设施** 方面已经构建了较为扎实的 Phase 1 能力，当前主要风险集中在：

1. 认证/授权错误码与 trace_id 注入等“**统一契约**”未完全对齐设计，影响跨模块一致性与可观测性。
2. 低代码多租户元数据与缓存仍以“租户 0 模式”为主，尚未完全实现“租户内独立 Collection 元数据”的 Phase 2 目标。
3. Legacy 域模型与旧代码风格尚未彻底退出主业务路径，需要通过计划性的重构批次清理，以避免未来演进中出现双轨实现。

后续章节将按模块给出详细对照结果与问题清单，并在第 4 章与第 5 章中给出问题分类统计与多阶段改进建议。

---

## 2. 审查方法与范围（Methodology & Scope）

### 2.1 设计基线与规则来源

本次审查以以下文档与规则为“唯一权威来源”：

- `design/` 目录下架构、数据、多租户、低代码、安全与插件系统设计文档
- `docs/technical-specs/` 下的技术规范、错误码矩阵、API 统一响应规范
- `.augment/rules/always-alkaidsys-project-rules.md`（Always Rules）
- `.augment/rules/auto-alkaidsys-guidelines.md`（Auto Guidelines）
- Serena 记忆中的多租户、权限码与低代码统一方案决策

特别注意：

- 明确区分 **Phase 1（当前实现）** 与 **Phase 2（规划能力）**：凡设计中标注为 Phase 2 的能力，若当前未实现，视为“**未完成但不算偏离**”，在完成度中扣分，但不视为设计违背。
- 对低代码相关实现，优先以 Domain\Lowcode + Infrastructure\Lowcode 为事实来源；Legacy Collection / CollectionManager / FieldTypeRegistry 仅作为兼容层存在。

### 2.2 审查步骤

1. **规则提炼（Checklist）**  
   从架构、多租户、安全、低代码、API 与代码规范文档中，提炼出“可程序化检查”的规则条目，例如：
   - “所有租户级业务表必须包含 `tenant_id`（必要时包含 `site_id`）并建立相应索引”；
   - “认证失败统一使用 HTTP 401 + `code=2001`，权限不足使用 HTTP 403 + `code=2002`”；
   - “权限内部以 `resource.action` 作为主键，对外暴露 `resource:action` 字符串数组”；
   - “控制器统一继承 ApiController 并通过 Helper 返回 `code/message/data/timestamp(/trace_id)` 结构”；
   - “新业务不得再新增对 Legacy Collection 体系的依赖”。

2. **代码对照检查**  
   - 使用 `codebase-retrieval` 及 `view` 抽样读取与规则相关的实现文件：中间件、Request、JwtService、PermissionService、低代码 Collection 管理与迁移脚本等；
   - 对照规则清单，逐一判断“已完全符合 / 部分符合 / 未实现 / 明显偏离”，并记录证据位置（文件、类、方法）。

3. **交叉验证与误报排除**  
   - 对初步发现的问题，通过再次阅读相关设计文档或代码上下文进行交叉验证，避免因历史文档或已废弃实现导致的误报；
   - 对已有 ADR / TODO / Report Archive 中记录并已修复的问题（如 `lowcode_collections.uk_name` 全局唯一索引），仅在报告中作为“历史问题已修复”加以说明，不计入当前问题得分。

4. **量化评分与问题分级**  
   - 按模块给出完成度（实现比例）与契合度（实现与设计吻合度）评分；
   - 为每个问题打上 **严重度（Blocker / Major / Minor / Info）** 标签，并按类型（架构 / API / 数据 / 安全 / 低代码 / 规范）归类。

5. **生成结构化报告**  
   - 按你要求的结构编写本报告：执行摘要 → 详细结果 → 问题统计 → 改进建议 → 附录；
   - 报告文本落地到 `docs/report/design-compliance-audit-2025-12-14.md`，便于后续在 PR / 里程碑中引用。

### 2.3 审查范围界定

- **覆盖范围**：
  - 核心中间件：`Trace`、`TenantIdentify`、`SiteIdentify`、`Auth`、`Permission`、`RateLimit`；
  - 自定义 Request：`app/Request.php`；
  - 认证与权限：`Infrastructure/Auth/JwtService.php`、`Infrastructure/Permission/Service/PermissionService.php`；
  - 低代码：`Domain/Lowcode/**`、`Infrastructure/Lowcode/**`、低代码相关 Controller 与路由；
  - 多租户与数据层：低代码相关迁移脚本、动态表 SchemaBuilder、BaseModel（已在前期轮次中审查）；
  - API 与统一响应：`app/controller/lowcode/**` 及部分其他代表性控制器与路由文件；
  - 代码规范：通过抽样文件与项目级 php-cs-fixer 配置进行评估。

- **不完全覆盖**：
  - 所有业务功能与所有 SQL/Query 的**逐行检查**不在本轮范围；对于“是否所有原生 SQL 均带 `tenant_id` 条件”等问题，仅能给出基于抽样和架构约束的风险评估与建议。

### 2.4 Phase 1 vs Phase 2 边界说明

在评分和问题判断时，本次审查严格区分：

- **Phase 1（当前实现）**：
  - 多租户：基于共享库 + 行级隔离的租户模型（所有租户级表包含 `tenant_id`，必要时包含 `site_id`），通过 Request 上下文和 BaseModel 作用域实现租户隔离；
  - 低代码：以 `Domain\\Lowcode` + `Infrastructure\\Lowcode` 为核心的 Collection/Field/Relationship 管理与动态表 SchemaBuilder，实现单库多租户场景下“一套物理结构 + `tenant_id/site_id` 行级隔离”；
  - 安全：JWT 双 Token（Access + Refresh）、Auth/Permission/RateLimit 中间件链、统一响应封装；
  - 权限：DB RBAC 为主，Casbin 处于接入与验证阶段，前后端统一使用 `resource:action` 字符串权限码；
  - 插件：应用/插件生态的基础能力与路由结构已经规划，但完整的 Market / 依赖管理仍在演进中。

- **Phase 2（规划能力）**：
  - 低代码四层架构全面落地（Lowcode Foundation / Plugins / Application / Workflow Engine），支持复杂工作流、跨应用集成、基于 Schema 的自动化 SDK 生成等；
  - 多租户维度进一步细化（如跨库切分、按租户定制 Schema 变体、租户级插件隔离等）；
  - 权限体系全面切换或升级到 Casbin 多租户 RBAC/RBAC-with-domain 方案；
  - 插件/应用市场、工作流引擎、前后端 SDK Builder 等高级能力。

本报告中：

- 对 **Phase 1 目标中应已落地但未实现或明显偏离** 的部分，记为“问题/偏差”，计入完成度与契合度扣分；
- 对 **仅在 Phase 2 规划中出现、当前尚未启动实现** 的能力，记为“未来规划/待实现”，只在完成度中反映“尚未实现”的事实，不视为设计违背；
- 对于 **Phase 1 与 Phase 2 边界模糊** 的场景（如低代码多租户元数据是否完全租户化），在后文会单独标注并给出建议归类。

---

## 3. 详细审查结果（按模块）

### 3.1 架构分层与模块职责

**设计基线**（节选）：

- 参考：`design/01-architecture-design/02-architecture-design.md`、`01-architecture-design/08-low-code-design.md`
- 后端采用分层架构：表现层/应用层 → 领域层 → 数据访问层 → 基础设施层 → 低代码层；
- 控制器只负责路由、参数校验、调用 Service/Domain、统一响应，不直接访问数据库；
- 低代码相关能力嵌入整体架构，但以独立的 Domain/Infrastructure Lowcode 模块存在；
- Legacy 域模型允许保留作兼容层，但不得作为新增业务主入口。

**现状实现概览**：

- 项目目录结构与设计一致：`app/controller/**`、`Domain/**`、`Infrastructure/**`、`database/migrations/**` 分层清晰；
- 低代码相关控制器位于 `app/controller/lowcode/**`，统一继承 `ApiController`，通过 `Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager` / `RelationshipManager` 等服务完成业务逻辑；
- Domain/Infrastructure Lowcode 模块承担动态 Schema 管理与物理表创建职责，Repository 层负责数据访问；
- Legacy 体系（如 `Domain\\Model\\Collection`、`Infrastructure\\Collection\\CollectionManager`、`Infrastructure\\Field\\FieldTypeRegistry`）保留，但在文档与代码中均标注为 `@deprecated`，主要被 CLI 和少量历史路径引用。

**主要符合点**：

- 控制器层（以低代码模块为代表）基本通过 Service/Domain 完成业务，不直接操作数据库；
- 低代码能力被清晰拆分为 Domain + Infrastructure + Controller 三层，职责划分与设计文档基本一致；
- Legacy 集合/字段体系已经在设计与实现层面被降级为兼容层，新 HTTP/API 路径普遍使用新 Lowcode 体系；
- 代码中大量使用依赖注入（构造函数注入 + 容器解析），与代码风格规范一致。

**主要偏差 / 风险点**：

- **LEGACY-01（Minor，架构/Legacy）**：Legacy 域模型与旧版 CollectionManager 仍然存在于代码库中，尽管已被标记为 `@deprecated`，但目前缺少“防新增依赖”的技术护栏（例如静态检查或自动化审查规则），存在未来新代码误用的风险。
- 部分多租户相关能力（例如低代码 Collection 元数据的租户化）目前由基础设施层承载，Domain 层对多租户概念的显式建模仍处于演进中。

**小结**：

- 架构分层在关键路径上基本符合设计，Legacy 兼容层的存在是有意识的过渡安排，但后续需要通过工具化手段防止新业务回流到 Legacy 体系。

### 3.2 API 设计与错误码矩阵

**设计基线**（节选）：

- 参考：`docs/technical-specs/api/api-specification.zh-CN.md`、`docs/technical-specs/security/security-guidelines.zh-CN.md`；
- 所有 JSON 响应采用统一结构：`code/message/data/timestamp(/trace_id)`；
- RESTful 路由，路径与方法符合资源语义，禁止使用 `?action=delete` 之类模式；
- 错误码矩阵（Always Rules 摘要）：
  - 认证失败（Token 问题）→ HTTP 401 + `code = 2001`；
  - 权限不足 → HTTP 403 + `code = 2002`；
  - 其他 4xx/5xx 错误需携带 `trace_id`。

**现状实现概览**：

- `app/controller/lowcode/CollectionController`、`RelationshipController` 等控制器统一继承 `ApiController`，通过 `success` / `error` / `notFound` / `paginate` 等方法返回标准结构响应；
- 全局路由（如 `route/lowcode.php`、`route/auth.php`、`route/admin.php`）普遍以 RESTful 风格组织，低代码相关路由位于 `v1/lowcode/**` 前缀下；
- 中间件层的错误响应普遍通过 `ResponseHelper::jsonError` 构造，接口支持附带 `trace_id`；
- `RateLimit` 中间件命中限流时返回 HTTP 429，响应体携带限流信息，并尝试附带 `trace_id`。

**主要符合点**：

- 主流业务控制器已经统一采用 `ApiController` + Helper 返回统一 JSON 结构，避免了散落的 `return json([...])`；
- 路由规划遵循 RESTful 资源语义，低代码、认证、权限管理等均有清晰的 URL 前缀与分组；
- 错误响应在中间件与控制器层均通过统一 Helper 构造，便于在后续集中调整结构或增加字段。

**主要偏差 / 问题**：

1. **SEC-01：认证错误码与错误码矩阵不一致（Major，安全/API）**  
   - 位置：`app/middleware/Auth.php`。
   - 现状：
     - Token 缺失时使用 `code=2001`（符合规范）；
     - Token 过期时将错误码设置为 `2002`，而 Always Rules 中 `2002` 被定义为“权限不足（HTTP 403）”；
     - Token 被撤销时使用 `2003`，但相应语义在错误码矩阵中尚未统一。
   - 影响：
     - `2002` 同时被用于“权限不足（Permission 中间件）”和“Token 过期（Auth 中间件）”，将给前端与调用方带来歧义；
     - 与文档中“认证失败统一使用 2001”的描述不一致，降低错误码的可预测性与可维护性。

2. **OBS-01：trace_id 注入接口不匹配，错误 JSON 可能缺失 trace_id（Major，可观测性/API）**  
   - 位置：`app/middleware/Trace.php`、`app/Request.php`、`app/middleware/Auth.php`、`Permission.php`、`RateLimit.php`；
   - 现状：
     - `Trace` 中间件通过 `$request->setTraceId($traceId)` 注入 trace_id，并在响应头写入 `X-Trace-Id`；
     - 自定义 `Request` 定义了 `traceId()` / `setTraceId()` 方法，但**未定义** `getTraceId()`；
     - Auth/Permission/RateLimit 等中间件为获取 trace_id，调用的是 `$request->getTraceId()`（通过 `method_exists($request, 'getTraceId')` 探测）；
     - 由于方法名不匹配，中间件很可能获取不到 Request 中保存的 trace_id，只能退化为从请求头中读取或返回 `null`。
   - 影响：
     - 当 trace_id 只存在于 Request 对象而未写入请求头时，错误 JSON 体中的 `trace_id` 字段可能为空或缺失，不符合“4xx/5xx 必须带 trace_id”的设计要求；
     - 影响日志与监控系统按 trace_id 聚合问题，降低链路排查效率。

3. **PERM-01：路由中权限码格式存在混用（Minor，权限/API）**  
   - 位置：`route/lowcode.php`、`route/admin.php` 等；
   - 现状：
     - 部分路由中间件参数使用 `resource:action`（如 `lowcode:read`、`lowcode:write`）；
     - 部分仍使用 `resource.action`（如 `casbin.manage`）；
     - `Permission` 中间件内部已兼容两种写法，会统一转换为对外格式 `resource:action` 再调用 `PermissionService`。
   - 影响：
     - 虽然运行时行为正确，但与“路由中间件使用内部 slug，API 返回对外权限码”的设计描述存在轻微偏差；
     - 对新同学理解造成一定心智负担，建议在文档或代码中择一作为“推荐写法”，并统一落地。

**小结**：

- API 路由与统一响应整体契合设计，但在**错误码矩阵与 trace_id 注入**两个关键点上存在偏差，需要优先修正以保障安全与可观测性的一致性。

### 3.3 数据模型与多租户数据层

**设计基线**（节选）：

- 参考：`design/03-data-layer/09-database-design.md`、`03-data-layer/12-multi-tenant-data-model-spec.md`、`docs/technical-specs/database/database-guidelines.zh-CN.md`；
- 表名使用 `snake_case` 复数，字段使用 `snake_case`；
- 租户级业务表必须包含 `tenant_id`，必要时包含 `site_id`，并为常用查询路径建立复合索引；
- 低代码动态业务表通过 SchemaBuilder 自动生成，必须内建 `tenant_id`/`site_id` 及主查询索引；
- 所有结构变更通过迁移脚本完成，禁止手写 DDL 直连生产库。

**现状实现概览**：

- 低代码集合表 `lowcode_collections`：
  - 初始迁移 `20251119000001_create_lowcode_collections_table.php` 创建基础结构与 `uk_name`、`uk_table_name` 唯一索引；
  - 后续迁移 `20251125000001_add_tenant_site_to_lowcode_collections_table.php` 增加 `tenant_id` 与 `site_id` 字段，并添加 `(tenant_id, name)` 唯一索引 `uk_tenant_name`，将历史记录视为 `tenant_id=0, site_id=0` 的系统模板；
  - ADR-001 对 “`uk_name` 阻止多租户同名 Collection” 问题进行了决策，迁移 `20251214160000_drop_uk_name_from_lowcode_collections.php` 已删除 `uk_name`，保留 `(tenant_id, name)` 唯一约束。
- 动态低代码业务表：
  - SchemaBuilder 在创建动态表时默认添加 `tenant_id`/`site_id` 字段及 `idx_tenant_id_id(tenant_id, id)` 索引；
  - 迁移 `20251125001000_add_tenant_and_site_to_dynamic_lowcode_tables.php` 对历史动态表统一补齐 `tenant_id`/`site_id` 字段与索引。
- 其他核心表（用户、角色、权限等）遵循 `snake_case` 命名和多租户字段规范（抽样检查）。

**主要符合点**：

- 低代码集合与动态表的多租户字段与索引已通过迁移脚本系统性补齐，符合“迁移为唯一入口”的设计原则；
- 租户级元数据（如 `lowcode_collections`）在索引层面已经支持“租户内唯一 + 系统模板 0 租户”的混合模式，满足设计中的模板租户需求；
- 动态表 SchemaBuilder 与迁移脚本一致使用 `tenant_id`/`site_id` 字段和 `idx_tenant_id_id` 索引，保证按租户 + 主键的高效访问路径。

**主要偏差 / 风险点**：

- **MT-01：动态表索引未显式包含 site 维度（Minor，数据/性能）**  
  - 现状：SchemaBuilder 和修复迁移均仅创建 `idx_tenant_id_id(tenant_id, id)` 索引，而非文档中示例的 `(tenant_id, site_id, id)` 或至少 `(tenant_id, site_id)`；
  - 影响：
    - 对以 `tenant_id + id` 为主的查询路径（按主键查询）影响不大；
    - 对以 `tenant_id + site_id` 为主的分页列表或统计类查询，可能无法完全利用复合索引，存在潜在性能问题；
    - 当前尚未观察到功能性错误，但在租户/站点规模增大后，建议根据实际查询模式补充面向 `tenant_id + site_id` 的索引。

**小结**：

- 数据模型与迁移体系整体契合设计，多租户字段与索引落地较为扎实；主要改进空间在于进一步对齐站点维度的索引设计，以提升高并发和大规模租户场景下的性能表现。

### 3.4 运行时多租户隔离

**设计基线**（节选）：

- 参考：`design/01-architecture-design/04-multi-tenant-design.md`；
- 通过中间件链实现租户上下文的识别与注入：`Trace` → `TenantIdentify` → `SiteIdentify` → `Auth` → `Permission` → 业务控制器；
- 自定义 Request 负责统一提供 `tenant_id`、`site_id`、`user_id`、`trace_id` 等上下文访问接口；
- ORM 访问必须始终包含租户（和必要时的站点）过滤，建议通过 BaseModel 作用域自动注入；
- 禁止对跨租户数据做无范围更新或查询。

**现状实现概览**：

- 全局中间件顺序（`app/middleware.php`）与设计文档中 T0-MT-CONTEXT 链路基本一致：
  - `Trace` 负责生成/透传 `trace_id`；
  - `TenantIdentify` / `SiteIdentify` 负责识别租户/站点并注入 Request；
  - `AccessLog` 包裹 `RateLimit`，保证被限流的请求也被记录；
  - 路由分组内再叠加 `Auth` 与 `Permission` 中间件。
- 自定义 `app/Request.php` 提供：
  - `tenantId()` / `setTenantId()`、`siteId()` / `setSiteId()`、`userId()` / `setUserId()`；
  - `traceId()` / `setTraceId()`；
  - `getTenantContext()` 返回 `{ tenant_id, site_id, user_id }`；
  - 限流标记：`setRateLimited()` / `isRateLimited()` / `getRateLimitInfo()`。
- BaseModel（前期审查中）通过作用域自动在查询中注入 `tenant_id` / `site_id` 条件，实现行级隔离。

**主要符合点**：

- 中间件链路清晰，租户/站点上下文通过 Request 统一承载，符合“租户 Context 为单一事实来源”的设计；
- 控制器层普遍通过 `$request->tenantId()` / `$request->siteId()` 获取多租户上下文，而非信任请求体中的 `tenant_id` 字段；
- BaseModel 作用域机制与 Request 上下文结合，实现了大部分 ORM 查询的自动租户隔离。

**主要偏差 / 风险点**：

- **OBS-02：日志中获取租户 ID 的接口与 Request 不匹配（Minor，可观测性/多租户）**  
  - 位置：`app/middleware/Auth.php::logAuthFailure()` 及 `Permission` 中间件类似日志逻辑；
  - 现状：日志中通过 `method_exists($request, 'getTenantId')` 尝试获取租户 ID，而自定义 Request 实际提供的是 `tenantId()` 而非 `getTenantId()`；
  - 影响：
    - 在默认实现下，认证/权限失败日志中的 `tenant_id` 字段可能长期为 `null`，削弱了多租户维度上的审计与问题排查能力；
    - 与设计文档中关于“日志需带上 tenant_id/site_id”的要求不完全一致。

- **RAWSQL-01：原生 SQL 访问缺乏统一租户防护（Info，多租户/风险）**  
  - 现状：
    - ORM 访问通过 BaseModel 作用域实现了自动注入租户条件；
    - 对于通过 `Db::table()` 等方式书写的原生 SQL，目前未见集中封装层统一注入租户范围，是否全部手工加上 `tenant_id`/`site_id` 条件依赖于开发规范执行情况；
  - 影响：
    - 在当前抽样范围内未发现明显跨租户泄露，但从架构风险角度看，缺乏统一防护机制意味着未来新增原生 SQL 时容易遗漏租户条件；
    - 建议通过静态检查或统一 Repository 封装，减少直接使用原生 SQL 的必要性。

**小结**：

- 运行时多租户隔离的总体设计与落地较为扎实，问题主要集中在“日志与原生 SQL 访问缺乏强约束”这类可观测性与防御深度层面。

### 3.5 认证与权限机制

**设计基线**（节选）：

- 参考：`design/04-security-performance/11-security-design.md`、`docs/technical-specs/security/security-guidelines.zh-CN.md`；
- 使用 Access Token + Refresh Token 的 JWT 双 Token 机制，`iss` 与环境变量 `JWT_ISSUER` 一致；
- 刷新时必须轮换 Refresh Token，并将旧 Token 加入黑名单；
- 统一的 Auth / Permission 中间件：
  - Auth 失败 → HTTP 401 + `code = 2001`；
  - Permission 失败 → HTTP 403 + `code = 2002`；
- 权限模型：
  - 内部 slug：`resource.action`（DB 字段 `permissions.slug`）；
  - 对外权限码：`resource:action`，`/v1/auth/me` / `/v1/auth/codes` 返回该数组；
  - 路由中间件使用内部 slug，PermissionService 负责 slug ↔ code 的转换。

**现状实现概览**：

- `Infrastructure\\Auth\\JwtService`：
  - 使用 `Firebase\\JWT`（HS256），校验 `iss` 与 `JWT_ISSUER` 一致性；
  - Access/Refresh Token 分离，使用 `jti` + 缓存实现 Refresh Token 白名单与 Token 黑名单；
  - 对已撤销 Token 抛出明确异常信息，供上层区分。
- `app/middleware/Auth`：
  - 从 `Authorization: Bearer` 头获取 Token，支持开发环境可配置跳过；
  - 验证失败时记录详细日志（reason、trace_id、tenant_id、user_agent 等），并通过 `ResponseHelper::jsonError` 返回统一 JSON；
  - 成功时将 `user_id`、`tenant_id`、`site_id` 注入自定义 Request。
- `Infrastructure\\Permission\\Service\\PermissionService`：
  - 支持 DB_ONLY / CASBIN_ONLY / DUAL_MODE 等模式；
  - 从数据库中基于 `permissions.slug = resource.action` 查询权限，并返回对外格式 `resource:action` 的数组；
  - `hasPermission($userId, $permissionCode)` 接收 `resource:action`，在权限数组中直接查找。
- `app/middleware/Permission`：
  - 未登录时返回 HTTP 401 + `code=2001`；
  - 无权限时返回 HTTP 403 + `code=2002`；
  - 支持中间件参数为 `resource.action` 或 `resource:action`，最终统一为 `resource:action` 传给 `PermissionService`。

**主要符合点**：

- JWT 双 Token、Issuer 校验、黑/白名单机制与设计高度一致，安全基线良好；
- 权限内部 slug = `resource.action`、对外权限码 = `resource:action` 的模型已在 DB Seed、PermissionService 与 `/v1/auth/me` 返回结构中统一落地；
- Auth/Permission 中间件错误码（2001/2002）与 HTTP 状态码（401/403）整体对齐设计要求；
- 低代码路由和管理类路由（如 Casbin 策略重载）均通过 `Auth` + `Permission` + `RateLimit` 组合保护高风险操作。

**主要偏差 / 问题**：

- **SEC-01** 与 **PERM-01** 已在 3.2 节详细描述，本节不再赘述；
- Casbin 相关能力目前处于可选/兼容模式，未在所有需要细粒度授权的场景中全面启用，这在当前时间点更偏向 Phase 2 演进问题，而非设计违背。

**小结**：

- 认证与权限整体契合设计，安全链路（JWT → Auth → Permission）是本次审查的一大亮点；少量错误码与权限码格式的细节需要在后续小批次修正以提升一致性。

### 3.6 低代码框架契合度

**设计基线**（节选）：

- 参考：`design/09-lowcode-framework/40-lowcode-framework-architecture.md`、`41-lowcode-overview.md`、`42-lowcode-data-modeling.md`、`docs/technical-specs/lowcode/lowcode-guidelines.zh-CN.md`；
- 低代码采用“四层架构”：Framework Core → Lowcode Foundation → Lowcode Plugins → Lowcode Application；
- Collection/Field/Relationship 等动态实体以 Lowcode Schema 为“单一事实来源”，驱动动态表结构与运行时行为；
- Legacy Collection 体系逐步退出主业务路径，新路径统一使用 `Domain\\Lowcode` + `Infrastructure\\Lowcode`；
- 多租户场景下，Collection 元数据支持“系统模板租户（tenant_id=0）+ 租户内实例”模式。

**现状实现概览**：

- Domain 层：`Domain\\Lowcode\\Collection\\Model\\Collection`、`Field`、`Relationship` 等模型承载低代码 Schema；
- Infrastructure 层：
  - `Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager` 负责 Collection 创建/更新/删除、动态表 SchemaBuilder 调用、缓存与事件发布；
  - `FieldRepository`、`RelationshipRepository` 等仓储负责元数据持久化；
  - `SchemaBuilderInterface` 抽象了动态表 DDL 行为；
- 控制器层：`app/controller/lowcode/CollectionController`、`RelationshipController` 暴露 RESTful API，对接前端 Form/模型设计器；
- 迁移与 Seed：低代码相关表结构、索引与初始权限在迁移脚本与数据填充中有清晰定义。

**主要符合点**：

- 低代码能力清晰拆分为 Domain + Infrastructure + Controller 三层，职责划分与设计文档高度一致；
- 动态表创建流程遵循“先 SchemaBuilder 建表，再写入 Collection/Field/Relationship 元数据”的顺序，保证元数据与物理表同步；
- `FieldTypeRegistry` 对未知类型委托 Lowcode FieldFactory 处理，新字段类型只需在 Lowcode 层注册一次即可，减少重复实现；
- HTTP/API 场景普遍使用 Lowcode 新体系，Legacy 仅在兼容路径出现，未发现新增 HTTP 入口直接依赖 Legacy Collection。

**主要偏差 / 问题**：

1. **LOWCODE-01：Collection 元数据与缓存的多租户支持仍处于过渡阶段（Major，低代码/多租户）**  
   - 位置：`Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager::get()` 及缓存逻辑；
   - 现状：
     - `get(string $name, ?int $tenantId = null)` 中，当 `$tenantId` 为 `null` 时统一视为 `0`，即“系统模板租户”；
     - `cache()` 默认也使用 `tenantId=0` 作为缓存 key 维度，注释中标明“当前阶段 Collection 实体尚未包含租户信息，未来会调整”；
     - `CollectionController::read()` / `update()` 已在调用 `get()` 时显式传入 `$tenantId`，但 `RelationshipController::save()` 仍调用 `get($collectionName)`，即只针对系统模板；
   - 影响：
     - 当前实现相当于“低代码关系元数据仍然集中在系统模板租户 0”，租户内差异化模型暂未完全支持；
     - 这与 Phase 2 中“租户内可定制 Collection/Relationship 元数据”的目标存在差距，但在 Phase 1 仍可视为可接受的过渡方案；
   - 结论：
     - 作为 Phase 1 当前状态，并非功能性错误，而是对 Phase 2 能力尚未完全落地的体现；
     - 建议在中期规划中优先完成“Collection 元数据与缓存的租户化改造”，并统一 Controller 调用方式（全部显式传入 `$tenantId`）。

2. **LEGACY-01（续）：Legacy 低代码体系仍存在但已降级为兼容层（Minor，架构/低代码）**  
   - 已在 3.1 节说明，不再赘述；
   - 需要通过工具（静态检查/CI 规则）防止新增业务继续依赖 Legacy 命名空间。

**小结**：

- 低代码框架主体实现与设计高度一致，是当前架构的一大亮点；
- 主要改进空间集中在“多租户元数据租户化”和“Legacy 彻底退出主路径”两个方向。

### 3.7 代码规范与测试

**设计基线**（节选）：

- 参考：`docs/technical-specs/code-style/general-guidelines.zh-CN.md`、`code-style/phpdoc-standards.md`、`docs/technical-specs/testing/testing-guidelines.zh-CN.md`；
- PHP 代码必须符合 PSR-12 编码规范，新文件建议使用 `declare(strict_types=1);`；
- 通过 php-cs-fixer 统一风格，配置文件位于项目根目录，是唯一权威来源；
- 新功能（尤其是认证/权限/限流/迁移）必须增加或更新自动化测试；
- 所有测试、迁移、工具命令必须在 `alkaid-backend` 容器内执行。

**现状实现概览**：

- 抽样文件（中间件、低代码控制器、Lowcode Service/Repository 等）基本均以严格类型声明开头，并遵循 PSR-12 格式；
- 项目根目录存在 `.php-cs-fixer.php` 配置，规则中包含 `@PSR12`、`array_syntax`、`no_unused_imports`、`single_quote` 等；
- 测试目录包含针对认证、权限、多租户和低代码能力的特性测试/集成测试（基于前期阅读与 docs 中的测试策略描述），但尚未形成针对所有高风险路径（如 RateLimit、低代码动态表 DDL 失败回滚等）的全面覆盖。

**主要符合点**：

- 核心新代码整体实现了 PSR-12 + 严格类型声明的要求，命名与注释风格统一；
- 存在较为系统的测试策略文档与部分自动化测试用例，覆盖了认证/授权与低代码基础链路；
- Docker 与测试命令规范明确要求所有 CLI 操作在 `alkaid-backend` 容器内执行，降低了环境偏差风险。

**主要偏差 / 改进空间**：

- 历史 Legacy 代码与少量基础设施类未完全采用严格类型声明与统一风格，但目前已被标注为兼容路径，对主功能影响有限；
- **TEST-01：高风险路径的测试覆盖度有待加强（Info，测试）**  
  - 尤其是 RateLimit 限流逻辑、多租户低代码迁移异常场景（如部分表已存在字段/索引）、权限码变更的向后兼容性等，建议增加针对性测试用例；
  - 当前并未观察到明显功能缺陷，但从工程稳健性角度看，仍有补充分空间。

**小结**：

- 代码规范整体良好，新代码质量与风格统一；测试基础已经建立，但在若干高风险路径上仍需通过专门用例提升置信度。

---

## 4. 问题分类统计

### 4.1 按严重度统计

基于前述各模块审查，本次识别出的问题按严重度统计如下：

| 严重度 | 数量 | 说明 |
| ------ | ---- | ---- |
| Blocker | 0 | 未发现必须立即阻断上线的致命架构或安全缺陷 |
| Major   | 3 | SEC-01、OBS-01、LOWCODE-01（在多租户演进背景下视为重要问题） |
| Minor   | 4 | OBS-02、PERM-01、MT-01、LEGACY-01 |
| Info    | 2 | RAWSQL-01、TEST-01 |

> 注：LOWCODE-01 更偏向 Phase 2 目标未完全落地，但考虑到其对多租户差异化模型的长期影响，将其按 Major 重要程度纳入统计，以便在中期规划中优先处理。

### 4.2 按类型统计

| 类型 | 问题 ID | 数量 | 说明 |
| ---- | ------- | ---- | ---- |
| 安全 / 认证 / 权限 | SEC-01、PERM-01 | 2 | 错误码矩阵与权限码格式的一致性问题 |
| 可观测性 | OBS-01、OBS-02 | 2 | trace_id 注入与日志租户维度缺失 |
| 多租户 / 数据模型 | MT-01、RAWSQL-01 | 2 | 索引设计与原生 SQL 防护层面的改进空间 |
| 低代码框架 | LOWCODE-01 | 1 | Collection 元数据与缓存的多租户租户化尚未完成 |
| 架构 / Legacy | LEGACY-01 | 1 | Legacy 体系仍在代码库中，缺少自动化护栏 |
| 测试与规范 | TEST-01 | 1 | 高风险路径测试覆盖不足 |

### 4.3 问题清单总览

以下为本次审查识别的主要问题摘要（详见前文对应小节）：

1. **SEC-01（Major）— 认证错误码与错误码矩阵不一致**  
   - 范畴：安全 / API；
   - 摘要：Token 过期场景使用 `code=2002`，与文档中“2002 为权限不足（Permission 失败）”的定义冲突；
   - 影响：前后端难以仅凭错误码准确区分“未登录/登录失效”与“权限不足”，降低可维护性；
   - 建议：统一将所有认证失败场景规范为 `2001`（或在文档中明确定义新的认证子错误码），并保证 Permission 失败仅使用 `2002`。

2. **OBS-01（Major）— trace_id 注入接口不匹配**  
   - 范畴：可观测性 / API；
   - 摘要：Trace 中间件使用 `setTraceId()` 注入，而 Auth/Permission/RateLimit 等中间件通过 `getTraceId()` 获取，自定义 Request 未实现该方法，导致错误 JSON 中的 `trace_id` 可能缺失；
   - 建议：在 Request 中补充 `getTraceId()` 或统一改造所有中间件使用 `traceId()` 访问，并为错误响应增加自动附带 trace_id 的集成测试。

3. **OBS-02（Minor）— 日志中缺失租户维度**  
   - 范畴：可观测性 / 多租户；
   - 摘要：日志记录函数尝试通过 `getTenantId()` 获取租户 ID，而 Request 实际提供的是 `tenantId()`，导致 `tenant_id` 在日志中可能长期为 null；
   - 建议：统一改为调用 `tenantId()` 或增加 `getTenantId()` 适配方法，确保所有关键日志均携带 `tenant_id`。

4. **PERM-01（Minor）— 路由中权限码格式混用**  
   - 范畴：权限 / API；
   - 摘要：部分路由使用 `resource:action`，部分使用 `resource.action`，虽运行无误但与“内部 slug、对外 code”的文档描述存在轻微偏差；
   - 建议：在文档与代码中统一推荐写法（例如：路由中统一使用 `resource.action`），并通过静态检查或代码审查保持一致。

5. **MT-01（Minor）— 动态表索引未覆盖 site 维度**  
   - 范畴：多租户 / 数据模型；
   - 摘要：动态表默认索引为 `idx_tenant_id_id(tenant_id, id)`，对 `tenant_id + site_id` 为主要过滤条件的查询支持有限；
   - 建议：基于实际查询模式评估是否补充 `tenant_id + site_id` 相关索引（如 `idx_tenant_site(tenant_id, site_id)`）。

6. **LOWCODE-01（Major）— Collection 元数据与缓存的多租户支持不完整**  
   - 范畴：低代码 / 多租户；
   - 摘要：`CollectionManager::get()` 与缓存逻辑仍以 `tenant_id=0` 为默认维度，部分控制器（如 `RelationshipController`）未显式传入租户 ID，多租户元数据仍处于“系统模板”阶段；
   - 建议：在中期规划中完成 Collection 元数据与缓存的租户化改造，并统一所有调用方的租户参数传递。

7. **LEGACY-01（Minor）— Legacy 低代码体系仍在代码库中**  
   - 范畴：架构 / Legacy；
   - 摘要：Legacy Collection/Field/Manager 仍以 `@deprecated` 形式保留，缺少自动化机制防止新业务继续依赖；
   - 建议：在 CI 或代码审查中增加检查规则，禁止新代码依赖 Legacy 命名空间，并规划单独批次清理 Legacy 代码。

8. **RAWSQL-01（Info）— 原生 SQL 缺乏统一租户防护层**  
   - 范畴：多租户 / 风险；
   - 摘要：BaseModel 为 ORM 查询自动注入租户范围，但原生 SQL/Query 仍依赖开发规范保证携带 `tenant_id`/`site_id` 条件；
   - 建议：通过静态分析、代码审查或统一 Repository 封装减少直接使用原生 SQL 的必要性，并为关键路径增加防御性检查。

9. **TEST-01（Info）— 高风险路径测试覆盖不足**  
   - 范畴：测试 / 规范；
   - 摘要：尚未看到针对 RateLimit、多租户迁移异常场景、权限码演进向后兼容性的系统化测试；
   - 建议：在后续迭代中为这些路径补充特性测试或集成测试，提高回归时的置信度。

---

## 5. 改进建议

### 5.1 短期修复建议（1–2 周内可完成）

1. **统一认证错误码与错误码矩阵（SEC-01）**  
   - 调整 `app/middleware/Auth.php` 中 Token 过期/撤销等分支的错误码，使所有认证失败统一使用 `code=2001`，或在文档中增加清晰、互不冲突的子错误码定义；
   - 同步更新 `docs/technical-specs/security/security-guidelines*.md` 中的错误码矩阵，保持文档与实现一致。

2. **修复 trace_id 注入接口不匹配问题（OBS-01）**  
   - 在 `app/Request.php` 中增加 `getTraceId()` 方法，内部复用现有 `traceId()` 实现，或统一改造所有中间件使用 `traceId()`；
   - 为 Auth/Permission/RateLimit 中间件增加集成测试，验证所有错误响应体均包含正确的 `trace_id` 字段。

3. **修复日志中租户维度缺失问题（OBS-02）**  
   - 将 Auth/Permission 中的日志记录逻辑统一改为通过 `$request->tenantId()` 获取租户 ID，避免依赖不存在的 `getTenantId()`；
   - 抽样检查其他日志记录点，确保关键操作日志均携带 `trace_id`、`tenant_id`（及必要时的 `site_id`）。

4. **统一路由中权限码写法（PERM-01）**  
   - 在文档中明确推荐：路由中间件使用内部 slug（`resource.action`），PermissionService 负责转换并对外暴露 `resource:action`；
   - 对现有路由进行一次性小范围重构，实现写法统一，并补充少量回归测试验证权限判定行为未改变。

5. **补充关键安全链路测试（TEST-01，部分覆盖）**  
   - 为以下场景添加/完善自动化测试：
     - 认证失败（token 缺失/无效/过期/撤销）与权限不足的错误码与 HTTP 状态码组合；
     - RateLimit 命中时的响应结构（含 `trace_id` 和限流信息）；
     - `/v1/auth/me` / `/v1/auth/codes` 返回的权限码格式（`resource:action`）。

### 5.2 中期优化建议（约 1 个月内）

1. **低代码 Collection 元数据与缓存的全面租户化（LOWCODE-01）**  
   - 设计并落地 Collection 元数据的多租户模型：
     - 明确系统模板租户 0 与真实租户之间的关系（复制/继承/覆盖策略）；
     - 为 Domain Collection 增加显式的 `tenant_id`/`site_id` 属性；
   - 调整 `CollectionManager::get()`、`cache()`、`clearCache()` 等方法，以 `tenant_id` 为一等维度构建缓存键与查询条件；
   - 统一所有调用方（包括 `RelationshipController`）显式传入租户 ID，并通过迁移/脚本完成历史数据的平滑迁移。

2. **评估并补充多租户索引设计（MT-01）**  
   - 基于实际生产查询模式（尤其是 `tenant_id + site_id` 维度的列表/统计）评估是否需要新增 `idx_tenant_site(tenant_id, site_id)` 等索引；
   - 为新增索引编写可回滚友好的迁移脚本，并在测试环境验证对典型查询的性能收益。

3. **构建原生 SQL 的防护与治理机制（RAWSQL-01）**  
   - 通过静态分析脚本扫描项目中所有 `Db::table()` / 原生 SQL 使用点，标记未显式带 `tenant_id`/`site_id` 条件的潜在风险；
   - 考虑引入统一的 Repository 或 Query Builder 封装，要求所有写操作与高风险读操作通过该封装，以便集中注入租户范围与审计逻辑。

4. **Legacy 体系收口与退出计划（LEGACY-01）**  
   - 在 CI 中增加简单的静态检查规则：禁止新代码引入 `Domain\\Model\\Collection` 等 Legacy 命名空间；
   - 针对 Legacy 代码规划一到两个专门的“清理批次”，将仍在使用的路径迁移到 Lowcode 新体系，并在迁移完成后删除 Legacy 实现。

5. **加强高风险路径测试（TEST-01）**  
   - 为 RateLimit、多租户迁移脚本异常路径、权限码/角色变更等场景补充特性测试与集成测试；
   - 将这些测试纳入 CI 必跑集，确保未来重构与性能优化不会破坏关键安全/多租户约束。

### 5.3 长期重构建议（Phase 2 规划）

1. **完成低代码四层架构与工作流能力落地**  
   - 按 `design/09-lowcode-framework/4x-5x` 系列文档规划，逐步实现 Lowcode Foundation、Plugins、Application 与 Workflow 引擎；
   - 通过 Schema-first 工作流，将表结构、表单、权限、工作流等统一由低代码 Schema 驱动，并产出后端/前端 SDK。

2. **多租户 + 多站点的深入架构演进**  
   - 评估在高租户规模场景下采用更强的物理隔离策略（如按租户/租户组分库分表）；
   - 完善多站点（`site_id`）在日志、监控、限流与权限体系中的一等公民地位。

3. **权限体系向 Casbin 多租户 RBAC 全面过渡**  
   - 在现有 DB RBAC 的基础上，逐步将高复杂度场景迁移到 Casbin，并在设计上统一“策略存储 + 决策引擎 + 审计日志”的闭环；
   - 完善 `/v1/auth/codes` 等接口，使其能够在不暴露内部实现细节的前提下为前端提供足够丰富的权限信息。

4. **可观测性与安全基线提升**  
   - 完成 trace_id、tenant_id、site_id 在日志、指标与追踪中的统一规范化；
   - 引入更严格的安全基线检查（依赖漏洞扫描、配置合规检查等），并与现有测试/部署流水线集成。

---

## 6. 附录

### 6.1 审查覆盖的设计文档清单（节选）

- **总体与架构**：
  - `design/00-core-planning/01-alkaid-system-overview.md`
  - `design/01-architecture-design/02-architecture-design.md`
  - `design/01-architecture-design/04-multi-tenant-design.md`
  - `design/01-architecture-design/06-application-plugin-system-design.md`
  - `design/01-architecture-design/08-low-code-design.md`
- **数据与多租户**：
  - `design/03-data-layer/09-database-design.md`
  - `design/03-data-layer/12-multi-tenant-data-model-spec.md`
  - `design/03-data-layer/11-database-evolution-and-migration-strategy.md`
- **安全与可观测性**：
  - `design/04-security-performance/11-security-design.md`
  - `design/04-security-performance/13-monitoring-logging.md`
  - `design/06-ratelimit/ratelimit-strategy.md`
- **低代码框架**：
  - `design/09-lowcode-framework/40-lowcode-framework-architecture.md`
  - `design/09-lowcode-framework/41-lowcode-overview.md`
  - `design/09-lowcode-framework/42-lowcode-data-modeling.md`
  - `design/09-lowcode-framework/51-form-collection-workflow-end-to-end.md`
- **测试与流程**：
  - `design/05-deployment-testing/15-testing-strategy.md`
  - `design/05-deployment-testing/16-development-workflow.md`

### 6.2 审查覆盖的代码模块清单（节选）

- **中间件与 Request**：
  - `app/middleware.php`
  - `app/middleware/Trace.php`
  - `app/middleware/TenantIdentify.php`
  - `app/middleware/SiteIdentify.php`
  - `app/middleware/Auth.php`
  - `app/middleware/Permission.php`
  - `app/middleware/RateLimit.php`
  - `app/Request.php`
- **认证与权限**：
  - `infrastructure/Auth/JwtService.php`
  - `infrastructure/Permission/Service/PermissionService.php`
  - `database/seeds/CorePlatformSeed.php`
- **低代码相关**：
  - `app/controller/lowcode/CollectionController.php`
  - `app/controller/lowcode/RelationshipController.php`
  - `infrastructure/Lowcode/Collection/Service/CollectionManager.php`
  - `infrastructure/Lowcode/Collection/Repository/*`
  - `Domain/Lowcode/Collection/Model/*`
  - 相关迁移脚本（如 `database/migrations/20251119*lowcode_collections*.php` 等）
- **配置与规范**：
  - `.php-cs-fixer.php`
  - `docs/technical-specs/api/api-specification*.md`
  - `docs/technical-specs/security/security-guidelines*.md`
  - `docs/technical-specs/lowcode/lowcode-guidelines*.md`
  - `docs/technical-specs/database/database-guidelines*.md`

### 6.3 参考的 Serena 记忆与 Always 规则

- 权限码统一方案：
  - 后端以 `permissions.slug = resource.action` 作为内部主键，API 与前端统一使用 `resource:action` 字符串权限码；
  - `/v1/auth/me` 与可选的 `/v1/auth/codes` 返回 `permissions: string[]`，供前端 Vben Access Store 直接消费；
  - 不再引入 AC_ 前缀或前端推导权限等额外编码体系。
- 多租户策略：
  - 当前采用共享库 + 行级隔离模式，表名格式 `lc_{name}`，通过 `tenant_id`/`site_id` 字段与 BaseModel 作用域实现隔离；
  - `lowcode_collections` 表历史上的全局唯一索引 `uk_name` 已被 ADR-001 标记为 Bug 并通过迁移移除，改为 `(tenant_id, name)` 唯一。
- Always Rules（摘录）：
  - 严格区分 Phase 1 与 Phase 2，不得假定未落地的 Phase 2 能力已经可用；
  - 低代码 Schema 是动态实体的单一事实来源，新业务不得再引入对 Legacy Collection 体系的新增依赖；
  - 所有租户级业务表必须包含 `tenant_id`，必要时包含 `site_id`，并在 ORM/SQL 中始终带上租户范围过滤；
  - 所有认证失败使用 HTTP 401 + `code = 2001`，权限不足使用 HTTP 403 + `code = 2002`，所有 4xx/5xx 错误响应必须带 `trace_id`。

