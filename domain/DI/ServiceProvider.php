<?php

declare(strict_types=1);

namespace Domain\DI;

use think\Service;

/**
 * Service Provider Base Class | 服务提供者基类
 * 
 * Base class for all service providers in the system, especially for plugins.
 * 系统中所有服务提供者的基类，特别用于插件。
 * 
 * @package Domain\DI
 */
abstract class ServiceProvider extends Service
{
    /**
     * Register services | 注册服务
     *
     * @return void
     */
    public function register()
    {
        // Default implementation
    }

    /**
     * Boot services | 启动服务
     *
     * @return void
     */
    public function boot()
    {
        // Default implementation
    }
}
