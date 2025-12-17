# Git 清理和提交实施计划（2025-12-17）

> **创建日期**：2025年12月17日
> **基于报告**：`docs/report/git-status-analysis-2025-12-17.md`
> **实施目标**：按照分析报告的建议，分批清理和提交230个文件变更

---

## 📋 执行摘要

基于Git状态分析报告，我们将按照4个批次来处理230个文件变更，确保风险可控且每个提交都有明确的目标和范围。

---

## 🎯 实施策略

### 分批原则
1. **风险优先级**：高优先级 → 中优先级 → 低优先级
2. **功能相关性**：相关功能放在同一批次
3. **测试验证**：每批次提交前进行充分测试
4. **文档同步**：及时更新相关文档

### 质量保证
- 每批次提交前运行代码格式检查
- 执行相关测试套件验证
- 确保配置文件一致性
- 遵循Conventional Commits规范

---

## 📦 第一批：核心基础设施（高优先级）

### 目标
提交低代码框架核心变更和认证权限中间件优化

### 涉及文件（45个）
```
app/:
├── AppService.php
├── Request.php
├── common.php
├── middleware/Auth.php
├── middleware/Permission.php
├── middleware/RateLimit.php
├── listener/SlowQueryListener.php
├── controller/lowcode/FieldController.php
├── controller/lowcode/RelationshipController.php
├── command/base/LowcodeCommand.php
├── command/lowcode/CreateFormCommand.php
├── command/lowcode/CreateModelCommand.php
├── command/lowcode/GenerateCommand.php
└── command/lowcode/MigrationDiffCommand.php

domain/:
├── Lowcode/Collection/Interfaces/CollectionInterface.php
├── Lowcode/Collection/Interfaces/RelationshipInterface.php
├── Lowcode/Collection/Model/Collection.php
└── CLAUDE.md

infrastructure/:
├── Lowcode/Collection/Field/FieldFactory.php
├── Lowcode/Collection/Repository/CollectionRepository.php
├── Lowcode/Collection/Repository/FieldRepository.php
├── Lowcode/Collection/Repository/RelationshipRepository.php
├── Lowcode/Collection/Service/CollectionManager.php
├── Lowcode/Collection/Service/FieldManager.php
├── Lowcode/Collection/Service/RelationshipManager.php
├── Lowcode/FormDesigner/Repository/FormSchemaRepository.php
├── Lowcode/FormDesigner/Service/FormSchemaManager.php
├── Permission/Casbin/DatabaseAdapter.php
├── Tenant/TenantAwareDb.php
├── Lowcode/Collection/Field/CascaderField.php
├── Lowcode/Collection/Field/TreeSelectField.php
├── Lowcode/Collection/Service/TableNameGenerator.php
├── Lowcode/Schema/
├── Plugin/
└── CLAUDE.md

config/:
├── lowcode.php
├── plugin.php
├── casbin.php
├── console.php
└── commitlint.config.js

.env.example
.example.env
```

### 提交信息
```
feat(lowcode): 重构低代码框架核心组件 (T-XXX)

- 重构 Collection、Field、Relationship 核心组件
- 优化认证权限中间件实现
- 更新基础配置文件结构
- 添加新的字段类型支持

影响范围：低代码框架、认证权限
测试状态：需要补充单元测试
关联任务：T-XXX
```

### 验证清单
- [ ] 运行 PHP-CS-Fixer 格式检查
- [ ] 执行低代码相关单元测试
- [ ] 验证认证权限中间件功能
- [ ] 检查配置文件语法正确性
- [ ] 测试多租户隔离功能

---

## 📦 第二批：新功能模块（高优先级）

### 目标
提交工作流引擎插件系统

### 涉及文件（58个）
```
plugins/workflow-engine/ (完整目录结构)
├── composer.json
├── composer.lock
├── LICENSE
├── README.md
├── config/
├── docs/
├── migrations/
├── packages/
├── routes/
├── scripts/
├── src/
├── tests/
└── vendor/

database/migrations/:
├── 20251217000001_create_lowcode_schema_versions_table.php
└── 20251217000002_create_plugin_packages_and_installations_tables.php
```

### 提交信息
```
feat(plugin): 添加工作流引擎插件系统 (T-XXX)

- 实现完整的工作流引擎插件
- 包含独立的 Composer 包管理
- 提供完整的测试覆盖和文档
- 添加相关数据库迁移

影响范围：插件系统、工作流引擎
测试状态：需要集成测试验证
关联任务：T-XXX
```

