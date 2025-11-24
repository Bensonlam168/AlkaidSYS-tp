# 分支保护配置指南

## 1. 范围与目标

本文档用于描述在 Git 平台（如 GitHub / GitLab）上如何配置 AlkaidSYS 项目的保护分支，使之与《Git 工作流与提交规范》保持一致。

- `main` **必须** 代表生产可用代码；
- `develop` **应当** 作为下一版本的集成分支；
- 两者 **必须** 配置为保护分支。

## 2. main / develop 保护分支规则

对 `main` 与 `develop`，建议统一采用以下规则：

1. **禁止直接推送（No direct pushes）**
   - 所有变更 **必须** 通过 Pull Request / Merge Request 合入。

2. **强制 CI 状态检查**
   - 合并前 **必须** 至少通过一条成功的 CI 流水线；
   - 推荐（非穷举）检查项：
     - 后端测试（PHPUnit）
     - 前端检查（lint + test）（如适用）
     - 静态分析 / 安全扫描（Phase 2 阶段逐步引入）。

3. **强制代码评审（Review）**
   - 合并前 **必须** 至少获得一名评审者的批准；
   - 对高风险变更（安全相关、Casbin 集成、限流、数据库迁移等） **应当** 要求 2 名及以上评审，或指定领域负责人评审。

4. **尽量保持线性历史**
   - 功能分支 / 修复分支 **建议** 使用 squash-and-merge，以一个分支对应一个逻辑提交；
   - 在平台支持的情况下，可以启用 “linear history / 禁止 merge commit” 等选项。

5. **限制可 push / merge 的角色**
   - **应当** 仅允许核心开发者 / 维护者合并到 `main` 与 `develop`。

## 3. Feature / Fix / Hotfix / Release 分支规则

- 功能分支（`feature/*`）与修复分支（`fix/*`）一般不设置为保护分支，但在 PR 层面 **应当** 启用 CI 检查；
- 热修复分支（`hotfix/*`） **应当** 视为高风险：
  - 合并到 `main` 前 **必须** CI 通过；
  - **应当** 至少由具有生产经验的开发者评审通过；
- 发布分支（`release/*`） **可以** 配置为保护分支，但规则可以略轻（例如仅要求 CI + 单人评审）。

## 4. 平台映射示例

### 4.1 GitHub 示例

对 `main` 与 `develop`：

- 勾选 **“Require a pull request before merging”**；
- 勾选 **“Require status checks to pass before merging”**，并选择需要的 CI 工作流；
- 勾选 **“Require approvals”**，最少 1 名（高风险仓库可以设置为 2 名）；
- 根据需要启用 **“Require linear history”**，以约束合并策略。

### 4.2 GitLab 示例

对 `main` 与 `develop`：

- 在项目设置中将分支标记为 **受保护分支**；
- 限制可以 push / merge 的角色；
- 通过项目设置要求合并前 **必须** 通过 CI 流水线并完成评审。

## 5. 与 Git 工作流与提交规范的关系

- 分支保护规则 **必须** 与以下文档保持一致：
  - `docs/technical-specs/git/git-workflow-guidelines.zh-CN.md`
  - 使用 commitlint 与 Git hooks 强化的提交消息规范；
- 后续 Phase 2 阶段，可以在 CI 中引入更严格的自动化检查，例如：
  - 检查分支命名是否符合 `feature|fix|hotfix|release` 约定；
  - 检查 PR 中的提交是否全部符合 Conventional Commits 与项目规则。

