# 回归修复与 tenant_id 政策固化 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 修掉本会话引入的 2 个真实回归（C-7 的 9 处 TypeError、R6 的批次误标），并把「tenant_id 双语义」固化成可执行检查与文档规则。

**Architecture:** 回归修复遵循项目已有的同款修法（R1 的 `int|string` 放宽、以 `batch = 0` 作"未标记"判据）。政策固化分两层：静态检查（未应用的迁移文件）+ 数据库检查（已存在的列），两者都挂到既有 `scripts/check-migrations.php`，复用其 `--strict` 与 legacy 基线机制。

**Tech Stack:** ThinkPHP 8 / PHP 8.2 / PHPUnit 10 / PHPStan 风格检查脚本 / MySQL 8

**Spec:** `docs/report/design-implementation-reconstruction-2026-10-30.md` §遗留 R4/R11 + 本轮三份只读审计结论（`tenant_id` 二分法、C-7 类型链、R6 批次误标）

## Global Constraints

- **全程中文**：注释、提交信息、文档一律中文。
- **不得推送**：`remote.origin.pushurl` 已禁用，任何情况不 push。
- **提交绕过 commitlint 钩子**：`git -c core.hooksPath=/dev/null commit`。
- **测试基线不得增加失败**：全量 `./vendor/bin/phpunit` 的失败集去重必须 ≤ 41 项；新增测试不得落入失败清单。
- **迁移顺序约束**：`scripts/check-migrations.php` 要求新迁移 version > 已应用最大 version（当前 `20251223000001`）。本计划不新增迁移。
- **不改 `vendor/`、不改既有已应用迁移**。
- **每个提交前**：`php -l` 全部改动文件；`php scripts/check-migrations.php --strict` 与 `php scripts/check-docs.php --strict` 必须 exit=0。

## Review Focus

- **已认证用户调用** `POST /v1/workflow/instances`、`POST/PUT /v1/workflow/triggers*`：当前会 500 且状态已部分写入。修复后必须 201/200，且不得把"未登录"路径变成别的错误。
- **未登录 / `userId()` 返回 null**：`?? 'system'` 分支经 HTTP **不可达**（路由挂 `Auth` 中间件，先返回 401）。因此不写 HTTP 测试，改为在 Task 2 Step 5 用 `grep` 逐个确认调用点在"未认证"分支上仍产出字符串，不放宽任何行为。
- **`migrate:run` 在已全部应用的库上重跑**：必须不改任何行的 `batch`（幂等）。
- **`check-migrations.php` 对既有 34 个迁移**：不得因新增检查而报错（它们均已应用）。
- **`check-migrations.php --strict` 在 CI 语义下**：新增检查必须能在发现问题时真的 exit=1，不能只打印。

---

## Task 1: 修复 `addHistoryEntry` 的 int→string 类型崩溃

**Files:**
- Modify: `app/controller/workflow/WorkflowInstanceController.php:512-519`（形参 `string $userId` → `int|string $userId`；同文件 `:504-511` 的 docblock `@param string $userId` → `@param int|string $userId`）
- Test: `tests/Feature/Workflow/WorkflowInstanceHistoryTypeTest.php`（新建）

**Interfaces:**
- Produces: `WorkflowInstanceController::addHistoryEntry(string $instanceId, string $eventType, ?string $nodeId, string $description, int|string $userId): void`
- Consumes: 既有 `Tests\Helpers\AuthHelper::createAuthenticatedRequest()`、`Tests\ThinkPHPTestCase`

- [ ] **Step 1: 写失败测试**

新建 `tests/Feature/Workflow/WorkflowInstanceHistoryTypeTest.php`，命名空间 `Tests\Feature\Workflow`，继承 `Tests\ThinkPHPTestCase`。两个用例：

```php
public function testStartInstanceWithAuthenticatedUserDoesNotTypeError(): void
{
    // 已认证用户 → userId() 返回 int
    $response = $this->post('/v1/workflow/instances', ['definition_id' => 'wf_type_test']);
    $this->assertNotSame(500, $response->getCode(), '已认证用户启动实例不应 500（int 传入 string 形参的 TypeError）');
}

public function testStartInstanceRecordsHistoryEntry(): void
{
    $this->post('/v1/workflow/instances', ['definition_id' => 'wf_type_history']);
    // 通过反射读 self::$history，断言存在 STARTED 记录且 instance 已入库
}
```

