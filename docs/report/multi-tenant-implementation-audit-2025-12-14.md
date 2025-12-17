# AlkaidSYS 多租户实现专项审查报告（Multi-tenant Implementation Audit）

> 报告日期：2025-12-14  
> 审查对象：AlkaidSYS（后端 PHP / ThinkPHP，多租户 + 低代码平台）  
> 审查范围：多租户核心机制、数据访问层、低代码多租户化、API 与业务层隔离、原生 SQL 防御、多租户索引与性能、多租户测试覆盖

---

## 1. 执行摘要（Executive Summary）

- **总体结论**：AlkaidSYS 当前的多租户实现（Phase 1）在核心机制和关键路径上基本符合设计文档：
  - Request 上下文 + TenantIdentify/SiteIdentify 中间件 + BaseModel 全局作用域构成了统一的多租户上下文与行级隔离基础；
  - 低代码 `lowcode_collections` 元数据与动态表通过迁移脚本补齐了 `tenant_id/site_id` 字段及主查询索引；
  - 新版 `Infrastructure\Lowcode\Collection\Service\CollectionManager`、FormDataManager 与低代码控制器整体做到了“仅信任 Request 上下文中的 tenantId/siteId”。
- **主要亮点**：
  - 多租户上下文路径清晰：`Trace → TenantIdentify → SiteIdentify → Auth → Permission`，自定义 Request 将 `tenantId()/siteId()` 暴露为统一入口；
  - 低代码动态表创建时默认带 `tenant_id/site_id` 字段与 `idx_tenant_id_id` 索引，历史表通过迁移统一补齐；
  - 多数原生 SQL 使用点要么为 DDL（SchemaBuilder），要么在关键路径（FormDataManager）显式带上了 `tenant_id/site_id` 过滤。
- **主要问题与风险**（多租户视角，与设计契合度报告中的 MT-01 / LOWCODE-01 / RAWSQL-01 / TEST-01 对应）：
  1. **LOWCODE-01（Major，低代码多租户）**：低代码 Collection 元数据与缓存仍采用“模板租户 0 + 显式 tenantId 参数”的过渡模式，Domain Collection 尚未显式建模 `tenant_id/site_id`，`RelationshipController` 等调用方在多租户维度上存在不一致使用；
  2. **MT-01（Minor，多租户索引）**：动态表默认只提供 `idx_tenant_id_id(tenant_id, id)`，对 `tenant_id + site_id`/其他高频查询模式暂未补充复合索引，存在潜在性能风险；
  3. **RAWSQL-01（Info，原生 SQL 防御）**：项目中原生 SQL 分布较分散，目前尚未建立统一的“租户安全封装层”，多依赖人工在 `Db::table()/Db::name()/Db::query()` 上补充 `tenant_id/site_id` 过滤；
  4. **TEST-01（Info，多租户测试覆盖）**：已经存在 BaseModel 作用域、Collection 多租户隔离、低代码 CLI、多租户 E2E 等测试，但针对“跨租户访问尝试”“动态表行级隔离异常”“原生 SQL 多租户防御”的自动化测试仍不充分。
- **总体评估**：
  - 多租户基础设施 **完成度 ~90% / 契合度 ~85%**；
  - 低代码 + 多租户链路 **完成度 ~80% / 契合度 ~80%**（处于有意识的过渡阶段）；
  - 原生 SQL 防御与测试覆盖 **完成度 ~60% / 契合度 ~70%**，建议在 1–2 个月内通过封装与测试补齐。

---

## 2. 审查方法与范围（Methodology & Scope）

- **设计基线**：
  - `design/01-architecture-design/04-multi-tenant-design.md`
  - `design/03-data-layer/12-multi-tenant-data-model-spec.md`
  - `docs/technical-specs/database/database-guidelines*.md`
  - `docs/technical-specs/adr/ADR-001-multi-tenant-table-naming-strategy.md`
  - `.augment/rules/always-alkaidsys-project-rules.md` 中的多租户相关 Always 规则
  - `docs/report/design-compliance-audit-2025-12-14.md` 中多租户相关结论（MT-01 / LOWCODE-01 / RAWSQL-01 / TEST-01）
