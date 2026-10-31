# R43 冷启动修复实施计划（精化版 ①）

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development

**Goal:** 让「全新库 + `migrate:run`」跑通（含系统租户与菜单），并让 `seed:run` 可重复执行、且不再硬编码默认口令。

**决策（用户 2026-10-31 确认）**：生产环境允许存在系统租户 → 采用精化版 ①；配套三件事一并做；默认口令无条件改。

**Spec:** `docs/superpowers/plans/2026-10-31-r43-cold-start.md`

## Global Constraints

- 中文注释与提交信息；**不得推送**；提交用 `git -c core.hooksPath=/dev/null commit`
- 全量失败集去重必须 **≤ 41**（当前 41，用例 536）
- 五道门禁 + `php think env:check` exit=0
- **不得修改 `.env`**（临时验证用 `PHP_ENV_NAME=xxx` + 完整 `.env.xxx` 副本，用完删除）
- 不改 `vendor/`

## Review Focus

- **全新库 `migrate:run` 必须 exit 0**，且 `menus` 有 21 行、`tenants` 有 1 行（id=1）
- **既有库跑 `migrate:run` 必须完全无副作用**（不重复插菜单、不重复插租户）
- **`seed:run` 必须在已迁移库上跑通，且重复跑不产生重复行**
- **默认口令不得出现在代码里**；缺失时必须**明确失败**而不是静默创建弱口令
- **不得破坏** `assignPermissionsToRoles()`（R43 上一轮已改为动态 + 幂等）

---

## Task 1：系统租户内联到 menus 迁移（修 B3）

**Files:**
- Modify: `database/migrations/20251219000003_add_initial_system_menus.php`

**为什么内联而不是新增迁移（重要约束，已核实）**
- 想修 B3，租户必须在 `20251219000003` **之前**创建
- 但 `check-migrations` 的检查 B 规定：**未应用迁移的 version 必须大于已应用的最大 version**（当前 `20251224000001`）
  → 任何新迁移文件只能放在链尾，无法插到 `20251219000003` 前面
- 而把 `20251219000003/4` 改名挪后也不行：它们的 `up()` **不幂等**（无条件 insert 21 行菜单），
  改名会让既有库重跑并产生重复菜单
- 因此唯一不削弱门禁的做法：**在 `20251219000003` 的 `up()` 开头确保租户 1 存在**

**要做**
- 在 `up()` 最前面（`$now`/`$tenantId` 之后、第一条 `insertMenu` 之前）加一段：
  `INSERT INTO tenants (id, name, slug, status, created_at, updated_at) SELECT ... WHERE NOT EXISTS (SELECT 1 FROM tenants WHERE id = 1)`
  —— 用原生 SQL 或 `Db::execute`，注意 `tenants` 表列名以实际 schema 为准（先 `DESCRIBE tenants` 核对，别猜）
- 加中文注释说明三件事：
  1. 为什么在这里（check B 不允许回填版本号、改名会导致菜单重复）
  2. 系统租户 id=1 是**schema 不变量**（`Request::tenantId()` 默认回落 1；`tenants` 被 11 个外键引用）
  3. `down()` **刻意不删租户**——删除会级联波及 11 张表，系统租户不接受回滚删除
- `down()` 保持现状（只删 7 个具名菜单），**不要**加删除租户的语句

## Task 2：seeder 去重 + 幂等 + 口令移出代码

**Files:**
- Modify: `database/seeds/CorePlatformSeed.php`
- Modify: `.env.example`（仅新增注释说明，**不要**加键值行）

**已核实事实**
1. `run()` 顺序：`createDefaultTenant` → `createRoles` → `createPermissions` → `assignPermissionsToRoles` → `createDefaultUser`
2. **`createPermissions()` 与迁移链完全重复**：它插的 20 个 slug（`forms.*`/`form_data.*`/`collections.*`/`users.*`/`roles.*`）
   与迁移创建的一模一样 → 这是 `seed:run` 报 `Duplicate entry '1' for key 'permissions.PRIMARY'` 的根因
3. `createDefaultTenant` / `createRoles` / `createDefaultUser` 全部**写死 id**（1 / 1,2 / 1）且无幂等
4. `createDefaultUser` 里 `password_hash('admin123')` + `user_roles` 插入（user_id=1, role_id=1）
5. `assignPermissionsToRoles()` 上一轮已改为动态查询 + `NOT EXISTS` 幂等，**不要动**

**要做**
- **删除** `createPermissions()` 方法及其在 `run()` 里的调用（权限由迁移创建）
- `createDefaultTenant()`：改为幂等——先按 `slug`（或 id=1）查存在则整体跳过（含 sites 的插入）
- `createRoles()`：改为幂等——按 `slug` 查，逐个 `WHERE NOT EXISTS`（保留 admin/user 两个角色的语义）
- `createDefaultUser()`：
  - 幂等：按 `username` 查存在则跳过（含 `user_roles` 分配，也要 `NOT EXISTS`）
  - **口令移出代码**：改为 `env('ADMIN_INIT_PASSWORD')`
    - 缺失或为空时：`$this->output->writeln(...)` 明确报错 + **抛异常终止**（不能静默创建弱口令，也不能用空口令）
    - 错误信息要写清怎么修（设置该 env 后重跑）
- `.env.example`：只加**注释块**说明「首次执行 `seed:run` 前必须设置 `ADMIN_INIT_PASSWORD`」。
  ⚠️ 遵守 R32 规则：注释里**不得出现半角 `=`、ASCII 括号/引号**（用全角括号或改写语序）。

**验证（必须给原始输出）**
1. **全新库冷启动**（`PHP_ENV_NAME=r43i` + **完整** `.env.r43i` 副本，只改 `DB_NAME`）：
   - `php think migrate:run` → **exit 0**、`migrations` 35 行、表数 29、`tenants` 1 行、`menus` **21 行**
   - 再跑一次 `migrate:run` → exit 0、行数不变
2. **seeder 幂等**（同一全新库）：
   - 不设 `ADMIN_INIT_PASSWORD` 跑 `seed:run` → **exit 非 0**，输出含明确提示
   - 设了 `ADMIN_INIT_PASSWORD=TestInit_12345` 再跑 → exit 0；`tenants`/`sites`/`roles`/`users`/`user_roles`/`role_permissions` 行数合理
     （期望：tenants 1、roles 2、users 1、user_roles 1、role_permissions = 权限总数 + 3）
   - **再跑一次**（同样设 env）→ exit 0，**所有表行数不变**
3. **既有库无副作用**：本地 `alkaid_sys` 跑 `migrate:run` → exit 0、`menus` 仍 21 行、`tenants` 行数不变
4. 全量 `./vendor/bin/phpunit`：失败集去重 ≤ 41，与基线 diff（基线 41 / 用例 536）
5. 五道门禁 + `env:check` exit=0
6. `php -l` 全部改动文件
7. 清理：删临时库与 `.env.r43i`，确认 `git status` 只剩你的改动

## 收尾验收（主审串行执行）

- [ ] 全新库 `migrate:run` + `seed:run` 双跑幂等（上面 1、2 的原始输出）
- [ ] 既有库无副作用
- [ ] 全量失败集去重 ≤ 41
- [ ] 五道门禁 + `env:check` exit=0
- [ ] 工作区干净
