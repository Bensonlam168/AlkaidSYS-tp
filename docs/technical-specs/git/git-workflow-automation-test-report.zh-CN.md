# Git 工作流自动化测试报告

日期：2025-11-24  
范围：后端根仓库（AlkaidSYS-tp）

## 1. 测试配置范围

1. `commitlint.config.js`
   - 基于 Conventional Commits，并结合项目定制规则：
     - 允许的类型（type）：`feat`、`fix`、`docs`、`refactor`、`test`、`chore`、`perf`、`style`。
     - 推荐的作用域（scope）：`auth`、`api`、`db`、`lowcode`、`security`、`performance`、`deployment`、`testing`、`git`。
     - `scope-empty`: `never`（必须包含 `<scope>`）。
     - `subject-empty`: `never`（必须包含 `<subject>`）。
     - `header-max-length`: `72` 字符。

2. `.git/hooks/commit-msg`
   - 在项目本地创建 `commit-msg` Git hook，用于在提交时强制执行 commitlint：
     - 使用 `npx --no-install commitlint` 或 `pnpm exec commitlint`，并显式指定 `commitlint.config.js`。
     - 如需临时禁用，可通过环境变量 `ALKAIDSYS_COMMIT_HOOK=0` 关闭。

3. 文档与规范
   - 分支保护指南：
     - `docs/technical-specs/git/branch-protection-guidelines.md`（英文）。
     - `docs/technical-specs/git/branch-protection-guidelines.zh-CN.md`（中文）。
   - PR 审查清单：
     - `docs/technical-specs/git/pr-review-checklist.md`（英文）。
     - `docs/technical-specs/git/pr-review-checklist.zh-CN.md`（中文）。

## 2. 测试命令

在后端根目录使用 `pnpm` 安装 `@commitlint/cli` 与 `@commitlint/config-conventional` 之后：

```bash
pnpm add -D @commitlint/cli @commitlint/config-conventional
```

执行了以下测试用例：

1. **合法提交信息（应通过）**

```bash
echo 'feat(git): test commitlint setup' > /tmp/commitmsg-ok.txt
npx --no-install commitlint --config commitlint.config.js --edit /tmp/commitmsg-ok.txt
# 结果：通过（ok-pass）
```

2. **非法提交信息（应失败）**

```bash
echo 'update' > /tmp/commitmsg-bad.txt
npx --no-install commitlint --config commitlint.config.js --edit /tmp/commitmsg-bad.txt
# 结果：失败，错误包含：type-empty、scope-empty、subject-empty（bad-fail）
```

## 3. 观察结论

- 前端 monorepo 最初包含独立的 commitlint + lefthook 配置与 `.commitlintrc.js`，但 `frontend/.git` 现已移除，前端代码完全由根仓库托管，不再直接关联 vben 官方远程仓库。
- 后端根仓库已独立配置 `commitlint.config.js`、Git hook 与 CLI 依赖，针对合法/非法示例的行为符合预期。
- `package.json` 中通过 `packageManager` 字段将 `pnpm` 配置为唯一包管理器。
- 实际执行了一次带有合法 Conventional Commit 头部的 `git commit`（示例：`chore(git): init AlkaidSYS-tp repo`），通过了 `commit-msg` hook 校验并成功提交。
- 当尝试使用非法头部（例如 `update`）进行提交时，通过 CLI 运行的 commitlint 能正确拒绝该提交。
- 已从 `main` 创建 `develop` 集成分支并推送为 `origin/develop`，后续 feature / fix 分支将以此为长期集成基线。

## 4. 建议与后续工作

1. **CI 集成**
   - 在 GitHub Actions / GitLab CI 等 CI 系统中增加作业，对 PR 中的所有提交使用同一份 `commitlint` 配置进行检查。
   - 可选：增加与 `git-workflow-guidelines*.md` 一致的分支命名校验逻辑。

2. **保持文档与实现同步**
   - 团队成员在配置项目级 CI / Git 规则时，应以本报告与 Git 相关技术规范为权威参考。
   - 当新增 CLI 测试用例或在真实项目中完成更多提交验证时，应同步更新本报告。

3. **分支保护当前状态（GitHub 免费版限制）**
   - 在 GitHub 免费版私有仓库中，无法启用 Branch protection rules 功能。
   - 针对 `main` / `develop` 的分支保护规则已在规范文档中完整设计，但当前仓库由于套餐限制，尚未在 GitHub 上得到强制执行。
   - 一旦仓库升级为付费方案或转为公开仓库，应立即根据分支保护指南文档启用对应配置。

