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

## API 接口

### 获取缓存统计

```bash
GET /v1/admin/permission-monitor/cache-stats
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

```bash
GET /v1/admin/permission-monitor/performance
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

### 清除所有缓存

```bash
DELETE /v1/admin/permission-monitor/cache
```

### 获取用户权限

```bash
GET /v1/admin/permission-monitor/user/{userId}/permissions
```

### 检查用户权限

```bash
GET /v1/admin/permission-monitor/user/{userId}/check/{permission}
```

### 健康检查

```bash
GET /v1/admin/permission-monitor/health
```

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

1. 检查后端服务是否启动：`http://localhost:9501`
2. 查看 Nginx 日志：`/var/log/nginx/error.log`
3. 查看应用日志：`runtime/log/`

### 数据不更新

1. 点击"刷新统计"按钮
2. 检查缓存服务是否正常
3. 清除所有缓存重新加载

## 注意事项

1. **权限要求**: 访问监控页面需要管理员权限
2. **缓存清除**: 清除缓存后，首次查询可能会较慢
3. **生产环境**: 生产环境中建议设置监控告警
4. **定期检查**: 建议定期查看监控数据，及时发现性能问题

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
