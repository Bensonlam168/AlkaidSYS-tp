# 租户参数契约与 fail-closed 约定（TENANT-01）

> 适用范围：`infrastructure/**/Repository/` 下的仓储类、任何在 `where` / `update` / `delete` 条件里落到 `tenant_id` 的代码，以及它们的调用方（Service / Controller / Command / 测试夹具）。
>
> 目标：把「租户参数」从口头约定变成**类型系统 + 静态守卫**能守住的契约 —— 让「忘记传租户」在测试阶段就抛错，而不是在线上变成一次跨租户查询或跨租户删除。

> 体例说明：本文与同目录 [原生 SQL 多租户安全检查清单（RAWSQL-01）](./rawsql-safety-checklist.md) 并列。
> **RAWSQL-01 管「必须写原生 SQL 时怎么隔离」；TENANT-01 管「参数层面怎么保证隔离条件一定在」。** 两者会被同一批代码同时命中。

---

## 1. 三条硬规则（结论先行）

- [ ] **必填**：凡是决定「是否按租户过滤」的参数，签名必须是 `int $tenantId` —— 不得可空、不得有默认值。
- [ ] **fail-closed**：缺少租户上下文时必须**抛异常**，不得退化为「不按租户过滤」。
- [ ] **逃生口显式命名**：确实需要跨租户时，新增 `xxxUnscoped()` 之类的方法；**禁止**用 `null` 或魔法数字 `0` 当开关。

判据（一句话版本，记住这一句就够）：

> **「省略参数」不能等价于「不过滤」。**

只要某个默认值让「少写一个实参」和「跨租户查询」变成同一件事，这个签名就是错的 ——
与它当前是否被滥用无关，与调用方是否「都很规矩」无关。

---

## 2. 规则一 · 租户参数必须必填

### 2.1 哪些参数算「决定是否按租户过滤」

- [ ] 直接出现在 `where('tenant_id', $x)` 条件里的参数；
- [ ] 传给仓储、最终落到上述条件的参数（`findById` / `delete` / `list` / `save` 的租户形参）；
- [ ] 参与**缓存键**（`buildCacheKey($name, $tenantId)`）或**物理表名**（`TableNameGenerator::generate()`）的参数 ——
  它们决定「读到谁的缓存 / 哪张表」，在本规范里与过滤条件等价；
- [ ] 「解析租户上下文」的辅助方法返回值（如 `requireTenantId()`），其失败语义见 §3。

不算的（见 §5 已知例外）：写进 `tenant_id` 列的**业务默认值**，以及不参与业务数据过滤的审计维度。

### 2.2 正例 / 反例

❌ **反例：可空 + 默认 null（fail-open）** —— `RoleRepository::findById()` 修复前的形态（commit `23cffeb` 之前）：

```php
// 反例：调用方少传一个参数时，where('tenant_id') 整段消失，查询悄悄变成跨租户
public function findById(int $id, ?int $tenantId = null): ?Role
{
    $query = Db::name($this->table)->where('id', $id);

    if ($tenantId !== null) {
        $query->where('tenant_id', $tenantId);
    }

    $data = $query->find();
    // ...
}
```

✅ **正例：租户必填，条件恒定存在** —— 现行 `infrastructure/Permission/Repository/RoleRepository.php:33`

```php
public function findById(int $id, int $tenantId): ?Role
{
    $data = Db::name($this->table)
        ->where('id', $id)
        ->where('tenant_id', $tenantId)   // 无条件、无分支
        ->find();

    if ($data === null) {
        return null;
    }

    return $this->hydrate($data);
}
```

✅ 同一契约下的 `delete()` —— `infrastructure/Permission/Repository/RoleRepository.php:210`：
`delete(int $id, int $tenantId)`，`where('id', $id)->where('tenant_id', $tenantId)->delete()`。

为什么「无分支」很重要：可空参数的风险不在 `if` 写错，而在**这个 `if` 给了代码一条「不按租户过滤」的合法路径**。
分支一旦存在，任何人（包括三个月后的自己）都能通过「少传一个参数」走进那条路径，而 PHP 不会报错。

### 2.3 禁止的「假必填」：默认值改 0 再 `if ($tenantId > 0)`

把 `?int $tenantId = null` 改成 `int $tenantId = 0` 并**不算**修好 —— 调用方依然可以省略实参，
只是把「不过滤」的判据从 `null` 换成了 `0`：

