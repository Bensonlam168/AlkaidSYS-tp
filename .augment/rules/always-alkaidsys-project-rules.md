# AlkaidSYS 项目规则（Always Rules）

> 本文件为 **Always 规则**，在 AlkaidSYS 仓库的所有 Augment 会话中始终生效。
> 这些规则是“硬约束”，优先级高于其他建议或代码现状。

## 1. 总体原则

1. **唯一权威来源**：
   - 所有实现、重构必须优先参考：
     - `docs/technical-specs/**/*.md`
     - `design/**`
     - `.augment/skills/**` 与 Serena memories
   - 不得仅以“当前代码长什么样”作为设计依据。

2. **Phase 区分**：
   - 明确区分 Phase 1（现状）与 Phase 2（目标能力）。
   - 未在任务上下文明确要求时，不得假定 Phase 2 能力已经落地并依赖之。

3. **低代码优先**：
   - 对动态业务实体与表结构，必须优先采用低代码框架（`Domain\\Lowcode` + `Infrastructure\\Lowcode`）。
   - 禁止为新业务直接设计与低代码无关的“散表 + 散 SQL”作为主数据模型。

---

## 2. 架构与职责

1. **分层架构**：
   - 严格区分：表现层/应用层、领域层、数据访问层、基础设施层、低代码层等。
   - 控制器只负责：路由、参数校验、调用 Service、统一响应；业务规则放到 Service/Domain。

2. **低代码真实来源**：
   - 低代码 Schema（集合/字段/关系）是动态实体的“单一事实来源”。
   - 新代码不得再引入对以下 Legacy 类的**新增依赖**：
     - `Domain\\Model\\Collection`
     - `Infrastructure\\Collection\\CollectionManager`
     - 旧版 `Infrastructure\\Field\\FieldTypeRegistry`

---

## 3. 数据库与多租户

1. **命名与字段**：
   - 表名使用 `snake_case` 复数；字段使用 `snake_case`。
   - 所有租户级业务表必须包含 `tenant_id`，必要时包含 `site_id`。

2. **租户隔离**：
   - ORM 与原生 SQL 访问必须始终包含租户（和站点）范围过滤：`tenant_id`（和 `site_id`）。
   - 禁止对跨租户数据做无范围更新或查询。

3. **迁移唯一入口**：
   - 所有结构变更（表/字段/索引/约束）必须通过迁移脚本完成。
   - 禁止在生产或共享环境直接执行手写 DDL。

---

## 4. API 与错误码

1. **RESTful 路由**：
   - 必须遵守 API 规范文档；路径与方法需符合 REST 语义。
   - 禁止使用 `action` 查询参数承载 CRUD 语义（如 `?action=delete`）。

2. **统一响应结构**：
   - 所有 JSON 响应必须使用统一结构：`code/message/data/timestamp(/trace_id)`。
   - 控制器必须使用统一基类与封装 Helper，不得随意 `return json([...])`。

3. **错误码矩阵**：
   - 认证/授权/限流等错误必须使用文档规定的错误码，例如：
     - 2001 未授权（Token 问题）
     - 2002 权限不足
     - 4xx/5xx 必须带 `trace_id`

---

## 5. 认证、权限与安全

1. **JWT 双 Token**：
   - 统一使用 Access Token + Refresh Token 机制，`iss` 与环境变量 `JWT_ISSUER` 一致。
   - 刷新时必须轮换 Refresh Token，并将旧 Token 加入黑名单。

2. **Auth / Permission 中间件**：
   - Auth 失败 → HTTP 401 + `code = 2001`；
   - Permission 失败（已登录无权限） → HTTP 403 + `code = 2002`；
   - 错误响应结构必须与 API 规范一致，并包含 `trace_id`。

3. **权限码格式**：
   - 内部权限 slug：`resource.action`，如 `forms.view`（DB 内部字段）。
   - 面向前端/客户端权限码：`resource:action`，如 `forms:view`。
   - 路由中间件使用内部 slug，API 返回给前端使用 `resource:action`。
   - 禁止引入新的权限编码体系（如 `AC_xxx` 或其他格式）。

4. **统一授权服务**：
   - 控制器与中间件只能通过统一的 `PermissionService`（或等价抽象）检查权限。
   - 禁止直接查询角色/权限表在控制器内做授权判断。

5. **限流与安全**：
   - 必须遵守安全规范中关于限流、签名、加密、脱敏等约定。
   - 高风险接口不得绕过限流或签名机制。

---

## 6. 前后端集成

