# Repository Guidelines

## 权威规则与工作流
- **硬性规则**：遵循 `.augment/rules/always-alkaidsys-project-rules.md`（Always Rules，设计文档优先、低代码优先、分层/多租户/REST 约束、后端命令必须在 `alkaid-backend` 容器内执行等）。
- **实现细节指南**：参考 `.augment/rules/auto-alkaidsys-guidelines.md`（代码/命名风格、API 响应包装、权限码映射、测试与 CI 习惯等）。
- **开发流程**：执行 `.agent/workflows/alkaidsys-dev.md`，先读 `design/**`、`docs/technical-specs/**` 与设计契合度报告，再对齐方案后编码与测试。
- **优先级**：Always Rules ＞ Auto Guidelines ＞ 工作流文档；与现状代码冲突时以规则与设计文档为准。

## Language & Communication
- **强制中文回复**：所有交互默认使用简体中文，仅当用户明确使用英文提问时切换
- **代码注释**：生成的代码必须包含中文注释

## 开发与设计原则
- **设计文档优先**：实现与重构需先对齐 `design/**`、`docs/technical-specs/**` 与 `docs/report/design-compliance-audit-2025-12-14.md`。
- **低代码优先**：动态业务实体必须走 `Domain\\Lowcode` + `Infrastructure\\Lowcode`，禁止新增“散表 + 散 SQL”。
- **分层/DDD**：控制器仅做路由、校验、调用 Service/Domain 与统一响应；数据访问放 Repository/Infrastructure。
- **多租户与安全**：所有租户级数据包含 `tenant_id`（必要时 `site_id`），请求/任务需传递权限与 trace_id，上下文过滤不可遗漏。
- **REST 与错误码**：遵守统一响应结构 `code/message/data/timestamp(/trace_id)`；认证/权限错误码按规范使用 2001/2002。
- **低代码表单校验对齐**：前端获取表单详情如需复用后端校验，调用 `GET /v1/lowcode/forms/{name}?with_validator=1&with_parsed=1`，优先使用返回的 `validator.rules/messages` 与 `parsed.components/permissions` 渲染和控制可见性，前端本地 zod 规则仅作兜底，不得覆盖后端规则。

## Project Structure & Module Organization
- `app/`: ThinkPHP application code (controllers, middleware, console commands).
- `domain/`: domain layer (business rules, aggregates, value objects).
- `infrastructure/`: adapters/integrations (DB/queue/cache/IO wiring).
- `config/`, `database/`: configuration plus migrations/seeds.
- `tests/`: PHPUnit suites (`Unit/`, `Feature/`, `Integration/`, `E2E/`).
- `frontend/`: Vue Vben Admin monorepo (pnpm + turbo).
- `docs/technical-specs/` and `design/`: project specs and architecture docs.

## Build, Test, and Development Commands

Backend（全部命令必须在 `alkaid-backend` 容器内执行，保持与 CI 一致）：
- Start stack: `docker compose up -d`（包含 `alkaid-backend`、`alkaid-mysql`、`alkaid-redis`、`alkaid-rabbitmq`、`alkaid-nginx`）。
- Install PHP deps: `docker exec -it alkaid-backend composer install`
- Run Think commands: `docker exec -it alkaid-backend php think migrate:run`
- Tail logs: `docker logs -f alkaid-backend`
- Run tests: `docker exec -it alkaid-backend php think test [--testsuite=Feature]`
- **改动生效规则**：`alkaid-backend` 是 Swoole **常驻进程**（非 PHP-FPM）。`app/**`、`route/**` 改完约 2–5 秒自动重载（仅 `APP_DEBUG=true` 时）；`config/**` 改完用 `docker kill -s USR1 alkaid-backend`；`config/swoole.php` 必须 `docker restart alkaid-backend`。详见 `.agent/workflows/alkaidsys-dev.md` §5。
- **boot 期失败必须退出非 0**：任何在 `Service::boot()` 里抛异常的守卫，必须在 `throw` 前调用 `AppService::markBootFailed()`；否则 `php think <命令>` 会以 0 退出（框架只渲染异常、不改退出码），部署脚本 `cmd || exit 1` 会误判成功。约定与可抄示例见 `docs/technical-specs/deployment/environment-validation.zh-CN.md` §11。

