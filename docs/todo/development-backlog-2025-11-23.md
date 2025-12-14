# AlkaidSYS-tp 开发 TODO Backlog

**生成日期**: 2025-11-23
**基于文档**:
- `docs/todo/archive/refactoring-plan.md`
- `docs/todo/archive/code-review-issues-2025-11-23.md`
- `docs/report/backend-implementation-vs-design-analysis.md` (v1.0 + v2)

**复核方法**: 三份文档逐条对照当前代码、配置与测试结果（通过 `view`/`codebase-retrieval` 完成验证），每条任务均给出来源与代码层证据。

---

## 1. 优先级分类总览

| 优先级 | 数量 | 说明 |
|--------|------|------|
| P0 - 阻塞性问题 | 3 | 影响核心业务或前后端集成，需立即处理 |
| P1 - 高优先级 | 7 | 影响重要功能、安全或基础设施稳定性 |
| P2 - 中优先级 | 5 | 功能增强、架构优化和技术债务 |
| P3 - 低优先级 | 3 | 优化与长期规划，可排在后续迭代 |

---

## 2. P0 - 阻塞性问题（按模块分组）

- [ ] **[P0] 实现工作流引擎（Workflow Engine）**
  - 来源: backend-implementation-vs-design-analysis §3.1.1
  - 当前状态: 设计完备，代码完全缺失（0%）。
  - 证据: 报告确认仓库中无 `WorkflowEngine`/`NodeModel` 等类；`design/09-lowcode-framework/47-workflow-backend-engine.md` 仅有设计。
  - 预估工时: 5 周
  - 实施建议: 按设计文档分阶段落地节点模型、执行引擎、触发器与持久化表结构，优先实现最小可用节点集。

- [ ] **[P0] 实现插件系统（Plugin System）**
  - 来源: backend-implementation-vs-design-analysis §3.1.2
  - 当前状态: 仅有设计，无 `CorePluginBaseService`/PluginManager 等实现，`addons/plugins` 目录不存在。
  - 证据: 报告与代码检索均未找到插件基类、钩子管理器及插件目录。
  - 预估工时: 9 周
  - 实施建议: 先实现钩子系统与插件生命周期管理，再扩展 iframe / 组件加载模式。

- [ ] **[P0] 重写技术规范文档（统一为标准开发规范）**
  - 来源: design-implementation-gap-analysis-2025-11-23 §4–§6；现有 `docs/technical-specs/*` 文档审查结果
  - 当前状态: API 规范、安全规范、代码规范等技术文档仍混杂“设计目标 vs 当前实现”的对比性描述，口吻不统一，且部分规范未区分 Phase 1 / Phase 2 实施阶段；不适合作为日常开发直接遵循的**权威统一规范**。
  - 证据: `docs/technical-specs/api/api-specification*.md` 与 `security/security-guidelines*.md` 中存在大量 "Design vs implementation"、"当前实现" 等措辞；部分条目仅在分析报告而非规范文档中体现。
  - 预估工时: 3–5 天
  - 实施建议: 以 `design/03-data-layer/10-api-design.md` 与 `design/04-security-performance/11-security-design.md` 为上游权威，系统性重写 `docs/technical-specs/` 下所有规范文档，将其统一为“开发必须遵循的标准规范”：
    - 所有条目使用规范化口吻（"必须/应当/可以"），不再使用“当前 vs 设计”对比表达；
    - 对分阶段实施的能力明确标注 `Phase 1`（当前阶段约定）与 `Phase 2`（目标态约定），并给出升级路径说明；
    - 将 Casbin 授权引擎、令牌桶限流、签名中间件等目标能力纳入 Phase 2 规范条目；
    - 将“实现进度/技术债/偏差分析”内容移入 Backlog 与报告文件，仅保留在规范中引用 Phase 约定与目标行为；
    - 保证中英文版本内容与结构一一对应，可作为代码评审与新成员入项培训的唯一规范来源。
- [ ] **[P0] Casbin 授权引擎实施（Phase 2 目标架构）**
  - 来源: `design/04-security-performance/11-security-design.md` §3（授权安全）；design-implementation-gap-analysis-2025-11-23 §3.2, §5
  - 当前状态: 设计文档以 PHP-Casbin 作为授权引擎目标架构；现阶段后端实际采用基于数据库直查的 RBAC 实现，Permission 中间件尚未接入 Casbin，且缺少清晰的 Phase 规划与实施任务。
  - 证据: 安全设计文档中的架构图与 Casbin 描述；`app/middleware/Permission.php` 只依赖 `permissions`/`role_permissions` 表；无 Casbin 相关配置与初始化代码。
  - 预估工时: 3–4 周（不含业务规则迁移），可按 PoC → 核心资源接入 → 全量接入分阶段推进
  - 实施建议:
    - Phase 1：在不改变现有 DB RBAC 行为的前提下，引入 `PermissionService` 抽象层，统一权限判定接口（基于 `resource:action` 权限码）；
    - Phase 2：在 `PermissionService` 内部接入 Casbin Policy 与 Model，将 DB 表中的角色/权限关系映射为 Casbin 策略；
    - 为 Casbin 引入独立配置（model/policy 存储、命名空间、多租户策略），并编写完整单元测试与集成测试；
    - 完成后在设计文档与技术规范中将 Casbin 标记为“已落地的目标架构”，DB 直查实现退化为历史兼容路径（可按模块逐步淘汰）。

