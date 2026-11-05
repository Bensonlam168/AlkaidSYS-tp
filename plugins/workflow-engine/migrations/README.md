# 工作流引擎迁移（历史留档，不参与执行）

> ⛔ **本目录下的迁移文件不会被执行，也不要手动执行。**
> 工作流 5 张表（`workflow_definitions` / `workflow_instances` / `workflow_tasks` /
> `workflow_triggers` / `workflow_event_logs`）的**唯一权威定义在仓库根的 `database/migrations/`**。

## 1. 为什么权威定义不在本目录

`php think migrate:run` 只扫描 `database/migrations/`，**插件目录下的迁移从未被执行过**。
因此历史上本目录这 5 份文件虽然写得很完整，但工作流 5 张表在数据库里实际并不存在
——「迁移写了」与「表建出来了」是两件事。

为保证表真的能建出来，这 5 份迁移被搬进 `database/migrations/` 并做了修正（见 §3）。
本目录的文件**原样保留、不再维护**，仅作历史留档，用于追溯「当初设计成什么样、后来改成了什么样」。

权威来源对照：

| 本目录（历史留档） | 权威定义（实际执行） |
| --- | --- |
| `20251216000001_create_workflow_tables.php` | [`database/migrations/20251216000001_create_workflow_tables.php`](../../../database/migrations/20251216000001_create_workflow_tables.php) |
| `20251216000002_add_foreign_keys.php` | [`database/migrations/20251216000002_add_foreign_keys.php`](../../../database/migrations/20251216000002_add_foreign_keys.php) |
| `20251216000003_insert_initial_data.php` | [`database/migrations/20251216000003_insert_initial_data.php`](../../../database/migrations/20251216000003_insert_initial_data.php) |
| `20251216000004_create_partitioned_tables.php` | **无**（未纳入根目录，见 §4） |
| `20251216000005_optimize_indexes.php` | [`database/migrations/20251216000005_optimize_indexes.php`](../../../database/migrations/20251216000005_optimize_indexes.php) |

> 注意版本号 `20251216000004` 在 `database/migrations/` 里是**空缺**的：根目录没有对应的
> `20251216000004_*.php`。这不是改名遗留，而是这份分区表迁移从未被采纳（§4）。

## 2. 为什么不删掉这些文件

1. **`20251216000004`（分区表）只在本地目录存在**，根目录没有对应文件。删掉它等于删掉这段设计的
   唯一副本，将来要采纳分区方案时只能翻 git 历史。
2. **两份实现已经分叉**（行数、逻辑、缺陷修复都不一样）。保留旧版才能回答
   「我们当时为什么改它」——尤其 §3 里那些缺陷，是靠读这份旧实现才发现的。

**所以：本目录的文件只读，不删除、不修改逻辑。** 需要改动请改 `database/migrations/` 里的权威版本。

## 3. 根目录版本相对本目录做了哪些修正

本轮用 `diff -u` 逐份比对过（命令见 §6）。四份文件的差异都落在「能不能真正建出表」上，
不是风格调整。各文件的主要修正：

### 3.1 `20251216000001_create_workflow_tables.php`（197 行 → 266 行）

| 修正 | 原因 |
| --- | --- |
| `workflow_instances.definition_id`、`workflow_triggers.definition_id` 由 `string(50)` 改为 `integer unsigned` | 被引用的 `workflow_definitions.id` 是本文件 `setId('id')` 生成的自增 `int unsigned`。**列类型不一致会让外键创建报 MySQL 3780**（`20251216000002` 里那几条外键就是这么失败的） |
| `change()` 改为 `up()` + 显式 `down()` | `down()` 按依赖倒序 `drop` 这 5 张表（先子表、后定义表），避免残留外键导致 `DROP TABLE` 报 3730；任一张删不掉就抛异常，不制造「记录已回滚、表还留着」的假成功 |
| 新增 `CREATED_TABLES` 常量与 `notice()` 日志辅助 | 回滚清单集中一处，避免 `down()` 与 `up()` 建表清单不同步 |
| 文件头 `@package plugins\workflow-engine\migrations` 换成「来源 + 改造点」说明 | 让执行入口处自带来源交代，不必再回插件目录翻记录 |

> 用 `up()`/`down()` 而不是 `change()` 是有意的：Phinx 在 `change()` 存在时会优先用它，
> 通过 ProxyAdapter 反向重放来回滚，显式 `down()` 会被忽略。表创建这种操作反向推导不可靠。

### 3.2 `20251216000002_add_foreign_keys.php`（182 行 → 170 行）

外键**数量与覆盖范围两边一致（都是 10 条）**，差别在失败处理：

| 修正 | 原因 |
| --- | --- |
| 10 条外键整理成 `FOREIGN_KEYS` 常量清单，逐条 `try/catch (\Throwable)` | 旧实现是一串直线调用，**任何一条失败就中断整个迁移**（`definition_id` 那条因列类型不一致必然 3780），后面的外键全部建不上 |
| 外键显式命名 | 便于排查与回滚定位 |
| 补 `down()`：按名删除本迁移加的外键，失败仅记录 | 表被整体删除时 MySQL 会自动清理外键，故此处失败不视为错误 |
| `change()` 改为 `up()` + `down()` | 同 §3.1 |

### 3.3 `20251216000003_insert_initial_data.php`（307 行 → 580 行）

