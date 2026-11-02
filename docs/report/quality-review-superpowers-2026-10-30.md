# AlkaidSYS-tp 项目质量审查报告

| 项目 | 内容 |
|---|---|
| 报告日期 | 2026-10-30 |
| 审查基线 | 本地 `develop` HEAD `f119d98` |
| 审查方法 | superpowers `requesting-code-review` / `code-reviewer.md` 五维清单 |
| 审查范围 | 后端 335 个 PHP / 57,376 行；`plugins/` 4,630 个跟踪文件；`frontend/`（见 C-2）；`design/` 73 份；`docs/` 111 份（不含归档） |
| 核查方式 | 四路独立只读审计 + 主审对**每一条结论**逐项复核（复核记录见第十章） |
| 最终结论 | **With fixes（修复后可合并）** —— Critical 共 **7 个**，**已修复 5 个（C-2/C-4/C-5/C-6/C-7，均经独立复验）**，**剩余 2 个（C-1 注入、C-3 vendor 入库）**（C-3 已于 2026-11-02 处理，见 C-3 节追记） |
| 阶段 A 追加修复 | `4b5f0cc` R1 工作流任务授权 fail-open（含 2 个新发现缺陷 N4/N5 + 类型缺陷 N6）、`4392b0f` A5 插件 autoload 与 Provider 接线、`1b01842` A7 前端权限路由、`f3de7b0` A4 工作流 5 表纳入主链 |
| 阶段 A 测试基线 | `Tests: 482, Errors: 22, Failures: 19, Risky: 4`（失败集去重 **41 项 < 42 基线**，零新增；详见 I-3 补记） |

> **计数口径说明**：所有依赖 git 状态的数字以审查基线 `f119d98` 为准。本报告自身的提交会使"从 develop 可达提交数"与"跟踪文件数"小幅递增，属正常增量，不影响结论。

---

## 一、审查方法

采用本地 superpowers 技能集 `/Users/Benson/Code/Aether/openakita/skills/superpowers-requesting-review/` 的审查方法，覆盖五维：

| 维度 | 检查要点 |
|---|---|
| 代码质量 | 关注点分离、错误处理、类型安全、DRY、边界情况 |
| 架构 | 设计决策、可扩展性、性能、安全性 |
| 测试 | 是否测真实逻辑、边界覆盖、集成测试、通过率 |
| 需求符合度 | 与 `design/` 规格一致性、范围蔓延 |
| 生产就绪度 | 迁移策略、向后兼容、文档完整性、明显缺陷 |

**执行约束**：每个结论必须附「已执行的命令 + 输出摘要」或「文件:行号 + 原文」。凡无法验证者明确标注，不做推断性陈述。所有关键结论均已由主审独立复核（第十章列出复核中发现并修正的偏差）。

---

## 二、仓库与分支拓扑（事实层）

### 2.1 分支实况

| 引用 | SHA | 日期 | upstream | ahead/behind |
|---|---|---|---|---|
| `develop`（HEAD） | `f119d98` | 2026-10-30 | **无** | 不可计算 |
| `develop-legacy` | `f567264` | 2025-11-26 | `origin/develop` | ahead 13 / behind 0 |
| `feature/lowcode-t002-p1-tenantization` | `26321dc` | 2025-12-17 | **无** | 不可计算 |
| `local-snapshot-before-cleanup` | `1f2c66c` | 2026-10-29 | **无** | 不可计算 |
| `main` | `c36ab58` | 2025-11-24 | `origin/main` | ahead 0 / behind 1 |
| `origin/develop` | `c36ab58` | 2025-11-24 | — | 与本地 `main` **同一提交** |
| `origin/main` | `60c2387` | 2025-12-14 | — | — |
| `origin/feature/…` | `33bce2f` | 2025-12-14 | — | — |

`[证据: git branch -vv --no-abbrev; git for-each-ref --format='%(refname) %(upstream) %(upstream:track)']`

### 2.2 真实拓扑

全仓 **0 个 merge commit**。所有 ref 可达提交 143 个（其中从 `develop` 可达 141，审查基线 140），构成线性链 + 2 个侧枝：

```
c36ab58  (main = origin/develop, "init")          pos 0
  ├── 60c2387  (origin/main)                      pos 1   ← 侧枝（squash）
  ├── f567264  (develop-legacy)                   pos 13
  ├── 8811267  (tree 与 60c2387 完全相同)          pos 72
  ├── 33bce2f  (origin/feature)                   pos 85
  ├── 26321dc  (feature)                          pos 101
  ├── b5a62d4                                     pos 119 ← 快照分叉点
  │     ├── 1f2c66c (local-snapshot)              pos 120 ← 侧枝
  │     └── → f119d98 (develop)                   pos 136
```

`[证据: git rev-list --merges --all | wc -l → 0; git rev-list --count --all → 143; git log --graph --all --boundary]`

### 2.3 三个反直觉事实

**① `origin/main` 与 `origin/develop` 有共同祖先，且就是 `origin/develop` 自身**

```
git merge-base origin/main origin/develop        →  c36ab58
git rev-list --count origin/develop..origin/main →  1
```

`60c2387` 是 GitHub 的 **squash merge**（单父、committer=GitHub、有 gpgsig、正文含 72 条 `* ` 条目），其 tree 与 `8811267` **字节级相同**，而 `8811267` 是 `develop` 的祖先。

**结论：`origin/main` 的全部内容已 100% 包含在 `develop` 历史中，零独有内容。**

`[证据: git cat-file -p 60c2387; git diff 60c2387 8811267 | wc -l → 0; git merge-base --is-ancestor 8811267 develop → exit 0; git rev-parse 60c2387^{tree} == git rev-parse 8811267^{tree} → YES]`

**② 「领先 136」≠「136 个从未推送」**

`develop` 领先 `origin/develop` 136 个提交，但其中 **85 个已存在于 `origin/feature/lowcode-t002-p1-tenantization`**。真正从未出现在任何远端的只有 **52 个**（主链 51 + 快照 1）。

`[证据: git rev-list --count 33bce2f..f119d98 → 51; git rev-list --all --not --remotes --count → 53（含本报告提交后）; git log f119d98 --not --remotes --oneline | wc -l → 51]`

**③ 推送可行性**

| 目标 | 判定 | 依据 |
|---|---|---|
| `develop` → `origin/develop` | **可快进（FF）** | `is-ancestor origin/develop develop` → exit 0 |
| `develop` → `origin/main` | **需 force** | `is-ancestor origin/main develop` → exit 1 |

`[证据: git merge-base --is-ancestor <a> <b>; echo $?]`

### 2.4 快照的实际效力

`local-snapshot-before-cleanup` 与 HEAD `f119d98` **仅差 5 个文件**，其中与本报告相关的业务差异只有 4 项：

```
M  .gitignore
M  docs/report/README.md
A  docs/report/ 下新增的一份审查报告
D  error_create.html
D  error_data.html
```

快照**不是**「整理前被跟踪状态的完整备份」：

- `.phpunit.result.cache` 在父提交 `b5a62d4` 中仍被跟踪（178,722 B），但在快照中**已删除**
  `[证据: git cat-file -e b5a62d4:.phpunit.result.cache → 成功；1f2c66c: → 失败]`
- Legacy Collection 三件套在快照中**已删除**
- 快照与 `develop` 是**双向分叉**，**不是** develop 的祖先
  `[证据: git rev-list --left-right --count develop...local-snapshot-before-cleanup → 18 1; merge-base → b5a62d4]`

### 2.5 仓库卫生

| 项 | 状态 |
|---|---|
| stash | 0 条 |
| merge commit | 0 个 |
| **悬空 gitlink** | **2 个**（见 C-2） |
| **`plugins/**/vendor/` 已入库** | **4,270 文件 / 39.28 MB**（见 C-3） |
| 最大单文件 | `plugins/workflow-engine/vendor/phpstan/phpstan/phpstan.phar` **22.65 MB** |
| 全仓跟踪（基线） | **5,421 文件 / 49.81 MB** |
| **调试残留仍被跟踪** | `all_design_files.txt`、`debug_controller.txt`、`debug_data.txt`、`debug_data_index.txt`、`debug_route.php`、`debug_routes_list.php` |
| 嵌套仓库 | 2 个（`docs/Example/ingenious/.git`、`.../demo/.git`，均已被 `.gitignore:40` 覆盖） |
| 推送封禁 | `pushurl=no-push-disabled://local-only` + `push.default=nothing`；`git push --dry-run` 立即 `fatal: remote helper 'no-push-disabled' aborted session` |

`[证据: git ls-tree -r -l HEAD | awk '$4+0>1048576'; git ls-tree -r -l HEAD -- plugins/workflow-engine/vendor | awk '{s+=$4;n++}END{print n, s/1048576" MB"}' → 4270 39.28 MB; git ls-tree --name-only HEAD | grep -E 'debug_|all_design_files' → 6 个]`

---

## 三、Strengths（优势）

### S-1 分层架构严格，DDD 边界清晰

`app/`(78) / `domain/`(30) / `infrastructure/`(82) 三层职责明确；低代码模块按 Collection / Field / FormDesigner / Schema / Generator 五个子域拆分（`infrastructure/Lowcode/` 39 个 PHP）。

### S-2 统一响应规范已规模化落地

