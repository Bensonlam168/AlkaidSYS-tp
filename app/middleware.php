<?php

// 全局中间件定义文件
return [
    // Trace 必须放在最前用于生成 trace_id
    \app\middleware\Trace::class,

    // CORS 必须在 Session 之前，以便正确处理 OPTIONS 预检请求
    \app\middleware\Cors::class,

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