- **代码与测试抽样范围**：
  - Request 与中间件：`app/Request.php`、`app/middleware.php`、`TenantIdentify`、`SiteIdentify`；
  - ORM 与数据层：`app/model/BaseModel.php`、多租户相关迁移（特别是 `lowcode_collections` 与动态表）、`Infrastructure\Schema\SchemaBuilder`；
  - 低代码：`Infrastructure\Lowcode\Collection\Service\CollectionManager`、`Infrastructure\Lowcode\FormDesigner\Service\FormDataManager`、`app/controller/lowcode/CollectionController.php`、`RelationshipController.php`；
  - 原生 SQL：`Infrastructure\Schema\SchemaBuilder`、Legacy `Infrastructure\Collection\CollectionManager` 及低代码目录下的 `Db::table()/Db::name()` 使用；
  - 测试：`tests/Unit/Model/BaseModelTest.php`、`tests/Unit/Infrastructure/Lowcode/Collection/Repository/CollectionRepositoryTest.php`、`tests/Feature/Lowcode/CollectionControllerTest.php`、`tests/Integration/Lowcode/LowcodeCliIntegrationTest.php`、`tests/E2E/MultiTenantIsolationTest.php`。
- **Phase 边界**：
  - Phase 1：共享库 + `tenant_id` 行级隔离（必要时含 `site_id`），动态表仍使用 `lc_{name}` 命名；
  - Phase 2：表名租户隔离（例如 `lc_t{tenant_id}_{name}`）、更强的多站点/多库支持与统一多租户报表等。报告中不将纯 Phase 2 能力视为当前偏差。

---

## 3. 详细审查结果（Detailed Findings）

### 3.1 多租户核心机制（上下文、中间件、BaseModel）

- **符合点**：
  - Request 提供 `tenantId()/siteId()/setTenantId()/setSiteId()`，并在首次解析后缓存结果；
  - 全局中间件顺序为 `Trace → Cors → SessionInit → TenantIdentify → SiteIdentify → AccessLog → RateLimit`，多租户上下文在业务逻辑之前即完成注入；
  - BaseModel 的 `scopeTenant/scopeSite` 根据 Request 上下文和模型字段自动追加 `tenant_id/site_id` 条件，并可通过 `setScopesEnabled()/withoutTenantScope()` 在 CLI 或运维场景显式关闭；
  - 设计文档中对“共享库 + 行级隔离”的约定在当前实现中已基本落地。
- **问题 / 改进点**：
  - BaseModel 作用域在 CLI 环境默认关闭，依赖测试或 CLI 代码显式开启，文档已有说明但仍需在测试与 CLI 命令中保持一致使用。

### 3.2 数据访问层（ORM、多租户字段与索引、迁移）

- **符合点**：
  - 所有多租户业务表（以低代码相关为主要代表）都通过迁移显式加入 `tenant_id`，必要时含 `site_id`，字段命名遵循 `snake_case`；
  - `lowcode_collections`：
    - 初始迁移创建基础结构；后续迁移增加 `tenant_id/site_id` 与 `(tenant_id, name)` 唯一索引 `uk_tenant_name`；
    - ADR-001 决策删除 `uk_name` 全局唯一索引的 Bug，迁移 `drop_uk_name` 已落地；
  - 动态表：SchemaBuilder + 迁移 `AddTenantAndSiteToDynamicLowcodeTables` 确保所有由 Collection 驱动创建的表均包含 `tenant_id/site_id` 字段和 `idx_tenant_id_id(tenant_id, id)` 索引；
  - 迁移脚本中对历史数据统一设置 `tenant_id=0, site_id=0`，与“模板租户 0 + 默认站点”策略一致。
- **MT-01（Minor，多租户索引）**：
  - 动态表目前只提供 `idx_tenant_id_id`，对 `tenant_id + site_id` 组合或按 `site_id` 维度的统计/过滤尚无显式索引支持；
  - 设计文档建议为常用访问路径（如 `tenant_id, site_id`）增加复合索引，当前实现略为保守，可能在高并发场景下暴露性能瓶颈。

### 3.3 低代码多租户化（元数据、缓存、动态表访问）

- **符合点**：
  - 新版 `Infrastructure\Lowcode\Collection\Service\CollectionManager`：
    - 所有读写接口均显式接收 `tenantId` 参数（`get(string $name, ?int $tenantId)`, `list(int $tenantId, ...)` 等）；
    - 使用 `lowcode:collection:{tenantId}:{name}` 形式构建缓存键，实现缓存维度上的租户隔离；
    - 通过 Repository 按 `tenant_id` 过滤集合元数据，`CollectionRepositoryTest::testFindByNameIsIsolatedByTenant` 证明了元数据层面的隔离性；
  - 动态表创建时：CollectionManager 通过 SchemaBuilder 创建物理表时自动加入 `tenant_id/site_id` 字段和 `idx_tenant_id_id` 索引；
  - FormDataManager：所有对动态表的 `insert/update/get/delete/list` 操作均显式：
    - 将 `tenant_id/site_id` 注入写入数据（不信任客户端传入的字段）；
    - 查询时带上 `where('tenant_id', $tenantId)->where('site_id', $siteId)` 双重过滤；
    - 通过 `schemaManager->get($formName, $tenantId, $siteId)` + `CollectionManager->get(..., $tenantId)` 链路确保表名解析与集合元数据也在租户上下文下进行。
