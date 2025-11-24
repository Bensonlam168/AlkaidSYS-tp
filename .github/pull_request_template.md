# Pull Request Template | PR 模板

> Language: English first, Chinese follows. Choose one language or keep both.

## 1. Summary | 变更摘要

- Describe the purpose of this PR and the main changes.
- 简要说明本次 PR 的目的及主要变更内容。

## 2. Related Issues / Backlog | 关联问题 / Backlog 条目

- Link to issues / backlog items (e.g. `P0-auth-permission-service`).
- 关联的 Backlog 条目或 Issue（例如：`P0-auth-permission-service`）。

## 3. Change Type | 变更类型

- [ ] feat      – new feature | 新功能
- [ ] fix       – bug fix | 缺陷修复
- [ ] docs      – documentation only | 仅文档
- [ ] refactor  – refactor without behaviour change | 重构（无行为变化）
- [ ] test      – tests only | 仅测试
- [ ] chore     – tooling / build | 工具链 / 构建
- [ ] perf      – performance | 性能优化
- [ ] style     – formatting / style only | 格式 / 风格调整

## 4. Scope | 影响范围（与提交 scope 对齐）

- [ ] auth        – authentication / authorization | 认证 / 授权
- [ ] api         – HTTP APIs & contracts | 接口与契约
- [ ] db          – schema / migrations / queries | 表结构 / 迁移 / 查询
- [ ] security    – security, Casbin, rate limiting | 安全、Casbin、限流
- [ ] performance – performance & scalability | 性能与扩展性
- [ ] testing     – tests / CI | 测试 / CI
- [ ] deployment  – deployment & ops | 部署与运维
- [ ] lowcode     – low-code engine | 低代码引擎
- [ ] git         – workflow / tooling | Git 工作流 / 工具

## 5. Checklist (High-level) | 基础检查清单（概要）

> Detailed checklist: see `docs/technical-specs/git/pr-review-checklist*.md`.
> 详细检查项请参考 `docs/technical-specs/git/pr-review-checklist*.md`。

### General | 通用
- [ ] Code builds locally without errors | 本地可正常构建
- [ ] No obvious dead code / commented-out blocks | 无明显无用或注释代码

### API
- [ ] Follows API spec (paths, methods, status codes) | 遵守 API 设计规范
- [ ] Responses use unified format & error codes | 使用统一响应与错误码

### Security | 安全
- [ ] Auth / RBAC / Casbin changes follow security specs | 认证 / 授权符合安全规范
- [ ] Sensitive data is not logged | 未输出敏感信息到日志

### Database | 数据库
- [ ] Migrations follow DB guidelines (tenant keys, naming) | 迁移与表结构符合数据库规范
- [ ] No obvious N+1 queries; proper indexes considered | 无明显 N+1，已考虑索引

### Testing | 测试
- [ ] New behaviour covered by tests | 新增/变更行为已有测试覆盖
- [ ] Tests pass locally | 本地测试通过

### Performance | 性能
- [ ] Critical paths reviewed for performance / N+1 / caching | 关键路径性能、N+1、缓存已评估

### Low-code | 低代码
- [ ] Low-code changes follow lowcode guidelines | 低代码相关变更符合规范

### Deployment & Ops | 部署与运维
- [ ] Env vars / configs documented & have safe defaults | 环境变量 / 配置有文档且有安全默认值
- [ ] Logs / metrics / tracing updated where needed | 日志 / 指标 / Trace ID 已更新（如适用）

### Git Workflow | Git 工作流
- [ ] Branch name follows `feature|fix|hotfix|release` convention | 分支命名符合规范
- [ ] Commits follow Conventional Commits + project rules | 提交信息符合 Conventional Commits 与项目规则

