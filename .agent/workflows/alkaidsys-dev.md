---
description: Workflow for developing the AlkaidSYS (ThinkPHP 8 + Swoole 5) project, referencing design/, docs/technical-specs/, docs/report/design-compliance-audit-2025-12-14.md and .augment rules as authoritative sources.
---

# AlkaidSYS 开发工作流规则（ThinkPHP 8 + Swoole 5）  

## 1. 文档参考  
- 所有开发需参考 `design/` 目录中的系列文档（如架构设计、接口规范、业务逻辑等）。  
- 设计文档索引：项目根目录 `all_design_files.txt`（列出 `design/**` 中的全部文档）。  
- 技术规范：`docs/technical-specs/**`（API、错误码、多租户、安全、测试等）。  
- 设计契合度基线：`docs/report/design-compliance-audit-2025-12-14.md`（包含 SEC-01 ~ TEST-01 九个已知偏差，用于评估“代码现状 vs 设计”）。  
- 项目内 AI 规则：`.augment/rules/always-alkaidsys-project-rules.md`（Always Rules）与 `.augment/rules/auto-alkaidsys-guidelines.md`（Auto Guidelines），优先级高于代码现状。  


## 2. 技术栈遵循  
- 运行环境：PHP 8.2+（以 `composer.json` 为准）。  
- 后端框架：ThinkPHP 8.0+（`topthink/framework:^8.0`，以设计文档与依赖配置为准）。  
- 异步/高性能层：Swoole 5.0+（需遵循协程使用、进程管理与资源隔离等最佳实践）。  
- 数据与基础服务：MySQL 8.0+、Redis 6.0+、RabbitMQ 3.12+（以 `docker-compose.yml` 为准）。  
- 管理端前端：Vue 3 + TypeScript 5.x + Vben Admin 5.x（参考 `design/06-frontend-design/**`）。  


## 3. 开发流程  
1. **需求分析**：优先阅读 `design/` 与 `docs/technical-specs/` 中对应模块/接口的设计与规范，并结合设计契合度审查报告确认当前实现是否存在 SEC-01 ~ TEST-01 等已知偏差。  
2. **方案与设计对齐**：必要时先在设计文档或 ADR 中补充/更新方案，经评审通过后再进入编码阶段，禁止仅按现状代码“直接扩展”。  
3. **代码实现**：  
   - **分层/DDD**：控制器仅负责路由、参数校验、调用 Service/Domain 与统一响应；业务规则放在 Domain/Service，数据访问放在 Repository/Infrastructure，不得在控制器中直接访问数据库或编写原生 SQL。  
   - **低代码优先**：涉及动态业务实体与表结构时，必须优先通过 `Domain\\Lowcode` + `Infrastructure\\Lowcode`（Schema / Collection / Field / Relationship）实现，禁止为新业务直接设计与低代码无关的“散表 + 散 SQL”。  
   - **异步任务**：使用 Swoole 协程或进程处理高并发场景时，需遵守安全与资源管理规范，并确保多租户（tenant_id/site_id）、权限与 trace_id 等上下文在任务中完整传递。  
4. **测试验证**：优先编写/补充 PHPUnit 单元测试与集成测试（尤其是认证/权限/限流/多租户/低代码关键路径），并在本地通过 Docker 在 `alkaid-backend` 容器内执行：  
   - 运行测试：`docker exec -it alkaid-backend php think test [可选参数]`  
   - 运行迁移/seed 等 CLI 命令：统一使用 `docker exec -it alkaid-backend php think ...`，禁止在宿主机直接执行 `php think ...`。  
5. **文档与报告同步**：  
   - 如变更会影响架构/接口/数据/安全/低代码等设计，需同步更新对应 `design/**` 或 `docs/technical-specs/**` 文档，遵守“先设计、后实现”的流程；  
   - 重要阶段性结果（如重构批次、设计偏差修复）建议在 `docs/report/` 目录下新增报告文档归档。  


