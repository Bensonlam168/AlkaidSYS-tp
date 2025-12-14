# CORS 配置指南 | CORS Configuration Guide

## 概述 | Overview

本文档详细说明 AlkaidSYS 项目的 CORS（跨域资源共享）配置方法，包括应用层和 Nginx 层的双重配置策略。

This document details the CORS (Cross-Origin Resource Sharing) configuration for AlkaidSYS project, including dual-layer configuration strategy at application and Nginx layers.

---

## 架构设计 | Architecture Design

### 双层 CORS 防护 | Dual-Layer CORS Protection

```
浏览器 Browser
    ↓
    ↓ OPTIONS 预检请求 | OPTIONS Preflight Request
    ↓
Nginx 层 CORS (快速响应 204)
Nginx Layer CORS (Fast 204 Response)
    ↓
    ↓ 实际请求 | Actual Request
    ↓
应用层 CORS 中间件 (添加 CORS 头)
Application Layer CORS Middleware (Add CORS Headers)
    ↓
后端业务逻辑
Backend Business Logic
```

### 设计优势 | Design Benefits

1. **性能优化** | Performance Optimization
   - Nginx 层直接响应 OPTIONS 预检请求，减轻应用层压力
   - Nginx layer directly responds to OPTIONS preflight requests, reducing application layer pressure

2. **双重保障** | Dual Protection
   - Nginx 层提供基础 CORS 保护
   - 应用层提供精细化 CORS 控制和日志记录
   - Nginx layer provides basic CORS protection
   - Application layer provides fine-grained CORS control and logging

3. **灵活配置** | Flexible Configuration
   - 支持环境变量动态配置
   - 支持 Nginx map 指令实现复杂规则
   - Supports dynamic configuration via environment variables
   - Supports complex rules via Nginx map directive

---

## 应用层配置 | Application Layer Configuration

### 1. 环境变量配置 | Environment Variable Configuration

在 `.env` 文件中配置 `CORS_ALLOWED_ORIGINS`：

Configure `CORS_ALLOWED_ORIGINS` in `.env` file:

```bash
# 开发环境 | Development Environment
CORS_ALLOWED_ORIGINS=http://localhost:5666,http://localhost:5173

# 测试环境 | Staging Environment
CORS_ALLOWED_ORIGINS=https://app.test.alkaidsys.com,https://admin.test.alkaidsys.com

# 生产环境 | Production Environment
CORS_ALLOWED_ORIGINS=https://app.alkaidsys.com,https://admin.alkaidsys.com
```

### 2. 配置规则 | Configuration Rules

#### 格式要求 | Format Requirements
- 多个源用逗号分隔，不要有空格
- Multiple origins separated by comma, no spaces
- 必须包含完整的协议和域名（如 `https://app.example.com`）
- Must include full protocol and domain (e.g., `https://app.example.com`)
- 不支持通配符子域名（如 `*.example.com`）
- Wildcard subdomains not supported (e.g., `*.example.com`)

#### 安全要求 | Security Requirements

**生产环境必须遵守 | Production MUST Follow:**

1. ✅ **明确配置允许的域名**
   - Explicitly configure allowed domains
   - 示例 | Example: `CORS_ALLOWED_ORIGINS=https://app.alkaidsys.com`

2. ❌ **禁止使用通配符 `*`**
   - DO NOT use wildcard `*`
   - 原因 | Reason: 与 `Access-Control-Allow-Credentials: true` 不兼容

3. ❌ **禁止使用 localhost**
   - DO NOT use localhost in production
   - 原因 | Reason: 安全风险，会触发配置警告日志

4. ✅ **使用 HTTPS 协议**
   - Use HTTPS protocol
   - 示例 | Example: `https://` not `http://`

### 3. 默认行为 | Default Behavior

如果 `CORS_ALLOWED_ORIGINS` 未配置或为空：

If `CORS_ALLOWED_ORIGINS` is not configured or empty:

- **开发环境** | Development: 允许所有 localhost 端口（5666, 5173, 5555, 5556, 5557）
- **生产环境** | Production: 记录警告日志，仍使用默认 localhost 列表（不推荐）

### 4. 配置验证 | Configuration Validation

应用层 CORS 中间件会在生产环境自动验证配置：

Application layer CORS middleware automatically validates configuration in production:

- ✅ 检查是否使用 localhost 源
- ✅ 检查是否未配置 CORS_ALLOWED_ORIGINS
- ✅ 记录配置警告到 `runtime/log/cors/cors-YYYYMMDD.log`

---

## Nginx 层配置 | Nginx Layer Configuration

### 1. 基础配置 | Basic Configuration

Nginx 配置文件：`deploy/nginx/alkaid.api.conf`