**21 个控制器中 19 个继承 `ApiController`**（未继承的仅 `ApiController` 自身与 `Index.php`），`success()`/`error()` 自动注入 `trace_id`：

```php
// app/controller/ApiController.php:56-69
$response = ['code'=>$code,'message'=>$message,'data'=>$data,'timestamp'=>time()];
$traceId = $this->getTraceId();
if ($traceId !== null) { $response['trace_id'] = $traceId; }
```

`TraceIdCoverageTest` **单独运行 9/9 通过（38 断言）**。

`[证据: grep -rl 'extends ApiController' app/controller | wc -l → 19; for f in $(find app/controller -name '*.php'); do grep -q 'extends ApiController' "$f" || echo "$f"; done → ApiController.php, Index.php; docker exec alkaid-backend php think test --filter=TraceIdCoverageTest → Tests: 9, Assertions: 38, All tests passed]`

### S-3 运行时 DDL 防护与设计一致，且已实证生效

`SchemaBuilder::assertCanRunDDL()`（:315-372）实现双层闸门（环境白名单 + `ALLOW_RUNTIME_DDL` 开关），默认 `APP_ENV=production` 拦截，返回 `code=5003 / HTTP 403`，并记录 `[SCHEMA_DDL_ATTEMPT/FORBIDDEN/EXECUTED]` 审计日志。

容器内实测：`addColumn` / `dropTable` 均抛 `HttpResponseException (code 5003)`。

`[证据: 容器内 PoC：addColumn → EXC HttpResponseException (code 5003); dropTable → 同上]`

### S-4 多租户隔离在四个核心组件中实现完整

| 组件 | 隔离实现 |
|---|---|
| `FormDataManager` | 读/写/删/列表全部带 `tenant_id + site_id` 双过滤（:114/:142/:160/:185） |
| `CollectionRepository` / `TenantAwareDb` | `TenantAwareDb.php:21-29` 强制注入 `where('tenant_id', …)` |
| `PermissionService` | `:212` 强制 `->where('r.tenant_id', $tenantId)` |
| `UserRepository` | `findByEmail/list/delete/getRoleIds` 均带租户（:61/:158/:249/:108） |

**6 个多租户测试类单独实测：5 个全过，1 个仅 1 处失败（且为断言过时）**：

```
PermissionServiceMultiTenantTest      Tests: 4, Assertions: 12      全过
FormDataManagerMultiTenantTest        Tests: 6, Assertions: 23      全过
AuthRegisterMultiTenantTest           Tests: 1, Assertions: 7       全过
MultiTenantIsolationTest              Tests: 3, Assertions: 8       全过
FormDataControllerMultiTenantTest     Tests: 3, Assertions: 14      全过
FormApiMultiTenantE2ETest             Tests: 7, Assertions: 43, Failures: 1
```

`[证据: 逐类 docker exec alkaid-backend ./vendor/bin/phpunit --filter <类名>]`

### S-5 Legacy 治理到位

旧实现（`domain/Model/Collection.php`、`infrastructure/Collection/CollectionManager.php`、`infrastructure/Field/FieldTypeRegistry.php`）已下线，配套 `tests/Legacy/LegacyCollectionUsageGuardTest.php` 守卫，全库无残留 `use` 引用。

### S-6 Casbin 授权体系完整

`CasbinService` + `DatabaseAdapter` + `config/casbin.php` + `casbin-model.conf` + `CasbinController` + 迁移 + **8 个测试**（含 `CasbinModeSwitchAndRollbackIntegrationTest`、`CasbinCacheDegradationTest`）。设计文档 `04-security-performance/11-security-design.md` 要求的三种运行模式（DB-only / Casbin-only / 双检）均有对应实现与测试。

`[证据: find infrastructure/Permission -name '*.php' → 6 个; find tests -name '*Casbin*' | wc -l → 8]`

### S-7 工作流引擎「代码规模」可观（但可用性另见 I-4）⚠️ **本节已于 2026-10-30 加限定**

`plugins/workflow-engine`：**276 个 PHP 文件**（排除 vendor，其中 213 个在 `src/`），7 层结构（Definition / Core / Expression / Nodes / Integration / Multitenant / Monitor），5 个迁移，**51 个自带测试**，容器内 `php -l` **276/276 全部通过**。

> **必须同时说明的限制**（详见 I-4）：
> - `php -l` 只查语法，**不解析 trait/父类**。实测 `class_exists('...model\NodeModel')` → **Fatal: Trait "madong\ingenious\libs\traits\DynamicPropsTrait" not found**
> - `src/` 213 个文件中 **88 个（41%）依赖未安装的 `madong/ingenious`** → 加载即 Fatal
> - 5 个迁移不在 `database/migrations/` → 表从未创建
>
> 因此本节应理解为「**代码体量可观**」，**不等于**「能力可用」。

`[证据: find plugins/workflow-engine -name '*.php' -not -path '*/vendor/*' | wc -l → 276; find plugins/workflow-engine/tests -name '*Test.php' | wc -l → 51; 可用性证据见 I-4]`

### S-8 文档体系成体系

`design/` **73 份**覆盖 16 个目录；`docs/technical-specs/` **39 份**；多份核心设计与实现可对应（详见第五章）。

`[证据: find design -name '*.md' | wc -l → 73; find docs/technical-specs -name '*.md' | wc -l → 39]`

---

## 四、Issues

### Critical（必须修复）

#### C-1 低代码标识符拼接注入：真实存在，且 `hasTable()` / `hasColumn()` 不受 DDL 防护

**真实注入入口是 body，不是 URL。**

| 跳 | 位置 | 关键代码 |
|---|---|---|
| 路由 | `route/lowcode.php:15` | `Route::post('', '...CollectionController@save')->middleware(Permission::class, 'lowcode.write')` |
| 入口校验 | `app/controller/lowcode/CollectionController.php:106` | `if (empty($data['name']) \|\| empty($data['title']) \|\| empty($data['fields']))` ← **只判空，无字符白名单** |
| 客户端可控表名 | `CollectionController.php:120-127` | `'table_name' => $data['table_name'] ?? null` |
| 生成表名 | `domain/Lowcode/Collection/Model/Collection.php:360-366` | `$snakeCase = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name)); return 'lc_' . $snakeCase;` |
| 生成表名 | `infrastructure/Lowcode/Collection/Service/TableNameGenerator.php:20-28` | 同上逻辑，**无 `preg_match`、无长度限制、无保留字过滤** |
| 注入点① | `SchemaBuilder.php:148` | `Db::query("SHOW TABLES LIKE '{$tableName}'")` ← **无 guard** |
| 注入点② | `SchemaBuilder.php:260` | `Db::query("SHOW COLUMNS FROM \`{$tableName}\` LIKE '{$columnName}'")` ← **无 guard** |
| 建表 | `SchemaBuilder.php:63,83,86` | `CREATE TABLE \`{$tableName}\`` / `COMMENT='{$comment}'` / `Db::execute($sql)` ← 有 guard |

**guard 覆盖缺口已逐行确认**：

```
assertCanRunDDL 调用点：:35 createTable / :117 dropTable / :160 addColumn / :213 dropColumn
未调用：                 :146 hasTable / :258 hasColumn
```

`[证据: grep -n 'assertCanRunDDL' infrastructure/Schema/SchemaBuilder.php → 4 个调用点；grep -n 'public function \(createTable\|dropTable\|hasTable\|addColumn\|dropColumn\|hasColumn\)' → 行号如上]`

**容器内 PoC（未改动源码）证实布尔注入成功**：

```
hasColumn('users', 'totally_missing_col')                → bool(false)   基线
hasColumn("users` WHERE 1=1 -- ", 'totally_missing_col')  → bool(true)    注入成功
实际 SQL: SHOW COLUMNS FROM `users` WHERE 1=1 -- ` LIKE 'totally_missing_col'
[hasTable OR1=1]    → EXC PDOException（语法错误暴露）
[addColumn(guard)]  → EXC HttpResponseException code 5003   ← DDL guard 生效
[dropTable(guard)]  → EXC HttpResponseException code 5003   ← DDL guard 生效
```

**为什么重要**：`assertCanRunDDL()` 能拦住 `CREATE/ALTER/DROP`，但**拦不住两个 `SHOW` 查询**——这是唯一在 `APP_ENV=production` 下仍可执行的注入面。且 `CollectionController.php:147-149` 把 `$e->getMessage()` 原样返回客户端，SQL 语法错误会回显 SQL 片段，构成基于报错的 schema 枚举。

**可利用前置条件**：需 `Auth` + `Permission('lowcode.write')`（当前 dev 库中仅 `test_admin` 角色持有）→ 需租户管理员权限。

**修复方向**：
1. `SchemaBuilder` 增加 `assertSafeIdentifier()`：`^[A-Za-z_][A-Za-z0-9_]{0,63}$`，对表名/列名/索引名统一校验，`hasTable()`/`hasColumn()` **也必须调用**
2. `hasTable`/`hasColumn` 改参数化或查 `information_schema`
3. `CollectionController::save` 删除客户端直传 `table_name`，或强制 `lc_` 前缀 + 白名单字符
4. catch 分支不返回 `$e->getMessage()`

---

#### C-2 `frontend/` 是悬空 gitlink —— 前端源码不在版本库中 ✅ **已修复（2026-10-30，提交 `bc2357e`）**