- [ ] **[P0] 令牌桶限流算法实施（Phase 2 目标算法）**
  - 来源: `design/03-data-layer/10-api-design.md` §5（安全防护/限流）、`design/04-security-performance/11-security-design.md` 限流章节；design-implementation-gap-analysis-2025-11-23 §3.3, §5
  - 当前状态: 设计文档将基于 Redis 的令牌桶算法作为 API 限流的目标实现；当前中间件 `app/middleware/RateLimit.php` 实际使用的是固定时间窗口计数，虽能满足目前 B 端/管理后台业务压力，但与目标算法存在明显偏差。
  - 证据: 限流设计章节中关于 Token Bucket 的描述与示意；`RateLimit` 中间件源码中使用 `incr` + 过期时间实现窗口计数；无 Token Bucket 算法实现或相关配置。
  - 预估工时: 2–3 周（含设计评审、PoC、压测与灰度发布），与 Nginx 网关/网关层限流方案需要协同规划
  - 实施建议:
    - Phase 1：保持现有固定窗口实现不变，仅在技术规范中明确其为“Phase 1 过渡算法”；
    - Phase 2：基于 Redis 实现可配置的令牌桶算法（支持通用与路由级策略），优先评估在网关层/边缘层落地，再决定是否保留应用层限流；
    - 为新算法补充压测脚本与容量规划文档，验证在高 QPS 和突发流量场景下的表现；
    - 与运维协同设计限流告警与可观测性指标，确保切换过程中有完整监控与回滚策略。




- [x] **[P0] [Backend] 前后端权限集成 - 后端部分（PermissionService + /v1/auth/me + /v1/auth/codes）** ✅ 已完成
  - 来源: vben-permission-integration-decision §3.1–3.3, §5–§6；`docs/todo/vben-backend-integration-plan.md` 第三、七部分
  - 完成状态:
    - ✅ 后端 `PermissionService` 已实现（`Infrastructure/Permission/Service/PermissionService.php`）
    - ✅ `/v1/auth/me` 已扩展，返回 `permissions` 字段（`resource:action` 格式）
    - ✅ `/v1/auth/codes` 已实现（`GET /v1/auth/codes`）
    - ✅ 路由已添加并修正为完整类名（`route/auth.php`）
    - ✅ 单元测试已完成（8/8 通过，133 断言）
    - ✅ 集成测试已完成（8/8 通过，139 断言）
  - 提交信息:
    - Commit: `51b23f7a0721088818d1ad4d4049874ab1da7b1c`
    - Message: `feat(auth): add permission integration for backend API`
    - Date: 2025-01-24
    - Files: 5 个（3 新增，2 修改）
    - Lines: +491, -7
    - Tests: 16/16 passed (272 assertions)
    - Review: 98/100
  - 预估工时: 1–1.5 天（已完成）

- [x] **[P0] [Frontend] 前后端权限集成 - 前端部分（getUserInfoApi + Auth Store）** ✅ 已完成（2025-11-25 代码审查确认）
  - 依赖: 后端部分已完成 ✅
  - 当前状态: 已在所有前端 apps 中落地：
    - `src/api/core/user.ts::getUserInfoApi()` 从 `/v1/auth/me` 响应中读取 `permissions: string[]` 并映射到 `UserInfo.permissions`（`resource:action` 格式）；
    - `src/store/auth.ts::authLogin()` 在登录成功后调用 `fetchUserInfo()`，并执行 `accessStore.setAccessCodes(userInfo.permissions || [])`；
    - `@vben/access` 的 `useAccess().hasAccessByCodes()` 基于 `accessStore.accessCodes` 做按钮级权限控制。
  - 测试与验证:
    - Playwright E2E 测试 `frontend/apps/web-antd/tests/e2e/common/auth.ts` 已通过真实后端 `/v1/auth/login` + `/v1/auth/me` 验证主链路；
    - 手工检查典型业务页面按钮权限显示符合预期。
  - 文档与决策对齐:
    - 与 `frontend/docs/src/guide/in-depth/access.md` 中“基于 `permissions: string[]` 写入 Access Store”一节对齐；
    - 细节与 `docs/report/alkaidsys-code-review-2025-11-25.md` 前端权限集成章节一致。

- [ ] **[P0] [Backend] 低代码 Collection 接口多租户隔离一致性整改**
  - 来源: `docs/report/alkaidsys-code-review-2025-11-25.md` §3.3 多租户隔离机制
  - 当前状态: FormData 相关接口已统一使用 `Request::tenantId()/siteId()` 并在单元测试中验证租户隔离；`CollectionController::update()` 仍从请求体 `tenant_id` 读取租户，`delete()` 调用 `CollectionManager::delete()` 时未显式传入 `tenant_id`，存在潜在越权/误删风险。
  - 实施建议:
    - 更新 `app/controller/lowcode/CollectionController::update()`：统一使用 `$request->tenantId()`，移除对请求体 `tenant_id` 的信任，保证租户来源唯一；
    - 更新 `app/controller/lowcode/CollectionController::delete()` 与 `CollectionManager::delete()`：增加并强制传入 `tenantId` 参数，在所有内部查询/删除语句中显式添加 `tenant_id = :tenantId` 条件；
    - 为 Collection 相关接口补充特性/单元测试，参考 `FormSchemaManagerTest::testTenantIsolation`，覆盖“不同租户间无法互相修改/删除集合”的场景；
    - 回归执行 `docker exec alkaid-backend php think test --testsuite=Feature`，确保现有低代码与权限相关测试全部通过。
  - 验收标准:
    - 代码中不再存在从请求体读取 `tenant_id` 并绕过 Request 上下文的路径；
    - 所有 Collection 删除/更新路径均带 `tenant_id` 过滤条件；
    - 新增测试在模拟跨租户场景下能稳定阻止越权操作。