Frontend:
- Install: `cd frontend && corepack enable && pnpm install`
- Dev server: `pnpm dev`（或 `pnpm dev:antd`）
- Build: `pnpm build`

## Coding Style & Naming Conventions
- 参考 Auto Guidelines：PHP 遵循 PSR-12，新文件 `declare(strict_types=1);`；前端使用 Vue3 + TS + Composition API。
- Follow `.editorconfig`（PHP: 4 spaces; JS/TS/Vue: 2 spaces）。
- PHP: `docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix --dry-run --diff`（基于 `.php-cs-fixer.php`）。
- Frontend: Prettier/ESLint/Stylelint；可用 `cd frontend && pnpm lint` / `pnpm format`（见 `frontend/lefthook.yml`）。

## Testing Guidelines
- Backend：`docker exec -it alkaid-backend php think test`，可加 `--testsuite=Feature` 或 `--filter=<类名>`；迁移同样通过 `docker exec -it alkaid-backend php think migrate:*`。`phpunit.xml` 共五个套件：`Unit` / `Feature` / `Integration` / `E2E` / `Legacy`。
- 静态守卫类测试放 `tests/Legacy`（`Legacy` 套件），如 `LegacyCollectionUsageGuardTest`、`WithoutTenantScopeUsageGuardTest`。CI 用 `php think test --testsuite=Legacy` **整组**跑：新增守卫只要落进该目录就自动生效，不必再改工作流。
- **「一条都没跑」必须变红**：`phpunit.xml` 已开 `failOnEmptyTestSuite` —— filter 写错、套件名写错、目录被挪走、用例被删空，都会以 **exit 1** 结束，而不是打印一句 `No tests executed!` 之后绿灯。
  事故背景：`tests/Legacy` 曾不在任何 testsuite 里，CI 却按类名 filter 它，于是两个守卫长期空转，「已纳入 CI」是假的。
  新增「按类名 filter」的 CI 步骤前，先在本地跑一遍确认它真能匹配到用例。
- Frontend：`cd frontend && pnpm test:unit`。
- 命名：测试放在 `tests/**`，使用 `*Test.php`；CI 还会检查 `php think lang:check` 与 `php think route:verify`。

## Commit & Pull Request Guidelines
- Use Conventional Commits with a required scope: `<type>(<scope>): <subject>`（示例：`feat(testing): ... (T-066)`）；header ≤ 72 chars。
- Common types: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `perf`, `style`（`commitlint.config.js` 强制）。
- PR 需包含清晰描述、关联 issue/任务、测试命令与结果；有 `frontend/` UI 变更需附截图。

### 合并前必须通过的配置类门禁（required status checks）

> 依据：R39。下表写的是**准确的 check 名称**，取自各工作流的 `jobs.<id>.name`
> —— 不是 YAML 文件名，也不是 job id。GitHub 分支保护里能勾选的只有 `name:` 那一行。

| 门禁 | 工作流文件 | 工作流 `name` | job id | **check 名称（`jobs.<id>.name`）** | 实际执行 |
| --- | --- | --- | --- | --- | --- |
| 文档一致性 | `.github/workflows/docs-check.yml` | `Docs Check` | `docs-check` | **Documentation Consistency** | `php scripts/check-docs.php --strict` |
| PHPUnit 配置一致性 | `.github/workflows/phpunit-config-check.yml` | `PHPUnit Config Check` | `phpunit-config-check` | **PHPUnit Config Consistency** | `php scripts/check-phpunit-config.php --strict` |
| `.env.example` 一致性 | `.github/workflows/env-check.yml` | `Env Example Check` | `env-example-check` | **Env Example Consistency** | `php scripts/check-env-example.php --strict`（**零依赖、秒级**，不需要 `composer install`；含检查 D0 对 `catalog.template_required` 的自校验，见下） |
| 语言包一致性 | `.github/workflows/backend-php-cs-fixer.yml` | `Backend CI` | `lang-check` | **Language Pack Consistency Check (i18n)** | `php think lang:check` |
| 迁移安全（**仅静态**） | `.github/workflows/migrations-check.yml` | `Migrations Check` | `migrations-check` | **Migration Safety Check** | `php scripts/check-migrations.php --strict --no-db`（只跑 A/C，见下） |
| 迁移数据一致性 | `.github/workflows/migrations-check.yml` | `Migrations Check` | `migration-data-check` | **Migration Data Consistency Check** | 起 MySQL + Redis service → `php think migrate:run` → `php scripts/check-migration-data.php --strict`（**不带** `--no-db`，见下） |
| **测试套件（建议设为 required）** | `.github/workflows/backend-php-cs-fixer.yml` | `Backend CI` | `phpunit` | **PHPUnit Tests** | `migrate:run` → `seed:run -s TestPlatformSeed` → `--testsuite=Unit` / `Feature` / `Legacy` → `route:verify`（**无 `needs`**，因此不会被上游跳过；见下） |

