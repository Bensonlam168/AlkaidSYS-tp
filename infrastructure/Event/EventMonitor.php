<?php

declare(strict_types=1);

namespace Infrastructure\Event;

use Domain\Event\EventContext;
use Domain\Event\EventPayload;
use think\facade\Db;
use think\facade\Config;
use think\facade\Log;

/**
 * 事件监控/审计器：优先落库，不可用则降级写日志。
 */
class EventMonitor
{
    protected string $metricsTable = 'event_metrics';
    protected string $dlqTable = 'event_dead_letters';
    protected string $alertsTable = 'event_alerts';

    public function recordMetric(string $event, EventPayload $payload, float $durationMs, bool $failed): void
    {
        $data = [
            'event' => $event,
            'trace_id' => $payload->getContext()->getTraceId(),
            'tenant_id' => $payload->getContext()->getTenantId(),
            'site_id' => $payload->getContext()->getSiteId(),
            'user_id' => $payload->getContext()->getUserId(),
            'duration_ms' => round($durationMs, 2),
            'failed' => $failed ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->canWriteTable($this->metricsTable)) {
            try {
                Db::name($this->metricsTable)->insert($data);
                $this->checkThresholdsOnDb($event, $payload->getContext(), $durationMs, $failed);
                return;
            } catch (\Throwable $e) {
                Log::warning('event.metrics.write_failed', [
                    'error' => $e->getMessage(),
                    'event' => $event,
                ]);
            }
        }

        Log::info('event.metrics', $data);
        $this->checkThresholdsOnLog($event, $payload->getContext(), $durationMs, $failed);
    }

