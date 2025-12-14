# T1-DOMAIN-CLEANUP 统一方案与低代码设计意图对齐性分析

> 范围：基于 `design/09-lowcode-framework/*`、`design/02-domain-layer/*`、`design/03-data-layer/*`、`design/08-developer-guides/*` 等文档，对当前“以 `Domain\\Lowcode\\Collection\\*` 为单一真源，下线 Legacy 体系”的统一方案进行设计层面的校验与修正建议。

## 1. 执行摘要

### 1.1 核心发现

1. **低代码框架在 AlkaidSYS 中的战略定位**：
   - 文档明确将低代码能力定位为 **开发者工具**（developer tool），而非面向业务人员的独立低代码平台；
   - 低代码能力被安放在“核心框架层 → 低代码基础层 → 低代码插件层 → 低代码应用层”的四层架构中，其中 Collection / Field / Relationship 抽象是“低代码基础层”的核心能力之一，而不是某个业务子系统的局部实现。

2. **当前 Domain\\Lowcode 体系与设计文档的契合度**：
   - `Domain\\Lowcode\\Collection\\Interfaces/*` + `Domain\\Lowcode\\Collection\\Model/*` + `Infrastructure\\Lowcode\\Collection/*` 的实际实现，与 `design/09-lowcode-framework/40/42` 中给出的接口/模型/Manager/Repository/SchemaBuilder 草图高度一致；
   - 多租户字段与数据表结构（`lowcode_collections` / `lowcode_fields` / `lowcode_relationships`）与 `03-data-layer` 中的多租户与迁移规范对齐，且实现了“元数据为真源、运行库为被控对象”的设计思路。

3. **Legacy 体系的位置与历史角色**：
   - 设计文档（特别是 40/41/42）中已经 **不再出现 `Domain\\Model\\Collection` 及其相关抽象**，所有 Collection/Field/Relationship 示例均指向 lowcode 命名空间；
   - Legacy 体系更像是第一代按 40/42 示例在主工程中做的 POC/简化实现，后续随着第二代 Domain\\Lowcode 的引入，文档已经随之演进，Legacy 仅保留在代码与部分 CLI/测试中，属于**技术债而非正式架构元素**。

4. **统一到 Domain\\Lowcode 的方案与设计理念的一致性**：
   - 以 Domain\\Lowcode 作为“单一真源”与现有设计文档中的分层架构、接口优先、领域与 ORM 解耦等原则完全一致；
   - 统一方案不会削弱文档中对开发者的承诺（CLI/API/UI 三入口、Schema-first 数据建模、多租户规范等），反而是让实现重新回到既定路线图上。

### 1.2 最终建议

- **确认并坚持**：以 `Domain\\Lowcode\\Collection\\*` 作为 Collection / Field / Relationship 的唯一领域真源是正确且必要的决策，无需引入“第三套通用 Collection 抽象层”作为本阶段的目标；
- **Legacy 定位**：将 `Domain\\Model\\Collection` / `Domain\\Field\\*` / `Infrastructure\\Collection\\CollectionManager` / `Infrastructure\\Field\\FieldTypeRegistry` 正式归类为“第一代 POC/历史实现”，通过兼容层 + CLI/测试迁移，在 T1-DOMAIN-CLEANUP 的后续阶段下线；
- **扩展弹性**：如果未来确实需要“非低代码场景的通用 Collection 抽象”，应当在 Domain\\Lowcode 之上增加轻量 Adapter/Facade，而不是重新创建一套平行领域模型。

---

## 2. 设计理念解读：低代码框架定位与 Collection 抽象初衷

### 2.1 战略定位：开发者工具，而非独立平台

来自 `design/09-lowcode-framework/41-lowcode-overview.md`：

- 核心定位：
  - 低代码能力是 **AlkaidSYS 框架的一部分**，目标是“帮助使用 AlkaidSYS 的应用/插件开发者减少重复劳动，提升开发效率”；
  - 明确对比传统低代码平台：AlkaidSYS 不追求“零代码业务人员平台”，而是偏向“为熟悉 PHP/Vue 的工程师提供强力加速器”。
- 使用方式：
  - 文档反复强调 **CLI + 界面 + API** 三条等价入口：
    - CLI：`alkaid lowcode:create-model` / `alkaid lowcode:create-form` / `alkaid lowcode:generate`；
    - 可视化：低代码管理应用 `lowcode-management-app`；
    - API：直接通过 `CollectionManager` / FormDesigner 等 Service 使用。

=> 这意味着：**数据建模相关的领域抽象（Collection/Field/Relationship）应当是一个“被框架内外多方复用的基础服务”，而不是任何单一业务模块的私有模型。**

