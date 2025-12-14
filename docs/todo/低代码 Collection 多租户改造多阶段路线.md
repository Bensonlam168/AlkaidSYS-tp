
## 方案概述：低代码 Collection 多租户改造多阶段路线

本方案将 **低代码 Collection 多租户能力** 拆分为三个递进阶段：

- **P0（当前阶段）**：  
  不改 DB 结构，只在 **Controller / Service / 调用链** 层面统一多租户语义与接口约束，并修复明显安全问题（如从请求体读取 `tenant_id`）。  
  → 目标：**接口契约正确、安全风险可控，为后续 DB 级改造铺路。**

- **P1（核心改造）**：  
  将 `lowcode_collections` 元数据表 **真正升级为租户级表**：新增 `tenant_id(/site_id)` 字段、迁移数据、调整 Domain 模型与 Repository、改造 `CollectionManager` 接口。  
  → 目标：**元数据层面实现严格多租户隔离**，支持“同名 Collection 在不同租户下共存”。

- **P2（数据表与运行时强化）**：  
  检查并加强 **动态业务数据表的多租户隔离**（由 Collection 定义的物理表）、运行时访问路径与缓存策略，使“元数据 + 数据”形成完整的安全闭环。  
  → 目标：**端到端的多租户安全与一致性**。

阶段之间的依赖关系：

- **P0 → P1**：  
  P0 完成后，所有上层代码已经统一通过 `tenantId` 与 `CollectionManager` 交互，P1 在此基础上只需要“换底层实现 + DB 结构调整”，不会再引入新调用约定。
- **P1 → P2**：  
  P1 确立“Collection 是租户级元数据”的事实，P2 才有清晰边界来检查“由 Collection 驱动的动态数据表是否在数据访问上严格按租户过滤”。

---

## 一、P0 阶段详细计划：接口统一 + 安全加固（当前要实施）

### 1. P0 目标与边界

**目标：**

1. **不信任请求体中的 `tenant_id`**，统一由 `Request::tenantId()` / `Request::siteId()` 提供租户上下文。
2. 统一 `CollectionManager` 接口签名，使其 **显式感知 tenant**，并与 Controller / Form 服务层调用一致。
3. 为未来 P1 引入 DB 级 tenant 字段预留 API 契约和缓存 key 设计。

**明确不做的事情（保留到 P1/P2）：**

- 不对 `lowcode_collections` 表做 migration（不新增 `tenant_id` 字段）。
- 不改变 `Domain\Lowcode\Collection\Model\Collection` 的核心字段集合。
- 不对 `CollectionRepository` 的 SQL 过滤条件加入 `tenant_id`（当前 DB 无此列）。

### 2. P0 文件清单与具体改动点

#### 2.1 `infrastructure/Lowcode/Collection/Service/CollectionManager.php`

1. **方法签名变更**

- `get`：

  - 从：
    ```php
    public function get(string $name): ?CollectionInterface
    ```
  - 调整为：
    ```php
    public function get(string $name, ?int $tenantId = null): ?CollectionInterface
    ```

- `delete`：

  - 从：
    ```php
    public function delete(string $name, bool $dropTable = true): void
    ```
  - 调整为：
    ```php
    public function delete(string $name, bool $dropTable = true, ?int $tenantId = null): void
    ```

2. **实现调整**

- `get` 内部逻辑暂时仍按 name 全局查询，但：
  - **缓存 key 引入 tenant 维度**（防止未来 per-tenant collection 时 key 冲突）：
    - 例如：`lowcode:collection:{tenantId or 'global'}:{name}`；
  - PHPDoc 中显式写明：
    - 当前 Phase：`lowcode_collections` 仍是全局表，`$tenantId` 只用于缓存命名与未来扩展；
    - **TODO 注释**：Phase 2/P1 将在 DB 层真正按 `tenant_id` 过滤（详见后文“可追溯性机制”）。

