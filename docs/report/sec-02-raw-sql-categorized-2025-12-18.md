# SEC-02 原生 SQL 分类清单（业务/系统级）2025-12-18

> 基于脚本 `php scripts/check-rawsql-safety.php`（仅扫描 app/domain/infrastructure）输出的 74 处调用，按“业务数据访问”与“系统级/元数据/生成”分类，供 A2/A3 整改使用。

## A. 业务数据访问（需补租户/站点过滤或封装）

- **Tenant/User 基础**
  - `infrastructure/Tenant/Repository/TenantRepository.php` (id/slug 查询、更新、删除)
  - `infrastructure/User/Repository/UserRepository.php` (find/save/roles 关联)
  - `infrastructure/Tenant/TenantAwareDb.php`（应作为统一入口，建议替换散落的 Db::name/table 调用）

- **权限/Casbin**
  - `infrastructure/Permission/Service/PermissionService.php` (`role_permissions` / `permissions` join)
  - `infrastructure/Permission/Casbin/DatabaseAdapter.php`（user_roles / role_permissions 读取）

- **插件**
  - `infrastructure/Plugin/Repository/PluginPackageRepository.php`
  - `infrastructure/Plugin/Repository/PluginInstallationRepository.php`

- **低代码核心**
  - `infrastructure/Collection/CollectionManager.php`
  - `infrastructure/Lowcode/Collection/Repository/*`
  - `infrastructure/Lowcode/FormDesigner/Service/FormDataManager.php`
  - `infrastructure/Lowcode/FormDesigner/Repository/FormSchemaRepository.php`
  - `infrastructure/Lowcode/Schema/SchemaVersionService.php`
  - `infrastructure/Lowcode/Generator/ControllerGenerator.php`

- **应用**
  - `infrastructure/Application/ApplicationManager.php`
  - `infrastructure/Application/BaseApplication.php`（除 DDL 外的配置写入）

- **事件日志**
  - `domain/Event/EventLogger.php`（低代码事件日志写入）

## B. 系统级 / 元数据 / 生成（可保留但需审计说明）

- DDL/元数据：
  - `infrastructure/Schema/SchemaBuilder.php`（建表/表结构检查）
  - `app/command/lowcode/MigrationDiffCommand.php`（CLI 对比）
  - `infrastructure/Application/BaseApplication.php`（应用初始化 DDL 部分）

## 建议的整改顺序（A2/A3）

1) **公共封装**：在 TenantAwareDb 或基类里提供安全查询方法（强制 tenant_id/site_id），逐步替换业务层的 Db::table/name。
2) **权限/Casbin**：为 `role_permissions/user_roles/permissions` 查询加入租户过滤，或使用租户前缀缓存命名空间；补集成测试（跨租户查询应返回空）。
3) **低代码**：FormDataManager/Repositories 增加租户/站点条件，补跨租户 CRUD 集成测试。
4) **用户/租户仓储**：对 TenantRepository/UserRepository 操作添加租户作用域验证（防跨租户更新/删除）。
5) **插件**：安装/包表可考虑租户维度（如需按租户隔离），或在文档中说明全局表语义并加审计。

> 注：测试/脚本/生成类使用 `--include-tests` 可单独审查，不在本分类内。*** End Patch
