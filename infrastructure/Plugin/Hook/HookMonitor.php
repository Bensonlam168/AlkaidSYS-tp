<?php

declare(strict_types=1);

namespace Infrastructure\Plugin\Hook;

use Domain\Event\EventContext;
use think\facade\Config;
use think\facade\Db;
use think\facade\Log;

/**
 * 钩子监控/审计器：优先落库，不可用则降级写日志。
 */
class HookMonitor
{
    protected string $metricsTable = 'hook_metrics';
    protected string $dlqTable = 'hook_dead_letters';
    protected string $alertsTable = 'hook_alerts';

    public function recordMetric(string $hook, string $type, EventContext $context, float $durationMs, bool $failed): void
    {
        $data = [
            'hook' => $hook,
            'type' => $type,
            'trace_id' => $context->getTraceId(),
            'tenant_id' => $context->getTenantId(),
            'site_id' => $context->getSiteId(),
            'user_id' => $context->getUserId(),
            'duration_ms' => round($durationMs, 2),
            'failed' => $failed ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->canWriteTable($this->metricsTable)) {
            try {
                Db::name($this->metricsTable)->insert($data);
                $this->checkThresholdsOnDb($hook, $context, $durationMs, $failed);
                return;
            } catch (\Throwable $e) {
                Log::warning('hook.metrics.write_failed', [
                    'error' => $e->getMessage(),
                    'hook' => $hook,
                ]);
            }
        }

        Log::info('hook.metrics', $data);
        $this->checkThresholdsOnLog($hook, $context, $durationMs, $failed);
    }

    public function recordDeadLetter(string $hook, ?EventContext $context, \Throwable $e, int $attempts, int $code = 2001, mixed $payload = null): void
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
            'hook' => $hook,
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
                $this->publishAlert('hook.alert.dlq', 'error', [
                    'hook' => $hook,
                    'trace_id' => $context?->getTraceId(),
                    'tenant_id' => $context?->getTenantId(),
                    'site_id' => $context?->getSiteId(),
                    'user_id' => $context?->getUserId(),
                    'attempts' => $attempts,
                    'code' => $code,
                ]);
                return;
            } catch (\Throwable $err) {
                Log::warning('hook.dlq.write_failed', [
                    'error' => $err->getMessage(),
                    'hook' => $hook,
                ]);
            }
        }

        Log::warning('hook.dlq', $data);
    }

    protected function canWriteTable(string $table): bool
    {
        try {
            Db::table($table)->getFields();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function checkThresholdsOnDb(string $hook, EventContext $context, float $durationMs, bool $failed): void
    {
        $monitorConfig = Config::get('hook.monitor', []);
        $slowMs = (int) ($monitorConfig['slow_ms'] ?? 300);
        if ($durationMs >= $slowMs) {
            $this->publishAlert('hook.alert.slow', 'warning', [
                'hook' => $hook,
                'duration_ms' => $durationMs,
                'trace_id' => $context->getTraceId(),
                'tenant_id' => $context->getTenantId(),
                'site_id' => $context->getSiteId(),
                'user_id' => $context->getUserId(),
            ]);
        }

        $window = (int) ($monitorConfig['failure_rate_window_seconds'] ?? 0);
        $threshold = (float) ($monitorConfig['failure_rate_threshold'] ?? 0.2);
        $minSamples = (int) ($monitorConfig['failure_rate_min_samples'] ?? 20);

        if ($window <= 0 || $threshold <= 0) {
            return;
        }

        try {
            $cutoff = date('Y-m-d H:i:s', time() - $window);
            $total = (int) Db::table($this->metricsTable)
                ->where('hook', $hook)
                ->where('created_at', '>=', $cutoff)
                ->count();

            if ($total < $minSamples) {
                return;
            }

            $failedCount = (int) Db::table($this->metricsTable)
                ->where('hook', $hook)
                ->where('created_at', '>=', $cutoff)
                ->where('failed', 1)
                ->count();

            $rate = $total > 0 ? ($failedCount / $total) : 0.0;
            if ($rate >= $threshold) {
                $this->publishAlert('hook.alert.failure_rate', 'error', [
                    'hook' => $hook,
                    'window_seconds' => $window,
                    'threshold' => $threshold,
                    'total' => $total,
                    'failed' => $failedCount,
                    'failure_rate' => round($rate, 4),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('hook.metrics.failure_rate_calc_failed', [
                'hook' => $hook,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function checkThresholdsOnLog(string $hook, EventContext $context, float $durationMs, bool $failed): void
    {
        $monitorConfig = Config::get('hook.monitor', []);
        $slowMs = (int) ($monitorConfig['slow_ms'] ?? 300);
        if ($durationMs >= $slowMs) {
            $this->publishAlert('hook.alert.slow', 'warning', [
                'hook' => $hook,
                'duration_ms' => $durationMs,
                'trace_id' => $context->getTraceId(),
                'tenant_id' => $context->getTenantId(),
                'site_id' => $context->getSiteId(),
                'user_id' => $context->getUserId(),
            ]);
        }

        // 失败率统计需要落库才有意义，日志路径不再聚合
    }

    protected function publishAlert(string $alertKey, string $level, array $payload): void
    {
        $knownKeys = ['hook', 'trace_id', 'tenant_id', 'site_id', 'user_id'];
        $extra = $payload;

        $data = [
            'alert_key' => $alertKey,
            'hook' => $payload['hook'] ?? null,
            'trace_id' => $payload['trace_id'] ?? null,
            'tenant_id' => $payload['tenant_id'] ?? 0,
            'site_id' => $payload['site_id'] ?? 0,
            'user_id' => $payload['user_id'] ?? 0,
            'level' => $level,
            'payload' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        foreach ($knownKeys as $key) {
            unset($extra[$key]);
        }

        if (!empty($extra)) {
            try {
                $data['payload'] = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (\Throwable) {
                $data['payload'] = null;
            }
        }

        try {
            Db::name($this->alertsTable)->insert($data);
            return;
        } catch (\Throwable $e) {
            Log::warning('hook.alert.write_failed', [
                'alert_key' => $alertKey,
                'error' => $e->getMessage(),
            ]);
        }

        Log::log($level === 'error' ? 'error' : 'warning', $alertKey, $data);
    }
}