### 2.2 Collection / Field / Relationship 抽象的设计初衷

集中体现在 `design/09-lowcode-framework/42-lowcode-data-modeling.md`：

- Collection：
  - 明确定义在 `alkaid\\lowcode\\datamodeling\\contract\\CollectionInterface` 与 `model\\Collection` 中：
    - 负责“逻辑表名 + 字段集合 + 关系集合 + 选项（options）”；
    - **不继承具体 ORM Model**，而是作为纯领域对象；
    - 提供 `toArray()`，用于持久化到元数据表或输出给前端/其他服务。
- Field：
  - `FieldInterface + AbstractField + 具体 FieldType` 同时承担三种职责：
    1. 业务层字段类型（string/integer/decimal/...）；
    2. 数据库列类型（`getDbType()`）；
    3. 运行时值校验（`validate($value)`）。
- Relationship：
  - 关系建模章节中给出 `addRelationship(name, config)` 的形式，目标是 **以 Collection 之间的关系元数据驱动 ORM 关系以及上层工作流节点行为**。

整体来看：

- 这些抽象本质上对应的是“**低代码基础层**”中的统一数据建模模型，而不是简单的业务 ActiveRecord；
- 设计上刻意 **将领域对象与 ORM 操作（Query/Model）分离**，以便在 dev/test 与 stage/prod 环境中严格遵守“Schema 真源 + Migration 管道”的约束。

### 2.3 为何文档中没有出现 Domain\\Model\\Collection？

- 40-architecture/42-data-modeling 中，所有 namespace 都以 `alkaid\\lowcode\\...` 起头，表明这是一套**低代码插件与基础层的设计草图**；
- 当前代码中的 `Domain\\Lowcode\\Collection\\*` 正是这套 design namespace 的 ThinkPHP 实际落地：
  - contract → `Domain\\Lowcode\\Collection\\Interfaces`；
  - model → `Domain\\Lowcode\\Collection\\Model`；
  - service/repository/field → `Infrastructure\\Lowcode\\Collection\\*`；
- Legacy 的 `Domain\\Model\\Collection` / `Domain\\Field\\*` 在 design/09 文档里完全没有命名对应项，只在早期 phase-1 设计评审报告中被提及为“Collection 抽象层 40% 完成”。

=> 结合时间线可以推断：**Domain\\Model 版本是第一代尝试，而 Domain\\Lowcode 版本是将 design/09 理念真正框架化、插件化之后的第二代。文档已经随第二代引入而更新，不再为第一代保留正式入口。**

---

## 3. 架构一致性分析：两套体系与设计文档的对齐情况

### 3.1 Domain\\Lowcode 体系与设计文档的一致性

对照 `design/09-lowcode-framework/40/42`：

- 分层与职责：
  - 文档中：CollectionManager / FieldManager / RelationshipManager 在“服务层”，FieldTypeRegistry / SchemaBuilder 在“基础层”；
  - 代码中：
    - `Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager/RelationshipManager` 对应服务层；
    - `Infrastructure\\Lowcode\\Collection\\Field\\*` + `FieldFactory` + `Infrastructure\\Schema\\SchemaBuilder` 对应基础层；
    - `domain/Lowcode/Collection/Model/*` 与 `Interfaces/*` 对应领域层；
  - 这与 40.md 中给出的 Mermaid 分层图基本 1:1 对应。
- 真源与漂移控制：
  - 文档里明确规定 `lowcode_collections.schema`（及相关多表）为 Schema 真源，通过 MigrationManager/lowcode:migration:diff 完成差异检测与脚本生成；
  - 代码里：
    - `lowcode_collections` / `lowcode_fields` 等表结构按照 42.md 中的多租户模型设计；
    - SchemaBuilder 实现运行时 DDL，但被 `T1-DDL-GUARD` 约束在 dev/test 环境使用，stage/prod 走标准迁移管道，与 03-data-layer 的规范一致。
- 接口优先与解耦：
  - `Domain\\Lowcode\\Collection\\Interfaces\\CollectionInterface/FieldInterface` 是显式契约，Infrastructure 与 App 侧仅依赖接口；
  - domain 层对象不继承 `think\\Model`，仅承担元数据与领域行为；
  - ORM 映射/Query/Repository 层在 Infrastructure 实现，契合“领域模型与 ORM 解耦”的原则。

=> 结论：**Domain\\Lowcode 体系在分层、接口、真源控制、多租户规范等方面，与设计文档高度同构，可视为设计稿的“规范实现”。**

### 3.2 Legacy 体系与设计文档的偏差

Legacy 主要包含：