权限：`workflow.instance.write` 需要播种（照抄 `WorkflowTaskClaimAuthorizationTest::seedAuthorizationFixtures()` 的写法：`permissions` 表 `resource='workflow'`、`action='instance:write'`，再绑 `role_permissions` + `user_roles`）。用户 ID 取 6xxx 区间并在 `setUp`/`tearDown` 清理 `self::$instances` / `self::$history`。

- [ ] **Step 2: 跑测试确认失败**

Run: `docker exec alkaid-backend ./vendor/bin/phpunit tests/Feature/Workflow/WorkflowInstanceHistoryTypeTest.php`
Expected: FAIL — `testStartInstanceWithAuthenticatedUserDoesNotTypeError` 得到 500；`TypeError: ... Argument #5 ($userId) must be of type string, int given`

- [ ] **Step 3: 改签名**

`app/controller/workflow/WorkflowInstanceController.php`：`addHistoryEntry` 第 5 个形参改 `int|string $userId`，docblock 同步。不改方法体。

- [ ] **Step 4: 跑测试确认通过**

Run: `docker exec alkaid-backend ./vendor/bin/phpunit tests/Feature/Workflow/WorkflowInstanceHistoryTypeTest.php`
Expected: OK（2 tests）

- [ ] **Step 5: 提交**

```bash
git add app/controller/workflow/WorkflowInstanceController.php tests/Feature/Workflow/WorkflowInstanceHistoryTypeTest.php
git -c core.hooksPath=/dev/null commit -m "fix(workflow): 修复实例历史记录形参类型导致的 500（C-7 回归）"
```

---

## Task 2: 修复 `addTriggerLog` 的同类崩溃

**Files:**
- Modify: `app/controller/workflow/WorkflowTriggerController.php:882`（`string $userId` → `int|string $userId`，docblock 同步）
- Test: `tests/Feature/Workflow/WorkflowTriggerLogTypeTest.php`（新建）

**Interfaces:**
- Produces: `WorkflowTriggerController::addTriggerLog(string $triggerId, string $event, int|string $userId, array $data): void`
- Consumes: Task 1 的测试基建（可直接复制其权限播种辅助方法，不改共享基类）

- [ ] **Step 1: 写失败测试**

两个用例：
```php
public function testCreateTriggerWithAuthenticatedUserDoesNotTypeError(): void
{
    $response = $this->post('/v1/workflow/triggers', [
        'definition_id' => 'wf_trigger_type_test',
        'name' => 'type test trigger',
        'type' => 'manual',
    ]);
    $this->assertNotSame(500, $response->getCode());
}

public function testUpdateTriggerWithAuthenticatedUserDoesNotTypeError(): void
{
    // 先创建再 PUT，覆盖 :369 的第二处调用
}
```
权限码 `workflow.trigger.write`（`resource='workflow'`、`action='trigger:write'`）。

- [ ] **Step 2: 跑测试确认失败**

Run: `docker exec alkaid-backend ./vendor/bin/phpunit tests/Feature/Workflow/WorkflowTriggerLogTypeTest.php`
Expected: FAIL — 500 + `TypeError: ... Argument #3 ($userId) must be of type string, int given`

- [ ] **Step 3: 改签名**

`app/controller/workflow/WorkflowTriggerController.php:882` 第 3 个形参改 `int|string $userId`，docblock 同步。

- [ ] **Step 4: 跑测试确认通过**

Run: `docker exec alkaid-backend ./vendor/bin/phpunit tests/Feature/Workflow/WorkflowTriggerLogTypeTest.php`
Expected: OK（2 tests）

- [ ] **Step 5: 顺手核对另外 7 处调用点未遗漏**

Run: `grep -n "?? 'system'" app/controller/workflow/*.php`
Expected: 9 行；确认其中落在 `addTaskHistory`（已放宽）与本次两个 helper 的调用全部覆盖，其余（如 `'started_by' => $userId` 直接入数组）不受类型约束影响。

- [ ] **Step 6: 提交**

