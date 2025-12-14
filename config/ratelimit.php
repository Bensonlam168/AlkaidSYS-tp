<?php

declare(strict_types=1);

/**
 * RateLimit 配置 | 应用层限流配置
 *
 * 本配置文件用于支撑 T2-RATELIMIT-LOG 任务中「应用层 RateLimit 中间件」的行为，
 * 通过简单、可读的数组结构定义限流相关参数，便于在不同环境(dev/stage/prod)下按需调节。
 *
 * 设计原则：
 * - 所有限流逻辑均可通过配置开关快速启用/禁用；
 * - 默认采用固定时间窗口 + 计数算法，依赖 Redis/Cache 存储计数器；
 * - 支持按多种维度(user/tenant/ip/route)叠加限流，只要任一维度命中即视为被限流；
 * - 支持白名单(用户/租户/IP)，白名单内的请求不参与限流计数；
 * - 不在本配置中写死具体业务路由，仅给出推荐示例，实际规则可由运维/开发按需调整。
 */
return [
    // ------------------------------------------------------------------
    // 1. 全局开关与基础配置
    // ------------------------------------------------------------------

    // 是否启用应用层限流中间件：
    // - 建议在 dev/local 环境默认关闭，在 stage/prod 环境按需开启；
    // - 可通过环境变量 RATELIMIT_ENABLED 覆盖，避免频繁改代码。
    'enabled' => env('RATELIMIT_ENABLED', false),

    // 使用的缓存 store：
    // - 默认为 null，表示使用 config/cache.php 中的 default store；
    // - 如需为限流单独指定 Redis 连接，可显式设置为 'redis' 等。
    'store' => env('RATELIMIT_STORE', null),

    // 默认限流规则（全局兜底策略）：
    // - limit: 在 period 秒内允许的最大请求数；
    // - period: 统计窗口长度(秒)；
    // - precision: 仅用于后续复杂算法扩展，此处保留字段不在首版使用；
    // - enabled: 是否启用全局默认规则。
    'default' => [
        'enabled'   => true,
        // dev/local 环境下，为了测试方便可通过 env 调低阈值，例如 3 次/60 秒
        'limit'     => (int) env('RATELIMIT_DEFAULT_LIMIT', 60),
        'period'    => (int) env('RATELIMIT_DEFAULT_PERIOD', 60),
        'precision' => 1,
    ],

    // ------------------------------------------------------------------
    // 2. 算法选择与 Token Bucket 配置
    // ------------------------------------------------------------------

    // 限流算法选择：
    // - 'fixed_window': 固定时间窗口算法（简单，但有边界效应）
    // - 'token_bucket': 令牌桶算法（平滑流量，支持突发）
    'algorithm' => env('RATELIMIT_ALGORITHM', 'token_bucket'),

    // Token Bucket 算法专属配置：
    // - capacity: 桶容量（最大令牌数）
    // - rate: 令牌补充速率（令牌/秒）
    // - cost_per_request: 每个请求消耗的令牌数
    'token_bucket' => [
        'capacity'         => (int) env('RATELIMIT_TB_CAPACITY', 100),
        'rate'             => (float) env('RATELIMIT_TB_RATE', 10.0),
        'cost_per_request' => 1,
    ],

    // ------------------------------------------------------------------
    // 3. 限流维度(scopes)配置
    // ------------------------------------------------------------------

    // 每个 scope 描述一种限流维度：按用户、租户、IP 或路由等；
    // - enabled: 是否启用该维度的限流；
    // - Fixed Window 参数：
    //   - limit: 在 period 秒内允许的最大请求数；
    //   - period: 统计窗口长度(秒)；
    // - Token Bucket 参数：
    //   - capacity: 桶容量（最大令牌数）；
    //   - rate: 令牌补充速率（令牌/秒）；
    //
    // 中间件会根据当前请求上下文动态组合 Redis key：
    //   Fixed Window: rl:{env}:{scope}:{identifier}:{period}s
    //   Token Bucket: rl:{env}:{scope}:{identifier}:token_bucket
    'scopes' => [
        // 按用户维度限流：适用于单用户频繁调用接口导致的风控场景
        'user' => [
            'enabled' => (bool) env('RATELIMIT_SCOPE_USER_ENABLED', true),
            'limit'   => (int) env('RATELIMIT_SCOPE_USER_LIMIT', 200),
            'period'  => (int) env('RATELIMIT_SCOPE_USER_PERIOD', 60),
            // Token Bucket 参数（可选，未设置时使用全局 token_bucket 配置）
            'capacity' => (int) env('RATELIMIT_SCOPE_USER_CAPACITY', 200),
            'rate'     => (float) env('RATELIMIT_SCOPE_USER_RATE', 20.0),
        ],

        // 按租户维度限流：保护单个租户在高并发场景下不会压垮系统
        'tenant' => [
            'enabled' => (bool) env('RATELIMIT_SCOPE_TENANT_ENABLED', true),
            'limit'   => (int) env('RATELIMIT_SCOPE_TENANT_LIMIT', 1000),
            'period'  => (int) env('RATELIMIT_SCOPE_TENANT_PERIOD', 60),
            'capacity' => (int) env('RATELIMIT_SCOPE_TENANT_CAPACITY', 1000),
            'rate'     => (float) env('RATELIMIT_SCOPE_TENANT_RATE', 100.0),
        ],

        // 按 IP 维度限流：适合公共接口的基础防护，例如匿名访问或攻击流量
        'ip' => [
            'enabled' => (bool) env('RATELIMIT_SCOPE_IP_ENABLED', true),
            'limit'   => (int) env('RATELIMIT_SCOPE_IP_LIMIT', 100),
            'period'  => (int) env('RATELIMIT_SCOPE_IP_PERIOD', 60),
            'capacity' => (int) env('RATELIMIT_SCOPE_IP_CAPACITY', 100),
            'rate'     => (float) env('RATELIMIT_SCOPE_IP_RATE', 10.0),
        ],

        // 按路由维度限流：针对部分高风险/高成本接口设置更严格的频率限制
        // 说明：route 维度本身不单独计数，而是结合 routes 配置中的具体 path 前缀使用。
        'route' => [
            'enabled' => (bool) env('RATELIMIT_SCOPE_ROUTE_ENABLED', true),
            'limit'   => (int) env('RATELIMIT_SCOPE_ROUTE_LIMIT', 50),
            'period'  => (int) env('RATELIMIT_SCOPE_ROUTE_PERIOD', 60),
            'capacity' => (int) env('RATELIMIT_SCOPE_ROUTE_CAPACITY', 50),
            'rate'     => (float) env('RATELIMIT_SCOPE_ROUTE_RATE', 5.0),
        ],
    ],

    // ------------------------------------------------------------------
    // 3. 路由级别规则(routes)
    // ------------------------------------------------------------------

    // 通过 path 前缀为特定接口或接口组定义更细粒度的限流规则：
    // - key 为路由前缀(不含域名与 query)，例如："/v1/auth/login"、"/v1/lowcode/"；
    // - 每个路由前缀下可指定覆盖默认 scopes 的 limit/period；
    // - 中间件将按最长前缀匹配策略选择最具体的规则。
    'routes' => [
        // 登录接口示例：5 分钟内同一账号最多尝试 10 次
        '/v1/auth/login' => [
            'user' => [
                'limit'  => (int) env('RATELIMIT_ROUTE_LOGIN_USER_LIMIT', 10),
                'period' => (int) env('RATELIMIT_ROUTE_LOGIN_USER_PERIOD', 300),
            ],
            'ip' => [
                'limit'  => (int) env('RATELIMIT_ROUTE_LOGIN_IP_LIMIT', 30),
                'period' => (int) env('RATELIMIT_ROUTE_LOGIN_IP_PERIOD', 300),
            ],
        ],

        // 低代码接口示例：按租户维度限制整体 QPS
        '/v1/lowcode/' => [
            'tenant' => [
                'limit'  => (int) env('RATELIMIT_ROUTE_LOWCODE_TENANT_LIMIT', 200),
                'period' => (int) env('RATELIMIT_ROUTE_LOWCODE_TENANT_PERIOD', 60),
            ],
        ],

        // 权限查询接口：不参与应用层限流，避免影响认证/权限集成测试与前端权限拉取
        // - /v1/auth/me    返回当前用户信息及权限列表
        // - /v1/auth/codes 返回权限码列表（resource:action）
        // 通过在 routes 维度显式关闭所有 scope（user/tenant/ip/route），保证始终放行。
        '/v1/auth/me' => [
            'user' => [
                'enabled' => false,
            ],
            'tenant' => [
                'enabled' => false,
            ],
            'ip' => [
                'enabled' => false,
            ],
            'route' => [
                'enabled' => false,
            ],
        ],
        '/v1/auth/codes' => [
            'user' => [
                'enabled' => false,
            ],
            'tenant' => [
                'enabled' => false,
            ],
            'ip' => [
                'enabled' => false,
            ],
            'route' => [
                'enabled' => false,
            ],
        ],
    ],

    // ------------------------------------------------------------------
    // 4. 白名单配置(whitelist)
    // ------------------------------------------------------------------

    // 白名单中的实体在应用层限流中始终放行，不参与计数：
    // - 适用于监控探针、内部批处理服务或特定测试账号；
    // - 请谨慎维护，避免因配置错误导致滥用风险。
    'whitelist' => [
        // 按 IP 白名单：支持 IPv4/IPv6 字符串数组
        'ips' => array_filter(array_map('trim', explode(',', (string) env('RATELIMIT_WHITELIST_IPS', '')))) ?: [],

        // 按用户 ID 白名单：整数 ID 数组，建议通过环境变量或配置中心注入
        'users' => [],

        // 按租户 ID 白名单：整数 ID 数组
        'tenants' => [],
    ],
];
