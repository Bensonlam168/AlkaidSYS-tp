# 多租户 & 低代码测试矩阵（Phase 1）

> 对应审计问题：TEST-01（多租户隔离测试覆盖不足）
> 相关任务：T-001/T-002/T-003/T-057/T-066/T-068/T-069/T-070

本文件用于汇总 **多租户 & 低代码** 相关的测试矩阵，说明：

- 现有覆盖点（Unit / Feature / Integration / E2E）；
- 针对 TEST-01 的增强措施（本轮 T‑070 首批实现）；
- 后续可在此基础上继续扩展测试用例，而不再依赖口头约定。

---

## 1. 现有测试覆盖概览

### 1.1 BaseModel 多租户作用域

- **实现**：`app/model/BaseModel.php`
- **现有单测**：`tests/Unit/Model/BaseModelTest.php`
  - 校验：
    - CLI 环境下默认关闭全局作用域（`areScopesEnabled`）；
    - 通过 `setScopesEnabled/resetScopesEnabled` 显式控制作用域开关；
    - `getTenantContext/getSiteContext` 在缺少 Request 上下文时行为健壮（返回 null 或 int）。

> 说明：现有单测主要覆盖“开关逻辑 + 上下文解析”，而非真实 SQL 条件注入，后续可以通过 Integration 测试进一步验证 `scopeTenant/scopeSite` 在真实模型上的行为。

### 1.2 FormDataManager 多租户隔离

- **实现**：`Infrastructure/Lowcode/FormDesigner/Service/FormDataManager.php`
- **单测**：
  - 基础用例：`tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php`
  - 多租户隔离专门用例：`tests/Unit/Lowcode/FormDesigner/FormDataManagerMultiTenantTest.php`
    - 重点场景：
      - 在同一物理表中构造不同 `tenant_id/site_id` 的数据行；
      - 验证 `save/get/delete/list` 只在当前租户/站点范围内生效；
      - 验证 `tenant_id/site_id` 由上下文注入，负载中的注入尝试会被忽略（防止越权写入）。

### 1.3 Lowcode Controller & API

- **Feature 测试**：
  - 集合 API：`tests/Feature/Lowcode/CollectionControllerTest.php`
    - 验证 Controller 不信任 query/body 中的 `tenant_id`，统一使用 Request 上下文；
    - 验证多租户下同名集合互不影响的 CRUD 行为（见 T‑001/T‑002 系列说明）。
  - 表单 API：`tests/Feature/Lowcode/FormApiTest.php`
    - 覆盖表单 Schema CRUD 与 FormData API 的基础路径；
    - 结合 FormDataManagerMultiTenantTest，可形成“服务层 + API 层”联动验证。

### 1.4 低代码 CLI 与 E2E

- **Integration**：`tests/Integration/Lowcode/LowcodeCliIntegrationTest.php`
  - 在 CLI 场景下，验证 `lowcode:*` 命令在指定 `tenant_id/site_id` 情况下的行为（含 Collection 与 Form 管理）。
- **E2E**：`tests/Integration/Lowcode/LowcodeCliE2ETest.php`
  - `testE2ECreateModelCommand`：验证 `lowcode:create-model` 可以在指定租户/站点下创建集合与元数据（标注关联 T‑057/T‑070）。
- **跨租户 E2E**：`tests/E2E/MultiTenantIsolationTest.php`
  - 验证不同租户之间的集合访问互相隔离，以及在租户切换情况下隔离性仍然成立。

---

## 2. T‑070 首批实现内容

本轮 T‑070 的目标是：

1. 将多租户 & 低代码相关测试矩阵“写清楚”，便于后续审计与扩展；
2. 对 **危险操作或高风险能力** 增加自动化“护栏测试”，避免未来引入未审查的使用路径。

### 2.1 FormDataManager 多租户隔离用例归档

- 将 `FormDataManagerMultiTenantTest` 明确纳入 TEST‑01 的测试矩阵，覆盖以下风险：
  - 跨租户读写尝试（get/update/delete）不会成功；
  - 上下文租户/站点 ID 被正确注入数据行；
  - 负载中的 `tenant_id/site_id` 注入被忽略，避免“伪造租户”写入。
- 这些用例与 `FormApiTest` 一起，形成“服务层 + HTTP API”协同验证。

> 说明：这些测试在审计报告生成之后陆续补齐，本次 T‑070 将其正式归档到测试矩阵文档中，作为 TEST‑01 的重要覆盖点之一。

### 2.2 BaseModel::withoutTenantScope 调用路径守卫

- 新增守卫测试：`tests/Legacy/WithoutTenantScopeUsageGuardTest.php`
  - 行为：
    - 递归扫描 `app/domain/infrastructure/tests/plugins` 下所有 PHP 文件；
    - 检测是否存在 `->withoutTenantScope(` 或 `::withoutTenantScope(` 的实际调用；
    - 当前基线要求：**仓库中不应存在任何直接调用**，否则测试失败并列出违规文件。
  - 目的：
    - 将 `BaseModel::withoutTenantScope()` 视为“高风险能力”，需要经过显式设计评审与白名单策略后才能在特定模块中使用；
    - 通过测试将“调用路径审计”自动化，避免后续开发在不知情的情况下滥用跨租户查询能力。