### 验证清单
- [ ] 验证插件系统加载
- [ ] 执行工作流引擎单元测试
- [ ] 运行数据库迁移
- [ ] 检查 Composer 依赖兼容性
- [ ] 测试插件生命周期管理

---

## 📦 第三批：测试和工具（中优先级）

### 目标
提交测试文件补充和开发工具

### 涉及文件（25个）
```
tests/:
├── Unit/Auth/
├── Unit/Infrastructure/Lowcode/Collection/Repository/TenantAwareRepositoryTest.php
├── Unit/Infrastructure/Lowcode/Collection/Service/FieldManagerTest.php
├── Unit/Infrastructure/Lowcode/Collection/Service/RelationshipManagerTest.php
├── Unit/Lowcode/Collection/
├── Unit/Lowcode/Field/
├── Unit/Lowcode/Schema/
├── Unit/Plugin/
├── Unit/Scripts/
├── Unit/Command/Lowcode/CreateFormCommandTest.php
├── Unit/Command/Lowcode/CreateModelCommandTest.php
├── Unit/Command/Lowcode/MigrationDiffCommandTest.php
├── Feature/Auth/
├── Feature/AuthMiddlewareTest.php
├── Feature/Lowcode/FormApiMultiTenantE2ETest.php
├── Feature/PermissionCodeFormatTest.php
├── Feature/RateLimitMiddlewareTest.php
├── Integration/Lowcode/LowcodeCliE2ETest.php
├── Integration/Model/
├── Fixtures/
├── Legacy/
├── Performance/.benchmarks/
├── Unit/Infrastructure/Application/ApplicationManagerTest.php
├── Unit/Infrastructure/Application/BaseApplicationTest.php
└── Unit/Lowcode/FormDesigner/FormDataManagerMultiTenantTest.php

scripts/:
├── check-permission-codes.php
├── check-rawsql-safety.php
├── git-workflow-tools.sh
├── check-legacy-usage.php
├── check-raw-sql-tenant.php
└── validate-config.sh

.github/workflows/:
├── branch-protection.yml
├── ci-validation-test.yml
├── frontend-ci.yml
└── git-metrics.yml
```

### 提交信息
```
test(testing): 补充测试覆盖和开发工具 (T-XXX)

- 补充低代码框架单元测试
- 添加多租户集成测试
- 完善开发工具和 CI/CD 流程
- 添加权限和限流测试

影响范围：测试工具、CI/CD
测试状态：测试工具本身需要验证
关联任务：T-XXX
```

### 验证清单
- [ ] 运行完整测试套件
- [ ] 验证 CI/CD 流程配置
- [ ] 检查开发工具脚本功能
- [ ] 测试多租户场景覆盖
- [ ] 验证权限码格式检查

---

## 📦 第四批：文档和规范（低优先级）

### 目标
提交技术规范文档和项目文档

### 涉及文件（35个）
```
docs/technical-specs/:
├── git-workflow.md
├── lowcode/lowcode-cli-guide.md (修改)
├── testing/testing-guidelines.md (修改)
├── multi-tenant/
├── plugin/
└── testing/multi-tenant-lowcode-test-matrix.md

docs/report/:
├── arch-01-legacy-migration-plan-2025-12-18.md
├── augment-skills-commands-codex-analysis.md
├── backend-implementation-vs-design-analysis.md
├── backend-permission-integration-review-2025-11-25.md
├── casbin-implementation-vs-design-analysis.md
├── comprehensive-project-audit-report-2025-12-16.md
├── design-compliance-audit-2025-12-14.md
├── git-audit-implementation-log.md
├── git-audit-report.md
├── ingenious-integration-readiness-analysis.md
├── lowcode-implementation-audit-2025-12-14.md
├── multi-tenant-implementation-audit-2025-12-14.md
├── perf-01-permission-cache-plan-2025-12-18.md
├── sec-02-raw-sql-audit-2025-12-18.md
├── sec-02-raw-sql-categorized-2025-12-18.md
└── test-01-coverage-plan-2025-12-18.md

docs/:
├── training/
├── api/admin/casbin.md
├── casbin/configuration.md
├── casbin/migration-guide.md
├── casbin/README.md
├── deployment/environment-variables.md (修改)
├── other-docs/deployment-guide.md
├── other-docs/deployment-guide.zh-CN.md
├── performance/performance-baseline-2025-12-14.md
├── technical-specs/adr/ADR-001-multi-tenant-table-naming-strategy.md
└── LOGO.md

项目根目录文档:
├── AGENTS.md
├── all_design_files.txt
├── CLAUDE.md
├── domain/CLAUDE.md
├── infrastructure/CLAUDE.md
└── README.local-merge-backup.md
```