> **修复结果**：gitlink 已替换为普通目录，**1,678 个前端文件（8.49 MB）已纳入版本控制**。
> 验证：`git ls-tree HEAD frontend` → `040000 tree`（不再是 `160000 commit`）；
> `git ls-files frontend` → 1,678；`node_modules` / `dist` / `.nitro` / `.turbo` 均未入库。
> 其中 `.vue/.ts` 源文件 1,273 个（另有 17 个在 `dist/`、`.nitro/types/` 下的产物被正确排除）。
> 原始问题描述保留如下，供追溯。

**原始状态**：

```
160000 commit f09aace7655656f002d4fe6b511be5bd06826c07    frontend
160000 commit d13e1d7715b02d87342419643094b53b2218adaf    plugins/workflow-engine/vendor/madong/helper

.gitmodules              → 不存在
git cat-file -t f09aace… → fatal: could not get object info
git ls-files frontend    → 1（仅 gitlink 一项）
frontend/.git            → 不存在
git submodule status     → fatal: no submodule mapping found in .gitmodules for path 'frontend'
```

`[证据: git ls-tree -r HEAD | awk '$1=="160000"' → 上述 2 行; git cat-file -e HEAD:.gitmodules → 失败; git ls-files frontend | wc -l → 1]`

**为什么重要**：

磁盘上 `frontend/` 有 **1,290 个 `.vue/.ts` 文件**，其中 `web-antd/src` 有 **89 个文件（81 个 `.vue/.ts`）/ 8,217 行**真实业务代码（`api/system/{dept,menu,role,permission,tenant,user}.ts` + `views/system/*` CRUD 页面 + `api/lowcode/form.ts`），但**这些内容完全不在任何 git tree 中**。后果：

- 前端代码**零备份、零历史、零协作能力**
- 任何 `git clean -fdx` 或重新 clone 都会**永久丢失前端全部工作**
- 该 gitlink 自初始提交 `c36ab58` 起就存在，指向的对象在库中不存在，**无法从远端恢复**

**修复方向**：移除 gitlink 条目（`git rm --cached frontend`），将前端按普通目录重新纳入版本控制；或在远端建立真实 submodule 仓库并补 `.gitmodules`。二者选一，当前状态不可接受。

---

#### C-3 `plugins/workflow-engine/vendor/` 已入库：4,270 文件 / 39.28 MB

> ⚠️ **2026-11-02 追记（原文保留，勿据此判断现状）**
>
> 本问题**已于 2026-11-02 处理完毕**，提交 `268389e`（`chore: 插件 vendor 出库，全局 ignore 规则下沉到仓库`）。
> 需要澄清一个数字：C-3 摘要与下文「仓库体积的 78.8% 是第三方依赖」里的 `78.8%`，
> 在**审查基线 `f119d98` 上是对的**（39.28 MB / 49.81 MB = 78.85%，第十章复核记录也是这么算的，
> 那里 `frontend/` 还是 1 个 gitlink 条目），但它**不能用来描述出库时刻的仓库** ——
> 到 `268389e^` 时全仓跟踪内容已增至 61.22 MB（`frontend/` 1,678 文件入库等），
> 同一份 39.28 MB 的占比已降到 **64.2%**。`docs/PROJECT-OVERVIEW.md` 曾把 78.8% 当作现状直接
> 引用、未声明口径，那一处才是真正需要纠正的（已在本次修复中改为 64.2% 并注明口径及出库后字节数）。
>
> **结论性证据**（`git ls-tree -r -l` 实测，仓库根执行）：
> ```
> 出库前 268389e^  →  7,237 文件 / 61.22 MB
> 出库后 268389e   →  2,967 文件 / 21.95 MB
> 其中 plugins/workflow-engine/vendor  →  4,270 文件 / 39.28 MB
> ```
> 即 vendor 占出库前全仓跟踪内容的 **64.2%**（字节口径，39.28 / 61.22）；按文件数为 **59.0%**（4,270 / 7,237）。
> 下文「全仓跟踪（基线）→ 5,421 文件 / 49.81 MB」是审查基线 `f119d98` 时的数字，
> 与出库时刻（`268389e^` 的 7,237 文件 / 61.22 MB）之间仓库又长了 1,816 个文件、11.41 MB，
> 两者各自成立但**不可混用**（这也是本节百分比看起来「对不上」的原因）。
>
> 实际处置与上文「修复方向」一致，并多做了一项：
> - 根 `.gitignore` 新增 `plugins/**/vendor/`（原 `/vendor/` 是根锚定，管不到嵌套 vendor）；
> - `git rm -r --cached plugins/workflow-engine/vendor` 出库 4,270 个文件，净删 513,900 行；
> - 依赖改由插件自身的 `plugins/workflow-engine/composer.json` / `composer.lock` 重建；
> - **顺带清掉** `plugins/workflow-engine/vendor/madong/helper` 那条 `mode 160000` 的坏 gitlink
>   （它是嵌套仓库残骸，`git rm --cached` 才会一并消失；出库后全仓已无 `160000` 条目）。
>
> 定位用引文：`78.8%`、`5,421 文件 / 49.81 MB` —— 行号以本次追记时的文件为准，后续改动可能漂移，检索引文即可定位。
> 本条注记由后续修复追加，**C-3 原文一字未改**。

```
plugins/workflow-engine/vendor  →  4,270 文件 / 39.28 MB（占全仓跟踪内容 78.8%）
其中 phpstan.phar               →  22.65 MB（单文件）
全仓跟踪（基线）                 →  5,421 文件 / 49.81 MB
```

`[证据: git ls-tree -r -l HEAD -- plugins/workflow-engine/vendor | awk '{s+=$4;n++}END{print n, s/1048576" MB"}' → 4270 39.28 MB; git ls-tree -r -l HEAD | awk '$4+0>1048576' → 仅 phpstan.phar 22.65 MB]`

**根因**：根 `.gitignore` 只写了**根锚定**的 `/vendor/`，对 `plugins/**/vendor/` 无效。实测 `git check-ignore -v plugins/workflow-engine/vendor/composer/installed.json` → **未忽略**。

**为什么重要**：违反 AGENTS.md「Avoid committing generated artifacts like `vendor/`」；仓库体积的 78.8% 是第三方依赖；`composer install` 可完全重建，入库无收益。

**修复方向**：`.gitignore` 增加 `plugins/**/vendor/`；`git rm -r --cached plugins/workflow-engine/vendor`；为插件补 `composer.json` 交付说明。

---

#### C-4 未认证注册接口用客户端 `X-Tenant-ID` 决定落库租户（跨租户账号注入） ✅ **已修复（2026-10-30）**

> **修复结果**：注册目标租户改为「域名绑定 → 服务端配置」两级解析，**不再读取 `X-Tenant-ID`**；
> 并校验租户状态（active / 未过期 / 未超 `max_users`），无法解析时返回 **HTTP 403 + code 2008**。
>
> **实测证据**（真实 HTTP，Swoole 重启后）：
> ```
> POST /v1/auth/register  X-Tenant-ID: 2
> → HTTP 403  {"code":2008,"message":"当前部署未开放自助注册","trace_id":"19e629b4…"}
> ```
>
> **新增测试**：`tests/Unit/Tenant/TenantRegistrationResolverTest.php`（8 个用例，全部通过）
> 覆盖：忽略客户端头 / 域名优先 / 无租户可解析时拒绝 / 配置租户不存在 / 停用 / inactive / 过期 / 正常通过。
>
> **回归验证**：全量测试 432 用例，Errors 22 + Failures 20（与修复前基线 **42 完全一致**，零新增失败）。
>
> 原始问题描述保留如下，供追溯。

**原始状态**：

```php
// route/auth.php:10-18  —— register 组内无 Auth 中间件
Route::group('v1/auth', function () {
    Route::post('login', '\\app\\controller\\AuthController@login');
    Route::post('register', '\\app\\controller\\AuthController@register');   // ← 无 Auth
    Route::post('refresh', '\\app\\controller\\AuthController@refresh');
    // Protected routes | 受保护的路由
    Route::get('me', ...)->middleware(\app\middleware\Auth::class);          // 仅这两个受保护
    Route::get('codes', ...)->middleware(\app\middleware\Auth::class);
});
```

```php
// app/middleware/TenantIdentify.php:32
$tenantId = (int)$request->header('X-Tenant-ID', '1');   // 无存在性校验、无白名单

// app/Request.php:73-89
public function tenantId(?int $default = 1): int
{ ... $tenantId = (int)$this->header('X-Tenant-ID', '0');
      if ($tenantId > 0) { $this->tenantId = $tenantId; return $tenantId; }
      $this->tenantId = $default ?? 1; return $this->tenantId; }

// app/controller/AuthController.php:137-171
$tenantId = $request->tenantId();
$defaultRoleId = $this->resolveOrCreateDefaultUserRoleId($tenantId);  // 在该租户下建角色
$user = new User($tenantId, $data['username'], $data['email'], password_hash(...));
$userId = $this->userRepository->save($user);
$this->userRepository->assignRole($userId, $defaultRoleId);
$accessToken = $this->jwtService->generateAccessToken($user->getId(), $user->getTenantId(), ...);
```

`[证据: cat route/auth.php; sed -n '30,34p' app/middleware/TenantIdentify.php; grep -an 'public function tenantId' -A16 app/Request.php; sed -n '128,175p' app/controller/AuthController.php]`

