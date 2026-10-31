# AlkaidSYS 重启开发前生产就绪度分析报告

| 项目 | 内容 |
|---|---|
| 报告日期 | 2026-10-31 |
| 分析对象 | `develop` 分支工作区（含本会话全部修复提交） |
| 方法 | Graphify 代码图谱 + 4 路并行只读实证核查 + 主审逐条复核 |
| 图谱 | Graphify 0.9.99（tree-sitter AST，本地确定性，无 LLM） |
| 核查范围 | 后端 612 文件（`app/ domain/ infrastructure/ database/ config/ route/ tests/ scripts/` + `plugins/workflow-engine/src`） |
| 结论 | **不可直接重启开发**——存在 6 项 P0 阻塞，其中 2 项使「测试通过」失去意义 |

> **本报告的一手证据优先**。凡标注 `[实测]` 的结论，均为本会话实际执行命令的输出；标注 `[推断]` 的为读码推理；标注 `⚠️ 证据不足` 的未验证。

> ⚠️ **2026-10-31 订正（后续复核推翻本报告 3 处结论）**
>
> 由 `docs/report/enterprise-production-restart-audit-2026-10-31.md` 提出质疑后逐条复验，确认本报告有 3 处错误，**以订正后的表述为准**：
>
> 1. **「四个 check 脚本一个都没接进 CI」——错误。** 四份工作流各跑一个脚本：
>    `docs-check.yml` → `check-docs.php`；`migrations-check.yml` → `check-migrations.php`；
>    `phpunit-config-check.yml` → `check-phpunit-config.php`；`env-check.yml` → `check-env-example.php`。
>    （其中 `check-migrations` 在 CI 只跑 A/C 静态项，B/D/E/F 需 applied 状态故传 `--no-db`。）
> 2. **「测试直接写开发库」——只对本地成立。** CI 的 `phpunit` job 用独立 service 库
>    `alkaid_sys_test`（`MYSQL_DATABASE` + step 级 `DB_NAME`），隔离是正确的。
>    污染发生在**本地** `phpunit.xml`（`DB_NAME=alkaid_sys`）。该问题已修（见阶段 A / ER-15）。
> 3. **「空接口导致仓储无法实现」——技术表述不准确。** 空接口**可以**实现，
>    缺的是**语义契约**（方法集合与错误语义未定义），不是语言层面的不可能。
>
> 另有两处口径需修正：
> - 「25 条公告 / 10 包 / 2 High」是**全依赖**口径；生产口径（`--locked --no-dev`）为 **24 条 / 9 包 / 1 Critical + 3 High**。以后统一用生产口径。
> - 「`$tasks` 永远为空所以任务 API 是死代码」——论据不足。任务通常由**引擎产生**而非手工创建；
>   真实缺陷是「生成 → 执行 → 持久化」链路未接通，而非缺少 POST 端点。
>
> 本报告的就绪度百分比（36%）无统一权重、不可复现，**后续不再引用**。

---

## 零、一页摘要：五个必须知道的事实

### 1. **CI 里的测试从未执行过**

```
.github/workflows/backend-php-cs-fixer.yml
  :86  phpunit:
  :90    needs: [php-cs-fixer, lang-check]
```

而 `php-cs-fixer` job 当前对 **492/922 文件**报 `fixable`（53.4%）`[实测]` → 该 job **恒红** → 依赖它的 `phpunit` job **永远不会启动**。

**这意味着：仓库里所有「测试通过」的结论，在 CI 上都没有依据。**

### 2. **本地测试直接写开发库**

```
phpunit.xml:49   <env name="DB_NAME" value="alkaid_sys"/>
phpunit.xml:32   <env name="ALLOW_RUNTIME_DDL" value="true"/>
```

测试与开发共用一个库，且允许运行时 DDL。实测该库已沉淀 **369 张表 / 207 个租户** `[实测]`（其中 330 张是测试遗留的 `lc_*` / `custom_table_*`）。

**这意味着：`Tests: 536, Errors: 22, Failures: 19` 这个基线在物理上不可复现**——每次跑都会改变它所测量的环境。

### 3. **代码风格门禁是「事实检查」的上游，而它红着**

CI 流水线是 `php-cs-fixer → lang-check → phpunit` 串行依赖。风格检查红 → 语言包检查红 → 测试不跑 → 迁移检查不跑 → 路由文档检查不跑。