- `delete`：
  - 仅将 `$collection = $this->get($name);` 改为 `$collection = $this->get($name, $tenantId);`；
  - 其它删除 metadata / 物理表 / 清缓存 / 触发事件的逻辑保持不变。

3. **TODO / FIXME 注释**

- 在类级 PHPDoc 或方法内添加清晰注释，例如：

  > `// TODO (Phase P1): lowcode_collections 迁移为租户级表后，这里的 get/delete 必须在 Repository 层按 tenant_id 过滤，当前仅为过渡实现。详见 docs/todo/development-backlog-2025-11-23.md 中 P1 任务。`

#### 2.2 `app/controller/lowcode/CollectionController.php`

1. **`read(string $name, Request $request)`**

- 统一使用 Request 上下文获取 tenant：

  - 读取：
    ```php
    $tenantId = $request->tenantId();
    ```
  - 调用：
    ```php
    $collection = $this->collectionManager->get($name, $tenantId);
    ```

2. **`update(string $name, Request $request)`**

- **删除** 从 PUT body 读取 `tenant_id` 的逻辑：

  - 删除：
    ```php
    $tenantId = $data['tenant_id'] ?? 1;
    ```
- 改为：
  ```php
  $tenantId = $request->tenantId();
  $collection = $this->collectionManager->get($name, $tenantId);
  ```

- 增加注释说明：

  > `// 注意：租户信息仅来自中间件注入的 Request 上下文，不信任请求体中的 tenant_id 字段。`

3. **`delete(string $name, Request $request)`**

- 补充 tenant 获取与调用：

  ```php
  $data      = $request->delete();
  $dropTable = (bool)($data['drop_table'] ?? true);
  $tenantId  = $request->tenantId();

  $this->collectionManager->delete($name, $dropTable, $tenantId);
  ```

- 可选：在返回响应前增加审计日志（未来 P2 可扩展）。

#### 2.3 `infrastructure/Lowcode/FormDesigner/Service/FormDataManager.php`

- 在已有 tenantId 参数的方法中，改动对 CollectionManager 的调用：

  - `save(string $formName, array $data, int $tenantId, int $siteId = 0)`：
    ```php
    $collection = $this->collectionManager->get($form['collection_name'], $tenantId);
    ```
  - `getTableName(string $formName, int $tenantId, int $siteId)`：
    ```php
    $collection = $this->collectionManager->get($form['collection_name'], $tenantId);
    ```

- 注释说明此处已开始显式按 tenant 取集合定义（即便当前底层实现仍为全局表）。

#### 2.4 其他调用点检查（只读）

- `FieldManager` / `RelationshipManager` / CLI 命令（`TestCollection` / `TestLowcodeCollection`）：
  - 继续使用 `get($name)`（不带 tenant），保持“系统级全局配置”的定位；
  - P0 不变更其接口，以免与当前“全局 metadata”设计冲突；
  - 后续 P1 若引入 per-tenant Collection，可能将这些能力限制为“仅系统运营或指定 system tenant 下操作”。

### 3. P0 测试策略与验收标准

#### 3.1 测试用例设计

1. **Controller 行为与安全性**

- 新增 Feature 测试（例如 `tests/Feature/Lowcode/CollectionControllerTenantTest.php`）：
  - 场景 A：请求体中伪造 `tenant_id`，但 Request 中间件注入的 tenant 不同：
    - 构造请求：`PUT /lowcode/collections/{name}`，body 里写入 `tenant_id = 999`；
    - 通过中间件模拟/设置真实 tenantId = 1；
    - 验证：Controller 使用的是 Request tenantId（可以通过日志/伪装 manager mock 或检查行为副作用），而不是 body。
  - 场景 B：`DELETE /lowcode/collections/{name}`：
    - 确认 Controller 会调用 `CollectionManager::delete($name, ..., $tenantIdFromRequest)`，而不是无 tenant。

2. **FormDataManager 回归测试**

- 扩展/新增单测（例如在 `tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php`）：
  - 构造有 tenantId 的 form schema 和数据，保证在透传 tenantId 到 `CollectionManager::get` 之后，行为仍与之前一致（即不会抛出异常，数据能正常保存/读取）。

