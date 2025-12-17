# Casbin 授权引擎实施方案

**任务**：[P0] Casbin 授权引擎实施（Phase 2 目标架构）  
**日期**：2025-01-24  
**预估工时**：3-4 周  
**优先级**：P0

## 1. 任务背景

### 1.1 当前状态

**Phase 1（当前实现）**：
- 基于数据库直查的 RBAC 实现
- Permission 中间件使用 PermissionService
- PermissionService 查询 RBAC 表（users, roles, permissions, user_roles, role_permissions）
- 权限格式：内部 `resource.action`（slug），外部 `resource:action`（code）

**存在的问题**：
1. 权限检查逻辑分散在 SQL 查询中，难以维护
2. 缺乏灵活的策略管理能力
3. 不支持复杂的授权场景（如 ABAC）
4. 性能优化空间有限

### 1.2 目标状态

**Phase 2（目标架构）**：
- 使用 PHP-Casbin 作为授权引擎
- 支持 RBAC with Domains（多租户）
- 保持 PermissionService 作为唯一授权入口
- 向后兼容，平滑迁移

**预期收益**：
1. 更灵活的权限管理
2. 更好的性能（通过缓存）
3. 支持复杂授权场景
4. 符合行业最佳实践

## 2. 技术选型

### 2.1 Casbin 库选择

**选择**：casbin/php-casbin ^3.0

**理由**：
1. 官方 PHP 实现，功能完整
2. 文档齐全，社区活跃
3. 支持多种模型（RBAC, ABAC, ACL 等）
4. 性能优秀，支持缓存

**替代方案**：
- casbin/think-casbin：ThinkPHP 专用，但更新不及时
- 自研：成本高，不推荐

### 2.2 Adapter 选择

**Phase 1**：自定义 DatabaseAdapter（基于现有 RBAC 表）

**理由**：
1. 无需数据迁移
2. 保持数据一致性
3. 向后兼容
4. 可以通过缓存优化性能

**Phase 2（可选）**：迁移到 Casbin DatabaseAdapter（casbin_rule 表）

**理由**：
1. Casbin 原生支持
2. 性能更好
3. 功能更完整

### 2.3 集成方式

**选择**：在 PermissionService 内部集成 Casbin

**理由**：
1. 保持 API 不变
2. 控制器和中间件无需修改
3. 易于测试和回滚

**配置开关**：
- `CASBIN_ENABLED`：是否启用 Casbin
- `CASBIN_MODE`：运行模式
  - `DB_ONLY`：仅使用数据库 RBAC（Phase 1）
  - `CASBIN_ONLY`：仅使用 Casbin（Phase 2 目标）
  - `DUAL_MODE`：双模式运行，对比验证（过渡期）

## 3. 架构设计

### 3.1 Casbin 模型配置

**模型类型**：RBAC with Domains（多租户）

**模型文件**：`config/casbin-model.conf`

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

**说明**：
- `sub`：主体（user:{userId} 或 role:{roleId}）
- `dom`：域（tenant:{tenantId}）
- `obj`：对象（resource，如 forms）
- `act`：操作（action，如 view）
- `g`：角色继承关系

### 3.2 策略格式

**角色分配（g 策略）**：
```
g, user:{userId}, role:{roleId}, tenant:{tenantId}
```

**示例**：
```
g, user:1, role:2, tenant:1
g, user:2, role:3, tenant:1
g, user:3, role:2, tenant:2
```

**权限分配（p 策略）**：
```
p, role:{roleId}, tenant:{tenantId}, {resource}, {action}
```

**示例**：
```
p, role:2, tenant:1, forms, view
p, role:2, tenant:1, forms, create
p, role:3, tenant:1, users, manage
```

### 3.3 多租户隔离

**策略**：使用 Casbin Domains

**实现**：
1. 每个请求携带 tenant_id
2. Casbin 检查时传入 domain = tenant:{tenantId}
3. 策略中包含 domain 字段
4. Casbin 自动进行租户隔离