四个静态门禁脚本（`check-migrations` / `check-docs` / `check-phpunit-config` / `check-env-example`）**全部只校验「文件/配置的形状」，不校验业务正确性**，而且**一个都没接进 CI**。

**这就是当前的真实状态：格式检查全绿，事实检查全空。**

### 4. **依赖链有 25 条安全公告，含 2 条 High**

```
$ composer audit   [实测]
Found 25 security vulnerability advisories affecting 10 packages
  guzzlehttp/guzzle        high    CVE-2026-69246
  phpseclib/phpseclib      high    CVE-2026-44167
  symfony/expression-language  CVE-2026-109449（无界嵌套导致进程崩溃）
  ...（共 10 个包）

$ composer validate   [实测]
- The lock file is not up to date with the latest changes in composer.json
- Defining autoload.psr-0 with an empty namespace prefix is a bad idea
```

`symfony/expression-language` 尤其相关——**工作流的条件求值正是表达式引擎**，该 CVE 的形态就是「不可信表达式打崩进程」。

### 5. **工作流「表已建好，代码从未落库」**

| 事实 | 证据 |
|---|---|
| 5 张表已建、13→18 条外键、全链路 `int unsigned` | `[实测 information_schema]` |
| 4 个仓储 = **17 个纯桩**，全返回 `null`/`[]`/`false` | `[实测]` 无 `__construct`、无 `Db::`、无接口实现 |
| 4 个仓储**零调用方** | 图谱 `graphify affected` 返回空 + `grep` 零命中，两路独立一致 |
| API 层 7 处 `static array`、**71 处读写点**、40 个端点 | `[实测]` |
| **`$tasks` 永远为空**：12 个任务端点全是 `:id` 操作，**没有任何创建任务的端点** | `[实测]` `route/workflow.php:128-185` |
| 引擎执行内核**不可用**：88 个文件依赖 `madong\ingenious`，而根 autoloader 无该映射 | `[实测]` |

**任务 API 整体是死代码**——这反而是好消息：任务落库没有存量行为要保。

---

## 一、方法与工具

### 1.1 Graphify 索引

```
$ uv tool install graphifyy          # graphify 0.9.99，含 tree-sitter-php
$ graphify update . --no-cluster
Rebuilt: 6522 nodes, 14196 edges     # 612 文件 / 590 PHP，耗时 27 秒
```

**全仓索引失败的诚实记录**：首次对全仓（7,161 被跟踪文件，含 4,270 个插件 vendor 文件）执行 `graphify update .`，**运行 42 分钟无产物**，CPU 持续消耗但 AST 缓存停在 5,436 文件。加 `--no-cluster` 重试 15 分钟仍无进展，遂终止。改为 `git archive` 导出后端 612 文件到临时目录索引，27 秒完成。

**工具局限（必须说明）**：

| 局限 | 影响 |
|---|---|
| 文件级依赖边不可靠 | `imports` 边连到符号/外部节点而非文件节点，文件入度全为 0，**不能用它做死代码判定** |
| 仅索引代码 | 不含 `docs/` `design/` `frontend/`，文档-代码一致性无法用它 |
| 未做社区聚类 | `--no-cluster`，`community` 字段为空，无社区标签 |

**仍然有效的用法**：符号级图解（`graphify explain`）、枢纽识别（`graphify god-nodes`）、反向影响面（`graphify affected`）。本报告中的「零调用方」结论即由 `affected` + `grep` 双路确认。

### 1.2 图谱给出的架构画像

```
$ graphify god-nodes --top 20   [实测]
 1. ExecutionContext        230 边
 2. ThinkPHPTestCase        168 边
 3. TriggerContext          104 边
 4. NodeResult               98 边
 5. WorkflowDefinition       76 边
 6. CollectionManager        71 边
 7. PermissionService        67 边
 8. PluginInterface          67 边
11. JwtService               64 边
16. ApiController            52 边
20. CasbinService            48 边
```

**读法**：前 5 名全是工作流引擎的类，说明**代码体量与连接密度最集中在工作流引擎**——而它恰恰是唯一没有落库、且执行内核不可用的部分。这是一个「投入最多、可用最少」的结构失衡。