## 4. 约束与最佳实践  
- 禁止绕过设计与规范：涉及架构、数据、多租户、安全、低代码相关的核心设计，必须通过评审流程更新 design/** 或 docs/technical-specs/** 后再实现，禁止私自修改或忽略设计文档。  
- Swoole 相关代码需添加中英文注释，说明协程/进程的作用以及与请求上下文（trace_id/tenant_id/site_id/user_id）的关系。  
- PHP 代码必须遵循 PSR-12 编码规范，并推荐在公共 Service/Controller 接口处编写中英双语 PHPDoc；提交前应通过项目根目录的 `.php-cs-fixer.php` 规则进行格式检查（只读或自动修复）。

## 5. 后端进程模型：改了什么代码，怎么生效

### 5.1 前提：后端是 Swoole 常驻进程，不是 PHP-FPM

- `Dockerfile` 的启动命令是 `CMD ["php","think","swoole"]`，`alkaid-backend` 里常驻的是
  1 个 `swoole: manager process` + `worker_num`（= `swoole_cpu_num()`，本机 8 个）个 `swoole: http server #n` + 1 个 `swoole: hot update` 进程；
  `docker top alkaid-backend` 可直接看到这串进程。
- PHP-FPM 每次请求都重新解析脚本，所以「存盘即生效」；Swoole 是在 **worker 启动时** 把 PHP 代码载入内存并常驻，
  **改文件不会改掉已经在跑的进程里的代码**。
- 由此产生一个高频误判：**CLI 一定是最新代码**（`php think ...`、phpunit 每次都是全新进程、重新读盘），
  而 **经 nginx 的请求用的是常驻 worker 里的旧代码**。「CLI 里对、走 nginx 不对」几乎都是进程没重载，而不是逻辑错。

### 5.2 「改了什么 → 怎么生效」速查表

| 改动位置 | 生效方式 |
| --- | --- |
| `app/**`（控制器、中间件、模型、服务、命令…） | 热重载 **自动**生效，约 2–5 秒（`hot_update` 每 2 秒轮询一次 mtime，命中后通知所有 worker 退出重建）；`docker top` 里可见 worker 换 pid |
| `route/**`（路由定义） | 热重载 **自动**生效，约 2–5 秒，同上（`config/swoole.php` 的 `hot_update.include` 已含 `route/`）；**只对新增/修改文件有效，删除/改名见 5.4** |
| `config/**`（`config/swoole.php` 除外） | **不会**自动生效（`config/` 不在 watch 列表）；执行 `docker kill -s USR1 alkaid-backend` 即可 —— worker 重建时会重新 bootstrap，`App::initialize()` 重新读一遍 `config/*.php` |
| `config/swoole.php` 本身 | **必须** `docker restart alkaid-backend`：worker 数量、`hot_update.include`、连接池等拓扑由 manager 进程在启动时读一次，worker 重建不会重读 |
| `database/migrations/**` | 与进程无关，需在容器内执行 `docker exec -it alkaid-backend php think migrate:run` |
| `vendor/**`、`composer.json` | 需 `docker exec -it alkaid-backend composer install` 后再 **`docker restart alkaid-backend`**：新装的类文件不会被常驻进程加载 |

> ⚠️ 上表前两行的「自动生效」**只在 `APP_DEBUG=true` 时成立**：`config/swoole.php` 的 `hot_update.enable` 取的就是 `env('APP_DEBUG', false)`。
> `APP_DEBUG=false`（预发/生产）时根本没有 hot update 进程，任何代码改动都必须 `docker restart alkaid-backend`。

> `.env` 与 `config/**` 走同一条 bootstrap 路径（`think\Env::load()` 在应用初始化时执行），按上表同类处理；该行本次未单独实测，其余各行均有实测依据（见 5.3）。

### 5.3 判定依据（源码位置 + 实测）

- **源码**：`vendor/topthink/think-swoole/src/concerns/InteractsWithHttp.php`
  - `prepareHttp()` 只做 `addBatchWorker($workerNum, [$this, 'createHttpServer'], 'http server')`，即**登记**启动函数，`Manager::initialize()` 里并不会跑 `preloadHttp()`；
  - `preloadHttp()`（负责 `loadRoutes()` / `loadMiddleware()`、clone 出 `route`/`middleware` 实例并通过 `resolving(SwooleHttp::class)` 注入）是 `createHttpServer()` 的**第一行**；
  - 所以**每次 worker 重建都会重新执行 `preloadHttp()`，从磁盘重新读 `route/**`**。
- **热更新侧**：`src/Watcher.php` + `src/watcher/driver/Scan.php` 用 `Timer::tick(2000)` 轮询 `hot_update.include` 目录下 `*.php` 的 mtime，变化后向其它 worker 发 `ReloadMessage`（`src/concerns/InteractsWithServer.php`），worker 退出后由 `Swoole\Process\Pool` 重新拉起。
- **实测**（2026-11-01，`alkaid-backend`，`APP_DEBUG=true`，临时路由 + 临时 config 探针，测完已清理）：

  | 动作 | http worker pid | `GET /__reload_probe` |
  | --- | --- | --- |
  | 新增临时路由（`include` 只有 `app/` 时） | **不变** | 404 |
  | `touch app/middleware/Trace.php`（命中 watch） | 8 个全换 | **200**（新路由生效） |
  | 再改路由返回值 + 改临时 config 各一次 | 不变 | 仍是旧值 |
  | `docker kill -s USR1 alkaid-backend` | 8 个 worker + hot update 进程全换 | **新值** |

- 结论：worker 重建**确实会**重读 `route/**`（也会重读 `config/**`）；缺的只是「谁来触发重建」——`route/` 此前不在 `include` 里，
  所以只能靠 USR1、或改 `app/` 顺带触发。

### 5.4 三个容易踩的边界

1. **删除 / 重命名不会触发重载**：`Scan` 只遍历「新快照里的文件」，文件消失不会命中，旧路由会一直留在内存里。
   实测：删掉 `route/xxx.php` 后 worker pid 不变、该路由仍被命中；DEBUG 下 `think-trace` 面板还要去读源文件，会直接 500
   （`filesize(): stat failed for .../route/xxx.php`），看起来像「路由坏了」，其实只是没重载 + 面板副作用。
   删除/改名后请补一条 `docker kill -s USR1 alkaid-backend`。
2. **重载有秒级抖动**：worker 集体退出重建的瞬间，少数请求会连不上（本地直连 swoole 端口表现为 curl `000`，经 nginx 表现为偶发 502）。
   实测：80 个 `/think` 请求覆盖一次重载，76×200 + 4×连接失败（约 5%，集中在 <1s 窗口内）。本地开发可忽略；要求连续可用时改完再统一重载。
3. **重载 ≠ 重启**：manager 进程不动，manager 读过的东西（`config/swoole.php` 的 `worker_num`/`include`、`.env` 里被 manager 读取的键）不会变。

### 5.5 案例（R51/R52）：权限码改完，恒 403

- 现象：`route/workflow.php` 的权限码已改成两段式 `workflow_*.action`，数据库 `permissions` 数据也补齐了，
  CLI 里跑完整 HTTP 内核返回 200；但经 nginx 的请求**恒定 403**。
- 走过的弯路：先怀疑权限数据没写进去 → 再怀疑 `PermissionCacheService` 缓存 → 又怀疑宿主/容器文件不同步。
- 实际原因：**运行中的 worker 仍持有旧的路由/权限码定义**，进程根本没重载。`docker kill -s USR1 alkaid-backend` 后立刻变 200。
- 现在 `route/` 已进 `hot_update.include`，同类改动会 2–5 秒自动生效，不会再复现。

### 5.6 重载 / 重启用哪条

```bash
docker kill -s USR1 alkaid-backend   # 优雅重载：所有 worker 退出重建，manager 不动，秒级抖动；config/** 改动用它
docker restart alkaid-backend         # 完整重启：manager 也重建；config/swoole.php、composer 依赖、新增扩展用它
```

- 判据一句话：**「数据库 / 配置都对了但行为没变」时，先怀疑进程没重载，再怀疑逻辑。**

## 6. 总体命名规范原则（建议目标态）

- **环境变量（ENV）命名**
   - 统一采用 **全大写 + 下划线** 风格：`APP_ENV`、`DB_HOST`、`JWT_ISSUER`、`JWT_SECRET`、`CACHE_DRIVER`、`REDIS_HOST` 等；
   - 禁止在新代码中使用 `foo.bar` 形式的小写点分隔 key（如 `jwt.secret`、`cache.driver`、`redis.host` 等）；
   - `.env.example` 作为 env 命名的“单一真相源”，所有文档与代码中的 env 名称应与其保持一致。

- **配置文件与数组 key**
   - `config/*.php` 中的配置 key 推荐使用 **小写 + 下划线/无下划线** 风格（当前实现基本符合 ThinkPHP 默认约定，如 `default_app`、`default_timezone`）；
   - 对于引用 env 的配置，应优先复用已有 env（如 Redis 统一走 `REDIS_*`）。

- **类 / 接口 / Trait 与命名空间**
   - 遵循 PSR-12：大驼峰类名（`JwtService`）、小驼峰方法名（`generateAccessToken`）、命名空间与目录结构对应（如 `Infrastructure\Auth\JwtService`）；
   - 插件/领域服务 Provider 推荐统一继承 `think\Service` 或 `Domain\DI\ServiceProvider` 并通过 `app/service.php` 注册。

- **数据库表名与字段名（抽样原则）**
   - 表名采用蛇形复数：如 `collections`、`collection_fields`；
   - 字段名采用蛇形：如 `tenant_id`、`site_id`、`created_at`；

## 7. 建议的统一规范方案（摘要）

- **JWT 相关环境变量统一**
   - 运行时代码与所有文档统一采用：
     - `JWT_SECRET`：JWT 签名密钥（必须为强随机值，生产环境通过安全渠道配置）；
     - `JWT_ISSUER`：JWT issuer（iss），必须为完整域名/URL；
   - 禁止继续使用 `jwt.secret`、`APP_JWT_SECRET` 等旧命名。

- **Redis 与 Swoole 相关环境变量统一**
   - 业务缓存与 Session：统一走 `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` / `REDIS_DB`；
   - Swoole 扩展能力（room/ipc/lock）：短期保持示例配置，长期引入 `SWOOLE_REDIS_*` 或复用 `REDIS_*`，并在设计文档中明确。

- **文档与 `.env.example` 的“一致性约束”**
   - 任一设计/部署文档中出现 env 名称时，必须在 `.env.example` 中存在对应项；
   - 反之，`.env.example` 新增的关键 env 需在设计文档中至少出现一次说明（如 JWT、Redis、缓存/Session 策略）。