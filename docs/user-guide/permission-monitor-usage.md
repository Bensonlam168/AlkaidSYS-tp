# 权限监控使用指南

## 概述

权限监控页面提供了权限服务的实时性能监控和缓存管理功能，帮助管理员了解系统权限查询的性能状况。

## 访问路径

**前端地址**: http://localhost:5666/#/admin/permission-monitor

在左侧菜单的"系统管理"分组下，点击"权限监控"菜单项。

## 功能介绍

### 1. 性能概览

监控页面顶部显示四个关键性能指标：

- **P95 延迟**: 95% 的权限查询响应时间
  - 目标值：< 100ms
  - 绿色表示达标，黄色表示超过阈值

- **缓存命中率**: 缓存命中的百分比
  - 目标值：> 90%
  - 绿色表示达标，黄色表示低于阈值

- **总查询数**: 系统启动以来的权限查询总数

- **平均延迟**: 所有权限查询的平均响应时间

### 2. 缓存统计

显示 L1 和 L2 两级缓存的详细统计信息：

- **命中率**: 缓存命中次数 / 总查询次数
- **TTL**: 缓存生存时间（秒）
- **命中次数**: 缓存命中的次数
- **未命中次数**: 缓存未命中的次数

**缓存层级说明**:
- L1 缓存：应用内存缓存（60s TTL），速度最快
- L2 缓存：Redis 缓存（300s TTL），可跨进程共享

### 3. 缓存管理

提供缓存管理功能：

- **清除所有缓存**: 清除所有用户的权限缓存
- **刷新统计**: 重新加载最新的统计数据

### 4. 用户权限查询

提供用户权限查询工具：

- **用户ID输入框**: 输入要查询的用户ID
- **权限码输入框**: 输入要检查的权限码（如 `user:read`）

**操作按钮**:
- **查询权限**: 获取指定用户的所有权限列表
- **检查权限**: 检查指定用户是否具有特定权限

## 调用前置条件

> ⚠️ **本组接口全部需要鉴权**。`route/admin.php` 里 `v1/admin/permission-monitor` 路由组是
> 「逐条挂中间件」的：每条路由都带 `Auth`（校验 `Authorization: Bearer <token>`）
> + `Permission`（校验权限码）。
> 直接照抄下面任何一个裸的 `GET /v1/...` 都会得到 **401 / 2001** —— 这不是服务故障。

### 1. 先登录拿 access token

`POST /v1/auth/login` 是本组接口唯一不需要 token 的前置步骤（该路由本身没有鉴权中间件）：

```bash
# dev/local 的入口是 Nginx 网关（80），由它反代容器内的 Swoole（8080）
BASE=http://localhost

curl -s -X POST "$BASE/v1/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@alkaidsys.local","password":"<你的密码>"}'
```

响应（节选，真实字段见 `app/controller/AuthController::login()`）：

```json
{
  "code": 0,
  "message": "登录成功",
  "data": {
    "access_token": "eyJ0eXAiOiJKV1Qi...",
    "refresh_token": "eyJ0eXAiOiJKV1Qi...",
    "token_type": "Bearer",
    "expires_in": 7200,
    "user": { "id": 996667, "username": "admin" }
  },
  "timestamp": 1766050554
}
```

把返回的 `data.access_token` 记进环境变量，后面所有示例都复用它：

```bash
TOKEN=<粘贴上一步返回的 data.access_token>
```

access token 默认有效期 **2 小时**（`Infrastructure\Auth\JwtService` 的 `accessTtl = 7200`）。
过期后用 `POST /v1/auth/refresh` 换新 token，或重新登录。

> 登录失败的口径与其它接口不同：凭据错误返回的是 **HTTP 400 + `code: 401`**
> （`message: Invalid credentials`），不是 HTTP 401。别把「HTTP 400」当成参数写错了。

### 2. 每个请求都要带 Authorization 头

```bash
-H "Authorization: Bearer $TOKEN"
```

下面的示例**逐条**写全了这个头（而不是靠一句「请自行补上」），可以直接复制执行。

### 3. 权限码要求