3. **回归检查 CLI 命令**

- 手动或自动运行：

  ```bash
  docker exec -it alkaid-backend php think TestCollection
  docker exec -it alkaid-backend php think TestLowcodeCollection
  ```

- 期望：所有原有“创建-查询-更新-删除集合”的流程依然通过。

#### 3.2 测试执行命令

- 单元/特性测试（建议最小集+回归）：

  ```bash
  docker exec -it alkaid-backend php think test --testsuite=Feature
  docker exec -it alkaid-backend php think test tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php
  ```

- 代码格式检查：

  ```bash
  docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix
  ```

#### 3.3 P0 验收标准

1. 所有 Controller 中有关 Collection 的操作均从 Request 上下文获取 tenant，不再读取 body 中的 `tenant_id`。
2. `CollectionManager::get/delete` 接口签名升级为 tenant-aware，并且：
   - 所有 Controller / FormDataManager 对其调用签名一致；
   - 所有历史调用保持兼容、测试通过。
3. 新增/修改的测试全部通过，CI（至少本地测试 + php-cs-fixer）干净。
4. 相关 TODO 注释与 backlog 条目已写明这是 P0 的过渡实现（见“可追溯性机制”部分）。

#### 3.4 回滚方案（P0）

- 所有改动集中在 PHP 代码，无 schema 迁移：
  - 回滚方式：`git revert` 或直接回滚该 commit。
  - 验证：重新运行 `php think test` 和 CLI 命令，确保行为回到改动前状态。

---

## 二、P1 阶段规划：将 `lowcode_collections` 真正改造为租户级表

### 1. P1 目标与边界

**目标：**

1. 为 `lowcode_collections` 新增 `tenant_id`（和必要时的 `site_id`），使 Collection 元数据真正成为 **“租户级资源”**。
2. 支持不同租户下存在同名集合（例如 `orders`），并在一切访问路径中按 `tenant_id`（和可选 `site_id`）过滤。
3. 保证现有功能兼容或通过明确定义迁移策略平滑过渡。

**边界（不做的内容）：**

- 不在 P1 中强制重构所有低代码动态物理表的数据结构（留到 P2）；
- 不调整前端多租户上下文（前端多租户属于独立 P1/P2 任务，这里聚焦后端）。

### 2. P1 关键任务与演进路径

#### 2.1 DB 迁移与数据策略

1. **新增 migration 文件**

- 新建类似 `database/migrations/2025xxxxxx_add_tenant_to_lowcode_collections_table.php`：
  - 为 `lowcode_collections` 增加：
    - `tenant_id`（int，非负，必要时默认 0 代表“系统级/全局模版”）；
    - 可选 `site_id`（int，默认 0）。
  - 根据业务设计，新增索引：
    - `unique(tenant_id, name)`：确保每个租户下集合名唯一；
    - 若支持站点维度：`unique(tenant_id, site_id, name)`。

- 命令执行（容器内）：
  ```bash
  docker exec -it alkaid-backend php think migrate:run
  ```

2. **数据迁移策略（关键设计点）**

这里存在两种主策略，需要结合业务决策选择：

- **策略 A：现有集合视为“系统级模板”（tenant_id = 0）**
  - 所有现有 `lowcode_collections` 记录在迁移时统一填充 `tenant_id = 0`；
  - 业务含义：未来租户可基于系统模板复制/继承自己的集合定义，系统级模板不会直接被租户改动；
  - 后续查询逻辑可以有两种模式：
    - “只看当前租户的集合”（排除 `tenant_id = 0`）；
    - 或“先按租户查找，如果没有则 fallback 到系统模板”。

- **策略 B：现有集合全部归属某个“全局运维租户”**
  - 创建一个 dedicated system tenant（例如 `tenant_id = 1` 或专门标记的 tenant）；
  - 迁移时将所有集合绑定到该 tenant；
  - 优点：更贴近“所有集合都属于某个具体租户”，更易统一审计；
  - 缺点：需要协调现有租户模型，可能触及更多业务决策。

