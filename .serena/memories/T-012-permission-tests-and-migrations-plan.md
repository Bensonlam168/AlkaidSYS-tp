# T-012 权限相关测试与迁移补齐 - 规划记录

## 背景与目标
- 依据 `docs/todo/development-backlog-2025-11-25.md`，T-012 当前状态为 🔄 PARTIAL。
- 已有较完整的 Casbin/Permission 测试覆盖与 `casbin.manage` 迁移。
- 待完成部分聚焦于：
  - 策略变更回滚策略：围绕 DB_ONLY / CASBIN_ONLY / DUAL_MODE 模式切换与回滚；
  - 至少 1~2 个“Casbin 策略变更 + 回滚”的端到端测试用例。

## 初步执行计划（来自 sequential-thinking）
1. 信息收集与基线确认：
   - 代码：`Infrastructure/Permission/Service/PermissionService.php`、`infrastructure/Permission/Service/CasbinService.php`、`app/middleware/Permission.php`、`app/controller/AuthController.php`、RBAC 表与种子数据、Casbin 配置与适配器。
   - 测试：CasbinServiceTest/CasbinCache(Degradation)Test/DatabaseAdapterTest/CasbinControllerTest/PermissionServiceIntegrationTest/CasbinPerformanceTest/AuthPermissionIntegrationTest/CasbinCacheIntegrationTest。
   - 文档：`docs/casbin/migration-guide.md`、权限相关设计与 code-review 报告。

2. 需求精炼：
   - 明确“策略变更回滚策略”的语义：模式演进（DB_ONLY→DUAL_MODE→CASBIN_ONLY）与回滚路径（CASBIN_ONLY/DUAL_MODE→DB_ONLY），以及失败/降级行为。
   - 把 backlog 要求的 1–2 条 E2E 场景具体化：
     - 场景 A：DB_ONLY→DUAL_MODE→DB_ONLY 的切换 + 验证关键路由行为一致性；
     - 场景 B：DUAL_MODE/CASBIN_ONLY 下策略更新 + 回滚后的行为验证。

3. 设计决策点：
   - 确认现有配置与 Service 中是否已支持模式切换/回滚；如果已有，则以补文档与测试为主；若存在缺口，则以最小增量补足实现。
   - 测试策略以 Feature/E2E 为主，通过 HTTP 调用 `/v1/auth/me` + Permission 保护路由观测行为，尽量不依赖内部实现细节。

4. 后续子任务（待在 Tasklist 中正式拆分）：
   - 子任务 1：全面审计现有实现与文档，输出“现状说明”。
   - 子任务 2：如有必要，微调 Casbin/Permission 运行模式与回滚机制实现。
   - 子任务 3：实现 1–2 条模式切换+回滚的端到端 Feature 测试。
   - 子任务 4：在 alkaid-backend 容器内运行并稳定通过相关测试。
   - 子任务 5：更新 docs/casbin/migration-guide.md 与 backlog T-012 的已完成/待完成小节。

## 约束与注意事项
- 遵守 Always Rules：
  - 保持 `permissions.slug = resource.action`，API 对外始终返回 `resource:action`；
  - 不破坏多租户隔离与统一 PermissionService 出口；
  - 所有迁移通过 ThinkPHP 迁移体系完成，不直接写 DDL。
- 测试执行一律在 `alkaid-backend` 容器内运行（如：`docker exec -it alkaid-backend php think test` 或 vendor/bin/phpunit）。