**示例**：
```php
// 检查用户 1 在租户 1 中是否有 forms:view 权限
$enforcer->enforce(
    'user:1',        // sub
    'tenant:1',      // dom
    'forms',         // obj
    'view'           // act
);
```

### 3.4 组件架构

```
┌─────────────────────────────────────────────────────────┐
│                    Permission Middleware                 │
│                  (app/middleware/Permission.php)         │
└────────────────────────┬────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│                    PermissionService                     │
│        (Infrastructure/Permission/Service/)              │
│                                                          │
│  ┌────────────────┐         ┌──────────────────┐       │
│  │   DB RBAC      │         │  Casbin Service  │       │
│  │  (Phase 1)     │◄───────►│   (Phase 2)      │       │
│  └────────────────┘         └──────────────────┘       │
│         │                            │                  │
│         │                            ▼                  │
│         │                   ┌──────────────────┐       │
│         │                   │ Casbin Enforcer  │       │
│         │                   └──────────────────┘       │
│         │                            │                  │
│         ▼                            ▼                  │
│  ┌────────────────────────────────────────────┐       │
│  │         Database Adapter                    │       │
│  │  (Infrastructure/Permission/Casbin/)        │       │
│  └────────────────────────────────────────────┘       │
└────────────────────────┬────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│                    RBAC Tables                           │
│  (users, roles, permissions, user_roles,                │
│   role_permissions)                                      │
└─────────────────────────────────────────────────────────┘
```

## 4. 实施步骤

### 4.1 步骤 1：安装 Casbin（0.5 天）

**任务**：
1. 使用 Composer 安装 casbin/php-casbin
2. 验证安装成功
3. 创建基础配置文件

**命令**：
```bash
docker exec -it alkaid-backend composer require casbin/php-casbin:^3.0
```

**验证**：
```php
use Casbin\Enforcer;
$enforcer = new Enforcer('model.conf', 'policy.csv');
```

### 4.2 步骤 2：创建 Casbin 配置（0.5 天）

**任务**：
1. 创建配置文件 `config/casbin.php`
2. 创建模型文件 `config/casbin-model.conf`
3. 添加环境变量配置

**文件**：
- `config/casbin.php`：Casbin 配置
- `config/casbin-model.conf`：RBAC with Domains 模型
- `.env`：添加 CASBIN_ENABLED, CASBIN_MODE

**配置项**：
```php
return [
    'model_path' => config_path() . 'casbin-model.conf',
    'adapter' => [
        'type' => 'database',
        'class' => \Infrastructure\Permission\Casbin\DatabaseAdapter::class,
    ],
    'log_enabled' => env('CASBIN_LOG_ENABLED', false),
    'cache_enabled' => env('CASBIN_CACHE_ENABLED', true),
    'cache_ttl' => env('CASBIN_CACHE_TTL', 3600),
];
```

### 4.3 步骤 3：实现自定义 DatabaseAdapter（2 天）

**任务**：
1. 创建 DatabaseAdapter 类
2. 实现 loadPolicy() 方法
3. 实现策略加载逻辑
4. 添加单元测试

**文件**：
- `Infrastructure/Permission/Casbin/DatabaseAdapter.php`
- `tests/Unit/Infrastructure/Permission/Casbin/DatabaseAdapterTest.php`

**核心方法**：
```php
class DatabaseAdapter implements Adapter
{
    public function loadPolicy(Model $model): void
    {
        // 1. 加载角色分配（g 策略）
        $this->loadRoleAssignments($model);
        
        // 2. 加载权限分配（p 策略）
        $this->loadPermissionAssignments($model);
    }
    
    protected function loadRoleAssignments(Model $model): void
    {
        // SELECT ur.user_id, ur.role_id, u.tenant_id
        // FROM user_roles ur
        // JOIN users u ON ur.user_id = u.id
        // JOIN roles r ON ur.role_id = r.id
        // WHERE u.tenant_id = r.tenant_id
        
        // 转换为：g, user:{user_id}, role:{role_id}, tenant:{tenant_id}
    }
    
    protected function loadPermissionAssignments(Model $model): void
    {
        // SELECT rp.role_id, r.tenant_id, p.resource, p.action
        // FROM role_permissions rp
        // JOIN roles r ON rp.role_id = r.id
        // JOIN permissions p ON rp.permission_id = p.id
        
        // 转换为：p, role:{role_id}, tenant:{tenant_id}, {resource}, {action}
    }
}
```