> 这部分设计属于 P1 内部的 **产品/架构决策点**，在真正进入 P1 实施前，需要与你和业务方确认选择 A/B 或折衷方案。

3. **P1 回滚策略（迁移层面）**

- 技术上可以用：
  ```bash
  docker exec -it alkaid-backend php think migrate:rollback --step=1
  ```
- 但一旦已有增量数据按新 schema 写入，简单 rollback 会导致数据丢失或不一致，因此：
  - P1 上线需要独立评审与灰度方案；
  - 建议上线前做 DB 备份，必要时通过 **数据回放/手工脚本** 回滚。

#### 2.2 Domain 模型与 Repository 改造

1. **`Domain\Lowcode\Collection\Model\Collection`**

- 增加字段：
  - `private int $tenantId;`
  - 可选：`private int $siteId;`
- 调整：
  - 构造函数和 `fromArray()` / `toArray()` 同步处理 `tenant_id` / `site_id`；
  - 增加 `getTenantId()` / `setTenantId()` / `getSiteId()` 等方法。

2. **`CollectionRepository`**

- 方法签名调整（示例）：

  - `findByName`：
    ```php
    public function findByName(string $name, int $tenantId, ?int $siteId = null): ?CollectionInterface;
    ```
  - `save`：
    ```php
    public function save(CollectionInterface $collection): int;
    ```
    > `tenant_id` / `site_id` 从 Collection 模型自身获取。

  - `list` / `deleteByName` 等也增加 tenant/site 过滤。

- 查询语句统一添加：
  ```php
  ->where('tenant_id', $tenantId)
  // 可选 siteId
  ```

3. **P1 对 Repository 的回滚**

- 若需要回滚，只能通过代码 revert + 迁移回退；
- 需要确保新旧代码不会共存运行（用 feature flag 或一次性切换）。

#### 2.3 `CollectionManager`、Controller 与 CLI 改造

1. **`CollectionManager`**

- 在 P0 tenant-aware 签名基础上升级为 **必填 tenantId**（在对外 API 层面）：
  ```php
  public function create(CollectionInterface $collection, int $tenantId, ?int $siteId = null): CollectionInterface;
  public function get(string $name, int $tenantId, ?int $siteId = null): ?CollectionInterface;
  public function delete(string $name, int $tenantId, bool $dropTable = true, ?int $siteId = null): void;
  ```
- 内部调用 `CollectionRepository` 时严格传递 tenant/site。

2. **`CollectionController`**

- 所有调用 `CollectionManager` 的入口，统一要求存在合法的 Request tenantId（由中间件保证）；
- 若缺少 tenantId（配置错误）：
  - 返回 HTTP 500 + 明确错误码/trace_id；
  - 或在路由中强制挂多租户中间件，使之永远不会出现。

3. **CLI 命令**

- `TestCollection` / `TestLowcodeCollection` 等：
  - 明确注入一个 “system tenant id”（与策略 A/B 一致）；
  - 不再调用无 tenant 版本 API；
  - 测试说明中注明这些命令的租户上下文含义。

#### 2.4 P1 测试与验收标准（重点）

1. **单元测试**

- Repository 层：
  - 插入两条记录：`name = 'orders'`，`tenant_id = 1/2`；
  - 验证 `findByName('orders', 1)` 只返回 tenant 1 的记录，tenant 2 同理；
  - 验证 `unique(tenant_id, name)` 约束生效。

- Domain + Manager 层：
  - 构造不同 tenant 下的 Collection，使用 `create/get/delete`；
  - 确保操作不会跨租户误删/误查。

2. **Feature 测试**

- 控制器端点：多租户场景下：
  - 同名集合在两个 tenant 下创建成功；
  - 各自的 read/update/delete 不会互相影响；
  - 带错误 tenantId 访问时返回正确的错误码/提示。

3. **验收标准**

