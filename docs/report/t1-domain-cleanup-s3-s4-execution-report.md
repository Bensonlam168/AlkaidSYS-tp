# T1-DOMAIN-CLEANUP S3/S4 执行报告

> 基于 `docs/report/t1-domain-cleanup-design-alignment-analysis.md` 已确认的设计对齐结论，本报告聚焦 S3/S4 实施结果与风险评估。

## 1. 执行摘要

- **S3（兼容层 + 新入口）**
  - 新增 Lowcode 专用 CLI：`app/command/TestLowcodeCollection.php`（命令：`test:lowcode-collection`），直接使用 `Domain\\Lowcode\\Collection\\Model\\Collection` + `Infrastructure\\Lowcode\\Collection\\Field\\FieldFactory` + `Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager` 完成集合 CRUD + 元数据校验。
  - Legacy CLI `test:collection` / `test:field` 的实现已迁移到 Lowcode 栈，仅保留命令名与入口，代码内打印 deprecation 提示。
  - `Infrastructure\\Field\\FieldTypeRegistry` 被收敛为兼容层，未知类型委托 `FieldFactory::create()` 处理，新代码直接使用 Lowcode FieldFactory。
- **S4（调用方迁移 + Legacy 下线）**
  - 字段相关单测 `tests/Unit/Field/FieldTypeSystemTest.php` 已全面改造为基于 Lowcode `FieldFactory` + `Domain\\Lowcode\\Collection\\Interfaces\\FieldInterface`。
  - Legacy 领域类 `Domain\\Model\\Collection` 与 `Infrastructure\\Collection\\CollectionManager` 已标记 `@deprecated`，HTTP/API 与新 CLI 均使用 Lowcode 对应实现。
  - 现有主链路（低代码 API、FormDesigner、字段/集合 CLI、字段单测）均统一在 Domain\\Lowcode 栈之上运行。

> 结论：在行为语义与设计对齐前提下，**Domain\\Lowcode 已成为 Collection/Field/Relationship 的唯一实现路径；Legacy 体系只作为兼容层与废弃入口存在**。

## 2. 行为一致性与设计对齐验证

- 与 `design/09-lowcode-framework/*` 对齐：
  - 所有新入口（API + CLI + 单测）均通过 `CollectionInterface`/`FieldInterface` + `CollectionManager`/`FieldFactory` 完成数据建模与 DDL 描述。
  - 字段承担三重职责：DDL 列定义（通过 `getDbType()/getDbColumn()`）、值校验（`validate()`）、元数据导出（`toArray()`），完全符合 42-lowcode-data-modeling 中对 Field 的定位。
- 与 `design/02-domain-layer/domain-model-unification-plan.md` 对齐：
  - Legacy `Domain\\Model\\Collection`/`Infrastructure\\Collection\\CollectionManager` 明确标记为第一代实现（Legacy），并在注释中指向 Lowcode 对应实现。
  - CLI 与单测层的统一路径已切换至 Lowcode，实现了“**Domain\\Lowcode 为单一真源**”的目标。

## 3. 变更清单（按模块）

### 3.1 CLI 命令

- **新增** `app/command/TestLowcodeCollection.php`
  - 新命令：`test:lowcode-collection`。
  - 场景：构造一个包含 `name(string)`、`age(integer)` 字段的 Collection，依次执行 create → get → update(新增 `email` 字段) → delete 演练，验证 Lowcode CollectionManager 全链路行为。
- **修改** `app/command/TestCollection.php`
  - 依赖从 Legacy 栈切换为：
    - `Domain\\Lowcode\\Collection\\Model\\Collection`
    - `Infrastructure\\Lowcode\\Collection\\Field\\FieldFactory`
    - `Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager`
  - 添加类级注释：`@deprecated`，并在 `execute()` 开头打印：
    - `[DEPRECATED] test:collection is deprecated, use test:lowcode-collection instead.`
