# 未决事项台账（2026-11-03）

> 🔶 **实现状态**：**进行中** —— 本文件记录截至 2026-11-03 的未决事项与已知红项，不是设计文档。
> 每项都带可复现的判定依据；解决后请在此划掉并注明提交号，不要直接删除条目。

> **后续评审说明（2026-11-03）**：本文保留执行阶段的备忘及历史数字；“领先 299 个提交”“未决仅两处”和部分“仍未做”条目已不代表当前状态。当前完整风险与 ER 复核见[整改实施评审报告](../report/remediation-implementation-review-2026-11-03.md)，开发优先级见[后续开发指引](enterprise-restart-development-guide-2026-11-03.md)。评审已只读确认 develop/main 均配置七项 required；最新前端 CI 失败，不能将局部门禁结果概括为所有 CI 全绿。

## 0. 一句话状态

本地 `develop` 领先 `origin/develop` **299 个提交**，工作区干净，全量测试
**812 tests / 4780 assertions / 0 errors / 0 failures**，十道 CI 门禁全绿。
未决事项集中在两处：**远端备份缺失**（见 §1）与**插件 DI 容器的契约缺件**（见 §2）。

---

## 1. ~~P0：远端与本地完全不同步~~ 已解决（2026-11-03）

原先的状态与含义（留档）：`origin/develop` 停在 `c36ab58 2025-11-24 chore(git): init`，
本地领先 299 个提交、2,914 文件、+413,564 / −5,045 行，提交时间跨度
2025-11-25 → 2026-11-03。线上只有初始化提交，**全部实际工作量只存在于本地**，
一次误删或被 `git clean -fdx` 清理即不可恢复。

**已处置**：2026-11-03 移除 `remote.origin.pushurl` 的 `no-push-disabled://local-only`
覆盖后推送 `develop`，`c36ab58..1e5c00e`（300 个提交），fast-forward 无需 force。
推送后本地与 `origin/develop` **0 ahead / 0 behind**。

**推送前做过的检查**（可复现）：
- `.env` / `.env.local` / `.env.production` 均未入库；入库的只有三个 env 模板
- 历史中从未出现真 `.env`；私钥 / AKIA / ghp_ 特征唯一命中是文档示例代码
  `design/07-integration-ops/25-system-integration.md` 的 `wordwrap($privateKey…)`，非真密钥
- 当前树无 > 5MB 文件；历史最大单文件 22.6 MB（`phpstan.phar`，随 vendor 出库但仍留在历史里），
  低于 GitHub 100MB 上限。推送范围 11,835 对象 / 7,921 blob / 73.1 MB 未压缩
- 五份门禁工作流的 `paths:` 过滤全为 0，不会出现永久 `Expected`
- 7 个 required check 名称与文档一致；`CODEOWNERS` 存在
- 全量 812 tests / 0 failures；十道门禁全 exit=0

**仍未做**：`main` 未推（`origin/main` 仍指向 `60c2387`，`develop` 领先它 300 个提交）。
分支保护是否已把这 7 个 check 勾为 required 属 GitHub 仓库设置，本地无法读取，需在
Settings → Branches 人工确认（清单见 AGENTS.md）。

---

## 2. 未决决策：插件 DI 容器

**现状**：`plugins/workflow-engine` 独立套件 `1043 tests / 3046 assertions / 24 errors`。
主套件 812 全绿，两者并存的原因是**这套 DI 容器从未进入生产路径**：
`grep WorkflowContainer|ServiceFactory|ServiceContext|ProcessEngines app/ config/ route/ domain/ infrastructure/`
命中 0。主应用只用插件里另一个自洽子系统（`Definition\*` / `Triggers\*`）。

### 2.1 A 类 11 条：接口零实现

`WorkflowContainer::buildCoreServices()` 映射 12 个接口，其中 **10 个在全仓库零实现**：

```
IProcessDefineService        IProcessInstanceService    IProcessTaskService
IProcessTaskActorService     IProcessDesignService      IProcessFormService
IProcessTypeService          IProcessInstanceHistoryService
IProcessTaskHistoryService   IScheduler
```

