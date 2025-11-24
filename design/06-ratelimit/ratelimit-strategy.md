# T2-RATELIMIT-LOG 设计草稿（初稿）

## 1. 文档目的与范围

本设计文档用于支撑 `docs/todo/refactoring-plan.md` 中 **T2-RATELIMIT-LOG：限流/熔断与访问日志** 任务，给出首版技术方案草稿，后续实现细节可在本文件基础上迭代。

- 目标环境：与 T2-NGINX-GW 一致，重点关注 stage / prod 等生产类环境，可在 dev/local 先做 PoC。
- 关联任务：
  - T2-NGINX-GW：Nginx 网关接入（统一流量入口）。
  - T3-TEST-COVERAGE：后续对限流和访问日志的测试保障。

## 2. 设计目标

1. **基础限流能力**：在流量异常升高时，保护后端核心服务不被压垮。
2. **访问日志统一化**：为所有 API 请求记录可观测的关键信息（trace-id / user / tenant / path / status / latency 等）。
3. **演进友好**：先实现简单可用的方案，后续可以平滑扩展为更复杂的策略（分级限流、熔断、灰度等）。

## 3. 总体方案概述

采用「Nginx 网关 + 应用层中间件」组合方案：

- **Nginx 层：基础限流与粗粒度保护**
  - 适合按 IP / 全局 QPS 的硬保护，例如防止单个 IP 在短时间内打爆接口。
  - 使用 `ngx_http_limit_req_module` / `ngx_http_limit_conn_module` 等内置功能。
- **应用层：细粒度限流与访问日志**
  - 结合业务身份（userId、tenantId、client_id 等），在 PHP 中实现更细粒度的限流策略。
  - 通过中间件记录结构化访问日志，写入文件或日志系统，为后续监控/告警铺路。

## 4. Nginx 层限流设计（草案）

> 本节仅给出配置思路，不在当前批次直接提交生效配置，可在后续 T2 实施时拆分为独立 `*.conf` 并通过 include 接入。

1. **按 IP 维度的 QPS 限制**（示例思路）：

   ```nginx
   # 在 http 块中定义 key 与共享内存区域
   limit_req_zone $binary_remote_addr zone=alkaid_ip_qps:10m rate=10r/s;

   # 在 API server/location 中应用限流
   location / {
       limit_req zone=alkaid_ip_qps burst=20 nodelay;
       # 其他 proxy_pass 等配置...
   }
   ```

2. **保护管理/敏感接口**：
   - 对 `/debug/*`、`/admin/*` 等路径设置更严格的 QPS 或并发数限制。
   - 如：`limit_req` + `limit_conn` 组合。

3. **限流日志与可观测性**：
   - 为被限流的请求输出特定状态码（如 429）和简洁 JSON 响应（由应用层统一处理）。
   - 在 access_log 中加入标记字段（如 `$sent_http_x_rate_limited` 或通过不同的 log_format），便于后续统计限流命中率。

## 5. 应用层限流设计（草案）

1. **实现位置**：
   - 新增 `RateLimit` 中间件，挂在 API 路由组前。
   - 复用 Redis 作为限流计数的存储（与 T1-CACHE-REDIS / T1-SESSION-REDIS 共享基础设施）。

2. **限流键设计**：
   - 基于以下因子组合生成 key：
     - `env`（stage/prod）
     - `tenantId` / `siteId`
     - `userId`（如已登录）
     - `client_ip`
     - `route_name` 或 `path` 前缀
   - 示例 key 结构：`rl:{env}:{route}:{tenantId}:{userId_or_ip}`。

3. **限流算法**：
   - 首版采用简单的「固定时间窗口 + 计数」或「滑动窗口」：
     - 如：`N` 秒内最多允许 `M` 次请求，超过则返回 429。
   - 后续如需更平滑的行为，可演进为令牌桶/漏桶算法，但不强制在首版实现。

4. **配置方式**：
   - 在配置文件中为关键路由定义限流策略，例如：
     - `/v1/auth/login`：每个 IP 或账号在 5 分钟内最多 10 次。
     - `/v1/lowcode/*`：每个租户在 1 秒内最多 50 次。
   - 支持简单的 YAML/数组配置，以便后续扩展。

## 6. 访问日志格式设计（草案）

### 6.1 Nginx 访问日志 log_format（建议）

- 自定义 `log_format alkaid_api`，字段包含：
  - 时间：`$time_iso8601`
  - 远端 IP：`$remote_addr`
  - 请求方法与 URI：`$request`
  - 状态码：`$status`
  - 响应时间：`$request_time`
  - 上游响应时间：`$upstream_response_time`
  - 请求 ID / trace-id：从 header 透传，如：`$http_x_request_id`
  - 用户/租户标识（如由应用侧通过 header 写回）：`$http_x_user_id`、`$http_x_tenant_id`

> 后续可在 `deploy/nginx/alkaid.api.conf` 或独立 `*.log.conf` 中定义实际 log_format。

### 6.2 应用层访问日志结构

- 在 PHP 中通过中间件记录 JSON 结构化日志，基础字段建议包括：
  - `timestamp`：ISO8601 时间戳
  - `env`：环境（dev/stage/prod）
  - `trace_id` / `request_id`
  - `user_id` / `tenant_id` / `site_id`
  - `method` / `path` / `query`
  - `status_code`
  - `response_time_ms`
  - `client_ip`
  - `user_agent`
  - `rate_limited`（bool，是否因限流被拒绝）

## 7. 与 refactoring-plan 的对应关系

