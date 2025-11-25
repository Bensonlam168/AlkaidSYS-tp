# Casbin 授权引擎集成文档

[English](#english) | [中文](#中文)

---

## 中文

### 概述

本项目已集成 [Casbin](https://casbin.org/) 授权引擎，提供强大的访问控制功能。Casbin 是一个支持多种访问控制模型的授权库，本项目使用 **RBAC with Domains** 模型实现多租户权限隔离。

### 核心特性

- ✅ **多租户支持**：基于 Casbin Domains 实现租户级别隔离
- ✅ **三种运行模式**：DB_ONLY、CASBIN_ONLY、DUAL_MODE，灵活切换
- ✅ **向后兼容**：完全兼容现有 RBAC 系统，无需数据迁移
- ✅ **实时策略加载**：从现有数据库表动态加载权限策略
- ✅ **自动策略刷新**：可配置的策略刷新机制
- ✅ **高性能**：Casbin 内部优化，权限检查性能优异

### 快速开始

#### 1. 启用 Casbin

编辑 `.env` 文件：

```env
# 启用 Casbin
CASBIN_ENABLED=true

# 选择运行模式（DB_ONLY, CASBIN_ONLY, DUAL_MODE）
CASBIN_MODE=DB_ONLY

# 可选：启用日志
CASBIN_LOG_ENABLED=false

# 可选：配置缓存
CASBIN_CACHE_ENABLED=true
CASBIN_CACHE_TTL=3600

# 可选：配置策略刷新间隔（秒）
CASBIN_RELOAD_TTL=300
```

#### 2. 使用权限检查

Casbin 集成到 `PermissionService` 中，使用方式与之前完全相同：

```php
use Infrastructure\Permission\Service\PermissionService;

$permissionService = new PermissionService();

// 获取用户权限
$permissions = $permissionService->getUserPermissions($userId);
// 返回：['forms:view', 'forms:create', 'users:manage', ...]

// 检查单个权限
$hasPermission = $permissionService->hasPermission($userId, 'forms:view');
// 返回：true 或 false

// 检查任一权限
$hasAny = $permissionService->hasAnyPermission($userId, [
    'forms:view',
    'forms:create',
]);
// 返回：true（如果有任一权限）

// 检查所有权限
$hasAll = $permissionService->hasAllPermissions($userId, [
    'forms:view',
    'forms:create',
]);
// 返回：true（如果有所有权限）
```

### 运行模式

#### DB_ONLY（默认）

**说明**：仅使用数据库查询，不使用 Casbin。

**适用场景**：
- 未启用 Casbin 的环境
- 向后兼容模式
- 不需要 Casbin 功能的场景

**配置**：
```env
CASBIN_ENABLED=false
# 或
CASBIN_MODE=DB_ONLY
```

**特点**：
- ✅ 完全向后兼容
- ✅ 无性能影响
- ✅ 无需额外配置

#### CASBIN_ONLY

**说明**：仅使用 Casbin 检查权限，不查询数据库。

**适用场景**：
- 完全迁移到 Casbin 后
- 需要 Casbin 高性能的场景
- 生产环境推荐模式

**配置**：
```env
CASBIN_ENABLED=true
CASBIN_MODE=CASBIN_ONLY
```

**特点**：
- ✅ 性能最优
- ✅ Casbin 内部优化
- ✅ 策略刷新机制

#### DUAL_MODE

**说明**：同时使用数据库和 Casbin，结果取并集。

**适用场景**：
- 迁移期间
- 验证 Casbin 结果正确性
- 确保兼容性

**配置**：
```env
CASBIN_ENABLED=true
CASBIN_MODE=DUAL_MODE
```

**特点**：
- ✅ 双重保障
- ✅ 结果取并集（任一方式通过即通过）
- ⚠️ 性能略低（需要两次查询）

### 配置说明

详细配置说明请参考 [配置文档](configuration.md)。

#### 主要配置项

**config/casbin.php**：
```php
return [
    // Casbin 模型文件路径
    'model_path' => config_path() . 'casbin-model.conf',
    
    // 适配器配置
    'adapter' => [
        'type' => 'database',
        'class' => \Infrastructure\Permission\Casbin\DatabaseAdapter::class,
    ],
    
    // 运行模式
    'mode' => env('CASBIN_MODE', 'DB_ONLY'),
    
    // 启用状态
    'enabled' => env('CASBIN_ENABLED', false),
    
    // 策略刷新间隔（秒）
    'reload_ttl' => env('CASBIN_RELOAD_TTL', 300),
];
```

### 多租户支持

Casbin 使用 **Domains** 实现多租户隔离：

```
策略格式：
- 角色分配：g, user:{userId}, role:{roleId}, tenant:{tenantId}
- 权限分配：p, role:{roleId}, tenant:{tenantId}, {resource}, {action}

示例：
g, user:1, role:2, tenant:1
p, role:2, tenant:1, forms, view
```

**特点**：
- ✅ 租户级别隔离
- ✅ 跨租户访问自动拒绝
- ✅ 无需额外配置

### 常见问题（FAQ）

#### 1. 如何从 DB_ONLY 迁移到 CASBIN_ONLY？

**建议分阶段迁移**：

1. **阶段 1**：启用 DUAL_MODE
   ```env
   CASBIN_ENABLED=true
   CASBIN_MODE=DUAL_MODE
   ```
   - 验证权限结果一致性
   - 监控性能和错误日志

2. **阶段 2**：切换到 CASBIN_ONLY
   ```env
   CASBIN_MODE=CASBIN_ONLY
   ```
   - 继续监控
   - 准备回滚方案

详细迁移指南请参考 [迁移指南](migration-guide.md)。

#### 2. Casbin 会影响性能吗？

**不会，反而会提升性能**：

- ✅ CASBIN_ONLY 模式性能优于数据库查询
- ✅ Casbin 内部有优化和缓存机制
- ✅ 策略刷新间隔可配置，减少数据库查询

**性能对比**（参考值）：
- DB_ONLY：每次查询数据库（~10-50ms）
- CASBIN_ONLY：Casbin 内存检查（~1-5ms）
- DUAL_MODE：两次查询（~15-60ms）

#### 3. 如何手动刷新策略？

```php
use Infrastructure\Permission\Service\CasbinService;

$casbinService = new CasbinService();
$casbinService->reloadPolicy();
```

#### 4. 如何调试权限问题？

**启用日志**：
```env
CASBIN_LOG_ENABLED=true
```

**使用 DUAL_MODE 对比结果**：
```env
CASBIN_MODE=DUAL_MODE
```

**检查策略加载**：
```php
use Infrastructure\Permission\Service\CasbinService;

$casbinService = new CasbinService();
$permissions = $casbinService->getUserPermissions($userId, $tenantId);
var_dump($permissions);
```

#### 5. Casbin 支持哪些权限模型？

本项目使用 **RBAC with Domains** 模型，支持：
- ✅ 基于角色的访问控制（RBAC）
- ✅ 多租户隔离（Domains）
- ✅ 角色继承
- ✅ 动态策略加载

#### 6. 如何回滚到原有实现？

**方法 1**：禁用 Casbin
```env
CASBIN_ENABLED=false
```

**方法 2**：使用 DB_ONLY 模式
```env
CASBIN_MODE=DB_ONLY
```

**特点**：
- ✅ 无需代码修改
- ✅ 立即生效
- ✅ 完全向后兼容

### 相关文档

- [配置文档](configuration.md) - 详细的配置说明
- [迁移指南](migration-guide.md) - 从 DB_ONLY 迁移到 CASBIN_ONLY
- [Casbin 官方文档](https://casbin.org/) - Casbin 官方文档

### 技术支持

如有问题，请：
1. 查看 [常见问题](#常见问题faq)
2. 查看 [配置文档](configuration.md)
3. 查看 [故障排查指南](configuration.md#故障排查)
4. 提交 Issue

---

## English

### Overview

This project has integrated [Casbin](https://casbin.org/) authorization engine to provide powerful access control functionality. Casbin is an authorization library that supports multiple access control models. This project uses the **RBAC with Domains** model to implement multi-tenancy permission isolation.

### Core Features

- ✅ **Multi-tenancy Support**: Tenant-level isolation based on Casbin Domains
- ✅ **Three Running Modes**: DB_ONLY, CASBIN_ONLY, DUAL_MODE with flexible switching
- ✅ **Backward Compatible**: Fully compatible with existing RBAC system, no data migration required
- ✅ **Real-time Policy Loading**: Dynamically load permission policies from existing database tables
- ✅ **Automatic Policy Refresh**: Configurable policy refresh mechanism
- ✅ **High Performance**: Casbin internal optimization, excellent permission checking performance

### Quick Start

#### 1. Enable Casbin

Edit `.env` file:

```env
# Enable Casbin
CASBIN_ENABLED=true

# Choose running mode (DB_ONLY, CASBIN_ONLY, DUAL_MODE)
CASBIN_MODE=DB_ONLY

# Optional: Enable logging
CASBIN_LOG_ENABLED=false

# Optional: Configure cache
CASBIN_CACHE_ENABLED=true
CASBIN_CACHE_TTL=3600

# Optional: Configure policy reload interval (seconds)
CASBIN_RELOAD_TTL=300
```

#### 2. Use Permission Checking

Casbin is integrated into `PermissionService`, usage remains the same:

```php
use Infrastructure\Permission\Service\PermissionService;

$permissionService = new PermissionService();

// Get user permissions
$permissions = $permissionService->getUserPermissions($userId);
// Returns: ['forms:view', 'forms:create', 'users:manage', ...]

// Check single permission
$hasPermission = $permissionService->hasPermission($userId, 'forms:view');
// Returns: true or false

// Check any permission
$hasAny = $permissionService->hasAnyPermission($userId, [
    'forms:view',
    'forms:create',
]);
// Returns: true (if has any permission)

// Check all permissions
$hasAll = $permissionService->hasAllPermissions($userId, [
    'forms:view',
    'forms:create',
]);
// Returns: true (if has all permissions)
```

### Running Modes

See Chinese version above for detailed mode descriptions.

### Related Documentation

- [Configuration Guide](configuration.md) - Detailed configuration instructions
- [Migration Guide](migration-guide.md) - Migrate from DB_ONLY to CASBIN_ONLY
- [Casbin Official Documentation](https://casbin.org/) - Official Casbin documentation

---

**Version**: 1.0.0  
**Last Updated**: 2025-01-24

