# 2025-11-25 组 A（低代码 & 多租户）初步规划

## 依据文档
- Backlog: docs/todo/development-backlog-2025-11-25.md
- 聚焦任务: 组 A T-001 ~ T-004
- 全局约束: `.augment/rules/always-alkaidsys-project-rules.md` + `.augment/rules/auto-alkaidsys-guidelines.md`

## 任务关系与优先级
- T-001 (P0, PARTIAL): 低代码 Collection 接口多租户 P0
  - 目标: Controller/Service/Repository 签名统一，多租户信息一律来自 Request::tenantId / siteId，上下游调用不再从 body 读 tenant_id
  - 影响范围: CollectionManager、FormDataManager、CollectionController、相关 Service/Repository
  - 被依赖: T-002, T-003, T-020, T-004（弱依赖）
- T-002 (P1, NOT_STARTED): lowcode_collections 表租户化 + Repository 多租户过滤
  - 依赖: T-001
- T-003 (P2, NOT_STARTED): 动态业务数据表多租户隔离
  - 依赖: T-002
- T-004 (P1, NOT_STARTED): 前端多租户上下文管理与请求头集成
  - 依赖: T-001

## 初步执行顺序建议（组 A 内）
1. 完成 T-001：后端 Collection 接口多租户 P0
   - 统一 Request 多租户上下文获取方式（Request::tenantId / siteId）
   - 调整 CollectionController / Service / Repository / CollectionManager / FormDataManager 签名与调用链
   - 确保所有低代码相关查询/写入都显式带上 tenant_id（以及必要时 site_id），并符合 always 规则中的“租户隔离”要求
2. 在 T-001 稳定后，推进 T-002：lowcode_collections 表租户化 + Repository 过滤
   - 设计并实现迁移：为 lowcode_collections 新增 tenant_id 字段及唯一索引
   - 调整 Repository 层所有查询方法，增加租户过滤
3. 基于 T-002 完成后，规划并实现 T-003：动态业务数据表多租户隔离
   - 动态表 schema 设计中引入 tenant_id/site_id
   - 确保 FormDataManager 等访问路径都按租户/站点过滤
4. 平行/后续进行 T-004：前端多租户上下文与请求头集成
   - 统一 Tenant Store
   - 自动注入 X-Tenant-ID / X-Site-ID 请求头，对齐后端多租户上下文实现

## 下一步（当前会话）
- 当前聚焦阶段：完成 T-001 的深入分析与详细技术方案设计
- 需要进一步使用 codebase-retrieval / view 查找并理解以下模块：
  - CollectionManager、FormDataManager
  - 低代码 Collection 相关 Controller/Service/Repository
  - Request::tenantId / siteId 的定义与中间件实现
- 完成技术方案后，再细化为若干开发子任务，并在 Tasklist 中创建对应任务（实现 / 测试 / 审查）。