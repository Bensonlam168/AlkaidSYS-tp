# Group A / T-002 (P1): lowcode_collections 表租户化 + Repository 多租户过滤 技术方案

## 1. 现状小结

- **表结构**：`database/migrations/20251119000001_create_lowcode_collections_table.php`
  - 字段：`id, name, table_name, schema, created_at, updated_at`，无 `tenant_id` / `site_id`。
  - 索引：
    - `uk_name`：`unique(name)`
    - `uk_table_name`：`unique(table_name)`
- **Repository**：`Infrastructure\\Lowcode\\Collection\\Repository\\CollectionRepository`
  - 所有查询/保存均基于全局表：
    - `save(CollectionInterface $collection)`：写入 `name/table_name/schema`，无租户维度。
    - `findByName(string $name)`：`where('name', $name)`。
    - `findById(int $id)`：按 `id` 查询。
    - `list(array $filters = [], int $page = 1, int $pageSize = 20)`：无租户过滤，仅 name like 过滤。
- **Service 缓存与调用链**：`Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager`
  - 已在 T-001 P0 实现 **tenant-aware 接口签名**（但实现仍是全局）：
    - `get(string $name, ?int $tenantId = null): ?CollectionInterface`。
    - `delete(string $name, bool $dropTable = true, ?int $tenantId = null): void`。
    - `list(int $tenantId, array $filters = [], int $page = 1, int $pageSize = 20): array`。
  - 当前缓存：
    - `protected string $cachePrefix = 'lowcode:collection:';`
    - `get()` 与 `cache()` / `clearCache()` 均使用 **name-only key**：`lowcode:collection:{name}`。
  - 设计文档中已经注明：P1 会在 Repository / DB 层引入租户隔离，并将缓存 key 升级为 tenant-aware。

## 2. T-002 目标范围（与设计文档对齐）

结合 `docs/todo/低代码 Collection 多租户改造多阶段路线.md` 的 P1 规划，以及 backlog 对 T-002 的定义，本轮 T-002 聚焦：

1. **DB 层（迁移）**：
   - 为 `lowcode_collections` 增加：
     - `tenant_id`（int，非负，默认 0 代表“系统级/全局模版”）。
     - `site_id`（int，默认 0），为将来站点维度预留位。
   - 新增/调整索引：
     - 新增 `unique(tenant_id, name)`，保证“同一租户内 collection name 唯一”。
     - 保留现有 `unique(table_name)`（`uk_table_name`），确保物理表名在全局范围内仍唯一（避免生成重复表名）。
   - 数据迁移：
     - 采用设计文档中的 **策略 A**：将现有记录视为“系统级模板”：
       - 在迁移中为所有已有行填充 `tenant_id = 0, site_id = 0`。
       - 后续业务可以在未来的任务中基于系统模板复制出各租户专属集合。

2. **Repository 层（多租户过滤）**：
   - 在不破坏现有调用方的前提下，逐步引入 tenant 过滤：
     - `findByName` 增加可选 `$tenantId` / `$siteId` 参数，并在 SQL 中附加 `tenant_id = :tenantId`（`$tenantId` 默认为 0）。
     - `list` 增加可选 `$tenantId` / `$siteId` 参数，并对查询附加 `tenant_id = :tenantId` 过滤。
   - 为了兼容当前 Domain 模型仍未显式包含 tenant 字段：
     - `save(CollectionInterface $collection)` 继续保留原签名，内部按如下策略写入：
       - 优先尝试从 `CollectionInterface` 读取 `getTenantId()` / `getSiteId()`（为未来 Domain 扩展预留）。
       - 若不存在上述方法，则默认写入 `tenant_id = 0, site_id = 0`（系统级模板）。
     - `hydrate(array $data)` 暂时仍按现有字段构建 `Domain\\Lowcode\\Collection\\Model\\Collection`，不强行引入 tenant 字段；未来当 Domain 模型扩展时，可在 options 中透传或直接传入构造参数。

