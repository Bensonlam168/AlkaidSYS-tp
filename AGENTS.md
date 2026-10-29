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

## Configuration & Security
- Create local config from `.env.example` (`cp .env.example .env`) and never commit secrets；env 命名以 `.env.example` 为“单一真相源”。
- Avoid committing generated artifacts like `vendor/`, `runtime/`, and `node_modules/`.