**为什么重要**：任意未认证者可指定 `X-Tenant-ID: <任意租户>` 为**任意租户**创建账号，并写入 `roles` / `role_permissions` 行，随后用自设密码登录取得该租户合法 JWT。`TenantIdentify.php:34` 的注释「Validate tenant exists (optional)」表明作者已知缺失。现有测试 `AuthRegisterMultiTenantTest.php:68-84` 甚至明确断言此行为（带 `X-Tenant-ID: 9902` 注册 → 用户落在 9902），说明该缺陷已被**固化进测试**。

**修复方向**：注册必须绑定邀请码或已校验的租户域名映射；`TenantIdentify` 应对 `X-Tenant-ID` 做租户存在性与状态校验；`Request::tenantId()` 默认值 `1` 应改为 `0` 并显式拒绝。

---

#### C-5 JWT 签名密钥是仓库内公开字符串 —— 可伪造任意用户身份 ✅ **已修复（2026-10-30，提交 `a7cf49b`）**

> **本节为 2026-10-30 补入**。原报告把该问题列在 Important 级的 I-7，定级过低 —— 实测证明它等价于**完整认证绕过**。

```php
// infrastructure/Auth/JwtService.php:54
$this->secret = (string) \think\facade\Env::get('JWT_SECRET', 'CHANGE_THIS_IN_PRODUCTION');
// .env.example:14  同样的公开值
```

**运行时实测**（此前报告只查了 `echo $JWT_SECRET` 得到空，那是 shell 环境变量，不是框架视角）：

```
Env::get('JWT_SECRET') → len=25, value='CHANGE_THIS_IN_PRODUCTION'
```

**攻击链实证**（用公开密钥自行签发 token，无需任何凭据）：

| 步骤 | 请求 | 结果 |
|---|---|---|
| 基线 | 正常 access token → `/v1/auth/me` | HTTP 200 |
| 攻击 1 | 伪造 `user_id=999999`（不存在） | HTTP 400「用户不存在」← **说明签名已被接受** |
| 攻击 2 | 伪造 **真实存在的** `user_id=101014` | **HTTP 200，返回该用户真实资料（email / tenant_id=1）** |

`[证据: docker exec alkaid-backend php -r '...Env::get("JWT_SECRET")...' → len=25; curl -H "Authorization: Bearer <自行签发>" /v1/auth/me → 200 + 真实用户数据]`


> **修复结果与复验证据（2026-10-30，提交 `a7cf49b`）**
>
> 实现要点：生产环境下拒绝「空值 / 已知公开占位符（含 `REPLACE_WITH`、`DEV-ONLY` 前缀）/ 长度 <32」；
> 生产判定词表 `production|prod|stage|staging` + `strtolower`，未设 `APP_ENV` 视为生产（失败安全）。
>
> 独立复验矩阵（主审亲自执行，11/11 符合预期）：
> | APP_ENV | JWT_SECRET | 结果 |
> |---|---|---|
> | production | `CHANGE_THIS_IN_PRODUCTION` | 拒绝 ✅ |
> | production | `REPLACE_WITH_*` 占位符 | 拒绝 ✅ |
> | prod / Production / staging / stage | 公开默认值 | 拒绝 ✅ |
> | production | 纯空格 / `abc`(3字符) / `.env.example` 的 dev 占位值 | 拒绝 ✅ |
> | production | 64 位随机串 | 放行 ✅（反向用例，防过严） |
> | dev | 公开默认值 | 放行 ✅（保护本地开发） |
>
> 配套修复的回归（评审发现，我方引入）：`.env.example` 原被改为空值，导致 dev 环境
> 「登录返回 200 带 token，但之后每个受保护接口 401」（firebase/php-jwt 拒绝空 key 解码）。
> 现已改为非空 dev 占位值 + `JwtService` 非生产空密钥回退，实测「签发→校验」闭环通过。
>
> **仍存在的限制（已知，未在本次范围）**：校验发生在 `JwtService` 惰性构造时，
> 因此生产环境的表现是「进程正常启动，鉴权请求失败」，**不是**启动期失败。
> 若要真正的启动期 fail-fast，需把校验前移到 `app/provider.php` 或 `AppService::boot()`。

**为什么重要**：任何人拿到源码即可伪造任意用户的 JWT，**完全绕过认证与密码**。这比 C-4（注册接口可跨租户建号）严重一个量级 —— C-4 是「能注册」，C-5 是「能变成任何人」。

**修复方向**：`APP_ENV=production` 且密钥为空或等于默认值时**启动即抛异常**；`.env.example` 改为空值并注明「必须设置」。改动约 10 行。

#### C-6 Refresh Token 可当 Access Token 使用 ✅ **已修复（2026-10-30，提交 `a7cf49b`）**

```php
// app/middleware/Auth.php:141
$payload = $this->jwtService->validateToken($token);   // ← 只验签名，不校验 token 类型

// infrastructure/Auth/JwtService.php:155  方法已存在，但无人调用
public function validateAccessToken(string $token): array {
    $payload = $this->validateToken($token);
    if (($payload['type'] ?? null) !== 'access') { throw ...; }
    return $payload;
}
```

**原缺陷实测**：用 `generateRefreshToken()` 产出的 token 访问 `/v1/auth/me` → **HTTP 200**。

> **修复结果与复验证据（2026-10-30，提交 `a7cf49b`）**
>
> 实现要点：`Auth.php` 改用 `validateAccessToken()`；`JwtService` 构造函数新增生产密钥校验
> （生产词表对齐 `EnvCheckCommand`、未设 APP_ENV 视为生产、拒绝空值/公开占位符/长度<32、
> 非生产空密钥回退 dev 密钥并警告）。
>
> 独立复验（主审亲自执行，不采信执行代理报告）：
> | 场景 | 结果 |
> |---|---|
> | access token → `/v1/auth/me` | HTTP 200，返回真实用户（未误伤正常路径） |
> | **refresh token → `/v1/auth/me`** | **HTTP 401 + code 2001**（修复前为 200） |
> | `AuthController::refresh` 用 refresh token 刷新 | 仍正常（该方法本就用 `validateRefreshToken`，未被改动波及） |
>
> 说明：本缺陷此前被列为 Important(I-7)，实测证明其等价于完整认证绕过，已升级为 Critical。



`[证据: 用 JwtService::generateRefreshToken() 签发后 curl /v1/auth/me → HTTP 200]`

**为什么重要**：refresh token 有效期 7 天，access token 2 小时。攻击者拿到 refresh token（或 C-5 伪造一个）即可长期访问全部业务接口，Access TTL 形同虚设。

**修复方向**：`Auth::handle()` 的 `validateToken()` 改为 `validateAccessToken()`。**一行改动**。

#### C-7 `$request->tenantId` 属性访问恒为 null —— 54 处租户过滤静默失效 ✅ **已修复（2026-10-30，提交 `7860fab`）**

```php
// app/Request.php:23    protected $tenantId;            ← 受保护属性
// app/Request.php:73    public function tenantId(...)   ← 方法

// 控制器中的写法（全仓 54 处）
$tenantId = $request->tenantId ?? null;    // ← 属性访问，走 __get
if ($tenantId !== null) { /* 租户过滤 */ }   // ← 永不进入
```

**实测**：

```
$r->setTenantId(1);
$r->tenantId()  → 1      方法调用，正确
$r->tenantId    → NULL   属性访问，think\Request::__get → middleware('tenantId') → null
```

`[证据: docker exec alkaid-backend php -r '...$r->setTenantId(1); var_export($r->tenantId()); var_export($r->tenantId);' → 1 / NULL]`

**影响分布**：

| 位置 | 数量 | 后果 |
|---|---|---|
| `app/controller/workflow/*.php` | **50 处** | 租户过滤全部跳过 → 跨租户可读定义/实例/任务/触发器；`save()` 写入 `tenant_id=null`；操作人恒为 `'system'` |
| `app/controller/admin/{User,Role,Menu,Dept}Controller.php` | 4 处 | 回退到 `header('X-Tenant-Id')`（**客户端可控**）→ 再回退到**硬编码租户 1** |

**对照**：低代码控制器（`FormSchemaController`/`CollectionController`/`FormDataController` 等）用的是 `$request->tenantId()`（方法），行为正确。所以这是**两套写法并存**，不是普遍缺陷。


> **修复结果与复验证据（2026-10-30，提交 `7860fab`）**
>
> 实现要点：54 处属性访问全部改为方法调用（tenantId 32 / userId 18 / siteId 0）；
> admin 4 个控制器移除「回退到 header → 回退到硬编码租户 1」，统一走 `Request::tenantId()`。
>
> 独立复验：
> - 属性式访问残留：`tenantId` 0 / `siteId` 0 / `userId` 0
> - `tests/Feature/Workflow/WorkflowTenantIsolationTest.php` 2 用例通过（跨租户列表不含、
>   跨租户按 ID 读取被拒、同租户读写仍可用、写入 `tenant_id === 1`）
> - 四个工作流控制器 `class_exists` 均 OK
>
> **仍存在的同类缺陷（评审发现，建议单独立项）**：
> `WorkflowTaskController.php:166/468/782` 三处 `$request->userGroups`（属性访问）未改 ——
> `app/Request` 中既无该属性也无 `userGroups()` 方法，需要先补数据来源。
> 其中 `claim()`（:468）因守卫条件为 `!empty($candidateUsers) && !empty($candidateGroups)`，
> 当任务仅有候选组时守卫被跳过 → **本租户内任意登录用户可认领组限定任务**（fail-open）。
> 这是既有缺陷，非本次改动引入，但属同一族「归属过滤失效」，风险等级 Important。

