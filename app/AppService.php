<?php

declare (strict_types=1);

namespace app;

use think\Service;
use Infrastructure\Plugin\PluginManager;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        // 服务注册
    }

    public function boot()
    {
        // 插件系统基础：启动时加载已配置/启用的插件（T-035）
        // 注意：本阶段不做目录扫描与下载，仅支持 config/plugin.php 声明的插件清单
        $pluginManager = new PluginManager($this->app);
        $pluginManager->loadFromConfig();
        $this->app->instance(PluginManager::class, $pluginManager);
    }
}