- **修改** `app/command/TestField.php`
  - 依赖从 `Infrastructure\\Field\\FieldTypeRegistry` + `Domain\\Field\\*` 迁移为 `Infrastructure\\Lowcode\\Collection\\Field\\FieldFactory`。
  - 行为：
    - 使用 `FieldFactory::getTypes()` 列出默认字段类型；
    - 使用 `FieldFactory::create('string', 'username', ...)` 创建字段并验证 `validate()`/`toArray()`；
    - 使用 `FieldFactory::create('integer', 'age', ...)` 验证整数字段可空与校验逻辑。
  - 添加类级注释与运行时 deprecation 提示，引导使用 `test:field-types` 或直接使用 Lowcode 字段 API。
- **修改** `config/console.php`
  - 注册新命令：`'test:lowcode-collection' => \\app\\command\\TestLowcodeCollection::class`。

### 3.2 字段兼容层与单测

- **修改** `infrastructure/Field/FieldTypeRegistry.php`
  - 引入 Lowcode FieldFactory，并标记整类为：
    - `@deprecated ... New code should use Infrastructure\\Lowcode\\Collection\\Field\\FieldFactory directly.`
  - `create()` 行为调整：
    - 若 `self::$types[$type]` 未注册，则直接委托 `FieldFactory::create($type, $name, $options)`；
    - 确保新字段类型仅在 Lowcode 栈注册一次。
  - 保留 `registerDefaults()` 对 Legacy 字段类型的注册，用于兼容历史代码。
- **修改** `tests/Unit/Field/FieldTypeSystemTest.php`
  - 所有用例改为以 Lowcode FieldFactory 为入口，断言：
    - 默认字段类型集合至少包含 `string/integer/boolean/date`；
    - `FieldFactory::create()` 返回对象实现 Lowcode `FieldInterface`；
    - 字段 `validate()`/`toArray()`/未知类型异常行为符合预期。

### 3.3 Legacy Domain/Infra 标记为废弃

- **修改** `domain/Model/Collection.php`
  - 注释更新为 “Collection Class (Legacy)”；
  - 添加：
    - `@deprecated since T1-DOMAIN-CLEANUP S3/S4, use Domain\\Lowcode\\Collection\\Model\\Collection and ...Interfaces\\CollectionInterface instead.`
- **修改** `infrastructure/Collection/CollectionManager.php`
  - 注释更新为 “Collection Manager (Legacy)”；
  - 添加：
    - `@deprecated since T1-DOMAIN-CLEANUP S3/S4, use Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager instead.`

## 4. 测试计划与执行情况

### 4.1 单元测试

- 目标：验证 Lowcode 字段体系的默认类型、创建与验证语义。
- 相关用例：`tests/Unit/Field/FieldTypeSystemTest.php`。
- 实际执行：在 Docker 容器 `alkaid-backend`（PHP 8.2.29）中运行：
  - 命令：`docker exec alkaid-backend php vendor/bin/phpunit tests/Unit/Field/FieldTypeSystemTest.php`
  - 结果：
    - 测试数：9；断言数：40；全部通过（OK），仅包含 1 条 PHPUnit Deprecation 提示。
- 备注：本地工具环境仍为 PHP 7.4.33，不适合直接运行 phpunit，应以 Docker/CI 中的 PHP 8.2+ 结果为准。

### 4.2 CLI 集成验证（dev/test 环境）

> 说明：相关命令会触发表结构创建/删除等 DDL 操作，受 T1-DDL-GUARD 约束，仅在 dev/test 环境并显式允许 Runtime DDL 时执行。

- 环境配置：通过 Docker 命令行注入环境变量运行 CLI 测试：
  - `APP_ENV=dev`
  - `ALLOW_RUNTIME_DDL=true`
