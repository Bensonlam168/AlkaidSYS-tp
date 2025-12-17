# AlkaidSYS 设计契合度审查（Serena 记忆快照）

## 元数据
- 2025-12-14 完成了一次 AlkaidSYS 设计契合度审查，报告路径为 `docs/report/design-compliance-audit-2025-12-14.md`，该报告是后续决策与重构规划的权威基线文档之一。

## 已识别偏差问题（SEC-01 ~ TEST-01）
- AlkaidSYS 当前已确认存在 9 个设计契合度偏差问题，编号为 SEC-01、OBS-01、OBS-02、PERM-01、MT-01、LOWCODE-01、LEGACY-01、RAWSQL-01 和 TEST-01，其详细定义和影响记录在设计审查报告中。
- SEC-01：Auth 中间件在 token 过期场景错误返回错误码 2002 而不是规范要求的 2001。
- OBS-01：Request 只实现 traceId()/setTraceId() 而中间件调用 getTraceId() 导致错误响应中 trace_id 可能缺失。
- OBS-02：日志中通过不存在的 getTenantId() 获取租户 ID 导致租户维度审计信息不完整。
- PERM-01：路由中 Permission 中间件参数存在 resource.action 与 resource:action 混用，需要统一写法并与权限码最终方案对齐。
- MT-01：低代码动态表默认仅有 (tenant_id, id) 索引，尚未显式覆盖 site_id 维度。
- LOWCODE-01：低代码 Collection 元数据与缓存目前仍主要基于 tenant_id=0 的系统模板租户，尚未完全多租户化。
- LEGACY-01：Legacy Collection 相关类仍存在，禁止新业务新增依赖但缺少静态检查或 CI 层面的技术护栏。
- RAWSQL-01：原生 SQL 访问缺少统一的租户防御封装，当前依赖人工遵守多租户规范。
- TEST-01：在 RateLimit、多租户迁移异常和权限兼容性等高风险路径上的自动化测试覆盖不足。
- 在 2025-12-14 的设计审查中，SEC-01、OBS-01、LOWCODE-01 被评为 Major，OBS-02、PERM-01、MT-01、LEGACY-01 被评为 Minor，而 RAWSQL-01 与 TEST-01 被评为 Info。

## 历史问题与修复状态
- lowcode_collections 表历史上存在 uk_name 全局唯一索引阻止多租户同名 Collection 的设计 Bug，但该 Bug 已在 2025-12-14 通过迁移移除 uk_name 并改为 (tenant_id, name) 唯一约束完成修复。

## 低代码多租户化当前阶段
- 低代码 Collection 元数据和缓存当前仍主要基于 tenant_id=0 的系统模板租户运作，多租户级差异化处于逐步演进的过渡阶段，对应审查问题 LOWCODE-01。

## 权限码方案与实现状态
- AlkaidSYS 权限码最终方案为内部 permissions.slug 使用 `resource.action`，外部 API 和前端统一使用 `resource:action`，这是权限设计的长期规范。
- /v1/auth/me 接口已按最终方案返回 `resource:action` 字符串数组供前端 Vben Access Store 直接消费，权限码方案在实现中已基本落地。
- /v1/auth/codes 被设计为可选的权限码瘦包装接口，其是否已经实现需要在具体会话里按代码检查确认，不应默认假设一定存在。

## 多租户策略与当前实现
- AlkaidSYS 多租户策略采用共享数据库加 tenant_id 行级隔离的模型，配合 BaseModel 注入租户作用域来保证 ORM 查询自动带上租户条件。
- 动态业务表统一使用 `lc_{name}` 命名，且包含 tenant_id（和必要时的 site_id）字段，这一多租户表名与字段策略已在当前实现中落地。
- 原生 SQL 的多租户防御目前尚未有统一封装，对应 RAWSQL-01 风险，后续需要通过封装与静态检查加强租户隔离。

## 模块完成度与契合度评分
- 设计契合度审查为架构分层与模块职责、API 设计与错误码、数据模型与多租户数据层、运行时多租户隔离、认证与权限、低代码框架以及代码规范与测试分别给出了完成度和契合度评分，详情以 `docs/report/design-compliance-audit-2025-12-14.md` 中的评分表为准。
- 审查总体结论认为 AlkaidSYS 在架构、API、数据与多租户、认证与权限、低代码和代码规范等方面与设计文档整体契合度较高，主要偏差集中在安全可观测性、低代码多租户细节、多租户索引与原生 SQL 防御以及高风险路径测试覆盖几个方面。