- `Domain\\Model\\Collection`（继承 Think ORM Model，带 fields/relationships 数组配置）；
- `Domain\\Field\\*`（只关注验证，不包含 dbType/DDL 语义）；
- `Infrastructure\\Collection\\CollectionManager`（使用上面两个完成动态建表与元数据落库）；
- `Infrastructure\\Field\\FieldTypeRegistry`（注册与创建 Domain\\Field 实例）。

与设计文档对比：

- 分层混合：
  - Collection 直接继承 ORM Model，将“领域对象 + ORM 行为 + 元数据描述”混在一起，与 40/42 的“领域对象与 ORM 解耦”原则不符；
- Field 职责不完整：
  - 文档中的 AbstractField 同时负责 type/dbType/validate；
  - Legacy `Domain\\Field` 系列只有 type + validate，dbType 映射则在 SchemaBuilder/CollectionManager 里通过硬编码推导，削弱了 Field 自描述能力；
- 命名与分层不对齐：
  - 命名空间挂在 `Domain\\Model`，缺少 design/09 中对“低代码基础层/插件层”的清晰分割；
  - 不利于未来引入基于插件的低代码分发/安装模型。

结合当前代码使用面，再看设计文档几乎不再提及这套抽象，**可以把 Legacy 定位为“与最新设计不完全一致的早期实现，需要在 T1-DOMAIN-CLEANUP 中收敛”。**

---

## 4. 决策验证：统一方案是否与设计意图一致？

### 4.1 决策 1：“以 `Domain\\Lowcode\\Collection\\*` 为单一真源”

**结论：合理且与设计文档完全对齐。**

- 从架构视角：
  - design/09 将 Collection / Field / Relationship 明确安放在“低代码基础层 + 插件层”的核心位置，并给出接口/模型/Manager 的完整草图；
  - 当前 Domain\\Lowcode 实现与这些草图高度一致，是这套设计的自然延伸；
- 从接口与解耦视角：
  - 设计文档强调“插件化核心引擎 + CLI/API/UI 多入口”，要求 Collection 抽象可以被不同应用复用；
  - Domain\\Lowcode 通过接口隔离 + Repository/Service 分层实现了这种可复用性；
- 从未来扩展视角：
  - 如果需要“更通用的 Collection 抽象”（不仅服务低代码，还服务其他领域模块），可以在 Domain 顶层定义更高一层接口，默认实现委托给 Domain\\Lowcode，而不需要再造一棵树；
  - 当前将 Domain\\Lowcode 设为唯一真源，为未来的 Adapter/Façade 保留了空间，而不是堵死。

### 4.2 决策 2：Legacy 体系的定位与下线路径

**结论：把 Legacy 定义为“第一代实现/技术债”并分阶段下线，符合设计演进预期。**

- 文档演进已经站在第二代实现上：
  - design/09 中所有类图/示例/命名空间都围绕 lowcode 展开；
  - phase-1 设计评审报告中把早期 Collection 抽象层标为 40% 完成，客观反映了“未完全与最新设计对齐”的状态；
- 当前统一方案对 Legacy 的处理方式：
  - 不在 Domain 层再为 Legacy 引入新的契约；
  - 通过 S3/S4 子任务：
    - 为还在用 Legacy 的 CLI/测试提供基于 Domain\\Lowcode 的兼容层或替代命令；
    - 在确认无新依赖后，将 Legacy 标记为 deprecated 并规划删除版本；
  - 该策略 **不会破坏** 任何已在设计文档中承诺的对外行为（因为文档压根没有向外承诺 Legacy 那一套）。

### 4.3 决策 3：迁移策略 S3/S4 是否引入新的理念冲突

**结论：在当前规划下，不会。关键在于执行过程中严守三条红线：**

1. 不在 Domain 层创造新的第三套 Collection 抽象；
2. 新增 Facade/CLI 仅是 Domain\\Lowcode 接口的“包装与编排”，而不是替代真源；
3. 不破坏以下硬约束：
   - Schema-first 数据建模（低代码元数据是真源）；
   - 多租户字段与索引规范（03-data-layer）；
   - dev/test 环境运行时 DDL，stage/prod 走迁移管道（T1-DDL-GUARD 所确立的边界）。

在此前提下：

- S3 阶段的兼容层（如基于 `FieldFactory` 替代 `FieldTypeRegistry`）只是让旧调用方迁移到新路径，不改变核心架构；
- S4 阶段的 Legacy 删除，只要有足够测试与回滚方案，就只是技术债清理，不会与设计理念冲突。

---

## 5. 风险与建议

### 5.1 若维持当前统一方案，需要注意的设计约束