1. **权限集成**：
   - 后端返回权限数组时，必须使用 `resource:action` 格式，供 Vben Access Store 使用。
   - 前端权限判断只能依赖该权限数组和统一工具函数，不得硬编码 role 名判断权限。

2. **多租户上下文**：
   - 前端需通过统一 store 管理 `tenant_id`/`tenant_code`，并按规范在请求中携带。
   - 后端必须基于请求上下文进行租户隔离。

---

## 7. 测试与 Git

1. **测试覆盖**：
   - 新功能（尤其是认证/权限/限流/迁移）必须增加或更新自动化测试。

2. **Git 工作流**：
   - 必须遵守仓库 Git 工作流与分支策略，禁止向受保护分支直推。



---

## 8. 本地 Docker 开发环境与命令执行规范

1. **容器与命名**：
   - 后端 PHP 应用容器固定使用 `alkaid-backend`（见根目录 `docker-compose.yml`）。
   - 相关基础服务容器命名：`alkaid-mysql`、`alkaid-redis`、`alkaid-rabbitmq`、`alkaid-nginx`。
   - 在宿主机确认容器时，优先使用：`docker compose ps` 或 `docker ps --filter "name=alkaid-backend"`。

2. **命令执行原则**：
   - 所有后端 CLI 操作（测试、迁移、seed、缓存清理、命令行工具、Composer 安装等）**必须在 `alkaid-backend` 容器内执行**，禁止直接在宿主机执行 `php think ...`。
   - Augment/Serena 在调用终端运行测试、迁移等命令时，必须构造 `docker exec ...` 形式的命令。

3. **测试命令模板（开发环境）**：
   - 运行全部测试：`docker exec -it alkaid-backend php think test`
   - 指定测试文件：`docker exec -it alkaid-backend php think test <test_file_path>`
   - 特性测试套件：`docker exec -it alkaid-backend php think test --testsuite=Feature`
   - 单个测试方法（示例）：`docker exec -it alkaid-backend php think test <test_file_path> --filter=<method_pattern>`
   - 生成覆盖率（示例）：`docker exec -it alkaid-backend php think test --coverage-html`

4. **迁移与数据库相关命令**：
   - 运行迁移：`docker exec -it alkaid-backend php think migrate:run`
   - 回滚迁移：`docker exec -it alkaid-backend php think migrate:rollback --step=<n>`
   - 查看迁移状态：`docker exec -it alkaid-backend php think migrate:status`
   - 运行数据填充：`docker exec -it alkaid-backend php think seed:run`

5. **其他常用命令**：
   - 进入容器 Shell：`docker exec -it alkaid-backend bash`
   - 查看后端容器日志：`docker logs -f alkaid-backend`
   - 安装后端 Composer 依赖：`docker exec -it alkaid-backend composer install`
   - 执行一次性维护脚本：`docker exec -it alkaid-backend php path/to/script.php`

6. **自动化与 CI 提示**：
   - 在非交互环境（CI 或无 TTY 的执行环境）中，可以去掉 `-it`，使用 `docker exec alkaid-backend ...`。
   - 但无论本地/CI，**一律禁止**直接在宿主机运行 `php think test`、`php think migrate:run` 等命令。

---

## 9. 代码格式与质量工具

1. **PSR-12 强制要求**：
   - 所有 PHP 代码必须符合 PSR-12 编码规范。
   - 新增或修改的 PHP 文件在提交前，应通过自动化工具进行格式检查和（必要时）修复。

2. **PHP-CS-Fixer 配置与使用**：
   - 项目根目录的 `.php-cs-fixer.php` 是 PHP-CS-Fixer 的**唯一权威配置来源**，禁止在其他位置新增平行配置文件。
   - 代码格式检查与修复必须使用该配置文件中约定的规则集（包含 `@PSR12`、`array_syntax`、`no_unused_imports`、`single_quote` 等）。

3. **命令执行环境约束**：
   - 所有 PHP-CS-Fixer 相关命令必须在 `alkaid-backend` 容器内执行，例如：
     - 只读检查：`docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix --dry-run --diff`
     - 自动修复：`docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix`
   - 禁止在宿主机直接调用 `php-cs-fixer` 针对项目代码进行操作。

4. **CI 集成要求**：
   - CI 流程中应包含基于 `.php-cs-fixer.php` 的只读格式检查步骤，例如：
     - `docker exec alkaid-backend ./vendor/bin/php-cs-fixer fix --dry-run --diff`
   - 对于大规模历史代码格式修复，应单独规划迁移批次，不得与业务功能改动混合在同一提交中。