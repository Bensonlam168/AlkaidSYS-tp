# ADR-001: 多租户低代码表命名策略

> **状态**: 已决定  
> **日期**: 2025-12-14  
> **决策者**: AI Assistant + 架构师  
> **关联任务**: T-058, T-066, T-067

## 背景

在 T-058 多租户 E2E 测试过程中发现以下问题：

1. `lowcode_collections` 表存在 `uk_name` 唯一索引，阻止不同租户创建同名集合
2. 动态表命名使用 `lc_{collection_name}` 格式，不包含 `tenant_id`
3. 这可能导致多租户环境下的表名冲突

## 问题分析

### 当前实现

| 组件 | 当前行为 |
|------|----------|
| 表名生成 | `lc_{collection_name}` |
| 元数据唯一约束 | `uk_name` (name 全局唯一) + `uk_tenant_name` (tenant_id, name) |
| 数据隔离 | 通过 `tenant_id` 字段行级隔离 |

### 问题影响

1. **`uk_name` 索引**: 阻止租户 B 创建与租户 A 同名的集合 → **Bug**
2. **表名无 tenant_id**: 即使删除 `uk_name`，同名集合仍会使用同一物理表 → **设计决策点**

## 决策

### 已决定

**T-066**: 删除 `uk_name` 唯一索引

- **理由**: 与多租户设计意图不符，是一个 Bug
- **影响**: 允许不同租户创建同名集合
- **风险**: 低

### 待评估 (Phase 2)

**T-067**: 表名租户隔离策略

需评估以下方案：

| 方案 | 表名格式 | 优点 | 缺点 |
|------|----------|------|------|
| A: 保持现状 | `lc_{name}` | 无需修改 | 同名集合共享表，需 schema 兼容机制 |
| B: 表名隔离 | `lc_t{tenant_id}_{name}` | 完全隔离 | 表数量增加 |
| C: Schema 隔离 | 独立数据库/schema | 最强隔离 | 实现复杂 |

**建议**: Phase 2 评估，默认采用方案 B（表名隔离）

## 相关代码

- `domain/Lowcode/Collection/Model/Collection.php` - `generateTableName()`
- `infrastructure/Lowcode/Collection/Service/CollectionManager.php`
- `infrastructure/Lowcode/Generator/MigrationManager.php`

## 参考文档

- `design/03-data-layer/12-multi-tenant-data-model-spec.md`
- `design/01-architecture-design/04-multi-tenant-design.md`