---

## 3. P1 - 高优先级问题（按模块分组）

- [ ] **[P1] 前端多租户上下文管理与请求头集成**
  - 来源: `docs/report/alkaidsys-code-review-2025-11-25.md` §2.2 多租户上下文管理；Always Rules §6 前后端多租户集成
  - 当前状态: 前端请求拦截器统一注入 `Authorization` 与 `Accept-Language`，尚未统一注入 `X-Tenant-ID`/`X-Site-ID`，也缺少集中式 `tenantStore/useTenant()` 上下文管理；多租户隔离目前完全依赖后端 JWT 与中间件默认行为。
  - 实施建议:
    - 在前端新增 `useTenantStore`（或等价 Pinia store），统一管理 `currentTenantId/currentTenantCode` 等状态，并在登录/租户切换时更新；
    - 在 `apps/*/src/api/request.ts` 请求拦截器中，根据 `useTenantStore` 的状态注入 `X-Tenant-ID`（及未来可能的 `X-Site-ID`）请求头，与后端 `TenantIdentify`/`SiteIdentify` 中间件保持一致；
    - 在前端 UI 中提供基础的租户切换入口，并联动后端租户枚举接口更新 tenant store；
    - 为多租户切换与数据隔离添加至少一条端到端 E2E 测试（可基于 web-antd），验证同一浏览器会话在不同租户下访问低代码集合/表单数据互不泄露。
  - 验收标准:
    - 在浏览器开发者工具中可观察到所有需要多租户隔离的 API 请求均携带正确的 `X-Tenant-ID`；
    - 切换租户后，低代码集合/表单数据等接口返回的数据范围随之变化且互不泄露；
    - 相关行为在前端文档中有说明，并配套至少一条 E2E 用例。

- [ ] **[P1] 应用系统基础设施（Application System）**
  - 来源: backend-implementation-vs-design-analysis §3.2.1
  - 当前状态: 设计存在，代码中无 `BaseApplication`、`ApplicationManager`，`addons/apps` 目录不存在。
  - 证据: 报告确认应用系统实现为 0%；仓库目录树中无应用基类与管理器。
  - 预估工时: 4 周
  - 实施建议: 先实现 Application 基类和 Manager，再实现应用生命周期与基础路由/菜单框架。

- [ ] **[P1] CLI 工具系统（生产级命令族）**
  - 来源: backend-implementation-vs-design-analysis §3.2.2；refactoring-plan §4, §5
  - 当前状态: 仅有 `think` 入口与若干 `test:*` 命令，无 `alkaid lowcode:*`/`alkaid init/build/publish`。
  - 证据: `config/console.php` 仅注册测试命令；无 `LowcodeCommand` 与生成器实现。
  - 预估工时: 3 周
- [ ] **[P1] 验证错误码统一（4001 → 422）**
  - 来源: `docs/technical-specs/api/api-specification*.md` §5.2；`app/ExceptionHandle.php`；design-implementation-gap-analysis-2025-11-23 §3.1, §5
  - 当前状态: 设计规范要求请求参数验证失败统一使用业务码 `422`、HTTP 422；当前全局 `ExceptionHandle` 将 `ValidateException` 映射为 `code = 4001`、HTTP 422，而 `ApiController::validationError()` 采用 `code = 422`，导致双轨错误码并存。
  - 证据: `ExceptionHandle::render()` 中对 `ValidateException` 的映射逻辑；API 规范与安全设计文档中的错误码矩阵。
  - 预估工时: 1–2 天
  - 实施建议:
    - 在不破坏兼容性的前提下，将 `ExceptionHandle` 中对验证错误的业务码调整为 `422`，并在日志中保留对历史码 `4001` 的兼容映射说明；
    - 梳理前端与第三方集成方对 `4001` 的依赖，必要时提供短期双写/映射方案与迁移指南；
    - 更新技术规范与错误码文档，将 `422` 明确为唯一的“验证错误”业务码，并在设计文档中标注历史实现差异已收敛。

