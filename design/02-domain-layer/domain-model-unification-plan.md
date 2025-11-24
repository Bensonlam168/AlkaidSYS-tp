# 域模型统一方案：T1-DOMAIN-CLEANUP 设计说明

> 目标：
> - 收敛当前代码库中围绕 Collection / Field / Relationship 的多套领域模型，实现“单一真实来源（Single Source of Truth）”；
> - 以 Lowcode 体系为中心，逐步让 Legacy 体系退出主调用路径；
> - 为后续 T1 执行阶段与 T2/T3 演进提供清晰、可落地的架构蓝图与迁移策略。

## 1. 当前格局简述（来自调研报告）

> 详见：`docs/todo/t1-domain-cleanup-investigation.md`

- Legacy 体系：
  - `Domain\\Model\\Collection` + `Domain\\Field\\*` + `Infrastructure\\Collection\\CollectionManager` + `Infrastructure\\Field\\FieldTypeRegistry`；
  - 主要出现在 CLI 命令与部分单元测试中，未被当前低代码 HTTP API 直接使用。
- Lowcode 体系：
  - `Domain\\Lowcode\\Collection\\Model\\Collection/Relationship` + `Interfaces` + `Enum\\RelationType`；
  - `Infrastructure\\Lowcode\\Collection\\{Service,Repository,Field\\*,FieldFactory}` + `Infrastructure\\Schema\\SchemaBuilder`；
  - 承载低代码建模、DDL、表单工作流等全部主路径，是事实上的“主线体系”。

结论：**统一方向应围绕 Lowcode 体系展开，将 Legacy 体系视为待清理的技术债。**

## 2. 目标架构（Target Architecture）

### 2.1 分层视角

- 领域层（Domain）：
  - 统一使用 `Domain\\Lowcode\\Collection\\*` 表达集合/字段/关系；
  - `Domain\\Schema\\Interfaces\\SchemaBuilderInterface` 作为 DDL 能力的唯一契约；
  - Legacy `Domain\\Model\\Collection` / `Domain\\Field\\*` 不再作为新代码的依赖入口。
- 基础设施层（Infrastructure）：
  - Collection / Field / Relationship 的持久化、缓存、事件与 DDL 全部通过：
    - `Infrastructure\\Lowcode\\Collection\\Service\\{CollectionManager,RelationshipManager}`；
    - `Infrastructure\\Lowcode\\Collection\\Repository\\*`；
    - `Infrastructure\\Lowcode\\Collection\\Field\\*` 与 `FieldFactory`；
    - `Infrastructure\\Schema\\SchemaBuilder`；
  - 如需“字段类型注册表”能力，统一通过 `FieldFactory` 提供扩展点。
- 应用层（App）：
  - HTTP 控制器、CLI 命令、FormDesigner 等上层模块，仅通过 `Domain\\Lowcode\\Collection\\Interfaces\\*` + Service 层访问数据建模能力；
  - Legacy CLI（`test:collection` / `test:field`）要么重写为基于 Lowcode 接口，要么在文档中标记为废弃后移除。

### 2.2 模型收敛原则

1. **单一概念 = 单一领域模型类**：
   - Collection / Field / Relationship 等核心概念，不再允许多套实现并行存在于 Domain 层。
2. **领域对象与 ORM 解耦**：
   - Domain 中的 Collection/Field/Relationship 不直接继承 `think\\Model`，ORM 由 Infrastructure 或应用层负责；
3. **接口优先**：
   - 应用层仅依赖接口（`CollectionInterface` / `FieldInterface` 等），实现可在 Lowcode 内部演进而不破坏契约。

## 3. 迁移与兼容策略

### 3.1 阶段划分

- 阶段 A：调研与设计（本轮）
  - [x] 完成现状调研与影响面分析：`docs/todo/t1-domain-cleanup-investigation.md`；
  - [x] 制定本统一方案与迁移策略：本文件。
- 阶段 B：兼容层与新入口
  - [x] 为仍引用 Legacy 体系的组件提供基于 Lowcode 的等价能力：
    - 通过增强 `Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager` 满足原 `Infrastructure\\Collection\\CollectionManager` 的使用场景；
    - 将 Legacy `FieldTypeRegistry` 收缩为基于 `FieldFactory` 的 shim，并整体标记为 deprecated，统一由 Lowcode 字段体系提供类型注册与创建能力；
  - [x] 新增/调整 CLI 命令：
    - 新增 `test:lowcode-collection` 命令直接测试 Lowcode 体系；
    - 保留 `test:collection` / `test:field` 作为兼容入口，并在代码与运行时输出中标注 deprecated。
- 阶段 C：调用方迁移与 Legacy 下线
  - [x] 将 `app/command/TestCollection.php` 重写为基于 Lowcode Collection 接口的实现；
  - [x] 将 `app/command/TestField.php` 与 `tests/Unit/Field/FieldTypeSystemTest.php` 迁移到 Lowcode 字段体系（统一使用 `FieldFactory`）；
  - [x] 检查全仓库中对 `Domain\\Model\\Collection`、`Domain\\Field\\*`、`Infrastructure\\Collection\\CollectionManager`、`Infrastructure\\Field\\FieldTypeRegistry` 的引用，确保：
    - 无新的业务代码引入这些依赖；
    - Legacy 类已在注释中明确标记为 deprecated，并规划后续删除版本；
  - [ ] 在 CI 中增加一条“禁止新 Legacy 依赖”的规则（例如简单 grep 检查）。

## 4. 验收标准（供 T1-DOMAIN-CLEANUP 使用）

- 领域层：
  - [ ] `Domain\\Model\\Collection` 与 `Domain\\Field\\*` 不再被任何新代码引用，旧调用点要么迁移要么删除；
  - [ ] Collection / Field / Relationship 概念仅通过 `Domain\\Lowcode\\Collection\\*` 表达；
- 基础设施层：
  - [ ] 运行时 DDL / 元数据持久化 / 字段类型注册能力全部由 Lowcode + SchemaBuilder 组合承担；
  - [ ] 任一字段类型新增或变更仅需在 Lowcode Field 实现与 FieldFactory 中完成；
- 应用层与文档：
  - [ ] HTTP 控制器、FormDesigner、CLI 命令等上层模块均通过 Lowcode 接口访问数据建模能力；
  - [ ] `docs/todo/refactoring-plan.md` 中 T1-DOMAIN-CLEANUP 小节与 `docs/todo/t1-domain-cleanup-investigation.md` / 本文件保持一致；
  - [ ] 设计评审报告中“领域模型命名和职责重叠”问题可标记为“已解决/仅保留历史说明”。

