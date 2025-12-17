# .augment skills/commands/subagents 适配性审查（Codex 视角）

## 范围
- 覆盖 `.augment/skills/*.yaml`、`.augment/commands/*.yaml`、`.augment/subagents/*.yaml` 与 `.augment/config.yaml` 声明的可用项。
- 评估与项目硬性规则（`.augment/rules/always-alkaidsys-project-rules.md`）、设计规范、Docker 执行约束的契合度，以及在 Codex/Serena 环境下的可用性。
- 参考现有审计文档：`docs/report/augment-configuration-audit-report.md`（发现曾记录部分技能待实现）。

## 结论摘要
- 规则覆盖度：技能/命令/子代理均指向核心安全、低代码、限流、工作流等关键领域，主题契合项目需求。
- 执行一致性：部分命令仍使用宿主机 `php think`/`composer`/`pnpm`，与 Always 规则要求“必须在 alkaid-backend 容器执行后端命令”不一致，易误导。
- 版本/场景标注：缺少“已实现/规划中”标识，历史审计曾标记 create-field/create-form-schema/run-migration/run-tests 等为“待实现”，但当前已有文件，需澄清状态。
- Codex 可用性：所需工具（serena、sequential-thinking、context7、exa/web）在 Codex 可用，但 exa/web 受网络限制需额外批准；命令中未内置容器前缀的部分需手动调整。

## 详细发现

### Skills 适配性
| Skill | 契合度 | 问题/风险 | Codex 可用性 |
| --- | --- | --- | --- |
| create-collection | 中 | 未显式强调多租户/低代码 Schema 为单一事实来源；未示例容器内执行迁移 | 可用 |
| create-field | 高 | 步骤涵盖迁移+低代码表更新；未明确 Docker 执行提示 | 可用 |
| create-form-schema | 高 | 生成前端配置/测试，符合低代码；缺少 Docker 提示 | 可用 |
| create-api-endpoint | 中 | 示例使用 tenantId/siteId，符合多租户；仍缺“先对齐 design/**”提醒 | 可用 |
| run-migration | 高 | 命令使用 `docker exec alkaid-backend`，与规则一致 | 可用 |
| run-tests | 高 | 使用容器命令，分组清晰 | 可用 |
| review-code | 高 | 依赖设计/规范，输出结构清晰 | 可用 |
| auth-permission-best-practices | 高 | 纯指南，紧扣 JWT/PermissionService/Casbin | 可用 |
| rate-limit-and-gateway-best-practices | 高 | Phase1/Phase2 区分、Nginx 分工明确 | 可用 |
| workflow-and-plugin-architecture | 高 | 关注工作流/插件与多租户关系 | 可用 |

### Commands 适配性
| Command | 契合度 | 问题/风险 | Codex 可用性 |
| --- | --- | --- | --- |
| lowcode-init | 中 | 迁移/seed 使用宿主机 `php think`，违反容器执行约束；示例数据未强调租户/站点 | 需手动改为 docker exec |
| generate-crud | 中 | 未写明容器执行；默认依赖现有 Collection 元数据，需先跑迁移/低代码 Schema | 需手动改为 docker exec |
| setup-project | 低-中 | 使用宿主机 `composer install`/`pnpm install`，与项目 Docker 开发约定不符；缺少多租户/低代码准备步骤 | 需替换为容器/CI 等价命令 |
| deploy | 中 | 假设主机直跑 `php think`/`composer`/`pnpm`，与“本地命令需容器化”有差异；生产部署场景未区分 | 需按环境决定执行方式 |
| tests-and-migrations-hardening | 高 | 关注关键路径测试与迁移链，符合需求 | 可用 |
| auth-permission-integration | 高 | 明确 PermissionService、/auth/me permissions 返回、前端同步 | 可用 |
| casbin-phase2-rollout | 高 | Phase 接入、双读比对、测试计划齐全 | 可用 |
| api-error-trace-pagination-unify | 高 | 聚焦错误码/trace_id/分页统一，符合近期任务 | 可用 |

### Subagents 适配性
| Subagent | 契合度 | 问题/风险 | Codex 可用性 |
| --- | --- | --- | --- |
| lowcode-developer | 高 | 无 context_files 指向设计/规范，建议补充 design/**、docs/technical-specs/** | 可用 |
| api-developer | 高 | 同上，缺少 context_files/设计引用 | 可用 |
| auth-security-engineer | 高 | 约束与安全规则一致；context_files 完整 | 可用 |
| frontend-integrator | 高 | 权限码、多租户上下文约束明确；缺少 context_files | 可用 |
| test-migration-engineer | 高 | 覆盖测试/迁移关键路径；context_files 完整 | 可用 |
| documentation-reviewer | 高 | 审查标准明确；已引用 rules 与 docs | 可用 |

## Codex 使用性与限制
- 工具匹配：skills/commands/subagents 均要求 serena + sequential-thinking，Codex 已内置；context7 可直接用，exa/web 因网络受限需用户批准。
- 执行约束：Always 规则要求后端命令通过 `docker exec -it alkaid-backend ...`；当前多个命令仍是宿主机形式，Codex 需手动替换或在说明中写明。
- 现有 CLI 示例均为 “auggie --print …”，Codex 可直接调用技能/命令语义，但需将命令映射到实际文件/步骤执行。

## 修复与优化建议
1) **命令容器化（P0）**：将 `lowcode-init.yaml`、`generate-crud.yaml`、`setup-project.yaml`、`deploy.yaml` 中的 `php think`/`composer`/`pnpm` 改为容器内执行或注明“生产环境直跑/本地需 docker exec”，避免违背 Always 规则。
2) **状态标注（P1）**：在各 skill/command header 补充 `status: stable|beta|planned`，并与 `config.yaml`/`validate-config.sh` 同步，澄清哪些已实现、哪些仍在规划。
3) **设计引用补齐（P1）**：为 `lowcode-developer`、`api-developer`、`frontend-integrator` 等子代理增加 `context_files`（design/**、docs/technical-specs/**、design-compliance-audit-2025-12-14.md），并在关键技能（create-collection/create-api-endpoint）顶部提醒“先对齐设计/规范”。
4) **多租户/低代码强调（P2）**：在 create-collection/create-field/create-api-endpoint skills 中显式要求 tenant_id/site_id 与低代码 Schema 为单一事实来源，避免新增散表/散 SQL。
5) **执行示例校正（P2）**：为 commands/skills 增加基于容器的示例命令，并在 run-tests/run-migration 中提示 `-it`/非交互模式差异。
6) **Codex 操作指引（P2）**：在 `.augment/README.md` 或新建 quick note 中加入 “在 Codex 使用技能/命令时的工具映射与网络批准流程”，减少落地成本。***
