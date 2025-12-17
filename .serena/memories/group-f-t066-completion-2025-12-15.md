# 组 F / T-066 完成记录（2025-12-15）

## 概要
- 任务：**[T-066] (P1) 统一低代码多租户语义与调用链**（LOWCODE-01 / LC-LOWCODE-01）
- 完成日期：2025-12-15
- 状态：**已完成**，Backlog 已从 `⬜` 更新为 `✅ [T-066] (P1) ... [已完成 2025-12-15]`。

## 主要改动点

### 1. Domain 层多租户语义
- `domain/Lowcode/Collection/Interfaces/CollectionInterface.php`
  - 新增 `getTenantId(): ?int` / `getSiteId(): ?int` 接口，禁止再依赖 `method_exists()` 做“鸭子类型”判断。
- `domain/Lowcode/Collection/Model/Collection.php`
  - `toArray()` 输出中增加 `tenant_id` / `site_id`，`fromArray()` 在 hydrate 时反向填充，保证 Domain 对象与 `lowcode_collections` 行数据的多租户信息一致。
- `domain/Lowcode/Collection/Interfaces/RelationshipInterface.php`
  - 新增 `getCollectionId(): int`，通过归属 Collection 承载租户语义，而不是在 Relationship 自身重复存储 `tenant_id` 字段。

### 2. Service 层多租户调用链
- `infrastructure/Lowcode/Collection/Service/FieldManager.php`
  - `addField` / `updateField` / `removeField` 方法签名均新增 `?int $tenantId = null, ?int $siteId = null`。
  - 统一使用 `$effectiveTenantId = $tenantId ?? 0` 作为实际租户 ID，并在调用 `CollectionManager::get()` 与 `clearCache()` 时显式传入该值。
  - Event payload 增加 `tenant_id` / `site_id`，便于审计与监控（配合 OBS-02）。
- `infrastructure/Lowcode/Collection/Service/RelationshipManager.php`
  - `addRelationship` / `removeRelationship` 方法签名同样增加 `?int $tenantId = null, ?int $siteId = null`。
  - 源/目标 Collection 均通过 `CollectionManager::get($name, $effectiveTenantId)` 获取，避免跨租户引用；缓存清理使用 `clearCache($collectionName, $effectiveTenantId)`。

### 3. Controller 层多租户上下文
- `app/controller/lowcode/FieldController.php`
  - 在 `save()` / `delete()` 中由 `$this->request->tenantId()` 和可选 `siteId()` 获取多租户上下文，并在调用 `FieldManager` 时透传，不再依赖请求体中的 `tenant_id`。
- `app/controller/lowcode/RelationshipController.php`
  - 在 `save()` / `delete()` 中统一从 Request 解析 `tenantId` / `siteId`，调用 `CollectionManager::get($collectionName, $tenantId)` 获取当前租户下的 Collection，并在调用 `RelationshipManager` 时透传租户上下文。

### 4. 测试补充
- 新增单元测试：
  - `tests/Unit/Infrastructure/Lowcode/Collection/Service/FieldManagerTest.php`
    - 覆盖 `addField` / `removeField` 在有 tenantId 时正确调用 `CollectionManager::get($collectionName, $tenantId)` 与 `clearCache($collectionName, $tenantId)`，并写入字段元数据。
  - `tests/Unit/Infrastructure/Lowcode/Collection/Service/RelationshipManagerTest.php`
    - 覆盖 `addRelationship` 在同一 tenant 下获取源/目标 Collection，`removeRelationship` 在同一 tenant 下查找并删除关系与缓存。
- 测试运行：
  - 在 `alkaid-backend` 容器内通过 `./vendor/bin/phpunit --configuration phpunit.xml` 针对上述两个 Test 文件执行，全部通过（仅有 PHPUnit 自身 Deprecation 提示）。

## 对设计/审计问题的回应
- 解决了设计审查中关于 LOWCODE-01 的核心偏差：
  - 多租户语义不再仅通过“模板租户 0 + 外部 tenantId 参数”隐式传递，而是在 Collection Domain 模型、Service 层调用与 Controller 层多租户上下文解析中形成显式、统一的契约；
  - Relationship 的租户语义通过 `collection_id -> lowcode_collections(tenant_id, site_id)` 间接承载，避免重复建模同时满足审计可追溯性；
  - Field/Relationship Service 与 Controller 的所有关键路径均已改为依赖 Request 上下文（tenantId/siteId），并与 CollectionManager 的租户维度缓存策略对齐；
  - 增加了针对 FieldManager/RelationshipManager 的多租户单元测试，作为低代码多租户测试矩阵的补充分支（为后续 T-070 扩展矩阵打基础）。

## 后续建议
- 在组 F 后续任务（T-067~T-070）中：
  - 避免新增对 Legacy Collection 栈（Domain\Model\Collection、Infrastructure\Collection\CollectionManager、旧 FieldTypeRegistry）的依赖；
  - 原生 SQL 相关任务（T-068）可参考本次对 Service/Controller 多租户调用链统一的方式，为 SQL 访问建立统一的租户上下文注入与校验规范；
  - 测试矩阵扩展（T-070）可以围绕新增加的 Field/Relationship 多租户能力，增加 Feature/E2E 级别的交叉租户场景覆盖。
