# T1-DOMAIN-CLEANUP 调研报告（现状与影响面）

> 范围：围绕 Collection / Field / Relationship 相关领域模型及其调用方，梳理当前代码库中的两套模型体系与依赖关系，为后续统一方案与迁移规划提供输入。

## 1. 模型清单总览

| 概念 | Legacy 体系 | Lowcode 体系 | 备注 |
|------|-------------|-------------|------|
| Collection 领域对象 | `Domain\\Model\\Collection` | `Domain\\Lowcode\\Collection\\Model\\Collection` + `CollectionInterface` | 均表示“逻辑表 + 字段 + 关系”，前者继承 ORM，后者为纯领域对象 |
| Field 领域对象 | `Domain\\Field\\*` + `FieldInterface` | `Domain\\Lowcode\\Collection\\Interfaces\\FieldInterface` + `Infrastructure\\Lowcode\\Collection\\Field\\*` | 两套字段类型与验证逻辑并存 |
| Collection 管理服务 | `Infrastructure\\Collection\\CollectionManager` | `Infrastructure\\Lowcode\\Collection\\Service\\CollectionManager` | 均负责创建表、保存元数据、触发事件 |
| Field 注册表 | `Infrastructure\\Field\\FieldTypeRegistry` | `Infrastructure\\Lowcode\\Collection\\Field\\FieldFactory` | 都扮演“按类型创建字段”的工厂/注册表 |
| Relationship 模型 | Legacy：数组结构/轻量配置 | `Domain\\Lowcode\\Collection\\Model\\Relationship` + `RelationshipInterface` | 仅 Lowcode 体系有完整显式模型 |
| Form / Schema | （无单独领域模型，早期设计文档内联） | `Infrastructure\\Lowcode\\FormDesigner\\Repository\\FormSchemaRepository` + Service 层 | Form 以数组/JSON Schema 形式存储，引用 Collection 名称 |

初步判断：**Lowcode 体系已经覆盖了实际 HTTP/API 场景中的数据建模需求，而 Legacy 体系主要停留在 CLI / 测试 / 历史文档层面。**

## 2. 关键类与调用方

### 2.1 Legacy Stack（Domain\Model\Collection + Domain\Field\*）

- 模型与基础设施：
  - `domain/Model/Collection.php`
  - `domain/Field/AbstractField.php`、`StringField`、`IntegerField`、`BooleanField`、`DateField`
  - `infrastructure/Field/FieldTypeRegistry.php`
  - `infrastructure/Collection/CollectionManager.php`
- 直接调用方：
  - CLI：`app/command/TestCollection.php`（使用 `Domain\\Model\\Collection` + `Infrastructure\\Collection\\CollectionManager`）
  - CLI：`app/command/TestField.php`（直接 new `Domain\\Field\\StringField` / `IntegerField` 等，依赖 `FieldTypeRegistry`）
  - 单元测试：`tests/Unit/Field/FieldTypeSystemTest.php`
- 特征：
  - Collection 继承 `think\\Model`，与 ORM 紧耦合；字段/关系以数组方式配置；
  - `CollectionManager` 直接操作 `lowcode_collections` 表，保存 `Collection::toArray()` 结果；
  - 在当前代码中 **未被 HTTP 控制器或 Lowcode API 使用**，主要作为 Phase-1 的原型实现存在。

### 2.2 Lowcode Stack（Domain\Lowcode\Collection\*）

- 领域模型与接口：
  - `domain/Lowcode/Collection/Model/Collection.php`（实现 `CollectionInterface`）
  - `domain/Lowcode/Collection/Model/Relationship.php`（实现 `RelationshipInterface`）
  - `domain/Lowcode/Collection/Interfaces/{CollectionInterface,FieldInterface,RelationshipInterface}.php`
  - `domain/Lowcode/Collection/Enum/RelationType.php`
- 基础设施与服务：
  - `infrastructure/Lowcode/Collection/Repository/{CollectionRepository,FieldRepository,RelationshipRepository}.php`
  - `infrastructure/Lowcode/Collection/Service/{CollectionManager,RelationshipManager}.php`
  - `infrastructure/Lowcode/Collection/Field/{StringField,IntegerField,DateField,DatetimeField,TextField,BigintField,...}.php`
  - `infrastructure/Lowcode/Collection/Field/FieldFactory.php`
  - `infrastructure/Schema/SchemaBuilder.php` + `domain/Schema/Interfaces/SchemaBuilderInterface.php`
- 应用层调用方：
  - `app/controller/lowcode/CollectionController.php`
    - 构建 `Domain\\Lowcode\\Collection\\Model\\Collection` 实例，使用 `FieldFactory` 创建字段，调用 `CollectionManager->create()` 完成表与元数据创建；
  - `app/controller/lowcode/RelationshipController.php`
    - 通过 `CollectionManager->get()` 拿到 `CollectionInterface`，再构建 `Relationship` 交给 `RelationshipManager`；
  - FormDesigner 相关：`infrastructure/Lowcode/FormDesigner/Repository/FormSchemaRepository.php` 及 Service 层
    - 表单 Schema 通过 `collection_name` 字段引用 Collection，实现 UI 与数据建模的解耦；
- 特征：
  - Collection/Relationship 为 **纯领域对象**，不继承 ORM，适合在 CLI/HTTP/Swoole 等多运行时统一复用；
  - Field 类型实现同时承担 **DDL 列定义 + 值校验 + 元数据导出** 三重职责，与 SchemaBuilder 深度耦合；
  - 完整覆盖当前低代码 API 与表单工作流的主要场景，是事实上的“主线体系”。

## 3. 使用场景对比与重叠评估

