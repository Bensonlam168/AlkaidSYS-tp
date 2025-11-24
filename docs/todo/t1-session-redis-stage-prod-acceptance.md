# T1-SESSION-REDIS stage/prod 环境验收操作手册

## 1. 文档目的与适用范围

本手册用于指导运维团队在 **stage / prod 等生产类环境** 下，对 T1-SESSION-REDIS 的落地情况进行一次可审计的正式验收。

- 目标：
  - 确认 Session 存储已统一迁移到 Redis（`type=cache, store=redis`）；
  - 确认 Cache/Session 相关 Guard 与健康检查在生产类环境中工作正常；
  - 确认 `/debug/session-redis` 在受控条件下能够完成一次端到端验证。
- 适用人群：运维人员、SRE、负责后端部署的工程师。
- 参考文档：
  - `design/05-deployment-testing/14-deployment-guide.md`
  - `docs/todo/refactoring-plan.md` 中 T1-SESSION-REDIS 小节

## 2. 前置检查清单

在执行正式验收前，请依次完成以下检查：

### 2.1 环境变量配置检查

1. 确认目标环境为 **生产类环境**：
   - `APP_ENV` 应为以下值之一：`stage` / `staging` / `production` / `prod`。
2. 确认 Redis 连接配置：
   - `REDIS_HOST=<替换为实际值>`
   - `REDIS_PORT=<替换为实际值，一般为 6379>`
   - `REDIS_PASSWORD=<如启用密码则必填>`
   - `REDIS_DB=<替换为实际 DB 索引，通常为 0>`
3. 建议通过部署系统（Kubernetes / 容器编排 / 环境变量注入工具）统一管理上述配置，避免在代码中硬编码。

### 2.2 Redis 连通性检查

在 **应用所在网络环境** 中，用 redis-cli 对目标 Redis 实例做一次 PING：

```bash
redis-cli -h <REDIS_HOST> -p <REDIS_PORT> -a '<REDIS_PASSWORD>' PING
# 预期输出：PONG
```

如无法得到 `PONG`，请先排查网络连通性、防火墙/安全组、密码配置等问题，再继续后续步骤。

### 2.3 应用启动与 Guard 检查

1. 重启或启动应用服务（命令依具体部署方式而定，例如 systemd / Supervisor / Docker / Kubernetes）：
   - 示例（仅供参考）：`sudo systemctl restart alkaid-swoole`。
2. 确认服务 **能够正常启动**，日志中未出现以下错误关键字：
   - `Session configuration misconfigured`（SessionEnvironmentGuardService）
   - `Cache driver misconfigured`（CacheEnvironmentGuardService）
   - `Redis health check failed`（RedisHealthCheckService）
3. 如存在上述错误，说明当前配置不满足 T1-CACHE-REDIS / T1-SESSION-REDIS 的要求，应先按部署指南修正配置后再进行验收。

## 3. 验收步骤

### 3.1 stage 环境验收

**环境示例：** `https://api.stage.alkaidsys.com`（如与实际不符，请替换为真实 stage API 域名）。

1. **访问调试接口**

   使用浏览器或 curl 访问：

   ```bash
   curl -k -sS -D - \
     "https://api.stage.alkaidsys.com/debug/session-redis" | tee /tmp/session-redis-stage-response.json
   ```

2. **验证 HTTP 响应**

   - HTTP 状态码应为 **200**；
   - JSON 响应体中关键字段应满足：
     - `env` 为 `stage` 或 `staging`；
     - `session_config.type === 'cache'`；
     - `session_config.store === 'redis'`；
     - `cache_debug.write_ok === true`；
     - `written_value === read_value`（Session 读写一致性）。

3. **Redis 侧验证（方式 A：推荐）**

   在 stage 所使用的 Redis 实例上执行：

   ```bash
   # 通过 KEYS + GET 查看 Session key
   redis-cli -h <REDIS_HOST> -p <REDIS_PORT> -a '<REDIS_PASSWORD>' \
     KEYS "alkaid:session:*"

   # 从 /debug/session-redis 响应中获取实际 session_id，构造完整 key
   redis-cli -h <REDIS_HOST> -p <REDIS_PORT> -a '<REDIS_PASSWORD>' \
     GET "alkaid:session:<session_id>"
   ```

   - 预期：能看到至少一个 `alkaid:session:<session_id>` key，且其值包含调试字段（如 `t1_session_redis_test`）。

4. **Redis 侧验证（方式 B：MONITOR，可选）**

   在 **低压时段**，在同一 Redis 实例上执行（时间控制在 10–30 秒内）：

   ```bash
   redis-cli -h <REDIS_HOST> -p <REDIS_PORT> -a '<REDIS_PASSWORD>' MONITOR
   ```

   然后再次访问 `/debug/session-redis` 1～2 次，观察输出中是否出现：

   - `GET alkaid:session:<session_id>`
   - `SETEX alkaid:t1_session_redis_debug_cache_* ...`
   - `SETEX alkaid:session:<session_id> ...`

5. **记录验收结果**

   - 使用本文档末尾的“验收记录模板”记录：
     - 验收时间、执行人；
     - 使用的 Redis 实例（host:port/db）；
     - `/debug/session-redis` 关键字段；
     - Redis 观察结果（KEYS/GET 或 MONITOR 摘要）。