- 对应任务：`T2-RATELIMIT-LOG`。
- 本文档完成后，可在 `docs/todo/refactoring-plan.md` 中标注：
  - 已完成限流与访问日志的前期调研与设计草稿；
  - 后续工作聚焦在：
    - 按本文档实现 Nginx 层与应用层限流配置；
    - 实现访问日志中间件与 Nginx log_format；
    - 在测试与 CI 中增加基础回归用例。

## 9. 应用层 RateLimit 最终实现说明（dev/local 首版）

- 中间件：`app/middleware/RateLimit.php`，在 `app/middleware.php` 中注册为全局中间件，顺序为：Trace → SessionInit → TenantIdentify → SiteIdentify → AccessLog → RateLimit（AccessLog 包裹 RateLimit，以便记录被限流请求）；
- 配置文件：`config/ratelimit.php`，统一定义 enabled/store/default/scopes/routes/whitelist 等参数：
  - scopes 支持 user/tenant/ip/route 多维度限流；
  - routes 允许为特定 path 前缀（如 `/v1/auth/login`、`/v1/lowcode/`）定义更严格的限流规则；
  - whitelist 支持 IP / 用户 / 租户白名单，白名单内请求不参与限流计数；
- 算法：固定时间窗口 + 计数（依赖 ThinkPHP Cache 抽象，prod-like 环境默认使用 Redis）：
  - key 结构：`rl:{env}:{scope}:{identifier}:{period}s`，如 `rl:prod:user:123:60s`；
  - 每次请求对各启用的 scope 增加计数，首次访问时设置 expire(period)；
  - 任一 scope 计数超过 limit 即视为命中限流，立即返回 429；
- 请求上下文与日志：
  - 在 `app/Request` 中新增 `setRateLimited()/isRateLimited()/getRateLimitInfo()` 方法，RateLimit 中间件在命中限流时写入 `{scope,key,limit,period,current,identifier}` 元信息；
  - AccessLog 中间件从 Request 读取 `rate_limited` 与 `rate_limit_*` 字段并写入访问日志，便于后续统计与排障；
- 错误处理与降级：
  - 所有 Cache/Redis 调用（包含 Cache::store/get/inc/handler()->expire）均包裹在 try/catch 中；
  - 如发生异常（网络中断、Redis 挂掉等），RateLimit 中间件记录 warning 日志并 **fail-open**：放行请求、将 Request 标记为 `rate_limited=false` 且 `reason=cache_error`；
- 响应规范：
  - 命中限流时返回 HTTP 429，响应体结构与统一 API 响应规范一致：`{ code, message, data, timestamp, trace_id }`；
  - 通过响应头返回附加信息：`Retry-After`、`X-RateLimit-Limit`、`X-RateLimit-Remaining`、`X-RateLimit-Reset`，便于客户端自适应退避；

## 10. Nginx JSON 访问日志最终形态（dev/local 首版）

- 在 `deploy/nginx/alkaid.api.conf` 的 upstream 定义之后，新增 `log_format alkaid_api_json`：
  - 使用 `escape=json` 输出单行 JSON，字段包括：
    - `timestamp`（`$time_iso8601`）、`env`（`$http_x_app_env`）、`trace_id`（`$http_x_trace_id`）；
    - 请求信息：`method`（`$request_method`）、`path`（`$uri`）、`query`（`$args`）、`status_code`（`$status`）；
    - 时延信息：`request_time`（`$request_time`）、`upstream_time`（`$upstream_response_time`）；
    - 上下文字段：`client_ip`（`$remote_addr`）、`user_agent`（`$http_user_agent`）、`user_id`（`$http_x_user_id`）、`tenant_id`（`$http_x_tenant_id`）、`site_id`（`$http_x_site_id`）、`rate_limited`（`$sent_http_x_rate_limited`）；
- HTTP(80) 与 HTTPS(443) server 的 access_log 均统一为：
  - `access_log /var/log/nginx/alkaid_api_access.log alkaid_api_json;`
- 运维验证示例（dev/local）：
  - 在 docker-compose 环境中：`docker-compose exec nginx tail -f /var/log/nginx/alkaid_api_access.log | jq '.'`；
  - 通过应用层 AccessLog 中的 trace_id / tenant_id / user_id 等字段，可与 Nginx JSON 日志进行交叉校验和链路追踪。

## 8. AccessLog 实现说明（应用层首版）

- 中间件位置：`app/middleware/AccessLog.php`，在 `app/middleware.php` 中注册为全局中间件，并放置在 `Trace` 之后。
- 行为概述：
  - 在进入下游中间件前记录请求开始时间；
  - 调用下游中间件与控制器（包含 Auth、TenantIdentify 等），待响应返回后计算耗时；
  - 从自定义 `app\Request` 对象和 Header 中提取 `trace_id`、`tenant_id`、`site_id`、`user_id`、`client_ip` 等上下文字段；
  - 将访问日志以 **JSON 单行** 格式写入 `runtime/log/access/access-YYYYMMDD.log`，每一行代表一次请求。
- 日志字段：
  - 与 6.2 节“应用层访问日志结构”中定义的字段保持一致，包括 `timestamp`、`env`、`trace_id`、`user_id`、`tenant_id`、`site_id`、`method`、`path`、`query`、`status_code`、`response_time_ms`、`client_ip`、`user_agent`、`rate_limited` 等；
  - 首版中 `rate_limited` 固定为 `false`，后续接入应用层限流中间件后再标记为 `true`。
- 环境与开关：
  - 通过环境变量 `ACCESS_LOG_ENABLED` 控制是否启用访问日志记录，默认开启；
  - 可在 dev/local 环境中用于调试日志格式，在 stage/prod 环境中作为正式访问日志来源。