```bash
git add app/controller/workflow/WorkflowTriggerController.php tests/Feature/Workflow/WorkflowTriggerLogTypeTest.php
git -c core.hooksPath=/dev/null commit -m "fix(workflow): 修复触发器日志形参类型导致的 500（C-7 回归）"
```

---

## Task 3: 修复 `migrate:run` 批次误标（全新库场景）

**Files:**
- Modify: `app/command/migrate/RunCommand.php:153`（UPDATE 增加 `AND batch = 0`）
- Test: 无 PHPUnit 覆盖（见 Step 5 说明），用脚本化复现验证

**Interfaces:**
- 不变（`markNewBatch(array $before, string $runStartedAt, Output $output): void` 签名不变）

- [ ] **Step 1: 记录当前批次分布作为基线**

Run:
```bash
docker exec alkaid-mysql mysql -uroot -proot -e "SELECT batch, COUNT(*), MIN(version), MAX(version) FROM alkaid_sys.migrations GROUP BY batch ORDER BY batch;"
```
Expected: batch 1..20，无 batch=0 的行。把输出存下来用于 Step 4 对比。

- [ ] **Step 2: 改 SQL**

`app/command/migrate/RunCommand.php:153` 的 UPDATE 语句末尾追加 `AND batch = 0`，并在方法注释里补一句：只有"从未打过批次"的行才允许被标记，避免全新库首跑时把已回填的历史行整体重打。

- [ ] **Step 3: 确认幂等（已全部应用的库上重跑不改任何 batch）**

Run:
```bash
docker exec alkaid-backend php think migrate:run
docker exec alkaid-mysql mysql -uroot -proot -e "SELECT batch, COUNT(*), MIN(version), MAX(version) FROM alkaid_sys.migrations GROUP BY batch ORDER BY batch;"
```
Expected: 两次输出完全一致；`migrate:run` 打印 `All Done`；无 `批次标记` 行（因为没有新迁移）。

- [ ] **Step 4: 复现全新库场景（关键验收）**

在一次性库上验证，**不碰 `alkaid_sys`**：
```bash
docker exec alkaid-mysql mysql -uroot -proot -e "DROP DATABASE IF EXISTS alkaid_migrate_probe; CREATE DATABASE alkaid_migrate_probe CHARACTER SET utf8mb4;"
# 用临时 .env 指向新库跑一次全量迁移（或用 --database 参数，按项目实际支持方式）
# 然后检查：
#   SELECT batch, COUNT(*) FROM alkaid_migrate_probe.migrations GROUP BY batch;
# 断言：除最后几条外，历史行落在独立批次；且
#   SELECT COUNT(*) FROM alkaid_migrate_probe.migrations WHERE batch = 0;  → 0
# 重建前先记录：无参 rollback --list 会命中多少条 —— 修复前是「几乎全部」，修复后应是少量新迁移
docker exec alkaid-mysql mysql -uroot -proot -e "DROP DATABASE alkaid_migrate_probe;"
```
Expected: 修复后无参回滚命中数 == 在 `20251221000001` 之后新增的迁移条数（2 条：R5、R10），不是 30+ 条。

> 若项目的 `think migrate` 不支持跨库，退化为：在 `alkaid_sys` 上确认 SQL 中含 `AND batch = 0`，并在报告里明确标注"全新库场景未做端到端实测，仅静态确认"。

- [ ] **Step 5: 说明为什么没有 PHPUnit 测试**

在提交信息里写明：该路径需要"全新空库"才能触发，在共享测试库上无法安全复现（会真的重打全部行的 batch）。用 Step 4 的一次性库脚本作为验证手段。

- [ ] **Step 6: 提交**

```bash
git add app/command/migrate/RunCommand.php
git -c core.hooksPath=/dev/null commit -m "fix(migration): 批次标记只作用于未打批次的行（修复全新库首跑误标）"
```

---

## Task 4: `check-migrations.php` 增加检查 D（tenant_id 静态声明）

**Files:**
- Modify: `scripts/check-migrations.php`（新增 `checkTenantIdDeclaration()`，在 `--strict` 与普通模式下均参与退出码）

