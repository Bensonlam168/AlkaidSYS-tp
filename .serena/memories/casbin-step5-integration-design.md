# Casbin 步骤 5 集成设计方案

**任务**：集成 CasbinService 到 PermissionService  
**日期**：2025-01-24

## 现有 PermissionService 分析

### 现有方法

1. **getUserPermissions(int $userId): array**
   - 获取用户权限
   - 返回 resource:action 格式的权限码数组
   - 通过数据库查询实现

2. **hasPermission(int $userId, string $permissionCode): bool**
   - 检查用户是否有指定权限
   - 调用 getUserPermissions() 并检查权限码

3. **hasAnyPermission(int $userId, array $permissionCodes): bool**
   - 检查用户是否有任一权限
   - 调用 getUserPermissions() 并检查权限码

4. **hasAllPermissions(int $userId, array $permissionCodes): bool**
   - 检查用户是否有所有权限
   - 调用 getUserPermissions() 并检查权限码

### 现有实现特点

- ✅ 使用 UserRepository 获取角色（支持多租户）
- ✅ 通过数据库查询获取权限
- ✅ 返回 resource:action 格式的权限码
- ✅ 支持多租户隔离（通过 UserRepository）
- ⚠️ 没有缓存机制
- ⚠️ 每次调用都查询数据库

## 集成设计方案

### 1. 运行模式设计

**三种运行模式**：

1. **DB_ONLY**（默认）
   - 仅使用现有数据库查询
   - 向后兼容，不影响现有功能
   - 适用于未启用 Casbin 的环境

2. **CASBIN_ONLY**
   - 仅使用 Casbin 检查
   - 适用于完全迁移到 Casbin 后
   - 性能更好（Casbin 内部有优化）

3. **DUAL_MODE**
   - 同时使用数据库和 Casbin
   - 结果取并集（任一方式通过即通过）
   - 适用于迁移期，确保兼容性

### 2. 架构设计

**依赖注入**：
- 添加 CasbinService 作为可选依赖
- 通过构造函数注入
- 如果未注入，则使用 DB_ONLY 模式

**配置读取**：
- 从 config/casbin.php 读取配置
- 读取 enabled 和 mode 配置
- 支持环境变量覆盖

**租户上下文**：
- 从 UserRepository 获取用户的 tenant_id
- 传递给 CasbinService 进行多租户隔离

### 3. 方法修改设计

#### 3.1 构造函数

```php
public function __construct(?CasbinService $casbinService = null)
{
    $this->casbinService = $casbinService;
    $this->casbinEnabled = Config::get('casbin.enabled', false);
    $this->casbinMode = Config::get('casbin.mode', 'DB_ONLY');
}
```

#### 3.2 getUserPermissions() 修改

```php
public function getUserPermissions(int $userId): array
{
    // 根据模式选择实现
    switch ($this->casbinMode) {
        case 'DB_ONLY':
            return $this->getUserPermissionsFromDatabase($userId);
        
        case 'CASBIN_ONLY':
            return $this->getUserPermissionsFromCasbin($userId);
        
        case 'DUAL_MODE':
            $dbPermissions = $this->getUserPermissionsFromDatabase($userId);
            $casbinPermissions = $this->getUserPermissionsFromCasbin($userId);
            return $this->mergePermissions($dbPermissions, $casbinPermissions);
        
        default:
            return $this->getUserPermissionsFromDatabase($userId);
    }
}
```

#### 3.3 新增辅助方法

**getUserPermissionsFromDatabase()**：
- 将现有的 getUserPermissions() 逻辑移到这里
- 保持原有实现不变

**getUserPermissionsFromCasbin()**：
- 获取用户的 tenant_id
- 调用 CasbinService::getUserPermissions()
- 返回权限码数组

**mergePermissions()**：
- 合并两个权限数组
- 去重
- 返回合并后的数组

**getTenantId()**：
- 从 UserRepository 获取用户的 tenant_id
- 缓存结果（可选）

### 4. 向后兼容性保证

**API 签名不变**：
- 所有公共方法签名保持不变
- 返回值格式保持不变

**默认行为不变**：
- 默认使用 DB_ONLY 模式
- 如果 Casbin 未启用，自动降级到 DB_ONLY

**错误处理**：
- 如果 Casbin 调用失败，降级到数据库查询
- 记录错误日志

### 5. 性能优化

**缓存策略**：
- CasbinService 内部有策略刷新机制
- 数据库查询结果可以缓存（可选）

**懒加载**：
- CasbinService 只在需要时初始化
- 避免不必要的开销

### 6. 测试策略

**单元测试**：
- 测试每种模式的权限检查
- 测试模式切换
- 测试向后兼容性
- 测试错误处理

**集成测试**：
- 测试与 CasbinService 的集成
- 测试多租户隔离
- 测试权限结果一致性

## 实现步骤

1. ✅ 分析现有 PermissionService
2. ✅ 设计集成方案
3. ⏳ 修改 PermissionService 类
   - 添加 CasbinService 依赖注入
   - 添加配置读取
   - 重构 getUserPermissions()
   - 添加辅助方法
4. ⏳ 编写集成测试
5. ⏳ 运行所有测试
6. ⏳ 验证向后兼容性
7. ⏳ 代码审查
8. ⏳ 提交代码

## 风险与缓解

**风险 1：性能下降**
- 缓解：DUAL_MODE 只在迁移期使用，迁移后切换到 CASBIN_ONLY
- 缓解：添加缓存机制

**风险 2：权限结果不一致**
- 缓解：DUAL_MODE 取并集，确保不会丢失权限
- 缓解：添加详细的测试用例

**风险 3：向后兼容性问题**
- 缓解：默认使用 DB_ONLY 模式
- 缓解：保持 API 签名不变
- 缓解：添加向后兼容性测试

## 预期效果

**功能**：
- ✅ 支持三种运行模式
- ✅ 保持向后兼容
- ✅ 支持多租户隔离
- ✅ 灵活的权限检查

**性能**：
- ✅ CASBIN_ONLY 模式性能更好
- ✅ 支持策略刷新机制
- ✅ 减少数据库查询

**可维护性**：
- ✅ 代码结构清晰
- ✅ 易于测试
- ✅ 易于扩展