| 方法 | 路径 | 所需权限码 |
| --- | --- | --- |
| GET | `/v1/admin/permission-monitor/cache-stats` | `permission.read` |
| GET | `/v1/admin/permission-monitor/performance` | `permission.read` |
| GET | `/v1/admin/permission-monitor/user/{userId}/permissions` | `permission.read` |
| GET | `/v1/admin/permission-monitor/user/{userId}/check/{permission}` | `permission.read` |
| GET | `/v1/admin/permission-monitor/health` | `permission.read` |
| DELETE | `/v1/admin/permission-monitor/cache/user/{userId}` | `permission.write` |
| DELETE | `/v1/admin/permission-monitor/cache` | `permission.write` |

两条 DELETE 要的是 `permission.write` 而不是 `permission.delete`：`permission.delete` 的语义是
「删除权限记录」，清缓存是对权限子系统的**写**操作，不删除任何权限定义。

`permission.read` / `permission.write` 属于系统管理权限，由迁移
`database/migrations/20251218000001_add_tenant_and_role_management_permissions.php` 创建，
并由 `20251225000001_ensure_system_permissions.php` 保证存在（幂等补齐）与授予 admin 角色。

### 4. 跨租户约束

带 `{userId}` 的三个接口会**先校验目标用户属于当前请求租户**，再查权限
（`PermissionMonitorController::ensureUserInCurrentTenant()`）。
目标用户不存在**或**属于其它租户时统一返回 **HTTP 404 + `code: 404` + `message: 用户不存在`**
—— 刻意不区分两者，避免 403 / 404 的差异被用来探测其它租户的用户 ID。

### 5. 常见失败

| 现象 | HTTP | `code` | `message` | 处置 |
| --- | --- | --- | --- | --- |
| 没带 token / token 过期 / 签名不符 | 401 | 2001 | Token缺失、无效或已过期 | 回到第 1 步重新登录 |
| token 正常但缺权限码 | 403 | 2002 | 权限不足 | 给该用户所属角色补 `permission.read` 或 `permission.write` |
| `{userId}` 不存在或不属于当前租户 | 404 | 404 | 用户不存在 | 换成本租户内的用户 ID |
| `{userId}` 不是纯数字 | 404 | — | 路由不匹配 | `userId` 的路由规则是 `\d+`，只接受数字 |
| `check` 用点号形态（`user.read`） | 200 | 200 | success + `has_permission: false` | 权限码要用 `resource:action` 形态（见「检查用户权限」） |
| 登录凭据错误 | 400 | 401 | Invalid credentials | 重新确认邮箱与口令 |

## API 接口

### 获取缓存统计

> 所需权限：`permission.read`

```bash
curl -s "$BASE/v1/admin/permission-monitor/cache-stats" \
  -H "Authorization: Bearer $TOKEN"
```

**响应示例**:
```json
{
  "code": 200,
  "message": "success",
  "data": {
    "l1_cache": {
      "hits": 100,
      "misses": 10,
      "hit_rate": 90.91,
      "ttl": 60
    },
    "l2_cache": {
      "hits": 50,
      "misses": 60,
      "hit_rate": 45.45,
      "ttl": 300
    },
    "overall": {
      "total_queries": 110,
      "overall_hit_rate": 72.73,
      "avg_latency_ms": 5.2,
      "p95_latency_ms": 8.5
    }
  },
  "timestamp": 1766050554
}
```

### 获取性能指标

> 所需权限：`permission.read`

```bash
curl -s "$BASE/v1/admin/permission-monitor/performance" \
  -H "Authorization: Bearer $TOKEN"
```

**响应示例**:
```json
{
  "code": 200,
  "message": "success",
  "data": {
    "cache": {
      "enabled": true,
      "l1_cache": {
        "hit_rate": 90.91,
        "ttl": 60,
        "hits": 100,
        "misses": 10
      },
      "l2_cache": {
        "hit_rate": 45.45,
        "ttl": 300,
        "hits": 50,
        "misses": 60
      },
      "overall": {
        "hit_rate": 72.73,
        "total_queries": 110,
        "avg_latency_ms": 5.2,
        "p95_latency_ms": 8.5
      }
    },
    "targets": {
      "p95_latency_target_ms": 100,
      "concurrent_query_target": 1000,
      "hit_rate_target": 90
    },
    "status": {
      "p95_latency_ok": true,
      "hit_rate_ok": false
    }
  },
  "timestamp": 1766050559
}
```

