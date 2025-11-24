# 多租户 / 多站点数据建模规范

## 📋 文档信息

| 项目 | 内容 |
|------|------|
| **文档名称** | 多租户 / 多站点数据建模规范 |
| **文档版本** | v1.0 |
| **创建日期** | 2025-11-18 |
| **关联文档** | 01-architecture-design/04-multi-tenant-design.md、01-architecture-design/05-multi-site-design.md、03-data-layer/09-database-design.md、03-data-layer/11-database-evolution-and-migration-strategy.md |

## 🎯 设计目标

1. 为多租户 / 多站点场景提供统一的数据建模约束，避免各文档和实现之间出现策略偏差；
2. 为分库分表、分区、Sharding 等演进策略提供稳定前提；
3. 为低代码 SchemaBuilder 生成的表结构提供“硬约束”，保证与平台多租户模型一致；
4. 降低未来迁移、合并、拆分租户 / 站点时的数据治理成本。

## 📌 适用范围

- 所有承载业务数据、需要按租户 / 站点隔离的业务表；
- 既包括手工建表的“平台内核表”，也包括通过低代码引擎生成的业务表；
- 不适用于仅存放全局配置、与租户无关的只读字典表（此类表可不含 `tenant_id` / `site_id`）。

## 1. 公共字段与命名约定

1. 所有多租户业务表 **必须** 包含以下字段：
   - `tenant_id` BIGINT UNSIGNED NOT NULL COMMENT '租户ID'
   - `site_id` BIGINT UNSIGNED NOT NULL COMMENT '站点ID'
2. 推荐公共时间字段：
   - `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
   - `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
   - `deleted_at` TIMESTAMP NULL DEFAULT NULL  （软删除场景）
3. 表命名推荐前缀：
   - 核心平台表：`alkaid_xxx`
   - 低代码业务表：`lc_{业务域}_xxx`（示例，实际可按低代码文档约定执行）。

## 2. 主键与唯一约束策略

### 2.1 共享数据库模式（shared）

1. **推荐主键策略**：
   - 采用 `id` 作为全局自增主键：`PRIMARY KEY (id)`；
   - 结合组合唯一约束保证租户内唯一性，例如：
     - 用户表：`UNIQUE KEY uk_tenant_site_username (tenant_id, site_id, username)`；
2. 对需要做 MySQL 分区（按 `tenant_id`）的超大表，如未来开启 `PARTITION BY HASH(tenant_id)`，应遵循：
   - 所有 **唯一索引** 均包含 `tenant_id` 作为前缀键；
   - 如需调整主键为 `(tenant_id, id)`，必须在迁移设计中评估并更新所有外键 / 索引。

### 2.2 独立数据库模式（database）

1. 每个租户拥有独立物理数据库（或 schema），`tenant_id` 字段可以：
   - 保留但仅用于逻辑统一；
   - 或在特定表中省略（由连接串 / 数据库名隐含租户维度）。
2. `site_id` 仍然需要显式存在于多站点表中，用于区分同一租户下的站点数据。
3. 迁移和运维层面需要通过 `TenantDatabaseService` 统一创建 / 升级各租户库，避免手工漂移。

### 2.3 混合模式（hybrid）

1. 对于体量较小的租户，使用共享库 + `tenant_id`/`site_id` 字段；
2. 对于大租户或有强隔离需求的租户，迁移至独立库：
   - 需要提供完整的数据迁移脚本和回滚方案；
   - 严禁出现“部分表在共享库，部分表在独立库但缺乏清晰规则”的灰色状态。

## 3. 索引与分区策略

1. 所有多租户业务表建议至少包含以下索引：
   - `KEY idx_tenant_site (tenant_id, site_id)` 作为查询入口；
   - 针对高频筛选条件增加组合索引，例如：
     - 用户表：`KEY idx_tenant_site_status (tenant_id, site_id, status)`；
2. 对按 `tenant_id` 分区的大表：
   - 主键与所有 `UNIQUE KEY` 必须包含 `tenant_id` 以满足 MySQL 分区约束；
   - 分区策略（HASH / RANGE）与分表策略（ShardingService）需在迁移设计中保持一致。

## 4. 与低代码 SchemaBuilder 的协同

1. 低代码生成的新表必须自动注入 `tenant_id` / `site_id` 字段及推荐索引；
2. 低代码 Schema 中允许的“多租户相关配置”应仅限：
   - 是否需要站点级隔离（决定是否加入 `site_id`）；
   - 是否需要额外的租户内唯一约束（如业务编码 `code`）；
3. 低代码在开发 / 测试环境可直接执行 DDL，但在生产环境 **仅生成迁移候选脚本**，具体执行遵循《数据库演进与迁移策略》文档。

## 5. 与其他文档的关系

- 本规范约束了 `03-data-layer/09-database-design.md` 中所有示例表的“应然形态”，后者的 SQL 可视为示例实现；
- 与 `01-architecture-design/04-multi-tenant-design.md`、`05-multi-site-design.md` 一起，定义了多租户 / 多站点在“模型 + 存储”两个维度上的统一边界；
- 与 `03-data-layer/11-database-evolution-and-migration-strategy.md` 协同，确保任何 Schema 演进都不会破坏租户 / 站点隔离。
