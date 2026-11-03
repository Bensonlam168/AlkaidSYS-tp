<?php

declare(strict_types=1);

namespace Infrastructure\Plugin\Hook;

use Domain\Event\EventContext;
use think\facade\Config;
use think\facade\Log;
use think\queue\Job;

/**
 * 异步钩子队列任务。
 */
class AsyncHookJob
{
    protected HookMonitor $monitor;
    protected int $maxAttempts;
    protected int $retryDelaySeconds;

    public function __construct(?HookMonitor $monitor = null)
    {
        $config = Config::get('hook.dlq', []);
        $this->maxAttempts = (int) ($config['max_attempts'] ?? 3);
        $this->retryDelaySeconds = (int) ($config['retry_delay_seconds'] ?? 10);
        $this->monitor = $monitor ?? new HookMonitor();
    }

    /**
     * 执行异步钩子。
     */
    public function fire(Job $job, array $data): void
    {
        $tag = $data['tag'] ?? '';
        $args = $data['args'] ?? [];
        $contextArr = $data['context'] ?? [];
        $context = $contextArr instanceof EventContext ? $contextArr : EventContext::fromArray((array) $contextArr);

        try {
            HookManager::instance()->doActionWithContext($tag, $args, $context, true);
            $job->delete();
        } catch (\Throwable $e) {
            $attempts = $job->attempts();
            $shouldDeadLetter = $attempts >= $this->maxAttempts;
            if ($shouldDeadLetter) {
                $code = (int) $e->getCode();
                if (!in_array($code, [2001, 2002], true)) {
                    $code = 2001;
                }
                $this->monitor->recordDeadLetter($tag, $context, $e, $attempts, $code, ['args' => $args]);
                $job->delete();
                return;
            }

            $job->release($this->retryDelaySeconds);
            Log::warning('hook.async.retry', [
                'tag' => $tag,
                'error' => $e->getMessage(),
                'attempts' => $attempts,
            ]);
        }
    }
}