- [ ] **[P1] Trace ID JSON 覆盖与可观测性增强**
  - 来源: `docs/technical-specs/api/api-specification*.md` §6；`app/middleware/Trace.php`、`app/ExceptionHandle.php`；design-implementation-gap-analysis-2025-11-23 §3.3, §5
  - 当前状态: Trace 中间件始终在响应头中写入 `X-Trace-Id`，且通过 `ExceptionHandle` 渲染的错误 JSON 包含 `trace_id` 字段；`ApiController` 已通过统一响应 Helper 在成功与错误响应中带上 `trace_id`（详见 `TraceIdCoverageTest`），大部分中间件错误也已统一通过该路径返回，仅个别 Legacy 路径仍需在后续清理中关注。
  - 证据: `ApiController` 中统一响应方法实现；`ExceptionHandle::buildJsonResponse()` 对 `trace_id` 的处理；`tests/Feature/TraceIdCoverageTest.php` 对成功/错误响应的断言；技术规范中对 Trace ID 行为的描述。
  - 预估工时: 0.5–1 天（验证与收尾）
  - 实施建议:
    - 对现有中间件与控制器进行一次静态检索，确认不存在绕过 ApiController/ExceptionHandle 直接 `json()` 返回的新增路径；
    - 如发现新路径，统一改造为经 ApiController/ResponseHelper 输出，以保证 `trace_id` 一致；
    - 在技术规范中保留“所有错误响应必须包含 trace_id”的约定，并在新增接口评审清单中加入该检查项。

- [ ] **[P1–P2] 分页结构统一（page/page_size/total/total_pages）**
  - 来源: `design/03-data-layer/10-api-design.md` §3（统一响应格式）；`docs/technical-specs/api/api-specification*.md` §3.4；`app/controller/ApiController.php`
  - 当前状态: 设计规范与 API 文档统一规定分页数据结构为 `{ list, total, page, page_size, total_pages }`；当前实现 `ApiController::paginate()` 返回 `{ list, total, page, pageSize }`，字段命名与是否包含 `total_pages` 存在偏差。
  - 证据: 设计文档中的分页示例；`ApiController` 源码；现有前端分页组件的字段使用情况。
  - 预估工时: 1–2 天
  - 实施建议:
    - 在后端统一调整分页响应结构为 `{ list, total, page, page_size, total_pages }`，其中 `total_pages` 由 `ceil(total / page_size)` 计算；
    - 对前端分页组件和调用方进行一次性字段对齐（保留兼容层或映射以平滑迁移）；
    - 在技术规范与示例代码中只保留统一后的分页结构，避免双轨字段名长期共存。


  - 实施建议: 分三步实现低代码命令、代码生成器+模板系统、应用/插件命令，并为核心命令补充集成测试。

- [ ] **[P1] DI 容器增强（懒加载 + 自动依赖解析）**
  - 来源: backend-implementation-vs-design-analysis §2.3.1, §3.2.3
  - 当前状态: 仅有 `DependencyManager` + ServiceProvider，未实现懒加载与自动注入。
  - 证据: `infrastructure/DI/DependencyManager.php`/`domain/DI/ServiceProvider.php` 无懒加载与自动解析逻辑。
  - 预估工时: 1 周
  - 实施建议: 为服务注册惰性工厂，扩展容器解析逻辑基于类型提示自动注入依赖，并编写单测验证循环依赖与错误路径。

- [ ] **[P2] API 签名中间件实装（PoC + 生产启用）**
  - 来源: `design/03-data-layer/10-api-design.md` §10（安全请求头）；`design/04-security-performance/11-security-design.md` 签名与验签设计；`docs/technical-specs/security/security-guidelines*.md` §5.2；design-implementation-gap-analysis-2025-11-23 §3.3, §5
  - 当前状态: 设计文档与技术规范已定义签名头、签名串格式与防重放策略，但签名验证中间件尚未在主链路启用，缺少统一的密钥管理、启用范围控制与审计日志规范。
  - 证据: 签名设计章节与示例代码；路由与中间件配置中无签名中间件的系统性使用记录。
  - 预估工时: 1–2 周（视接入范围与环境差异而定）
  - 实施建议:
    - Phase 1（PoC）：为少量高安全等级接口（如管理后台敏感操作或第三方回调）接入签名验证中间件，验证签名串构造、密钥管理模式与失败路径审计；
    - Phase 2（推广）：按业务域分批扩展签名保护范围，为所有外部第三方调用接口和关键内部管理接口启用签名校验；
    - 建立签名失败与签名重放的监控与告警机制，并在安全规范中补充运维处理流程；
    - 同步更新技术规范与对外集成文档，给出完整示例（含多语言 SDK 签名代码片段）。


- [ ] **[P1] 关键技术栈与配置修正（PHP/DB/Expression/Swoole）**
  - 来源: backend-implementation-vs-design-analysis §5.2, 行752-775, 758-763, 764-775
  - 当前状态: composer 中 PHP `>=8.0.0`；`config/database.php` 中 `deploy => 0`；`composer.json` 无 `symfony/expression-language`；Swoole 连接池配置存在但未启用。
  - 证据: `composer.json` 第7行；`config/database.php` 第23行；`config/swoole.php` pool 段；报告中已注明偏差。
  - 预估工时: 1–2 天（不含环境升级）
  - 实施建议: 在确认运行环境后提升 PHP 要求；启用主从分离；通过包管理器引入 Expression Language 并封装服务；在 Swoole 启动流程中激活连接池并编写回归测试。（涉及部署与依赖变更，需额外评审。）