- 实际执行命令与结果：
  - `docker exec -e APP_ENV=dev -e ALLOW_RUNTIME_DDL=true alkaid-backend php think test:lowcode-collection`
    - 结果：完成 Collection 的 create → get → update（新增 email 字段）→ delete 元数据 全链路，最终输出：
      - `All Lowcode CollectionManager tests passed! | 所有 Lowcode CollectionManager 测试通过！`
  - `docker exec -e APP_ENV=dev -e ALLOW_RUNTIME_DDL=true alkaid-backend php think test:collection`
    - 结果：先输出废弃提示：
      - `[DEPRECATED] test:collection is deprecated, use test:lowcode-collection instead. | [已废弃]请使用 test:lowcode-collection 替代此命令。`
    - 随后完成与 `test:lowcode-collection` 等价的 create → get → update → delete 全链路，最终输出：
      - `All CollectionManager tests passed! | 所有集合管理器测试通过！`
  - `docker exec alkaid-backend php think test:field`
    - 结果：字段相关 CLI 测试全部通过，最终输出 `All tests passed! | 所有测试通过！`
- 期望使用方式：
  - 在本地 dev/test 环境中，如需手动验证 Lowcode Collection/Field 行为，应在容器或运行环境中设置 `APP_ENV=dev` 且 `ALLOW_RUNTIME_DDL=true`，以允许 Runtime DDL；
  - 在 stage/prod 环境中不应开启上述开关，避免误触发结构变更；低代码建模应通过受控 DDL 流程完成。

## 5. 风险与后续建议

1. **PHP 版本与测试体系不一致**
   - 风险：当前工具环境的 PHP 版本过低，无法跑 phpunit；若线上 CI 也使用 7.4，将导致 Lowcode 相关回归测试长期缺位。
   - 建议：尽快在 CI 中升级到 PHP 8.2+ 并将 Lowcode 相关单测纳入默认测试矩阵。

2. **DDL 操作与多租户规范**
   - Lowcode CollectionManager 的 DDL 行为已在设计层与实现层统一，但 CLI 命令仍可能在本地频繁创建/删除表。
   - 建议：
     - 明确文档：相关 CLI **仅在 dev/test 环境** 使用；
     - 在未来可考虑为 CLI 增加环境检查/确认提示，以降低误用风险。

3. **兼容层类型签名差异（FieldTypeRegistry）**
   - 兼容层 `FieldTypeRegistry::create()` 在未知类型时会返回 Lowcode FieldInterface 实现，与 Legacy `Domain\\Field\\FieldInterface` 在类型签名上存在差异，但方法集合基本兼容。
   - 当前所有新代码和单测均已改用 `FieldFactory`，剩余使用方仅为 Legacy/过渡逻辑，风险可控。
   - 建议：后续版本可进一步搜索并消除对 `FieldTypeRegistry` 的依赖，最终删除该类。

4. **Legacy 类的最终下线节奏**
   - 目前仅通过 `@deprecated` + CLI 提示弱化 Legacy 使用，未物理删除。
   - 建议：
     - 在下一个 **major 版本** 中：
       - 从 `config/console.php` 中移除 `test:collection`/`test:field` 命令；
       - 物理删除 `Domain\\Model\\Collection` 与 `Infrastructure\\Collection\\CollectionManager` 及相关 Legacy Field 类；
       - 在 CHANGELOG 中明确记录为 breaking change。

## 6. 验收结论

- **是否满足“Domain\\Lowcode 成为唯一实现路径”？**
  - 是。从 HTTP API、FormDesigner 到 CLI 与单测，所有新/主路径均通过 Domain\\Lowcode + Infrastructure\\Lowcode 实现，Legacy 仅作为兼容层存在。
- **Legacy 体系是否已下线或标记废弃？**
  - 是。关键 Legacy 类与 CLI 均已添加 `@deprecated` 注释和运行时 warning，并由 Lowcode 实现提供真实能力。
- **是否存在第三套抽象？**
  - 否。本轮只引入了极薄的兼容层（shim），其职责是将 Legacy 调用重定向到 Lowcode，而非新的抽象栈。

