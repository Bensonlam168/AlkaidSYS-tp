<?php

// +----------------------------------------------------------------------
// | 权限服务配置 | Permission Service Configuration
// +----------------------------------------------------------------------

return [
    // 缓存配置 | Cache Configuration
    'cache' => [
        // 是否启用缓存 | Enable cache
        'enabled' => env('PERMISSION_CACHE_ENABLED', true),

        // L1 缓存 TTL（秒）| L1 cache TTL (seconds)
        'l1_ttl' => (int) env('PERMISSION_CACHE_L1_TTL', 60),

        // L2 缓存 TTL（秒）| L2 cache TTL (seconds)
        'l2_ttl' => (int) env('PERMISSION_CACHE_L2_TTL', 300),

        // 缓存前缀 | Cache prefix
        'prefix' => env('PERMISSION_CACHE_PREFIX', 'permission:'),

        // 最大延迟历史记录大小（用于 P95 计算）| Max latency history size (for P95 calculation)
        'max_latency_history' => (int) env('PERMISSION_CACHE_MAX_LATENCY_HISTORY', 1000),
    ],

    // 性能目标 | Performance Targets
    'performance' => [
        // P95 延迟目标（毫秒）| P95 latency target (ms)
        'p95_latency_target_ms' => 100,

        // 并发查询目标 | Concurrent query target
        'concurrent_query_target' => 1000,

        // 缓存命中率目标（%）| Cache hit rate target (%)
        'hit_rate_target' => 90,
    ],

    // 监控配置 | Monitoring Configuration
    'monitoring' => [
        // 是否启用性能监控 | Enable performance monitoring
        'enabled' => env('PERMISSION_MONITORING_ENABLED', true),

        // 慢查询阈值（毫秒）| Slow query threshold (ms)
        'slow_query_threshold_ms' => 50,

        // 是否记录缓存统计日志 | Whether to log cache statistics
        'log_stats' => env('PERMISSION_LOG_CACHE_STATS', true),

        // 统计日志间隔（秒）| Statistics log interval (seconds)
        'stats_log_interval' => (int) env('PERMISSION_STATS_LOG_INTERVAL', 300),
    ],

    // 事件配置 | Event Configuration
    'events' => [
        // 用户角色变更事件 | User role change event
        'user_role_changed' => 'permission.user.role.changed',

        // 角色权限变更事件 | Role permission change event
        'role_permission_changed' => 'permission.role.permission.changed',

        // 权限变更事件 | Permission change event
        'permission_changed' => 'permission.changed',

        // 租户权限变更事件 | Tenant permission change event
        'tenant_permission_changed' => 'permission.tenant.changed',
    ],
];
