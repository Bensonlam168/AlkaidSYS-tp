# 插件系统基础（T-035）

## 目标

在不引入“插件包下载/签名/隔离加载”等复杂能力的前提下，先落地 **最小可用** 的后端插件系统基础设施：

- 插件生命周期：install / enable / disable / uninstall / upgrade
- 钩子机制：Action / Filter（支持优先级）
- 与 DI 的联动：通过 `DependencyManager` 注册插件 ServiceProvider
- CLI 基础命令：查看/启用/禁用/扫描落库

## 目录结构

- 插件目录：`addons/plugins/`
- 插件命名空间建议：`plugin\\{plugin_key}`
- 插件主类建议：`plugin\\{plugin_key}\\Plugin`

## 插件清单（plugin.json）

插件包通过 `addons/plugins/**/plugin.json` 描述（最小字段约定）：

- `key`：插件唯一标识（建议：`[a-z0-9_]+`，且不以数字开头）
- `name`：展示名称（可选）
- `version`：版本号（可选，默认 `0.0.0`）
- `category`：分类（可选）
- `app_key`：应用专属插件可选（可选）
- `class`：插件主类（可选；未提供时回退为 `plugin\\{plugin_key}\\Plugin`）

## 配置

插件清单由 `config/plugin.php` 提供：

- `plugin.plugins`：显式声明插件（推荐，避免扫描）
- `plugin.enabled`：需要默认启用的插件 key 列表

应用启动时由 `app/AppService.php` 调用 `Infrastructure\\Plugin\\PluginManager::loadFromConfig()` 完成加载：

1. 先按 `plugin.plugins` 注册（显式声明，优先级更高）
2. 再尝试从插件包表注册（若已执行扫描落库）
3. 最后按 `plugin.enabled` 默认启用

> 说明：当 DB 表尚未迁移时，启动期会降级跳过“状态同步/包表注册”，避免阻断应用启动。

## 钩子 API

在 `app/common.php` 中提供全局函数（Action/Filter）：

- `hook($tag, $callback, $priority = 10, $type = 'action')`
- `do_action($tag, ...$args)`
- `apply_filter($tag, $value, ...$args)`

对应实现：`infrastructure/Plugin/Hook/HookManager.php`

### 钩子执行行为（T-052 增强）

- 优先级：数字越小越先执行，维持 Action/Filter 兼容现有接口。
- 停止传播：回调返回 `false` 或 `HookResult::stop($value)` 将终止后续回调（Filter 会返回 stop 内携带的值）。
- 上下文透传：回调参数数量多于调用时入参或为可变参数时，将自动注入 `Domain\Event\EventContext`，包含 `trace_id/tenant_id/site_id/user_id`。
- 异步执行：`HookManager::doActionAsync()` 队列入库，`doActionAsyncCoroutine()` 在 Swoole `go()` 下直接调度，无协程时自动回退队列。
- 监控/告警：耗时与失败会落库 `hook_metrics`（缺表时降级日志 `hook.metrics`），失败重试超过阈值写入 `hook_dead_letters` 并通过 `hook.alert.*` 产生日志/告警；阈值配置见 `config/hook.php`。
- 数据表：`hook_metrics`（耗时/失败）、`hook_dead_letters`（超过重试阈值的任务）、`hook_alerts`（慢钩子/失败率/DLQ 告警）。缺表时自动降级日志。

## 数据表

迁移文件：`database/migrations/20251217000002_create_plugin_packages_and_installations_tables.php`

- `alkaid_plugin_packages`：插件包清单（扫描结果落库）
  - `plugin_key`：插件 key
  - `main_class`：插件主类
  - `base_path`：插件目录路径（相对项目根，例如 `addons/plugins/demo`）
  - `manifest_path`：清单文件路径（相对项目根，例如 `addons/plugins/demo/plugin.json`）
  - `manifest`：完整的 `plugin.json`
- `alkaid_plugin_installations`：插件安装/启用状态（租户/站点维度）
  - `(tenant_id, site_id, plugin_key)` 唯一
  - `status`：`installed/enabled/disabled/uninstalled`

## ORM 与低代码约束（T-048 对齐）

- 动态业务实体必须使用 `Domain\\Lowcode` + `Infrastructure\\Lowcode`，禁止新增 Legacy Collection 依赖。
- 运行期 DDL 统一通过 `Infrastructure\\Schema\\SchemaBuilder` 执行，要求幂等/可回滚，避免重复执行破坏数据。
- 字段定义以 `FieldFactory` + `FieldInterface::getDbColumn()` 作为唯一映射出口，默认值/更新时间等字段约束必须体现在列定义中。
- 多租户字段 `tenant_id/site_id` 与默认索引必须保留，插件相关扩展表不得绕过租户隔离规范。
- 迁移 diff 输出需保持幂等（如 `IF NOT EXISTS/IF EXISTS`），并记录必要的审计与回滚路径。

## 事件系统接入指引（T-049 对齐）