1. **文档与代码的一致性要持续维护**：
   - 每次对 Domain\\Lowcode 或 Infrastructure\\Lowcode 的调整，需同步校验 design/09-* 与 design/02-domain-layer 是否仍然成立；
   - `docs/todo/t1-domain-cleanup-investigation.md` 与 `design/02-domain-layer/domain-model-unification-plan.md` 需作为后续变更的评审输入，而不是一次性文档。

2. **避免把 ORM 逻辑重新渗入领域对象**：
   - 后续开发中，不应让 Domain\\Lowcode 的模型去继承 `think\\Model` 或携带 Query 行为；
   - 所有与 SQL/Query/缓存直接交互的逻辑，继续放在 Infrastructure（Repository/Service）层。

3. **多入口体验要统一在 Lowcode 体系上**：
   - CLI 命令（lowcode:create-model/create-form/generate）、HTTP API（/api/lowcode/*）、UI（FormDesigner/WorkflowDesigner）都应围绕 Domain\\Lowcode 能力构建；
   - 不再新增面向外部用户/开发者的 Legacy 命令或 API。

4. **保留未来扩展抽象层的“安全接口面”**：
   - 如果未来需要 `Domain\\Modeling\\CollectionInterface` 这类“通用抽象”，应：
     - 以接口方式定义在 Domain 顶层；
     - 默认实现通过组合/委托调用 Domain\\Lowcode；
     - 严禁再引入一套独立的数据建模栈。

### 5.2 如调整方案，可能的调整点与影响面

当前不建议在 T1 阶段引入第三套抽象，但可以考虑在文档层做两点增强，为未来演进留钩子：

1. **在 design/02-domain-layer 中增加“一般化数据建模抽象”小节**：
   - 用文字说明：
     - 目前框架将低代码数据建模视为“通用 Collection 抽象”的首要落地；
     - 未来如有更通用的建模需求，可在 Domain 顶层引入更宽泛的接口，其实现默认基于 Domain\\Lowcode；
   - 这样可以在设计上预先消解“会不会以后需要第三套抽象”的顾虑，而不急于在实现层增加更多类型。

2. **在 design/09-lowcode-framework 中明确 Legacy 的历史地位**：
   - 在适当位置添加“演进历史”/“废弃实现说明”小节：
     - 简要说明早期曾在主工程中尝试过基于 Domain\\Model 的 Collection 抽象；
     - 当前推荐路线是 Domain\\Lowcode + Infrastructure\\Lowcode，Legacy 将在后续版本中移除；
   - 这有助于新加入的开发者快速理解为什么代码里曾经有两套 Collection 模型，而当前只推荐使用其中一套。

---

## 6. 附录：关键设计文档摘录

> 以下仅保留与本次决策直接相关的关键段落，完整内容请参考对应设计文档。

1. `design/09-lowcode-framework/41-lowcode-overview.md`：

> "AlkaidSYS 低代码能力定位为**开发者工具**，而非独立的低代码平台。其核心目标是：帮助使用 AlkaidSYS 框架的应用/插件开发者快速开发应用和插件。"

2. `design/09-lowcode-framework/40-lowcode-framework-architecture.md`：

> "低代码基础层（Lowcode Foundation）：Collection Manager、Field Type Registry、Relationship Manager、Schema Manager、Validator Generator 等构成低代码能力的核心抽象与服务。"

3. `design/09-lowcode-framework/42-lowcode-data-modeling.md`：

> "Collection 抽象层代表一个数据表的抽象，负责字段和关系的元数据管理；Field 类型系统同时负责字段业务含义、数据库类型和运行时校验；MigrationManager 通过 Collection 元数据生成数据库迁移脚本，确保 Schema 一致性。"

4. `design/03-data-layer/11-database-evolution-and-migration-strategy.md`（间接引用）：

> "低代码运行时 DDL 能力仅限 dev/test 环境使用；stage/prod 环境的结构变更必须通过标准迁移管道执行，低代码 Schema 仅作为真源输入，不得直接在生产库执行运行时 DDL。"

5. `design/08-developer-guides/31-application-development-guide.md`：

> "应用开发者可以通过 CLI 命令（lowcode:create-model/create-form/generate）、低代码管理界面、或直接调用低代码插件 API 的方式，完成数据建模与表单/工作流集成。"

---

**本报告可作为 T1-DOMAIN-CLEANUP 最终执行方案的设计评审依据：**
- 如果接受“Domain\\Lowcode 为单一真源 + Legacy 渐进下线”的方向，则后续 S3/S4 子任务可以按当前 refactoring-plan 中的拆解继续推进；
- 如果未来要引入更通用的数据建模抽象，应在 Domain\\Lowcode 之上增加接口层，而不是重新复制一套 Collection/Field/Relationship 模型。
