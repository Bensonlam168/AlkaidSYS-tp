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
