# T-065 任务完成报告：修复 RateLimit 单元测试失败

**任务 ID**: T-065  
**完成日期**: 2025-12-14  
**执行者**: AI Assistant  
**CI 验证**: ✅ 通过 (Workflow Run ID: 20209046807)

---

## 一、任务概述

### 1.1 任务目标

修复 `tests/Unit/Infrastructure/RateLimit/RateLimitServiceTest.php` 中的 6 个失败测试用例，确保所有 RateLimit 单元测试通过。

### 1.2 失败的测试用例

| 测试方法 | 失败原因 |
|----------|----------|
| `test_tokens_refill_over_time` | 断言失败：期望 `false`，实际 `true` |
| `test_reset_bucket` | 断言失败：期望 `false`，实际 `true` |
| `test_high_cost_request` | 断言失败：期望 `false`，实际 `true` |
| `test_boundary_capacity_one` | 断言失败：期望 `false`，实际 `true` |
| `test_fractional_rate` | 断言失败：期望 `false`，实际 `true` |
| `test_deny_request_when_tokens_exhausted` | 断言失败：期望 `false`，实际 `true` |

---

## 二、问题分析

### 2.1 根本原因

`RateLimitService` 构造函数使用 `Cache::store()` 而未指定具体的 store，导致使用系统默认缓存驱动。

在开发环境中：
- `APP_ENV=dev`
- `CACHE_DRIVER` 未设置
- 默认缓存驱动为 `file`

**问题链**：
1. File 缓存驱动不支持 Redis 操作（Lua 脚本）
2. `getRedisHandler()` 方法返回 `null`
3. 触发 `failOpen()` 行为
4. 所有请求都被允许通过（返回 `true`）
5. 测试断言 `false` 失败

### 2.2 代码分析

原始代码：
```php
public function __construct(?string $store = null)
{
    $this->cache = Cache::store($store);  // 使用系统默认 store
}
```

当 `$store` 为 `null` 时，ThinkPHP 使用 `config('cache.default')` 作为默认 store，在开发环境中通常是 `file`。

---

## 三、解决方案

### 3.1 修改 RateLimitService 构造函数

**文件**: `infrastructure/RateLimit/Service/RateLimitService.php`

```php
public function __construct(?string $store = null)
{
    // Rate limiting must use Redis for atomic Lua script execution
    $this->cache = Cache::store($store ?? 'redis');
}
```

**理由**：限流服务必须使用 Redis 才能正确执行原子 Lua 脚本，确保 Token Bucket 算法的正确性。

### 3.2 更新测试基类 Redis 配置

**文件**: `tests/ThinkPHPTestCase.php`

添加 Redis 缓存 store 配置：

```php
'redis' => [
    'type' => 'Redis',
    'host' => getenv('REDIS_HOST') ?: 'redis',
    'port' => (int) (getenv('REDIS_PORT') ?: 6379),
    'password' => getenv('REDIS_PASSWORD') ?: '',
    'select' => (int) (getenv('REDIS_DB') ?: 0),
    'prefix' => 'alkaid:test:',
    'expire' => 0,
    'timeout' => 0,
],
```

### 3.3 改进测试隔离

**文件**: `tests/Unit/Infrastructure/RateLimit/RateLimitServiceTest.php`

1. 添加 `registerTestKey()` 辅助方法确保测试键被正确追踪
2. 在 `tearDown()` 中正确清理所有测试键
3. 移除未使用的 `Cache` facade 导入

---

## 四、测试结果

### 4.1 RateLimit 测试

| 指标 | 修复前 | 修复后 |
|------|--------|--------|
| 通过 | 4 | **10** |
| 失败 | 6 | **0** |
| 总计 | 10 | 10 |

### 4.2 Unit 测试套件

| 指标 | 修复前 | 修复后 |
|------|--------|--------|
| 通过 | 185 | **191** |
| 失败 | 6 | **0** |
| 总计 | 191 | 191 |

---

## 五、CI 验证

### 5.1 Workflow 执行结果

| Job | 状态 | 说明 |
|-----|------|------|
| PHP Code Style Check (PSR-12) | ✅ 成功 | 代码格式符合规范 |
| PHPUnit Tests | ✅ 成功 | 191 个测试全部通过 |

**Workflow Run ID**: 20209046807  
**Commit**: `43cdefb`  
**分支**: `feature/lowcode-t002-p1-tenantization`

---

## 六、变更文件清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `infrastructure/RateLimit/Service/RateLimitService.php` | 修改 | 构造函数默认使用 `redis` store |
| `tests/ThinkPHPTestCase.php` | 修改 | 添加 Redis 缓存 store 配置 |
| `tests/Unit/Infrastructure/RateLimit/RateLimitServiceTest.php` | 修改 | 改进测试隔离，添加 `registerTestKey()` |

---

## 七、Git 提交历史

```
commit 43cdefb
Author: AI Assistant
Date:   2025-12-14

    fix(ratelimit): fix RateLimitService to use redis store by default
    
    - RateLimitService now defaults to 'redis' store instead of system default
    - Add Redis cache configuration to ThinkPHPTestCase for test environment
    - Improve test isolation with registerTestKey() helper method
    - All 10 RateLimit tests now pass
    - All 191 Unit tests pass
    
    Fixes T-065
```

---

## 八、经验总结

### 8.1 问题教训

1. **缓存驱动依赖**：需要 Redis 特性的服务应明确指定 Redis store，不应依赖系统默认配置
2. **测试环境配置**：测试基类需要正确配置所有必要的基础设施（Redis、数据库等）
3. **Fail-Open 策略**：虽然 fail-open 是合理的容错策略，但会掩盖配置问题

### 8.2 最佳实践

1. 限流、分布式锁等需要原子操作的服务，必须使用 Redis
2. 测试应使用独立的 key 前缀，避免与生产数据冲突
3. 测试结束后应正确清理测试数据

---

## 九、后续建议

1. **监控告警**：考虑在 `failOpen()` 被触发时记录警告日志，便于发现配置问题
2. **配置验证**：在服务启动时验证 Redis 连接可用性
3. **文档更新**：在部署文档中明确 Redis 是限流服务的必要依赖

---

**报告生成时间**: 2025-12-14  
**项目健康度**: 87% (↑1%)

