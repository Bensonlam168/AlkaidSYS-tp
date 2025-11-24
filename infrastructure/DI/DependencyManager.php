<?php

declare(strict_types=1);

namespace Infrastructure\DI;

use think\App;

/**
 * Dependency Manager | 依赖管理器
 * 
 * Manages dynamic registration of service providers for plugins.
 * 管理插件服务提供者的动态注册。
 * 
 * @package Infrastructure\DI
 */
class DependencyManager
{
    /**
     * Application instance | 应用实例
     * @var App
     */
    protected App $app;

    /**
     * Constructor | 构造函数
     *
     * @param App $app Application instance | 应用实例
     */
    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Register a service provider | 注册服务提供者
     *
     * @param string $providerClass Provider class name | 提供者类名
     * @return void
     */
    public function registerProvider(string $providerClass): void
    {
        if (class_exists($providerClass)) {
            $this->app->register($providerClass);
        }
    }

    /**
     * Register multiple service providers | 注册多个服务提供者
     *
     * @param array $providers Array of provider class names | 提供者类名数组
     * @return void
     */
    public function registerProviders(array $providers): void
    {
        foreach ($providers as $provider) {
            $this->registerProvider($provider);
        }
    }
}
