# API 设计规范

## 1. 概述
本文档定义了 AlkaidSYS 项目的 API 设计规范，包括 RESTful 标准、响应格式、版本管理和限流策略。

## 2. RESTful API 标准

### 2.1 HTTP 方法使用
| 方法 | 用途 | 示例 |
|------|------|------|
| **GET** | 获取资源 | `GET /api/users` |
| **POST** | 创建资源 | `POST /api/users` |
| **PUT** | 完整更新资源 | `PUT /api/users/1` |
| **PATCH** | 部分更新资源 | `PATCH /api/users/1` |
| **DELETE** | 删除资源 | `DELETE /api/users/1` |

### 2.2 URL 设计规范
- 使用名词，避免动词（例如：`/api/users`，而非 `/api/getUsers`）
- 集合使用复数名词
- 使用层级关系表示关联（例如：`/api/users/123/orders`）

### 2.3 查询参数规范
- **分页**: `?page=1&page_size=20`
- **过滤**: `?status=1&role=admin`
- **排序**: `?sort=-created_at,+name`（- 表示降序，+ 表示升序）
- **字段筛选**: `?fields=id,name`
- **关联查询**: `?include=roles,permissions`

## 3. 统一响应格式

所有 API 响应必须遵循以下结构：

```json
{
  "code": 0,          // 业务状态码（0 = 成功）
  "message": "Success", // 人类可读的提示信息
  "data": { ... },    // 业务数据或 null
  "timestamp": 1705651200, // 服务器时间戳（秒）
  "trace_id": "abc..." // 可选的追踪 ID
}
```

### 3.1 响应结构规则

- **HTTP 状态码**：表示传输/语义层（200/4xx/5xx）
- **业务状态码** (`code`)：表示业务逻辑状态
  - `0` = 成功
  - 非零 = 失败（详见下方错误码矩阵）
- **消息**: 人类可读的文本（英文为主，前端可按需本地化）
- **数据**: 业务负载或 `null`
- **时间戳**: 服务器 Unix 时间戳（秒）  
- **追踪 ID**: 可选的请求追踪 ID，用于调试（由 Trace 中间件注入）

### 3.2 成功响应
```json
{
  "code": 0,
  "message": "操作成功",
  "data": { "id": 1, "name": "测试" },
  "timestamp": 1705651200
}
```

### 3.3 错误响应
```json
{
  "code": 422,
  "message": "验证失败",
  "data": {
    "errors": {
      "email": ["邮箱格式不正确"]
    }
  },
  "timestamp": 1705651200
}
```

### 3.4 分页响应
```json
{
  "code": 0,
  "message": "获取成功",
  "data": {
    "list": [...],
    "total": 100,
    "page": 1,
    "page_size": 20,
    "total_pages": 5
  },
  "timestamp": 1705651200
}
```

## 4. ApiController 标准方法

所有 API 控制器**必须**继承 `app\controller\ApiController`。以下标准方法可用：

- **`success(mixed $data = null, string $message = 'Success', int $code = 0, int $httpCode = 200)`**
  - 用途：业务成功响应
  - 默认值：`code = 0`，HTTP 200

- **`paginate(array $list, int $total, int $page, int $pageSize, string $message = 'Success')`**
  - 用途：统一分页响应封装
  - **标准响应结构**：`{ list, total, page, page_size, total_pages }`
  - **实现状态**：✅ 已完全对齐目标规范。
    - `ApiController::paginate` 返回 `{ list, total, page, page_size, total_pages }`，字段名统一使用 snake_case。
    - 所有新代码必须使用此 Helper，以确保所有 API 的分页响应结构一致。

- **`error(string $message, int $code = 400, array $errors = [], int $httpCode = 400)`**
  - 用途：业务失败响应
  - 如果提供 `$errors`，`data.errors` 将包含字段级错误信息

- **`validationError(array $errors, string $message = 'Validation failed')`**
  - 用途：表单/参数验证失败
  - 默认值：`code = 422`，HTTP 422

- **`notFound(string $message = 'Resource not found')`**
  - 用途：资源不存在
  - 默认值：`code = 404`，HTTP 404

- **`unauthorized(string $message = 'Unauthorized')`**
  - 用途：未认证
  - 默认值：`code = 401`，HTTP 401

- **`forbidden(string $message = 'Forbidden')`**
  - 用途：已认证但权限不足
  - 默认值：`code = 403`，HTTP 403

**强制要求：**
- 所有 API 控制器必须继承 `ApiController`
- 控制器禁止直接使用 `return json([...])` 
- 必须使用标准方法以保证响应一致性

## 5. 错误码矩阵

### 5.1 HTTP 状态码与业务码映射

| 场景 | HTTP 状态 | 业务码 | 描述 |
|------|----------|--------|------|
| 成功 | 200/201 | 0 | 操作成功 |
| 验证错误 | 422 | 422 | 输入验证失败 |
| 未授权 | 401 | 2001 | Token 缺失/无效/过期 |
| 禁止访问 | 403 | 2002 | 权限不足 |
| Refresh Token 无效 | 401 | 2003 | Refresh token 格式无效 |
| Refresh Token 过期 | 401 | 2004 | Refresh token 已过期 |
| Token Issuer 不匹配 | 401 | 2005 | Token issuer 不匹配 |
| 错误的 Token 类型 | 401 | 2006 | 在刷新接口使用了 Access token |
| Token 已撤销/重放 | 401 | 2007 | Refresh token 已撤销或重放 |
| 未找到 | 404 | 404 | 资源未找到 |
| 服务器错误 | 500 | 5000 | 内部服务器错误 |

> **参考**：完整错误码矩阵见 `design/04-security-performance/11-security-design.md`