**为什么重要**：这是设计文档 `04-multi-tenant-design.md:336`「禁止仅依赖 X-Tenant-ID 识别租户」在实现层的直接违反，且**静默**——没有报错、没有日志、测试也没有覆盖。

**修复方向**：54 处改为方法调用。注意：改对之后工作流内存里混住的多租户数据会立刻被正确过滤（表现为「数据变少」），需与「工作流转 DB」一起验收。

---

### Important（应当修复）

#### I-1 `PermissionMonitorController` 完全脱离统一响应规范（15 处 `return json(`）

```php
// app/controller/api/admin/PermissionMonitorController.php:31-34
public function __construct() { $this->permissionService = new PermissionService(); }  // 不调 parent::__construct
// :47
return json(['code' => 200, 'message' => 'success', 'data' => $stats, 'timestamp' => time()]);
// :54
} catch (\Exception $e) { return json(['code' => 500, 'message' => '...' . $e->getMessage(), 'timestamp' => time()], 500); }
```

问题：
- `code` 用 200/500（HTTP 语义）而规范要求成功 `code=0`
- 无 `trace_id`（未注入 `App`，`getTraceId()` 恒 null）
- 部分分支缺 `data` 键（:74/:117/:144/:170/:196）
- `$e->getMessage()` 直出客户端

这是 **21 个控制器中唯一**真正越规者。

`[证据: grep -c 'return json(' app/controller/api/admin/PermissionMonitorController.php → 15]`

#### I-2 `FieldController::delete` 零校验可删除任意列（含 `tenant_id`）

```php
// infrastructure/Lowcode/Collection/Service/FieldManager.php:151-166
$collection = $this->collectionManager->get($collectionName, $effectiveTenantId);
if (!$collection) { throw new \InvalidArgumentException(...); }   // 只校验 collection 存在
// 1. Drop column from physical table | 从物理表删除列
$this->schemaBuilder->dropColumn($collection->getTableName(), $fieldName);   // ← 先删列
// 2. Delete field metadata | 删除字段元数据
$collection->removeField($fieldName);                                       // ← 后删元数据
```

`$fieldName` 来自 URL、零校验；代码只检查 `$collection` 是否存在，**不检查该字段是否存在** → 传 `tenant_id`/`site_id`/`created_at` 会真的 DROP。

生产被 `assertCanRunDDL` 拦住，但 dev/local/testing（含 CI 与开发机）可破坏租户隔离列。

**修复方向**：先 `getField()` 校验存在；禁删系统列白名单（`id/tenant_id/site_id/created_at/updated_at`）；`$fieldName` 加 `alphaDash` 规则。

#### I-3 测试套件不可信：42~43 个失败**全部**来自跨测试状态污染，无一是业务缺陷

> **2026-10-30 补充说明**：本节结论「失败全部是污染」成立，但**「无一是业务缺陷」的说法过于乐观**。
> 更准确的说法是：**失败列表里没有业务缺陷，但同时也没有任何测试覆盖真正的高危路径** ——
> C-5（伪造 JWT）、C-6（refresh 当 access）、C-7（租户上下文失效）三个已实测可利用的缺陷，
> 在 432 个用例中**零覆盖**。测试套件的问题不只是「红灯不可信」，
> 更是「绿灯也不可信」——它保护不了它声称保护的东西。

| 统计 | 结果 |
|---|---|
| 全量（两次运行） | Tests 424 / Assertions 1829 / **Errors 22 + Failures 20~21（合计 42~43，非确定性）** / Risky 4 / Skipped 2 / Incomplete 3 |
| 分套件 | **Unit 318 全过** / **Feature 64（12 失败）** / **Integration 39 全过** / **E2E 3 全过** |

**关键矛盾**：分套件跑只有 12 个失败，全量跑却是 42~43 个 → 说明 30 个失败是**跨测试污染**而非用例本身缺陷。

`[证据: docker exec alkaid-backend php think test → Tests: 424, Errors: 22, Failures: 20（首次为 21）; ./vendor/bin/phpunit --testsuite=Unit → Tests: 318 无失败; --testsuite=Feature → Tests: 64, Failures: 12; --testsuite=Integration → OK (39 tests); --testsuite=E2E → OK (3 tests)]`

**根因 1（约 30 项）：Mockery mock 泄漏进共享容器**

`tests/ThinkPHPTestCase.php:17` 的 `protected static ?App $app` 全进程唯一，`think-container` 的 `make()` 缓存实例且 `bind()` 后永不解绑：

```php
// tests/Unit/Lowcode/FormDesigner/FormDataManagerMultiTenantTest.php:44,61
$this->app()->bind(FormSchemaManager::class, $mockSchemaManager);
$this->app()->bind(CollectionManager::class, $mockCollectionManager);
```

代表性报错：
```
Mockery\Exception\BadMethodCallException: Received Mockery_4_…CollectionManager::create(), but no expectations were specified
```

复现实验：
```
--filter '(FormDataManagerMultiTenantTest|FormApiTest)'                → Failures: 2
--filter '(FormDataManagerMultiTenantTest|LowcodeCliIntegrationTest)'  → 6 个 Mockery 错误
--filter FormApiTest 单独                                              → 4/4 全过
```

**根因 2（约 12 项）：`Request` 上下文跨测试残留**

`app/Request` 把 `tenantId/siteId/userId/traceId` 存为实例属性；`make(\think\Request::class)` 命中容器缓存，测试拿到**上一个用例的请求对象**：

```
[diagA] request obj=1169 code=400
[diagB] request obj=1169 tenantId=3 siteId=0     ← 同一对象，租户被污染
```

`TraceIdCoverageTest` 全量跑时的真实失败断言：
```
-'test-trace-id-123'
+'3f3f8a42d9fd600caed4cbfdfc1eef7e'
tests/Feature/TraceIdCoverageTest.php:67
```
（单独运行则 9/9 通过 —— 证明是污染而非规范缺失）

**根因 3（1 项）：断言与实现相反**

`FormApiMultiTenantE2ETest::testInjectedTenantIdInPayloadIsIgnored:237` 断言 200，实测 403。实现是**更安全的 fail-closed**：

```php
// FormDataManager.php:64-70
if (isset($data['tenant_id']) && (int)$data['tenant_id'] !== $tenantId) {
    throw new ForbiddenException('租户无权访问该数据', ErrorCode::FORBIDDEN);
}
```

**为什么重要**：测试套件当前**不可作为质量信号**——42~43 个红灯里 0 个指向真实缺陷，反而已有 4 个安全/多租户测试因污染而「假通过」。

**修复方向**：每个测试类 `tearDown()` 清理 `bind`（`FormApiTest::setUp` 已有此 hack，应抽到基类）；`ThinkPHPTestCase` 改为每用例新建 App 或启用 `processIsolation`；`Request` 上下文改为容器注入而非属性缓存。

> **阶段 A 补记（2026-10-30，测试基线已推进，但本节结论未失效）**
>
> | 指标 | 报告原值 | 阶段 A 后 |
> |---|---|---|
> | 用例总数 | 424 | **476**（+52，新增全部与本次修复配套） |
> | Errors / Failures | 22 / 20~21（合计 42~43） | **22 / 19**（去重失败集 **41 项 < 42**） |
> | Risky | 4 | 4（不变） |
>
> 新增用例：C-5/C-6/C-7 22 个、R1 7 个、A5 8 个、A7 2 个、A4 5 个，共 44 个；
> 另有 A7 顺带补的 2 个。**新增用例无一落入失败清单**，且全量为干净跑（无并发代理）。
>
> 三条根因**仍然成立**：失败集与报告所列完全一致，污染机制未修（T-064 测试隔离仍在待办）。
> 唯一变化：`AuthRegisterMultiTenantTest::testRegisterCreatesTenantScopedUserRoleAndPermissions`
> 这一项 flaky 在本轮转为通过，故失败集从 42 降到 41。
>
> 结论更新为：**测试套件的「红灯」仍不可信，「绿灯」的可信度已因 44 个针对性新用例而在
> 高危路径上有所改善，但隔离机制本身未修，跨测试污染仍是系统性风险。**

#### I-4 工作流存在两套实现，且插件侧 41% 文件加载即 Fatal ⚠️ **本节已于 2026-10-30 修正（原结论不准确）**

