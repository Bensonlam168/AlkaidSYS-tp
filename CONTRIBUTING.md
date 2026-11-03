# 参与贡献（CONTRIBUTING）

> 本文件面向**想改这个仓库代码的人**：环境怎么起、测试怎么跑、代码与提交要遵守什么。
> 只想直接用？看 [README](README.md) 的「快速开始」。

## 🧩 环境要求

| 依赖 | 版本 | 用途 |
| --- | --- | --- |
| PHP | 8.2+ | 后端运行环境（`composer.json` 要求 `>=8.2.0`） |
| Swoole | 5.0+ | Swoole 常驻进程（开发镜像内置 ext-swoole 5.1） |
| MySQL | 8.0+ | 主数据库 |
| Redis | 6.0+ | 缓存 / 队列 / Session |
| RabbitMQ | 3.12+ | 消息队列 |
| Composer | 2.0+ | PHP 依赖管理 |
| Docker + Docker Compose | — | 推荐方式：一条命令起全套依赖 |
| Node.js | 20.10+ | 前端（`frontend/package.json` 的 `engines`） |
| pnpm | 9.12+ | 前端包管理器，禁止使用 npm / yarn |

前端开发实际使用 Node 22.1（`frontend/.node-version`）；`corepack enable` 会按 `packageManager` 字段自动准备 pnpm 版本。

## 🚀 本地启动

推荐用 Docker Compose，依赖与后端都跑在容器里：

```bash
# 1. 环境变量
cp .env.example .env

# 2. 启动依赖与后端（mysql / redis / rabbitmq / backend / nginx）
docker compose up -d

# 3. 安装后端依赖（必须在容器内）
docker exec -it alkaid-backend composer install

# 4. 建表 + 初始数据
docker exec -it alkaid-backend php think migrate:run
docker exec -it alkaid-backend php think seed:run   # 可选
```

自检（返回 `code: 0` 即后端存活）：

```bash
docker exec alkaid-backend curl -fsS http://127.0.0.1:8080/healthz
# {"code":0,"message":"ok","data":{"status":"ok"},"timestamp":...,"trace_id":"..."}
```

几条容易踩的约定：

- **所有 `php think` / `composer` / `php-cs-fixer` 命令都在 `alkaid-backend` 容器内执行。** 容器里的 `DB_HOST=mysql`、`REDIS_HOST=redis` 是 compose 服务名，宿主机上没有同名主机；把命令放进容器可以省掉这类环境差异。
- 改 `app/**`、`route/**` 在 `APP_DEBUG=true` 时会自动重载（约 2–5 秒）；改 `config/**` 后重启容器：`docker restart alkaid-backend`。
- 看日志：`docker logs -f alkaid-backend`；进容器：`docker exec -it alkaid-backend bash`。

前端：

```bash
cd frontend
corepack enable
pnpm install
pnpm dev:antd
```

不想用 Docker？宿主机方式的完整步骤与端口见 [README](README.md) 的「快速开始」，下文命令去掉 `docker exec ...` 前缀即可。

## 🧪 测试

后端（容器内）：

```bash
# 全部测试
docker exec -it alkaid-backend php think test

# 指定套件：Unit / Feature / Integration / E2E / Legacy
docker exec -it alkaid-backend php think test --testsuite=Unit

# 按类名或方法名过滤
docker exec -it alkaid-backend php think test --filter=JwtServiceTest

# 跑单个文件（必须用 --passthru）
docker exec -it alkaid-backend php think test --passthru tests/Unit/Auth/JwtServiceTest.php
```

> `php think test` 不接受位置参数：`php think test tests/Unit/Auth/JwtServiceTest.php` 会报 `Too many arguments.`，指定文件请用 `--passthru`。

前端：

```bash
cd frontend && pnpm test:unit
```

- Feature / Integration 套件依赖数据库与缓存，隔离机制见 [测试隔离说明](docs/testing/test-isolation.md)。
- 改了认证、权限、限流、迁移或 API 行为，请补相应测试；确实无法覆盖的，在 PR 里说明原因。
- 改了语言包，跑 `docker exec -it alkaid-backend php think lang:check`；细则见 [语言包贡献指南](docs/developer-guides/language-pack-contribution.md)。
- 改了 `.env.example` 或环境变量，跑 `docker exec -it alkaid-backend php think env:check`。

## 🎨 代码风格

后端（PHP）：

- 遵循 PSR-12；新增文件必须写 `declare(strict_types=1);`。完整规范见 [代码风格指南](docs/technical-specs/code-style/general-guidelines.zh-CN.md)。
- 命名：类 `PascalCase`、方法/变量 `camelCase`、常量 `UPPER_SNAKE_CASE`；表名 `snake_case` 复数、字段 `snake_case`。
- 缩进 4 空格（`.editorconfig`）；对外的 Service / Controller 方法建议中英双语 PHPDoc。
- 格式配置的唯一来源是根目录 `.php-cs-fixer.php`（`@PSR12` + 短数组 + 去掉未使用 import + 单引号），不要在别处新增平行配置：

```bash
# 只读检查
docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix --dry-run --diff

# 自动修复
docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix
```

前端（Vue + TS）：

