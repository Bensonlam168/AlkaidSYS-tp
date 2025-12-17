# AlkaidSYS 中间件与安全机制

## 中间件系统概述
AlkaidSYS 使用中间件模式处理请求的预处理和后处理，所有中间件位于 `app/middleware/` 目录。

## 核心中间件

### 1. Auth（身份认证中间件）
**文件**: `app/middleware/Auth.php`

**功能**:
- JWT Token验证
- 用户身份识别
- 登录状态检查
- Token刷新机制

**使用场景**:
- 需要登录才能访问的接口
- 用户信息获取
- 权限验证前置条件

**依赖服务**:
- `infrastructure/Auth/JwtService.php` - JWT服务

### 2. Permission（权限验证中间件）
**文件**: `app/middleware/Permission.php`

**功能**:
- RBAC权限检查
- 角色验证
- 资源访问控制
- 操作权限验证

**权限模型**:
- `domain/Permission/Model/Permission.php` - 权限模型
- `domain/Permission/Model/Role.php` - 角色模型

**验证流程**:
1. 获取当前用户
2. 获取用户角色
3. 检查角色权限
4. 验证资源访问权限
5. 允许或拒绝访问

### 3. TenantIdentify（租户识别中间件）
**文件**: `app/middleware/TenantIdentify.php`

**功能**:
- 从请求中识别租户
- 设置当前租户上下文
- 租户数据隔离
- 租户配置加载

**识别方式**:
- HTTP Header: `X-Tenant-Id`
- 子域名识别
- URL参数
- Token中的租户信息

**租户模型**:
- `domain/Tenant/Model/Tenant.php`
- `domain/Tenant/Interfaces/TenantInterface.php`
- `infrastructure/Tenant/Repository/TenantRepository.php`

**数据隔离**:
- 自动在查询中添加 `tenant_id` 条件
- 防止跨租户数据访问
- 租户级别的配置隔离

### 4. SiteIdentify（站点识别中间件）
**文件**: `app/middleware/SiteIdentify.php`

**功能**:
- 多站点支持
- 站点配置加载
- 站点级别的数据隔离
- 站点主题和样式切换

### 5. RateLimit（限流中间件）
**文件**: `app/middleware/RateLimit.php`

**功能**:
- API请求频率限制
- 防止恶意攻击
- 保护系统资源
- 支持多种限流策略

**限流策略**:
- 基于IP的限流
- 基于用户的限流
- 基于接口的限流
- 滑动窗口算法

**配置项**:
- 时间窗口（秒）
- 最大请求数
- 限流后的响应
- 白名单/黑名单

### 6. AccessLog（访问日志中间件）
**文件**: `app/middleware/AccessLog.php`

**功能**:
- 记录API访问日志
- 请求参数记录
- 响应时间统计
- 错误追踪

**记录内容**:
- 请求时间
- 请求方法
- 请求URL
- 请求参数
- 响应状态码
- 响应时间
- 用户信息
- IP地址
- User-Agent

### 7. Trace（链路追踪中间件）
**文件**: `app/middleware/Trace.php`

**功能**:
- 生成请求追踪ID
- 分布式链路追踪
- 性能监控
- 问题定位

**追踪信息**:
- Trace ID（全局唯一）
- Span ID（请求段标识）
- Parent Span ID（父请求标识）
- 时间戳
- 耗时统计

**集成点**:
- HTTP请求头：`X-Trace-Id`
- 日志系统
- 数据库操作
- 外部服务调用

## 安全机制

### 1. JWT认证
**服务**: `infrastructure/Auth/JwtService.php`

**功能**:
- Token生成
- Token验证
- Token刷新
- Token撤销

**Token结构**:
```json
{
  "user_id": 123,
  "tenant_id": 1,
  "roles": ["admin"],
  "exp": 1234567890,
  "iat": 1234567890
}
```

**安全特性**:
- 使用HS256/RS256算法签名
- Token过期时间控制
- Refresh Token机制
- Token黑名单

