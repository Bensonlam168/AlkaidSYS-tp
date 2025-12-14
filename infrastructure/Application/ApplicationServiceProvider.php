<?php

declare(strict_types=1);

namespace Infrastructure\Application;

use Infrastructure\DI\AbstractServiceProvider;
use think\App;

/**
 * Application Service Provider | 应用服务提供者
 *
 * Registers application system services with the DI container.
 * Uses the service provider mechanism from T-031.
 * 将应用系统服务注册到 DI 容器。
 * 使用 T-031 的服务提供者机制。
 *
 * @package Infrastructure\Application
 * @see design/02-app-plugin-ecosystem/06-1-application-system-design.md
 */
class ApplicationServiceProvider extends AbstractServiceProvider
{
    /**
     * Whether the provider is deferred | 是否延迟加载
     *
     * Set to true to delay loading until ApplicationManager is requested.
     * 设置为 true 以延迟加载，直到请求 ApplicationManager。
     */
    protected bool $defer = true;

    /**
     * List of services provided by this provider | 此提供者提供的服务列表
     *
     * @var array<string>
     */
    protected array $provides = [
        ApplicationManager::class,
        'application.manager',
    ];

    /**
     * Register services | 注册服务
     *
     * @param App $app Application instance | 应用实例
     * @return void
     */
    public function register(App $app): void
    {
        // Register ApplicationManager as singleton
        $this->singleton($app, ApplicationManager::class, function (App $app) {
            $basePath = $app->getRootPath() . 'addons/apps';
            return new ApplicationManager($basePath);
        });

        // Create alias for easier access
        $this->alias($app, ApplicationManager::class, 'application.manager');
    }

    /**
     * Boot services | 启动服务
     *
     * Called after all providers have been registered.
     * 在所有提供者注册后调用。
     *
     * @param App $app Application instance | 应用实例
     * @return void
     */
    public function boot(App $app): void
    {
        // Discover and register enabled applications
        /** @var ApplicationManager $manager */
        $manager = $app->make(ApplicationManager::class);

        // Auto-discover applications from the addons/apps directory
        $discovered = $manager->discover();

        // Register application classes for lazy loading
        foreach ($discovered as $key => $info) {
            $applicationFile = $info['path'] . '/Application.php';
            if (file_exists($applicationFile)) {
                $namespace = 'addon\\' . $key;
                $className = $namespace . '\\Application';
                $manager->registerClass($key, $className);
            }
        }
    }
}
