<?php

declare(strict_types=1);

namespace Infrastructure\DI;

use think\App;

/**
 * Abstract Service Provider | 抽象服务提供者
 *
 * Base class for service providers that supports deferred loading.
 * 服务提供者基类，支持延迟加载。
 *
 * @package Infrastructure\DI
 */
abstract class AbstractServiceProvider implements ServiceProviderInterface
{
    /**
     * Whether the provider is deferred | 是否延迟加载
     *
     * Set to true to delay loading until a provided service is requested.
     * 设置为 true 以延迟加载，直到请求其提供的服务。
     *
     * @var bool
     */
    protected bool $defer = false;

    /**
     * List of services provided by this provider | 此提供者提供的服务列表
     *
     * Only used when $defer is true. These service identifiers will trigger
     * loading of this provider when requested from the container.
     * 仅在 $defer 为 true 时使用。当从容器请求这些服务标识符时，将触发加载此提供者。
     *
     * @var array<string>
     */
    protected array $provides = [];

    /**
     * {@inheritdoc}
     */
    public function isDeferred(): bool
    {
        return $this->defer;
    }

    /**
     * {@inheritdoc}
     */
    public function provides(): array
    {
        return $this->provides;
    }

    /**
     * {@inheritdoc}
     */
    abstract public function register(App $app): void;

    /**
     * {@inheritdoc}
     *
     * Default implementation does nothing. Override if needed.
     * 默认实现不执行任何操作。如需要请重写。
     */
    public function boot(App $app): void
    {
        // Default implementation - override in subclasses if needed
    }

    /**
     * Bind a service to the container | 绑定服务到容器
     *
     * Helper method to simplify service binding.
     * 辅助方法，简化服务绑定。
     *
     * @param App    $app      Application instance | 应用实例
     * @param string $abstract Service identifier | 服务标识符
     * @param mixed  $concrete Implementation (class name, closure, or instance) | 实现（类名、闭包或实例）
     * @return void
     */
    protected function bind(App $app, string $abstract, mixed $concrete): void
    {
        $app->bind($abstract, $concrete);
    }

    /**
     * Bind a singleton service to the container | 绑定单例服务到容器
     *
     * The service will be instantiated only once and reused.
     * 服务只会被实例化一次并重复使用。
     *
     * @param App    $app      Application instance | 应用实例
     * @param string $abstract Service identifier | 服务标识符
     * @param mixed  $concrete Implementation (class name, closure, or instance) | 实现（类名、闭包或实例）
     * @return void
     */
    protected function singleton(App $app, string $abstract, mixed $concrete): void
    {
        if ($concrete instanceof \Closure) {
            $app->bind($abstract, function ($app) use ($concrete) {
                static $instance = null;
                if ($instance === null) {
                    $instance = $concrete($app);
                }
                return $instance;
            });
        } else {
            $app->bind($abstract, $concrete);
        }
    }

    /**
     * Alias a service in the container | 为容器中的服务创建别名
     *
     * @param App    $app      Application instance | 应用实例
     * @param string $abstract Original service identifier | 原始服务标识符
     * @param string $alias    Alias name | 别名
     * @return void
     */
    protected function alias(App $app, string $abstract, string $alias): void
    {
        $app->bind($alias, $abstract);
    }
}
