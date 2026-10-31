# 阶段 A：建立可信验证基线（合并两份审计报告）

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development

**Goal:** 让「测试通过」重新成为可信信号。阶段 A 不产生业务价值，但决定后续一切判断是否成立。

**依据：** 两份同日审计报告的合并结论 ——
`docs/report/production-readiness-analysis-2026-10-31.md`（图谱 + 4 路实证）与
`docs/report/enterprise-production-restart-audit-2026-10-31.md`（设计裁决 + ER-01~ER-20）。

**两份报告的分歧与裁决（主审已核实）**：

| 分歧点 | 我的报告 | 新报告 | 核实结果 |
|---|---|---|---|
| 四个门禁脚本是否接 CI | 「一个都没接」 | 「已接，与源码不符」 | **新报告正确**：`docs-check` / `phpunit-config-check` / `env-check` / `migrations-check` 四份工作流各跑一个脚本。我写错了 |
| 本地测试是否写开发库 | 是 | 是（ER-15） | 两者一致。**但 CI 已隔离**（`phpunit` job 用 `alkaid_sys_test` service），需区分本地与 CI |
| 风格检查是否阻断下游 | 是 | 「只能确认依赖链会阻断，不足以证明历史从未运行」 | **当前状态成立**：`app/` 82 文件中有 9 个需修复（实测），全仓更多；`phpunit` job 的 `needs: [php-cs-fixer, lang-check]` 会因此被跳过 |
| 依赖公告数量 | 25 条 / 10 包 / 2 High（全依赖） | 24 条 / 9 包 / 1 Critical + 3 High（仅生产依赖 `--locked --no-dev`） | **两者都对，口径不同**。以后统一用「生产依赖」口径 |
| 空接口导致仓储无法实现 | 「无法实现」 | 「技术上可以实现，缺的是语义契约」 | **新报告更准确**。改为「缺语义契约」 |
| 无 POST tasks ⇒ 任务 API 死代码 | 是 | 「论据不足，任务通常由引擎产生」 | **新报告更准确**。改为「生成/执行/持久化未接通」 |
| 生产就绪百分比 | 36% | 拒绝打分（无统一权重） | **新报告更严谨**。本计划不用百分比 |

## Global Constraints

- 中文注释与提交信息；**不得推送**；提交用 `git -c core.hooksPath=/dev/null commit`
- 后端命令一律 `docker exec alkaid-backend ...`
- **不得让测试写开发库**（`alkaid_sys`）——这是阶段 A 的核心
- 不改 `vendor/`、不改应用业务代码（阶段 A 只动验证基建）
- 五道门禁保持 exit=0：`check-migrations` / `check-docs` / `check-phpunit-config` / `check-env-example` / `lang:check`

---

## Task A1：测试资源隔离（ER-15）

**问题**：`phpunit.xml` 的 `DB_NAME=alkaid_sys`，且 `tests/ThinkPHPTestCase.php:50-60` 逐行加载本地 `.env` 并 `putenv()` → **本地跑测试直接改开发库**。开发库已沉淀 369 表 / 207 租户。

**Files:**
- Modify: `phpunit.xml`
- Modify: `tests/ThinkPHPTestCase.php`
- Create: `scripts/check-test-isolation.php`（可选，见验收）

**要求**
1. `phpunit.xml`：`DB_NAME` 改为 `alkaid_sys_test`
2. `tests/ThinkPHPTestCase.php`：
   - **不得**用 `.env` 覆盖 `DB_NAME`（当前 `:50-60` 会 `putenv` 覆盖）
   - 启动断言：解析出的数据库名**必须**含 `_test`，否则**抛异常终止**（fail-closed，不是警告）
   - 断言失败消息要写清「请建 alkaid_sys_test 并检查 phpunit.xml / .env」
3. 建库：在 `alkaid-mysql` 中创建 `alkaid_sys_test`（结构与 `alkaid_sys` 一致，可 `mysqldump --no-data` 复制 schema，或跑 `migrate:run` 冷启动——**冷启动已验证可用**）

**验收（必须给原始输出）**
- 本地跑任一会写库的 Feature 测试后，`alkaid_sys` 的表数与关键行数**完全不变**
- `alkaid_sys_test` 中该测试的数据存在
- 故意把 `DB_NAME` 改回 `alkaid_sys` → 测试**立即抛异常**（证明 fail-closed）
- 全量 `./vendor/bin/phpunit` 在 `alkaid_sys_test` 上跑一次，记录失败集（作为**新的真基线**）

---

## Task A2：CI 解除顺序阻断（ER-16）

**问题**：`backend-php-cs-fixer.yml:90` 的 `phpunit` job 有 `needs: [php-cs-fixer, lang-check]`，而 `php-cs-fixer` job 对全仓报 fixable（实测 `app/` 82 文件有 9 个需修复）→ **测试被跳过**。另 `:161` 的 `migrate:run` 带 `continue-on-error: true`。

**Files:**
- Modify: `.github/workflows/backend-php-cs-fixer.yml`

**要求**
1. `phpunit` 的 `needs` **去掉** `php-cs-fixer`（保留 `lang-check` 或改为 `needs: []`）；风格检查作为**独立门禁**，不与测试串行
2. 删除 `migrate:run` 的 `continue-on-error: true`（迁移失败必须阻断）
3. 测试套件从 `--testsuite=Unit` 扩到 `Unit` + `Feature`（若 Feature 在干净库上不稳，先只加 `Feature` 并记录实际结果，**不要**加 `|| true`）
4. 在工作流里加注释说明为什么解耦（避免后人"优化"回去）