**Interfaces:**
- Produces: 检查 D 的规则 —— 对**未应用**的迁移文件，`tenant_id` 列必须声明为 `integer` + `signed => false`（或 `biginteger` + unsigned 之外的形式一律报错）；禁止 `'string'`
- 复用既有 `getAppliedVersions()`（或等价方法）与 `formatResult()` / 退出码机制

- [ ] **Step 1: 写一个会违反规则的临时迁移**

Create: `database/migrations/20251224000099_tenant_probe_bad.php`，其中：
```php
$this->table('probe_bad')->addColumn('tenant_id', 'biginteger', ['comment' => '租户ID'])->create();
```

- [ ] **Step 2: 跑检查确认当前「无此检查」→ 不报错**

Run: `docker exec alkaid-backend php scripts/check-migrations.php --strict`
Expected: exit=0（检查 D 尚未实现，探针未被告发）

- [ ] **Step 3: 实现检查 D**

在 `scripts/check-migrations.php` 增加 `checkTenantIdDeclaration()`：
- 只扫**未应用**版本（`fileversion > max(applied)` 的文件）
- 用 `token_get_all` 或结构化匹配定位 `addColumn('tenant_id', ...)` 与 `Column::integer('tenant_id')`
- 判定：
  - 类型为 `'string'` → 错误
  - 类型为 `'biginteger'` 且无 `'signed' => false` → 错误
  - 类型为 `'integer'` 且无 `'signed' => false` → 错误
  - `Column::integer('tenant_id')`（无 `->setUnsigned(true)`）→ 错误
- 输出格式与既有 A/B/C 一致（`[TENANT]` 前缀）

- [ ] **Step 4: 确认探针被告发**

Run: `docker exec alkaid-backend php scripts/check-migrations.php --strict`
Expected: exit=1，错误信息点名 `20251224000099_tenant_probe_bad.php`

- [ ] **Step 5: 删除探针，确认恢复通过**

Run:
```bash
rm database/migrations/20251224000099_tenant_probe_bad.php
docker exec alkaid-backend php scripts/check-migrations.php --strict
```
Expected: exit=0，扫描 34 个文件

- [ ] **Step 6: 提交**

```bash
git add scripts/check-migrations.php
git -c core.hooksPath=/dev/null commit -m "feat(migration): 检查脚本增加 tenant_id 声明规则（检查 D）"
```

---

## Task 5: `check-migrations.php` 增加检查 E（租户外键一致性）

**Files:**
- Modify: `scripts/check-migrations.php`（新增 `checkTenantForeignKeyConsistency()`）

**Interfaces:**
- Produces: 检查 E 的规则 —— 已存在库中，`tenant_id` 列若 `COLUMN_DEFAULT IS NULL` 则必须有 `tenant_id → tenants.id` 外键；否则报错
- 复用 Task 4 的 DB 连接与 `formatResult()`；legacy 基线常量 `KNOWN_TENANT_FK_EXCEPTIONS = ['lowcode_forms']`（带 TODO 指向待决 D1）

- [ ] **Step 1: 确认当前违规集合**

Run:
```sql
SELECT c.table_name FROM information_schema.columns c
LEFT JOIN information_schema.key_column_usage k
  ON k.table_schema=c.table_schema AND k.table_name=c.table_name
 AND k.column_name='tenant_id' AND k.referenced_table_name='tenants'
WHERE c.table_schema='alkaid_sys' AND c.column_name='tenant_id'
  AND c.column_default IS NULL AND k.constraint_name IS NULL;
```
Expected: 只有 `lowcode_forms` 一行

- [ ] **Step 2: 实现检查 E**

规则如上；命中 `KNOWN_TENANT_FK_EXCEPTIONS` 的记 warn 不记 error；其他记 error。无 `tenants` 表或连不上库时跳过（不因环境问题让门禁变红）。

- [ ] **Step 3: 验证通过态**

Run: `docker exec alkaid-backend php scripts/check-migrations.php --strict`
Expected: exit=0，输出里 `lowcode_forms` 以 warn 形式出现

- [ ] **Step 4: 验证能真的报错**

临时把 `lowcode_forms` 从基线常量里删掉，重跑 → exit=1 且点名；再改回。

- [ ] **Step 5: 提交**

```bash
git add scripts/check-migrations.php
git -c core.hooksPath=/dev/null commit -m "feat(migration): 检查脚本增加租户外键一致性检查（检查 E）"
```

