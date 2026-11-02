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

    // ------------------------------------------------------------------
    // 1.1 客户端 IP 解析：可信反向代理（ER-09）
    // ------------------------------------------------------------------
    //
    // 限流的 ip 维度依赖「客户端真实 IP」，而真实 IP 只能从 X-Forwarded-For 里读出来 ——
    // 这个头**客户端可以随便伪造**（可写多个值，也可整段瞎写）。因此必须先回答一个问题：
    // 「这条 XFF 是谁写的？」
    //
    // 判定规则（由 app/middleware/RateLimit.php::resolveClientIp 实现）：
    //   1. TCP 对端不在本列表 → 整条 XFF 忽略，用对端地址（REMOTE_ADDR）；
    //   2. TCP 对端在本列表 → 从**右往左**扫描 XFF，跳过可信代理，取第一个不可信地址；
    //   3. XFF 全链可信 / 非法 / 超长 → 回退对端地址。
    //
    // **默认空数组 = 不信任任何 XFF**，这是唯一安全的默认值：
    // 未配置时伪造 X-Forwarded-For 不会改变限流桶，代价是「经反代后所有客户端共用一个 ip 桶」
    // （应先配好本项再启用 ip 维度限流，否则整站会挤在一个 IP 桶里）。
    //
    // 取值格式：逗号分隔，支持单个 IP 与 CIDR，IPv4 / IPv6 均可，例如：
    //   TRUSTED_PROXIES=172.18.0.6
    //   TRUSTED_PROXIES=10.0.0.0/8,2001:db8::/32
    // 非法条目会被忽略并记 warning（不会让其余条目一起失效）。
    //
    // ⚠️ 不要填「客户端可能直达的地址段」：例如把 backend 容器的发布端口 9501 暴露给宿主机时，
    // 直连请求的对端是 docker 网关（172.18.0.1）；若把 172.18.0.0/16 整段设为可信，
    // 这条直连路径上的 XFF 就又变成可伪造了。生产环境只填真实反代地址。
    'trusted_proxies' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
        static fn (string $item): bool => $item !== ''
    )),

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
        // 按用户维度限流：**当前不可用（默认关闭）**
        //
        // 原因（ER-09）：RateLimit 是全局中间件，Auth 是路由级中间件，执行到限流时
        // $request->userId() 必然为空。历史实现把「取不到用户」写成空串，
        // 结果所有请求（含未登录）共用同一个 user 桶 —— 那不是「按用户限流」，
        // 而是一条伪装成用户维度的**全局配额**：任何客户端先打满就把其他人一起 429。
        //
        // 现在中间件的行为是「显式跳过 + 告警」，因此：
        //   - 保持 enabled=false 是**诚实**的默认值，表示「本维度不生效」；
        //   - 即使有人把它改成 true，也只会得到告警并跳过，绝不会再退化成全局桶；
        //   - 真要按用户限流，需要把限流挪到 Auth 之后的**路由级**中间件，
        //     方案见 docs/technical-specs/security/security-guidelines.zh-CN.md §5.1.1。
        'user' => [
            'enabled' => (bool) env('RATELIMIT_SCOPE_USER_ENABLED', false),
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
        // 登录接口示例：5 分钟内同一 IP 最多尝试 30 次
        //
        // ⚠️ 这里的 user 段（原先写的是「同一账号最多 10 次」）**不生效，且不应依赖**：
        // 登录请求本身就是「尚未认证」的请求，限流中间件执行时一定拿不到 userId；
        // 而 user scope 在拿不到用户时会显式跳过（见 scopes.user 的说明），
        // 绝不再退化成「所有人共用一个 10 次配额」的全局桶。
        // 真正生效的是同一段里的 ip 规则（基于可信代理解析出的客户端 IP）。
        // 若确需「按账号」限制登录尝试，应在 AuthController 内按登录名做计数（当前未实现）。
        '/v1/auth/login' => [
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

        // 健康探针：不参与应用层限流
        //
        // `/healthz` 是编排层（Dockerfile HEALTHCHECK / docker-compose healthcheck / 负载均衡）
        // 高频调用的 liveness 探针，它的语义是「worker 还活着吗」，**不应该消耗业务配额**：
        //   - 若探针走 ip 维度（默认 capacity=100 / rate=10 每秒），连续探测会把同一个
        //     对端（nginx 容器或宿主 docker 网关）的桶打空 → 探针开始收到 429 →
        //     编排层判定 unhealthy → 无谓重启。**探针被自己的限流拒掉**是最典型的自伤。
        //   - 探针的健康与否与业务流量无关，给它单独配额（而不是共享业务桶）也说不通。
        //
        // 实现方式与上面 /v1/auth/* 完全一致：把四个维度显式 `enabled = false`。
        // 这属于「路径级放行」，不是「IP 白名单」，因此不依赖 TRUSTED_PROXIES 的配置是否正确 ——
        // 探针在「反代没配好/直连」两种部署形态下行为相同。
        '/healthz' => [
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