### 4.4 步骤 4：创建 CasbinService（1 天）

**任务**：
1. 创建 CasbinService 类
2. 初始化 Enforcer
3. 提供权限检查方法
4. 添加单元测试

**文件**：
- `Infrastructure/Permission/Service/CasbinService.php`
- `tests/Unit/Infrastructure/Permission/Service/CasbinServiceTest.php`

**核心方法**：
```php
class CasbinService
{
    protected Enforcer $enforcer;
    
    public function __construct()
    {
        $modelPath = config('casbin.model_path');
        $adapter = new DatabaseAdapter();
        $this->enforcer = new Enforcer($modelPath, $adapter);
        
        // 启用缓存
        if (config('casbin.cache_enabled')) {
            $this->enforcer->enableCache(true);
        }
    }
    
    public function check(int $userId, int $tenantId, string $resource, string $action): bool
    {
        return $this->enforcer->enforce(
            "user:{$userId}",
            "tenant:{$tenantId}",
            $resource,
            $action
        );
    }
    
    public function getUserPermissions(int $userId, int $tenantId): array
    {
        // 获取用户的所有权限
        $permissions = $this->enforcer->getImplicitPermissionsForUser(
            "user:{$userId}",
            "tenant:{$tenantId}"
        );
        
        // 转换为 resource:action 格式
        return $this->formatPermissions($permissions);
    }
}
```

### 4.5 步骤 5：集成到 PermissionService（2 天）

**任务**：
1. 修改 PermissionService
2. 添加 Casbin 支持
3. 实现三种运行模式
4. 添加集成测试

**文件**：
- `Infrastructure/Permission/Service/PermissionService.php`（修改）
- `tests/Feature/Permission/CasbinIntegrationTest.php`（新增）

**核心逻辑**：
```php
class PermissionService
{
    protected ?CasbinService $casbinService = null;
    
    public function __construct()
    {
        if (env('CASBIN_ENABLED', false)) {
            $this->casbinService = new CasbinService();
        }
    }
    
    public function hasPermission(int $userId, string $permissionCode): bool
    {
        $mode = env('CASBIN_MODE', 'DB_ONLY');
        
        switch ($mode) {
            case 'DB_ONLY':
                return $this->hasPermissionDB($userId, $permissionCode);
                
            case 'CASBIN_ONLY':
                return $this->hasPermissionCasbin($userId, $permissionCode);
                
            case 'DUAL_MODE':
                $dbResult = $this->hasPermissionDB($userId, $permissionCode);
                $casbinResult = $this->hasPermissionCasbin($userId, $permissionCode);
                
                // 记录差异
                if ($dbResult !== $casbinResult) {
                    Log::warning('Permission check mismatch', [
                        'user_id' => $userId,
                        'permission' => $permissionCode,
                        'db_result' => $dbResult,
                        'casbin_result' => $casbinResult,
                    ]);
                }
                
                // 使用 DB 结果（安全起见）
                return $dbResult;
                
            default:
                throw new \InvalidArgumentException("Invalid CASBIN_MODE: {$mode}");
        }
    }
    
    protected function hasPermissionDB(int $userId, string $permissionCode): bool
    {
        // 现有实现
        $userPermissions = $this->getUserPermissions($userId);
        return in_array($permissionCode, $userPermissions, true);
    }
    
    protected function hasPermissionCasbin(int $userId, string $permissionCode): bool
    {
        if (!$this->casbinService) {
            throw new \RuntimeException('Casbin is not enabled');
        }
        
        // 解析权限码：resource:action
        [$resource, $action] = explode(':', $permissionCode);
        
        // 获取租户 ID（从当前用户）
        $tenantId = $this->getUserTenantId($userId);
        
        // 使用 Casbin 检查
        return $this->casbinService->check($userId, $tenantId, $resource, $action);
    }
}
```

