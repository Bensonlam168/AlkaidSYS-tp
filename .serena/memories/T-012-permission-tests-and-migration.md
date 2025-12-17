# T-012 权限相关测试与迁移补齐 - 完成记录

## 核心结论
- 依据 `docs/todo/development-backlog-2025-11-25.md`，已将 [T-012] 从 🔄 PARTIAL 更新为 ✅ DONE，并补充了实际完成内容与后续工作归属。
- 新增集成测试文件：`tests/Integration/Permission/CasbinModeSwitchAndRollbackIntegrationTest.php`，包含两个关键用例：
  - `testModeSwitchAcrossDbOnlyDualModeAndCasbinOnlyIsReversibleForAdminUser`
  - `testPermissionChangeReloadAndRollbackWithCasbinKeepsInSyncWithDatabase`
- 新测试用例系统性覆盖了：
  - DB_ONLY → DUAL_MODE → CASBIN_ONLY 模式切换与完全回滚到 DB_ONLY 的行为一致性与可逆性；
  - 权限变更（角色/权限关系撤销与恢复）+ Casbin reloadPolicy + 回滚 后，数据库 RBAC 与 Casbin 策略的一致性。

## 文档更新
- `docs/casbin/migration-guide.md` 中“回滚方案”章节新增小节：
  - 在“完全回滚到 DB_ONLY”下的 **验证** 中，推荐同时运行：
    - `vendor/bin/phpunit tests/Unit/Infrastructure/Permission/PermissionServiceTest.php`
    - `vendor/bin/phpunit tests/Integration/Permission/CasbinModeSwitchAndRollbackIntegrationTest.php`
  - 新增「自动化验证：策略变更 + 回滚（T-012）」小节，明确在以下场景应运行上述集成测试：
    - DB_ONLY → DUAL_MODE → CASBIN_ONLY 分阶段迁移或回滚；
    - 调整 roles/permissions/role_permissions/user_roles 表结构或种子数据。

## Backlog 更新
- `docs/todo/development-backlog-2025-11-25.md` 中 [T-012] 区块更新为：
  - 状态图标改为 ✅，状态更新日期为 2025-11-27（端到端测试与文档补齐）。
  - “已完成”中新增：
    - 新增集成测试文件覆盖模式切换与策略变更/回滚场景；
    - 文档更新指向 migration-guide 新增小节。
  - “待完成”标记为无，并显式说明：控制器 DI / 异常信息收敛 / 测试命令规范等后续优化转由 [T-044] 负责。

## 约束与边界
- 在运行更大范围权限相关测试套件时，仍存在既有的 Feature 与 Performance 测试问题（BaseController DI、AuthPermissionIntegrationTest、Admin/CasbinControllerTest、性能报告输出导致的 Risky），这些问题未在 T-012 之下解决，而被规划到后续 [T-044] 任务中处理。
- T-012 的完成定义聚焦于：
  1. 策略变更 + 回滚策略的定义与自动化验证；
  2. DB_ONLY / CASBIN_ONLY / DUAL_MODE 切换与回滚的端到端测试闭环；
  3. 对应迁移指引文档的补齐。
