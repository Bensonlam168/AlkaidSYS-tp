---
type: "always_apply"
---

# AlkaidSYS 项目指南（Auto Guidelines）

> 本文件为 **Auto Guidelines**，用于在相关会话中按需自动载入，指导 AI 的实现细节与风格选择。
> 若与 `always` 规则或官方文档冲突，以 `always` 规则与文档为准。
> 当前实现与设计之间的已知偏差（SEC-01 ~ TEST-01）详见 `docs/report/design-compliance-audit-2025-12-14.md`，本指南描述的是目标规范；当代码实现与本指南不一致时，应优先视为待修复的偏差，而非以现状代码为准。

## 1. 代码风格与命名

1. **PHP 后端**
   - 遵守 `PSR-12`，新文件使用 `declare(strict_types=1);`。
   - 命名约定：
     - 类：`PascalCase`（`UserService`）
     - 方法/变量：`camelCase`（`createUser`, `$userId`）
     - 常量：`UPPER_SNAKE_CASE`
     - 表名：`snake_case` 复数（`lowcode_collections`）
     - 字段名：`snake_case`（`tenant_id`, `created_at`）
   - 对外 Service/Controller 方法推荐中英双语 PHPDoc 注释。

2. **前端 Vue + TS**
   - 使用 Vue 3 + TypeScript + Composition API。
   - 组件命名使用 `PascalCase`，文件命名在同一模块内保持风格一致。
   - 状态管理统一使用 Pinia + 持久化插件，敏感数据（token 等）不做明文持久化。
   - 推荐 `<script setup lang="ts">` + composables（`useAuth`, `useTenant` 等）。

---

## 2. API 设计与文档

1. **RESTful 设计**
   - 资源路由遵循 REST 命名：`GET /api/v1/users`、`POST /api/v1/users` 等。
   - 查询参数用于分页/过滤/排序/字段筛选（`page`, `page_size`, `sort`, `fields`, `include`）。

2. **统一响应包装**
   - 成功：HTTP 200/201 + `code = 0`，数据放在 `data` 字段。
   - 失败：HTTP 4xx/5xx + `code != 0`，错误详情按文档放在 `data` 内相应字段。
   - 推荐使用统一的控制器基类与 Helper 函数（`success`, `paginate`, `error` 等）。

3. **OpenAPI / Swagger**
   - 新增或修改 API 时，同时维护 Swagger/OpenAPI 注解。
   - 前端 TS 类型优先通过 OpenAPI 生成，而不是手写重复类型。

---

## 3. 权限与多租户集成

1. **权限码映射习惯**
   - 后端内部：`resource.action`（DB 字段 `permissions.slug`）。
   - 对前端：`resource:action`（例如 `forms:view`）。
   - 前端路由/按钮控制优先依赖统一的 `hasPermission('resource:action')` 工具方法。

2. **多租户上下文约定**
   - 所有业务查询必须考虑 `tenant_id`（以及需要时的 `site_id`）。
   - 推荐通过统一上下文对象或基础模型自动注入租户过滤，手写 SQL 时务必显式带上。

---

## 4. 低代码与 Schema 工作流

1. **Schema-first**
   - 动态实体优先通过低代码 Schema 定义集合、字段和关系。
   - 数据库表结构与索引优先通过迁移脚本自动生成，避免手写 DDL。
  - 表单校验一致性：前端获取低代码表单详情时调用 `GET /v1/lowcode/forms/{name}?with_validator=1&with_parsed=1`，使用返回的 `validator.rules/messages` 与 `parsed.components/permissions` 构建校验/渲染，前端本地规则仅作兜底，禁止与后端漂移。

2. **漂移检测与同步（规划中）**
   - 推荐工作流：
     - 修改低代码 Schema → 生成迁移 diff 报告 → 审核 → 执行迁移。
   - AI 生成迁移时应尽量保持可回滚与幂等设计。

---

## 5. 安全、限流与审计

1. **安全基线**
   - 尽量使用统一的认证/授权/限流中间件，不在业务代码内重复实现。
   - 涉及敏感操作（登录、支付、配置修改）优先考虑双因子验证、签名或防重放机制。

2. **审计与日志**
   - 推荐在关键业务路径记录操作日志，带上 `trace_id/user_id/tenant_id/ip` 等上下文。
   - 日志信息需注意脱敏，避免输出明文敏感数据。

---

## 6. 测试与质量

1. **测试优先**
   - 新增能力优先编写或补充单元测试/集成测试/特性测试。
   - 涉及权限、限流、认证、迁移等改动，推荐增加覆盖典型异常路径的测试用例。

2. **持续集成**
   - 建议在 CI 中执行：代码风格检查、静态分析、单元测试、API 文档校验等。
   - 高风险改动应在 PR 中明确列出测试范围与结果。

---

## 7. Git 与 PR 习惯

1. **分支与提交信息**
   - 分支命名参考：`feature/<scope>-<summary>`、`fix/<scope>-<summary>`。
   - 提交信息推荐采用 Conventional Commits（`feat(auth): ...` / `fix(db): ...`）。

2. **PR 粒度**
   - 优先拆分为若干小而清晰的 PR，每个 PR 聚焦一个明确主题。
   - PR 描述中简要列出：变更点、涉及规范条目、测试情况。
