# T-003 (P2) 动态表与租户隔离现状审计

> 本报告聚焦由低代码 Collection 驱动的 **动态业务数据表**，审计其当前表结构与运行时访问路径的多租户隔离情况。

---

## 1. 范围与结论概览

- **范围**：
  - Schema 层：`Infrastructure\Schema\SchemaBuilder`、`Infrastructure\Lowcode\Collection\Service\CollectionManager` 动态表创建逻辑；
  - 运行时访问层：`Infrastructure\Lowcode\FormDesigner\Service\FormDataManager` 以及 `infrastructure/Lowcode` 下所有 `Db::table()` / `Db::name()` 调用；
  - 元数据表（`lowcode_collections` 等）仅用于对比，不是本次 P2 的主对象。
- **结论摘要**：
  - 当前由低代码 Collection 创建的动态业务表 **默认不包含 `tenant_id` / `site_id` 字段**；
  - `FormDataManager` 对动态表的所有读/写/删/列表操作 **均未显式按 `tenant_id` / `site_id` 过滤**，仅依赖表名区分；
  - `infrastructure/Lowcode` 中唯一针对动态业务表使用 `Db::table()` 的位置在 `FormDataManager`，其它 `Db::name()` 调用均指向元数据表；
  - 若未来不同租户共享同一物理动态表（同一 `table_name`），则当前实现存在 **潜在跨租户数据泄露风险**，需由 T-003 P2 予以修复。

---

## 2. 动态表结构与创建路径

### 2.1 SchemaBuilder 与建表逻辑

- 核心实现：`infrastructure/Schema/SchemaBuilder.php`
  - `SchemaBuilder::createTable(string $tableName, array $columns, array $indexes = [], string $engine = 'InnoDB', string $comment = '')`：
    - 基于 `$columns` 拼接原生 SQL，执行 `CREATE TABLE`；
    - **本类本身并不注入任何固定字段**（如 `tenant_id` / `site_id`），仅按调用方提供的 `$columns` 工作；
    - 索引信息通过 `$indexes` 传入，但当前低代码动态表创建路径未显式使用此参数。

### 2.2 低代码 Collection 动态表创建

- 入口：`infrastructure/Lowcode/Collection/Service/CollectionManager.php`
  - 方法：`create(CollectionInterface $collection): void`
    - 构造列：`$columns = $this->buildColumns($collection->getFields());`
    - 建表调用：`$this->schemaBuilder->createTable($collection->getTableName(), $columns);`
  - 列构建逻辑：`buildColumns(array $fields): array`
    - 当前固定列集合：
      - `id`：INT UNSIGNED，自增主键；
      - 动态字段：来自每个 `FieldInterface` 的 `getDbColumn()` 定义；
      - `created_at`：DATETIME，默认 `CURRENT_TIMESTAMP`；
      - `updated_at`：DATETIME，默认 `CURRENT_TIMESTAMP`，带 `on_update CURRENT_TIMESTAMP`；
    - **未在此处追加 `tenant_id` / `site_id` 字段**；
    - 如需多租户隔离，必须在此方法中统一追加租户字段。

### 2.3 动态表命名与清单

- 动态表名来源：`$collection->getTableName()`，由 Domain Collection 决定；
- 代码中未强制 `lc_` 前缀或其它命名约定，但在实践中通常使用统一前缀以避免与业务表冲突；
- 本次审计基于**代码结构**，无法直接访问运行中数据库列出所有实际动态表，只能确认：
  - **所有由低代码 Collection 动态创建的业务表均通过 `CollectionManager::create()` → `SchemaBuilder::createTable()` 生成**；
  - 这些表在 schema 层默认只有：主键、业务字段、`created_at`、`updated_at`，**不包含内建的 `tenant_id` / `site_id` 列**，除非业务方在 Collection 字段设计中手工添加了名为 `tenant_id` 的自定义字段（但这不构成统一多租户模型）。

### 2.4 元数据表对比

- 与动态表不同，低代码元数据表（如 `lowcode_collections`）在 T-002 中已：
  - 通过迁移脚本新增了 `tenant_id` / `site_id` 字段；
  - 在 Repository 层按 `tenant_id`（和可选 `site_id`）过滤查询。
- 这意味着：**元数据层已经多租户化，数据层尚未完成**，恰好是 T-003 需要补齐的缺口。

---

## 3. 运行时访问路径清单与租户过滤状态

### 3.1 FormDataManager 动态表访问

- 文件：`infrastructure/Lowcode/FormDesigner/Service/FormDataManager.php`
- 与动态表读写相关的关键方法（均依赖 `$tableName = $this->getTableName($formName, $tenantId, $siteId)`）：

1. **`save(string $formName, array $data, int $tenantId, int $siteId = 0)`**
   - 动态表名：
     - 通过 form schema 取得 `collection_name`，再由 `CollectionManager::get(..., $tenantId)` 获得 Collection 后，取 `$collection->getTableName()`；
   - 写入逻辑：
     - `Db::table($tableName)->where('id', $data['id'])->update($saveData)`（更新）；
     - `Db::table($tableName)->insertGetId($saveData)`（插入）；
   - **租户过滤现状**：
     - 行级操作均 **未附加 `tenant_id` / `site_id` 条件**；
     - 写入时也未自动填充 `tenant_id` / `site_id` 字段（因为表结构中不存在这些列）。