### 3.2 prod 环境验收

**环境示例：** `https://api.alkaidsys.com`（如与实际不符，请替换为真实 prod API 域名）。

> ⚠️ **重要：仅在业务低峰期执行，并确保仅在内网 / VPN / 受控 IP 范围内开放调试入口。**

1. **选择低压时段与访问边界**

   - 建议在凌晨或业务低峰期执行；
   - 仅允许通过内网、VPN 或跳板机访问 `/debug/session-redis`，避免对外暴露。

2. **临时开放 `/debug/session-redis` 接口**

   可通过以下任一方式限制访问：

   - 在网关/Nginx 层增加临时 location 规则，仅允许特定 IP；
   - 或通过应用配置/环境变量（如 `SESSION_REDIS_DEBUG_ENABLED=true`）临时启用调试路由（具体实现以实际代码为准，如未实现请采用网关层控制）。

3. **执行与 stage 相同的验收步骤**

   - 将所有 URL 中的 `api.stage.alkaidsys.com` 替换为生产域名；
   - 按 3.1 节步骤 1～4 完整执行一次；
   - 使用验收记录模板完整记录结果。

4. **关闭调试接口**

   - 验收完成后，立即：
     - 撤销网关/Nginx 临时配置；或
     - 将 `SESSION_REDIS_DEBUG_ENABLED` 之类的开关恢复为 `false`，并重新部署应用；
   - 确认外网无法再访问 `/debug/session-redis`。

## 4. 验收通过标准

当满足以下条件时，可认为 T1-SESSION-REDIS 在目标环境验收通过：

1. **服务启动与 Guard**
   - APP_ENV 为 stage/staging/production/prod；
   - 应用服务能够正常启动；
   - 日志中不存在 Cache/Session Guard 或 Redis 健康检查相关异常。

2. **HTTP 视角**
   - `/debug/session-redis` 返回 HTTP 200；
   - JSON 中：
     - `env` 与实际环境匹配；
     - `session_config.type === 'cache'`；
     - `session_config.store === 'redis'`；
     - `written_value === read_value`；
     - `cache_debug.write_ok === true` 且 `cache_debug.written === cache_debug.read_value`。

3. **Redis 视角**
   - 在对应 Redis 实例中能观测到 `alkaid:session:<session_id>` key；
   - 如使用 MONITOR，则能看到对应的 GET/SETEX 命令；
   - 未发现明显的连接错误或权限错误（如 `NOAUTH` / `WRONGPASS`）。

## 5. 异常处理指南

1. **服务启动失败或被 Guard 拦截**
   - 检查：
     - APP_ENV 是否为生产类环境；
     - `config/cache.php` 中默认 driver 是否为 `redis`；
     - `config/session.php` 是否为 `type=cache, store=redis`；
   - 根据日志中的报错信息（SessionEnvironmentGuardService / CacheEnvironmentGuardService / RedisHealthCheckService）修正配置后重试。

2. **`/debug/session-redis` 返回 500 或超时**
   - 检查应用错误日志，搜索以下关键字：
     - `RedisHealthCheckService`
     - `Connection refused` / `NOAUTH` / `WRONGPASS`
   - 排查 `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` / `REDIS_DB` 是否与实际 Redis 拓扑匹配，网络是否可达。

3. **接口返回成功但 Redis 中没有 Session key**
   - 确认连接的是与应用相同的 Redis 实例与 DB；
   - 检查：
     - `config/session.php` 与 `config/cache.php`；
     - `\think\middleware\SessionInit` 是否在当前环境的中间件栈中被启用；
   - 重新发起 `/debug/session-redis` 并立即在同一 Redis 实例上执行 KEYS/GET 验证。

## 6. 安全注意事项

> ⚠️ `/debug/session-redis` 仅用于联调与验收，不应在生产环境长期开放。

- 接口风险：
  - 响应中会包含当前 Session 的 `session_id` 以及内部调试字段；
  - 如未做访问控制，可能被外部扫描或滥用。
- MONITOR 风险：
  - 为阻塞式“全量命令监听”，在高 QPS 实例上可能造成 CPU / 网络 IO 与日志放大；
  - 建议仅在 stage 或低峰期短时间启用，并限制为单实例操作。
- 验收记录与审计：
  - 如需保留响应内容，请对 `session_id` 做部分脱敏（例如只保留前后 4 位）；
  - 不要在公共渠道粘贴完整的 Redis key 或敏感字段。

## 7. 验收记录模板

请为每个环境填写一行验收记录，可复制下表作为独立文档或表格使用：

| 环境 | 验收时间 | 执行人 | APP_ENV | Redis 实例 (host:port/db) | `/debug/session-redis` 关键字段摘要 | Redis 观察结果 (KEYS/GET/MONITOR 摘要) | 验收结论 (通过/失败/部分通过) | 备注 |
| ---- | -------- | ------ | ------- | --------------------------- | ----------------------------------- | --------------------------------------- | -------------------------------------- | ---- |
| stage |          |        |         |                             |                                     |                                         |                                        |      |
| prod  |          |        |         |                             |                                     |                                         |                                        |      |