`check-migrations`（`scripts/check-migrations.php`）**已有工作流**（R41），但**CI 的静态 job 只跑 A/C**：
`check A` 迁移版本唯一性、`check C` 的 up()/down() 与 change() 规则 —— 这两项与环境无关。

`check B`（版本倒挂）、`check D`（`tenant_id` 列声明）、`check E`（无默认值 `tenant_id` 的租户外键）、
`check F`（applied 记录必须有对应迁移文件）**都需要「哪些迁移已应用」这个基准**。
静态 job（`migrations-check`）刻意不执行迁移 —— 它要的是秒级、无依赖，因此那里没有 applied 状态，
工作流显式传 `--no-db`（语义是「本环境没有 applied 状态」，不是「允许跳过检查」），
脚本会打印「跳过（--no-db）」而不是把跳过伪装成通过。
（R43 已修好新库冷启动，全量 `migrate:run` 现在跑得通 —— 这正是下面那个 job 敢真跑迁移的前提。）

`check-migration-data`（`scripts/check-migration-data.php`，R51）解决的是**另一类**事故：
**迁移在 `migrations` 表里记为已应用，但它插入的数据一行都不存在**。开发库发生过两次 ——
`20251218000001` 缺 12 条 `tenant.*` / `role.*` / `permission.*` / `user.*` 权限（由 `20251225000001` 修复）、
`20251219000002` 缺 6 条 `dept.*` / `menu.*` 权限（由 `20251226000001` 修复），两次都只以「后台 403 / 2002」暴露。
这类状态**静态检查与单元测试都抓不到**，必须「真建一遍库 → 比对迁移意图 ↔ 库里的实际行」，
所以它单独占一个 job（`migration-data-check`）：MySQL + Redis service → `composer install` →
`php think migrate:run` → `check-migration-data.php --strict`。
静态 job 里只跑它的 `--no-db` 形态（仅验证源码侧 token 提取没被打断，不比对数据）。

⚠️ **本地 / 提交前必须跑全量**，CI 绿不代表 B/D/E/F 与数据比对没问题：

```bash
docker exec alkaid-backend php scripts/check-migrations.php --strict      # 不带 --no-db，会连库
docker exec alkaid-backend php scripts/check-migration-data.php --strict  # 不带 --no-db，会连库
```

⚠️ **CI 只能覆盖「全新库」这一半**：`migration-data-check` 比对的是 `migrate:run` 刚刚建出来的库，
它抓不到**既有开发库自己的漂移** —— 而 R2/R3 的成因恰恰是「数据被执行环境之外的改动弄丢」，
脏库不会自己变干净。所以上面第二行（连开发库/测试库跑）只能靠本地，CI 绿不等于账本与数据一致。

**这些 check 必须被设为 required，否则只是「跑过」而不阻止合并。** 设置位置是**仓库设置**，代码侧无法强制：

```
Settings → Branches → Branch protection rule（或 Rulesets）
  → Require status checks to pass before merging
  → 搜索并勾选上表 6 个名称（`Migration Safety Check` 只覆盖静态检查 A/C，勾选它不等于 B/D/E/F 已被守住；
    `Migration Data Consistency Check` 只覆盖「全新库」里的迁移数据一致性，不等于既有库的账本没漂移）
```