（余下 2 个：`IProcessEngines` 由 `Core\ProcessEngines` 实现、`IConfiguration` 由
`cfg\Configuration` 实现；`INodeParser` 是抽象类不可实例化。）

要收敛这 11 条必须**凭空写出九个业务服务**，并先定三个决策：

| 编号 | 决策 | 为什么必须人定 |
|---|---|---|
| D1 | 短名 `processDefineService` 与接口 FQCN `IProcessDefineService::class` 是否互为别名、指向同一实例 | 代码里没有任何别名机制的痕迹；实测短名注册 mock 后 `find(IProcessDefineService::class)` 仍为 null |
| D2 | 全局上下文由 `Configuration` 还是 `WorkflowContainer` 独占 | `Configuration::__construct` 目前会重建并**抹掉**外层容器（实测）；两套引导路径都是既有代码，选谁等于删改另一套 |
| D3 | `ProcessEngines` 的构造参数取哪一份配置 | 候选有 `initialize()` 收到的、`getBackupConfig()`、`config/workflow.php`，代码未指定 |

### 2.2 B 类 13 条：宿主耦合，属测试放置问题

报错点 `ThinkPHPAdapter` 需要 `Request::instance()`、`app()->make(app\middleware\Auth::class)`、
`app()->make(app\service\JwtService::class)`，而插件 autoload 只映射
`Alkaid\Workflow\Engine\`，不含 `app\`。

实测即使把 `Request::instance()` 整段跳过，13 条仍会以 `TypeError` 继续报错 ——
**改错误消息不减少 error 数**，所以没有改。

可选处置（需人批准）：

1. 把插件 `phpunit.xml` 拆成 standalone / host-required 两个 suite，默认门禁只跑前者；
2. 归档这批用例；
3. 给插件补「宿主必须在启动后注入上下文」的显式契约，让测试先引导一个最小宿主。

> 注意：选项 1 与 2 都会让这 13 条**在默认门禁里不跑**。仓库既有约定是
> 「一条都没跑必须变红」（`failOnEmptyTestSuite`），因此这一步必须显式批准，
> 不能由实施者顺手做。

### 2.3 若要让插件真正接入主应用，容器必须先补的 7 件事

1. 9 个 service 接口 + `IScheduler` 的实现
2. key 口径统一：引擎侧用接口 FQCN 与裸类型名（`start`），容器侧用 `xxxService` / `xxxParser`
3. value 口径统一：`setContext()` 要像 `register()` 那样把类名字符串归一化为定义
4. `ProcessEngines` / `Configuration` 的配置来源明确，且不出现 `setContext([])`
5. 全局上下文 owner 归一
6. 解析器 key 统一或双注册（`defaultService()` 注册 `start`，`getNodeParser()` 查 `startParser`）
7. 宿主侧 boot 而非插件侧探测

---

## 3. 已登记但未做的小项

| # | 项 | 依据 |
|---|---|---|
| ~~3.1~~ | ~~插件 `phpunit.xml` 的 `failOn*` 三项未开~~ → **已解决（`ea72b13`）**：三项已补，并把该豁免的 `safety_exempt_allowed` 一并移除，让它进入 fail-closed。反向验证：摘掉一条即报 `[SAFETY-DRIFT]` exit=1 | 补前后 1043/3046/24 完全一致，无新增 risky/warning |
| ~~3.2~~ | ~~`testing-guide.md` 目录树仍列已删除的 `bootstrap.php`~~ → **已解决（`ea72b13`）**：该行已删（当前状态型指南，不加注记） | `grep -c bootstrap.php` 现为 0 |
| ~~3.3~~ | ~~`PROJECT-OVERVIEW` 多项指标过期~~ → **已解决（`ea72b13`）**：按文档自身复现命令重算后回填 8 处（后端 PHP 434/102217、迁移 38、测试 812/4780/0、技术规格 42、Critical 7、文档质量 22、plan 12、report 29、frontend 1679）。另该文档还有 10 处过期（第 12/183/189/190/127/146/129/128/133/241 行）**未在本次授权内**，已单列在交付报告里 | 逐条实测 |
| 3.4 | 根 `.gitignore` 里 `/frontend/node_modules`、`/frontend/dist`、`/frontend/.turbo`、`/frontend/.cache` 四条已被 `frontend/.gitignore` 覆盖，在本文件里是功能死码 | 保留的理由是「将来有人删掉 `frontend/.gitignore` 时不改变行为」；若要清理需评估该前提 |
| 3.5 | `/storage/` 与 `/tmp/` 是 root-anchored 且仅匹配目录：根下一个名叫 `storage` 的**文件**不被忽略 | 路径矩阵实测 |
| 3.6 | `check-raw-sql-tenant.php` / `check-rawsql-safety.php` / `check-env-production.php` 基线即 exit=1，均**不在**十道门禁内 | 前两者是人工审查工具，最后一个是生产配置预检（本地无 `.env.production`） |
| 3.7 | `plugins/workflow-engine/vendor` 已出库，但仓库里的本地副本仍是旧 source 口径；跑一次 `composer install` 才会切到 dist | `vendor/composer/installed.json` 的 `installation-source` 仍是 source |
| ~~3.8~~ | ~~`Dockerfile` 里的 `composer dump-autoload` 仍会触发 `post-autoload-dump` → `php think service:discover` → Redis 自检；构建期无 `.env`、无 `redis` 主机，**`docker build` 在任何环境都失败**。CI 已改走 runner 直接跑 PHP，是绕过而非修好~~ → **已解决（RV-12，2026-11-03 晚）**：① 第 5 层的 `composer dump-autoload` 补 `--no-scripts`，构建期不再引导应用；② 新增 `docker/docker-entrypoint.sh`，容器启动时若 `vendor/services.php` 不可用（缺失、为空、或 include 出来不是非空数组）就执行一次 `php think service:discover`，可用则跳过（幂等，不重复引导应用），末行 `exec "$@"` 透传 CMD 与 compose 的 `command:`；③ 新增第 6 层**只预创建** `vendor/services.php`（内容 `<?php return [];`）并仅 chown 这一个文件给运行期用户 —— 不 chown 整份 `vendor/`，否则运行期能改写任何依赖代码；④ 新增第 7 层 `COPY --chmod=755 docker/docker-entrypoint.sh /usr/local/bin/` + `ENTRYPOINT`，取代基础镜像的 `/entrypoint.sh`（TASK 模式两者等价，均以 `exec "$@"` 收尾）。**验证边界（务必如实转述）**：源码与层内容逐行核对通过、全量测试 812/4780/0/0 与基线一致；**尚未跑真实 `docker build`**（当时 Docker Hub 元数据请求失败，且依赖升级在并行进行），因此「镜像可构建、可启动」仍**未经实测** —— 本项要等一次干净检出上的 build + 隔离实例冒烟才算真正验收 | 见 `Dockerfile` 第 5/6/7 层注释与 `docker/docker-entrypoint.sh` 头注释；实跑待补 |
| 3.9 | `.github/workflows/branch-protection.yml` 有一处同样的缺陷：`composer install` 未加 `--no-scripts` 且全程不生成 `.env`。它只在 `pull_request` 触发，**下一个 PR 会以同样的 `getaddrinfo for redis` 红** | 加 `--no-scripts` 即可 |
| 3.10 | `.github/workflows/git-metrics.yml` 每次推送都以 `event=push` 红了、**0 个 job**，属工作流加载期失败。它只声明了 `schedule` 与 `workflow_dispatch`，不是门禁 | 需单独排查；不影响合并 |

---

## 4. 已解决，留作备忘

| 项 | 结论 | 证据 |
|---|---|---|
| 插件 4,270 个 vendor 文件入库 | 已出库 | `git ls-files plugins/workflow-engine/vendor` = 0 |
| `madong/helper` 只能 git clone gitcode | 已补 `dist` 指向同 commit 的 GitHub zipball | 把 `git` 换成 exit 127 的假二进制后 `composer install` 仍 exit 0 |
| `::class` 写错测试抓不到 | 已有 FQCN 静态守卫 | `tests/Legacy/FqcnReferenceGuardTest.php`，逐段比对大小写 |
| 全局 ignore 的三条僵尸规则 | 已下沉到仓库 `.gitignore` | `git check-ignore -v` 的 source 已是 `.gitignore` |
| `tests/bootstrap.php` 死文件 | 已删除 | 231 行，从未被载入 |

---

## 5. 复现命令

```bash
# 主套件
docker exec alkaid-backend php think test