- **LOWCODE-01（Major，低代码元数据与缓存过渡态）**：
  - Domain Collection 实体尚未显式建模 `tenant_id/site_id` 属性，CollectionManager 在缓存与查询时仍需通过外部传入 `tenantId` 来“补全语义”；
  - `RelationshipController` 在创建关系时调用 `CollectionManager->get($collectionName)` **未传入 tenantId**，意味着关系当前仅在“模板租户 0”维度管理，与 per-tenant 元数据模型存在一定偏差；
  - 现状可以工作（模板租户 0 作为 schema 源），但与最终“每个租户独立 Collection/Relationship 元数据”的目标不完全一致，需在 Phase 2 中统一设计：
    - Domain 模型中显式增加 `tenant_id/site_id`；
    - 明确模板租户与真实租户之间的继承/覆盖策略；
    - 统一所有调用方（包括 RelationshipManager/Controller）按 Request tenantId 使用 CollectionManager。

### 3.4 API 与业务层的租户上下文处理

- **符合点**：
  - `app/controller/lowcode/CollectionController` 全部从 `Request->tenantId()/siteId()` 解析上下文：
    - `index/read/update/delete` 等接口均忽略 query/body 中的 `tenant_id` 字段，仅信任 Request 中间件注入的值；
    - `tests/Feature/Lowcode/CollectionControllerTest::testIndexIgnoresTenantIdInQuery/testReadIgnoresTenantIdInQuery` 显式验证了“忽略请求中伪造 tenant_id”这一行为；
  - FormDesigner 相关控制器（见设计与测试报告）在调用 FormDataManager 时统一传入 `$request->tenantId()/siteId()`，与上述策略一致。
- **改进点 / 风险**：
  - 个别控制器（如 `RelationshipController`）在调用 CollectionManager 时未显式传入 tenantId，导致多租户维度退化为全局/模板租户模式，应在未来重构中统一；
  - 需要在 API 文档中明确声明：
    - 所有多租户相关 API 不接受 body/query 中的 `tenant_id/site_id` 作为可信输入；
    - 多租户上下文只来自中间件与 Auth 解出的 JWT。

### 3.5 原生 SQL 与跨租户防御（RAWSQL-01）

- **符合点**：
  - 多数业务访问通过 ORM 与 BaseModel 全局作用域完成租户隔离；
  - 低代码动态表的数据操作虽使用 `Db::table()`，但 FormDataManager 中的读写均显式带上 `tenant_id/site_id` 条件，且强制注入写入字段，避免客户端伪造；
  - SchemaBuilder 使用原生 SQL 主要执行 DDL（`CREATE TABLE`/`ALTER TABLE`/`DROP TABLE`/`SHOW COLUMNS` 等），不直接涉及业务行级数据；同时通过 `assertCanRunDDL()` 严格限制环境与添加审计日志。
- **RAWSQL-01（Info，缺乏统一防御层）**：
  - 项目目前尚未提供统一的 “TenantAwareDb” 或原生 SQL 防护封装；
  - 在低代码外的其他模块中仍存在少量直接 `Db::name()/Db::query()` 调用，需要依赖人为遵守“手动添加 tenant_id 条件”的规范；
  - 当前抽样未发现明显跨租户泄露，但从长期维护角度看，缺少集中化防御层会放大人为疏漏的风险。

### 3.6 多租户隔离的测试覆盖（TEST-01）

- **已有覆盖**：
  - `BaseModelTest`：验证全局作用域开关、CLI 环境默认关闭与上下文获取的健壮性；
  - `CollectionRepositoryTest::testFindByNameIsIsolatedByTenant`：证明 `lowcode_collections` 元数据访问按 `tenant_id` 隔离；
  - `CollectionControllerTest`：验证 Controller 忽略 query/body 中的 `tenant_id`，统一使用 Request 上下文；
  - `LowcodeCliIntegrationTest`：在 CLI 场景下测试指定租户/站点 ID 的 Collection 创建与 CLI 命令行为；
  - `MultiTenantIsolationTest`：端到端验证不同租户下集合访问互相隔离、租户切换后数据仍然隔离。