### 4.6 步骤 6：编写测试（3 天）

**任务**：
1. 单元测试：DatabaseAdapter
2. 单元测试：CasbinService
3. 集成测试：PermissionService with Casbin
4. 对比测试：DB RBAC vs Casbin
5. 多租户隔离测试

**测试文件**：
- `tests/Unit/Infrastructure/Permission/Casbin/DatabaseAdapterTest.php`
- `tests/Unit/Infrastructure/Permission/Service/CasbinServiceTest.php`
- `tests/Feature/Permission/CasbinIntegrationTest.php`
- `tests/Feature/Permission/CasbinDualModeTest.php`
- `tests/Feature/Permission/CasbinMultiTenantTest.php`

**测试覆盖**：
- ✅ 策略加载正确性
- ✅ 权限检查正确性
- ✅ 多租户隔离
- ✅ DB RBAC vs Casbin 一致性
- ✅ 性能测试
- ✅ 边界情况

### 4.7 步骤 7：文档和配置（1 天）

**任务**：
1. 更新技术文档
2. 添加 Casbin 使用指南
3. 提供配置示例
4. 编写迁移指南

**文档**：
- `docs/technical-specs/casbin-integration.md`：Casbin 集成文档
- `docs/guides/casbin-migration.md`：迁移指南
- `README.md`：更新配置说明

## 5. 数据迁移策略

### 5.1 策略加载

**方式**：实时加载（不创建新表）

**流程**：
1. 应用启动时，DatabaseAdapter 从 RBAC 表加载策略
2. 转换为 Casbin 策略格式
3. 加载到 Enforcer 内存中
4. 启用缓存，避免重复加载

**SQL 查询**：

**加载角色分配**：
```sql
SELECT 
    CONCAT('user:', ur.user_id) as sub,
    CONCAT('role:', ur.role_id) as role,
    CONCAT('tenant:', u.tenant_id) as domain
FROM user_roles ur
JOIN users u ON ur.user_id = u.id
JOIN roles r ON ur.role_id = r.id
WHERE u.tenant_id = r.tenant_id;
```

**加载权限分配**：
```sql
SELECT 
    CONCAT('role:', rp.role_id) as sub,
    CONCAT('tenant:', r.tenant_id) as domain,
    p.resource as obj,
    p.action as act
FROM role_permissions rp
JOIN roles r ON rp.role_id = r.id
JOIN permissions p ON rp.permission_id = p.id;
```

### 5.2 策略同步

**问题**：RBAC 表更新后，Casbin 策略未同步

**解决方案**：
1. **方案 A**：定时刷新（推荐）
   - 每隔一定时间（如 5 分钟）重新加载策略
   - 适用于策略变更不频繁的场景
   
2. **方案 B**：事件驱动
   - RBAC 表更新时触发事件
   - 监听事件，刷新 Casbin 策略
   - 适用于策略变更频繁的场景
   
3. **方案 C**：手动刷新
   - 提供 API 手动刷新策略
   - 适用于开发和测试环境

**实现**（方案 A + 方案 C）：
```php
// 定时刷新（在 CasbinService 中）
protected function shouldReload(): bool
{
    $lastReload = Cache::get('casbin_last_reload', 0);
    $ttl = config('casbin.reload_ttl', 300); // 5 分钟
    return (time() - $lastReload) > $ttl;
}

public function reloadPolicy(): void
{
    $this->enforcer->loadPolicy();
    Cache::set('casbin_last_reload', time());
}

// 手动刷新 API
// POST /api/v1/admin/casbin/reload
public function reload(Request $request): Response
{
    $casbinService = new CasbinService();
    $casbinService->reloadPolicy();
    
    return ResponseHelper::success([
        'message' => 'Casbin policy reloaded successfully',
    ]);
}
```