### 清除指定用户的缓存

> 所需权限：`permission.write`

```bash
curl -s -X DELETE "$BASE/v1/admin/permission-monitor/cache/user/996667" \
  -H "Authorization: Bearer $TOKEN"
```

**响应示例**（实测）:

```json
{
  "code": 200,
  "message": "User cache cleared for user ID: 996667",
  "data": { "user_id": 996667 },
  "timestamp": 1793551880
}
```

只清该用户名下的权限缓存；`{userId}` 必须是**当前租户**的用户，否则 404（见「调用前置条件」第 4 节）。

### 清除所有缓存

> 所需权限：`permission.write`

```bash
curl -s -X DELETE "$BASE/v1/admin/permission-monitor/cache" \
  -H "Authorization: Bearer $TOKEN"
```

**响应示例**（实测；注意这条响应**没有 `data` 字段**）:

```json
{
  "code": 200,
  "message": "All permission caches cleared",
  "timestamp": 1793551880
}
```

影响面是**所有用户**的权限缓存（按 `l1:permission:` / `l2:permission:` / `permission:`
三个白名单前缀清除，不会 `FLUSHDB` 整个缓存库）。

### 获取用户权限

> 所需权限：`permission.read`

```bash
curl -s "$BASE/v1/admin/permission-monitor/user/996667/permissions" \
  -H "Authorization: Bearer $TOKEN"
```

**响应示例**（实测，权限码是 `resource:action` 形式）:

```json
{
  "code": 200,
  "message": "success",
  "data": {
    "user_id": 996667,
    "permissions": ["casbin:manage", "forms:view", "permission:read", "tenant:read", "user:read"],
    "count": 47
  },
  "timestamp": 1793551877
}
```

`permissions` 是该用户**所有角色**权限的并集，已按 `resource:action` 归一；`count` 与数组长度一致。
用户没有任何权限时返回 `"permissions": []` 且 `"count": 0`（200，不是 403）。

### 检查用户权限

> 所需权限：`permission.read`

```bash
curl -s "$BASE/v1/admin/permission-monitor/user/996667/check/permission:read" \
  -H "Authorization: Bearer $TOKEN"
```

`{permission}` 必须是 **`resource:action` 形态的对外权限码**（如 `user:read`、
`permission:read`），也就是前端「权限码输入框」里填的那种。

> ⚠️ 路由规则虽然是 `[\w\.\-:]+`（点号也能匹配上），但 `PermissionService::hasPermission()`
> 拿的是「用户权限集（已归一为 `resource:action`）」做**严格字符串比较**。
> 因此用点号形态 `permission.read` 请求会返回 **HTTP 200 + `has_permission: false`**
> —— 不是报错，而是静默错答。排查权限问题时务必确认用的是冒号形态。

传入不存在的权限码同样不会报错，而是返回 `has_permission: false`。

**响应示例**（实测）:

```json
{
  "code": 200,
  "message": "success",
  "data": {
    "user_id": 996667,
    "permission": "permission:read",
    "has_permission": true
  },
  "timestamp": 1793551877
}
```

### 健康检查

> 所需权限：`permission.read`（**该接口也需要鉴权**，无 token 时返回 401 / 2001）

```bash
curl -s -i "$BASE/v1/admin/permission-monitor/health" \
  -H "Authorization: Bearer $TOKEN"
```

**HTTP 状态码有两档**：

| 情形 | HTTP | 说明 |
| --- | --- | --- |
| 全部检查项 `ok` | 200 | 服务健康 |
| 任一检查项 `warning` | **206** | 例如缓存命中率为 0（空载时的常态） |

注意：206 时响应体的 `code` **仍是 200**，真正的健康结论在 `data.status`
（`ok` / `warning`）。另外这条响应**没有顶层 `timestamp`**，时间戳在 `data.timestamp` 里。

**响应示例**（实测，空载时 `hit_rate` 为 0 故为 `warning` + HTTP 206）:

```json
{
  "code": 200,
  "message": "success",
  "data": {
    "status": "warning",
    "timestamp": 1793551877,
    "services": { "permission_service": "ok", "cache_service": "ok" },
    "checks": {
      "p95_latency": { "status": "ok", "value": 0, "threshold": 100, "unit": "ms" },
      "hit_rate": { "status": "warning", "value": 0, "threshold": 90, "unit": "%" }
    }
  }
}
```

缓存关闭时（`PERMISSION_CACHE_ENABLED=false`），`services.cache_service` 为 `disabled`，
`checks` 为空数组，`status` 为 `ok`（HTTP 200）；此时 `performance` 接口会返回
HTTP 400 + `code: 400` + `message: Cache service not enabled`。

## 监控指标说明

### 延迟指标

- **P50**: 50% 的请求响应时间
- **P95**: 95% 的请求响应时间（最重要的性能指标）
- **P99**: 99% 的请求响应时间

### 缓存指标

- **命中率**: 缓存命中的比例，越高越好
  - 理想值：> 90%
  - 低于 80% 需要优化

- **TTL**: 缓存生存时间
  - L1: 60秒（短，用于快速访问）
  - L2: 300秒（长，用于持久化）

## 性能优化建议

### 1. 缓存命中率低

**原因**:
- TTL 设置过短
- 缓存频繁失效
- 用户权限频繁变更

**解决方案**:
- 适当延长 TTL
- 优化权限变更策略
- 使用缓存预热

### 2. P95 延迟高

**原因**:
- 数据库查询慢
- 缓存穿透
- 网络延迟

**解决方案**:
- 优化数据库索引
- 使用缓存预热
- 检查网络状况

### 3. 缓存失效风暴

**原因**:
- 大量用户同时登录
- 权限批量变更

**解决方案**:
- 使用随机过期时间
- 批量操作分批执行
- 启用缓存预热

## 故障排查

### 页面无法访问

1. 检查前端服务是否启动：`http://localhost:5666`
2. 检查菜单是否显示：确认当前用户有权限访问
3. 检查浏览器控制台是否有错误

### API 返回错误

先按状态码分流，不要一律当成「后端没起来」：

1. **401 / 2001**：没带 `Authorization` 头，或 token 已过期/签名不符 —— 重新登录（见「调用前置条件」第 1 步）；
2. **403 / 2002**：token 正常但账号缺 `permission.read` / `permission.write` —— 查该用户所属角色的授权；
3. **404 / 404**：`{userId}` 不存在或不属于当前租户；
4. 以上都不是，再检查服务本身：
   - 入口是 **Nginx 网关 `http://localhost`（80）**，由它反代容器内的 Swoole（8080）。
     `docker-compose.yml` 里映射的 9501 并没有接到 Swoole 的监听端口上，直接打
     `http://localhost:9501/...` 会得到 `Empty reply from server` —— 那是端口映射问题，不是服务挂了；
   - 查看 Nginx 日志：`/var/log/nginx/error.log`；
   - 查看应用日志：`runtime/log/`。

### 数据不更新

1. 点击"刷新统计"按钮
2. 检查缓存服务是否正常
3. 清除所有缓存重新加载

## 注意事项

1. **权限要求**: 访问监控页面需要 `permission.read`；清除缓存（两条 DELETE）需要 `permission.write`
2. **鉴权前提**: 所有接口都要 `Authorization: Bearer <token>`，登录见「调用前置条件」
3. **缓存清除**: 清除缓存后，首次查询可能会较慢
4. **生产环境**: 生产环境中建议设置监控告警
5. **定期检查**: 建议定期查看监控数据，及时发现性能问题

## 相关配置

### 缓存配置

文件：`config/permission.php`

```php
'cache' => [
    'enabled' => env('PERMISSION_CACHE_ENABLED', true),
    'l1_ttl' => (int) env('PERMISSION_CACHE_L1_TTL', 60),
    'l2_ttl' => (int) env('PERMISSION_CACHE_L2_TTL', 300),
],
```

### 性能目标

```php
'performance' => [
    'p95_latency_target_ms' => 100,
    'concurrent_query_target' => 1000,
    'hit_rate_target' => 90,
],
```