> **修正说明**：本节原写「主应用未加载插件」。经复核，准确表述是：
> **未作为「插件」（无生命周期/无插件路由）加载，但已作为「类库」被使用** ——
> 根 `composer.json` 的 PSR-4 把 `Alkaid\Workflow\Engine\` 映射到 `plugins/workflow-engine/src/`，
> 且 `app/controller/workflow/` 中**直接 `new` 了 6 个插件类**。
> 因此「归档插件」方案不可行——会导致主应用 Fatal。

| 实现 | 位置 | 状态 |
|---|---|---|
| **原生 API 层** | `app/controller/workflow/*`（4 控制器，2,874 行）+ `route/workflow.php`（42 条路由） | **运行中，但数据在内存态**（7 处 `protected static array`，零 DB 调用） |
| **插件引擎库** | `plugins/workflow-engine`（`Alkaid\Workflow\Engine\`，213 个 src PHP） | **被主应用引用 6 个类**；其余多数不可加载 |

**关键事实（本轮实测）**：

```
1. 主应用引用的 6 个类（均无 madong 依赖，可正常加载）：
   Definition\{WorkflowBuilder, WorkflowDefinition, WorkflowValidator,
                Interfaces\WorkflowDefinitionInterface}
   Triggers\{TriggerManager, TriggerContext}

2. 213 个 src PHP 中 88 个（41%）`use madong\ingenious\*`，而该包从未安装：
   class_exists('Alkaid\Workflow\Engine\model\NodeModel')
   → Fatal error: Trait "madong\ingenious\libs\traits\DynamicPropsTrait" not found

3. 命名空间大小写缺陷（Linux 环境下 autoload 必 miss）：
   src/Core/ProcessEngines.php:14 → namespace ...Engine\core;  （小写）
   实际目录名 → Core                                            （大写）
   macOS 大小写不敏感掩盖了此问题

4. 5 个迁移位于 plugins/workflow-engine/migrations/，
   而 `php think migrate:run` 只扫 database/migrations/ → 表从未创建
   SHOW TABLES LIKE '%workflow%' → 0 行
   ✅ **阶段 A 已修**（`f3de7b0`）：4 个迁移纳入主链、5 张表建成、
      `migrate:status` 31 up / 0 MISSING、run→rollback→run 闭环通过。
      **当时外键仅成功 2/10**（其余 8 条类型不匹配被跳过）。
      后续由 `c137f12` 把 11 列改 `int unsigned` 并补齐 11 条外键，**实测外键 13 条**，
      且插入不存在的 `instance_id` 已被 1452 拒绝（R5 已修）。

5. 4 个 Repository（ProcessDefine/ProcessInstance/ProcessTask/WorkflowEvent）
   全部为 `// TODO: 实现数据访问逻辑; return null;`
   ⚠️ **表建好后此条更刺眼**：数据落地能力仍是 0，形成「表建好了没人用」。

6. **工作流任务授权曾有两个 fail-open 缺陷**（阶段 A 新发现并修复，`4b5f0cc`）：
   - `claim()` 守卫 `!empty(users) && !empty(groups)` 要求两类候选**都**非空，
     仅有候选用户/候选组时守卫被跳过 → 同租户任意登录用户可认领或审批
   - `checkTaskAccess()` 算出的 `$canAccess` 是**死代码**，从未被引用
   两者均已修复并补 7 个测试（`WorkflowTaskClaimAuthorizationTest`）。
```

`[证据: 见 design-implementation-reconstruction-2026-10-30.md §零/C-5 与 §五 P0-4/P0-5/P0-6]`

**为什么重要**：原判断「两套实现并存」方向正确，但结论过于简单。真实情况是
**「一个未接通的引擎库 + 一个不落库的 API 层」**，且引擎库近半文件是死代码。

**补充事实**：`addons/apps/_template/` 存在应用模板（`Application.php`、`manifest.json`、`install.sql` 等），说明应用生态骨架已铺，但无任何实际应用。

#### I-5 前端业务代码存在但不在版本库（C-2 的事实补充）

| 应用 | 自有代码 | 是否有业务代码 |
|---|---|---|
| `web-antd` | **89 文件（81 个 `.vue/.ts`）/ 8,217 行** | **有**：`views/system/{dept,menu,role,permission}` + `admin/permission-monitor.vue` + `api/system/{dept,menu,role,permission,tenant,user}.ts` + `api/lowcode/form.ts` |
| `web-ele` | 58 / 3,452 | 无，仅 `_core/dashboard/demos` 脚手架 |
| `web-naive` | 61 / 3,396 | 无，纯脚手架 |
| `web-tdesign` | 58 / 3,083 | 无，纯脚手架 |

前端已通过 `requestClient` 对接真实后端（`/v1/auth/*`、`/v1/admin/*`、lowcode），成功判定 `code === 0 || code === 200`（`api/request.ts:101`）。

**但**：未消费 `trace_id`；业务页无任何测试（40 个前端测试文件几乎全为脚手架自带）；且全部内容不在 git 中（C-2）。

`[证据: grep -rln '租户|低代码|工作流|部门|菜单|权限' frontend/apps/*/src; ls frontend/apps/*/src/views; find frontend/apps/web-antd/src -type f | wc -l → 89]`

#### I-6 `route:verify`（CI 门禁）实跑失败

```
docker exec alkaid-backend php think route:verify ; echo exit=$?
Current routes: 119 | Documented routes: 29
Routes not in documentation (需要添加到文档): ...
Routes do not match documentation!   exit=1
```

AGENTS.md 明确 CI 会执行该命令。当前基线为**红**（90 条路由未文档化）。对照 `php think lang:check` → 通过（`All language packs are consistent`）。

`[证据: 实跑上述两条命令]`

#### I-7 ~~JWT 默认密钥为仓库内公开字符串~~ → **已升级为 C-5（Critical）**

> 本节内容已并入 **C-5**。原定级为 Important 过低：实测可用公开密钥伪造任意用户身份并成功通过认证，
> 等价于完整认证绕过。此处保留指针，避免编号断档。

#### I-8 分层违规：控制器内直接写 SQL 与业务事务

```php
// app/controller/AuthController.php:403-472  resolveOrCreateDefaultUserRoleId
Db::startTrans();
$existing = Db::name('roles')->where('tenant_id', $tenantId)->where('slug', 'user')->find();
$newRoleId = (int) Db::name('roles')->insertGetId([... 'is_system' => ...]);
$permissionIds = Db::name('role_permissions')->where('role_id', (int)$templateRole['id'])->column('permission_id');

// app/controller/admin/RoleController.php:322
$userCount = \think\facade\Db::name('user_roles')->where('role_id', $roleId)->count();
```

违反 AGENTS.md「控制器仅做路由、校验、调用 Service/Domain 与统一响应；数据访问放 Repository/Infrastructure」。同处还有魔法值 `where('tenant_id', 1)`（硬编码「模板租户」）。

**反向依赖**：`domain/Event/EventLogger.php:7 use think\facade\Db;`、`domain/Event/EventService.php:7-8 use think\facade\Event/Queue;`、`domain/Event/EventContext.php:7 use think\Request;` → 领域层被框架绑死，无法独立测试。

#### I-9 `BaseApplication::executeSqlFile()` 是完全绕过 guard 的 DDL 入口

```php
// infrastructure/Application/BaseApplication.php:158-175
protected function executeSqlFile(string $relativePath): void
{
    $sql = file_get_contents($sqlPath);
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        // Db::execute($statement) —— 无 guard、无事务、按 ';' 粗暴切分
    }
}
```

设计文档未提及此入口。未找到 HTTP 可达路径，但作为插件/应用安装通道（`addons/apps/_template/sql/install.sql`）存在风险。

---

### Minor（改进建议）

| # | 问题 | 位置 |
|---|---|---|
| M-1 | **源文件含 NUL 字节**：`file app/Request.php` → `data`；`:230-233` 区间含 296 个 NUL；`grep` 需 `-a` | `app/Request.php` |
| M-2 | 死代码：`validate()` 失败分支不可达（`failException(true)` 直接抛异常） | `FieldController.php:50-57`；`BaseController.php:93` |
| M-3 | `updateField()` 是空壳，`TODO: Implement ALTER TABLE` | `FieldManager.php:101-139` |
| M-4 | `FormDataManager::save()` 更新路径影响 0 行仍返回成功 | `FormDataManager.php:111-120` |
| M-5 | 分页键名前后不一致（入参 `pageSize`，出参 `page_size`）；两仓储不一致 | `FormSchemaController.php:55` vs `ApiController.php:91` |
| M-6 | 保留框架示例路由 `think` / `hello/:name` | `route/app.php:14-16` |
| M-7 | 魔法数字散落（`5003`、`$statusCode*10`、`tenant_id=1`） | `SchemaBuilder.php:353`、`ExceptionHandle.php:95` |
| M-8 | 注释与实现矛盾（称表无 `tenant_id`，实际迁移 `20251220000005` 已加列） | `FieldRepository.php:65` vs `:124/147` |
| M-9 | trace_id 解析逻辑复制 5 份且实现各异 | `ApiController:244`、`Auth:221`、`Permission:231`、`SchemaBuilder:377`、`ExceptionHandle:55` |
| M-10 | `.env.example` 与 `.example.env` 重复（违反单一真相源） | 根目录 |
| M-11 | `role_permissions` 缺唯一约束（实测有重复授权行） | 迁移 `20251119210500` |
| M-12 | **php-cs-fixer 大面积不合规**：`Found 486 of 883 files that can be fixed`（exit 8） | 全仓 |
| M-13 | 17 个文件缺 `declare(strict_types=1)` | `app/event.php`、`AppService.php`、`middleware.php` 等 |
| M-14 | `tests/Helpers/AuthHelper.php:5` 命名空间写成小写 `tests\Helpers`（依赖 PSR-4 大小写不敏感「歪打正着」） | 同左 |
| M-15 | `assertDatabaseHas` / `assertDatabaseMissing` 使用数 = **0**；全项目不校验库内落盘 | `tests/` |
| M-16 | 错误码体系四套并存：`ErrorCode`(2001/2002…) vs `ApiController::error` 默认 400 vs `ExceptionHandle` `$statusCode*10` vs `PermissionMonitorController` 200/500 vs 硬编码 5003 | 多处 |
| M-17 | 测试失败时向**仓库根目录**写 `error_create.html` / `error_data.html`（实测已重新生成 232 B / 210 B） | `FormApiTest.php:246,320` |

---

## 五、设计文档与实现交叉验证

| 设计文档 | 判定 | 关键证据 |
|---|---|---|
| `03-data-layer/11-database-evolution-and-migration-strategy.md` | **已实现**（与设计一致） | `SchemaBuilder` + `app/command/TestDDLGuard.php` + 27 迁移；§6/§7 所述双闸门与 5003/403 均已落地。**但** guard 未覆盖 `hasTable/hasColumn`（C-1），且「生产只生成迁移建议」在请求路径未实现（生产直接 403） |
| `03-data-layer/10-api-design.md` | **已实现**（含 1 处偏差） | `ApiController:56-66` 五字段齐备，`code=0` 表成功，`timestamp=time()`；偏差：设计分页 `pageSize` 而实现 `page_size` + 新增 `total_pages` |
| `04-security-performance/11-security-design.md` | **已实现** | `CasbinService` + `DatabaseAdapter` + `config/casbin.php` + `casbin-model.conf` + `CasbinController` + 迁移 + 8 测试（含模式切换与缓存降级） |
| `09-lowcode-framework/40/41/42/43/45`（5 份） | **已实现** | `infrastructure/Lowcode/` 39 PHP（Collection/FormDesigner/Generator/Schema）+ 迁移 + `app/command/lowcode/` 4 个命令 + `LowcodeCliIntegrationTest` |
| `09-lowcode-framework/47-workflow-backend-engine.md` | **已实现但未接入** | 276 PHP / 7 层 / 5 迁移 / 51 测试齐备，但主应用未加载（I-4） |
| `01-architecture-design/04-multi-tenant-design.md` | **部分实现** | `tenant_id` 落地于 16 个迁移、`site_id` 于 8 个迁移，infrastructure 29 文件、domain 7 文件；**独立库/混合模式无实现**（27 个迁移全在同一 schema） |
| `01-architecture-design/06-application-plugin-system-design.md` | **部分实现** | `infrastructure/Plugin/` 18 PHP 齐备（Contract/Enum/Exception/Hook×4/Metadata/Manager/Scanner/TypeDetector/Repository×2）+ 2 迁移；六大应用（电商/OA/CRM/ERP/CMS/AI）**无目录**；`addons/apps/` 仅有 `_template` |
| `06-frontend-design/17-admin-frontend-design.md` | **部分实现** | `web-antd/src` 89 文件 / 8,217 行业务代码，`api/system/*` 六模块落地；但 3/4 apps 为纯脚手架；且不在版本库（C-2） |
| `09-lowcode-framework/48-workflow-frontend-apps.md` | **未发现实现** | 顶层 `plugins/workflow-ui/` 仅 **3 个 Vue 组件**（MiniMap/ExportDialog/ImportDialog）、无 `package.json`、无入口；`frontend/apps/` 五个子应用均无工作流应用 |
| `02-app-plugin-ecosystem/06-3`、`06-4`（市场） | **未发现实现** | `marketplace` / `market` 在 `app/`、`infrastructure/`、`domain/`、`route/`、`app/controller` 全库搜索**零匹配** |

**补充发现**：`plugins/workflow-engine/packages/workflow-ui/` 是一个**完整**的 Vue3 组件库（39 文件，其中 31 个 `.ts/.vue` / 9,911 行，含 `src/core/WorkflowEngine.ts`、`components/designer/WorkflowDesigner.vue`、8 类节点组件、`vitest.config.ts`），与顶层残缺的 `plugins/workflow-ui/`（3 个组件、无配置）**是两份不同的东西**。前者具备工作流设计器能力，但未被主前端引用。

`[证据: find plugins/workflow-ui -type f | wc -l → 3; find plugins/workflow-engine/packages/workflow-ui -type f | wc -l → 39（31 个 ts/vue，9,911 行）]`

---

## 六、测试真实状况（关键澄清）

| 指标 | 数值 |
|---|---|
| 用例 / 断言 | 424 / 1,829 |
| 全量失败 | **22 errors + 20~21 failures = 42~43（两次运行不一致，非确定性）** |
| 分套件 | Unit 318 全过 / Feature 64（12 失败）/ Integration 39 全过 / E2E 3 全过 |
| 失败归因 | 约 30 项跨测试 Mockery 泄漏 + 约 12 项跨测试 Request 上下文残留 + 1 项断言过时 = **全部，业务缺陷 0** |
| `assertDatabaseHas` | **0 处** |
| Mockery 使用 | 7 个文件 |
| `TraceIdCoverageTest` 单独运行 | **9/9 通过（38 断言）** |
| 6 个多租户测试类单独运行 | **5 全过，1 个仅 1 处失败（断言过时）** |

**结论**：当前测试套件**不能作为质量门禁**。修复测试隔离基建（I-3）应优先于修复任何具体用例，否则新增测试同样会被污染。

---

## 七、Recommendations

### 7.1 优先级

| 级别 | 时限 | 事项 |
|---|---|---|
| **P0** | 立即 | ① 修复 `frontend/` 悬空 gitlink，把前端源码纳入版本控制（C-2）<br>② `SchemaBuilder` 加标识符白名单，并使 `hasTable/hasColumn` 受控（C-1）<br>③ 注册接口改为邀请码/域名绑定，`X-Tenant-ID` 加校验（C-4） |
| **P1** | 1~2 周 | ④ `.gitignore` 补 `plugins/**/vendor/` 并移出索引（C-3）<br>⑤ 修复测试隔离基建（I-3），恢复套件可信度<br>⑥ 修复 `PermissionMonitorController` 统一响应（I-1）<br>⑦ `FieldController::delete` 加系统列保护（I-2） |
| **P2** | 1 个月 | ⑧ 明确工作流两套实现取舍并接线（I-4）<br>⑨ 修复 `route:verify` 文档缺口（I-6）<br>⑩ JWT 密钥 fail-fast（I-7）<br>⑪ 控制器越层 SQL 收敛到 Repository（I-8）<br>⑫ `BaseApplication::executeSqlFile()` 接入 guard（I-9） |
| **P3** | 技术债 | ⑬ php-cs-fixer 全量修复（M-12）<br>⑭ 清理调试残留与 NUL 字节文件（2.5 / M-1）<br>⑮ 统一错误码体系（M-16）<br>⑯ 补 `assertDatabaseHas` 真实库断言（M-15） |

### 7.2 测试策略

当前 100% 依赖内存 mock，`assertDatabaseHas` 为 0，无法验证 SQL 执行 / 事务 / 索引 / 约束。建议分层：

| 层级 | 目标 | 依赖 |
|---|---|---|
| 单元 | 纯逻辑（如 `TableNameGenerator` 命名规则） | 可 mock |
| 集成 | 模块交互（低代码 → 事件 → 权限） | 真实数据库 + `assertDatabaseHas` |
| E2E | 完整 API 链路 | HTTP 客户端 + 真实数据库 |

**前置条件**：先修测试隔离（每用例独立 App / `tearDown` 清理 `bind` / `Request` 上下文不缓存在实例属性），否则新增测试同样被污染。

### 7.3 CI 建议门禁

```yaml
- name: PHP Syntax
  run: docker exec alkaid-backend find app domain infrastructure tests route config plugins -name "*.php" -not -path "*/vendor/*" | xargs -P4 -n1 php -l