```php
// ❌ 假必填（示意代码，仓库中当前无此写法）：省略实参仍然等价于「不按租户过滤」
public function delete(int $id, int $tenantId = 0): bool
{
    $query = Db::name($this->table)->where('id', $id);

    if ($tenantId > 0) {
        $query->where('tenant_id', $tenantId);
    }

    return $query->delete() > 0;
}
```

静态守卫把这种写法列为独立的禁止项（`tests/Unit/Infrastructure/TenantScopedRepositorySignatureGuardTest.php:56` 的
`FORBIDDEN_PATTERNS` 第 2 条 `int $tenantId 带默认值（假必填）`），正是因为它**看起来**已经改掉了 `?`。

> 真正的判据不是「类型是不是 `int`」，而是「不传这个参数还能不能编译/调用成功」。

### 2.4 历史反例一：`RoleRepository::save()` 更新分支只按 `id` 定位（已修）

commit `23cffeb`（`fix(tenant): Role 更新分支补租户条件，核心仓储租户参数改必填`）之前的代码：

```php
// 反例（历史代码）：更新分支只有 where('id')，可跨租户改写他人角色，
// 且 $data 里带着 tenant_id，实测能把他人的角色「改挂」到自己租户下。
if ($role->getId()) {
    $data['updated_at'] = $now;
    Db::name($this->table)->where('id', $role->getId())->update($data);
    return $role->getId();
}
```

**症状**：读路径（`findById`）是否带租户条件，与写路径无关。把读路径修好之后，写路径仍然可以按主键命中任意租户的行。

**怎么改**（现行 `RoleRepository.php:131` 起）：

1. 更新分支同时带 `where('id', ...)` 与 `where('tenant_id', $role->getTenantId())`；
2. `$affected === 0` **不能**直接判失败 —— MySQL 对「新值与旧值完全相同」同样返回 0 行；
3. 因此回查该 `(id, tenant_id)` 是否仍存在，用于区分「值没变」与「越权/不存在」；
4. 后者抛 `Domain\Permission\Exception\RoleWriteRejectedException`，**绝不**静默当作成功。

```php
// ✅ 现行写法（节选 RoleRepository::save()）
$affected = Db::name($this->table)
    ->where('id', $roleId)
    ->where('tenant_id', $tenantId)
    ->update($data);

if ($affected === 0) {
    $existsInTenant = Db::name($this->table)
        ->where('id', $roleId)
        ->where('tenant_id', $tenantId)
        ->count() > 0;

    if (!$existsInTenant) {
        $this->rejectWriteOutOfTenant($roleId, $tenantId);
    }
}
```

> `rejectWriteOutOfTenant()` 内部还会按 `id` **无租户条件**再查一次 —— 但那一次查询只用于**区分拒绝原因**（属别的租户 / 根本不存在），
> 结果只进入异常消息，不参与任何写入或放行判断。**「用于报错的跨租户读」和「用于放行的跨租户读」必须区分开。**

### 2.5 历史反例二：`CollectionManager::delete()` 调用仓储时省略租户（已修）

commit `57b798e`（`fix(lowcode): 收敛全部租户参数为必填，修掉跨租户删除集合`）之前的代码：

```php
// 反例（历史代码）：仓储侧执行的是 DELETE ... WHERE id = ?（无租户条件），
// 租户 B 可以删掉租户 A 的集合并连带 DROP 掉物理表。
public function delete(string $name, bool $dropTable = true, ?int $tenantId = null): void
{
    // ...
    // 3. Delete collection | 删除Collection
    $this->collectionRepo->delete($collectionId);   // ← 租户被整体省略

    // 4. Drop physical table if requested
    if ($dropTable && $this->schemaBuilder->hasTable($collection->getTableName())) {
        $this->schemaBuilder->dropTable($collection->getTableName());
    }
}
```

**症状**：同一个方法里，前两步（删关系、删字段）都乖乖传了 `$effectiveTenantId`，第三步漏了 ——
漏传不会报错，只会少一个 `where`。这是「可空参数」最典型的事故形态：**不是有人故意绕过，而是有人少写一个实参**。

**怎么改**（现行 `infrastructure/Lowcode/Collection/Service/CollectionManager.php:237`）：

1. 签名去掉 `?` 与默认值：`delete(string $name, bool $dropTable, int $tenantId)`；
2. 租户一路传到第三步：`$this->collectionRepo->delete($collectionId, $tenantId)`（`:256`）；
3. `$dropTable` 也一并去掉默认值 —— 否则会触发 PHP「可选参数在必填参数之前」的弃用告警
   （现有调用点本来就都显式传前三个参数）；