### 5.3 缓存策略

**Casbin 内置缓存**：
- 启用：`$enforcer->enableCache(true)`
- 缓存策略检查结果
- 自动失效（策略重新加载时）

**Redis 缓存**（可选）：
- 缓存策略数据
- 减少数据库查询
- 提高性能

## 6. 测试计划

### 6.1 单元测试

**DatabaseAdapter 测试**：
- ✅ 测试策略加载正确性
- ✅ 测试角色分配加载
- ✅ 测试权限分配加载
- ✅ 测试多租户数据隔离
- ✅ 测试边界情况（空数据、无效数据）

**CasbinService 测试**：
- ✅ 测试 Enforcer 初始化
- ✅ 测试权限检查
- ✅ 测试用户权限获取
- ✅ 测试策略刷新
- ✅ 测试缓存机制

### 6.2 集成测试

**PermissionService 集成测试**：
- ✅ 测试 DB_ONLY 模式
- ✅ 测试 CASBIN_ONLY 模式
- ✅ 测试 DUAL_MODE 模式
- ✅ 测试模式切换
- ✅ 测试错误处理

**多租户隔离测试**：
- ✅ 测试租户 A 用户无法访问租户 B 资源
- ✅ 测试跨租户角色分配失败
- ✅ 测试租户隔离边界

**对比测试**：
- ✅ 测试 DB RBAC 和 Casbin 结果一致性
- ✅ 测试大量权限场景
- ✅ 测试复杂角色继承

### 6.3 性能测试

**基准测试**：
- ✅ 测试权限检查性能（DB vs Casbin）
- ✅ 测试策略加载时间
- ✅ 测试缓存效果
- ✅ 测试并发性能

**目标**：
- 权限检查：< 10ms（99th percentile）
- 策略加载：< 1s（10000 条策略）
- 缓存命中率：> 95%

### 6.4 安全测试

**测试场景**：
- ✅ 测试未授权访问
- ✅ 测试权限提升攻击
- ✅ 测试跨租户访问
- ✅ 测试策略注入

## 7. 风险评估与缓解

### 7.1 风险矩阵

| 风险 | 影响 | 概率 | 等级 | 缓解措施 |
|------|------|------|------|----------|
| 性能问题 | 中等 | 高 | 中等 | 缓存、优化查询 |
| 策略不一致 | 高 | 中等 | 高 | DUAL_MODE 验证 |
| 多租户隔离失效 | 严重 | 低 | 高 | 严格测试 |
| 向后兼容性问题 | 高 | 低 | 中等 | 保持 API 不变 |
| 学习曲线 | 中等 | 中等 | 中等 | 文档和培训 |
| 依赖库问题 | 中等 | 低 | 低 | 使用稳定版本 |

### 7.2 缓解措施

**性能问题**：
1. 实现策略缓存（Redis）
2. 使用 Casbin 内置缓存
3. 优化策略加载查询
4. 监控性能指标

**策略不一致**：
1. 使用 DUAL_MODE 对比验证
2. 提供策略刷新 API
3. 监控策略一致性
4. 定期审计

**多租户隔离失效**：
1. 严格的单元测试和集成测试
2. 测试跨租户访问场景
3. 代码审查重点检查
4. 安全审计

**向后兼容性问题**：
1. 保持 PermissionService API 不变
2. 使用配置开关控制
3. 完整的回归测试
4. 分阶段推进

**学习曲线**：
1. 详细的文档和注释
2. 提供使用示例
3. 团队培训
4. 代码审查

**依赖库问题**：
1. 使用稳定版本（^3.0）
2. 充分测试
3. 监控社区反馈
4. 准备回滚方案

### 7.3 回滚策略

**回滚触发条件**：
1. 严重性能问题
2. 多租户隔离失效
3. 大量权限检查错误
4. 生产环境故障