- `lowcode_collections` 表包含 `tenant_id` 字段并有正确索引；
- 在测试环境中可证明：
  - 同名 Collection 可存在于不同租户；
  - 程序所有入口都能正确按租户隔离访问；
- 所有相关测试通过，且有完备的回滚/灾备预案文档。

---

## 三、P2 阶段规划：动态业务数据表与运行时多租户强化

### 1. P2 目标

1. 确认所有由 Collection 驱动创建的动态业务数据表（`$collection->getTableName()` 对应的物理表）均包含 `tenant_id` / `site_id` 字段，并在读写时强制过滤。
2. 校验 `FormDataManager`、其它低代码数据访问路径，保证所有查询、更新、删除都 **显式带上租户过滤条件**。
3. 统一缓存策略：禁止跨 tenant 共享不应共享的数据缓存。

### 2. P2 关键任务

1. **SchemaBuilder / 动态表创建逻辑检查与改造**
   - 确认当前 Schema builder 在创建表时是否自动加入 `tenant_id/site_id`；
   - 若无，则新增 migration + 重建策略，并设计已有表的加列与数据迁移。

2. **运行时访问路径梳理**
   - `FormDataManager`、其它 Lowcode XxxManager 中所有直接 `Db::table(...)->where(...)` 的地方：
     - 强制加上 `tenant_id` 条件（从 Request 或方法参数获取）；
   - 单独设计“跨租户运维工具”（若需要）并严格限制使用。

3. **端到端测试**
   - 类似 `FormSchemaManagerTest::testTenantIsolation` 的端到端测试扩展到：
     - Collection + 物理表 + 表单数据；
   - 验证跨租户读取/写入/删除都被正确阻止。

4. **验收标准**
   - 安全评审通过：无已知路径可以在缺少 tenant 过滤的前提下操作动态业务数据；
   - 性能评估：新增 tenant 条件与索引布置是否合理，不引入显著性能退化。

---

## 四、风险评估与缓解措施

### 1. P0 过渡状态的风险

**风险 1：开发者误以为“传了 tenantId 就已经物理隔离”**

- 由于 P0 已经让接口层感知 tenantId，但 DB 层仍为全局表，可能产生错误安全假设。

**缓解：**

- 在 `CollectionManager::get/delete` 上：
  - 加强 PHPDoc 与 TODO 注释：
    - 明确“当前 Phase 仅为接口过渡，尚未在 DB 层做租户隔离”；
    - 标记关联的 backlog 任务与 Phase。
- 在 `docs/technical-specs/lowcode/*.md` 或专门文档中，增加一节“Collection 多租户改造路线”，写明当前状态与最终目标。

---

**风险 2：后续 P1/P2 被遗忘，技术债固化**

**缓解：**

- 在 `docs/todo/development-backlog-2025-11-23.md` 中新增明确的 P1/P2 任务条目（见“可追溯性机制”）。
- 将这些任务标记为：
  - P1：高优先级（例如 P0/P1 级），并在描述中链接当前代码位置。
- 在关键方法（`CollectionManager::get/delete` / `CollectionRepository` / 动态表 SchemaBuilder）处写 TODO，包含：
  - 对应 backlog 条目 ID / 标题；
  - 预期 Phase（P1/P2）。

---

**风险 3：未来 P1 DB 结构变更带来兼容性与数据迁移风险**

**缓解：**

- 在 P1 方案中明确：
  - 采用何种数据迁移策略（系统模版 vs 绑定特定 tenant）；
  - 上线前的 DB 备份策略；
  - 回滚策略仅限“逻辑回滚 + 数据备份恢复”，不依赖简单的 migrate:rollback。

---

**风险 4：若出现跨租户数据泄露线索**

- 一旦发现任何 **依赖 Collection 元数据而产生的跨租户数据访问缺陷**，P1/P2 必须提级：

**触发条件示例：**

- 安全测试发现存在通过某些低代码接口访问他租户数据的路径；
- 新业务要求将“Collection 管理”完全开放给租户管理员（每个租户独立管理自己的 schema），此时“全局 Collection”结构已不再适用。

