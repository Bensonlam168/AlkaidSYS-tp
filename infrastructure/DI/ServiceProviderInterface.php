<?php

declare(strict_types=1);

namespace Infrastructure\DI;

use think\App;

/**
 * Service Provider Interface | 服务提供者接口
 *
 * Defines the contract for service providers that register and bootstrap services.
 * 定义服务提供者的契约，用于注册和启动服务。
 *
 * @package Infrastructure\DI
 */
interface ServiceProviderInterface
{
    /**
     * Register services into the container | 注册服务到容器
     *
     * This method is called during application initialization.
     * Use it to bind interfaces to implementations.
     * 此方法在应用初始化期间调用。用于绑定接口到实现。
     *
     * @param App $app Application instance | 应用实例
     * @return void
     */
    public function register(App $app): void;

    /**
     * Bootstrap services after all providers are registered | 在所有提供者注册后启动服务
     *
     * This method is called after all providers have registered.
     * Use it for any bootstrapping logic that depends on other services.
     * 此方法在所有提供者注册完成后调用。用于依赖其他服务的启动逻辑。
     *
     * @param App $app Application instance | 应用实例
     * @return void
     */
    public function boot(App $app): void;

    /**
     * Check if the provider is deferred | 检查提供者是否延迟加载
     *
     * Deferred providers are only loaded when one of their services is requested.
     * 延迟加载的提供者仅在其服务被请求时才加载。
     *
     * @return bool
     */
    public function isDeferred(): bool;

    /**
     * Get the services provided by this provider | 获取此提供者提供的服务
     *
     * Only used for deferred providers to know which services trigger loading.
     * 仅用于延迟加载的提供者，以确定哪些服务会触发加载。
     *
     * @return array<string> List of service identifiers | 服务标识符列表
     */
    public function provides(): array;
}