**回滚步骤**：
1. 设置环境变量：`CASBIN_MODE=DB_ONLY`
2. 重启应用
3. 验证功能正常
4. 分析问题原因
5. 修复后重新部署

**回滚时间**：< 5 分钟

## 8. 实施时间表

### 8.1 总体时间表（3-4 周）

**第 1 周**：
- Day 1-2：安装 Casbin + 创建配置
- Day 3-5：实现 DatabaseAdapter

**第 2 周**：
- Day 1-2：创建 CasbinService
- Day 3-5：集成到 PermissionService

**第 3 周**：
- Day 1-3：编写单元测试
- Day 4-5：编写集成测试

**第 4 周**：
- Day 1-2：性能测试和优化
- Day 3-4：文档和配置
- Day 5：代码审查和提交

### 8.2 里程碑

- ✅ M1：Casbin 安装和配置完成（Day 2）
- ✅ M2：DatabaseAdapter 实现完成（Day 5）
- ✅ M3：CasbinService 实现完成（Day 7）
- ✅ M4：PermissionService 集成完成（Day 10）
- ✅ M5：测试完成（Day 15）
- ✅ M6：文档完成（Day 18）
- ✅ M7：代码审查通过（Day 20）

## 9. 验收标准

### 9.1 功能验收

- [ ] Casbin 成功集成到 PermissionService
- [ ] 支持三种运行模式（DB_ONLY, CASBIN_ONLY, DUAL_MODE）
- [ ] 多租户隔离正常工作
- [ ] 权限检查结果正确
- [ ] 策略加载和刷新正常

### 9.2 性能验收

- [ ] 权限检查性能 < 10ms（99th percentile）
- [ ] 策略加载时间 < 1s（10000 条策略）
- [ ] 缓存命中率 > 95%
- [ ] 无明显性能退化

### 9.3 质量验收

- [ ] 单元测试覆盖率 ≥ 90%
- [ ] 集成测试覆盖率 ≥ 80%
- [ ] 所有测试通过
- [ ] 代码审查评分 ≥ 90/100
- [ ] 无 P0/P1 问题

### 9.4 文档验收

- [ ] Casbin 集成文档完整
- [ ] 迁移指南清晰
- [ ] 配置示例完整
- [ ] API 文档更新

## 10. 后续优化

### 10.1 Phase 2.1（可选）

**迁移到 Casbin DatabaseAdapter**：
- 创建 casbin_rule 表
- 迁移数据到 casbin_rule
- 切换到 Casbin DatabaseAdapter
- 性能对比和优化

### 10.2 Phase 3（未来）

**支持 ABAC（Attribute-Based Access Control）**：
- 扩展 Casbin 模型
- 支持基于属性的权限控制
- 支持动态策略

**支持策略管理 UI**：
- 提供策略管理界面
- 可视化策略编辑
- 策略审计日志

## 11. 参考文档

- Casbin 官方文档：https://casbin.org/
- PHP Casbin 文档：https://github.com/php-casbin/php-casbin
- 安全设计文档：`design/04-security-performance/11-security-design.md`
- Backlog 文档：`docs/todo/development-backlog-2025-11-23.md`
- 权限集成方案：`permission-integration-complete-summary-2025-01-24`

## 12. 总结

本方案提供了完整的 Casbin 授权引擎实施计划，包括：
- ✅ 技术选型（casbin/php-casbin + 自定义 Adapter）
- ✅ 架构设计（RBAC with Domains）
- ✅ 实施步骤（7 个步骤，3-4 周）
- ✅ 数据迁移策略（实时加载）
- ✅ 测试计划（单元测试 + 集成测试 + 性能测试）
- ✅ 风险评估与缓解
- ✅ 验收标准

**关键特点**：
1. 向后兼容，平滑迁移
2. 支持多租户隔离
3. 提供三种运行模式
4. 完整的测试覆盖
5. 详细的文档和指南

**下一步**：
- 开始实施步骤 1：安装 Casbin
- 创建 Casbin 配置文件
- 实现 DatabaseAdapter