4. 仓储侧同步改成 `delete(int $id, int $tenantId)`（`CollectionRepository.php:146`），
   并在方法注释里保留「修复前会按主键跨租户删除」的记录（`:138`）。

**验收证据**（按方法名定位，行号会随测试文件演进漂移）：
`tests/Unit/Infrastructure/Lowcode/Collection/Repository/CollectionRepositoryTest.php`
的 `testDeleteTenantParameterIsMandatoryAndNotNullable()`（写作时 `:193`）用反射盯住签名
（`isOptional()` 为 false、`getType()` 为 `int`、`allowsNull()` 为 false）；
`testDeleteCannotRemoveAnotherTenantsCollection()`（写作时 `:213`）用行为用例证明「租户 B 删不掉租户 A 的集合」。

---

## 3. 规则二 · fail-closed：缺少租户上下文必须显式失败

### 3.1 规则

- [ ] 解析不到租户上下文时 **抛异常**，不得让执行继续；
- [ ] **禁止** `$data['tenant_id'] ?? null` 这类把「缺失」转成「不过滤」的写法 ——
  它读起来像防御性编程，实际是把缺失降级成了跨租户；
- [ ] 同样禁止 `?? 0` 静默降级（详见 §3.4）；
- [ ] 异常的触发点尽量**靠前**：在打开连接、写事务之前就失败，而不是写了一半才报错。

### 3.2 为什么是异常，不是警告、不是返回值

警告不会让任何东西停下来。本项目已经踩过两次同一个坑，先例就在代码注释里：

- [ ] `tests/ThinkPHPTestCase.php:247` 的守卫方法注释（`assertTestDatabaseName()` 的 docblock）：

  > 刻意用异常而不是 `trigger_error` / 输出警告：警告不会让 PHPUnit 停下来，测试会继续在开发库上 CRUD —— 那正是本函数要消灭的行为。

  同文件 `:20` 记录了这个坑的代价（ER-15）：本地跑测试 = 直接写开发库，开发库已沉淀 **369 张表 / 207 个租户**。

- [ ] `domain/Permission/Exception/RoleWriteRejectedException.php:20` 的类注释：

  > 为什么用异常而不是返回 false：`save()` 的返回类型是 `int`（角色 ID），返回 0 或负数会被误当作合法 id；
  > 让越权写入「抛出来」才能保证不可能被上层忽略。

结论：**返回值可以被忽略，警告可以被淹没，异常不能。** 租户边界属于「一旦忽略就是数据事故」的那一类，
所以只能用异常。

### 3.3 为什么 HTTP 层统一转 404，不区分「不存在」与「属于他租户」

如果「不存在」返回 404、「属于他租户」返回 403，攻击者就能用状态码**探测 id 是否存在**：

```
GET /v1/admin/roles/999   → 404   （不存在）
GET /v1/admin/roles/1001  → 403   （存在，但不是你的）
```

后者等于免费告诉调用方「这个 id 是真的」。因此两者必须对外**不可区分**。

现行做法 —— `app/controller/admin/RoleController.php:272`（`update()` 的兜底分支）：

```php
try {
    $this->roleRepository->save($newRole);
} catch (RoleWriteRejectedException $e) {
    // 兜底：本方法开头已用 findById($id, $tenantId) 校验过归属，
    // 走到这里只可能是「校验通过后角色归属被并发改动」。
    // 对外统一回 404（不区分「不存在」与「属于别的租户」，避免泄露他租户数据的存在性）。
    return $this->error(
        $this->langService->trans('role.not_found'),
        404,
        [],
        404
    );
}
```

- [ ] `read()`（`:111`）、`update()`（`:209`）、`delete()`（`:310`）、`setPermissions()`（`:376`）
  对「本租户内 `findById` 返回 null」一律回 **404**，文案统一为 `role.not_found`；
- [ ] 也就是说：**「租户内查不到」和「根本不存在」从对外接口看是同一件事**；
- [ ] 日志/异常消息里可以区分（便于排障），**HTTP 响应里不可以**。

### 3.4 反例 / 正例：`?? null` 与 `?? 0`

❌ **反例（历史代码）** —— `infrastructure/Lowcode/FormDesigner/Service/FormSchemaManager.php:75` 的注释保留了它：

```php
// 反例（历史代码）：payload 里没有 tenant_id 时，
// `?? null` 把「缺失租户」退化成了「不按租户过滤」，读回来的就是任意租户的行。
$form = $this->repository->findById($formId, $data['tenant_id'] ?? null);
```