### 5.2 错误码映射规则

- **安全 / 认证 / 授权相关错误码（2001–2007, 5000）**
  - 实现必须严格遵循 `design/04-security-performance/11-security-design.md` 中的错误码矩阵。
- **验证错误**
  - 请求参数验证失败必须使用业务码 `422`，HTTP 状态码为 422。
  - 控制器和中间件必须通过 `ApiController::validationError` / `error` 等统一 Helper 产生验证错误响应，不得自定义其他验证错误码。
- **HTTP 异常**
  - 当 `HttpException` 被渲染为 JSON 时，必须按 `code = statusCode * 10` 进行映射，同时保留原始 HTTP 状态码（例如：404 → 4040，405 → 4050）。
- **模型 / 数据未找到**
  - `ModelNotFoundException` / `DataNotFoundException` 必须映射为 HTTP 404、业务码 `4004`。
- **通用服务器错误**
  - 未被捕获的异常必须映射为 HTTP 500、业务码 `5000`。
- **客户端处理建议**
  - 客户端必须将 `code = 0` 视为成功，任意非零 `code` 视为失败，不应仅依赖 HTTP 状态码判断业务结果。
  - HTTP 状态码主要用于传输层语义（是否重试、是否被限流、是否未认证等），而业务分支和错误提示应主要依据响应体中的 `code` 字段。

## 6. Trace ID 机制

`trace_id` 字段用于端到端请求追踪：

- **生成方式**：
  - 优先使用客户端传入的 `X-Trace-Id` 请求头
  - 回退到 `X-Request-Id` 请求头
  - 如两者都不存在，通过 `bin2hex(random_bytes(16))` 生成新 ID
- **注入**：Trace 中间件将其注入到请求对象。
- **响应与传播行为**：
  - Trace 中间件必须始终在响应头中写入 `X-Trace-Id`。
  - 所有 JSON 错误响应（HTTP 4xx/5xx），无论由控制器、中间件还是全局异常处理器生成，必须在响应体中包含 `trace_id` 字段，其值需与 `X-Trace-Id` 响应头保持一致。
  - 成功响应可以在 JSON 体中省略 `trace_id` 字段，但仍然应当在响应头中包含 `X-Trace-Id` 以支持问题排查与链路关联。
- **客户端使用建议**：
  - 客户端应当在日志中记录 `trace_id`（可来自响应头或 JSON 体），并在错误上报与工单中附带该值，以方便后端排查。
- **用例**：审计日志、调试、分布式追踪

## 7. 版本管理

- **URL 版本控制**: `/api/v1/...`, `/api/v2/...`
- **Header 版本控制**: `Api-Version: v1`（通过中间件支持）

## 8. 限流策略

本项目的限流行为分为两个阶段定义，必须遵循 `docs/technical-specs/security/security-guidelines.zh-CN.md` 第 5.1 节中的约定。

- **Phase 1（基线要求——固定时间窗口实现）**：
  - 后端必须基于配置的缓存存储（通常为 Redis）实现按作用域的固定时间窗口计数。
  - 中间件必须至少支持 `user`、`tenant`、`ip`、`route` 等维度的限流，并允许通过配置为特定路由覆盖默认规则。
  - 当缓存出现故障时，中间件必须记录告警并降级为放行（fail-open），避免影响主业务链路可用性。
  - 命中限流时，接口必须返回 HTTP `429`，业务 `code = 429`。
  - 对于被限流的请求，JSON 响应体的 `data` 字段必须包含诊断字段（scope、limit、period、current、identifier），具体格式以安全规范为准。
  - 被限流时响应头中至少必须包含：
    - `Retry-After`：剩余惩罚窗口秒数；
    - `X-Rate-Limited`：命中限流时为 `"1"`；
    - `X-RateLimit-Scope`：触发限流的维度（如 `user`、`tenant`、`ip`、`route`）。

- **Phase 2（目标算法——基于 Redis 的令牌桶，必须落地）**：
  - API 限流的长期目标算法必须切换为基于 Redis 的令牌桶，以支持更平滑的突发流量削峰和更精细的限流策略。
  - 在 Phase 2 落地后，中间件应当补充标准限流响应头，例如：
    - `X-RateLimit-Limit`：当前窗口允许的最大请求数；
    - `X-RateLimit-Remaining`：当前窗口剩余请求数；
    - `X-RateLimit-Reset`：当前限流窗口的重置时间戳。
  - 令牌桶的具体参数（桶大小、补充速率、按路由定制策略等）可以按环境差异进行配置，但必须在设计文档与部署手册中明确记录。
  - 当 Phase 2 算法在生产环境生效时，本节必须同步更新，明确哪些限流响应头在所有被限流响应中为强制要求。

## 9. API 文档生成
- 使用 Swagger/OpenAPI 标准
- 通过控制器注解自动生成
- 生成命令：`php think api:doc`
- 文档校验：`npx redocly lint public/api-docs.json`
- TypeScript 类型生成：`npx openapi-typescript public/api-docs.json -o admin/src/api/types.d.ts`

## 10. 安全请求头（可选）

用于高安全级别接口（可选增强）：
- `X-App-Key`：应用密钥（平台分配）
- `X-Timestamp`：请求时间戳（±300秒容差）
- `X-Nonce`：唯一随机字符串（16+ 字节）
- `X-Signature`：HMAC-SHA256 签名
- `X-Signature-Algorithm`：签名算法（hmac-sha256/rsa-sha256/ed25519）

**签名字符串**：
```
plain = method + '|' + path_with_query + '|' + timestamp + '|' + nonce + '|' + body
signature = HMAC_SHA256(plain, app_secret)
```