✅ **`paths:` 过滤与 required 的冲突已解决（R40）**：上表三个配置类工作流
（`docs-check.yml` / `phpunit-config-check.yml` / `env-check.yml`）此前在 `pull_request` 与 `push` 下
**都**声明了 `paths:`。带 `paths:` 的工作流一旦被设为 required，未命中这些路径的 PR **根本不会触发**该 check
—— GitHub 会一直显示 `Expected — Waiting for status to be reported` 且不会自动结束，PR 反而永久无法合并
（required 的语义是「必须出现且为 success」，「不出现」等价于永远 pending）。
现已**删除这三份工作流的 `paths:` 过滤**（`push.branches` 保留 `develop` / `main` 不变），
因此这三个 check 对每个 PR 都会给出结论，可以安全地勾选为 required。
代价：每个 PR 都会多跑三次配置类校验，CI 分钟数影响可忽略。
其中 `docs-check`、`phpunit-config-check` 与 `env-check` 都是**秒级纯静态**校验（只读 Markdown / XML / `.env*` / PHP 源码，无 DB、无网络、不需要 `composer install`）。

`env-check` 曾经是**两段式**（后半段 `composer install` + 跑一个 PHPUnit 用例文件，约 1 分钟并需要网络取依赖），现已回到**单段**。
原因与代价都要记住：一个 **required** check 依赖 packagist，网络抖动就会阻塞合并，而它挡的并不是业务代码。改法是把保护移进脚本自身：

- **漂移**（catalog 里写错键名，如 `DB_HOST` → `DB_HOSTX`）：检查 D 自己就会红 —— 它拿清单去两份模板里找，找不到即 `[MISSING-KEY]`；
- **清空**（`template_required` 被改成空数组）：检查 D 会无事可做、原地保持绿色，因此加了检查 D0 的 `[CATALOG-EMPTY]` 非空断言；
- **变少但仍非空**（删掉其中一两个键）：检查 D **也看不见**（它只回答「清单里的键在不在模板里」，不回答「清单本该有哪些键」），
  因此 D0 另加 `[CATALOG-SHRUNK]` 下限断言（`count(template_required) >= TEMPLATE_REQUIRED_MIN_KEYS`，当前 6）。
  用下限而不是再抄一份清单：清单的权威定义只能有一处；下限的作用是**逼人显式表态** —— 真要下线某个键，必须同时把下限改小，那次改动会出现在 diff 里。
- **键集合被换成另外 N 个键**：脚本层看不见（下限只约束数量），只有 `tests/Unit/Scripts/EnvExampleTemplateRequiredConsistencyTest`
  的「钉死 6 键与顺序」能抓 —— 它跑在 `PHPUnit Tests`，因此**把 `PHPUnit Tests` 设为 required 才能让这一类阻断合并**（见下方 §把 `PHPUnit Tests` 设为 required）。
  
R41 新增的 `migrations-check.yml` 自建立起就不带 `paths:`，与上述三份保持一致。

历史说明（为什么当初会有这条）：三份工作流原本用 `paths:` 省 CI 分钟数 ——
未改到 `design/**`、`docs/**`、`phpunit*.xml`、`.env.example`、对应脚本或 `AGENTS.md` 的 PR 就不触发。
把这类门禁列为 required 候选（R39）时才暴露：「省分钟数」与「required 必须每次都有结论」互斥。
当时列过三条备选，最终选第一条 —— 它是唯一既不依赖仓库设置实测、也不会留下永久 `Expected` 的做法：

- **已采用**：去掉对应工作流的 `paths:` 过滤，让这三个 check 对每个 PR 都跑一遍（R40 已完成）；
- 或改用 **Rulesets** 的 required status checks，并在落地前实测：开一条只改 `app/` 的 PR，确认未被 `paths:` 命中的 check 不会让 PR 停在 `Expected`；
- 或暂不设 required，改为靠 `CODEOWNERS` + PR 模板人工把关。

`.github/CODEOWNERS` 同样要仓库设置配合才生效：只有勾选 **Require review from Code Owners** 才阻止合并；
且**唯一 owner 自己发起的 PR 无法自己 approve 自己**，单维护者仓库开启前需先加第二位有 write 权限的协作者
（详见该文件头部注释）。

#### 把 `PHPUnit Tests` 设为 required（步骤）

**为什么需要它**：上表的门禁全是**静态**检查，没有一条真正跑业务测试。具体到这个仓库最近修的一类问题——
`EnvRequirementCatalog::template_required` 的键集合**被换成另外 N 个键**（数量不变、键名不同）——
脚本层的检查 D 与 D0 下限断言都看不见（下限只约束数量），只有
`tests/Unit/Scripts/EnvExampleTemplateRequiredConsistencyTest` 里「钉死 6 键与顺序」那条能抓，
而它跑在 `PHPUnit Tests`。不把它设为 required，这类改动就是「红了但不阻断合并」。