✅ **正例：先显式解析，再带着它去读** —— 现行同一文件的 `requireTenantId()`（`:107`）：

```php
protected function requireTenantId(array $data): int
{
    if (!array_key_exists('tenant_id', $data) || $data['tenant_id'] === null || $data['tenant_id'] === '') {
        throw new \InvalidArgumentException(
            'tenant_id is required for form schema read/write: 缺失租户上下文时不允许继续'
            . '（旧实现会退化成「不按租户过滤」的读，属于跨租户缺陷）'
        );
    }

    if (!is_numeric($data['tenant_id'])) {
        throw new \InvalidArgumentException('tenant_id must be numeric for form schema operations');
    }

    $tenantId = (int) $data['tenant_id'];
    if ($tenantId <= 0) {
        throw new \InvalidArgumentException(
            "tenant_id must be a positive tenant ID for form schema operations, got {$tenantId}"
        );
    }

    return $tenantId;
}
```

调用点：`$tenantId = $this->requireTenantId($data);` → `$this->repository->findById($formId, $tenantId);`。

要点：

- [ ] 判「键是否存在」用 `array_key_exists()`，**不要**用 `isset()`/`??` —— 它们把「键存在但值是 null」和「键不存在」混为一谈；
- [ ] `0` / 负数 / 非数字要单独判：`0` 在这个上下文里不是「系统模板空间」，而是非法租户（该表有外键指向 `tenants`）；
- [ ] 校验完成后返回**非空 `int`**，下游不再有任何分支。

> 注意「`?? 0` 静默降级」和「业务默认值 `0`」是两件事，区分方法见 §5.2。

---

## 4. 规则三 · 逃生口必须显式命名

### 4.1 禁止用 `null` / `0` 当跨租户开关

- [ ] 不要为了「偶尔需要看全量」而把参数改回可空；
- [ ] 不要把 `0` 定义成「所有租户」——`0` 在现有代码里的既定语义是**系统模板空间**，
  让它兼职「跨租户」会让同一个值有两种含义，读代码的人无法判断拿到的是哪一义；
- [ ] 不要把「不过滤」写成 `where('tenant_id', '>', 0)` 之类的变体 —— 判据（静态守卫）认的是**签名**，不是方法体，
  这种写法会绕过守卫并在 review 时被当成「正常的条件」。

### 4.2 什么情况才算「真的需要」

三条**同时**满足才算，缺一条就不是「真的需要」，而是「绕一下更快」：

- [ ] **它是产品需求本身**，不是实现便利：例如平台管理端跨租户统计、运维对账脚本、数据迁移/修数；
- [ ] **调用点可以穷举**（个位数），且每个调用点都有**独立的权限校验**，而不是「登录即可」；
- [ ] **有审计留痕**：`trace_id` + 调用方 + 影响范围，与 RAWSQL-01 §4 的要求一致。

### 4.3 当前代码库的核查结果（写规范时的实测）

- [ ] 仓库中**不存在** `findByIdUnscoped()` / `listAllTenants()` 之类的仓储级跨租户方法，
  也**没有**任何调用方。核查命令与实测命中（`grep` 原始输出 10 行，归并后 4 类，
  **没有任何一处是仓储级跨租户方法的定义或调用**）：

  ```bash
  docker exec alkaid-backend grep -rn 'Unscoped\|listAllTenants\|withoutTenant' app domain infrastructure tests
  ```

  | 命中位置 | 性质 |
  | --- | --- |
  | `infrastructure/Lowcode/Collection/Repository/CollectionRepository.php:114` | 注释（约定「将来需要时新增」） |
  | `tests/Unit/Infrastructure/TenantScopedRepositorySignatureGuardTest.php:31`、`:103` | 守卫的提示文案 |
  | `tests/Unit/Lowcode/FormDesigner/FormSchemaManagerTest.php:243` | 用例方法名 `testReadPathRejectsMissingTenantInsteadOfUnscopedLookup` |
  | `app/model/BaseModel.php:399` | legacy 方法的**定义**（见下条） |
- [ ] 唯一的「跨租户能力」是 legacy 的 `app/model/BaseModel.php:399` `withoutTenantScope()`，
  它被 `tests/Legacy/WithoutTenantScopeUsageGuardTest.php:17` 静态禁止调用
  （扫描 `app/` `domain/` `infrastructure/` `tests/` `plugins/`，白名单只放行守卫文件自己）；
- [ ] 结论：**当前没有任何合法的跨租户调用方**。新增第一个之前，先回到 §4.2 逐条确认。