### 2. RBAC权限控制
**模型**:
- `domain/Permission/Model/Permission.php`
- `domain/Permission/Model/Role.php`

**权限结构**:
- 用户 -> 角色 -> 权限
- 支持角色继承
- 支持权限组合
- 支持动态权限

**权限类型**:
- 菜单权限
- 操作权限
- 数据权限
- 字段权限

### 3. 数据隔离
**多租户隔离**:
- 数据库级别隔离（独立数据库）
- 表级别隔离（共享数据库，tenant_id字段）
- 行级别隔离（RLS - Row Level Security）

**实现方式**:
- 中间件自动注入租户条件
- ORM模型全局作用域
- 数据库视图
- 触发器

### 4. DDL操作保护
**文件**: `infrastructure/Schema/SchemaBuilder.php`

**保护机制**:
- 环境检查（禁止生产环境直接DDL）
- 操作审计日志
- 操作权限验证
- 操作前确认

**安全检查**:
```php
assertCanRunDDL() {
    // 检查环境
    // 检查权限
    // 记录日志
    // 生成追踪ID
}
```

### 5. 输入验证
**验证器**:
- `domain/Validator/Interfaces/ValidatorGeneratorInterface.php`
- `infrastructure/Validator/JsonSchemaValidatorGenerator.php`

**验证层次**:
1. 请求参数验证（控制器层）
2. 业务规则验证（服务层）
3. 数据完整性验证（模型层）
4. 数据库约束验证（数据库层）

**验证类型**:
- 数据类型验证
- 格式验证（邮箱、手机号等）
- 长度验证
- 范围验证
- 自定义规则验证

### 6. SQL注入防护
**防护措施**:
- 使用参数化查询
- ORM框架封装
- 输入过滤和转义
- 预编译语句

### 7. XSS防护
**防护措施**:
- 输出转义
- Content-Security-Policy头
- HTML标签过滤
- 富文本编辑器安全配置

### 8. CSRF防护
**防护措施**:
- CSRF Token验证
- SameSite Cookie属性
- Referer检查
- 双重提交Cookie

## 中间件配置
**文件**: `app/middleware.php`

**全局中间件**:
```php
[
    Trace::class,           // 链路追踪
    AccessLog::class,       // 访问日志
    TenantIdentify::class,  // 租户识别
]
```

**路由中间件**:
```php
[
    'auth' => Auth::class,
    'permission' => Permission::class,
    'rate_limit' => RateLimit::class,
]
```

## 环境守护服务

### 1. CacheEnvironmentGuardService
**文件**: `app/provider/CacheEnvironmentGuardService.php`

**功能**:
- 缓存环境检查
- 缓存连接验证
- 缓存性能监控

### 2. SessionEnvironmentGuardService
**文件**: `app/provider/SessionEnvironmentGuardService.php`

**功能**:
- Session环境检查
- Session存储验证
- Session配置验证

### 3. RedisHealthCheckService
**文件**: `app/provider/RedisHealthCheckService.php`

**功能**:
- Redis连接健康检查
- Redis性能监控
- Redis故障告警

## 最佳实践

### 中间件使用
1. 按需加载中间件，避免全局中间件过多
2. 中间件顺序很重要（Trace -> Auth -> Permission）
3. 使用中间件组简化配置
4. 为敏感操作添加额外的中间件保护

### 安全配置
1. 定期更新JWT密钥
2. 设置合理的Token过期时间
3. 启用HTTPS
4. 配置CORS策略
5. 定期审计权限配置
6. 监控异常访问行为

### 性能优化
1. 使用缓存减少数据库查询
2. 限流保护高频接口
3. 异步处理日志记录
4. 优化中间件执行顺序
5. 使用连接池

### 监控告警
1. 监控限流触发频率
2. 监控认证失败次数
3. 监控DDL操作
4. 监控跨租户访问尝试
5. 设置异常告警阈值
