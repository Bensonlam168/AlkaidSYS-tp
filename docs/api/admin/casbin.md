# Casbin 管理 API 文档
# Casbin Admin API Documentation

## 概述 | Overview

Casbin 管理 API 提供了 Casbin 授权引擎的管理功能，包括手动刷新策略等。

Casbin Admin API provides management functions for Casbin authorization engine, including manual policy reload.

**基础路径 | Base Path**: `/v1/admin/casbin`

**认证要求 | Authentication**: 需要 JWT Access Token | Requires JWT Access Token

**权限要求 | Permission**: `casbin:manage`

---

## 手动刷新策略 | Manually Reload Policy

从数据库重新加载 Casbin 策略，用于策略更新后立即生效。

Reload Casbin policies from database for immediate effect after policy updates.

### 请求 | Request

**HTTP 方法 | HTTP Method**: `POST`

**路径 | Path**: `/v1/admin/casbin/reload-policy`

**请求头 | Headers**:

```http
Authorization: Bearer <access_token>
Content-Type: application/json
X-Trace-Id: <optional_trace_id>
```

**请求体 | Request Body**: 无 | None

### 响应 | Response

#### 成功响应 | Success Response

**HTTP 状态码 | HTTP Status Code**: `200 OK`

**响应体 | Response Body**:

```json
{
  "code": 0,
  "message": "Policy reloaded successfully",
  "data": {
    "execution_time_ms": 52.34,
    "timestamp": 1732550400,
    "trace_id": "casbin_reload_673a1b2c3d4e5"
  },
  "timestamp": 1732550400
}
```

**字段说明 | Field Description**:

| 字段 | 类型 | 说明 |
|------|------|------|
| `code` | int | 业务状态码，0 表示成功 |
| `message` | string | 响应消息 |
| `data.execution_time_ms` | float | 执行时间（毫秒） |
| `data.timestamp` | int | 刷新时间戳 |
| `data.trace_id` | string | 请求追踪 ID |
| `timestamp` | int | 响应时间戳 |

#### 错误响应 | Error Response

**HTTP 状态码 | HTTP Status Code**: `401 Unauthorized` / `403 Forbidden` / `500 Internal Server Error`

**响应体 | Response Body**:

```json
{
  "code": 2001,
  "message": "Unauthorized: Token is missing, invalid, or expired",
  "data": {
    "trace_id": "casbin_reload_error_673a1b2c3d4e5"
  },
  "timestamp": 1732550400
}
```

**错误码 | Error Codes**:

| 错误码 | HTTP 状态码 | 说明 |
|--------|-------------|------|
| 2001 | 401 | 未授权：Token 缺失、无效或过期 |
| 2002 | 403 | 权限不足：需要 `casbin:manage` 权限 |
| 5000 | 500 | 服务器内部错误：策略刷新失败 |

### 使用场景 | Use Cases

1. **角色权限关系更新后**：
   - 管理员修改了角色的权限配置
   - 需要立即生效，不等待自动刷新

2. **用户角色分配更新后**：
   - 管理员为用户分配或移除了角色
   - 需要立即生效，不等待自动刷新

3. **权限配置更新后**：
   - 管理员修改了权限定义
   - 需要立即生效，不等待自动刷新

### 限流保护 | Rate Limit

**限制 | Limit**: 10 次/分钟 | 10 requests per minute

**超出限制响应 | Rate Limit Exceeded Response**:

```json
{
  "code": 4290,
  "message": "Too many requests",
  "data": null,
  "timestamp": 1732550400
}
```

### 示例 | Examples

#### cURL 示例 | cURL Example

```bash
curl -X POST https://api.alkaidsys.local/v1/admin/casbin/reload-policy \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc..." \
  -H "Content-Type: application/json" \
  -H "X-Trace-Id: my-trace-id-123"
```

#### JavaScript 示例 | JavaScript Example

```javascript
const response = await fetch('https://api.alkaidsys.local/v1/admin/casbin/reload-policy', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${accessToken}`,
    'Content-Type': 'application/json',
    'X-Trace-Id': 'my-trace-id-123'
  }
});

const result = await response.json();
console.log(result);
```

#### Python 示例 | Python Example

```python
import requests

url = 'https://api.alkaidsys.local/v1/admin/casbin/reload-policy'
headers = {
    'Authorization': f'Bearer {access_token}',
    'Content-Type': 'application/json',
    'X-Trace-Id': 'my-trace-id-123'
}

response = requests.post(url, headers=headers)
result = response.json()
print(result)
```

### 审计日志 | Audit Log

每次手动刷新策略都会记录审计日志，包含以下信息：

Every manual policy reload is logged with the following information:

```json
{
  "level": "info",
  "message": "Casbin policy reloaded manually",
  "context": {
    "trace_id": "casbin_reload_673a1b2c3d4e5",
    "user_id": 1,
    "tenant_id": 1,
    "ip": "192.168.1.100",
    "execution_time_ms": 52.34,
    "timestamp": 1732550400
  }
}
```

### 注意事项 | Notes

1. **性能影响 | Performance Impact**:
   - 策略刷新会重新加载所有策略，可能需要 50-100ms
   - 建议在低峰期执行，避免影响用户体验
   - Policy reload reloads all policies, may take 50-100ms
   - Recommended to execute during off-peak hours to avoid affecting user experience

2. **频率限制 | Frequency Limit**:
   - 限流保护：10 次/分钟
   - 避免频繁刷新影响系统性能
   - Rate limit: 10 requests per minute
   - Avoid frequent reloads affecting system performance

3. **自动刷新 | Auto Reload**:
   - 系统默认每 5 分钟自动刷新一次策略
   - 手动刷新用于需要立即生效的场景
   - System auto-reloads policies every 5 minutes by default
   - Manual reload is for scenarios requiring immediate effect

4. **权限要求 | Permission Requirement**:
   - 只有具有 `casbin:manage` 权限的管理员可以调用此 API
   - 普通用户无法访问
   - Only administrators with `casbin:manage` permission can call this API
   - Regular users cannot access

---

**最后更新 | Last Updated**: 2025-11-25  
**文档版本 | Document Version**: v1.0.0  
**维护者 | Maintainer**: AlkaidSYS 架构团队 | AlkaidSYS Architecture Team