### 4.4 命名与注释约定（伪代码，仓库中尚无此方法）

```php
// ⚠️ 伪代码：仓库中当前不存在 findByIdUnscoped()，此处仅示范命名与注释形状，勿照抄引用。
//
// 平台级跨租户读：**不按 tenant_id 过滤**。
// 调用它即可被 grep 审计：本方法的每个调用点都必须能说出「为什么需要跨租户」。
// 审计命令：grep -rn 'findByIdUnscoped(' app domain infrastructure
public function findByIdUnscoped(int $id): ?SomeEntity
{
    // 方法名自带 Unscoped 后缀，grep 一次就能列全调用点
}
```

- [ ] 命名必须让**读到调用点的人**一眼看出它没有租户过滤：`Unscoped` / `AllTenants` / `AcrossTenants`；
- [ ] 必须保留一个只返回 `int` 的必填版本（`findById(int $id, int $tenantId)`），
  而不是把原方法改成 `findById(int $id, int $tenantId, bool $crossTenant = false)`
  —— 布尔开关同样是「隐藏的跨租户后门」，且 grep 不出调用意图；
- [ ] 仓库里已有同款约定可引用：`infrastructure/Lowcode/Collection/Repository/CollectionRepository.php:114`
  的注释写着「若将来确实需要平台级遍历，请新增显式命名的 `findByIdUnscoped()`（可被 grep 审计），**不要**在这里把参数改回可空」。

---

## 5. 已知例外（必须读完，否则本规范会误伤）

### 5.1 表本身没有 `tenant_id` 列 —— 单参 `findById($id)` 是合法的

**先查 `information_schema`，不要靠猜。** 复核命令：

```bash
# 口令用本机 .env 里的 DB_PASS（-it 是为了让 mysql 能提示输入口令）
docker exec -it alkaid-mysql mysql -uroot -p -e "
SELECT TABLE_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS cols
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='alkaid_sys'
  AND TABLE_NAME IN ('permissions','tenants','role_permissions','user_roles','roles')
GROUP BY TABLE_NAME ORDER BY TABLE_NAME;"
```

实测结果（2026 年本仓库 `alkaid_sys`）：

| 表 | 有 `tenant_id` | 列 |
| --- | --- | --- |
| `permissions` | ❌ 无 | `id,name,slug,resource,action,description,created_at,updated_at` |
| `tenants` | ❌ 无（它本身就是租户表） | `id,name,slug,domain,status,config,max_sites,max_users,expires_at,created_at,updated_at` |
| `role_permissions` | ❌ 无 | `role_id,permission_id,created_at` |
| `user_roles` | ❌ 无 | `user_id,role_id,created_at` |
| `roles` | ✅ 有 | `id,tenant_id,name,slug,description,is_system,created_at,updated_at` |

因此下面这些**单参**调用合法，不属于本规范的违规：

- [ ] `infrastructure/Tenant/Repository/TenantRepository.php:39` — `findById(int $id): ?Tenant`
- [ ] `infrastructure/Permission/Repository/PermissionRepository.php:28` — `findById(int $id): ?Permission`
- [ ] `infrastructure/Permission/Repository/RoleRepository.php:245` — `getRolePermissionIds(int $roleId)`（读 `role_permissions`）
- [ ] `infrastructure/Permission/Repository/RoleRepository.php:301` — `getAllPermissions()`（读 `permissions`）
- [ ] `app/controller/admin/RoleController.php:330` — 统计 `user_roles` 判断「角色是否还有用户」

⚠️ **但这些表有前置依赖**：`role_permissions` / `user_roles` 没有 `tenant_id`，
它们的隔离性来自「`role_id` 已经在租户范围内被校验过」。所以调用顺序必须是：

```php
// ✅ 先按 (id, tenantId) 确认角色属于自己的租户，再动 role_id 维度
$role = $this->roleRepository->findById($id, $tenantId);   // RoleController::setPermissions():374
if ($role === null) { /* 404 */ }

$this->roleRepository->syncPermissions($id, array_map('intval', $data['permissions']));
```

反过来说：**任何跳过「先按租户校验父实体」直接操作关联表的代码，等于绕过了隔离**，
不能以「这张表没有 `tenant_id`」作为免责理由。

### 5.2 业务默认值 `0` —— 不参与「是否过滤」的判断

