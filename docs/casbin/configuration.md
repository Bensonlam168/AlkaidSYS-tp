# Casbin 配置说明

[English](#english) | [中文](#中文)

---

## 中文

### 配置文件

Casbin 集成涉及以下配置文件：

1. **config/casbin.php** - 主配置文件
2. **config/casbin-model.conf** - Casbin 模型文件
3. **.env** - 环境变量配置

### config/casbin.php

主配置文件，定义 Casbin 的核心配置。

#### 完整配置示例

```php
<?php

return [
    // Casbin 模型文件路径
    // Casbin model file path
    'model_path' => config_path() . 'casbin-model.conf',
    
    // 适配器配置
    // Adapter configuration
    'adapter' => [
        'type' => 'database',
        'class' => \Infrastructure\Permission\Casbin\DatabaseAdapter::class,
    ],
    
    // 日志配置
    // Log configuration
    'log_enabled' => env('CASBIN_LOG_ENABLED', false),
    
    // 缓存配置
    // Cache configuration
    'cache_enabled' => env('CASBIN_CACHE_ENABLED', true),
    'cache_ttl' => env('CASBIN_CACHE_TTL', 3600),
    
    // 策略刷新配置
    // Policy reload configuration
    'reload_ttl' => env('CASBIN_RELOAD_TTL', 300),
    
    // 运行模式
    // Running mode
    'mode' => env('CASBIN_MODE', 'DB_ONLY'),
    
    // 启用状态
    // Enable status
    'enabled' => env('CASBIN_ENABLED', false),
];
```

#### 配置项说明

##### model_path

**类型**：`string`  
**默认值**：`config_path() . 'casbin-model.conf'`  
**说明**：Casbin 模型文件的路径

**示例**：
```php
'model_path' => config_path() . 'casbin-model.conf',
```

##### adapter

**类型**：`array`  
**说明**：适配器配置，定义如何加载策略

**子配置项**：
- `type`：适配器类型（当前仅支持 `database`）
- `class`：适配器类名

**示例**：
```php
'adapter' => [
    'type' => 'database',
    'class' => \Infrastructure\Permission\Casbin\DatabaseAdapter::class,
],
```

##### log_enabled

**类型**：`boolean`  
**默认值**：`false`  
**环境变量**：`CASBIN_LOG_ENABLED`  
**说明**：是否启用 Casbin 日志

**示例**：
```php
'log_enabled' => env('CASBIN_LOG_ENABLED', false),
```

##### cache_enabled

**类型**：`boolean`  
**默认值**：`true`  
**环境变量**：`CASBIN_CACHE_ENABLED`  
**说明**：是否启用缓存（注意：Casbin PHP 版本无内置缓存，此配置预留）

##### cache_ttl

**类型**：`integer`  
**默认值**：`3600`（秒）  
**环境变量**：`CASBIN_CACHE_TTL`  
**说明**：缓存过期时间（秒）

##### reload_ttl

**类型**：`integer`  
**默认值**：`300`（秒）  
**环境变量**：`CASBIN_RELOAD_TTL`  
**说明**：策略自动刷新间隔（秒），设置为 `0` 禁用自动刷新

**建议值**：
- 开发环境：`60`（1 分钟）
- 测试环境：`300`（5 分钟）
- 生产环境：`600`（10 分钟）

##### mode

**类型**：`string`  
**默认值**：`DB_ONLY`  
**环境变量**：`CASBIN_MODE`  
**可选值**：`DB_ONLY`, `CASBIN_ONLY`, `DUAL_MODE`  
**说明**：运行模式

**模式说明**：
- `DB_ONLY`：仅使用数据库查询（默认，向后兼容）
- `CASBIN_ONLY`：仅使用 Casbin 检查（推荐）
- `DUAL_MODE`：同时使用两种方式，结果取并集（迁移期使用）

##### enabled

**类型**：`boolean`  
**默认值**：`false`  
**环境变量**：`CASBIN_ENABLED`  
**说明**：是否启用 Casbin

**注意**：
- 如果 `enabled = false`，则自动使用 `DB_ONLY` 模式
- 如果 `enabled = true` 且 `mode = DB_ONLY`，仍然使用数据库查询

### .env 环境变量

#### 完整配置示例

```env
# Casbin 授权引擎配置
# Casbin Authorization Engine Configuration

# 是否启用 Casbin（默认：false）
# Enable Casbin (default: false)
CASBIN_ENABLED=false

# 运行模式（默认：DB_ONLY）
# Running mode (default: DB_ONLY)
# 可选值 | Options: DB_ONLY, CASBIN_ONLY, DUAL_MODE
CASBIN_MODE=DB_ONLY

# 是否启用日志（默认：false）
# Enable logging (default: false)
CASBIN_LOG_ENABLED=false

# 是否启用缓存（默认：true）
# Enable cache (default: true)
CASBIN_CACHE_ENABLED=true

# 缓存过期时间（秒，默认：3600）
# Cache TTL in seconds (default: 3600)
CASBIN_CACHE_TTL=3600

# 策略刷新间隔（秒，默认：300）
# Policy reload interval in seconds (default: 300)
# 设置为 0 禁用自动刷新 | Set to 0 to disable auto reload
CASBIN_RELOAD_TTL=300
```

#### 环境变量说明

##### CASBIN_ENABLED

**类型**：`boolean`  
**默认值**：`false`  
**说明**：是否启用 Casbin

**示例**：
```env
# 启用 Casbin
CASBIN_ENABLED=true

# 禁用 Casbin（使用原有数据库查询）
CASBIN_ENABLED=false
```

##### CASBIN_MODE

**类型**：`string`  
**默认值**：`DB_ONLY`  
**可选值**：`DB_ONLY`, `CASBIN_ONLY`, `DUAL_MODE`

**使用场景**：

**开发环境**：
```env
CASBIN_ENABLED=true
CASBIN_MODE=DUAL_MODE
CASBIN_RELOAD_TTL=60
```

**测试环境**：
```env
CASBIN_ENABLED=true
CASBIN_MODE=DUAL_MODE
CASBIN_RELOAD_TTL=300
```

**生产环境**：
```env
CASBIN_ENABLED=true
CASBIN_MODE=CASBIN_ONLY
CASBIN_RELOAD_TTL=600
```

### config/casbin-model.conf

Casbin 模型文件，定义 RBAC with Domains 模型。

#### 模型内容

```ini
[request_definition]
r = sub, dom, obj, act

[policy_definition]
p = sub, dom, obj, act

[role_definition]
g = _, _, _

[policy_effect]
e = some(where (p.eft == allow))

[matchers]
m = g(r.sub, p.sub, r.dom) && r.dom == p.dom && r.obj == p.obj && r.act == p.act
```

#### 模型说明

##### request_definition

**格式**：`r = sub, dom, obj, act`

**参数说明**：
- `sub`：主体（subject），格式：`user:{userId}`
- `dom`：域（domain），格式：`tenant:{tenantId}`
- `obj`：对象（object），即资源（resource）
- `act`：操作（action）

**示例**：
```
r = user:1, tenant:1, forms, view
```

##### policy_definition

**格式**：`p = sub, dom, obj, act`

**说明**：定义权限策略的格式

**示例**：
```
p, role:2, tenant:1, forms, view
```

##### role_definition

**格式**：`g = _, _, _`

**说明**：定义角色继承关系，三个参数分别为：用户、角色、域

**示例**：
```
g, user:1, role:2, tenant:1
```

##### matchers

**格式**：`m = g(r.sub, p.sub, r.dom) && r.dom == p.dom && r.obj == p.obj && r.act == p.act`

**说明**：定义匹配规则

**匹配逻辑**：
1. `g(r.sub, p.sub, r.dom)`：检查用户是否有该角色（在指定域中）
2. `r.dom == p.dom`：检查域是否匹配（租户隔离）
3. `r.obj == p.obj`：检查资源是否匹配
4. `r.act == p.act`：检查操作是否匹配

### 配置最佳实践

#### 1. 开发环境

```env
CASBIN_ENABLED=true
CASBIN_MODE=DUAL_MODE
CASBIN_LOG_ENABLED=true
CASBIN_RELOAD_TTL=60
```

**特点**：
- ✅ 启用 DUAL_MODE 验证结果一致性
- ✅ 启用日志便于调试
- ✅ 短刷新间隔（1 分钟）

#### 2. 测试环境

```env
CASBIN_ENABLED=true
CASBIN_MODE=DUAL_MODE
CASBIN_LOG_ENABLED=false
CASBIN_RELOAD_TTL=300
```

**特点**：
- ✅ 使用 DUAL_MODE 确保兼容性
- ✅ 中等刷新间隔（5 分钟）

#### 3. 生产环境

```env
CASBIN_ENABLED=true
CASBIN_MODE=CASBIN_ONLY
CASBIN_LOG_ENABLED=false
CASBIN_RELOAD_TTL=600
```

**特点**：
- ✅ 使用 CASBIN_ONLY 获得最佳性能
- ✅ 较长刷新间隔（10 分钟）减少开销

### 故障排查

#### 问题 1：权限检查总是返回 false

**可能原因**：
1. Casbin 未正确启用
2. 策略未正确加载
3. 租户 ID 不匹配

**排查步骤**：

1. 检查配置：
```env
CASBIN_ENABLED=true
CASBIN_MODE=CASBIN_ONLY
```

2. 启用日志：
```env
CASBIN_LOG_ENABLED=true
```

3. 手动刷新策略：
```php
$casbinService = new CasbinService();
$casbinService->reloadPolicy();
```

4. 检查用户权限：
```php
$permissions = $casbinService->getUserPermissions($userId, $tenantId);
var_dump($permissions);
```

#### 问题 2：性能下降

**可能原因**：
1. 使用 DUAL_MODE
2. 策略刷新间隔过短

**解决方案**：

1. 切换到 CASBIN_ONLY：
```env
CASBIN_MODE=CASBIN_ONLY
```

2. 增加刷新间隔：
```env
CASBIN_RELOAD_TTL=600
```

#### 问题 3：跨租户访问未被拒绝

**可能原因**：
1. 租户 ID 未正确传递
2. 模型配置错误

**排查步骤**：

1. 检查模型文件中的 matchers：
```ini
m = g(r.sub, p.sub, r.dom) && r.dom == p.dom && r.obj == p.obj && r.act == p.act
```

2. 确保传递正确的租户 ID：
```php
$hasPermission = $casbinService->check($userId, $tenantId, $resource, $action);
```

### 相关文档

- [使用文档](README.md) - Casbin 集成使用指南
- [迁移指南](migration-guide.md) - 迁移步骤和注意事项

---

## English

See Chinese version above for detailed configuration instructions.

---

**Version**: 1.0.0  
**Last Updated**: 2025-01-24