`ThinkPHPTestCase` 排第 2（168 边）也值得注意：测试基类是第二大枢纽，意味着**测试代码与生产代码的耦合度很高**，这解释了为什么测试会污染开发库。

---

## 二、遗留项逐项判定（13 项）

| # | 项 | 原判定 | 本次判定 | 级别 |
|---|---|---|---|---|
| C-1 | 低代码标识符拼接注入 | Critical | **仍成立，且比原描述严重** | **P0** |
| C-3 | 插件 vendor 入库 | Critical | 仍成立；**删除几乎不破坏任何东西** | P1 |
| R2 | 密钥校验非启动期 | 遗留 | 仍成立；**首个 `/auth/login` 即 500** | P1 |
| R3 | `.env` 注释禁 ASCII 括号 | 遗留 | ✅ **已修复**（约定 + CI 门禁），有覆盖面缺口 | P3 |
| R4 | admin 控制器无租户测试 | 遗留 | 仍成立，**缺口 50/50（全部未覆盖）** | P1 |
| R7 | `boot()` 每次 `scandir` | 遗留 | 描述准确，**影响≈0**（每 worker 一次，非每请求） | P3 |
| R8 | `addon\` 无 PSR-4 | 遗留 | 仍成立，**且是双重缺陷**（补 PSR-4 也修不好） | P2 |
| R9 | 无独立 `edges` 列 | 遗留 | **原判定不准确**：真问题是 edges 无处落地 + `bpmn_xml` 从未被解析 | **P0** |
| R12 | `.env.example` 缺 REDIS/RABBIT | 遗留 | 部分修复：`config/queue.php` 与 workflow 插件仍 `127.0.0.1`；RabbitMQ 完全未接线 | P2 |
| R24 | JWT 未加租户校验 | 遗留 | 仍成立，但 dead code + footgun，**无现网影响** | P3 |
| R25 | 语言探测在构造期 | 遗留 | **不成立（前提错误）**：每请求探测一次，语言切换正常；但有 2 个真实缺陷 | P3 |
| R50 | seeder 权限断言只查 DB | 遗留 | **成立且更糟**：断言已退化为自证；3 个 slug 后端无任何执行点 | **P0**（数据一致性） |
| R51 | 剩余 `sprintf` 拼 SQL | 遗留 | 部分修复（R49 有遗漏），剩余处值均为代码内常量 | P3 |
| R52 | `Console::run()` 只捕 `\Exception` | 遗留 | **类型缺口成立，但「退出码 0」实测为 255（我此前的说法被证伪）** | P3 |

### 2.1 C-1：注入面比原描述严重（P0）

**证据** `[实测]`：

| 位置 | 事实 |
|---|---|
| `infrastructure/Schema/SchemaBuilder.php:146` | `Db::query("SHOW TABLES LIKE '{$tableName}'")` —— 单引号内插值 |
| `:258` | `SHOW COLUMNS FROM \`{$tableName}\` LIKE '{$columnName}'` —— 反引号内插值 |
| `:63/:77/:79/:83` | `CREATE TABLE`、索引列 `implode`、`ENGINE={$engine}`、`COMMENT='{$comment}'` |
| `:315-370` | `assertCanRunDDL()` 只被 `createTable/dropTable/addColumn/dropColumn` 调用 |
| **`hasTable()` / `hasColumn()` 不调用 guard** | **读路径完全无守卫，生产环境照样执行** |

**输入链无任何校验**：

```
POST /v1/lowcode/collections
  → CollectionController.php:123   'table_name' => $data['table_name'] ?? null
  → Collection.php:54-55           $name / $tableName 原样赋值
  → CollectionManager.php:84       hasTable($tableName)   ← 注入点，在 guard 之前
  → CollectionManager.php:87       createTable()          ← 到这里才被 assertCanRunDDL 拦
```

`TableNameGenerator.php:21-28` 只做 camel→snake + `strtolower`，**无白名单**——`'`、`` ` ``、`;`、空格全部放行。

**修复要点**：① 标识符白名单正则（`^[a-z][a-z0-9_]{0,63}$`）作为唯一入口，不依赖 guard；② `hasTable/hasColumn` 也必须走同一校验；③ 加一条测试：传入 `a';DROP TABLE x;--` 必须被拒。

### 2.2 C-3：vendor 入库（P1）