- [ ] `infrastructure/Lowcode/Collection/Service/TableNameGenerator.php:18`
  `generate(string $collectionName, int $tenantId = 0, int $siteId = 0)`：
  `0` 只用于选**命名策略**（`tenant_prefixed` 且 `$tenantId > 0` 才加前缀），
  默认策略是共享表 `lc_{name}`，**不产生任何 where 条件**。
- [ ] `infrastructure/Lowcode/Schema/SchemaVersionService.php:49`
  `record(string $objectType, string $objectName, array $schema, int $tenantId = 0, int $siteId = 0, ?int $currentId = null)`：
  `0` 表示「系统模板空间的审计行」。该方法里的 `where('tenant_id', ...)` 只用于定位**同一条审计记录的上一个版本**
  （配合 `object_type` / `object_name` 自增 `version`），不用于读取业务数据，越权也读不到业务行。

判据：**问「这个 `0` 是决定『过滤谁的数据』，还是只决定『数据自己贴哪个标签 / 拼哪个名字』」**。
前者适用本规范，后者不适用。

> 反过来说，`0` 一旦被用来「跳过 where 条件」，它就不再是业务默认值，而是 §2.3 的「假必填」，必须改。

### 5.3 实体属性与 DTO 的可空字段 —— 静态守卫不覆盖

静态守卫只看**方法参数**，不看属性。以下可空属性本身不违规：

- `domain/Lowcode/Collection/Model/Collection.php:39` — `protected ?int $tenantId = null;`
- `domain/Event/EventContext.php:16` — `protected ?int $tenantId = null`

但要注意它们的下游用法：`CollectionManager::create()`（`:59`）与 `update()`（`:180`）里
`$collection->getTenantId() ?? 0` 仍是「缺失 → 系统模板空间」的静默降级，
属于**本规范尚未收敛的残余**（详见 §8），不要以为「守卫是绿的」就等于这一层也干净。

---

## 6. 强制机制：静态守卫 + 豁免自净

### 6.1 静态守卫

**位置**：`tests/Unit/Infrastructure/TenantScopedRepositorySignatureGuardTest.php`

- [ ] **扫描范围**：`infrastructure/**/Repository/*.php` —— 按目录名 `Repository` 匹配，不写死文件名，
  新增仓储自动纳入（`:259`）；
- [ ] **判定对象**：方法签名里的**参数**，不解析方法体（`:291`）—— 契约层的可空/默认值才是漏洞来源；
- [ ] **三种禁止写法**（`:56`）：

  | 正则 | 命中 | 为什么禁 |
  | --- | --- | --- |
  | `/\?\s*int\s+\$tenantId(?![A-Za-z0-9_])/` | `?int $tenantId` | 可空租户参数 |
  | `/\bint\s+\$tenantId\s*=\s*(?:null|\d+)/` | `int $tenantId = 0` | 假必填 |
  | `/\$(?:tenantId)\s*=\s*null/` | `$tenantId = null`（任何类型） | 省略即不过滤 |

- [ ] **fail-closed 自检**：
  - `scanAllRepositories()` 若一个仓储文件都没扫到，直接 `assertGreaterThan(0, $scannedFiles)` 失败（`:276`）——
    「没有违规」不能建立在「什么都没扫」之上；
  - `testScannerDetectsForbiddenSignatures()`（`:196`）用一段内联样例反向验证正则本身没坏，
    防止「正则写错 → 永远绿」这种最隐蔽的失效。

### 6.2 豁免机制（当前 `EXEMPTIONS = []`）

`TenantScopedRepositorySignatureGuardTest::EXEMPTIONS`（`:74`）与 `scripts/check-migrations.php` 的
`ORPHAN_APPLIED_EXEMPTIONS` 是**同一套模式**：每条豁免按「文件 + 方法」粒度生效，必须三件套齐全：

```php
// 结构示意（当前 EXEMPTIONS 为空数组，故此为伪代码）
private const EXEMPTIONS = [
    'infrastructure/Xxx/Repository/YyyRepository.php' => [
        'methods'   => ['findById', 'delete'],
        'reason'    => '为什么可以暂时不满足（写清楚不是「懒得改」）',
        'review_by' => 'YYYY-MM-DD',
    ],
];
```

- [ ] `methods`：非空数组，按 `文件::方法` 粒度 —— 不做整文件豁免；
- [ ] `reason`：非空字符串，写**为什么**，不写「TODO」；
- [ ] `review_by`：`YYYY-MM-DD` 且必须是**真实存在的日期**（`2027-02-30` 会被回写比对识别，`:138`）。

三种自净标签（由守卫自己的断言产出，不是人工标记）：

