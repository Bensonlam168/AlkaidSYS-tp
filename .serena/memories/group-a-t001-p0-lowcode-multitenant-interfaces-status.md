# 组 A - T-001 P0 低代码 Collection 多租户接口改造：当前状态（含 HTTP Feature Test，2025-11-25）

## 已完成

1. **接口签名统一（P0 语义层，不改 DB 结构）**
   - `Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager`：
     - `get(string $name, ?int $tenantId = null): ?CollectionInterface`
     - `delete(string $name, bool $dropTable = true, ?int $tenantId = null): void`
     - `list(int $tenantId, array $filters = [], int $page = 1, int $pageSize = 20): array`
     - `clearCache(string $name, ?int $tenantId = null): void`
   - `app\\controller\\lowcode\\CollectionController`：
     - 所有多租户入口（`index/read/update/delete`）统一从 `Request::tenantId()` 获取租户；不再信任 body/query 中的 `tenant_id` 字段。
   - `Infrastructure\\Lowcode\\FormDesigner\\Service\\FormDataManager`：
     - `save()` / `getTableName()` 中对 `CollectionManager::get()` 的调用统一透传 `$tenantId`。
   - CLI：
     - `app\\command\\TestLowcodeCollection` / `TestCollection` 均更新为使用新签名（传 `null` 作为 `tenantId`），并在低代码测试命令中增加异常堆栈输出方便调试。

2. **单元测试与 CLI 测试验证**
   - 运行 `docker exec -it alkaid-backend ./vendor/bin/phpunit tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php`：
     - 全部通过（Assertions=17）。
     - Mockery 期望从 `get('test_collection')` 更新为 `get('test_collection', $tenantId)`，与新的多租户接口保持一致。
   - 运行 `docker exec -it alkaid-backend php think test:lowcode-collection` / `test:collection`：
     - 仍在创建集合阶段因 `Infrastructure\\Schema\\SchemaBuilder::assertCanRunDDL()` 抛出 `HttpResponseException`（DDL 防护）而失败，属于全局环境约束，与本次 T-001 改造无关；未对该防护做任何绕过或弱化。

3. **HTTP 层 Feature Test（CollectionController，多租户安全与错误处理）**
   - 新增文件：`tests/Feature/Lowcode/CollectionControllerTest.php`，基于 `Tests\\ThinkPHPTestCase`。
   - 关键用例：
     1. `testIndexIgnoresTenantIdInQuery`
        - 场景：`GET v1/lowcode/collections`，query 中伪造 `tenant_id=999`。
        - 通过 Mock `CollectionManager::list(1, ...)` 断言实际使用的租户 ID 为 `Request::tenantId()` 返回的 1，而非 query 中伪造的 999。
        - 断言响应为 HTTP 200 且业务 `code=0`，`data.list` 存在。
     2. `testReadIgnoresTenantIdInQuery`
        - 场景：`GET v1/lowcode/collections/test_collection`，query 中伪造 `tenant_id=999`。
        - 通过直接构造 `app\\Request` 并设置 `tenantId=1`，Mock `CollectionManager::get('test_collection', 1)` 返回实现了 `Domain\\Lowcode\\Collection\\Interfaces\\CollectionInterface` 的 Mock 对象。
        - 断言响应为 HTTP 200、`code=0`，`data.title` 等于 `Test Collection`，验证详情接口同样只信任请求上下文中的租户。
     3. `testUpdateIgnoresTenantIdInBody`
        - 直接构造 `app\\Request`，设置 `tenantId=1`，body 中伪造 `tenant_id=999`，并调用 `CollectionController::update()`。
        - 使用 Mockery 模拟 `Domain\\Lowcode\\Collection\\Interfaces\\CollectionInterface` 实例，并设置期望：
          - `CollectionManager::get('test_collection', 1)` 被调用一次；
          - `CollectionManager::update($collection)` 被调用一次；
          - `setTitle('Updated Title')` 被调用一次。
        - 断言响应为 HTTP 200、业务 `code=0`，`data.title='Updated Title'`。
     4. `testDeleteIgnoresTenantIdInBody`
        - 同样通过构造 `app\\Request`（`tenantId=1`，body 中伪造 `tenant_id=999`），直接调用 `CollectionController::delete()`。
        - 期望 `CollectionManager::delete('test_collection', false, 1)` 被调用一次，验证 Controller 仅信任租户上下文而忽略 body 中伪造的 `tenant_id`。
        - 断言响应为 HTTP 200、业务 `code=0`。
     5. `testCollectionsRequireAuthReturnsUnauthorizedWithTraceId`
        - 场景：`GET v1/lowcode/collections`，使用无效的 `Authorization` 头，附带 `X-Trace-Id`。
        - 通过完整 HTTP 流程（Auth + Permission 中间件）验证：
          - 响应为 HTTP 401；
          - 业务 `code=2001`；
          - 响应结构包含 `message`、`timestamp`、`trace_id`，其中 `trace_id` 与请求头一致。
   - 在容器内运行：`docker exec -it alkaid-backend ./vendor/bin/phpunit tests/Feature/Lowcode/CollectionControllerTest.php`
     - 当前结果：5 个用例全部通过（Tests=5, Assertions=17），仅有 1 条 PHPUnit 弃用警告（与框架内部 API 相关）。

## 待办与后续建议

1. **多租户异常场景的进一步覆盖（可选增强）**
   - 若后续在规范中明确“缺失/无效租户上下文”的错误码与行为，可追加：
     - 未附带有效 `tenant_id`（Token/头部均缺失）时的响应；
     - `tenant_id` 与用户账号不匹配时的错误处理与审计日志。

2. **为 T-002/T-003 提供基础**
   - 当前 `CollectionManager` 的 `tenantId` 参数已经在调用链和测试中固化，可直接作为后续表结构租户化与 Repository 过滤的契约基础：
     - 在 T-002 中扩展 `lowcode_collections` 表结构，并在 Repository 中按 `tenant_id` 过滤；
     - 在 T-003 中将动态业务数据表与访问路径完全租户化，确保与 Collection 元数据、FormDataManager 的租户上下文保持一致。

> 结论：T-001 P0 已完成接口签名统一 + 单元测试 + HTTP Feature Test，Backlog 中状态已更新为 DONE；CLI 级别的集合测试仍因全局 DDL 防护限制而无法在当前环境中完全跑通，但这不影响多租户接口与业务调用链的正确性与安全边界验证。