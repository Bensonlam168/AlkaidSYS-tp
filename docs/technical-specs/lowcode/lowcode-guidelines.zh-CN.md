# 低代码开发规范

## 1. 范围与目标

本规范用于约束 AlkaidSYS 中低代码子系统（`Domain\\Lowcode`、`Infrastructure\\Lowcode`、后台管理 UI） 的使用方式，确保其成为动态集合、字段与关联关系的**唯一事实来源**。

## 2. 唯一事实来源

- `Domain\\Lowcode` 下的元数据 **必须** 被视为动态实体模型的权威来源。
- 操作集合与字段的代码 **必须** 通过低代码服务完成；禁止直接依赖 Legacy 集合抽象。

## 3. Legacy 使用限制

- 新功能 **禁止** 引入对 Legacy 集合 API（如 `Domain\\Model\\Collection`、`Infrastructure\\Collection\\CollectionManager` 等）的新依赖。
- 仍需保留的 Legacy 代码 **必须** 通过适配层进行封装，并明确标记为废弃，仅用于兼容目的。

## 4. 建模约定

- 集合与字段名称 **必须** 遵循与数据库表和字段相同的命名规则。
- 集合之间的关系 **应当** 明确建模并文档化，包括基数与所有权等信息。

## 5. 低代码 Phase 模型

- **Phase 1**：低代码覆盖所有新功能；既有流程仍可依赖 Legacy 模型。
- **Phase 2**：关键业务流程 **应当** 全面迁移至低代码模型，并按照废弃计划逐步下线 Legacy 模型。