**前置条件（不满足就不要设）**

- [ ] **先在真实 CI 上确认 `PHPUnit Tests` 是绿的**。required 的语义是「必须出现且为 success」，
      一个**永远不会 success** 的 check 被设为 required，等于把仓库锁死（同 R40 的 `Expected` 教训）。
      做法：随便开一个 PR（或往 `develop` 推一次），等 `Backend CI` 跑完，看 `PHPUnit Tests` 的结论。
      当前本地全量基线是 `810 tests / 4768 assertions / 0 errors / 0 failures`；
      CI 与本地环境的差异（PHP 补丁版本、无 `.env`、service 启动耗时）只有实跑能证明。
- [ ] 若首次实跑是红的：**不要设 required**，先把失败修掉。把红的检查设成 required 只会让所有人绕过它。

**它为什么不会被「跳过」（这三点是本仓库已经改过的，别改回去）**

- 该 job **没有 `needs:`**（见 `.github/workflows/backend-php-cs-fixer.yml`）。此前是
  `needs: [php-cs-fixer, lang-check]`，而风格检查当前恒红 → `phpunit` 被 skip → 测试信号完全丢失，
  且 skip 会让 required check 永久 pending。解耦的来龙去脉见上文 R40 段。
- 不声明 `paths:`（`grep -cE '^[[:space:]]*paths:' .github/workflows/backend-php-cs-fixer.yml` 应为 `0`）。
- `phpunit.xml` 已开 `failOnEmptyTestSuite="true"`：套件名打错 / 一个用例都没跑也会红，
  不会出现「跑了 0 个用例但绿」的 required check。

**设置步骤（GitHub 网页，代码侧无法强制）**

1. 进入仓库 **Settings** → **Branches**（或 **Rulesets**）。
2. 对 **`develop`** 与 **`main`** **分别**编辑规则（只设默认分支等于没设——大多数 PR 合进 `develop`）。
3. 勾选 **Require status checks to pass before merging**。
4. 在搜索框里按**准确的 check 名称**搜索并勾选：**`PHPUnit Tests`**
   （名称取自 `.github/workflows/backend-php-cs-fixer.yml` 的 `jobs.phpunit.name`；
   不是 YAML 文件名，也不是 job id `phpunit`）。
5. 同时把上表另外六个名称一并勾上（若尚未勾选）。
6. 保存后**实测一次**：开一个只改 `app/` 一个字（或加一行注释）的 PR，确认
   ① `PHPUnit Tests` 出现在 checks 列表里、② 它**不是** `Expected` 而是真的在跑、
   ③ 把它手动标红时合并按钮确实被禁用。

**已知边界**

- 该 job 带 `if: github.repository == 'Bensonlam168/AlkaidSYS-tp'`。**来自 fork 的 PR 不会触发它**
  → 对 fork PR 而言它永远是「缺失」，会被判为 pending。当前是单仓库开发、无 fork PR 流，
  所以不构成问题；**若将来接收 fork PR，必须先把这条 `if:` 去掉**（代价是 fork PR 会消耗本仓库 CI 分钟数）。
- 该 job 会跑 `migrate:run` + `seed:run -s TestPlatformSeed`，比静态门禁慢一个数量级（分钟级）。
  这是它的价值所在，不要为了省时间把它改成 `--testsuite=Unit` 单套件。

#### 人工核对清单（改 CI / 分支策略后逐项确认）

