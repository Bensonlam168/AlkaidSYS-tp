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

        if (!$event) {
            // 无事件名属于脏数据：直接删除，避免反复重试
            $job->delete();
            return;
        }

        $eventName = $this->normalizeEventName($event);

        try {
            // Trigger the event | 触发事件（统一传递 EventPayload，透传上下文）
            /** @var EventService $service */
            $service = new $serviceClass();

            // 标注分发模式：worker 内 trigger 是同步调用，但业务语义属于异步事件，
            // 失败日志的 mode 字段应记为 async（EventService 子类若未继承该方法则忽略）
            if (method_exists($service, 'setDispatchMode')) {
                $service->setDispatchMode('async');
            }

            $payload = $service->trigger($eventName, $params, $context);

            // ER-13：EventService 默认「监听器异常不打断业务」，trigger 会正常返回 payload。
            // 队列任务不能把这种情况当成成功（否则任务被 delete，重试/DLQ 永远不触发），
            // 因此这里主动抛出 payload 上记录的原始异常，交给下面的 catch 走 release / DLQ。
            if ($payload instanceof EventPayload && $payload->hasListenerFailures()) {
                throw $payload->getFirstListenerFailure()
                    ?? new \RuntimeException('event listener failed: ' . $eventName);
            }

            $job->delete();
        } catch (\Throwable $e) {
            // 记录 trace 便于排查：字段与同步链路保持一致（ER-13）
            $attempts = (int) $job->attempts();

            EventFailureLogger::report(
                'event.async.failed',
                $eventName,
                null,
                $context,
                $e,
                'async',
                ['attempts' => $attempts],
                'warning'
            );

            // If max retries reached, delete | 如果达到最大重试次数，删除任务
            //
            // 边界说明：ThinkPHP 队列的 attempts() 从 1 开始（Redis 实现为 payload.attempts + 1），
            // 因此 `> max_attempts` 恰好表示「首次执行 + max_attempts 次重试」后进入死信。
            // 例：max_attempts 取值为 3 时，第 4 次仍失败才落 DLQ，与 config/event.php 注释一致。
            $maxAttempts = (int) Config::get('event.dlq.max_attempts', 3);
            if ($attempts > $maxAttempts) {
                try {
                    $this->recordDeadLetter($eventName, $context, $e, $attempts, [
                        'event' => $eventName,
                        'params' => $params,
                        'context' => $data['context'] ?? null,
                    ]);
                } catch (\Throwable $dlqError) {
                    // ER-13：DLQ 写失败绝不能「任务丢了 + 日志也没留」。
                    // 这里用 error 级记录死信写入失败，并带上原始异常与 payload，便于人工补偿。
                    EventFailureLogger::report(
                        'event.dlq.record_failed',
                        $eventName,
                        null,
                        $context,
                        $dlqError,
                        'async',
                        [
                            'attempts' => $attempts,
                            'original_exception' => $e::class,
                            'original_message' => $e->getMessage(),
                            'payload' => $params,
                        ]
                    );
                }

                // 无论 DLQ 是否写成功都必须删除任务：否则该任务会因 attempts 持续超过阈值
                // 被反复取出、反复失败，永远无法结束。
                $job->delete();
            } else {
                $delay = (int) Config::get('event.dlq.retry_delay_seconds', 10);
                $job->release($delay); // Retry after delay | 延迟后重试
            }
        }
    }

    /**
     * 把事件名归一化为字符串，便于日志与 DLQ 落库
     *
     * 正常路径下 $data['event'] 一定是字符串；脏数据里可能是数组/对象，
     * 直接拼接会触发 "Array to string conversion"，这里显式降级为类型名。
     */
    protected function normalizeEventName(mixed $event): string
    {
        if (is_string($event)) {
            return $event;
        }

        if (is_scalar($event)) {
            return (string) $event;
        }

        return get_debug_type($event);
    }
}
