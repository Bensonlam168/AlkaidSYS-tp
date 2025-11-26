<?php

declare(strict_types=1);

namespace Infrastructure\DI;

use think\App;

/**
 * Dependency Manager | 依赖管理器
 *
 * Manages dynamic registration of service providers for plugins and modules.
 * Provides unified interface for service provider management with support for
 * deferred loading, auto-discovery, and configuration-driven registration.
 * 管理插件和模块服务提供者的动态注册。提供统一的服务提供者管理接口，支持延迟加载、自动发现和配置驱动注册。
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
     * Service provider manager instance | 服务提供者管理器实例
     * @var ServiceProviderManager|null
     */
    protected ?ServiceProviderManager $providerManager = null;

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
     * Get the service provider manager | 获取服务提供者管理器
     *
     * @return ServiceProviderManager
     */
    public function getProviderManager(): ServiceProviderManager
    {
        if ($this->providerManager === null) {
            $this->providerManager = new ServiceProviderManager($this->app);
        }
        return $this->providerManager;
    }

    /**
     * Register a ThinkPHP service provider (legacy method) | 注册 ThinkPHP 服务提供者（遗留方法）
     *
     * @param string $providerClass Provider class name | 提供者类名
     * @return void
     * @deprecated Use registerServiceProvider() for new ServiceProviderInterface implementations
     */
    public function registerProvider(string $providerClass): void
    {
        if (class_exists($providerClass)) {
            $this->app->register($providerClass);
        }
    }

    /**
     * Register multiple ThinkPHP service providers (legacy method) | 注册多个 ThinkPHP 服务提供者（遗留方法）
     *
     * @param array<string> $providers Array of provider class names | 提供者类名数组
     * @return void
     * @deprecated Use registerServiceProviders() for new ServiceProviderInterface implementations
     */
    public function registerProviders(array $providers): void
    {
        foreach ($providers as $provider) {
            $this->registerProvider($provider);
        }
    }

    /**
     * Register a service provider implementing ServiceProviderInterface | 注册实现 ServiceProviderInterface 的服务提供者
     *
     * @param class-string<ServiceProviderInterface>|ServiceProviderInterface $provider Provider class or instance
     * @return ServiceProviderInterface
     */
    public function registerServiceProvider(string|ServiceProviderInterface $provider): ServiceProviderInterface
    {
        return $this->getProviderManager()->register($provider);
    }

    /**
     * Register multiple service providers implementing ServiceProviderInterface | 注册多个实现 ServiceProviderInterface 的服务提供者
     *
     * @param array<class-string<ServiceProviderInterface>> $providers Array of provider class names
     * @return void
     */
    public function registerServiceProviders(array $providers): void
    {
        $this->getProviderManager()->registerMany($providers);
    }

    /**
     * Register providers from configuration | 从配置注册提供者
     *
     * Expects a config array like: ['providers' => [ProviderClass1::class, ProviderClass2::class]]
     * 期望配置数组格式: ['providers' => [ProviderClass1::class, ProviderClass2::class]]
     *
     * @param array<string, mixed> $config Configuration array
     * @return void
     */
    public function registerFromConfig(array $config): void
    {
        $providers = $config['providers'] ?? [];
        if (!empty($providers)) {
            $this->registerServiceProviders($providers);
        }
    }

    /**
     * Boot all registered service providers | 启动所有已注册的服务提供者
     *
     * @return void
     */
    public function boot(): void
    {
        $this->getProviderManager()->boot();
    }

    /**
     * Load a deferred service provider | 加载延迟服务提供者
     *
     * @param string $service Service identifier that triggers the load
     * @return bool Whether a deferred provider was loaded
     */
    public function loadDeferredService(string $service): bool
    {
        return $this->getProviderManager()->loadDeferred($service);
    }

    /**
     * Check if a service has a deferred provider | 检查服务是否有延迟提供者
     *
     * @param string $service Service identifier
     * @return bool
     */
    public function isDeferredService(string $service): bool
    {
        return $this->getProviderManager()->isDeferredService($service);
    }

    /**
     * Check if a provider class is loaded | 检查提供者类是否已加载
     *
     * @param class-string<ServiceProviderInterface> $providerClass Provider class name
     * @return bool
     */
    public function isProviderLoaded(string $providerClass): bool
    {
        return $this->getProviderManager()->isLoaded($providerClass);
    }

    /**
     * Get the application instance | 获取应用实例
     *
     * @return App
     */
    public function getApp(): App
    {
        return $this->app;
    }
}
