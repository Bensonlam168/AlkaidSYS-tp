<?php

declare(strict_types=1);

/**
 * Casbin 授权引擎配置
 * Casbin Authorization Engine Configuration
 *
 * 本配置文件定义了 Casbin 授权引擎的各项参数，包括模型路径、适配器、缓存等。
 * This configuration file defines the parameters for Casbin authorization engine,
 * including model path, adapter, cache, etc.
 *
 * @see https://casbin.org/
 * @see https://github.com/php-casbin/php-casbin
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Casbin 模型文件路径
    | Casbin Model File Path
    |--------------------------------------------------------------------------
    |
    | Casbin 模型文件定义了访问控制模型（RBAC, ABAC, ACL 等）。
    | 当前使用 RBAC with Domains 模型以支持多租户隔离。
    |
    | The Casbin model file defines the access control model (RBAC, ABAC, ACL, etc.).
    | Currently using RBAC with Domains model to support multi-tenancy isolation.
    |
    */
    'model_path' => config_path() . 'casbin-model.conf',

    /*
    |--------------------------------------------------------------------------
    | 适配器配置
    | Adapter Configuration
    |--------------------------------------------------------------------------
    |
    | 适配器负责从数据源加载和保存策略。
    | 当前使用自定义 DatabaseAdapter，从现有 RBAC 表加载策略。
    |
    | The adapter is responsible for loading and saving policies from data source.
    | Currently using custom DatabaseAdapter to load policies from existing RBAC tables.
    |
    */
    'adapter' => [
        // 适配器类型 | Adapter type
        'type' => 'database',

        // 适配器类 | Adapter class
        'class' => \Infrastructure\Permission\Casbin\DatabaseAdapter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | 日志配置
    | Log Configuration
    |--------------------------------------------------------------------------
    |
    | 是否启用 Casbin 日志记录。
    | 开发环境建议启用，生产环境建议关闭以提高性能。
    |
    | Whether to enable Casbin logging.
    | Recommended to enable in development, disable in production for performance.
    |
    */
    'log_enabled' => env('CASBIN_LOG_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | 缓存配置
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Casbin 内置缓存机制，可以缓存策略检查结果以提高性能。
    | 建议在生产环境启用缓存。
    |
    | Casbin built-in cache mechanism can cache policy check results to improve performance.
    | Recommended to enable cache in production.
    |
    */
    'cache_enabled' => env('CASBIN_CACHE_ENABLED', true),

    // 缓存过期时间（秒）| Cache TTL (seconds)
    'cache_ttl' => env('CASBIN_CACHE_TTL', 3600),

    // 策略缓存配置 | Policy cache configuration
    // 是否启用策略缓存（将完整策略缓存到 Redis）| Enable policy cache (cache full policies to Redis)
    'policy_cache_enabled' => env('CASBIN_POLICY_CACHE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | 缓存降级配置
    | Cache Degradation Configuration
    |--------------------------------------------------------------------------
    |
    | 当 Redis 缓存故障时，自动降级到直接调用 Casbin 引擎。
    | 降级机制确保系统在缓存故障时仍能正常工作。
    |
    | When Redis cache fails, automatically degrade to direct Casbin engine call.
    | Degradation mechanism ensures system works normally when cache fails.
    |
    */
    // 是否启用缓存降级 | Enable cache degradation
    'cache_degradation_enabled' => env('CASBIN_CACHE_DEGRADATION_ENABLED', true),

    // 是否记录降级日志 | Enable degradation logging
    'cache_degradation_log_enabled' => env('CASBIN_CACHE_DEGRADATION_LOG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | 策略刷新配置
    | Policy Reload Configuration
    |--------------------------------------------------------------------------
    |
    | 策略刷新间隔时间（秒）。
    | 当 RBAC 表更新后，Casbin 需要重新加载策略。
    | 设置为 0 表示不自动刷新，需要手动调用刷新 API。
    |
    | Policy reload interval (seconds).
    | When RBAC tables are updated, Casbin needs to reload policies.
    | Set to 0 to disable auto-reload, requiring manual API call.
    |
    */
    'reload_ttl' => env('CASBIN_RELOAD_TTL', 300), // 5 分钟 | 5 minutes

    /*
    |--------------------------------------------------------------------------
    | 运行模式
    | Running Mode
    |--------------------------------------------------------------------------
    |
    | Casbin 运行模式：
    | - DB_ONLY: 仅使用数据库 RBAC（Phase 1，默认）
    | - CASBIN_ONLY: 仅使用 Casbin（Phase 2 目标）
    | - DUAL_MODE: 双模式运行，对比验证（过渡期）
    |
    | Casbin running mode:
    | - DB_ONLY: Use database RBAC only (Phase 1, default)
    | - CASBIN_ONLY: Use Casbin only (Phase 2 target)
    | - DUAL_MODE: Dual mode, compare and validate (transition period)
    |
    */
    'mode' => env('CASBIN_MODE', 'DB_ONLY'),

    /*
    |--------------------------------------------------------------------------
    | 启用状态
    | Enable Status
    |--------------------------------------------------------------------------
    |
    | 是否启用 Casbin 授权引擎。
    | 设置为 false 时，将使用原有的数据库 RBAC 实现。
    |
    | Whether to enable Casbin authorization engine.
    | When set to false, will use the original database RBAC implementation.
    |
    */
    'enabled' => env('CASBIN_ENABLED', false),
];