| 标签 | 触发条件 | 处置 |
| --- | --- | --- |
| `[EXEMPT-META]` | 元数据缺失或格式非法（缺 `reason`/`methods`、日期格式错） | 写豁免的人补齐 |
| `[EXEMPT-EXPIRED]` | 已过 `review_by` | 重新判断：要么收敛并删条目，要么更新理由并续期 |
| `[EXEMPT-STALE]` | 条目指向的方法**已经没有该问题**，或文件已不存在 | 立刻删除条目（不允许留僵尸豁免） |

### 6.3 怎么加一个新仓储（可操作步骤）

- [ ] 1. 先查表有没有 `tenant_id` 列（§5.1 的 SQL）。**没有就按 §5.1 处理，不要为了「统一」硬加参数**；
- [ ] 2. 有 `tenant_id` 的话，所有按主键/按业务键的读写方法签名一律写成 `(..., int $tenantId)`：
      不写 `?`、不写 `= null`、不写 `= 0`；
- [ ] 3. `where('tenant_id', $tenantId)` 与 `where('id', $id)` **放在同一个链式调用里**，
      不要写成「先查再在 PHP 里比对」（那就变成全表扫描 + 手工过滤，同时违反 RAWSQL-01 §2）；
- [ ] 4. 更新/删除的返回值语义要明确：`update()` 返回 0 行可能是「值没变」，不要直接当失败（见 §2.4），
      `delete()` 返回 `false` 表示「本租户内没有这行」，不是「系统错误」；
- [ ] 5. 写一个行为用例（租户 B 读/删不到租户 A 的行）+ 一个反射用例（签名不可为 null），
      参考 `CollectionRepositoryTest.php:193` 与 `:213`（按方法名定位更稳）；
- [ ] 6. 跑 §6.5 的命令。

### 6.4 怎么加一条新豁免（应当少见）

- [ ] 1. 先确认**真的**改不动（例如第三方约束、跨版本兼容），把原因写进 `reason`；
- [ ] 2. `methods` 只列真正有问题的那几个方法，不要整文件；
- [ ] 3. `review_by` 写一个**明确的到期日**（建议不超过一个迭代周期），不要写远期占位；
- [ ] 4. 跑一次守卫，确认没有 `[EXEMPT-META]`；
- [ ] 5. 到期后 `[EXEMPT-EXPIRED]` 会让守卫失败 —— 这是设计意图，不是 bug。

### 6.5 改完后必须跑的命令

> 形态说明：`php think test` 只接受 `--testsuite` / `--filter` / `--configuration` 等选项，**没有位置参数**
> （见 `app/command/TestCommand.php:184` 与 `:190`），所以「只跑某一个测试类」要用 `--filter=<类名>`，
> 不能写 `php think test tests/xxx/SomeTest.php`。

```bash
# 1) 静态守卫：唯一能机械发现「租户参数又变回可空/带默认值」的手段
docker exec alkaid-backend php think test --filter=TenantScopedRepositorySignatureGuardTest

# 2) 受影响仓储的行为用例（按你改到的模块挑，至少跑对应的那个）
docker exec alkaid-backend php think test --filter=RoleRepositoryTenantIsolationTest
docker exec alkaid-backend php think test --filter=CollectionRepositoryTest
docker exec alkaid-backend php think test --filter=CollectionManagerTest

# 3) legacy 跨租户后门守卫（确认没人新开 withoutTenantScope 的口子）
docker exec alkaid-backend php think test --filter=WithoutTenantScopeUsageGuardTest

# 4) 手工复核：仓库里还有没有可空租户参数（守卫只覆盖 Repository 目录）
docker exec alkaid-backend grep -rn '?int \$tenantId' infrastructure/

# 5) 文档门禁（改动了本文/README 时必跑）
docker exec alkaid-backend php scripts/check-docs.php --strict
```

第 4 条不是冗余：静态守卫的扫描范围是 `infrastructure/**/Repository/`，
**Service / Manager / Command 层不在其中**（已知覆盖缺口，见 §8）。

---

## 7. 规范条目 → 代码位置索引