- **不足之处（TEST-01，Info）**：
  - 缺少针对“跨租户访问尝试”的系统性测试矩阵，例如：
    - 在动态表中构造不同租户的数据，验证通过 FormDataManager 的 `get/delete/list` 不会跨租户返回；
    - 对 BaseModel `withoutTenantScope()` 的调用路径做专门测试与审计；
  - 缺少针对原生 SQL 使用点（尤其是未来可能新增的报表或批处理逻辑）的防御性测试；
  - 低代码多租户化路线文档中规划的 T-001/T-002/T-003 测试用例尚未全部以自动化测试形式固化到仓库。

---

## 4. 问题分类统计（Issue Classification Summary）

- **Critical**：本轮多租户实现审查中未新增 Critical 级别问题。
- **Major**：
  - LOWCODE-01：低代码 Collection 元数据与缓存多租户化仍处于过渡阶段，部分调用方（如 RelationshipController）未统一使用 tenant-aware 接口。
- **Minor**：
  - MT-01：动态表索引仅覆盖 `tenant_id + id`，缺少对 `tenant_id + site_id` 等访问模式的复合索引优化。
- **Info / 风险提示**：
  - RAWSQL-01：原生 SQL 尚无统一多租户防御封装，未来新增代码若不受控，存在遗漏 `tenant_id/site_id` 条件的风险；
  - TEST-01：多租户关键路径已有一定测试覆盖，但在跨租户攻击场景、原生 SQL 调用与低代码多租户化演进方面仍需增强自动化测试。

---

## 5. 改进建议（Remediation & Roadmap）

- **短期（1–2 周内，集中处理 Major + 明显风险）**：
  1. 统一低代码调用链上的 tenantId 传递：
     - 更新 `RelationshipController`/RelationshipManager 等调用方，显式从 Request 获取 tenantId 并传入 CollectionManager；
     - 为 Domain Collection 增加最小化的 `tenant_id/site_id` 只读属性，为后续 Phase 2 打基础。
  2. 为现有原生 SQL 使用点增加代码级审查 Checklist，并在 CI 中集成简单的静态扫描（例如搜索 `Db::table(` / `Db::query(` 并人工确认是否带 `tenant_id`）。
- **中期（约 1 个月，Minor + 性能 + 测试）**：
  1. 按生产访问模式评估并为动态表补充 `idx_tenant_site(tenant_id, site_id)` 等复合索引，对高频报表查询进行基准测试；
  2. 扩展多租户测试矩阵：
     - 新增 FormDataManager 多租户隔离单测/集成测试；
     - 为 BaseModel `withoutTenantScope()` 相关路径增加安全测试；
     - 将低代码多租户改造路线中的 T-001/T-002/T-003 测试用例系统性落地。
- **长期（Phase 2，多租户/多站点深度演进）**：
  1. 按 ADR-001 评估并落地动态表“表名租户隔离”方案（优先考虑 `lc_t{tenant_id}_{name}`），降低多租户 Schema 兼容性复杂度；
  2. 设计统一的 TenantAwareDb / Repository 层，对所有原生 SQL 与高风险查询进行集中管控；
  3. 在 Domain 层完成多租户概念的全面建模（Collection/Relationship/Form 等均显式携带 `tenant_id/site_id`），并为模板租户 0 与真实租户之间的继承策略编写专门文档与测试。

---

## 6. 附录（Appendix）

- **代码文件清单（主要涉及多租户实现）**：
  - `app/Request.php`、`app/middleware.php`、`app/model/BaseModel.php`；
  - `app/controller/lowcode/CollectionController.php`、`RelationshipController.php`；
  - `Infrastructure/Lowcode/Collection/Service/CollectionManager.php`；
  - `Infrastructure/Lowcode/FormDesigner/Service/FormDataManager.php`；
  - `Infrastructure/Schema/SchemaBuilder.php`；
  - 多租户与低代码相关迁移脚本（特别是 `lowcode_collections` 与动态表相关的几个迁移）。
- **参考设计文档与历史报告**：
  - `design/01-architecture-design/04-multi-tenant-design.md`；
  - `design/03-data-layer/12-multi-tenant-data-model-spec.md`；
  - `docs/technical-specs/adr/ADR-001-multi-tenant-table-naming-strategy.md`；
  - `docs/report/design-compliance-audit-2025-12-14.md`；
  - `docs/report/t003-dynamic-table-tenant-audit.md`（作为历史状态参考，当前实现已对其部分问题完成修复）。
- **与设计契合度报告中多租户相关问题的映射**：
  - MT-01：对应本报告第 3.2 节关于动态表索引设计的分析；
  - LOWCODE-01：对应第 3.3/3.4 节关于低代码元数据与调用链的多租户过渡态；
  - RAWSQL-01：对应第 3.5 节关于原生 SQL 防御层缺失的风险提示；
  - TEST-01：对应第 3.6 节关于多租户测试覆盖不足的分析与改进建议。

