# SEC-02 原生 SQL 多租户安全审查清单（2025-12-18）

## 目标

梳理项目中原生 SQL 入口（`Db::query()` / `Db::execute()`）与高风险查询入口（`Db::table()` / `Db::name()`），识别需要补齐 `tenant_id/site_id` 过滤或审计的调用点，为后续整改提供依据。

## 审查方法

- 使用脚本：`php scripts/check-rawsql-safety.php`
- 额外聚焦：`rg -n "Db::query\\(|Db::execute\\(" app domain infrastructure`
- 关注目录：`app/`、`domain/`、`infrastructure/`

## 结果摘要

### 1) 原生 SQL（Db::query/Db::execute）- 生产代码

以下为 **生产代码** 中的原生 SQL 入口（需人工确认是否为系统级操作或需租户过滤）：

| 文件 | 行号 | 调用 | 说明 |
| --- | --- | --- | --- |
| `infrastructure/Schema/SchemaBuilder.php` | 94/128/181/219 | `Db::execute(...)` | 动态建表/DDL 操作（系统级） |
| `infrastructure/Schema/SchemaBuilder.php` | 156/249 | `Db::query(...)` | `SHOW TABLES/COLUMNS` 元数据查询 |
| `app/command/lowcode/MigrationDiffCommand.php` | 351/368/452 | `Db::query(...)` | CLI 迁移对比（系统级） |
| `infrastructure/Application/BaseApplication.php` | 172 | `Db::execute($statement)` | 应用初始化/菜单配置 DDL/SQL |

> 结论：当前原生 SQL 主要集中在 **DDL/元数据** 与 **CLI** 场景，仍需为相关执行路径加入审计/白名单说明，避免误用到业务数据场景。

### 2) 查询构造器入口（Db::table/Db::name）

`Db::table()/Db::name()` 在业务层使用广泛，需结合租户上下文判断是否缺失 `tenant_id/site_id` 约束，重点关注：

- `infrastructure/Permission/Service/PermissionService.php`
- `infrastructure/Permission/Casbin/DatabaseAdapter.php`
- `infrastructure/Collection/CollectionManager.php`
- `infrastructure/Lowcode/**/Repository/*`
- `infrastructure/User/Repository/UserRepository.php`
- `infrastructure/Tenant/Repository/TenantRepository.php`

> 建议：结合 `TenantAwareDb` / `Request::tenantId()` 使用场景，逐一确认 tenant 约束是否缺失。

## 下一步建议（整改路线）

1. **A1（盘点）**：将上述清单纳入 Backlog（T-115）子任务，按模块标记“系统级/租户级”用途。
2. **A2（约束）**：对业务数据访问路径补齐 `tenant_id/site_id` 条件或引入统一的 `TenantAwareDb`。
3. **A3（审计）**：为原生 SQL 入口增加审计日志与单元/集成测试覆盖（含跨租户访问阻断）。

## 关联任务

- Backlog：`docs/todo/development-backlog-2025-11-25.md` → T-115（SEC-02）