- **优先级监听**：使用 `Domain\Event\EventService::listenWithPriority($event, $listener, $priority)`，数字越小优先级越高。
- **停止传播**：监听器返回 `false` 或 `EventResult::stop()`，或在回调中调用 `$payload->stopPropagation()`。
- **上下文透传**：监听器可接受第二参数 `EventPayload` 访问 `trace_id/tenant_id/site_id/user_id`。
- **异步派发**：
  - 队列：`triggerAsync($event, $params, $context, $queue)`，上下文自动序列化。
  - 协程：`triggerAsyncCoroutine($event, $params, $context, $runner)`，默认使用 Swoole `go()`，无协程时回退队列。
- **失败重试/死信**：队列执行异常重试 3 次后删除并记录 DLQ（错误码 2001/2002 语义），日志键 `event.dlq` 便于告警订阅。
- **失败可观测（ER-13）**：
  - 同步监听器异常记录 `event.listener.failed`（error 级），字段含 `event/listener/trace_id/tenant_id/site_id/user_id/exception/message/mode/phase/listener_key`；默认**不中断业务、也不阻断同事件的后续监听器**。
  - 严格模式：`EVENT_FAIL_FAST=true` 时监听器异常原样上抛，仅供测试与排障；默认 false，避免插件写错把主流程变成 500。
  - 异步失败记录 `event.async.failed`（warning，可重试）与 `event.dlq`（error，进入死信）；DLQ 写失败兜底 `event.dlq.record_failed`，日志系统本身故障时再降级 `error_log()`，保证事件不会「失败且无痕迹」。
- **审计/指标**：同步触发记录 `duration_ms/failed/trace_id/tenant/site/user`（默认写入 `event_metrics` 表，表缺失时降级 `event.metrics` 日志）；死信写入 `event_dead_letters` 表或日志键 `event.dlq`；慢事件与失败率阈值可通过 `config/event.php`（或环境变量 `EVENT_MONITOR_*`）配置。
- **PSR-14 兼容**：使用 `Domain\Event\PsrEventDispatcher` 作为桥接器，可将内部 EventService 提供给外部需要 PSR-14 Dispatcher/ListenerProvider 的组件。

### 监控与告警接入（Grafana/日志订阅）

**1) 日志订阅（推荐上报到 ELK/Loki）**
- 关键日志键：`event.alert.*`（慢事件/失败/失败率/DLQ）、`event.async.failed`（异步执行失败）、`event.dlq`（死信原始记录）
- 项目内已提供 `event_alert` 日志通道（JSON 格式），可直接采集 `runtime/log` 下对应文件。

**2) Grafana/SQL 报表（直接查询 DB）**
- 指标表：`event_metrics`（耗时/失败/上下文）
- 死信表：`event_dead_letters`（失败超过阈值的任务）
- 告警表：`event_alerts`（event.alert.* 的 DB 级订阅）

示例 SQL（MySQL）：
```sql
-- 近 1 小时按事件聚合：P95 耗时、失败率（需要 MySQL 8.0）
SELECT
  event,
  COUNT(*) AS total,
  SUM(failed) AS failed_count,
  ROUND(SUM(failed) / COUNT(*), 4) AS failure_rate,
  ROUND(PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms) OVER (PARTITION BY event), 2) AS p95_ms
FROM event_metrics
WHERE created_at >= NOW() - INTERVAL 1 HOUR;

-- 近 24 小时 DLQ 统计
SELECT
  event,
  code,
  COUNT(*) AS dlq_count
FROM event_dead_letters
WHERE created_at >= NOW() - INTERVAL 1 DAY
GROUP BY event, code
ORDER BY dlq_count DESC;

-- 最近 100 条告警（DB 级订阅）
SELECT *
FROM event_alerts
ORDER BY id DESC
LIMIT 100;
```

### 使用示例
```php
$service = new \Domain\Event\EventService();
$ctx = new \Domain\Event\EventContext('trace-1', 100, 1, 9);

// 注册优先级监听
$service->listenWithPriority('order.created', function ($data, $payload) {
    // 业务处理
    if ($data['should_stop'] ?? false) {
        return \Domain\Event\EventResult::stop();
    }
}, 5);

// 同步触发（携带上下文）
$service->trigger('order.created', ['should_stop' => true], $ctx);

// 异步（队列）触发
$service->triggerAsync('order.created', ['id' => 1], $ctx, 'events');

// 协程触发（Swoole go 可用时）
$service->triggerAsyncCoroutine('order.created', ['id' => 2], $ctx);
```

## CLI 命令

已注册命令（见 `config/console.php`）：

- `php think plugin:list`
- `php think plugin:enable {key}`
- `php think plugin:disable {key}`
- `php think plugin:scan`

可选参数（多租户维度）：

- `--tenant-id=0` / `--site-id=0`（默认 0/0）

> 注意：当前运行时插件实例仍是进程级共享对象；`tenant/site` 维度用于“状态持久化落库”，后续若需要按请求隔离加载，需要引入租户上下文与按需装配机制。

## 后续扩展（非 T-035 范围）

- 插件包下载/签名校验、升级/回滚
- Hook 注销、Hook 性能指标与审计
- 前端 iframe/组件加载器（组 E / 阶段 5）
