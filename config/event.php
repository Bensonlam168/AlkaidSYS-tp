<?php

declare(strict_types=1);

/**
 * 事件系统配置（T-049）
 */
return [
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
