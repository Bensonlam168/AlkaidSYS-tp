# AlkaidSYS-tp 开发 TODO Backlog

**生成日期**: 2025-11-23
**基于文档**:
- `docs/todo/refactoring-plan.md`
- `docs/todo/code-review-issues-2025-11-23.md`
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




- [ ] **[P0] [Backend][Frontend] 前后端权限集成（PermissionService + /v1/auth/me + Auth Store）**
  - 来源: vben-permission-integration-decision §3.1–3.3, §5–§6；`docs/todo/vben-backend-integration-plan.md` 第三、七部分
  - 当前状态: 后端 `PermissionService` 与 `/v1/auth/me.permissions`、`/v1/auth/codes` 均未实现；前端 `getUserInfoApi` 未处理 `permissions`，`Auth Store` 仍仅通过 `getAccessCodesApi` 从 mock `/auth/codes` 加载权限。
  - 证据: `route/auth.php` 仅有 login/register/refresh/me；`app/controller/AuthController.php` 无 `codes()` 且 `me()` 不返回 `permissions`；`database/seeds/CorePlatformSeed.php` 仅提供 `slug/resource/action`；`frontend/apps/web-antd/src/api/core/auth.ts` 与 `src/store/auth.ts` 存在但未按集成方案实现。
  - 预估工时: 2–3 天（后端 1–1.5 天，前端 1–1.5 天）。
  - 实施建议:
    - [Backend-P0] 实现 `PermissionService::getUserPermissions(int $userId): string[]`，基于 `user_roles`、`role_permissions`、`permissions` 计算并返回 `resource:action` 权限码数组（内部仍以 `slug = resource.action` 为主键）。
    - [Backend-P0] 扩展 `GET /v1/auth/me`，在 data 中增加 `permissions: string[]` 字段（`resource:action`），并在文档中将其标记为权限码主通道。
    - [Frontend-P0] 调整 `src/api/core/user.ts` 中的 `getUserInfoApi()` 处理 `permissions` 字段；在 `src/api/core/auth.ts` 中实现 `getAccessCodesFromMe()`；调整 `src/store/auth.ts` 登录/刷新流程，在获取用户信息后调用 `accessStore.setAccessCodes(permissions)`。
    - [Backend-P1-可选] 在 `route/auth.php` 注册 `GET /v1/auth/codes`，在 `AuthController::codes()` 中复用 `PermissionService`，返回与 `/v1/auth/me.permissions` 完全一致的 `string[]`。
    - [Test-P1] 为 `PermissionService`、`/v1/auth/me` 与（若实现）`/v1/auth/codes` 编写单元测试与集成测试，覆盖典型 RBAC 场景与 403 权限不足路径。

---

## 3. P1 - 高优先级问题（按模块分组）

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
  - 当前状态: Trace 中间件始终在响应头中写入 `X-Trace-Id`，且通过 `ExceptionHandle` 渲染的错误 JSON 包含 `trace_id` 字段；但通过 `ApiController` 直接返回的成功/部分错误响应，以及部分中间件直接 `json()` 返回的错误，目前 JSON 体中仍缺少 `trace_id`。
  - 证据: `ApiController` 中统一响应方法实现；`ExceptionHandle::buildJsonResponse()` 对 `trace_id` 的处理；技术规范中对 Trace ID 行为的描述。
  - 预估工时: 2–3 天
  - 实施建议:
    - 为 `ApiController` 增加注入 trace_id 的统一路径（例如通过 Response 装饰器或中间件后置处理），确保所有 4xx/5xx JSON 响应都包含 `trace_id`；
    - 梳理 `Auth`、`Permission` 等中间件中直接 `json()` 返回的代码路径，改造为统一经过带 trace_id 的响应构造逻辑；
    - 更新技术规范，将“所有错误响应必须包含 trace_id”提升级别为强制要求，并在回归测试中增加断言。

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

