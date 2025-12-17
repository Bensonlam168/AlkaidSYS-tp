# 组 F：多租户 & 低代码专项审查执行规划（2025-12-15）

## 范围
- 对应 backlog 中组 F（T-066 ~ T-075），重点覆盖多租户 & 低代码审查新增问题：
  - LOWCODE-01（低代码多租户语义与调用链）
  - LEGACY-01 / LC-LEGACY-01（Legacy Collection 栈护栏）
  - RAWSQL-01（原生 SQL 多租户安全）
  - MT-01（动态表/索引多租户化）
  - TEST-01 / LC-TEST-01（测试矩阵不足）

## 执行顺序（优先级驱动）
1. **T-066 (P1, Major)**：统一低代码多租户语义与调用链（Domain + Service + Controller）。
   - 目标：明确 Domain Collection/Relationship 的 tenant_id/site_id 语义；所有低代码 Service/Controller 仅从 Request 上下文取租户信息；缓存键与调用链多租户语义统一。
   - 依赖：组 A 已完成的多租户基础能力（T-001/T-002/T-003）和 BaseModel 作用域（T-037）。
2. **T-067 (P1, Major)**：为 Legacy Collection 栈增加技术护栏，阻断新依赖。
   - 目标：@deprecated 注释 + 运行时告警日志 + 静态/CI 检查，避免新代码继续使用 Domain\\Model\\Collection 等旧栈。
3. **T-068 (P1, Info-High Impact)**：原生 SQL 多租户安全 Checklist + CI 静态扫描。
   - 目标：先用简单但可落地的脚本列出所有原生 SQL 使用点，建立人工/规则 Review 流程，再逐步演进到更强抽象（与 T-074 协同）。
4. **后续**（当前仅规划，不立即落地）：
   - T-069：动态表 & N:M 中间表索引增强（多租户 + 性能）；
   - T-070：多租户 & 低代码测试矩阵扩展；
   - T-071：低代码 CLI 体验与文档优化；
   - T-072 ~ T-075：Phase 2 能力（Schema 漂移检测、字段类型扩展、多租户感知 DB 抽象、表名级多租户隔离方案等）。

## 首个实现任务选择
- 综合优先级与依赖关系：
  - T-066 直接对应 LOWCODE-01（Major），且是后续索引增强（T-069）、测试矩阵（T-070）、Schema 漂移（T-072）和表名级多租户（T-075）的前置基础；
  - T-067/T-068 更偏“护栏 + 安全与流程”，可以在 T-066 完成后交错推进；
- 因此本轮迭代首个开发任务锁定为 **T-066：统一低代码多租户语义与调用链**。

## T-066 初步实施步骤
1. 设计对齐：阅读 multi-tenant / lowcode 相关设计与审查报告（multi-tenant-implementation-audit-2025-12-14.md、lowcode-implementation-audit-2025-12-14.md、design-compliance-audit-2025-12-14.md、低代码架构设计文档）。
2. 现状审计：
   - Domain\\Lowcode\\Collection/Relationship 实体的字段与语义；
   - Infrastructure\\Lowcode\\Collection\\Service\\{CollectionManager, RelationshipManager} 对 tenantId/siteId 的处理方式；
   - FormDataManager 与低代码 Controller（Collection/FormSchema/FormData/Relationship）中的多租户调用链；
   - 缓存键与动态表命名/索引策略与组 A/T-037 的一致性。
3. 目标方案精炼：
   - Domain 实体中显式 tenant_id/site_id 只读属性定义；
   - Request → Controller → Service → Repository 的 tenantId/siteId 传递规范；
   - 缓存键规范：统一采用 lowcode:{tenantId}:{name}（或文档中指定格式），避免混用模板租户 0 与外部 tenantId；
   - 与 BaseModel 全局作用域、动态表 schema 多租户化的契合性校验。
4. 实现与迁移：按最小可行步拆分改造 Domain/Service/Controller 与缓存逻辑，保持兼容性；
5. 测试与文档：补充/更新单元、Feature、Integration/E2E 测试，并更新相关技术文档与 backlog 状态。