- [ ] **[P1] Nginx 网关接入与限流/访问日志在 stage/prod 的落地与验证**
  - 来源: refactoring-plan §5.1, §5.2；backend-implementation-vs-design-analysis §9.1；`deploy/nginx/alkaid.api.conf`
  - 当前状态: dev/local 已通过 Nginx + RateLimit + AccessLog 端到端验证，stage/prod 接入与限流/日志指标尚未正式验收。
  - 证据: refactoring-plan 第295行“当前状态（2025-11-22）”；报告第9.1 节将 Nginx 部署状态标记为 ⚠️ 需进一步确认。
  - 预估工时: 2–3 天
  - 实施建议: 在 stage/prod 接入 `alkaid.api.conf`，确保所有 API 入口统一走 Nginx，验证 `X-Rate-Limited` 与 JSON 访问日志字段完整性，并更新运维文档与验收记录。

- [ ] **[P1] BaseModel 全局作用域性能与 CLI 行为优化**
  - 来源: code-review-issues-2025-11-23.md H-003
  - 当前状态: 多处查询通过 `BaseModel::init()` 自动附加租户/站点作用域，可能在高频查询与 CLI 环境产生性能与行为风险。
  - 证据: `app/model/BaseModel.php` 第55-96行注册全局作用域；审查文档 H-003 分析。
  - 预估工时: 2 天
  - 实施建议: 优化 Request 上下文缓存，评估将部分全局作用域下沉到查询层；为 CLI 明确提供显式上下文注入或禁用策略，并通过性能测试验证优化收益。

- [ ] **[P1] 关键路径测试覆盖与数据库迁移体系补齐**
  - 来源: refactoring-plan §6.1 (T3-TEST-COVERAGE)；code-review-issues H-007, H-008
  - 当前状态: 低代码/RateLimit/AccessLog 已有测试；认证/权限/多租户中间件、异常处理、DB 迁移脚本仍缺少系统化测试与完整迁移集。
  - 证据: `tests/` 中仅部分中间件有测试；`database/migrations/` 目录缺失或不完整；审查文档 H-007/H-008 状态为未完成。
  - 预估工时: 1–2 周
  - 实施建议: 先补齐 Auth/Permission/TenantIdentify/SiteIdentify/ExceptionHandle 的 Feature & Unit Test，再基于当前真实表结构生成首批迁移文件，为核心表与低代码表建立迁移链，并在 CI 中强制执行。

---

## 4. P2 - 中优先级问题（按技术领域分组）

- [ ] **[P2] 文档与注释规范统一（含 API 文档）**
  - 来源: code-review-issues M-001, M-002, M-005；refactoring-plan §8.2
  - 当前状态: 注释中中英混用、PHPDoc 不完整、缺少正式 API 文档与部分路由说明。
  - 证据: 审查文档中给出的代码片段；`app/controller/*` 与若干 Service/Repository 缺少完整 PHPDoc。
  - 预估工时: 3–5 天（可并行）
  - 实施建议: 制定注释与 PHPDoc 规范，优先覆盖对外 API、核心服务与低代码控制器，并规划 OpenAPI/Swagger 文档生成链路。

- [ ] **[P2] 配置与部署文档 + 环境变量校验完善**
  - 来源: code-review-issues M-003, M-007, M-011, M-012；refactoring-plan §4.2, §4.3
  - 当前状态: `.env.example` 不完整；Redis/限流/Swoole 池等关键配置说明零散；缺少统一的环境变量校验机制。
  - 证据: 审查文档 M-003/M-007/M-011/M-012 描述；`config/*.php` 中大量 `env()` 调用无集中校验。
  - 预估工时: 3 天
  - 实施建议: 补齐 `.env.example` 与部署手册，对必需变量在启动期统一校验（可参考 `SessionEnvironmentGuardService`），并为 ratelimit 与连接池配置增加示例和注释。

- [ ] **[P2] 可观测性与运维监控能力增强**
  - 来源: code-review-issues M-004, M-006；backend-implementation-vs-design-analysis §5.3
  - 当前状态: AccessLog/RateLimit 已提供基础数据；认证/权限失败、数据库异常、缓存命中率与性能指标缺少系统化采集与告警。
  - 证据: 审查文档 M-004/M-006；`app/middleware/Auth.php`/`Permission.php` 日志较少；无明显慢查询与缓存监控配置。
  - 预估工时: 3–5 天
  - 实施建议: 统一结构化审计日志规范，补齐认证/授权失败日志；为慢查询、缓存命中率与核心接口响应时间建立指标与告警（可先输出到日志，再接入 APM）。

- [ ] **[P2] 代码与架构规范化（魔法数字、DI 使用、格式化）**
  - 来源: code-review-issues M-008, M-009, M-010, L-001, L-002, L-004
  - 当前状态: 多处硬编码租户/站点默认值、业务错误码与 HTTP 状态码；DI 使用方式不统一；缺少统一格式化配置；部分数组处理与重复代码可优化。
  - 证据: 审查文档中示例代码；`app/controller/*`、部分 Service 存在魔法数字与 `new` 直接实例化。
  - 预估工时: 5–7 天（可按模块拆分）
  - 实施建议: 引入代码规范与自动格式化配置（PHP-CS-Fixer + .editorconfig），统一 DI 使用约定，抽取常量与公共 Helper 函数，分模块渐进重构。

