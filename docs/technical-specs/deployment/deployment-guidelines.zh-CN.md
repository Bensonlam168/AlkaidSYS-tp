# 部署与运维规范

## 1. 范围与目标

本规范用于约束 AlkaidSYS 的部署与运维实践，重点关注配置管理、可观测性与运行时安全，是生产环境运维的**权威参考**，与 API 与安全规范互为补充。

## 2. 配置与环境

- 所有与环境相关的配置 **必须** 通过环境变量或纳入版本控制的配置文件提供（不包含机密信息）。
- 密钥（密码、Token、密钥等） **禁止** 提交到代码仓库；**应当** 使用专门的机密管理方案。
- 预发与生产环境的配置 **应当** 尽量保持一致，仅在终端地址、密钥和容量等方面存在差异。
- 仓库内提供两份**仅含占位符**的模板：`.env.example` 是键集合的单一真相源，
  `.env.production.example` 是生产模板（运维复制为 `.env.production` 后逐项替换）。
  生产模板 **必须** 覆盖示例模板里的每一个键，反向不要求 —— 生产可以有示例里没有的键
  （如 `APP_ENV`、`CACHE_DRIVER`、`REDIS_*`、`CORS_ALLOWED_ORIGINS`）。
  这条**单向**约束由 `php scripts/check-env-example.php --strict` 的检查 E 守护，
  CI 门禁为 `Env Example Consistency`；改动任一份模板后 **应当** 先本地跑一次。
  缺键不会让进程启动失败，只会在运行期以「以 root 空口令连库」「所有不带 `X-Tenant-ID` 的请求 500」
  「限流退化为全站共用 ip 桶」这类形式暴露，因此必须由门禁而不是靠人 review 守住。
- **模板静态检查只是第一道，取值合规还要有第二道、第三道**：模板里的键齐全、文件可解析，并不代表这台机器上的
  取值可用（`REPLACE_WITH_*` 忘替换、`DB_USER=root`、`JWT_SECRET` 仍是公开占位符、`APP_DEBUG=true` 都能让
  「键齐全」的配置在运行时炸掉）。
  · **部署前（依赖可能还不通时）**：`php scripts/check-env-production.php --env-file="$ENV_FILE"` ——
  它**不启动 App**，因此不依赖 Redis / DB，读不到文件或解析失败一律以 1 退出；
  · **依赖就绪后**：`php think env:check --strict` 在生产类环境（`production` / `prod` / `stage` / `staging`）
  会逐条判定这些「生产必需项」的生效取值并以非 0 退出，**发布前必须跑**；非生产环境同一套规则只提示不阻断。
  两者共用同一份规则（单一真相源 `app\support\EnvRequirementCatalog`）。
  三道检查的职责边界、生产必需清单与逐键理由、`TRUSTED_PROXIES` 为何是「条件必需」、
  以及生产预检**不能**做什么：见 [环境配置的三道校验](./environment-validation.zh-CN.md)。
- **部署脚本可以相信「boot 期失败 ⇒ 退出码非 0」**：自 R51 起，任何在 `boot()` 里抛异常的启动期守卫
  （`.env` 无法解析、Cache / Session 配置不合规、Redis 自检失败……）都会先调用
  `AppService::markBootFailed()`，由唯一的 shutdown 钩子把**控制台进程**的退出码改成 1。
  因此 `php think env:check || exit 1` 这类写法现在是可靠的 —— 在此之前「生产配置根本起不来」会以
  **0** 退出，被误判成成功。新增 boot 期守卫时**必须**遵守同一约定（示例与验收要求见
  [环境配置的三道校验 §11](./environment-validation.zh-CN.md)）。

## 3. 日志与可追踪性

- 所有服务 **必须** 输出结构化日志（JSON 或键值格式），至少包含时间戳、级别、消息、`trace_id` 以及可用时的租户/站点上下文。
- 应用日志级别 **必须** 可按环境配置。
- 通过 `X-Trace-Id` 传播的 Trace ID **必须** 写入日志，并在各服务之间保持可关联性。

## 4. 指标与健康检查

- 服务 **应当** 暴露与选用监控体系兼容的指标端点。
- 在编排环境中，存活探针与就绪探针 **必须** 实现并正确配置。
- 后端提供两个语义**不同**的探针端点（`app\controller\HealthController`）：

  | 端点 | 语义 | 依赖面 | 失败时该怎么办 |
  | --- | --- | --- | --- |
  | `GET /healthz` | **liveness**：worker 还能不能把请求处理完 | 0 个（不查库、不读缓存、不鉴权） | **重启**容器 |
  | `GET /readyz` | **readiness**：运行时依赖是否可用 | 数据库 + Redis + 配置 | **摘流量**，不要重启 |

  - 两者都返回统一响应结构 + `Cache-Control: no-store`，都无鉴权、幂等、无副作用；
  - 两者都由全局中间件 `app\middleware\HealthProbeBypass` 在 `SessionInit` **之前**短路，
    因此 **Redis 抖动不会把 liveness 打成 500**（否则 K8s 会把全部实例一起重启，
    把局部故障放大成全局故障）。是否真的命中了旁路，看响应头 `X-Health-Probe-Bypass`
    （值为 `healthz` / `readyz`）——没有这个头说明请求走的是路由兜底，免疫性**未生效**；
  - `/readyz` 失败返回 **503**，响应体逐项列出 `checks.{database,redis,config}` 的
    `ok` / `latency_ms` / `error`，各项**独立超时（2s）、互不阻塞**，可以一次看全所有失败项；
  - `/readyz` 的 `config` 检查包含两条 fail-closed 前提：`DEFAULT_TENANT_ID` 已配置且为正整数
    （ER-04），生产类环境下 `APP_DEBUG=false`；
  - `Dockerfile` 的 `HEALTHCHECK` 与 `docker-compose.yml` 的 backend/nginx healthcheck 用的都是
    `/healthz`（compose 没有 readiness 概念）；K8s / Swarm 下的探针配置见
    [镜像与编排的生产要求](./image-and-orchestration-production-requirements.md) §6.1。
- 关键业务指标与错误率 **应当** 配置告警规则。

## 5. 缓存、会话与队列

- 缓存、会话和队列（在适用场景下）**必须** 统一使用 Redis（或等价方案）作为后端。
- 针对租户级数据的缓存键 **应当** 包含租户/站点信息。
- 队列消费者 **必须** 配置安全的重试与退避策略；应当 定义异常消息的处理方案（例如死信队列）。

## 6. 部署流程

- 生产环境部署 **必须** 通过 CI/CD 实现自动化且可复现。
- 每次部署 **应当** 关联到唯一的 Git 提交与构建产物。
- 回滚流程 **必须** 被文档化，并在非生产环境中定期演练。
- 容器层要求（镜像可重现构建、非 root 运行、健康检查、端口暴露面、凭据管理）见
  [镜像与编排的生产要求](./image-and-orchestration-production-requirements.md)。

## 7. 运维 Phase 模型

- **Phase 1（基线能力）**：
  - 已具备基础日志和环境配置；可以通过人工操作完成回滚。
- **Phase 2（目标能力）**：
  - 具备完整的可观测性（日志、指标、链路追踪）与告警；部署失败时具备自动回滚能力。

