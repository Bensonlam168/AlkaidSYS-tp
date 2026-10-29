<?php

declare(strict_types=1);

namespace Domain\Event;

use think\queue\Job;
use Infrastructure\Event\EventMonitor;
use think\facade\Config;

/**
 * Async Event Job | 异步事件任务
 *
 * Queue job for handling asynchronous event dispatching.
 * 用于处理异步事件分发的队列任务。
 *
 * @package Domain\Event
 */
class AsyncEventJob
{
    protected EventMonitor $monitor;

    public function __construct(?EventMonitor $monitor = null)
    {
        $this->monitor = $monitor ?? new EventMonitor();
    }

    /**
     * 当重试超过阈值时写入死信（DLQ）表。
     */
    protected function recordDeadLetter(string $event, ?EventContext $context, \Throwable $e, int $attempts, mixed $payload = null): void
    {
        $code = (int) $e->getCode();
        if (!in_array($code, [2001, 2002], true)) {
            $code = 2001;
        }

        $this->monitor->recordDeadLetter($event, $context, $e, $attempts, $code, $payload);
    }

    /**
     * Execute the job | 执行任务
     *
     * @param Job $job Queue job instance | 队列任务实例
     * @param array $data Job data containing event and params | 包含事件和参数的任务数据
     * @return void
     */
    public function fire(Job $job, array $data): void
    {
        $event = $data['event'] ?? null;
        $params = $data['params'] ?? [];
        $context = isset($data['context']) && is_array($data['context']) ? EventContext::fromArray($data['context']) : null;
        $serviceClass = $data['service_class'] ?? EventService::class;

        if ($event) {
            try {
                // Trigger the event | 触发事件（统一传递 EventPayload，透传上下文）
                /** @var EventService $service */
                $service = new $serviceClass();
                $service->trigger($event, $params, $context);
                $job->delete();
            } catch (\Throwable $e) {
                // Log error | 记录错误
                // 记录 trace 便于排查
                \think\facade\Log::warning('event.async.failed', [
                    'event' => $event,
                    'trace_id' => $context?->getTraceId(),
                    'tenant_id' => $context?->getTenantId(),
                    'site_id' => $context?->getSiteId(),
                    'user_id' => $context?->getUserId(),
                    'attempts' => $job->attempts(),
                    'error' => $e->getMessage(),
                ]);
                // If max retries reached, delete | 如果达到最大重试次数，删除任务
                $maxAttempts = (int) Config::get('event.dlq.max_attempts', 3);
                if ($job->attempts() > $maxAttempts) {
                    $this->recordDeadLetter($event, $context, $e, $job->attempts(), [
                        'event' => $event,
                        'params' => $params,
                        'context' => $data['context'] ?? null,
                    ]);
                    $job->delete();
                } else {
                    $delay = (int) Config::get('event.dlq.retry_delay_seconds', 10);
                    $job->release($delay); // Retry after delay | 延迟后重试
                }
            }
        } else {
            $job->delete();
        }
    }
}