3. **Service 层缓存策略（CollectionManager）**：
   - 在保持 **对外接口签名不变** 的前提下（与 T-001 P0 兼容）：
     - 引入统一的 cache key 生成函数：
       - `lowcode:collection:{tenantId}:{name}`。
       - 当前阶段不引入 `site_id` 维度到缓存 key（site 场景延后到后续 P1/P2 任务）；但在注释中保留扩展位。
     - `get(string $name, ?int $tenantId = null)`：
       - 计算有效租户：`$effectiveTenantId = $tenantId ?? 0;`。
       - 优先从 `lowcode:collection:{effectiveTenantId}:{name}` 读取缓存。
       - 若缓存未命中，则通过 Repository 按 `tenant_id = $effectiveTenantId` 查询，并将结果写入对应的 tenant 级缓存 key。
       - **暂不实现 fallback 到 `tenant_id = 0`**，以保持“每个租户只看到自己的集合”的强隔离语义；未来若业务确认需要模板 fallback，再以额外任务引入 feature flag 控制的双层查找逻辑。
     - `clearCache(string $name, ?int $tenantId = null)`：
       - 若传入 `$tenantId`，仅清理 `lowcode:collection:{tenantId}:{name}`。
       - 若为 `null`，默认清理 `lowcode:collection:0:{name}`（系统级模板），并在注释中说明多租户场景建议显式传入 tenantId。
     - `cache(CollectionInterface $collection)`：
       - 由于当前 Domain 模型不含 tenant 字段，且 `create()` / `update()` 仍主要服务系统级模板场景，本阶段默认写入 `tenant_id = 0` 的缓存：
         - key 为 `lowcode:collection:0:{name}`。
       - 当未来 Domain Collection 引入 `getTenantId()` 后，可升级为根据实体自身的 tenantId 选择缓存 key。

4. **向后兼容性策略**

- 对外 Service 签名保持与 T-001 P0 一致：
  - 不在 T-002 中强制将 `get/delete/list` 的 `$tenantId` 升级为必填非 null；依然允许 `null`，且解释为“系统级/模板租户（tenant_id = 0）”。
- 对现有调用方的兼容：
  - T-001 已经将 `CollectionController` / `FormDataManager` 等对 `get/list/delete` 的调用改造为总是传入 `Request::tenantId()`，因此它们在 T-002 后会自然按租户过滤。
  - 仍然保留部分 legacy/系统级调用（如部分 CLI 命令）直接以 `null` 调用 `get($name)` / `delete($name)` 作为操作系统级模板的入口，对应 DB 与缓存均使用 `tenant_id = 0`。
- DB 层兼容：
  - 新增列时为现有数据补全默认值（`tenant_id = 0, site_id = 0`），避免 `NULL` 带来的查询条件歧义。
  - 保留 `uk_table_name`，不会影响现有物理表的唯一性约束。

## 3. 具体改动点规划

### 3.1 迁移脚本

- 新增迁移文件：`database/migrations/2025xxxxxx_add_tenant_site_to_lowcode_collections_table.php`，主要步骤：
  1. 使用 `$table = $this->table('lowcode_collections');`。
  2. `addColumn('tenant_id', 'integer', [ 'default' => 0, 'null' => false, 'signed' => false, 'comment' => 'Tenant ID | 租户ID' ])`。
  3. `addColumn('site_id', 'integer', [ 'default' => 0, 'null' => false, 'signed' => false, 'comment' => 'Site ID | 站点ID' ])`。
  4. 新增索引：`addIndex(['tenant_id', 'name'], ['unique' => true, 'name' => 'uk_tenant_name']);`。
  5. 通过 `execute('UPDATE lowcode_collections SET tenant_id = 0, site_id = 0 WHERE tenant_id IS NULL OR site_id IS NULL');` 为历史数据补全字段值（若底层初始值为 NULL）。
- 回滚策略：
  - 使用 Phinx 的 `change()` 风格以保持与现有迁移一致，字段新增可自动回滚（列与索引都可被移除）。
  - 数据层面的 `UPDATE` 不可自动回滚，视为一次性“补齐默认值”操作，已经在设计文档中明确风险，由 T-002 评审时同步说明。

### 3.2 Repository 改造

- `CollectionRepository::save`：
  - 继续保持签名：`public function save(CollectionInterface $collection): int`。
  - 内部构造 `$data` 时新增：
    - 尝试从 `$collection` 中读取 `getTenantId()` / `getSiteId()`，若方法不存在或返回 null，则使用 `0`：
      - `tenant_id` => `$tenantId ?? 0`。
      - `site_id` => `$siteId ?? 0`。
  - 更新和插入逻辑保持不变（依据 `id` 是否存在决定 `update` 还是 `insertGetId`）。

- `CollectionRepository::findByName`：
  - 调整签名为：
    ```php
    public function findByName(string $name, ?int $tenantId = null, ?int $siteId = null): ?CollectionInterface
    ```
  - 内部实现：
    - 计算 `$effectiveTenantId = $tenantId ?? 0;`。
    - 构造查询：
      ```php
      $query = Db::name($this->table)
          ->where('name', $name)
          ->where('tenant_id', $effectiveTenantId);
      if ($siteId !== null) {
          $query->where('site_id', $siteId);
      }
      $data = $query->find();
      ```