| 修正 | 原因 |
| --- | --- |
| 去掉 `'id' => 'workflow_reimbursement_001'` / `'trigger_...'` / `'event_...'` 这类**字符串主键** | 这三张表的主键都是 `int unsigned` 自增。给自增整型列塞字符串，在 `STRICT_TRANS_TABLES` 下直接报 1366 `Incorrect integer value`，迁移必挂。现在不写主键、由自增生成 |
| 触发器改为按 `(tenant_id, name, version)` 反查真实 `definition_id` | 定义主键不再由代码指定，触发器要用反查才能建立关联 |
| 插入前检查租户 `1` 是否存在，不存在则整体跳过并记录 | 全新库可能还没有租户数据；直接插会撞外键 / 插入孤儿数据 |
| 每类数据插入前做存在性判断 | 重复执行时不再命中唯一索引而中断迁移 |
| 补 `down()`：按 `name` / `event_name` 这类确定性标识删除示例数据 | 数据插入无法被框架「反向重放」成删除，只有显式 `down()` 才能回滚干净 |

### 3.4 `20251216000005_optimize_indexes.php`（274 行 → 165 行）

| 修正 | 原因 |
| --- | --- |
| 修掉致命拼写错误 | 旧版 `change()` 调用 `optimizeWorkflowEventLogsIndexes()`，而实际定义的方法名是 `optimizeWorkflowEventsLogsIndexes()`（多一个 `s`）→ `Call to undefined method`，**迁移跑到这一步直接致命错误** |
| 索引改为「清单 + 统一安全创建」：先按索引名判重，再 `try/catch (\Throwable)` | 重复建索引（MySQL 1061 `Duplicate key name`）与个别失败不再中断整个迁移 |
| 删除 `createPartialIndexes()` | 它用的是 `CREATE INDEX ... WHERE ...` 部分索引，**MySQL 8.0 不支持**，这段 SQL 必然失败，只会在迁移日志里制造噪音 |
| 补 `down()`：按索引名删除本迁移建的索引 | 同 §3.1 回滚要求 |

## 4. `20251216000004_create_partitioned_tables.php`（分区表）为什么没进根目录

**它建的不是那 5 张核心表**，而是 4 张**额外的** `_partitioned` 后缀表：

- `workflow_event_logs_partitioned`（按 `created_at` 月分区）
- `workflow_instance_history_partitioned`（按 `completed_at` 月分区）
- `workflow_task_history_partitioned`（按 `completed_at` 月分区）
- `workflow_instances_active_partitioned`（按 `status` LIST 分区）

也就是说它属于**大数量级下的性能优化选件**，不是工作流功能的前置条件——这与「插件迁移从未执行、
5 张表实际不存在」是两件互不牵连的事，`0004` 缺失并不会让任何工作流功能缺表。

它当前**不具备直接进 `database/migrations/` 的条件**，至少要先解决三点：

1. **失败是静默的**：每个 `CREATE TABLE` 都包在 `try/catch (\Exception)` 里，catch 里只
   `writeln` 一行日志、不抛出。表建不出来时迁移照样被记为 applied——这类「假成功」正是
   `docs/technical-specs` 里反复强调要避免的。进根目录前必须先改成失败即抛（或至少可判失败）。
2. **分区边界已经过期**：分区写死 `p202501`~`p202512`，上限 `TO_DAYS('2026-01-01')`，
   之后所有数据都落进 `pmax` 单分区，分区等于失效。要采纳必须改成可滚动维护的方案。
3. **列类型与权威定义已分叉**：`workflow_event_logs_partitioned.id` 是 `varchar(50)`、
   `workflow_instances_active_partitioned.definition_id` 也是 `varchar(50)`，
   而根目录对应表是 `int unsigned` 自增——正是 §3.1 修掉的那类不一致。

综上：**当前判定为「未采纳」而非「遗漏」**（应用代码里没有任何地方读写这 4 张表，
它们只出现在本目录迁移与插件文档中）。若产品上确实需要分区表，应按上面三点单独评估、
单独立项，**不要**直接把这文件复制进 `database/migrations/`。

## 5. 若将来确实要执行本目录的迁移

**不要**在插件目录里跑迁移，也不要把 `migrations` 指到本目录。正确顺序是：

1. 把要采纳的迁移**同步进 `database/migrations/`**（保留原时间戳前缀，或按当前时间重新编号，
   但**绝不能与已有版本号重复**）；
2. 按本文件的规范改造它：`up()` / `down()` 成对、失败必须抛出而不是吞掉、列类型与权威定义对齐、
   `tenant_id` 声明符合检查 D；
3. 跑 `php scripts/check-migrations.php --strict`（不带 `--no-db`）确认检查 A~F 全过；
4. 再 `php think migrate:run`；
5. 本目录的旧副本保持只读留档，**不要**跟着一起改——一改就又回到「两份实现、谁都不知道哪份生效」的分叉状态。

> 判断依据只有一条：`database/migrations/` 是唯一执行入口，本目录**永远不执行**。
> 只要两份内容都被人改过，就必然再次分叉。

## 6. 防再次分叉：自查命令

在仓库根执行，确认「权威版本已改动、留档版本未跟进」这类分叉能被一眼看见：

```bash
# 逐份对比留档与权威定义（0004 在根目录无对应文件，会出现 "No such file" 属预期）
diff -u plugins/workflow-engine/migrations/20251216000001_create_workflow_tables.php \
        database/migrations/20251216000001_create_workflow_tables.php
```

同时确认 `scripts/check-migrations.php` **不会**扫到本目录：
该脚本的 `MIGRATION_DIR` 常量固定为 `database/migrations`（`collectMigrationFiles()` 里
`glob($dir . '/*.php')` 也只扫这一层），因此本目录的留档**不会**被当成迁移参与检查 A~F。
若将来有人把扫描范围扩大到插件目录，必须先排除本目录，否则这些历史文件会持续产生噪音告警。

## 7. 状态

> ⛔ **冻结**：本目录 5 份迁移为历史留档，不参与迁移执行、不再维护。
> 工作流表的权威定义与后续所有迁移改动都在 `database/migrations/`。