已包含默认的 CORS map 配置，允许所有 localhost 源（开发环境）。

Includes default CORS map configuration, allowing all localhost origins (development).

### 2. 生产环境配置 | Production Configuration

创建 `/etc/nginx/conf.d/cors_origins.conf` 文件：

Create `/etc/nginx/conf.d/cors_origins.conf` file:

```nginx
# 生产环境 CORS 源白名单
# Production CORS origin whitelist
map $http_origin $cors_origin {
    default "";
    
    # 主应用 | Main Application
    "~^https://app\.alkaidsys\.com$" $http_origin;
    
    # 管理后台 | Admin Panel
    "~^https://admin\.alkaidsys\.com$" $http_origin;
    
    # 移动端 | Mobile
    "~^https://m\.alkaidsys\.com$" $http_origin;
    
    # 支持子域名（可选）| Support subdomains (optional)
    # "~^https://[a-z0-9-]+\.alkaidsys\.com$" $http_origin;
}
```

### 3. 配置说明 | Configuration Notes

- `default ""`: 默认不允许任何源（安全）
- 使用正则表达式精确匹配允许的域名
- `$http_origin`: 返回请求的 Origin 头（动态）
- 支持多环境配置（通过不同的配置文件）

---

## 部署步骤 | Deployment Steps

### 开发环境 | Development Environment

1. 复制 `.env.example` 为 `.env`
2. 保持 `CORS_ALLOWED_ORIGINS` 为空（使用默认配置）
3. 启动应用

### 测试环境 | Staging Environment

1. 复制 `.env.production.example` 为 `.env`
2. 配置测试域名：
   ```bash
   CORS_ALLOWED_ORIGINS=https://app.test.alkaidsys.com,https://admin.test.alkaidsys.com
   ```
3. 创建 Nginx CORS 配置文件（可选）
4. 重启 Nginx 和应用

### 生产环境 | Production Environment

1. 复制 `.env.production.example` 为 `.env`
2. 配置生产域名：
   ```bash
   CORS_ALLOWED_ORIGINS=https://app.alkaidsys.com,https://admin.alkaidsys.com
   ```
3. 创建 `/etc/nginx/conf.d/cors_origins.conf`
4. 测试 Nginx 配置：`nginx -t`
5. 重新加载 Nginx：`nginx -s reload`
6. 重启应用

---

## 故障排查 | Troubleshooting

### 问题 1：CORS 错误仍然出现

**症状** | Symptom:
```
Access to XMLHttpRequest has been blocked by CORS policy
```

**排查步骤** | Troubleshooting Steps:

1. 检查 `.env` 文件中的 `CORS_ALLOWED_ORIGINS` 配置
2. 检查前端请求的 Origin 是否在白名单中
3. 查看 CORS 拒绝日志：`runtime/log/cors/cors-YYYYMMDD.log`
4. 检查 Nginx 配置是否正确加载

### 问题 2：生产环境配置警告

**症状** | Symptom:
```json
{
  "level": "WARNING",
  "event": "cors_config_warning",
  "message": "CORS configuration warning: Using localhost origins in production environment"
}
```

**解决方案** | Solution:
- 在 `.env` 中配置正确的生产域名
- 移除所有 localhost 相关的源

### 问题 3：OPTIONS 请求超时

**症状** | Symptom:
- OPTIONS 请求响应时间过长

**解决方案** | Solution:
- 确保 Nginx 层 CORS 配置正确
- Nginx 应直接返回 204，不应代理到后端

---

## 监控和日志 | Monitoring and Logging

### CORS 拒绝日志 | CORS Rejection Logs

位置 | Location: `runtime/log/cors/cors-YYYYMMDD.log`

格式 | Format:
```json
{
  "timestamp": "2025-11-25T10:30:00+00:00",
  "env": "production",
  "trace_id": "abc123...",
  "event": "cors_rejected",
  "origin": "https://unknown.example.com",
  "method": "POST",
  "path": "/v1/auth/login",
  "client_ip": "1.2.3.4",
  "allowed_origins": ["https://app.alkaidsys.com"]
}
```

详细监控指南请参考：[CORS 监控文档](../operations/cors-monitoring.md)

---

## 最佳实践 | Best Practices

1. ✅ 使用环境变量管理 CORS 配置
2. ✅ 生产环境必须明确配置允许的域名
3. ✅ 使用 Nginx 层处理 OPTIONS 预检请求
4. ✅ 定期检查 CORS 拒绝日志
5. ✅ 使用 HTTPS 协议
6. ❌ 不要在生产环境使用通配符 `*`
7. ❌ 不要在生产环境使用 localhost
8. ❌ 不要忽略配置警告日志