- [ ] `develop` 与 `main` **都**建了 branch protection rule（或 ruleset），不只默认分支
- [ ] 已勾选 `Require status checks to pass before merging`
- [ ] 已勾选：`Documentation Consistency`、`PHPUnit Config Consistency`、`Env Example Consistency`、`Language Pack Consistency Check (i18n)`、`Migration Safety Check`、`Migration Data Consistency Check`（后两者分别只覆盖「静态 A/C」与「全新库的数据一致性」，见上文 ⚠️）
- [ ] 已确认 `PHPUnit Tests` **先在真实 CI 上跑绿**，再按上文「把 `PHPUnit Tests` 设为 required」的步骤勾选（红的 check 设为 required = 锁死仓库）
- [ ] 已确认四份工作流（上表前三个 + `migrations-check.yml`）**不再有** `paths:`（R40 已完成；`grep -cE '^[[:space:]]*paths:' .github/workflows/docs-check.yml .github/workflows/phpunit-config-check.yml .github/workflows/env-check.yml .github/workflows/migrations-check.yml` 均应为 `0`，注释里提到 `paths:` 不算），不会出现永久 `Expected`
- [ ] 已勾选 `Require review from Code Owners`，且已排除「owner 本人无法合并」的单人瓶颈
- [ ] `check B` / `check D` / `check E` / `check F` 无法在静态 job 里覆盖（需要 applied 状态），已确认**本地跑过全量**：`docker exec alkaid-backend php scripts/check-migrations.php --strict`
- [ ] 已确认**本地跑过**迁移数据全量比对（CI 的 `migration-data-check` 只比对全新库，抓不到既有库漂移）：`docker exec alkaid-backend php scripts/check-migration-data.php --strict`
- [ ] 新增 / 重命名工作流的 job 后，回填上表的 check 名称（永远取自 `jobs.<id>.name`）

#### 迁移文件改名 / 删除的协调流程（ER-03）

`migrations` 表是 Phinx 的账本，它**只记版本号，不知道文件去哪了**。某个迁移文件一旦被改名或删除，
旧库的 applied 记录就会变成「孤儿记录」：`php think migrate:status` 把它显示成 `MISSING`，**退出码却仍是 0**。
这类记录不会被 `migrate:run` 自愈，必须显式协调。

`scripts/check-migrations.php` 的**检查 F** 是这条防线：连库时逐条比对「applied 记录 ↔ 迁移文件」，
发现 MISSING 即报错（`--strict` 下 exit 1）并**列出全部版本号**。已知遗留可登记到
`ORPHAN_APPLIED_EXEMPTIONS` 作过渡豁免，但**必须带 `review_by` 到期日**：到期即退化为错误，
账本协调完成后条目会变成 `[EXEMPT-STALE]` 要求删除（不允许留僵尸豁免）。

处置顺序（**不要手工拼 DELETE**）：

```bash
# 1) dry-run：只 SELECT + 打印「孤儿记录」与「有文件但未 applied」两个清单，绝不写库
docker exec alkaid-backend php scripts/migrate-reconcile.php

# 2) 备份 migrations 表（脚本也会提示；先确认备份非空再往下走）
docker exec alkaid-mysql mysqldump -u<user> -p <db> migrations > migrations.backup.sql

# 3) 只删「指定版本的单条」孤儿记录；缺 --yes 会被拒绝执行，数据库不动
docker exec alkaid-backend php scripts/migrate-reconcile.php --apply --version=<ver> --yes

# 4) 重跑全量检查：检查 F 应无 [MISSING]；随后删掉脚本里对应的豁免条目
docker exec alkaid-backend php scripts/check-migrations.php --strict
```

`migrate-reconcile.php` 的边界是**故意做窄**的：默认 dry-run；一次只删一条且版本号必须由人指定；
账本里没有该版本、或该版本**仍有对应文件**时一律拒绝（删掉有文件的记录会让迁移被重复执行）。
它不改迁移文件、不改表结构、不批量清理。
**改名迁移时只改文件名而不清账本，等于亲手制造上面那条 MISSING。**

## Documentation Maintenance（文档维护约定）

> 依据：`docs/report/doc-review-design-docs-2026-10-30.md` 的 22 项文档缺陷分析。

### 1. 设计文档必须标注「实现状态」

新增或修改 `design/**/*.md` 时，在文档头部（H1 与文档信息表之后）加一行状态声明：

```markdown
> 🔶 **实现状态**：**部分实现** —— 已实现：xxx。未实现：yyy。
```

状态取值：`✅ 已实现` / `🔶 部分实现` / `❌ 未实现` / `⛔ 已废弃` / `🔄 已被取代` / `⚠️ 计划文档·进度未回填`。

**理由**：此前 73 份设计文档中仅 4 份有「最后更新」，读者无法判断哪些能力真实存在。

### 2. 示例代码使用设计阶段命名时必须声明

若设计文档示例使用非实际命名空间（如 `app\common\*`、`alkaid\lowcode\*`），必须在头部加：

