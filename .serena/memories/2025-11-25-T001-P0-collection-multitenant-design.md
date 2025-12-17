# T-001 P0 

## 1. 阶段边界
- **仅做接口层与调用链整改，不改 DB 结构 / 不改 Collection Domain 核心字段 / 不在 Repository SQL 中加 tenant_id 过滤**。
- 目标：
  - 统一多租户语义（统一从 Request::tenantId()/siteId() 获取上下文）；
  - 统一 CollectionManager/Controller/FormDataManager 的多租户接口签名；
  - 修复明显安全问题（禁止从请求体读取 tenant_id）。

## 2. 关键接口设计（P0）

### 2.1 CollectionManager（infrastructure/Lowcode/Collection/Service/CollectionManager.php）
- `get`：
  - **现状**：`public function get(string $name): ?CollectionInterface`
  - **P0 目标**：`public function get(string $name, ?int $tenantId = null): ?CollectionInterface`
  - 说明：
    - P0 内部仍按 name 全局查询（依赖当前全局表与 Repository）；
    - tenantId 仅用于接口契约与未来扩展，暂不影响查询语义；
    - 通过 TODO 注释绑定 P1 任务，说明未来会在 Repository/DB 层按 tenant_id 做强隔离。

- `delete`：
  - **现状**：`public function delete(string $name, bool $dropTable = true): void`
  - **P0 目标**：`public function delete(string $name, bool $dropTable = true, ?int $tenantId = null): void`
  - 说明：
    - 仅将内部 `$collection = $this->get($name);` 替换为 `$collection = $this->get($name, $tenantId);`；
    - 其它删除 metadata/删表/清缓存/事件逻辑保持不变；
    - tenantId 为可选以兼容历史调用（CLI/test）。

- `list`：
  - **现状**：`public function list(array $filters = [], int $page = 1, int $pageSize = 20): array`
  - **P0 目标**：`public function list(int $tenantId, array $filters = [], int $page = 1, int $pageSize = 20): array`
  - 说明：
    - 与 CollectionController::index 现有调用 `$this->collectionManager->list($tenantId, $filters, $page, $pageSize);` 对齐；
    - P0 内部仍调用 `CollectionRepository::list($filters, $page, $pageSize)`，暂不按 tenant 过滤；
    - 强制控制层在调用时显式传入 tenantId，为 P1/P2 做好接口契约准备。

- `clearCache`：
  - 可在 P0 将签名扩展为 `public function clearCache(string $name, ?int $tenantId = null): void`，内部仍按旧 key 清理；
  - 为未来根据 tenantId 区分缓存做好接口准备（具体 key 方案可在 P1 实施）。

### 2.2 CollectionController（app/controller/lowcode/CollectionController.php）
- **统一原则**：
  - 所有与 Collection 相关的操作一律从 `Request::tenantId()` 获取租户上下文；
  - 不再从 body/querystring 中读取 `tenant_id`。

- `index(Request $request)`：
  - 保留现有 `$tenantId = $request->tenantId();`；
  - 使用新签名调用：`$this->collectionManager->list($tenantId, $filters, $page, $pageSize);`。

- `read(string $name, Request $request)`：
  - 使用 `$tenantId = $request->tenantId();`；
  - 调用：`$collection = $this->collectionManager->get($name, $tenantId);`。

- `update(string $name, Request $request)`：
  - **删除** `$tenantId = $data['tenant_id'] ?? 1;`；
  - 改为：`$tenantId = $request->tenantId();` + `get($name, $tenantId)`；
  - 增加注释：租户信息仅来自 Request 上下文，不信任 body 中的 tenant_id 字段。

- `delete(string $name, Request $request)`：
  - 新增 `$tenantId = $request->tenantId();`；
  - 调用：`$this->collectionManager->delete($name, $dropTable, $tenantId);`。

### 2.3 FormDataManager（infrastructure/Lowcode/FormDesigner/Service/FormDataManager.php）
- 该类方法签名已经包含 `int $tenantId, int $siteId` 参数：
  - `save(string $formName, array $data, int $tenantId, int $siteId = 0)`；
  - `get(string $formName, int $id, int $tenantId, int $siteId = 0)`；
  - `delete(string $formName, int $id, int $tenantId, int $siteId = 0)`；
  - `list(string $formName, array $filters = [], int $page = 1, int $pageSize = 20, int $tenantId = 1, int $siteId = 0)`。

- P0 需要统一对 CollectionManager 的调用：
  - 在 `save()` 中：
    - 将 `$collection = $this->collectionManager->get($form['collection_name']);` 改为：
      `$collection = $this->collectionManager->get($form['collection_name'], $tenantId);`。
  - 在 `getTableName()` 中：
    - 将 `$collection = $this->collectionManager->get($form['collection_name']);` 改为：
      `$collection = $this->collectionManager->get($form['collection_name'], $tenantId);`。
  - 注释说明：当前底层仍使用全局 `lowcode_collections`，tenantId 仅作为接口契约与未来扩展的透传参数。

### 2.4 多租户上下文来源（Request & 中间件）
- 统一依赖：
  - `app/Request::tenantId(?int $default = 1): int`；
  - `app/Request::siteId(?int $default = 0): int`；
  - `app/middleware/TenantIdentify` 负责从 `X-Tenant-ID` 头解析并写入 Request（`setTenantId`）。
- P0 不增加额外租户解析逻辑，也不从 body/query 中读 tenant_id。

## 3. 待修改文件与方法清单（T-001 P0）
- infrastructure/Lowcode/Collection/Service/CollectionManager.php
  - `get(string $name)` -> `get(string $name, ?int $tenantId = null)` + 内部实现更新；
  - `delete(string $name, bool $dropTable = true)` -> `delete(string $name, bool $dropTable = true, ?int $tenantId = null)`；
  - `list(array $filters = [], int $page = 1, int $pageSize = 20)` -> `list(int $tenantId, array $filters = [], int $page = 1, int $pageSize = 20)`；
  - （可选）`clearCache(string $name)` -> `clearCache(string $name, ?int $tenantId = null)`。
- app/controller/lowcode/CollectionController.php
  - `index(Request $request)`：确认调用 `list($tenantId, $filters, $page, $pageSize)`；
  - `read(string $name, Request $request)`：使用 `$request->tenantId()` 并调用 `get($name, $tenantId)`；
  - `update(string $name, Request $request)`：移除对 `$data['tenant_id']` 的依赖，改为 `Request::tenantId()`；
  - `delete(string $name, Request $request)`：新增从 Request 获取 tenantId，并调用 `delete($name, $dropTable, $tenantId)`。
- infrastructure/Lowcode/FormDesigner/Service/FormDataManager.php
  - `save()` 与 `getTableName()` 中对 `CollectionManager::get` 的调用改为带 tenantId 版本。

## 4. 测试与验收要点（P0）
- 验证所有 Collection 相关 Controller：
  - 不再从 body/query 中读取 `tenant_id`；
  - 统一依赖 Request::tenantId()；
  - 与新的 CollectionManager 签名调用一致。
- FormDataManager：
  - 在透传 tenantId 给 CollectionManager 后，原有表单数据保存/读取逻辑仍然正常；
- 所有受影响测试与 CLI 命令通过（包括 Feature Test 与低代码相关 CLI）。
