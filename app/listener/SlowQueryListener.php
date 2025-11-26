<?php

declare(strict_types=1);

namespace app\listener;

use think\facade\Log;

/**
 * Slow Query Listener | 慢查询监听器
 *
 * Monitors database queries and logs slow queries that exceed the configured threshold.
 * 监控数据库查询并记录超过配置阈值的慢查询。
 *
 * Usage | 使用方法：
 * Register this listener in config/event.php to enable slow query monitoring.
 * 在 config/event.php 中注册此监听器以启用慢查询监控。
 */
class SlowQueryListener
{
    /**
     * Slow query threshold in milliseconds | 慢查询阈值（毫秒）
     */
    protected int $threshold = 100;

    /**
     * Handle SQL executed event | 处理 SQL 执行事件
     *
     * @param mixed $event SQL executed event
     * @return void
     */
    public function handle($event): void
    {
        // Check if event is a valid SQL executed event
        // 检查事件是否为有效的 SQL 执行事件
        if (!is_object($event) || !method_exists($event, 'getRuntime')) {
            return;
        }

        try {
            $runtime = $event->getRuntime();

            // Only log queries that exceed threshold | 仅记录超过阈值的查询
            if ($runtime > $this->threshold) {
                // Get trace_id if available | 获取 trace_id（如果可用）
                $traceId = null;
                try {
                    $request = request();
                    if ($request && method_exists($request, 'getTraceId')) {
                        $traceId = $request->getTraceId();
                    }
                } catch (\Throwable $e) {
                    // Ignore trace ID retrieval errors
                }

                // Get SQL and bindings | 获取 SQL 和绑定参数
                $sql = method_exists($event, 'getSql') ? $event->getSql() : 'unknown';
                $bindings = method_exists($event, 'getBindings') ? $event->getBindings() : [];

                Log::warning('Slow query detected', [
                    'sql'          => $sql,
                    'bindings'     => $bindings,
                    'runtime_ms'   => $runtime,
                    'threshold_ms' => $this->threshold,
                    'trace_id'     => $traceId,
                    'timestamp'    => time(),
                ]);
            }
        } catch (\Throwable $e) {
            // Silently ignore errors in listener to avoid breaking request handling
            // 静默忽略监听器中的错误，避免中断请求处理
            Log::error('Error in SlowQueryListener', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