**验收**
- YAML 可解析（`ruby -ryaml`），打印 `phpunit` job 的 `needs` 与 steps
- `grep` 确认 `continue-on-error` 已从 migrate 步骤移除
- 更新 `.github/workflows` 后，`check-phpunit-config.php --strict` 仍 exit=0

---

## Task A3：迁移账本一致性（ER-03）

**问题**：`php think migrate:status` 显示 `20251126000001` up、同时 `20251125` **MISSING**，退出码仍 0。这是 R43 改名的副作用（同一迁移两行）。`check-migrations.php` 不检查「applied 记录必须有对应文件」。

**Files:**
- Modify: `scripts/check-migrations.php`（新增检查 F）
- Create: `scripts/migrate-reconcile.php`（默认 dry-run）

**要求**
1. **检查 F：applied 集合必须有对应文件**。存在 MISSING 时**报错**（`--strict` 下 exit=1），消息列出 MISSING 版本
   - 需要一份**过渡基线**（类似 `LEGACY_CHANGE_ONLY`）：当前已知的 `20251125` 属「改名遗留，待协调」
   - 但基线条目必须有**到期复核**机制（复用 `check-phpunit-config` 的 `review_by` 模式）
2. **`scripts/migrate-reconcile.php`**：默认为 **dry-run**，打印将要做的事（哪些 applied 版本无文件、哪些文件未 applied）；`--apply` 才执行，且只做「删除孤儿 applied 记录」这一种操作，执行前要求备份提示
   - **不得**自动删除任何东西
3. 文档：在 `AGENTS.md` 或迁移相关文档写明「改名/删除迁移文件的协调流程」

**验收**
- 现状下 `check-migrations --strict` 因检查 F 报 MISSING（先让基线条目生效则通过，两种状态都要给证据）
- `migrate-reconcile.php` 无参运行 → 只打印、不写库（用 `SELECT` 前后对比证明库未变）
- `--apply` 需显式指定版本，不做批量猜测

---

## Task A4：依赖与锁文件治理（ER-17）— 本轮只做无风险部分

**Files:**
- Modify: `composer.json` / `composer.lock`（仅 `update --lock`）

**要求**
- 只做 `composer update --lock` 使 lock 与 json 同步；**不做**依赖大版本升级（那会引入未知回归）
- 记录 `composer audit --locked --no-dev` 的**当前**清单（9 包 / 24 条 / 1 Critical + 3 High）到报告，作为后续逐条处置的输入
- **不删** `plugins/workflow-engine/vendor`（需先统一安装与许可证核实）

**验收**：`composer validate --no-check-publish` 退出码改善（lock 错误消失）；全量测试失败集不增加

---

## Task A5：能力台账（ER-20）

**Files:**
- Create: `docs/report/capability-ledger-2026-10-31.md`

**要求**：一张表，每行：能力 / 承诺范围 / 当前状态 / 入口 / 实现证据 / 验证证据 / 阻塞项 / 对应 ER 编号。
只写有证据的项，注明 `[实测]` / `[源码]` / `[待验证]`。
明确列出「已声明但不可用」的入口（如工作流 API）。

**验收**：`check-docs --strict` 通过；表格覆盖 ER-01~ER-20 与我的 12 项新发现

---

## 阶段 B–F（本计划只定方向，A 完成后再逐包细化）

| 阶段 | 内容 | 关键 ER |
|---|---|---|
| **B 关闭安全与资源边界** | 低代码标识符白名单（ER-01）；表所有权 ADR（ER-02）；会话生命周期（ER-04）；grant ceiling（ER-05）；撤权缓存失效链（ER-06）；站点信任 + BaseModel fail-closed（ER-07）；Refresh 原子消费（ER-08）；XFF 可信代理（ER-09）；前端会话协议（ER-14） | 9 项 |
| **C 数据演进与交付链** | Schema 发布协议（ER-10/11）；迁移新旧库路径；CI required checks 落地；镜像与 compose 生产化（ER-18） | 4 项 |
| **D 首个业务闭环** | 「租户内申请单 + 一级审批」端到端；若不含审批则明确不承诺工作流；含工作流则必须先闭环 ER-12 持久化 | — |
| **E 生产预演** | 同产物部署、压测、故障演练、备份恢复（ER-19）、值班与回滚手册 | 1 项 |
| **F 小范围生产验证** | 有限租户放量、观察窗口、回退条件 | — |

**需要先拍板才能进 B 的 4 个决策**（来自两份报告的交集）：

| # | 决策 | 选项 |
|---|---|---|
| D1 | 实例/定义 ID 策略 | 纯自增 `int unsigned`（推荐） / 对外 ULID + 内部自增 |
| D2 | 工作流引擎去留 | 降级为 Definition 子集类库（推荐，零 madong 依赖） / 引入 `madong/helper` 完整收编 / 接口先行执行后置 |
| D3 | 租户表策略 | 平台统一模型 + 共享物理表（推荐） / 租户自主建模 + 前缀隔离 |
| D4 | 首期业务场景 | 由产品负责人定（如「申请单 + 一级审批」） |
