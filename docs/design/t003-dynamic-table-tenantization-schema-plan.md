# T-003 Phase B：动态业务表多租户化（Schema 层）设计方案

> 目标：为所有低代码驱动的动态业务表补齐 `tenant_id` / `site_id` 字段与主查询索引，为后续运行时访问路径（FormDataManager 等）的行级多租户过滤提供结构基础。

## 1. 适用范围与约束

- 适用范围：
  - 由 `Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager` 通过 `SchemaBuilder::createTable()` 创建的动态业务表；
  - 已存在的动态业务表，通过 `lowcode_collections.table_name` 可识别的所有物理表。
- 设计约束：
  - 遵守 `docs/technical-specs/database/database-guidelines(.zh-CN).md` 的多租户规范：所有租户级业务表**必须**包含 `tenant_id`（必要时 `site_id`）且主查询索引需包含 `tenant_id`；
  - 不修改 `SchemaBuilder` 的 DDL 语法与行为，仅在调用方（CollectionManager）层构造列与索引；
  - 与 T-001（接口层多租户）与 T-002（lowcode_collections 多租户）保持兼容，本阶段仅改造 schema 层，不调整 FormDataManager 的 SQL 行为。

## 2. 新建动态表的 Schema 改造

### 2.1 列结构设计

在 `CollectionManager::buildColumns()` 中统一追加多租户字段，形成如下列顺序：

1. `id`：自增主键（保持现状）；
2. `tenant_id`：租户 ID；
3. `site_id`：站点 ID；
4. 动态业务字段（来自 `FieldInterface::getDbColumn()`）；
5. `created_at` / `updated_at`：时间戳字段。

字段属性：

- `tenant_id`：
  - `type = 'INT'`
  - `length = 11`
  - `nullable = false`（NOT NULL）
  - `default = 0`（`0 = 系统模板空间`）
- `site_id`：
  - `type = 'INT'`
  - `length = 11`
  - `nullable = false`（NOT NULL）
  - `default = 0`（`0 = 默认站点`）

> 说明：当前 `Infrastructure\\Schema\\SchemaBuilder::createTable()` 仅识别 `type/length/nullable/default/auto_increment/comment/primary` 等字段，暂不处理 `unsigned`。本方案不在本轮改造中强行引入 `unsigned` 语义，以避免改变现有 DDL 行为。

### 2.2 索引设计

为满足“主查询索引中包含 `tenant_id`”的规范，并适配典型访问模式（按租户分页、按租户+主键精确查询），在 CollectionManager 层新增索引构造方法：

- 新增受保护方法：`buildIndexes(): array`
  - 返回索引定义数组，供 `SchemaBuilder::createTable()` 使用；
  - 初始仅包含一个非唯一索引：
    - 名称：`idx_tenant_id_id`
    - 列：`['tenant_id', 'id']`
    - 用途：
      - `WHERE tenant_id = ? AND id = ?` 场景（get/delete）；
      - `WHERE tenant_id = ? ORDER BY id ...` 场景（list）。

在 `CollectionManager::create()` 中：

- 由 `buildColumns()` 构造列；
- 由 `buildIndexes()` 构造索引；
- 调用：`$this->schemaBuilder->createTable($collection->getTableName(), $columns, $indexes);`。

## 3. 既有动态表迁移策略

### 3.1 动态表识别

- 通过 `lowcode_collections` 元数据表获取所有动态表名：
  - `SELECT DISTINCT table_name FROM lowcode_collections WHERE table_name IS NOT NULL AND table_name <> ''`；
- 对于每个 `table_name`：
  - 使用迁移框架的 `$this->hasTable($tableName)`（或等价机制）判断物理表是否存在；
  - 存在则视为“低代码动态业务表”，纳入迁移处理；
  - 不存在则跳过（可能是历史残留元数据或已删除表）。

### 3.2 Up：批量添加字段与索引

在新的迁移类 `up()` 方法中：

1. 遍历所有识别出的动态表 `$tableName`；
2. 对每个表执行：
   - 若无 `tenant_id` 列：
     - `integer(11) unsigned=false null=false default=0 comment='Tenant ID | 租户ID（0 = 系统模板）'`；
   - 若无 `site_id` 列：
     - `integer(11) unsigned=false null=false default=0 comment='Site ID | 站点ID（0 = 默认站点）'`；
   - 若不存在索引 `idx_tenant_id_id`：
     - 在 `(tenant_id, id)` 上创建非唯一索引。
3. 通过 `$table->update()` 提交结构变更。

历史数据策略：

- 由于现有动态业务表记录没有可可靠推断 `tenant_id/site_id` 的外键信息，本次迁移不尝试按业务规则“回填租户”；
- 统一依赖列默认值 `0`，将所有既有记录视为“系统模板空间 + 默认站点”，与 T-002 对 `lowcode_collections` 的策略保持一致。

### 3.3 Down：结构级回滚

为满足“可回滚”要求，迁移类采用 `up()` / `down()` 显式模式：

- `up()`：执行 3.2 中的结构扩展；
- `down()`：
  - 再次遍历同一批动态表：
    - 如存在 `idx_tenant_id_id` 索引则删除；
    - 如存在 `tenant_id` / `site_id` 列则删除；
    - 调用 `$table->update()` 提交变更；
  - 回滚将导致相关列数据被物理删除，仅用于结构级回退，不推荐在生产环境随意执行。

## 4. 与其他阶段的一致性

- 与 T-001：
  - T-001 已在接口层（Controller / Service / FormDataManager）引入 `tenantId` / `siteId` 参数，但当前 SQL 尚未使用这些字段进行行级过滤；
  - 本阶段仅在 schema 层预先补齐所需字段与索引，不改变现有查询/写入行为，为 T-003 阶段 C（运行时访问改造）打基础。

- 与 T-002：
  - T-002 已将 `lowcode_collections` 元数据表多租户化（`tenant_id/site_id` + `uk_tenant_name` 索引）；
  - 本阶段为由这些 Collection 驱动的动态业务表补齐相同的租户字段与主查询索引，使元数据层与数据层在租户维度上对齐，同时保持现有业务逻辑不变。

## 5. 实施与验证

- 代码改造：
  - 修改 `Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager`：
    - `buildColumns()` 新增 `tenant_id` / `site_id` 字段；
    - 新增 `buildIndexes()` 方法并在 `create()` 中传入 `$indexes`；
  - 暂不修改 FormDataManager，避免在同一阶段混入运行时访问行为改造。
- 迁移脚本：
  - 在 `database/migrations/` 目录新增迁移类，基于 `lowcode_collections.table_name` 发现动态表并批量执行 DDL；
  - 首次实现后先由评审确认脚本内容与影响，再在 `alkaid-backend` 容器中执行 `php think migrate:run`。
- 验证：
  - 运行迁移前后，对比典型动态表结构，确认新增字段与索引生效；
  - 回归现有低代码相关单测与 Feature Test，确保无业务回归；
  - 为 CollectionManager 新增/补充单测覆盖 `create()` 对 SchemaBuilder 的列与索引传递行为（可在 T-003 阶段 B/C 合并实现）。