| # | 规范条目 | 实际文件:行 | 状态 |
| --- | --- | --- | --- |
| 1 | ✅ 正例：`findById(int $id, int $tenantId)` | `infrastructure/Permission/Repository/RoleRepository.php:33` | 现行 |
| 2 | ✅ 正例：`delete(int $id, int $tenantId)` | `infrastructure/Permission/Repository/RoleRepository.php:210` | 现行 |
| 3 | ✅ 正例：同一契约的其它仓储 | `Organization/Repository/DeptRepository.php:32`、`MenuRepository.php:32`、`User/Repository/UserRepository.php:55`、`Lowcode/Collection/Repository/CollectionRepository.php:121` | 现行 |
| 4 | ❌ 反例：更新分支只按 `id` 定位 | `RoleRepository.php:144`（修复后）／commit `23cffeb` 之前 | 已修 |
| 5 | ❌ 反例：调用仓储时省略租户 | `CollectionManager.php:256`（修复后）／commit `57b798e` 之前 | 已修 |
| 6 | ❌ 反例：`$data['tenant_id'] ?? null` | `FormSchemaManager.php:75`（注释中留档） | 已修 |
| 7 | ✅ fail-closed/异常：`requireTenantId()` | `infrastructure/Lowcode/FormDesigner/Service/FormSchemaManager.php:107` | 现行 |
| 8 | ✅ 警告不会让测试停下（先例） | `tests/ThinkPHPTestCase.php:247` | 现行 |
| 9 | ✅ 异常而非返回 false（先例） | `domain/Permission/Exception/RoleWriteRejectedException.php:20` | 现行 |
| 10 | ✅ HTTP 统一 404、不区分存在性 | `app/controller/admin/RoleController.php:272` | 现行 |
| 11 | ✅ 显式命名逃生口（注释约定） | `infrastructure/Lowcode/Collection/Repository/CollectionRepository.php:114` | 现行 |
| 12 | ✅ legacy 跨租户后门被静态禁止 | `tests/Legacy/WithoutTenantScopeUsageGuardTest.php:17` | 现行 |
| 13 | ⚪ 已知例外：无 `tenant_id` 列的表 | `Tenant/Repository/TenantRepository.php:39`、`Permission/Repository/PermissionRepository.php:28` | 现行 |
| 14 | ⚪ 已知例外：业务默认值 `0` | `TableNameGenerator.php:18`、`SchemaVersionService.php:49` | 现行 |
| 15 | 🛡 静态守卫 | `tests/Unit/Infrastructure/TenantScopedRepositorySignatureGuardTest.php:56`（规则）/ `:74`（豁免） | 现行 |

---

## 8. 本规范的边界与未收敛项

**属于本规范（会写检查项）**：方法签名的可空/默认值、缺失租户时的失败方式、逃生口的命名与可见性、HTTP 层错误码的存在性泄露。

**不属于本规范（留给设计文档 / 既有专项规范）**：

- 租户上下文**从哪来**（JWT claim → 中间件 → `Request` 的解析优先级与降级策略）；
- 单库行级隔离 vs 分库分表的选型、`site_id` 二级维度的语义；
- `tenant_id` 的建表约束（非空、默认值、外键）；
- 跨租户能力的权限模型设计。

**当前已知的未收敛项（守卫是绿的，但不代表这些地方干净）**：

- [x] ~~`app/Request.php:86` `tenantId(?int $default = 1)` 在无认证上下文、无 `X-Tenant-ID` 时**回落到默认租户 1**~~
      —— **已收敛（2026-10-31）**。`tenantId(): int` 已删除参数与默认值，解析不出即抛
      `app\exception\TenantContextMissingException`（请求级 HTTP 400 / 业务码 1001）；
      需要「允许无租户」的调用点改用新增的 `tenantIdOrNull(): ?int`。
      「默认租户」现在只由 `app/middleware/TenantIdentify.php` 一处决定，且必须由
      `DEFAULT_TENANT_ID` **显式配置**（代码中的隐式 `1` 已删除，未配置即
      `TenantContextMissingException` 配置级 HTTP 500 / 业务码 5000）。
      落到默认租户的请求会写 `[TENANT_IDENTIFY_DEFAULT]` 审计日志，可被 grep 观测。
      本机 `.env` 与三份 `phpunit*.xml` 均已显式声明该配置。
- [ ] `CollectionManager::create()`（`:59`）与 `update()`（`:180`）对实体可空属性做 `?? 0`，
      缺失租户时静默归入系统模板空间（§5.3）；
- [ ] `infrastructure/Plugin/PluginManager.php:151 / :166 / :183` 的 `int $tenantId = 0` 不在 `Repository/` 目录下，
      **守卫扫不到**；其 `0` 语义是系统模板空间（插件安装状态），但省略实参仍会把状态写到租户 0，
      需要单独判断是否收敛；
- [ ] 静态守卫只覆盖 `infrastructure/**/Repository/`，Service / Manager / Command 层目前**没有**同等强度的守卫。