1. **HTTP/API 路径**
   - 低代码相关 REST 控制器（Collection / Relationship）**全部依赖 Lowcode Stack**，未直接引用 `Domain\\Model\\Collection` 或 `Domain\\Field\\*`。
   - 设计文档 `design/09-lowcode-framework/*.md` 与实际代码高度一致，示例代码中出现的 `Collection` / `StringField` 等均指向 Lowcode 命名空间实现。

2. **CLI 与测试场景**
   - Legacy Stack 仍在以下路径中使用：
     - `php think test:collection`（TestCollection 命令）；
     - `php think test:field`（TestField 命令）；
     - `tests/Unit/Field/FieldTypeSystemTest`。
   - Lowcode Stack 也有自己的 CLI/测试：
     - `app/command/TestFieldTypes.php` 对 `Infrastructure\\Lowcode\\Collection\\Field\\FieldFactory` 注册的 15+ 字段类型做验证；
     - 多个 FormDesigner / CollectionManager 相关的单元测试。

3. **配置与元数据持久化层**
   - 两套体系最终都落在类似的元数据表上（如 `lowcode_collections`、`lowcode_fields`、`lowcode_relationships`），但：
     - Legacy Stack 通过 `Collection::toArray()` 将字段/关系以数组整体存入一列 `schema`；
     - Lowcode Stack 将 Collection/Field/Relationship 拆分为多张表并有独立仓储，粒度更细。

4. **重叠结论**
   - 概念上：**Collection / Field / Relationship 三个核心概念在领域层被重复建模，两套接口与实现并存**；
   - 实际使用上：
     - Lowcode Stack 已成为 HTTP/API 与 Form 工作流的事实标准；
     - Legacy Stack 主要在 CLI / 测试中存续，可视为历史技术债/过渡层。

## 4. 依赖关系图（简化版）

```mermaid
graph TB
  subgraph "Legacy Stack"
    LCol[Domain\\Model\\Collection]
    LField[Domain\\Field\\*]
    LReg[Infrastructure\\Field\\FieldTypeRegistry]
    LMgr[Infrastructure\\Collection\\CollectionManager]
    LCmd[app\\command\\{TestCollection,TestField}]
    LTest[tests\\Unit\\FieldTypeSystemTest]
  end

  subgraph "Lowcode Stack"
    DCol[Domain\\Lowcode\\Collection\\Model\\Collection]
    DRel[Domain\\Lowcode\\Collection\\Model\\Relationship]
    DInt[Domain\\Lowcode\\Collection\\Interfaces\\*]
    FImpl[Infra Lowcode Field\\*]
    FFac[FieldFactory]
    Repo[Collection/Field/Relationship Repository]
    Svc[CollectionManager / RelationshipManager]
    SB[Infrastructure\\Schema\\SchemaBuilder]
    Ctl[Lowcode Controllers]
    Form[FormDesigner Services]
  end

  LCmd --> LMgr --> LCol
  LCmd --> LReg --> LField
  LTest --> LReg

  Ctl --> Svc
  Form --> Svc
  Svc --> Repo
  Svc --> SB
  Svc --> DCol
  Svc --> DRel
  FFac --> FImpl
  DCol --> FImpl
  DCol --> DRel
```

> 说明：图中仅保留了与 Collection / Field / Relationship 直接相关的主要节点，忽略了多租户、审计、缓存等横切能力。

## 5. 其他相关概念（Form / View / DataSource / Schema）

- **Form**：
  - 代码中只有一套 FormDesigner 相关实现：`Infrastructure\\Lowcode\\FormDesigner\\{Repository,Service}`，使用 `lowcode_forms` 表持久化 Schema，未发现第二套平行的领域模型实现；
  - 设计文档（如 `43-lowcode-form-designer.md`）与该实现对齐，可认为暂不存在“Form 领域模型重叠”问题。
- **View / DataSource**：
  - 目前主要停留在设计文档层（视图模型、数据源抽象），在代码中尚未演化为独立的领域模型体系；
  - 与 Collection 的耦合关系后续在真正实现时需要对齐本次的统一方案，但当前不构成结构性技术债。
- **Schema / DDL**：
  - 统一使用 `Domain\\Schema\\Interfaces\\SchemaBuilderInterface` + `Infrastructure\\Schema\\SchemaBuilder`，**未出现第二套并行 SchemaBuilder**。

## 6. 初步结论与后续设计输入

1. **主线与支线的角色划分**
   - Lowcode Stack = 主线：承载当前低代码能力的全部关键路径（API + DDL + 表单工作流），应作为未来领域模型统一的基准；
   - Legacy Stack = 支线：仅在 CLI/测试中存活，且文档已将其评估为“简化实现”，适合在 T1-DOMAIN-CLEANUP 中被标记为 deprecated 并规划迁移/清理。

2. **统一方向（供后续方案设计使用）**
   - 领域层统一围绕 `Domain\\Lowcode\\Collection\\*` 展开，Legacy 中与之概念重叠的类（`Domain\\Model\\Collection`、`Domain\\Field\\*`、`Infrastructure\\Collection\\CollectionManager`、`Infrastructure\\Field\\FieldTypeRegistry`）应逐步：
     - 停止在新代码中被引用；
     - 为现有 CLI/测试提供适配或替代实现；
     - 最终在一到两个迭代后从代码库中移除。

3. **对后续文档与计划的影响**
   - `design/02-domain-layer/domain-model-unification-plan.md`：
     - 需要以本报告为基础，给出“以 Lowcode 体系为中心、Legacy 逐步退出”的目标架构与迁移策略；
   - `docs/todo/refactoring-plan.md`：
     - T1-DOMAIN-CLEANUP 小节应细化为多条可执行子任务（调研、设计、兼容层、CLI/测试迁移、Legacy 下线等），并关联对应的验收标准。