> 综合判断：在当前阶段，可以将 T1-DOMAIN-CLEANUP 的 S3/S4 判定为“**已完成（完成度取决于后续在 CI/环境中的测试执行情况）**”。如需，我可以在此基础上进一步更新 `docs/todo/refactoring-plan.md` 与 `design/02-domain-layer/domain-model-unification-plan.md` 中 S3/S4 的完成状态与后续计划说明。

## 7. 任务完结声明

### 7.1 阶段最终状态

- T1-DOMAIN-CLEANUP 的 S3（兼容层与新入口落地）和 S4（调用方迁移与 Legacy 下线规划）已按设计方案全部完成：
  - 代码层面：
    - 领域与基础设施统一以 `Domain\\Lowcode\\Collection\\*` + `Infrastructure\\Lowcode\\Collection\\*` 为单一真源；
    - Legacy 领域模型与管理器类已集中标记为 `@deprecated`，仅作为兼容层存在；
    - CLI 与单测已全部切换到 Lowcode 体系，Legacy CLI 仅保留命令名与废弃提示。
  - 测试层面：
    - 在 Docker 容器 `alkaid-backend`（PHP 8.2.29）中，`tests/Unit/Field/FieldTypeSystemTest.php` 全部通过（9 测试 / 40 断言）；
    - 在 dev 环境（`APP_ENV=dev` + `ALLOW_RUNTIME_DDL=true`）下，`test:lowcode-collection` / `test:collection` / `test:field` CLI 测试全部通过。
  - 文档层面：
    - `docs/todo/refactoring-plan.md` 中 T1-DOMAIN-CLEANUP 的 S1–S4 与验收标准全部标记为完成；
    - `design/02-domain-layer/domain-model-unification-plan.md` 中阶段 B/C 标记为完成，明确 Legacy 为兼容层并规划后续删除；
    - 本执行报告补充了 Docker 测试结果、环境变量配置与风险建议。

综上，**可以将 T1-DOMAIN-CLEANUP 的 S3/S4 阶段视为“技术上完结”**。

### 7.2 关键成果清单

- 架构与代码收敛：
  - Lowcode 成为 Collection/Field/Relationship 的唯一实现路径；
  - Legacy 体系被收缩为薄兼容层（shim），不再承载新功能。
- 测试体系落地：
  - 有针对 Lowcode 字段体系的单元测试（FieldTypeSystemTest）；
  - 有基于 Lowcode CollectionManager 的 CLI 集成测试（含 Legacy 兼容入口）。
- 文档与设计统一：
  - 调研、统一方案、执行报告与总体整改计划四类文档对当前实现状态达成一致；
  - T1-DOMAIN-CLEANUP 在整体重构路线图中可标记为已交付里程碑。

### 7.3 后续行动建议（摘要）

- CI 集成：
  - 在 CI 中增加基于 PHP 8.2+ 的 Job，运行 `tests/Unit/Field/FieldTypeSystemTest.php`，必要时在受控 dev/test 数据库上跑一轮 CLI smoke test；
  - 在 CI 中加入简单 grep 规则，禁止新引入 `Domain\\Model\\Collection` / `Domain\\Field\\*` / `Infrastructure\\Collection\\CollectionManager` / `Infrastructure\\Field\\FieldTypeRegistry` 等 Legacy 依赖。
- Legacy 删除时间表：
  - 在下一个 major 版本窗口中：
    - 从 `config/console.php` 中移除 `test:collection` / `test:field`；
    - 物理删除 Legacy 领域类与管理器及其不再使用的字段实现；
    - 在 CHANGELOG 中明确记录为 breaking change（可引用本执行报告作为技术背景）。
- 日常使用与团队协作：
  - 新功能开发与问题排查统一以 Domain\\Lowcode 体系为参照；
  - Legacy 代码仅在阅读旧实现或历史行为时作为参考，不再用于新开发。