---

## Task 6: 把 tenant_id 双语义写进多租户设计文档

**Files:**
- Modify: `design/01-architecture-design/04-multi-tenant-design.md`（在文档头部「实现状态」之后、正文之前插入新小节；并修正与实现冲突的表述）

**Interfaces:**
- 无代码接口；产出是文档规则，供 Task 4/5 的检查项引用

- [ ] **Step 1: 插入「tenant_id 两条语义」规则小节**

内容必须包含（数值与结论来自本轮审计，不得改写）：
- 规则一：真实租户引用 → `int unsigned NOT NULL`，**无默认值**，必须有 `fk_{表}_tenant_id → tenants.id`
- 规则二：系统模板空间 → 有 `DEFAULT 0`，**不建外键**（因为 `tenants` 无 id=0）
- 实测依据：224 张表里，无默认值的 11 张有 10 张带外键；有默认值的 213 张**零外键**（零例外）
- `site_id = 0` 与 `tenant_id = 0` **不同构**：前者是"默认站点"真实分区值，后者一半是模板空间、一半是"关闭租户过滤"开关
- 明确写出：`lowcode_forms` 是当前唯一违反规则一却未建外键的表，归属待定

- [ ] **Step 2: 修正文档中与实现冲突的三处**

- `:739` 「`tenant_id` 语义：> 0 表示有效租户」→ 补一句"但低代码/插件域把 0 用作系统模板空间，见新小节"
- 示例 DDL 里的 `alkaid_tenants` → `tenants`（库里没有 `alkaid_tenants`）
- `:790` 关于 `TenantIdentify`/`SiteIdentify` 被注释掉的描述 → 改为"已启用"

- [ ] **Step 3: 校验文档门禁**

Run: `docker exec alkaid-backend php scripts/check-docs.php --strict`
Expected: exit=0

- [ ] **Step 4: 提交**

```bash
git add design/01-architecture-design/04-multi-tenant-design.md
git -c core.hooksPath=/dev/null commit -m "docs(design): 明确 tenant_id 两条语义与租户外键规则"
```

---

## Task 7: 更正报告里的 R11 结论

**Files:**
- Modify: `docs/report/design-implementation-reconstruction-2026-10-30.md`（R11 行 + 相关引用）
- Modify: `design/09-lowcode-framework/47-workflow-backend-engine.md`（§7.1.3 关于 `alkaid_plugin_installations`/`lowcode_forms` 的表述）

- [ ] **Step 1: 更正 R11**

把「`alkaid_plugin_installations`、`lowcode_forms` 仍是 bigint unsigned 且无租户外键 = 缺陷」改为：
- `alkaid_plugin_installations`：`tenant_id` 有 `DEFAULT 0` + `PluginManager` 默认传 0 → **属系统模板空间，本就不该有外键**；本轮判断有误，已更正
- `lowcode_forms`：唯一真正违反"无默认值应带外键"的表，归属待定（D1）

- [ ] **Step 2: 同步 47 号文档**

§7.1.3 结尾的「仍未对齐的长尾」段落按上述更正改写，并链接到 04 号文档的新规则小节。

- [ ] **Step 3: 校验**

Run: `docker exec alkaid-backend php scripts/check-docs.php --strict`
Expected: exit=0

- [ ] **Step 4: 提交**

```bash
git add docs/report/design-implementation-reconstruction-2026-10-30.md design/09-lowcode-framework/47-workflow-backend-engine.md
git -c core.hooksPath=/dev/null commit -m "docs(report): 更正 R11 结论——plugin_installations 属系统模板空间无需外键"
```

---

## 收尾验收（全部任务完成后执行一次）

- [ ] `docker exec alkaid-backend ./vendor/bin/phpunit` → `Tests: 486+, Errors: 22, Failures: 19, Risky: 4`；失败集去重 ≤ 41
- [ ] `docker exec alkaid-backend php scripts/check-migrations.php --strict` → exit=0
- [ ] `docker exec alkaid-backend php scripts/check-docs.php --strict` → exit=0
- [ ] `git status --porcelain` → 空
- [ ] `git log --oneline` 显示 7 个新提交，未推送
