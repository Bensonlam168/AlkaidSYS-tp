# 插件目录（T-035）

本目录用于存放 **后端插件包**（Phase 2 基础版）。

## 约定

- 插件建议使用命名空间：`plugin\\{plugin_key}`（例如：`plugin\\payment_wechat`）。
- 插件主类建议命名为 `Plugin`，并继承 `Infrastructure\\Plugin\\CorePluginBaseService`。
- 插件注册的 Action/Filter 钩子可通过 `hook()` / `do_action()` / `apply_filter()` 进行注册与触发。

## 配置加载

- 插件清单配置：`config/plugin.php`
- 启动时会在 `app/AppService.php` 中调用 `PluginManager::loadFromConfig()` 完成加载（启用列表为空时不会产生副作用）。

