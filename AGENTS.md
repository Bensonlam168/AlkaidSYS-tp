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
