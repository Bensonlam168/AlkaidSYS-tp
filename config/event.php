<?php

declare(strict_types=1);

/**
 * 事件系统配置（T-049）
 */
return [
    // 同步事件失败严格模式（ER-13）
    // false（默认）：监听器异常只记 error 日志，不中断业务，也不阻断同事件的后续监听器；
    // true：监听器异常原样上抛，仅用于测试与排障，线上开启会让插件问题直接变成业务 500。
    // 用 filter_var 而不是 (bool)：避免 EVENT_FAIL_FAST 被写成 no/off 时，非空字符串被当成 true。
    'fail_fast' => filter_var(env('EVENT_FAIL_FAST', false), FILTER_VALIDATE_BOOLEAN),
    'monitor' => [
        // 慢事件告警阈值（毫秒）
        'slow_ms' => (int) (getenv('EVENT_MONITOR_SLOW_MS') ?: 300),
        // 失败率统计窗口（秒，0 表示关闭失败率统计）
        'failure_rate_window_seconds' => (int) (getenv('EVENT_MONITOR_FAILURE_WINDOW') ?: 60),
        // 失败率阈值（0-1）
        'failure_rate_threshold' => (float) (getenv('EVENT_MONITOR_FAILURE_THRESHOLD') ?: 0.2),
        // 触发失败率告警的最小样本数
        'failure_rate_min_samples' => (int) (getenv('EVENT_MONITOR_FAILURE_MIN_SAMPLES') ?: 20),
    ],
    'dlq' => [
        // 队列最大重试次数（超过后写 DLQ 并删除任务）
        'max_attempts' => (int) (getenv('EVENT_DLQ_MAX_ATTEMPTS') ?: 3),
        // 失败重试延迟（秒）
        'retry_delay_seconds' => (int) (getenv('EVENT_DLQ_RETRY_DELAY') ?: 10),
    ],
];