- Vue 3 + TypeScript + Composition API，优先 `<script setup lang="ts">` 与 composables。
- 缩进 2 空格（`.editorconfig`）；状态管理统一用 Pinia，token 等敏感数据不做明文持久化。
- 组件命名 `PascalCase`。

```bash
cd frontend && pnpm lint     # 代码检查
cd frontend && pnpm format   # 自动格式化
```

## 📝 提交信息

格式 `<type>(<scope>): <subject>`。规则以 `commitlint.config.js`（`@commitlint/config-conventional` + 项目覆盖）为准：

- `type` **必填**，且只能是 `feat` `fix` `docs` `refactor` `test` `chore` `perf` `style` 之一。
- `scope` **必填**（本仓库约定）。常用 scope：`auth` `api` `db` `config` `deployment` `ci` `git` `security` `performance` `testing` `docs` `lowcode` `workflow` `system` `event` `plugin` `permission` `frontend`；写成清单外的 scope 只告警、不阻断。
- header 长度 10–72 字符；body 每行不超过 100 字符；body、footer 之前各留一个空行。

自检（根目录已 `pnpm install`）：

```bash
# 校验上一次提交信息
pnpm exec commitlint --edit .git/COMMIT_EDITMSG

# 或直接校验一行
echo "feat(api): 新增用户查询接口" | pnpm exec commitlint
```

一个合格的样子：`feat(auth): 登录响应返回 refresh token`——类型和 scope 明确，标题说清做了什么。

## 🔀 Pull Request

- 从 `develop` 切分支，命名 `feature/<priority>-<area>-<short-description>` 或 `fix/<priority>-<area>-<short-description>`，见 [Git 工作流与提交规范](docs/technical-specs/git/git-workflow-guidelines.md)。
- 一个 PR 只做一件事；描述里写清：改了什么、为什么改、关联的 issue。
- 贴出本地跑过的测试命令与结果，例如 `docker exec -it alkaid-backend php think test --testsuite=Feature`。
- 改了 `frontend/` 的界面，请附截图或录屏。
- 改了 API、权限、多租户或对外行为，同步更新 `docs/technical-specs/` 下对应规范。
- 评审关注点见 [PR 评审清单](docs/technical-specs/git/pr-review-checklist.md)。

## 🏛️ 架构约定

这几条是硬约束，评审会逐条对照：

- **分层**：控制器只做路由、参数校验、调用 Service、返回统一响应；业务规则放 Service / Domain；数据访问放 Repository / Infrastructure。
- **统一响应**：所有 JSON 响应为 `code/message/data/timestamp(/trace_id)`。控制器继承 `app/controller/ApiController.php`，用它的 `success()` / `paginate()` / `error()` / `validationError()`，不要手写 `return json([...])`；`code = 0` 表示成功。
- **错误码**：认证失败 → HTTP 401 + `code = 2001`；已登录但权限不足 → HTTP 403 + `code = 2002`（常量定义在 `app/constant/ErrorCode.php`）；4xx/5xx 响应必须带 `trace_id`。
- **多租户**：租户级表必须有 `tenant_id`（必要时再加 `site_id`），ORM 查询与手写 SQL 都必须带租户范围过滤，禁止跨租户的无范围查询或更新；租户 / 站点上下文由 `app/middleware/TenantIdentify.php`、`app/middleware/SiteIdentify.php` 建立。细节见 [租户参数契约](docs/technical-specs/multi-tenant/tenant-parameter-contract.md) 与 [数据库设计规范](docs/technical-specs/database/database-guidelines.zh-CN.md)。
- **低代码优先**：动态业务实体必须走 `Domain\Lowcode` + `Infrastructure\Lowcode`，Schema 是动态实体的唯一事实来源；禁止为业务新建「散表 + 散 SQL」。低代码表单校验以 `GET /v1/lowcode/forms/{name}?with_validator=1&with_parsed=1` 返回的 `validator` / `parsed` 为准，前端本地规则只作兜底。见 [低代码开发规范](docs/technical-specs/lowcode/lowcode-guidelines.zh-CN.md)。
- **权限码**：内部用 `resource.action`（恰好两段，如 `forms.view`），返回给前端用 `resource:action`（如 `forms:view`），不要另立编码体系。
- **结构变更只走迁移**：表、字段、索引、约束一律写在 `database/migrations/`，禁止在共享环境手写 DDL。
- **API 形状**：遵循 REST 语义，不要用 `?action=delete` 这类查询参数承载 CRUD；规范见 [API 规范](docs/technical-specs/api/api-specification.zh-CN.md)。

## 📦 本仓库不包含什么

设计文档与过程报告不在本仓库；这里只放可运行的源码、测试，以及对外发布的技术规范（`docs/technical-specs/`）。

## 💬 遇到问题

- Bug 与功能建议：[GitHub Issues](https://github.com/Bensonlam168/AlkaidSYS-tp/issues)
- 使用与设计讨论：[GitHub Discussions](https://github.com/Bensonlam168/AlkaidSYS-tp/discussions)

## 📄 许可证

本项目采用 [MIT 许可证](LICENSE.txt)，提交即表示同意以同一许可证授权你的贡献。