- name: Raw SQL Safety
  run: docker exec alkaid-backend php scripts/check-rawsql-safety.php
- name: Route Verify
  run: docker exec alkaid-backend php think route:verify
- name: Lang Check
  run: docker exec alkaid-backend php think lang:check
- name: PHPUnit
  run: docker exec alkaid-backend php think test
- name: Migration Status
  run: docker exec alkaid-backend php think migrate:status
```

注意：`plugins/` 当前**未纳入任何检查**，应加入；`check-rawsql-safety.php` 的 allow-list 整文件豁免了 `SchemaBuilder.php`，应改为只豁免已加标识符校验的行。

### 7.4 文档治理

1. `docs/report/README.md` 的归档计数已于 2026-10-30 按 `find` 重算（原 36/6/10/19 → 真值 25 个顶层 md / 12 / 6 / 21）
2. 设计文档头部增加「实现状态」标记（已实现 / 部分实现 / 未实现），至少覆盖第五章的 10 份
3. `design/01-architecture-design/04-multi-tenant-design.md` 与 `06-application-plugin-system-design.md` 最后更新为 2025-01-19，早于最早相关迁移（2025-11-19）10 个月，从未回填

---

## 八、Assessment

### Ready to merge? **No**

### Reasoning

代码基础面是好的：分层严格（S-1）、统一响应已规模化落地（S-2）、DDL 防护与设计一致且实证生效（S-3）、多租户隔离在核心组件完整（S-4）、Casbin 与低代码实现成熟（S-6/S-7）。

但存在 **4 个 Critical**：
- **C-2 前端源码完全不在版本库**（可导致永久丢失）
- **C-1 生产环境仍可触发的注入点**
- **C-3 39.28 MB vendor 入库**（C-3 已于 2026-11-02 处理，见 C-3 节追记）
- **C-4 未认证跨租户账号注入**

且测试套件当前不可信（I-3），无法作为质量信号。

**完成度评估**：

| 模块 | 实现度 | 依据 |
|---|---|---|
| 低代码框架 | 85% | 39 PHP + 5 子域 + CLI + 迁移 + 测试；Legacy 已收敛 |
| 事件与钩子 | 80% | domain/Event 6 文件 + EventMonitor + Hook 4 文件 + 3 迁移 |
| 多租户 | 80% | 16/8 迁移 + 4 核心组件过滤齐全；缺独立库模式 |
| 权限 Casbin | 75% | 6 文件 + 2 配置 + 8 测试 + 模式切换 |
| 系统管理 | 70% | 6 控制器 + 2 域模型 + 2 仓储 + 4 迁移 |
| 工作流（后端） | **35%** | 引擎库 213 PHP 中 88 个加载即 Fatal；API 层 2,874 行但零 DB 调用（内存态）；5 个迁移从未执行、表不存在；4 个 Repository 全 TODO。**原估 70% 高估**（见 I-4 修正） |
| 插件系统 | 65% | 18 PHP + 2 迁移；六大应用与市场未做 |
| 前端 | 55% | `web-antd` 系统管理已可用；其余 3 app 为脚手架；**且未入版本库** |
| 应用/插件市场 | 0% | 仅设计 |

**综合约 60%**。瓶颈已从「写代码」转移到「工程治理」——前端未入库、vendor 入库、工作流双实现并存。这三项不解决，代码写得再多也无法安全交付。

---

## 九、覆盖度与未验证声明

**已覆盖**：

- 后端全量 PHP 语法检查（容器内 `php -l`，app/domain/infrastructure/tests/route/config + plugins）→ 0 错误
- `plugins/workflow-engine` 276 个自有 PHP 语法检查 → 276/276 通过
- 全量测试执行 2 次（证明非确定性）+ 4 个套件分别执行 + 6 个多租户类单独执行 + `TraceIdCoverageTest` 单独执行
- 迁移数量与 `migrate:status` 双向核对（27 = 27）
- 分支拓扑：8×8 `merge-base` / `is-ancestor` 矩阵 + 全仓 tree 比对 + `60c2387` 与 `8811267` tree 逐字节比对
- 文档数量：全部以 `find | wc -l` 为准
- 设计-实现交叉验证：10 份核心设计文档
- 注入 PoC：容器内直调 `SchemaBuilder` 验证布尔注入与 guard 行为
- CI 门禁：`route:verify`（红）、`lang:check`（绿）、`php-cs-fixer --dry-run`（486/883）

**未覆盖 / 未验证**：

1. **HTTP 层真实攻击验证**：未构造合法 JWT 发起真实请求（避免写入脏数据），C-1 可利用性由源码逐跳 + 容器内直调 PoC 推断；URL 层注入被排除的依据是 `config/route.php:25` 的路由正则，非实跑
2. **`APP_ENV=production` 运行实例**：容器内无该配置实例，5003/403 分支仅通过 `HttpResponseException` 观察
3. **`BaseApplication::executeSqlFile()` 的 HTTP/CLI 可达路径**：只找到定义与 `addons/apps/_template/sql/*.sql`，未追到调用方
4. **Swoole 常驻进程下 `Request` 单例的实际泄漏面**：`config/swoole.php` 存在，但无 Swoole 实例可验证
5. **两个 gitlink 指向对象的来源仓库**：对象不在库中，且无 `.gitmodules`，无法定位
6. **`plugins/workflow-engine` 276 个 PHP 的功能正确性**：仅验证语法与测试数量，未逐个审阅逻辑
7. **`web-antd` 89 个文件中业务代码与 Vben 模板示例的精确占比**：现有测试显示 `views/system/*` 与 `api/system/*` 为自建，但逐文件归属未判定
8. **失败数抖动（20 vs 21）的具体抖动用例**：推测与 `RateLimitMiddlewareTest` 依赖 Redis 计数有关，未定位
9. **各分支历史上是否曾被 force-push**：远端 reflog 不可得

---

## 十、本次复核记录

本报告的每一项结论均经主审独立复核。复核中发现并修正的偏差如下（保留以证明复核确实执行）：

| # | 初稿表述 | 复核后修正 | 原因 |
|---|---|---|---|
| 1 | `plugins/workflow-engine/vendor` 39.28 MB 占全仓 **78.9%** | **78.8%** | 39.28 / 49.81 = 78.85%，四舍五入应为 78.8% |
| 2 | `web-antd/src` **89 文件 / 8,217 行** | **89 文件（81 个 `.vue/.ts`）/ 8,217 行** | 89 为全部文件数，81 为 `.vue/.ts` 数，行数按后者统计；初稿混用了两个口径 |
| 3 | `packages/workflow-ui` **30 文件 / 10,476 行** | **39 文件（31 个 `.ts/.vue`）/ 9,911 行** | 初稿采用了子代理的未复核数字；实测 39 总文件、31 个 ts/vue、9,911 行 |
| 4 | `PermissionMonitorController` **16 处** `return json(` | **15 处** | 逐行计数应为 15（`grep -c` 实测） |
| 5 | 测试失败 **43 个（22 errors + 21 failures）** | **42~43（Errors 22 + Failures 20~21）** | 两次实跑分别为 43 与 42，需如实标注非确定性 |
| 6 | `config/plugin.php` base_path 指向 **不存在的** `addons/plugins` | 指向 `addons/plugins`，该目录**存在但为空**（仅 `.gitkeep` + `README.md`） | 子代理断言「不存在」未经核实；实测目录存在，但不含 workflow-engine |
| 7 | 跟踪 **5,421 文件 / 49.81 MB** | 保留基线值，并注明**基线口径** | 报告自身提交会使其递增，须声明口径 |
| 8 | `develop` 领先 **136**、从未推送 **52** | 保留基线值，并注明「`--all` 口径当前为 53」 | 同上 |
| 9 | 「`hasTable`/`hasColumn` 不受 guard 保护」 | 追加 4 个调用点与 2 个未调用方法的**行号级证据** | 初稿仅给结论，未附逐方法行号 |
| 10 | 「`addons/apps/` 无任何实现」 | 修正为「仅有 `_template` 模板」 | 实测存在模板骨架，非完全空白 |

**复核方法**：所有数字均以 `find | wc -l`、`git ls-tree`、`grep -c` 直接测得；所有代码断言均以 `sed -n` 打印原文核对行号；测试结论以 3 类运行方式（全量 2 次、分套件 4 次、单类 7 次）交叉验证。

---

**报告生成时间**：2026-10-30
**审查方式**：四路独立只读审计 + 主审逐项复核
**审查对象**：AlkaidSYS-tp 本地 `develop` 分支 `f119d98`

---

## 十一、本报告修订记录

| 日期 | 修订内容 | 触发原因 |
|---|---|---|
| 2026-10-30（初版） | 4 Critical / 9 Important / 17 Minor | 四路独立审计 + 主审复核 |
| 2026-10-30（阶段 A 修复） | **C-5 / C-6 / C-7 已修复并经独立复验**（提交 `a7cf49b`、`7860fab`）：<br>· C-6 refresh→401（原 200）<br>· C-5 生产密钥 11/11 拒绝矩阵（原 6/7 可绕过）+ 修回「dev 空密钥导致能签发不能校验」的回归<br>· C-7 54 处属性访问改方法调用，残留 0<br>· **新发现并修复 3 个使工作流定义接口完全不可用的既有缺陷**：`validate()` 与父类签名不兼容（类声明期 Fatal）、`ValidationResult::getInfo()` 不存在、对 string 日期调 `format()` → 500<br>· 测试 +22 用例（454），失败集合与基线逐条相同（零回归） | 按阶段 A 计划执行 C-5/C-6/C-7；引入 superpowers TDD + 子代理实施 + 独立 Reviewer 评审，评审发现 1 个我方引入的 Critical 回归（`.env.example` 空密钥）与 6 条绕过路径，已在第二轮修复 |
| 2026-10-30（前序） | **Critical 4 → 7**：新增 C-5（JWT 公开密钥可伪造身份，实测 HTTP 200 冒充成功）、C-6（refresh 当 access 用，实测 200）、C-7（`$request->tenantId` 属性访问恒 null，54 处过滤失效）<br>**I-7 升级并入 C-5**（原定级 Important 过低）<br>**I-4 结论修正**：由「主应用未加载插件」改为「未作为插件加载、但作为类库被引用 6 个类」；补充插件 41% 文件 Fatal<br>**S-7 加限定**：`php -l` 通过 ≠ 可加载，88 文件缺 madong trait<br>**I-3 加限定**：失败确为污染，但三个 Critical 缺陷零测试覆盖<br>**工作流完成度 70% → 35%** | 设计文档全域对照（`design-implementation-reconstruction-2026-10-30.md`）发现原报告遗漏 |

**配套基线报告**：[`design-implementation-reconstruction-2026-10-30.md`](design-implementation-reconstruction-2026-10-30.md)
——184 份文档逐份对照 + 10 项实测，是本报告的上游事实来源，两者冲突时**以该报告为准**。