```markdown
> ⚠️ **命名空间声明**：本文示例代码使用**设计阶段的命名**，与最终实现结构不同。
> **请勿直接照抄**，实际命名映射见 `lowcode-architecture-guidelines.md` §5.3。
```

**理由**：核查发现设计文档 48 个项目类引用中 39 个指向不存在的文件。

### 3. 设计文档与任务的双向引用

- 设计文档中实现某能力时，标注对应任务号，如 `（实现任务：T-118）`。
- `docs/todo/plan/task-completion-report-T*.md` 中，链接对应的设计文档章节。

**理由**：`design/` 中仅出现 1 个任务号，而 `docs/todo/` 有 114 个，双向不可追溯。

### 4. 文档改动必须通过一致性检查

```bash
docker exec alkaid-backend php scripts/check-docs.php            # 本地自查
docker exec alkaid-backend php scripts/check-docs.php --strict   # CI 门禁模式
```

检查项（`scripts/check-docs.php`，共 4 项）：

- **检查 0 扫描范围完整性**：扫描根是 `design/`、`docs/`，外加 `SCAN_FILES` 登记的根目录文档
  （`AGENTS.md` / `CLAUDE.md` / `README.md` / `README.local.md`）。根目录 md 既未纳入扫描、
  也未登记进豁免清单 → 报错（逼提交者显式表态，避免新文件被静默漏扫）。
- **检查 1 链接有效性**：目标是**任意非协议路径** —— `.md`、代码文件（`.php` / `.sh` 等）、
  `composer.json` 这类配置、以及**目录**都同等校验；一律按**所在文件所在目录**解析，
  `is_file()` 或 `is_dir()` 即通过；裸相对链接同样校验；`file://` 本机绝对路径判错；
  `path:行号` 判错（见 4.2）。
- **检查 2 代码围栏配平**：奇数个围栏（三个反引号）会让后续内容被吞进代码块。
- **检查 3 示例代码类引用**：`use` 声明指向不存在的类 → 报错；已声明「设计阶段命名」或
  「待实施方案」横幅的文档、以及 `docs/report/` 下的审计报告豁免。

CI 门禁：`.github/workflows/docs-check.yml`，check 名称 `Documentation Consistency`。
**每个 PR 都会跑，与改动路径无关** —— R40 已按「required 必须每次都有结论」删掉 `paths:` 过滤
（原因见上文「合并前必须通过的配置类门禁」），并不是「只在改 `design/**` / `docs/**` 时才跑」。
本门禁是纯静态校验（只读 Markdown / PHP 源码，无 DB、无网络），秒级完成。

#### 4.1 链接怎么写（在 GitHub 上必须点得开）

下面每条都是**在 GitHub 上必然 404** 的写法，而旧版检查器曾经全部放过 —— 因此定成硬约定：

1. **必须从文档所在目录出发写相对路径**。在 `docs/report/x.md` 里想指向仓库根的
   `app/Request.php`，正确写法是 `../../app/Request.php`，不是 `app/Request.php`
   （后者会在 GitHub 上解析成 `docs/report/app/Request.php`）。检查 1 也按「文件所在目录」
   解析，两边口径一致。
2. **要定位行用 `#L12` / `#L12-L20`，不要用 `:12` / `:12-20`**：GitHub 会把 `:12` 当成文件名的一部分。
   注意 `#行号` 锚点只在**代码视图（blob，含 `?plain=1`）**上可靠；要在**渲染后的 Markdown 正文**里
   定位，用**标题锚点**而不是行号。检查 1 只校验 `#` 之前的目标是否存在，**不校验锚点本身**。
3. **不要用 `file://` 本机绝对路径**（`file:///Users/xxx/...`）：既泄漏本机目录结构，
   换台机器、换个人就必然打不开，检查 1 直接判错。
4. **指向已删除文件的归档引用，降级为行内代码**（写成 `domain/Model/Collection.php` 这样）：
   保留原文陈述、退出链接校验；**不要**为了过检查而删掉那句陈述。
5. **目录链接是合法目标**，末尾 `/` 保留（`is_dir()` 即通过，否则所有「目录索引」链接都会被误报）。

> `AGENTS.md` 自己在 `SCAN_FILES` 里，本文档同样要过这套检查：**本节与 4.2 列的反例必须放在
> 行内代码或围栏代码块里**（检查 1 会先剥离这两类内容再找链接）。裸着写就会真的被报错 ——
> 那是设计如此，不是误报。

