# PERF-01 权限查询性能与缓存优化计划（2025-12-18）

## 现状梳理

- 主要实现：`infrastructure/Permission/Service/CasbinService.php`
  - 缓存键：`casbin:check:{userId}:{tenantId}:{resource}:{action}`
  - 配置：`config/casbin.php`（`cache_enabled` / `cache_ttl` / 降级日志）
  - 缓存降级：Redis 失败时降级为直接调用 Casbin 引擎，并记录降级日志
- 适配层：`infrastructure/Permission/Casbin/DatabaseAdapter.php`
  - 读取 DB 表 `user_roles` / `role_permissions` / `permissions`，无显式租户缓存命名空间
- 性能目标（审查报告）：权限查询 P95 < 100ms，命中率 > 90%，并发 > 1000

## 问题/风险

- 缓存命名空间未明确区分环境/租户/站点（现有键包含 tenantId，但缺少站点/环境前缀）。
- 监控缺口：暂无统一指标输出（命中率、延迟、降级次数）。
- 基准测试缺口：缺少性能基准脚本与数据集。

## 优化计划

1. **缓存命名空间强化（B4）**
   - 增加可配置的全局前缀：`casbin:{env}:{tenant}:{site}:{resource}:{action}`，默认 `env` 来源 `APP_ENV`。
   - 若 `site_id` 在上下文中可获取，则纳入缓存键，避免跨站点污染。
2. **多级缓存与失效**
   - 维持 Redis 结果缓存；评估是否需要本地（进程内）短 TTL 二级缓存。
   - 对 RBAC 变更事件（角色/权限/用户绑定）触发 cache busting。
3. **监控与指标（B6）**
   - 暴露命中率、P95 延迟、降级次数（可写入日志并导出到监控）。
   - 在降级时标记 trace_id，便于故障排查。
4. **基准测试（B5）**
   - 新增 Benchmark：构造 1k/10k 权限规模，测试 P50/P95/P99。
   - 并发压测（本地/CI 可选步骤）验证 >1000 QPS 能力。

## 下一步动作

- 设计缓存键变更与配置项（新增 `casbin.cache_prefix`、可选 `site_id`）。
- 编写性能基准脚本与测试用例，纳入 CI 可选 job。
- 更新 Backlog：B4（设计）完成后进入实现阶段；B5/B6 待落地。