`[实测]` 4,270 个被跟踪文件、占全仓跟踪内容的主要体积；**主应用 composer autoload 只映射 `plugins/workflow-engine/src/`，不加载它的 vendor**；`grep madong` 在主应用 0 命中。

**删除几乎不破坏任何东西**——但会切断「未来引入 `madong/helper`」的唯一现成来源（见工作流方案 D2）。

### 2.3 R9：`edges` 无处落地（P0，原判定需修正）

原以为「文档要 edges 列、实现没建」。实际是三个更严重的问题 `[实测]`：

1. `workflow_definitions` 的 `nodes_config` 实测形状是 `{nodeId:{id,name}}`，**不含连线**
2. `bpmn_xml` 列存了真 BPMN 2.0 XML，但**全仓无任何代码解析它**
3. 引擎 `ModelParser::parse():47` 期望的输入是 `{nodes:[],edges:[]}` JSON，与上述两者都不同格式

→ **定义体的三种表示互不通**，且连线的真值在 API 层就被丢弃（`$connections` 未被任何持久化结构接收）。

### 2.4 R8：双重缺陷（P2）

`composer.json` 无 `addon\` 映射；且 `addons/apps/_template/Application.php` 声明 `namespace addon\template` 而目录名是 `_template` → **即便补上 PSR-4，`class_exists` 仍为 false**。应用系统因此不可用。

### 2.5 R25：我的前提是错的（P3）

此前判断「`LanguageService` 在构造时探测语言，Swoole 常驻下复用控制器会让语言切换失效」。实测：**每请求新建控制器实例，语言探测每请求执行一次，`?lang=` / `Accept-Language` 切换正常**。原判断不成立。

但发现 2 个真实缺陷（详见核查原文）。

### 2.6 R50：断言已退化为自证（P0）

`CorePlatformSeed` 的 `USER_READONLY_PERMISSION_SLUGS` 断言只校验「DB 里有没有该 slug」，**不校验「常量 ⊆ 权限定义集」**。后果 `[实测]`：把定义里的 `forms.view` 打成 `forms.veiw` 后，老库上旧 slug 仍在 → 断言通过 → 新增一条僵尸权限（`permissions` 从 39 变 40），**无任何报错**。

且这 3 个 slug（`forms.view` / `form_data.view` / `collections.view`）**在后端无任何路由或中间件引用**——授权本身目前没有执行点。

### 2.7 R52：我此前的说法被证伪（P3）

我此前称「未捕获的 `\Error` 退出码为 0，会让 CLI 静默成功」。**实测为 255**，不是 0。`think\Console::run()` 只捕 `\Exception` 是事实，但 PHP 未捕获异常默认退出码 255，CI 可感知。

顺带发现：`AppService.php:134-140` 的注释（称 0→1）与另一处注释矛盾，**实测支持后者**——R37 加的 `register_shutdown_function(exit(1))` 实际是把 **255 统一成 1**，而非 0→1。注释需修正，否则误导排障。

---

## 三、本次核查新发现（超出原列表）

| # | 发现 | 级别 |
|---|---|---|
| N1 | **CI 中 `phpunit` job 依赖 `php-cs-fixer` job，后者恒红 → 测试从未在 CI 执行** | **P0** |
| N2 | **`phpunit.xml` 的 `DB_NAME=alkaid_sys` + `ALLOW_RUNTIME_DDL=true` → 测试写开发库**，已沉淀 369 表 / 207 租户 | **P0** |
| N3 | **`composer audit`：25 条公告 / 10 包 / 2 High**，含 `symfony/expression-language` 无界嵌套崩溃 | **P0** |
| N4 | **`composer.lock` 与 `composer.json` 不同步** + 空 PSR-0 前缀警告 | **P0** |
| N5 | 4 个门禁脚本（`check-*`）**一个都没接 CI** | P1 |
| N6 | 5 个工作流带 `if: github.repository == ...`，fork PR 的 job 会被 skip | P1 |
| N7 | `docs/report` 实有 27 份、索引说 26 份、5 份未登记；`design/` 状态声明覆盖 **14/73** | P1 |
| N8 | `WorkflowBootstrap` 零调用，且其配置路径有 bug（`getAppPath().'config/workflow.php'` = `app/config/workflow.php`，该目录不存在）→ 若将来启用会「静默用空配置」 | P1 |
| N9 | `domain/` 存在 4 处反向依赖：`EventLogger.php` 直接用 `think\facade\Db`、`EventService.php` 用 `Event`/`Queue` facade、`AsyncEventJob.php` 依赖 `Infrastructure\Event\EventMonitor`、`DI/ServiceProvider.php` 依赖 `Infrastructure\DI\AbstractServiceProvider` | P1 |
| N10 | 3 个控制器直接写 SQL（`AuthController` / `admin/RoleController` / `workflow/WorkflowTaskController`），其中一处直接开事务 | P1 |
| N11 | UI 层 `$tasks` 永远为空（无创建任务端点）→ 12 个任务端点是死代码 | P2 |
| N12 | 4 个仓储的返回类型是**空接口**（`interface IProcessDefine {}`，零方法）→ 按现状「照着签名填 TODO」不可能正确 | **P0**（阻塞设计） |

---

## 四、工作流持久化：最大的一块欠账

### 4.1 现状（全部 `[实测]`）

| 维度 | 事实 |
|---|---|
| 表 | 5 张已建、18 条外键、全链路 `int unsigned` |
| 仓储 | 4 个类、**17 个纯桩**、无构造参数、无 `Db::`、无接口实现 |
| 契约 | 返回类型是空接口（`IProcessDefine {}`）→ **无法实现** |
| 调用方 | 图谱 `affected` = 空 + `grep` 零命中 |
| API | 7 处 static array、71 处读写点、40 端点 |
| 已死 | 任务 API（无创建端点）；`TriggerManager` 只做类型查表；`WorkflowBootstrap` 零调用 |
| 内核 | 88 文件依赖缺失的 `madong\ingenious` |

### 4.2 十项必须解决的冲突

| # | 冲突 | 关键证据 |
|---|---|---|
| C1 | **ID 类型**：API 造字符串（`'inst_'.uniqid()`），DB 是 `int unsigned` | `InstanceController.php:202`、`TriggerController.php:292`、`Definition.php:179` |
| C2 | **同一坑已踩过**：seeder 原用字符串主键 → MySQL 1366 | `20251216000003:14-17` 注释自述 |
| C3 | **`title` 缺失导致 INSERT 必失败**：`POST /instances` 不设 `title`，DB `title NOT NULL` | `InstanceController.php:205-220` |
| C4 | **字段名漂移**：`started_by`↔`created_by`、`type`↔`task_type`、`status`↔`is_active` | 控制器 vs 迁移 |
| C5 | **十余个 API 字段无对应列**（`claimed_at`/`approved_by`/`webhook_url`/`node_id` …） | 任务/触发器/实例/日志四张表均缺 |
| C6 | **用户 ID 类型**：`userId()` 返回 `?int`，控制器一律 `?? 'system'`，而 `created_by` 是 FK→`users.id int unsigned` | `Request.php:177` |
| C7 | **事务边界消失**：内存写入瞬时原子；落库后「建实例+建任务+写日志」必须同事务 | 引擎有 `executeInTransaction()` 但零调用 |
| C8 | **租户上下文传不进仓储**：仓储无 request；引擎有 `TenantContext` 但无人接线 | `BaseModel.php:72-92` |
| C9 | **定义体三种格式互不通**：DB `nodes_config`=点表、`bpmn_xml`=真 BPMN、引擎要 `{nodes,edges}` JSON | `ModelParser.php:44-70` |
| C10 | **版本语义未定**：唯一键 `(tenant_id,name,version)` 暗示快照，但 `update()` 就地改、不 bump | `DefinitionController.php:286-309` |

### 4.3 分阶段方案

**阶段 0：契约冻结**（0.5 天，无业务代码）

- 产出 `docs/report/workflow-persistence-contract.md`：5 张表的「API 字段 ↔ DB 列」映射，逐列裁决 C4/C5（新增列 / 塞 json / 从响应移除）
- 加一个**只加列不改列**的迁移：`workflow_instances.definition_version`、`workflow_instances.trigger_type`、`workflow_event_logs.node_id`
- 验收：`check-migrations --strict` 通过

**阶段 1：最小可用落库**（3–5 天，只做 definition + instance）

- ID 策略：**沿用自增 `int unsigned`**，对外序列化为整数（依据：表/外键/seeder 已全部对齐，前端零调用——`grep "workflow/(instances|definitions|tasks|triggers)" frontend/` 无命中）
- 先接 6 个端点，其余保持现状到阶段 2
- 验收（可执行）：`POST /instances` 后 `SELECT COUNT(*)` 增 1；`docker restart alkaid-backend` 后 `GET` 仍返回该条；响应 `id` 是 JSON number 且等于 DB 主键；少传 `title` 不 500；跨租户仍 403/404

**阶段 2：引擎与 API 的边界**（5–10 天，含一个必须先拍板的前置）

- 原则：**状态机归引擎、持久化归主应用**
- 新增 4 个仓储接口（`int $id`），`ServiceFactory` 从类名绑定改为接口绑定
- 必做：新增 `config/workflow.php` 并**验证被真正加载**（当前路径有 bug，会静默用空配置）
- 前置：引擎执行内核需要 `madong/helper`（见决策 D2）；若不引入，阶段 2 降级为「只复用 Definition 子集，永久冻结执行内核」

**阶段 3：事件日志与审计**（2–3 天）

- 写入点：实例 4 类事件、任务 7 类、触发器 4 类
- **必须与业务写入同事务**
- 验收含「故意让第二步抛异常 → 实例行与日志行都不存在」的原子性测试

### 4.4 四个必须拍板的决策

| # | 决策 | 推荐 | 代价 |
|---|---|---|---|
| **D1** | 实例/定义 ID | **A. 纯自增 `int unsigned`** | 对外暴露自增 ID（可用 `business_key` 缓解） |
| **D2** | 引擎库去留 | **A. 降级为「Definition 子集类库」**，冻结执行内核并标废弃 | 拿不到真实节点流转；但零 madong 依赖、立刻可开工 |
| **D3** | 定义版本语义 | 取决于是否需要历史版本追溯 | B（发布即新增版本行）会让 `GET` 去重、`delete` 语义变复杂 |
| **D4** | API 契约可改性 | **A. 现在就对齐 DB 形状** | 破坏性变更；但前端零调用，`docs/` 接口契约需同步 |

---

## 五、生产级就绪度评分

| 维度 | 评分 | 依据 |
|---|---|---|
| 功能完整性 | **40%** | 低代码后端可用；工作流 0% 落库；应用/插件生态未通电；市场 0% |
| 架构一致性 | **55%** | 分层大体成立，但 `domain/` 有 4 处反向依赖 + 3 个控制器直接写 SQL |
| 测试可信度 | **20%** | CI 不跑测试；本地测试写开发库；基线不可复现 |
| 安全基线 | **35%** | C-1 注入未修；25 条依赖公告；密钥校验非启动期 |
| 可观测性 | **30%** | 有 trace_id；无指标/告警/APM |
| 交付工程化 | **30%** | 门禁全绿但不覆盖事实；风格检查恒红阻断流水线；required checks 代码侧无强制 |
| 文档可信度 | **45%** | 三份 2026-10-30 报告质量高；但 73 份设计文档仅 14 份有实现状态声明，27 份报告有 5 份未登记 |

**综合：约 36%，未达「可支撑团队并行开发」的门槛。**

---

## 六、阻塞重启开发的 6 项（按依赖顺序）

| 序 | 事项 | 验收标准 |
|---|---|---|
| **B1** | **测试库隔离**：`phpunit.xml` 的 `DB_NAME` 改为 `alkaid_sys_test`；启动断言库名含 `_test`；清理开发库的 330 张 `lc_*` 与 207 个多余租户 | 连跑 3 次全量，失败集完全相同 |
| **B2** | **修测试隔离残留**：Mock 泄漏（3 处 trait 接线）、`Request` 实例复用、每类重建 App | `Errors` 归零或稳定为「显式基线」 |
| **B3** | **一次性 PSR-12 格式化 + `route:doc` 重生成**（同一 PR）+ 锁 fixer 版本 | `php-cs-fixer` 与 `route:verify` 同时转绿 |
| **B4** | **拆 CI 依赖链**：`phpunit` 不再 `needs: php-cs-fixer`；去掉 `continue-on-error`；补 Feature 套件 | PR 上能看到测试结果 |
| **B5** | **依赖治理**：`composer remove predis`（若无用）+ `composer update --lock` + 处理 2 条 High CVE + 修空 PSR-0 | `composer validate` 无 lock 错误；`composer audit` 无 High |
| **B6** | **让门禁真的拦**：仓库设置里配置 5 个 required checks；处理 5 处 `if: github.repository` 守卫 | 开一个只改 `database/` 的 PR，验证会被拦 |

**B1–B6 未落地前，任何「回归通过」都不可信。**

### 可并行（不阻塞开工）

| 序 | 事项 | 成本 |
|---|---|---|
| R1 | 文档索引自动化 + 5 份报告登记 + 取代关系标注 | M |
| R2 | `design/` 状态声明补齐（62 份），或改为「新增即强制」 | L（分批） |
| R3 | 4 个孤儿门禁脚本接入 CI 或删除 | S |
| R4 | `CODEOWNERS` 补 `database/migrations/`、`composer.*` | XS |
| R5 | `plugins/workflow-engine/vendor` 出库 | M |
| R6 | 前端 `pnpm audit` | S |
| R7 | C-1 标识符白名单化 | M |
| R8 | R50 断言补「常量 ⊆ 定义集」纯代码校验 | XS |

---

## 七、本次被推翻或修正的既有判断

诚实记录——这些是我此前给出、本次核查证明有误的结论：

| 我的原判断 | 实际情况 | 影响 |
|---|---|---|
| R52「未捕获 `\Error` 退出码为 0，CLI 静默成功」 | **实测 255**，非 0 | 严重性下调；但发现 `AppService` 注释自相矛盾 |
| R25「语言探测在构造期，Swoole 下切换失效」 | **每请求探测一次，切换正常** | 该遗留项不成立 |
| R7「每请求 `scandir`」 | **每 worker 一次**（Swoole） | 影响≈0 |
| R9「文档要 edges 列、实现没建」 | **真问题是定义体三种格式互不通、连线在 API 层被丢弃** | 升级为 P0 |
| C-1「注入存在但被 guard 拦住」 | **`hasTable/hasColumn` 绕过 guard，读路径生产可用** | 严重性上调 |
| 「41 项失败全是测试污染」 | **至少 1 项是真实测试缺陷**（断言与实现相反） | 基线口径修正 |

---

## 八、⚠️ 证据不足 / 未验证

1. **required status checks 是否已在 GitHub 配置**——代码侧零信息，`branch-protection.yml` 不调用任何 GitHub API
2. **fork PR 下 `skipped` job 是否被 branch protection 视为通过**——需实测
3. **`LowcodeCliIntegrationTest` 19 个 Error 的具体异常文本**——受「不跑 phpunit」约束未取堆栈；机制链成立但非 in-test 证据
4. **引入 `madong/helper` 后 88 个文件的真实可用度**——未评估；嵌套 vendor 版本与根 `topthink` 是否冲突未验证
5. **`guzzle` / `phpseclib` 是否在运行时代码路径被调用**——未逐个查调用点
6. **前端依赖漏洞**——未跑 `pnpm audit`
7. **全仓 Graphify 索引为何超时**——未定位（疑与 4,270 个插件 vendor 文件或某些超大文件有关）

---

## 九、建议的下一步

1. **先做 B1–B6**（预计 3–5 个工作日），这不产生任何业务价值，但决定后续一切判断是否可信
2. **再做 C-1 与 R50**（各半天），这两项是真实安全/数据一致性缺陷
3. **然后按第四节的阶段 0–1 启动工作流落库**，并在开工前拍板 D1–D4
4. **R9 定义的三种格式**需要在阶段 0 一次性定案，否则阶段 1 无法收口

---

## 附：本报告使用的关键命令（可复现）

```bash
# 图谱
uv tool install graphifyy
graphify update . --no-cluster
graphify god-nodes --top 20
graphify affected "ProcessDefineRepository"

# CI 依赖链
grep -nE "needs:|continue-on-error" .github/workflows/backend-php-cs-fixer.yml

# 测试库
grep -nE "DB_NAME|ALLOW_RUNTIME_DDL" phpunit.xml

# 依赖
docker exec alkaid-backend composer audit
docker exec alkaid-backend composer validate --no-check-publish

# 分层违规
grep -rln "use Infrastructure\\\\|use think\\\\facade" domain/
grep -rln "Db::" app/controller/
```