- [ ] **[P2] 路由文档化与自动化校验**
  - 来源: refactoring-plan §8.2.1-8.2.3；code-review-issues 路由问题复盘
  - 当前状态: 低代码路由顺序与权限控制已修复，但路由行为依赖顺序的特点尚未系统化文档与静态校验。
  - 证据: `route/lowcode.php` 调整顺序后 FormApi 测试通过；文档中建议新增路由测试与顺序验证工具。
  - 预估工时: 2–3 天
  - 实施建议: 为关键路由添加注释与专门的路由匹配测试，并实现一个简单脚本检测常见路由顺序错误，集成入 CI。

---

## 5. P3 - 低优先级问题（按类型分组）

- [ ] **[P3] 测试遗留小问题清理**
  - 来源: refactoring-plan §8.1.1-8.1.2；code-review-issues 遗留问题列表
  - 当前状态: 存在 PHPUnit `setAccessible()` 弃用警告；部分 Event 测试因 DB 连接被 skip。
  - 证据: `tests/Feature/Lowcode/FormApiTest.php` 使用弃用 API；事件测试用例带有 `markTestSkipped`。
  - 预估工时: 1–2 天
  - 实施建议: 使用 PHP 8 反射新 API 替换弃用方法，为 Event 测试准备独立测试数据库或使用内存数据库，去除 skip。

- [ ] **[P3] 错误消息国际化与多语言支持**
  - 来源: code-review-issues L-005
  - 当前状态: 多处错误消息硬编码为中/英文，未走语言包。
  - 证据: `app/middleware/Auth.php`、部分控制器中直接返回英文或中文 message。
  - 预估工时: 2 天
  - 实施建议: 启用 ThinkPHP 多语言机制，为核心错误码建立语言包，并在 API 文档中说明语言切换机制。

- [ ] **[P3] 代码现代化与局部性能微优化**
  - 来源: code-review-issues L-001, L-002, L-003
  - 当前状态: 仍大量使用 PHP 7 风格代码；部分数组操作与复杂业务逻辑缺少说明和微优化。
  - 证据: 若干 Repository/Service 使用传统循环与手写 hydrate；复杂逻辑文件注释较少。
  - 预估工时: 3–4 天（可穿插进行）
  - 实施建议: 在不破坏兼容的前提下逐步引入 PHP 8 特性（match/nullsafe 等），优化热点数组操作，并为复杂算法补充解释性注释。

---

## 6. 已完成但文档需同步归档的条目

- [X] **统一 API 响应规范与认证/多租户契约（T0-API-UNIFY/T0-AUTH-SPEC/T0-MT-CONTEXT）**
  - 依据: refactoring-plan §3.1-3.3、§7、§9 标记为已完成；`app/controller/ApiController.php` 已统一响应结构；Auth/TenantIdentify 中间件与 Request 扩展已稳定使用。
  - 建议: 在后续设计文档与对外接口文档中引用当前实现作为基线。

- [X] **低代码路由权限控制与路由顺序问题修复（H-006 + 路由复盘）**
  - 依据: code-review-issues H-006 完成总结；`route/lowcode.php` 已恢复并强化 Permission 中间件与正确路由顺序；相关 Feature Test 通过。
  - 建议: 在 backlog 中仅保留“路由文档化与自动化校验”作为后续优化项。

- [X] **缓存与 Session 迁移到 Redis（T1-CACHE-REDIS/T1-SESSION-REDIS）**
  - 依据: refactoring-plan §4.3-4.5 当前状态说明与验收记录；配置与测试已在 dev/local 验证通过。
  - 建议: 保持运维手册与验收脚本同步更新，避免重复规划。

---

## 7. 不再适用或已被新架构/新决策替代的项

- ~~当前迭代未发现完全失效的整改项~~
  - 说明: 三份源文档中的问题在当前架构下仍然适用，暂无需要标记为“废弃”的条目；后续如有重大架构调整可在下一版 Backlog 中更新。
- ~~[P0] 实现 GET /v1/auth/codes 接口（前后端权限集成，返回 AC_* 权限码）~~
  - 说明: 已被新的权限集成最终决策替代：后端统一返回 `resource:action` 权限码，AC_ 编码方案废弃；对应能力已纳入“[P0] 前后端权限集成（PermissionService + /v1/auth/me + Auth Store）”条目中统一追踪。

---

## 8. 需进一步确认的风险项

- ⚠️ **Nginx 配置在各环境的真实启用状态**
  - 来源: backend-implementation-vs-design-analysis §9.1；refactoring-plan §5.1
  - 风险: 代码与配置骨架已就绪，但无法仅凭仓库确认 stage/prod 是否已加载 `alkaid.api.conf` 并接入真实流量。
  - 建议: 与运维协同，在各环境执行一次访问与日志、限流头验证，并记录结论。

- ⚠️ **生产环境 PHP 版本与依赖兼容性**
  - 来源: backend-implementation-vs-design-analysis §5.2
  - 风险: 设计要求 PHP 8.2+，composer 约束为 `>=8.0.0`，需确认实际运行环境是否已满足升级前提。
  - 建议: 在修改 composer PHP 约束前先盘点服务器 PHP 版本与依赖库兼容性。

---

## 9. 实施路线图建议