### 提交信息
```
docs(documentation): 完善技术规范和项目文档 (T-XXX)

- 更新技术规范文档
- 生成项目审计报告
- 完善 API 和部署文档
- 添加培训材料和 ADR 文档

影响范围：项目文档、规范
测试状态：文档本身无需测试
关联任务：T-XXX
```

### 验证清单
- [ ] 检查文档格式一致性
- [ ] 验证链接有效性
- [ ] 确认文档与代码同步
- [ ] 检查中英文术语一致性
- [ ] 验证文档目录结构

---

## 🧹 清理操作

### 临时文件清理
以下文件已添加到 `.gitignore`，将在下次提交时被忽略：

```bash
# 临时分析脚本
analyze_*.py
debug_*.py
generate_*.py
refine_*.py
split_*.py

# 临时 HTML 文件
error_*.html
public/debug_*.html
public/debug_*.svg

# 生成的图片文件
public/generated_image_*.png

# 开发工具配置
.git-commit-message*.txt
README.local-merge-backup.md
```

### 手动清理建议
```bash
# 清理临时文件（可选）
rm -f analyze_*.py
rm -f debug_*.py
rm -f generate_*.py
rm -f refine_*.py
rm -f split_*.py
rm -f error_*.html
rm -f public/debug_*.html
rm -f public/debug_*.svg
rm -f public/generated_image_*.png
rm -f .git-commit-message*.txt
rm -f README.local-merge-backup.md
```

---

## 🔍 质量检查流程

### 每批次提交前检查

#### 1. 代码质量检查
```bash
# 在 alkaid-backend 容器内执行
docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix --dry-run --diff
```

#### 2. 测试验证
```bash
# 运行相关测试套件
docker exec -it alkaid-backend php think test --testsuite=Feature
docker exec -it alkaid-backend php think test --testsuite=Unit
```

#### 3. 配置验证
```bash
# 验证配置文件语法
docker exec -it alkaid-backend php think config:cache
```

#### 4. 依赖检查
```bash
# 检查 Composer 依赖
docker exec -it alkaid-backend composer validate
```

### 提交信息规范
遵循 Conventional Commits 规范：
- 格式：`<type>(<scope>): <subject>`
- 类型：`feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `perf`, `style`
- 主题：≤ 72 字符
- 正文：详细描述变更内容和影响

---

## 📊 进度跟踪

### 执行状态
| 批次 | 状态 | 完成时间 | 提交哈希 | 备注 |
|------|------|----------|----------|------|
| 第一批 | 待执行 | - | - | 核心基础设施 |
| 第二批 | 待执行 | - | - | 工作流引擎插件 |
| 第三批 | 待执行 | - | - | 测试和工具 |
| 第四批 | 待执行 | - | - | 文档和规范 |

### 风险控制
- **高风险操作**：第一批和第二批需要充分测试
- **回滚计划**：每批次都有独立的回滚点
- **分支策略**：建议在功能分支进行，避免直接操作主分支

---

## 🎯 成功标准

### 技术标准
- [ ] 所有代码通过 PSR-12 格式检查
- [ ] 测试覆盖率达到 80% 以上
- [ ] 所有配置文件语法正确
- [ ] 文档与代码保持同步

### 流程标准
- [ ] 遵循 Conventional Commits 规范
- [ ] 每个提交都有清晰的描述和范围
- [ ] 所有高风险变更都有测试保护
- [ ] 文档更新及时完整

### 质量标准
- [ ] 代码审查通过率 100%
- [ ] 自动化检查通过率 100%
- [ ] 测试执行通过率 100%
- [ ] 部署验证通过率 100%

---

## 📞 后续维护

### 持续改进
1. **定期清理**：每月检查临时文件和过期文档
2. **自动化检查**：在 CI 中集成更多质量检查
3. **文档维护**：定期更新技术规范和最佳实践
4. **工具优化**：持续改进开发工具和脚本

### 监控指标
- 代码质量分数
- 测试覆盖率趋势
- 文档完整性指标
- 提交质量和频率

---

**计划创建时间**：2025年12月17日 12:30
**预计完成时间**：1-2个工作日
**负责人**：开发团队
**审查人**：技术负责人

---

## 📝 备注

1. **灵活性**：本计划可根据实际情况调整批次顺序和内容
2. **协作**：建议团队成员分工负责不同批次
3. **沟通**：重要变更需要团队讨论和确认
4. **备份**：执行前建议创建当前状态的备份

---

*本计划将根据实际执行情况动态更新*