**对应措施：**

- 立即将 P1 任务优先级提升为 P0（安全紧急修复类）；
- 为 P1 开辟独立分支与安全评审流程，优先完成 DB 级改造与强隔离测试。

---

## 五、可追溯性机制设计

### 1. 代码层注释与 TODO 规范

在关键位置增加明确的 TODO/FIXME：

1. `infrastructure/Lowcode/Collection/Service/CollectionManager.php`

- 在类或 `get/delete` 方法上：

  > `// TODO(P1: lowcode-collections-tenant): 当前 lowcode_collections 仍为全局表，tenantId 仅用于缓存 key。P1 阶段需要在 Repository 和 DB 层按 tenant_id/site_id 做强隔离，并补充 testTenantIsolationForCollections。`

2. `infrastructure/Lowcode/Collection/Repository/CollectionRepository.php`

- 在查询方法上：

  > `// TODO(P1: lowcode-collections-tenant): 引入 tenant_id 字段后，这里必须增加 where('tenant_id', $tenantId) 过滤，目前为过渡实现。`

3. `FormDataManager` 内对 `CollectionManager::get` 的调用处：

  > `// NOTE: P0 仅按 tenantId 透传给 CollectionManager，底层仍为全局集合定义。P1 后将按租户级元数据隔离。`

### 2. backlog 文档更新计划

在 `docs/todo/development-backlog-2025-11-23.md` 中新增/调整条目（**目前只在方案中定义，实际修改留到你确认后再执行**）：

1. **新增 P1 任务：**

- 标题示例：  
  `P1 - 将 lowcode_collections 表升级为租户级元数据表`
- 内容要素：
  - **目标**：
    - 为 `lowcode_collections` 增加 `tenant_id(/site_id)`，实现元数据租户隔离；
  - **范围**：
    - migration、新列、索引；
    - Collection Domain 模型与 Repository 改造；
    - CollectionManager 接口升级为必传 tenantId；
    - Controller/CLI 调整；
  - **验收标准**：
    - 同名集合可在不同租户下共存且相互隔离；
    - 新测试 `testTenantIsolationForCollections` 通过；
  - **关联文档/代码**：
    - `infrastructure/Lowcode/Collection/Service/CollectionManager.php`;
    - `infrastructure/Lowcode/Collection/Repository/CollectionRepository.php`;
    - `docs/technical-specs/lowcode/*.md`。

2. **新增 P2 任务：**

- 标题示例：  
  `P2 - 强化低代码动态数据表的多租户隔离与运行时安全`
- 内容要素：
  - **目标**：
    - 确保所有动态数据表含 `tenant_id/site_id` 并在访问时强制带租户过滤；
  - **范围**：
    - SchemaBuilder/动态迁移；
    - FormDataManager 等数据访问路径；
    - 端到端多租户隔离测试；
  - **验收标准**：
    - 安全测试确认无跨租户数据访问路径；
    - 性能评估通过。

3. **在 P0 条目中补充说明**

- 对当前 P0 任务（Collection 多租户接口层整改）：
  - 明确标注：**“本任务为过渡阶段，不改变 DB 结构，后续 P1/P2 任务负责完成真正的租户隔离”**；
  - 链接上述 P1/P2 条目 ID 以便导航。

---

## 小结与后续步骤

- 上述文档即为你要求的 **“多阶段完整方案 + 分步实施计划 + 风险与可追溯性设计”**。
- 若你认可这一整体方案，我下一步将按照这里的 **P0 详细计划** 进入实际实施：

  1. 使用 `apply_patch` 修改 `CollectionManager` / `CollectionController` / `FormDataManager`；
  2. 补充/调整测试；
  3. 在容器中执行测试与 php-cs-fixer；
  4. 本地 `git commit`（不 push）；
  5. 按你前文要求，在合适时机更新 `docs/todo/development-backlog-2025-11-23.md`，同步写入 P1/P2 条目与 P0 说明。