    public function recordDeadLetter(string $event, ?EventContext $context, \Throwable $e, int $attempts, int $code = 2001, mixed $payload = null): void
    {
        $payloadJson = null;
        if ($payload !== null) {
            try {
                $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (\Throwable) {
                $payloadJson = null;
            }
        }

        $data = [
            'code' => $code,
            'event' => $event,
            'trace_id' => $context?->getTraceId(),
            'tenant_id' => $context?->getTenantId(),
            'site_id' => $context?->getSiteId(),
            'user_id' => $context?->getUserId(),
            'attempts' => $attempts,
            'error' => $e->getMessage(),
            'payload' => $payloadJson,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->canWriteTable($this->dlqTable)) {
            try {
                Db::name($this->dlqTable)->insert($data);
                // DLQ 进入时直接告警
                $this->publishAlert('event.alert.dlq', 'error', $data);
                return;
            } catch (\Throwable $err) {
                Log::warning('event.dlq.write_failed', [
                    'error' => $err->getMessage(),
                    'event' => $event,
                ]);
            }
        }

        Log::error('event.dlq', $data);
        $this->publishAlert('event.alert.dlq', 'error', $data);
    }

    /**
     * 发布告警：同时写日志（便于日志订阅）与可选落库（便于 Grafana/SQL 订阅）。
     *
     * @param string $alertKey 如 event.alert.slow
     * @param string $level info|warning|error
     * @param array $payload
     */
    protected function publishAlert(string $alertKey, string $level, array $payload): void
    {
        // 1) 写日志：外部可按日志键 event.alert.* 订阅
        try {
            $logger = Log::channel('event_alert');
            if ($level === 'error') {
                $logger->error($alertKey, $payload);
            } elseif ($level === 'info') {
                $logger->info($alertKey, $payload);
            } else {
                $logger->warning($alertKey, $payload);
            }
        } catch (\Throwable) {
            // 若未配置 channel，则降级默认通道
            if ($level === 'error') {
                Log::error($alertKey, $payload);
            } elseif ($level === 'info') {
                Log::info($alertKey, $payload);
            } else {
                Log::warning($alertKey, $payload);
            }
        }

        // 2) 落库：便于 Grafana/SQL 报表或 DB 级订阅
        if (!$this->canWriteTable($this->alertsTable)) {
            return;
        }

        try {
            $event = $payload['event'] ?? null;
            $json = null;
            try {
                $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (\Throwable) {
                $json = null;
            }

            Db::name($this->alertsTable)->insert([
                'alert_key' => $alertKey,
                'level' => $level,
                'event' => is_string($event) ? $event : null,
                'trace_id' => $payload['trace_id'] ?? null,
                'tenant_id' => (int) ($payload['tenant_id'] ?? 0),
                'site_id' => (int) ($payload['site_id'] ?? 0),
                'user_id' => (int) ($payload['user_id'] ?? 0),
                'payload' => $json,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('event.alert.write_failed', [
                'alert_key' => $alertKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 基于 DB 的阈值判断（失败率/慢事件）。
     */
    protected function checkThresholdsOnDb(string $event, ?EventContext $context, float $durationMs, bool $failed): void
    {
        $slowMs = (int) Config::get('event.monitor.slow_ms', 300);
        if ($slowMs > 0 && $durationMs >= $slowMs) {
            $this->publishAlert('event.alert.slow', 'warning', [
                'event' => $event,
                'trace_id' => $context?->getTraceId(),
                'tenant_id' => $context?->getTenantId(),
                'site_id' => $context?->getSiteId(),
                'user_id' => $context?->getUserId(),
                'duration_ms' => round($durationMs, 2),
                'threshold_ms' => $slowMs,
            ]);
        }

        if ($failed) {
            $this->publishAlert('event.alert.failed', 'warning', [
                'event' => $event,
                'trace_id' => $context?->getTraceId(),
                'tenant_id' => $context?->getTenantId(),
                'site_id' => $context?->getSiteId(),
                'user_id' => $context?->getUserId(),
            ]);
        }

        $window = (int) Config::get('event.monitor.failure_rate_window_seconds', 60);
        $threshold = (float) Config::get('event.monitor.failure_rate_threshold', 0.2);
        $minSamples = (int) Config::get('event.monitor.failure_rate_min_samples', 20);

        if ($window <= 0 || $threshold <= 0) {
            return;
        }

        // 失败率聚合：按事件名称全局统计（可按需改为 tenant/site 维度）
        try {
            $cutoff = date('Y-m-d H:i:s', time() - $window);
            $total = (int) Db::table($this->metricsTable)
                ->where('event', $event)
                ->where('created_at', '>=', $cutoff)
                ->count();

            if ($total < $minSamples) {
                return;
            }

            $failedCount = (int) Db::table($this->metricsTable)
                ->where('event', $event)
                ->where('created_at', '>=', $cutoff)
                ->where('failed', 1)
                ->count();

            $rate = $total > 0 ? ($failedCount / $total) : 0.0;
            if ($rate >= $threshold) {
                $this->publishAlert('event.alert.failure_rate', 'error', [
                    'event' => $event,
                    'window_seconds' => $window,
                    'threshold' => $threshold,
                    'total' => $total,
                    'failed' => $failedCount,
                    'failure_rate' => round($rate, 4),
                ]);
            }
        } catch (\Throwable $e) {
            $this->publishAlert('event.alert.failure_rate_calc_failed', 'warning', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 日志降级模式下的阈值判断（只做慢事件/失败告警）。
     */
    protected function checkThresholdsOnLog(string $event, ?EventContext $context, float $durationMs, bool $failed): void
    {
        $slowMs = (int) Config::get('event.monitor.slow_ms', 300);
        if ($slowMs > 0 && $durationMs >= $slowMs) {
            $this->publishAlert('event.alert.slow', 'warning', [
                'event' => $event,
                'trace_id' => $context?->getTraceId(),
                'tenant_id' => $context?->getTenantId(),
                'site_id' => $context?->getSiteId(),
                'user_id' => $context?->getUserId(),
                'duration_ms' => round($durationMs, 2),
                'threshold_ms' => $slowMs,
            ]);
        }

        if ($failed) {
            $this->publishAlert('event.alert.failed', 'warning', [
                'event' => $event,
                'trace_id' => $context?->getTraceId(),
                'tenant_id' => $context?->getTenantId(),
                'site_id' => $context?->getSiteId(),
                'user_id' => $context?->getUserId(),
            ]);
        }
    }

    protected function canWriteTable(string $table): bool
    {
        try {
            $result = Db::query("SHOW TABLES LIKE '{$table}'");
            return !empty($result);
        } catch (\Throwable) {
            return false;
        }
    }
}