2. **`get(string $formName, int $id, int $tenantId, int $siteId = 0): ?array`**
   - 表名：`$tableName = $this->getTableName($formName, $tenantId, $siteId);`
   - 查询：`Db::table($tableName)->find($id);`
   - **租户过滤现状**：
     - 仅按主键 `id` 查找，**未检查该行的所属租户**；

3. **`delete(string $formName, int $id, int $tenantId, int $siteId = 0): bool`**
   - 表名：同上；
   - 删除：`(bool) Db::table($tableName)->delete($id);`
   - **租户过滤现状**：
     - 仅按主键 `id` 删除，**未校验该行 `tenant_id` 是否匹配当前上下文**（当前表结构中也无此列）。

4. **`list(string $formName, array $filters = [], int $page = 1, int $pageSize = 20, int $tenantId = 1, int $siteId = 0): array`**
   - 表名：`$tableName = $this->getTableName($formName, $tenantId, $siteId);`
   - 查询：
     - `$query = Db::table($tableName);`
     - 依次应用 `$filters` 中的键值对作为 where 条件；
     - `$total = $query->count();`
     - `$list = $query->page($page, $pageSize)->select()->toArray();`
   - **租户过滤现状**：
     - 查询条件完全来源于 `$filters`；
     - **未自动附加 `tenant_id` / `site_id` 过滤**，调用方也无法通过参数强制这一点，因为表结构尚无该字段。

> 小结：目前所有通过 `FormDataManager` 访问动态表的路径，**都只在“表名”层面区分租户上下文（通过不同 form/collection 绑定不同 tableName）**，在同一物理表内部 **不存在行级租户隔离**。

### 3.2 其他低代码数据访问路径

- 针对 `infrastructure/Lowcode` 目录进行 `Db::table(` 模式搜索：
  - 结果均集中于 `FormDataManager`，未发现其它针对动态业务表的 `Db::table($collection->getTableName())` 风格调用；
- 针对 `Db::name(` 搜索：
  - 命中点主要为元数据表访问，例如：
    - `Db::name('lowcode_collections')`（集合元数据）；
    - `Db::name('lowcode_form_schemas')`（表单 schema 元数据）；
  - 这些访问对象均为元数据表，**不属于本次 P2 的动态业务数据表范畴**。

---

## 4. 风险评估与改造优先级建议

### 4.1 主要风险点

1. **行级跨租户访问风险**
   - 一旦存在多个租户共享同一动态表（同一 `table_name`），当前 `get/delete/list/save` 均未按 `tenant_id` 过滤：
     - 某租户可能通过已知 `id` 访问和删除其它租户的数据；
     - 列表接口在无 `tenant_id` 条件下会返回所有租户混合数据。

2. **动态表结构与元数据层不一致**
   - 元数据表已按 `tenant_id` / `site_id` 隔离，动态表仍为“无租户字段”的结构：
     - 容易给开发者造成“系统已实现完整多租户隔离”的错觉；
     - 增加今后排查问题与审计的复杂度。

### 4.2 改造优先级建议（后续 T-003 实施参考）

基于以上审计结果，建议在 T-003 中按照以下优先级推进：

1. **最高优先级：为动态表 schema 统一引入 `tenant_id` / `site_id` 字段**
   - 在 `CollectionManager::buildColumns()` 中追加：
     - `tenant_id`（INT UNSIGNED NOT NULL，默认 0）；
     - 视业务需求追加 `site_id`；
   - 对已有动态表通过迁移脚本补列与数据填充（策略类似 T-002 中对 `lowcode_collections` 的处理）。

2. **高优先级：改造 `FormDataManager` 的所有动态表访问**
   - 写入时：自动填充 `tenant_id` / `site_id`；
   - 读取 / 删除 / 列表时：
     - 统一 `where('tenant_id', $tenantId)`（必要时叠加 `site_id`）；
     - 对 `delete` 场景，确保不会删除其它租户行。

3. **中优先级：补充端到端多租户隔离测试**
   - 为两个不同租户（及可选 site）构造：Collection + Form + 动态表 + 表单数据；
   - 验证：在 tenant=1 下无法读写/删除 tenant=2 的记录，反之亦然。

4. **低优先级：必要的 SchemaBuilder / 索引优化**
   - 针对常用访问模式，为动态表增加 `(tenant_id, 主键/业务键)` 组合索引；
   - 评估索引带来的写入性能影响，并在文档中标注。

---

## 5. 小结

- 动态业务数据表当前处于“**无内建租户字段 + 运行时无租户过滤**”状态，只在表名层面隐含区分上下文；
- 与已完成的 T-001/T-002 相比，**数据层是多租户安全闭环中的短板**；
- 本报告为后续 T-003 实施提供了：
  - 统一的动态表创建入口与字段现状；
  - 覆盖所有动态表访问路径（主要集中在 `FormDataManager`）；
  - 针对风险点给出了清晰的改造优先级建议。