- **第一阶段（本周）**: 清理 P0（工作流引擎、插件系统架构设计与 PoC、前后端权限集成主链路：PermissionService + `/v1/auth/me.permissions` + Auth Store 适配）+ 启动技术栈修正评估。
- **第二阶段（本月）**: 完成 P1（应用系统、CLI、DI 增强、关键测试与迁移、Nginx/限流在 stage/prod 验收）。
- **第三阶段（下月）**: 推进 P2（文档规范、配置与监控、代码规范与路由工具），并滚动处理 P3 优化项。

## 11. refactoring-plan 迁移确认与并行开发建议

### 11.1 refactoring-plan 迁移完整性说明

- 截至 2025-11-23：`docs/todo/refactoring-plan.md` 中 T0/T1/T2/T3 任务的“未完成事项”均已通过本 Backlog 条目或“已完成但需归档”条目承接：
  - T0-API-UNIFY / T0-AUTH-SPEC / T0-MT-CONTEXT：已在第 6 节“统一 API 响应规范与认证/多租户契约（T0-API-UNIFY/T0-AUTH-SPEC/T0-MT-CONTEXT）”中标记为已完成；后续演进（如错误码/分页/Trace ID 收敛）在 P1 条目中跟踪。
  - T1-MW-ENABLE / T1-DDL-GUARD / T1-CACHE-REDIS / T1-SESSION-REDIS：其在 stage/prod 的验收工作由 P1 的“Nginx 网关接入与限流/访问日志在 stage/prod 的落地与验证”、配置与环境变量校验完善等条目统一承接。
  - T1-DOMAIN-CLEANUP：领域模型 Legacy 体系已按 T1 执行报告完成 S1–S4，并在代码中通过 `@deprecated` + 兼容层实现；后续“在下一个 major 版本中物理删除 Legacy 领域类与 CLI 命令”的规划纳入整体版本管理与 CHANGELOG 约定，无需单独拆分为 P0/P1 任务。
  - T2-NGINX-GW / T2-RATELIMIT-LOG：其 dev/local 实施已完成，阶段性工作由 P1“Nginx 网关接入与限流/访问日志在 stage/prod 的落地与验证”条目继续跟踪。
  - T3-TEST-COVERAGE 及第 8 章“测试遗留小问题”“路由文档化与顺序校验工具”等：统一承接在 P1“关键路径测试覆盖与数据库迁移体系补齐”、P2“路由文档化与自动化校验”与 P3“测试遗留小问题清理”等条目中。
- 结论：refactoring-plan.md 中不存在“仍标记为未完成、但尚未在本 Backlog 或‘已完成/归档’条目中体现”的任务；未来新增整改任务应直接写入本 Backlog，并在适当时机归档到“已完成”或“被新架构替代”小节。

### 11.2 并行开发建议清单（草案）

- [并行组 A - 规范与文档]
  - [P0] 重写技术规范文档（统一为标准开发规范）
  - [P2] 文档与注释规范统一（含 API 文档）
  - [P2] 配置与部署文档 + 环境变量校验完善
  - 说明：该组任务主要涉及文档与规范收敛，可与多数代码实现类任务并行推进，但应在关键实现类任务启动前冻结核心规范（特别是错误码/分页/Trace ID/权限模型相关条款）。

- [并行组 B - 授权与权限集成]
  - [P0] Casbin 授权引擎实施（Phase 2 目标架构）
  - [P0] [Backend][Frontend] 前后端权限集成（PermissionService + /v1/auth/me + Auth Store）
  - [P1] 关键路径测试覆盖与数据库迁移体系补齐（针对 Auth/Permission/多租户中间件的测试部分）
  - 依赖关系：
    - Casbin 授权引擎实施依赖于 PermissionService 抽象与 `/v1/auth/me.permissions` 接口的稳定（即前后端权限集成主链路至少完成 Backend P0 部分）。
    - 授权相关测试用例的完善可与权限集成开发并行，但集成测试验收需在权限主链路基本稳定后进行。

- [并行组 C - 限流与网关]
  - [P0] 令牌桶限流算法实施（Phase 2 目标算法）
  - [P1] Nginx 网关接入与限流/访问日志在 stage/prod 的落地与验证
  - [P2] 可观测性与运维监控能力增强（限流与访问日志指标部分）
  - 依赖关系：
    - Nginx 网关在 dev/local 的 PoC 已完成，可在 stage/prod 环境与令牌桶 PoC 并行推进，但令牌桶生产启用前需完成网关能力与监控指标的设计评审。

- [并行组 D - 内部基础设施与代码质量]
  - [P1] CLI 工具系统（生产级命令族）
  - [P1] DI 容器增强（懒加载 + 自动依赖解析）
  - [P1] BaseModel 全局作用域性能与 CLI 行为优化
  - [P2] 代码与架构规范化（魔法数字、DI 使用、格式化）
  - [P3] 代码现代化与局部性能微优化
  - 说明：该组任务主要影响内部开发体验与运行时性能，对业务功能的直接影响有限，可在不阻塞 P0/P1 业务链路的前提下按资源情况穿插执行。

