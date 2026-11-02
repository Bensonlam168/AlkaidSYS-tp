<?php

// 全局中间件定义文件
return [
    // Trace 必须放在最前用于生成 trace_id
    \app\middleware\Trace::class,

    // CORS 必须在 Session 之前，以便正确处理 OPTIONS 预检请求
    \app\middleware\Cors::class,

    // 健康探针旁路：必须在 SessionInit **之前**，否则 Redis 一抖动，
    // /healthz（liveness）就会在路由之前被 SessionInit 抛异常打成 500 ——
    // K8s/Swarm 随即重启全部后端实例，把局部故障放大成全局故障。
    //
    // 为什么放在 Trace / Cors 之后而不是整条链的最前面：
    //   - Trace 只做 random_bytes() 生成 trace_id（零外部依赖），放在它后面探针响应才带
    //     trace_id / X-Trace-Id，能和 nginx 日志、AccessLog 对齐排查；
    //   - Cors 只读配置与请求头并给响应补头，不碰 DB/Redis/会话；
    //   - 再往后的 SessionInit / TenantIdentify / SiteIdentify / AccessLog / RateLimit
    //     才是探针必须绕开的部分（读 Redis 会话、fail-closed 抛异常、写日志、限流）。
    //
    // 匹配规则是 **pathinfo 全等 + 只允许 GET/HEAD**（见 app\middleware\HealthProbeBypass），
    // 不要改成 str_starts_with 之类的前缀匹配：那会静默跳过 AccessLog 与 RateLimit。
    \app\middleware\HealthProbeBypass::class,

    // Session 初始化
    \think\middleware\SessionInit::class,

    // Multi-tenant middleware | 多租户中间件
    \app\middleware\TenantIdentify::class,
    \app\middleware\SiteIdentify::class,

    // AccessLog 统一记录访问日志，使用 Request 中写入的 rate_limited 标记；
    // 需要包裹 RateLimit 中间件，以便即使被限流（返回 429）也能记录访问日志。
    \app\middleware\AccessLog::class,

    // 应用层限流中间件：它会根据 Request 上下文进行限流决策
    \app\middleware\RateLimit::class,
];
