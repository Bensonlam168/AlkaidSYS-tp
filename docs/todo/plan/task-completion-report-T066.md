# T-066 任务完成报告：修复 uk_name 唯一索引问题

> **完成日期**: 2025-12-14  
> **任务 ID**: T-066  
> **任务描述**: 删除 lowcode_collections 表的 uk_name 唯一索引，支持多租户同名 collection  
> **预估工作量**: 0.5 天  
> **实际工作量**: 2 小时

---

## 一、问题背景

### 1.1 问题发现

在 T-058 多租户 E2E 测试过程中发现：

- `lowcode_collections` 表存在 `uk_name` 唯一索引，约束 `name` 列全局唯一
- 这阻止了不同租户创建同名集合（如租户 A 和租户 B 都创建 `orders` 集合）
- 与多租户设计意图不符

### 1.2 根因分析

| 索引 | 约束范围 | 预期行为 |
|------|----------|----------|
| `uk_name` | name 全局唯一 | ❌ 过于严格 |
| `uk_tenant_name` | (tenant_id, name) 组合唯一 | ✅ 正确（租户内唯一） |
| `uk_table_name` | table_name 全局唯一 | ⚠️ 待 T-067 评估 |

---

## 二、解决方案

### 2.1 修复内容

创建迁移 `20251214160000_drop_uk_name_from_lowcode_collections.php`：

- 删除 `uk_name` 索引
- 保留 `uk_tenant_name` (tenant_id, name) 组合唯一约束
- 提供完整的 `down()` 回滚方法

### 2.2 技术决策

同时创建 ADR 文档 `docs/technical-specs/adr/ADR-001-multi-tenant-table-naming-strategy.md`：

- 记录问题分析过程
- 记录表名策略待评估事项（T-067）
- 提供后续改进建议

---

## 三、变更文件清单

| 文件 | 操作 | 说明 |
|------|------|------|
| `database/migrations/20251214160000_drop_uk_name_from_lowcode_collections.php` | 新增 | 删除 uk_name 索引的迁移 |
| `docs/technical-specs/adr/ADR-001-multi-tenant-table-naming-strategy.md` | 新增 | 架构决策记录 |
| `docs/todo/plan/project-status-report-2025-12-14.md` | 修改 | 添加 T-066 完成记录 |

---

## 四、测试结果

### 4.1 迁移执行

```bash
$ docker exec alkaid-backend php think migrate:run

== 20251214160000 DropUkNameFromLowcodeCollections: migrating
== 20251214160000 DropUkNameFromLowcodeCollections: migrated 0.0380s

All Done. Took 0.0963s
```

### 4.2 单元测试

```
PHPUnit 11.5.44
Tests: 191, Assertions: 677
OK, but there were issues!
```

### 4.3 E2E 测试

```
PHPUnit 11.5.44
Tests: 3, Assertions: 8
OK, but there were issues!
```

---

## 五、CI 验证

| 项目 | 值 |
|------|-----|
| Workflow Run ID | 20210188503 |
| 状态 | ✅ success |
| Commit SHA | b9d7664 |
| 分支 | feature/lowcode-t002-p1-tenantization |

---

## 六、后续任务

| 任务 ID | 描述 | 优先级 | 状态 |
|---------|------|--------|------|
| T-067 | 表名租户隔离策略评估（表名是否包含 tenant_id） | P2 | Phase 2 规划 |

---

## 七、Git 提交记录

```
commit b9d7664
fix(db): drop uk_name index for multi-tenant support (T-066)

- Create migration to drop uk_name unique index from lowcode_collections
- Add ADR-001 documenting multi-tenant table naming strategy
- uk_name prevented different tenants from creating same-name collections
- uk_tenant_name (tenant_id, name) remains for tenant-scoped uniqueness
```