- 必须串行的关键任务链（示例）：
  - 技术规范文档重写（P0） → 验证错误码统一（P1）/Trace ID JSON 覆盖（P1）/分页结构统一（P1–P2）：
    - 依赖：实现改造必须以冻结后的技术规范为准，避免边改边变更规范导致评审困难。
  - 前后端权限集成（P0） → Casbin 授权引擎实施（P0） → 授权相关测试与文档完善（P1）：
    - 依赖：Casbin 接入必须在 PermissionService 与 `/v1/auth/me.permissions` 稳定后进行；测试与文档更新则依赖于 Casbin 行为的稳定。
  - Nginx 网关接入与 stage/prod 验收（P1） → 令牌桶限流算法生产启用（P0 Phase 2）：
    - 依赖：令牌桶在生产环境的启用需要可观测性与回滚策略到位，应建立在 Nginx/AccessLog 已在各环境稳定运行的基础上。


---

## 10. 问题统计与来源追溯

| 来源文档 | Backlog 中引用的条目数（可重复计数） | 说明 |
|----------|--------------------------------------|------|
| refactoring-plan.md | 9 | 本 Backlog 中 9 条任务引用了该文档作为来源 |
| code-review-issues-2025-11-23.md | 11 | 本 Backlog 中 11 条任务引用了该文档作为来源 |
| backend-implementation-vs-design-analysis.md | 9 | 本 Backlog 中 9 条任务引用了该文档作为来源 |
| **合计** | **29** | **按“文档×条目”计数，单条任务可在多份文档中有来源** |

## 后端权限集成后续优化项（2025-11-25）

基于代码审查报告 `docs/report/backend-permission-integration-review-2025-11-25.md` 的修复工作，以下优化项已记录待后续版本实施：

### 1. Permission 中间件异常信息安全收敛（优先级：Medium）
- **当前状态（2025-11-27 更新）**：Permission 中间件已在内部异常时通过 `Log::error(...)` 记录详细异常与 `trace_id`，并对客户端统一返回 HTTP 500 + `code = 5000` + 通用消息 `"Internal server error"`，不再暴露 `"Permission check failed: ..."` 等内部实现细节。
- **目标**：保持与 `AuthController::handleInternalError()` 模式一致，确保后续新增的权限相关路径继续复用统一错误处理与错误码矩阵（2001/2002/5000）。
- **实施要点**：
  - 在权限相关改动的 code review 中，检查是否绕过 Permission 中间件或 ApiController/ExceptionHandle 直接返回 JSON；
  - 将当前行为在技术规范中作为“已达成基线”归档（见 `docs/technical-specs/api/api-specification*.md` 与安全规范）。
- **相关文件**：`app/middleware/Permission.php`

### 2. AuthController 依赖注入改造（优先级：Low）
- **当前状态（2025-11-27 更新）**：AuthController 已通过构造函数参数注入 `JwtService`、`UserRepository`、`PermissionService` 并调用 `parent::__construct($app)`，不再在内部手动 `new` 依赖，符合 DI 规范与测试可替换性要求。
- **目标**：将该模式作为后续控制器 DI 的标准写法，在路由与测试中统一复用。
- **实施要点**：
  - 参考 `app/controller/AuthController.php` 的构造函数签名与用法，作为控制器 DI 示例；
  - 在新增控制器时避免手动实例化权限/认证相关服务，统一通过容器注入。
- **相关文件**：`app/controller/AuthController.php`、相关测试文件

### 3. 测试执行命令规范化（优先级：Low）
- **当前状态（2025-11-27 更新）**：本地开发与 CI 均已约定在 `alkaid-backend` 容器内通过 `docker exec ...` 运行测试：
  - 推荐入口：`docker exec -it alkaid-backend php think test`（或等价的 `./vendor/bin/phpunit` 入口）；
  - 详细命令规范见 `README.local.md` 第 3 节「常用测试命令」与 `.augment/rules/always-alkaidsys-project-rules.md` 第 8 节。
- **目标**：通过后续任务 T-056 提供统一的“一键测试”入口（例如 `php think test` 或等价脚本），并在 CI 中作为唯一测试入口。
- **实施要点**：
  - 保持文档中的 `docker exec alkaid-backend ...` 测试命令与实际使用一致；
  - 在实现 T-056 时，将统一测试入口与现有命令规范对齐，并更新 testing-guidelines 文档。

### 4. PSR-12 代码格式统一（优先级：Low）
- **当前状态（2025-11-27 更新）**：项目根目录已提供 `.php-cs-fixer.php` 作为唯一格式化配置，Auth/Permission 中间件与权限相关控制器整体已符合 PSR-12，仅少量历史缩进问题可通过批量格式化工具修复。
- **目标**：在 CI 中启用基于 `.php-cs-fixer.php` 的只读格式检查（见 T-059），并在日常开发中统一通过该配置进行自动修复。
- **实施要点**：
  - 使用 `docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix --dry-run --diff` 作为本地与 CI 的格式检查命令；
  - 需要批量修复时，执行不带 `--dry-run` 的自动格式化，并在单独提交中完成历史代码格式修复。

---
**记录时间**：2025-11-25  
**关联报告**：`docs/report/backend-permission-integration-review-2025-11-25.md`  
**已完成修复**：H1（AuthController 错误处理）、M1（Permission 中间件重构）、M2（多租户隔离显式化）