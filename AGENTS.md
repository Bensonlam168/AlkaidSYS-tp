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
- Backend：`docker exec -it alkaid-backend php think test`，可加 `--testsuite=Feature` 或指定文件；迁移同样通过 `docker exec -it alkaid-backend php think migrate:*`。
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
| `.env.example` 一致性 | `.github/workflows/env-check.yml` | `Env Example Check` | `env-example-check` | **Env Example Consistency** | `php scripts/check-env-example.php --strict` |
| 语言包一致性 | `.github/workflows/backend-php-cs-fixer.yml` | `Backend CI` | `lang-check` | **Language Pack Consistency Check (i18n)** | `php think lang:check` |
| 迁移安全（**仅静态**） | `.github/workflows/migrations-check.yml` | `Migrations Check` | `migrations-check` | **Migration Safety Check** | `php scripts/check-migrations.php --strict --no-db`（只跑 A/C，见下） |
| 迁移数据一致性 | `.github/workflows/migrations-check.yml` | `Migrations Check` | `migration-data-check` | **Migration Data Consistency Check** | 起 MySQL + Redis service → `php think migrate:run` → `php scripts/check-migration-data.php --strict`（**不带** `--no-db`，见下） |

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
代价：每个 PR 都会多跑三次**秒级纯静态校验**（只读 Markdown / XML / 源码，无 DB、无网络），CI 分钟数影响可忽略。
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

#### 人工核对清单（改 CI / 分支策略后逐项确认）

- [ ] `develop` 与 `main` **都**建了 branch protection rule（或 ruleset），不只默认分支
- [ ] 已勾选 `Require status checks to pass before merging`
- [ ] 已勾选：`Documentation Consistency`、`PHPUnit Config Consistency`、`Env Example Consistency`、`Language Pack Consistency Check (i18n)`、`Migration Safety Check`、`Migration Data Consistency Check`（后两者分别只覆盖「静态 A/C」与「全新库的数据一致性」，见上文 ⚠️）
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

检查项：相对链接有效性、代码围栏配平、示例代码类引用存在性。
CI 门禁：`.github/workflows/docs-check.yml`（`design/**` 或 `docs/**` 变更时触发）。

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
- **禁止提交嵌套 `vendor/`**：根 `.gitignore` 的 `/vendor/` 只对根目录生效，`plugins/**/vendor/` 需单独忽略（现存问题见质量审查报告 C-3）。
