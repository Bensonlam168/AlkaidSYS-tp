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

        // 应用系统：注册并加载延迟服务提供者（A5）
        // 说明：ApplicationServiceProvider 继承项目自有的 Infrastructure\DI\AbstractServiceProvider，
        // 并非 think\Service 子类，不能写入 app/service.php，只能在容器就绪后手动接线。
        // provider 接线失败不得影响主流程（避免全站 500），故整体兜底为告警日志。
        try {
            $providerManager = new \Infrastructure\DI\ServiceProviderManager($this->app);
            // 延迟提供者：register() 仅登记服务映射，不立即执行其 register()
            $providerManager->register(\Infrastructure\Application\ApplicationServiceProvider::class);

            // 按服务名触发延迟加载；返回 false 表示该服务未登记，记录告警但不抛异常
            if (!$providerManager->loadDeferred(\Infrastructure\Application\ApplicationManager::class)) {
                \think\facade\Log::warning('应用服务提供者延迟加载未命中', [
                    'provider' => \Infrastructure\Application\ApplicationServiceProvider::class,
                    'service'  => \Infrastructure\Application\ApplicationManager::class,
                ]);
            }

            $this->app->instance(\Infrastructure\DI\ServiceProviderManager::class, $providerManager);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('应用服务提供者注册失败，已跳过（不影响主流程）', [
                'provider' => \Infrastructure\Application\ApplicationServiceProvider::class,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