#### 4.2 「伪协议」：文件名带 `:行号` 后缀

这一类是本项目真实踩过的坑，**连检查器自己都被骗过去了**：

```
形如 composer.json:1、phpunit.xml:1、docker-compose.yml:1、phpunit.performance.xml:1 的目标，
看起来像「文件名 + 行号」，但按 URL 语法它是一条合法的 scheme：
协议判定的正则是 ^[a-z][a-z0-9+.\-]*:，而 scheme 名允许点号，
于是 composer.json:1 被读成「scheme 取值为 composer.json、剩余路径为 1」，
整条链接被当成外部协议静默跳过 —— 坏链就此长期潜伏。
```

- **现状**：检查 1 现在**先摘掉行号后缀、再做协议判定**（`detectLineSuffixLink()`），
  所以这类写法**能被抓到**了，并附带定向提示「改用 `#L12` / `#L12-L20`」。
- **教训**：**「检查器通过」不等于「内容正确」**。上面这批坏链正是在「检查全绿」的状态下潜伏了很久，
  因为写法恰好落在检查器的盲区里。发现盲区要**补检查**，不要靠人工记忆。
- **通用一条**：任何「看起来像 URL 的东西」写进文档前，先想清楚它在 GitHub 上会被解析成什么。

### 5. 修改代码时同步更新文档

- 修改/删除类、命名空间、目录结构时，同步更新引用它的设计文档；
- 完成一个阶段后，更新 `docs/PROJECT-OVERVIEW.md` 的「设计 vs 实现对照」；
- 文档与代码冲突时，**以代码为准**，并修正文档。

## Configuration & Security
- Create local config from `.env.example` (`cp .env.example .env`) and never commit secrets；env 命名以 `.env.example` 为“单一真相源”。
- **`.env` / `.env.example` 的注释行禁用 ASCII 括号与引号**（`(` `)` `"` `'` `[` `]` `{` `}` 等）：
  `parse_ini_file` 遇到未加引号的 ASCII 括号/引号会抛 `syntax error`，导致**整份文件解析失败**、所有键**静默失效**
  （`think\Env::load()` 拿到 `false` 后按 `?: []` 兜底），表现为「容器正常起来了，但连不上库 / 配置像没生效」，
  极难定位。需要括号时一律用**全角括号**：`（）`（书名号 `「」` 同理可用）。
- **注释里也不要出现半角 `=`**：`parse_ini_file` 会把 `# xxx = yyy` 解析成**伪键**（键名形如 `# 1. 生产类环境（APP_ENV`）。
  虽有 `#` 前缀不会覆盖真实键，但会制造噪音，并掩盖「注释里含 `=` 会变键」这一危险模式。
  表达「等于」请改写为「取值为 / 为 / 即」，或改用全角 `＝`。
- **上述两条的自查命令**（改完 `.env` / `.env.example` 必跑，在 `alkaid-backend` 容器内执行）：
  - 能否正常解析：`php -r 'var_dump(parse_ini_file(".env.example", true, INI_SCANNER_RAW) !== false);'`
    —— 必须输出 `bool(true)`。输出 `bool(false)` 时同屏还会打印 `syntax error, unexpected '(' in ... on line N`，据此定位行号。
  - 有无伪键：`php -r '$d = parse_ini_file(".env.example", true, INI_SCANNER_RAW) ?: []; var_dump(array_values(array_filter(array_keys($d), fn ($k) => !preg_match("/^[A-Z][A-Z0-9_]*$/", (string) $k))));'`
    —— 必须输出 `array(0) {}`。非空即说明注释里有半角 `=`。
- Avoid committing generated artifacts like `vendor/`, `runtime/`, and `node_modules/`.
- **禁止提交嵌套 `vendor/`**：根 `.gitignore` 的 `/vendor` 是根锚定的，管不到 `plugins/**/vendor/`，故「依赖与构建产物」分组已单独补上 `plugins/**/vendor/`；规则现处于生效状态（`plugins/workflow-engine/vendor` 历史上被跟踪的 4,270 个文件已用 `git rm -r --cached` 出库，改由插件自身的 `composer.json` / `composer.lock` 重建；问题来龙去脉见质量审查报告 C-3）。