- `CollectionRepository::list`：
  - 调整签名为：
    ```php
    public function list(array $filters = [], int $page = 1, int $pageSize = 20, ?int $tenantId = null, ?int $siteId = null): array
    ```
  - 实现：
    - 与现有实现类似，但在最开始附加：
      ```php
      $effectiveTenantId = $tenantId ?? 0;
      $query = Db::name($this->table)
          ->where('tenant_id', $effectiveTenantId);
      if ($siteId !== null) {
          $query->where('site_id', $siteId);
      }
      ```
    - 其余 filter / 分页/排序逻辑不变。

- 其他方法（如 `findById`, `delete`）：
  - 出于安全性考虑可以在内部也附加 `tenant_id` 限制，但考虑到 `id` 为表主键，全局唯一，T-002 阶段暂不强制增加 tenant 条件，仅在未来需要更严格防护时再扩展。

### 3.3 CollectionManager 改造（缓存与 Repository 调用）

- `get(string $name, ?int $tenantId = null)`：
  - 计算：`$effectiveTenantId = $tenantId ?? 0;`。
  - cache 部分：
    - 使用新 key：`$cacheKey = $this->cachePrefix . $effectiveTenantId . ':' . $name;`。
  - repository 查询部分：
    - 调整为：`$collection = $this->collectionRepo->findByName($name, $effectiveTenantId);`。
  - 加载字段与关系逻辑保持不变。
  - 将从 DB 加载到的集合使用 tenant-aware key 写入缓存。

- `list(int $tenantId, array $filters = [], int $page = 1, int $pageSize = 20)`：
  - 调整为：
    ```php
    return $this->collectionRepo->list($filters, $page, $pageSize, $tenantId);
    ```
  - 暂不对 list 结果做缓存（沿用现有行为），后续如需要可在 P2 统一设计列表级缓存策略。

- `clearCache(string $name, ?int $tenantId = null)`：
  - 计算 `$effectiveTenantId = $tenantId ?? 0;`。
  - 删除单个 key：`lowcode:collection:{effectiveTenantId}:{name}`。
  - 在注释中说明：
    - 多租户场景下建议调用方始终显式传入 tenantId；
    - 仅传入 `null` 时表示“系统级模板缓存”。

- `cache(CollectionInterface $collection)`：
  - T-002 阶段仍假定该方法主要用于系统级模板（tenant=0）；
  - 生成 key 时默认 tenantId=0：
    - `lowcode:collection:0:{collection->getName()}`。
  - 在 PHPDoc 中注明：
    - 未来当 Domain Collection 支持 tenantId 后，将改为优先使用 `$collection->getTenantId()` 作为 key 中的租户维度。

## 4. 测试策略（为后续 Task 预留）

- Repository 单测：
  - 针对 `CollectionRepository::findByName` / `list`：
    - 插入多条不同 `tenant_id` 的 lowcode_collections 记录，验证：
      - 按 tenantId 查询时不会返回其它租户的数据；
      - 未传 tenantId 时仅返回 `tenant_id = 0` 的记录。
- Service 层测试：
  - 针对 `CollectionManager::get/list/clearCache`：
    - 通过 Mock Repository 验证传入的 `$tenantId` 被正确用于构造 cache key 与 Repository 调用。
- Feature Test（视需要扩展）：
  - 可以在现有 `CollectionControllerTest` 基础上补充跨租户隔离场景，例如：
    - 直接通过 DB 插入两个不同 `tenant_id` 的同名集合，分别在不同租户上下文下调用 `GET /v1/lowcode/collections/{name}`，验证只返回本租户数据。

## 5. 风险与后续扩展

- **数据可见性变化**：
  - T-002 引入 `tenant_id` 过滤后，以前依赖全局 `lowcode_collections` 的调用若未传入 tenantId，将默认访问 `tenant_id = 0` 的系统级模板，不再“隐式对所有租户可见”。
  - 当前环境主要为开发阶段，且通过 Controller 的主要入口已经在 T-001 改造为从 `Request::tenantId()` 获取租户，因此可控。

- **Domain 模型与强制 tenantId 的升级**：
  - 设计文档 P1 仍规划在后续步骤中为 `Domain\\Lowcode\\Collection\\Model\\Collection` 增加 `tenantId/siteId` 字段，并将 `CollectionManager::create/get/delete` 的 tenantId 升级为必填。
  - 本轮 T-002 为该演进打基础（DB 字段、Repository 过滤、缓存 key），但不在当前任务中强制落地 Domain 层变更，以降低一次性改动范围。

- **后续任务建议**：
  - T-002 完成后，可新增一条 T-002.x/T-003 子任务：
    - 将 Domain Collection 扩展为 tenant-aware，并调整 create/update 流程，使新建集合直接绑定到当前租户；
    - 视业务决定是否引入“系统模板 fallback”机制，并以 feature flag 控制。
