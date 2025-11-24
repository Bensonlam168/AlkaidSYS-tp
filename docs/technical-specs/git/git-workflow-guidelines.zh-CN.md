# Git 工作流与提交规范

## 1. 范围与目标

本规范用于约束 AlkaidSYS 项目的 Git 分支模型、提交消息约定与合并策略，是后续 CI 规则与 PR Review Checklist 的**前置与唯一权威规范**。

- 所有贡献者 **必须** 遵守本规范进行分支管理与提交。
- 后续阶段的 CI 与代码评审规则 **将** 基于本规范中的约定进行配置与检查。
- 项目在 JavaScript/Node.js 生态中 **必须** 统一使用 `pnpm` 作为包管理器，禁止使用 `npm` / `yarn` 安装依赖。

## 2. 分支模型

### 2.1 主干分支

- `main` **必须** 代表可用于生产环境的代码。
- `develop` **应当** 用作下一版发布的集成分支。
- 对 `main` 和 `develop` 的直接提交 **禁止** 出现，所有变更 **必须** 通过功能/修复分支及 PR 合入。

### 2.2 功能分支（Feature Branch）

- 功能分支 **必须** 基于 `develop` 创建。
- 命名规范：
  - `feature/<priority>-<area>-<short-description>`
  - 示例：
    - `feature/p0-auth-permission-service`
    - `feature/p1-security-casbin-phase2`
    - `feature/p1-rate-limit-redis-token-bucket`
- 功能分支 **应当** 聚焦单一 Backlog 任务（例如 `development-backlog-2025-11-23.md` 中的某个 P0/P1 任务）。

### 2.3 修复分支（Fix Branch）

- 修复分支 **必须** 用于缺陷修复。
- 命名规范：
  - `fix/<priority>-<area>-<short-description>`
  - 示例：
    - `fix/p0-auth-refresh-token-rotation`
    - `fix/p1-db-tenant-scope-bug`

### 2.4 热修复分支（Hotfix Branch）

- 热修复分支 **必须** 以 `main` 为基础，用于紧急生产问题修复。
- 命名规范：
  - `hotfix/<version>-<short-description>`
  - 示例：`hotfix/1.2.3-rate-limit-429-misconfig`
- 合入 `main` 后，热修复分支 **必须** 同步合并回 `develop`（或当前活动的发布分支），保持分支一致性。

### 2.5 发布分支（Release Branch）

- 在需要对版本进行稳定性整理时 **可以** 使用发布分支。
- 命名规范：
  - `release/<version>`
  - 示例：`release/1.3.0`
- 发布分支上 **应当** 仅合入缺陷修复、文档更新与发布相关变更。

## 3. 提交消息规范

### 3.1 基本格式

- 项目 **建议** 采用 [Conventional Commits](https://www.conventionalcommits.org/) 风格：
  - `<type>(<scope>): <subject>`
  - 可选 body 与 footer 说明细节。
- `scope` **应当** 标明受影响的领域（如 `auth`、`api`、`db`、`lowcode`、`security`、`performance` 等）。

### 3.2 提交类型

推荐的提交类型包括：

- `feat` – 新功能；
- `fix` – 缺陷修复；
- `docs` – 仅文档变更；
- `refactor` – 不改变行为的重构；
- `test` – 新增或调整测试；
- `chore` – 构建或工具链相关变化（不影响生产代码逻辑）；
- `perf` – 性能相关优化；
- `style` – 代码格式或风格调整（不改变逻辑）。

### 3.3 示例

**正确示例：**

- `feat(auth): add PermissionService for resource:action checks`
- `fix(db): enforce tenant_id scope on invoice queries`
- `perf(rate-limit): switch to Redis token bucket algorithm`
- `docs(specs): update security guidelines for Casbin Phase 2`

**错误示例（不允许）：**

- `update`
- `fix bug`
- `change stuff`

### 3.4 关联 Backlog 与 Issue

- 在合适场景下，提交 **应当** 在 footer 中引用 Backlog 或工单，例如：
  - `Refs: P0-auth-permission-service`
  - `Closes: P1-security-casbin-phase2`
- 这些引用 **可以** 被后续工具用于生成变更日志与进度报告。

### 3.5 破坏性变更（Breaking Changes）

- 破坏性变更 **必须** 通过以下方式之一明确标注：
  - 在 header 中使用 `feat(scope)!: ...`；或
  - 在 body 中使用 `BREAKING CHANGE:` 前缀的段落。
- 描述 **必须** 清楚说明影响范围（例如删除了哪些接口、改变了哪些响应格式），并引用相关设计/规范/Backlog 条目。

示例：

- `feat(auth)!: remove legacy v1 auth endpoints`

  Body：

  - `BREAKING CHANGE: /v1/auth/login-legacy has been removed; clients must migrate to /v2/auth/login as per api-specification.md.`

## 4. 合并策略

- 所有变更 **必须** 通过 Pull Request / Merge Request 合入目标分支。
- 对于 `main` 与 `develop`，历史 **应当** 尽量保持线性：
  - 开发者 **应当** 在合并前，将分支基于目标分支进行 rebase；
  - 对功能分支与修复分支 **建议** 使用 squash-and-merge，将一个分支压缩为一个逻辑提交。
- 在发布分支场景下，如有助于追踪 **可以** 使用带合并提交的策略。

## 5. 保护分支与 CI 要求

- `main` 与 `develop` **必须** 设置为保护分支：
  - 禁止直接 push；
  - 合并前 **必须** 至少通过一条成功的 CI 流水线；
  - 合并前 **必须** 至少获得一名评审者的批准（高风险变更建议增加评审人数）。
- 对高风险变更（如安全相关、Casbin 集成、限流策略、数据库迁移等） **应当** 要求更多评审与更严格检查。

## 6. 远程仓库操作安全检查

- 在执行 `git push` 前，贡献者 **必须** 检查当前仓库的远程配置：
  - 运行 `git remote -v`，确认远程地址确认为本项目的目标仓库；
  - 严禁将代码推送到任何上游 / 第三方官方仓库（例如 `vbenjs/vue-vben-admin` 等）。
- 对于 monorepo 子项目（如 `frontend/`），在推送前 **必须** 确认其 `.git/config` 中的 remote 指向正确目标；
- 如有疑问，应将仓库视为**仅本地使用**，直至远程配置已被明确核对。

## 7. 与其他技术规范的对齐

- 涉及应用代码的提交 **必须** 满足以下规范：
  - 代码风格规范（`docs/technical-specs/code-style/general-guidelines*.md`）；
  - 安全规范（尤其是认证、RBAC/Casbin 与限流相关条款）；
  - 数据库规范（涉及任何 schema 或迁移变更时）；
  - 测试规范（功能性变更 **应当** 同步新增或更新测试用例）。
- 体量较大或跨领域的变更 **应当** 拆分为多个可审查的提交，每个提交都独立满足上述约束。

## 7. 与 Backlog 与 Phase 的关系

- 面向 P0/P1 任务的分支命名 **应当** 包含优先级与领域信息（如 `p0-auth`、`p1-security`、`p1-db` 等）。
- Casbin、Redis 令牌桶限流等 P0/P1 任务 **应当** 采用独立的功能分支开发，以保持历史清晰。
- 后续 Phase 2 中，可以通过 commitlint、分支命名校验等方式 **自动化** 检查本规范的部分约束。

