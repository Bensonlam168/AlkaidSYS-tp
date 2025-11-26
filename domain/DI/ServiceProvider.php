<?php

declare(strict_types=1);

namespace Domain\DI;

use Infrastructure\DI\AbstractServiceProvider;
use think\App;

/**
 * Service Provider Base Class | 服务提供者基类
 *
 * Base class for all service providers in the system, especially for plugins.
 * Extends AbstractServiceProvider to support deferred loading and provides
 * backward compatibility with existing service providers.
 * 系统中所有服务提供者的基类，特别用于插件。
 * 继承 AbstractServiceProvider 以支持延迟加载，并提供与现有服务提供者的向后兼容性。
 *
 * @package Domain\DI
 */
abstract class ServiceProvider extends AbstractServiceProvider
{
    /**
     * {@inheritdoc}
     *
     * Override this method to register services into the container.
     * 重写此方法以将服务注册到容器中。
     */
    public function register(App $app): void
    {
        // Default implementation - override in subclasses
    }

    /**
     * {@inheritdoc}
     *
     * Override this method to bootstrap services after all providers are registered.
     * 重写此方法以在所有提供者注册后启动服务。
     */
    public function boot(App $app): void
    {
        // Default implementation - override in subclasses
    }
}