> 未来如确需在某些内部运维/报表模块中使用 `withoutTenantScope()`，可以：
> - 在设计文档中补充说明；
> - 在守卫测试中引入白名单列表，仅允许指定文件中出现调用；
> - 并为这些调用点增补专门的单测/集成测试。

### 2.3 BaseModel 多租户作用域 Integration 测试（T‑070 增量）

- **实现/测试文件**：`tests/Integration/Model/BaseModelScopeIntegrationTest.php`
- **覆盖场景**：
  - 在真实数据表 `bm_scope_test`（包含 `tenant_id/site_id`）上，验证 `BaseModel` 的全局作用域行为：
    - `testTenantAndSiteScopesFilterByContext`
      - 显式 `setScopesEnabled(true)` 时，`scopeTenant/scopeSite` 会按 `getTenantContext()/getSiteContext()` 自动注入 `WHERE tenant_id = ? AND site_id = ?` 条件；
    - `testNoFiltersWhenScopesDisabled`
      - 调用 `setScopesEnabled(false)` 后，即便存在上下文 `tenant_id/site_id`，查询也不再附加任何租户/站点过滤，所有行均可见；
    - `testScopesDisabledByDefaultInCli`
      - 在 PHPUnit/CLI 环境下，`BaseModel` 默认关闭作用域（`areScopesEnabled() === false`），确保批处理脚本不会意外带上租户条件。
  - 通过内嵌测试模型 `TestTenantScopedModel extends BaseModel` 启用 `['tenant', 'site']` 两个全局作用域，并注入伪造的租户/站点上下文，直接验证真实 SQL 查询结果，而非仅依赖单元级别的开关逻辑。

### 2.4 Form API 多租户 E2E 测试（T‑070 增量）

- **实现/测试文件**：`tests/Feature/Lowcode/FormApiMultiTenantE2ETest.php`
- **覆盖场景**：
  - `testTenantBCannotAccessTenantAFormData`
    - 租户 A 创建 Collection + Form + Data 后，租户 B 在自己的集合/表单上下文下，无法通过 HTTP API 读取或删除 A 的数据：
      - `GET /v1/lowcode/forms/{name}/data/{id}` 返回 404；
      - `DELETE /v1/lowcode/forms/{name}/data/{id}` 返回 404；
    - 租户 B 尝试 `PUT` 更新该 ID 时，请求不会影响租户 A 的记录，E2E 用例通过再次以租户 A 身份读取数据，确认字段仍为原始值；
  - `testSameFormNameIsolatedBetweenTenants`
    - 租户 A/B 各自创建同名表单 `data_form_mt_iso`，但绑定到各自的集合（租户 B 使用独立的物理表，如 `lc_test_api_data_t2`），验证：
      - A 在自己的上下文下列表数据只看到 `TenantA-Only`；
      - B 列表中只看到 `TenantB-Only`；
      - 同名 Form 在不同租户间完全隔离，不受对方数据影响；
  - `testInjectedTenantIdInPayloadIsIgnored`
    - 在请求 body 中注入伪造的 `tenant_id/site_id` 字段，通过直接查询底层动态表 `lc_test_api_data` 验证：
      - 实际写入行中的 `tenant_id/site_id` 来自 Request 上下文，而非客户端 payload；
      - 与 `FormDataManagerMultiTenantTest` 的服务层行为保持一致，构成“服务层 + HTTP 层”双层防线。

> 说明：`FormApiMultiTenantE2ETest` 统一使用 JWT + `X-Tenant-ID` 头部构造多租户 Request 上下文，直接命中 `FormDataController`/`FormSchemaController` 路由，属于完整 HTTP 级别的端到端验证。

---

## 3. 后续扩展建议（非本轮范围）

以下内容建议在后续 T‑070 增量或新的 Task 中继续推进：

1. **BaseModel 全局作用域集成测试的扩展**：
   - 首批典型场景已由 `BaseModelScopeIntegrationTest` 覆盖（见 2.3）；
   - 后续可在此基础上增加：软删除、多表关联查询、复杂 where 条件等更贴近业务的使用场景。

2. **FormDataManager + API 的跨租户 E2E 场景扩展**：
   - 基本跨租户隔离场景已由 `FormApiMultiTenantE2ETest` 覆盖（见 2.4）；
   - 后续可进一步扩展：批量删除、复杂筛选（分页/排序）以及与权限/限流中间件的联动测试。

3. **低代码 CLI & FormDesigner 复杂表单场景**：
   - 在现有 CLI E2E 的基础上，增加包含多字段、关联关系与复杂校验规则的表单；
   - 验证在不同租户/站点下 CLI 命令与 HTTP 操作的一致性与隔离性。

4. **与 RAWSQL‑01 的联动**：
   - 对涉及低代码动态表的原生 SQL 使用点，引入与 `rawsql-safety-checklist.md` 协同的测试/审计策略（如 smoke 测试 + 静态扫描）。

本文件会随着后续多租户与低代码相关任务（包括新的审计发现）不断更新，用于持续记录测试矩阵的演进情况。

