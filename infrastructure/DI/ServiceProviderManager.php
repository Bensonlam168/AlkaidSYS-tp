<?php

declare(strict_types=1);

namespace Infrastructure\DI;

use think\App;

/**
 * Service Provider Manager | 服务提供者管理器
 *
 * Manages registration, booting, and deferred loading of service providers.
 * 管理服务提供者的注册、启动和延迟加载。
 *
 * @package Infrastructure\DI
 */
class ServiceProviderManager
{
    /**
     * Application instance | 应用实例
     * @var App
     */
    protected App $app;

    /**
     * Registered (non-deferred) providers | 已注册的（非延迟）提供者
     * @var array<ServiceProviderInterface>
     */
    protected array $providers = [];

    /**
     * Mapping of service identifiers to deferred provider classes | 服务标识符到延迟提供者类的映射
     * @var array<string, class-string<ServiceProviderInterface>>
     */
    protected array $deferredProviders = [];

    /**
     * Classes of providers that have been loaded | 已加载的提供者类
     * @var array<class-string<ServiceProviderInterface>, bool>
     */
    protected array $loadedProviders = [];

    /**
     * Whether providers have been booted | 提供者是否已启动
     * @var bool
     */
    protected bool $booted = false;

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
     * @param class-string<ServiceProviderInterface>|ServiceProviderInterface $provider Provider class or instance
     * @return ServiceProviderInterface The registered provider instance
     */
    public function register(string|ServiceProviderInterface $provider): ServiceProviderInterface
    {
        // Resolve provider instance
        if (is_string($provider)) {
            $providerClass = $provider;
            if (!class_exists($providerClass)) {
                throw new \InvalidArgumentException("Provider class not found: {$providerClass}");
            }
            $provider = new $providerClass();
        } else {
            $providerClass = get_class($provider);
        }

        // Check if already loaded
        if (isset($this->loadedProviders[$providerClass])) {
            return $provider;
        }

        // Handle deferred providers
        if ($provider->isDeferred()) {
            $this->registerDeferredProvider($providerClass, $provider->provides());
            return $provider;
        }

        // Register immediately
        $provider->register($this->app);
        $this->providers[] = $provider;
        $this->loadedProviders[$providerClass] = true;

        // Boot if already in boot phase
        if ($this->booted) {
            $provider->boot($this->app);
        }

        return $provider;
    }

    /**
     * Register a deferred provider | 注册延迟加载的提供者
     *
     * @param class-string<ServiceProviderInterface> $providerClass Provider class name
     * @param array<string> $services Services provided by this provider
     * @return void
     */
    protected function registerDeferredProvider(string $providerClass, array $services): void
    {
        foreach ($services as $service) {
            $this->deferredProviders[$service] = $providerClass;
        }
    }

    /**
     * Boot all registered providers | 启动所有已注册的提供者
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->boot($this->app);
        }

        $this->booted = true;
    }

    /**
     * Load a deferred provider for a specific service | 为特定服务加载延迟提供者
     *
     * @param string $service Service identifier | 服务标识符
     * @return bool Whether a deferred provider was loaded | 是否加载了延迟提供者
     */
    public function loadDeferred(string $service): bool
    {
        if (!isset($this->deferredProviders[$service])) {
            return false;
        }

        $providerClass = $this->deferredProviders[$service];

        if (isset($this->loadedProviders[$providerClass])) {
            return true;
        }

        $provider = new $providerClass();
        $provider->register($this->app);
        $provider->boot($this->app);

        $this->loadedProviders[$providerClass] = true;
        $this->providers[] = $provider;

        return true;
    }

    /**
     * Register multiple providers at once | 批量注册多个提供者
     *
     * @param array<class-string<ServiceProviderInterface>> $providers Array of provider class names
     * @return void
     */
    public function registerMany(array $providers): void
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    /**
     * Register providers from a configuration array | 从配置数组注册提供者
     *
     * @param array<class-string<ServiceProviderInterface>> $config Configuration array of provider classes
     * @return void
     */
    public function registerFromConfig(array $config): void
    {
        $this->registerMany($config);
    }

    /**
     * Get all registered (non-deferred) providers | 获取所有已注册的（非延迟）提供者
     *
     * @return array<ServiceProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get all deferred provider mappings | 获取所有延迟提供者映射
     *
     * @return array<string, class-string<ServiceProviderInterface>>
     */
    public function getDeferredProviders(): array
    {
        return $this->deferredProviders;
    }

    /**
     * Check if a provider class is registered/loaded | 检查提供者类是否已注册/加载
     *
     * @param class-string<ServiceProviderInterface> $providerClass Provider class name
     * @return bool
     */
    public function isLoaded(string $providerClass): bool
    {
        return isset($this->loadedProviders[$providerClass]);
    }

    /**
     * Check if boot phase has completed | 检查启动阶段是否已完成
     *
     * @return bool
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Check if a service has a deferred provider | 检查服务是否有延迟提供者
     *
     * @param string $service Service identifier
     * @return bool
     */
    public function isDeferredService(string $service): bool
    {
        return isset($this->deferredProviders[$service]);
    }
}