# 插件独立套件（vendor 在磁盘上，但不在版本库）
docker exec alkaid-backend ./plugins/workflow-engine/vendor/bin/phpunit \
  -c plugins/workflow-engine/phpunit.xml --no-coverage

# 十道门禁
docker exec alkaid-backend php scripts/check-docs.php --strict
docker exec alkaid-backend php scripts/check-phpunit-config.php --strict
docker exec alkaid-backend php scripts/check-env-example.php --strict
docker exec alkaid-backend php think lang:check
docker exec alkaid-backend php scripts/check-migrations.php --strict
docker exec alkaid-backend php scripts/check-migration-data.php --strict
docker exec alkaid-backend php think route:verify
docker exec alkaid-backend php scripts/check-permission-codes.php --strict
docker exec alkaid-backend php scripts/check-legacy-usage.php
docker exec alkaid-backend php think env:check

# 重装插件依赖（换机器时）
cd plugins/workflow-engine && composer install
```

---

## 6. 变更记录

| 日期 | 变更 |
|---|---|
| 2026-11-03 | 建立台账；登记远端分叉、DI 契约三项决策、B 类测试归属、7 项小遗留 |
| 2026-11-03 | §1 远端分叉已解决：推送 `develop` 至 `1e5c00e`，本地与远端同步 |
| 2026-11-03 | CI 首次在这批工作上运行：五道门禁工作流已全绿（含 `PHPUnit Tests`）。根因是 `composer install` 的 `post-autoload-dump` 会启动应用并触发 Redis 自检；另修一处 `.env` 的 `sed` 静默失效与 `route:verify` 依赖 `variables_order` 的问题 |
| 2026-11-03 晚 | §3.8 已闭合（RV-12）：`composer dump-autoload` 补 `--no-scripts`，`vendor/services.php` 改由启动期 `docker/docker-entrypoint.sh` 生成，Dockerfile 新增第 6 层（预创建占位并单独 chown）与第 7 层（`ENTRYPOINT`）。**真实 `docker build` 仍未实跑**，镜像验收待补 |
| 2026-11-03 晚 | RV-15 依赖公告已清零：活跃公告 24 → 0，`composer audit --locked --no-dev` 与 `composer validate` 均 exit 0；仅 `firebase/php-jwt` 1 条 low 走精确 ID 豁免（理由与到期评估见评审报告 RV-15 追记）。本台账原本**没有** RV-15 / 依赖公告条目，故不凭空新增行 |
| 2026-11-03 晚 | 依赖公告门禁从 warn-only 改为真门禁：`.github/workflows/backend-php-cs-fixer.yml` 新增 `Dependency Audit`（job id `dependency-audit`，无 `needs` / 无 `paths`，不跑 `composer install`），**fail-closed** —— 拿不到 packagist 公告库也 exit 1（3 次重试，间隔 5s / 15s；退出码实测 2.9.1 下 `0` 无未豁免公告 / `1` 发现公告 / `100` 拿不到公告库）。同时删除 `.github/workflows/branch-protection.yml` 里那段 `if composer audit ... else 仅警告` 的重复来源。防退化守卫新增 `tests/Legacy/ComposerAuditConfigGuardTest`（C1 `config.audit.block-insecure` 必须为 true；C2 每条豁免必须有非空 `extra.audit-rationale`；C3 必须有未过期的 `extra.audit-review-by`；C4 不许留孤儿条目），并给 `firebase/php-jwt` 豁免填到期日 `2027-03-31`（`composer.lock` 只同步 `content-hash`）。**仍未做**：GitHub 分支保护里勾选 `Dependency Audit` 属仓库设置，需人工操作（清单见 AGENTS.md） |
