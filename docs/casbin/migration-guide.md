# Casbin 迁移指南

[English](#english) | [中文](#中文)

---

## 中文

### 概述

本指南帮助您从传统的数据库查询方式（DB_ONLY）迁移到 Casbin 授权引擎（CASBIN_ONLY），实现更高性能的权限检查。

### 迁移策略

**推荐采用分阶段迁移**：

```
DB_ONLY → DUAL_MODE → CASBIN_ONLY
```

**优势**：
- ✅ 风险可控
- ✅ 可随时回滚
- ✅ 验证结果一致性
- ✅ 平滑过渡

### 迁移前准备

#### 1. 环境检查

**检查清单**：

- [ ] PHP 版本 >= 8.0
- [ ] Casbin 已安装（casbin/casbin ^3.0）
- [ ] 数据库连接正常
- [ ] 现有权限系统运行正常
- [ ] 备份数据库

**验证命令**：

```bash
# 检查 PHP 版本
php -v

# 检查 Casbin 安装
composer show casbin/casbin

# 运行现有测试
vendor/bin/phpunit tests/Unit/Infrastructure/Permission/
```

#### 2. 配置文件检查

**检查文件**：

- [ ] `config/casbin.php` 存在
- [ ] `config/casbin-model.conf` 存在
- [ ] `.env.example` 包含 Casbin 配置

**验证配置**：

```bash
# 检查配置文件
ls -la config/casbin.php
ls -la config/casbin-model.conf

# 检查环境变量示例
grep CASBIN .env.example
```

#### 3. 测试环境准备

**创建测试环境**：

```bash
# 复制生产环境配置
cp .env .env.test

# 编辑测试环境配置
vim .env.test
```

**测试环境配置**：

```env
CASBIN_ENABLED=true
CASBIN_MODE=DUAL_MODE
CASBIN_LOG_ENABLED=true
CASBIN_RELOAD_TTL=60
```

### 阶段 1：启用 DUAL_MODE

#### 1.1 配置 DUAL_MODE

**编辑 `.env` 文件**：

```env
# 启用 Casbin
CASBIN_ENABLED=true

# 使用 DUAL_MODE
CASBIN_MODE=DUAL_MODE

# 启用日志（便于调试）
CASBIN_LOG_ENABLED=true

# 短刷新间隔（便于测试）
CASBIN_RELOAD_TTL=60
```

#### 1.2 重启应用

```bash
# 清理缓存
php think clear

# 重启服务（根据实际情况）
# 如果使用 Docker
docker-compose restart backend

# 如果使用 Supervisor
supervisorctl restart all
```

#### 1.3 验证 DUAL_MODE

**测试权限检查**：

```php
use Infrastructure\Permission\Service\PermissionService;

$permissionService = new PermissionService();

// 测试用户权限
$userId = 1;
$permissions = $permissionService->getUserPermissions($userId);

// 应该返回权限数组
var_dump($permissions);

// 测试权限检查
$hasPermission = $permissionService->hasPermission($userId, 'forms:view');
var_dump($hasPermission); // 应该返回 true 或 false
```

**运行测试套件**：

```bash
# 运行所有权限相关测试
vendor/bin/phpunit tests/Unit/Infrastructure/Permission/

# 运行集成测试
vendor/bin/phpunit tests/Unit/Infrastructure/Permission/Service/PermissionServiceIntegrationTest.php
```

**预期结果**：
- ✅ 所有测试通过
- ✅ 权限检查结果正确
- ✅ 无错误日志

#### 1.4 监控和验证

**监控指标**：

1. **性能指标**：
   - 权限检查响应时间
   - 数据库查询次数
   - 内存使用

2. **错误日志**：
   - 检查应用日志
   - 检查 Casbin 日志（如果启用）

3. **业务指标**：
   - 用户登录成功率
   - 权限拒绝率
   - 功能访问正常性

**验证方法**：

```bash
# 查看应用日志
tail -f runtime/log/app.log

# 查看错误日志
tail -f runtime/log/error.log

# 监控性能
# 使用 APM 工具或自定义监控
```

**验证期限**：建议至少运行 **3-7 天**

### 阶段 2：切换到 CASBIN_ONLY

#### 2.1 前置条件

**确认以下条件满足**：

- [ ] DUAL_MODE 运行稳定（至少 3-7 天）
- [ ] 无权限相关错误
- [ ] 性能指标正常
- [ ] 业务功能正常

#### 2.2 配置 CASBIN_ONLY

**编辑 `.env` 文件**：

```env
# 保持启用 Casbin
CASBIN_ENABLED=true

# 切换到 CASBIN_ONLY
CASBIN_MODE=CASBIN_ONLY

# 可以关闭日志（生产环境）
CASBIN_LOG_ENABLED=false

# 增加刷新间隔（提升性能）
CASBIN_RELOAD_TTL=600
```

#### 2.3 灰度发布（推荐）

**方法 1：按环境灰度**

```
测试环境 → 预发布环境 → 生产环境
```

**方法 2：按用户灰度**

```php
// 示例：根据用户 ID 灰度
$userId = getCurrentUserId();

if ($userId % 10 < 3) {
    // 30% 用户使用 CASBIN_ONLY
    Config::set(['casbin.mode' => 'CASBIN_ONLY']);
} else {
    // 70% 用户使用 DUAL_MODE
    Config::set(['casbin.mode' => 'DUAL_MODE']);
}
```

#### 2.4 重启应用

```bash
# 清理缓存
php think clear

# 重启服务
docker-compose restart backend
# 或
supervisorctl restart all
```

#### 2.5 验证 CASBIN_ONLY

**运行测试**：

```bash
# 运行所有测试
vendor/bin/phpunit

# 运行权限相关测试
vendor/bin/phpunit tests/Unit/Infrastructure/Permission/
vendor/bin/phpunit tests/Feature/AuthPermissionIntegrationTest.php
```

**功能验证**：

1. 用户登录
2. 获取权限列表
3. 访问有权限的资源
4. 访问无权限的资源（应被拒绝）
5. 跨租户访问（应被拒绝）

**性能验证**：

```bash
# 使用 ab 或 wrk 进行压力测试
ab -n 1000 -c 10 http://your-domain/api/v1/auth/me

# 对比 DUAL_MODE 和 CASBIN_ONLY 的性能
```

**预期结果**：
- ✅ 所有测试通过
- ✅ 功能正常
- ✅ 性能提升（响应时间减少 20-50%）

### 回滚方案

#### 快速回滚到 DUAL_MODE

**编辑 `.env` 文件**：

```env
CASBIN_MODE=DUAL_MODE
```

**重启应用**：

```bash
php think clear
docker-compose restart backend
```

**验证**：

```bash
vendor/bin/phpunit tests/Unit/Infrastructure/Permission/
```

#### 完全回滚到 DB_ONLY

**编辑 `.env` 文件**：

```env
CASBIN_ENABLED=false
# 或
CASBIN_MODE=DB_ONLY
```

**重启应用**：

```bash
php think clear
docker-compose restart backend
```

**验证**：

```bash
vendor/bin/phpunit tests/Unit/Infrastructure/Permission/PermissionServiceTest.php
```

### 常见问题

#### 1. DUAL_MODE 下性能下降

**原因**：DUAL_MODE 需要同时查询数据库和 Casbin

**解决方案**：
- 这是正常现象，迁移到 CASBIN_ONLY 后性能会提升
- 如果性能下降严重，检查数据库查询优化

#### 2. 权限检查结果不一致

**原因**：数据库和 Casbin 策略不同步

**解决方案**：

```php
// 手动刷新策略
$casbinService = new CasbinService();
$casbinService->reloadPolicy();
```

**预防措施**：
- 减少 `CASBIN_RELOAD_TTL` 值
- 在权限变更后手动刷新策略

#### 3. 跨租户访问未被拒绝

**原因**：租户 ID 未正确传递

**解决方案**：
- 检查 `getUserPermissions()` 调用
- 确保传递正确的租户 ID
- 检查 Casbin 模型配置

### 迁移检查清单

#### 阶段 1：DUAL_MODE

- [ ] 配置 DUAL_MODE
- [ ] 重启应用
- [ ] 运行测试套件
- [ ] 验证权限检查
- [ ] 监控性能和错误
- [ ] 运行至少 3-7 天

#### 阶段 2：CASBIN_ONLY

- [ ] 确认 DUAL_MODE 稳定
- [ ] 配置 CASBIN_ONLY
- [ ] 灰度发布（可选）
- [ ] 重启应用
- [ ] 运行测试套件
- [ ] 验证功能和性能
- [ ] 监控生产环境

#### 回滚准备

- [ ] 准备回滚脚本
- [ ] 测试回滚流程
- [ ] 准备监控告警
- [ ] 准备应急联系人

### 最佳实践

1. **分阶段迁移**：不要跳过 DUAL_MODE 阶段
2. **充分测试**：在每个阶段运行完整的测试套件
3. **监控告警**：设置性能和错误监控告警
4. **灰度发布**：使用灰度发布降低风险
5. **准备回滚**：随时准备回滚到上一个稳定版本
6. **文档记录**：记录迁移过程和遇到的问题

### 相关文档

- [使用文档](README.md) - Casbin 集成使用指南
- [配置文档](configuration.md) - 详细的配置说明

---

## English

See Chinese version above for detailed migration guide.

---

**Version**: 1.0.0  
**Last Updated**: 2025-01-24

