<?php

declare(strict_types=1);

namespace Domain\Event;

use think\facade\Event;
use think\queue\Job;

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

        if ($event) {
            try {
                // Trigger the event | 触发事件
                Event::trigger($event, $params);
                $job->delete();
            } catch (\Throwable $e) {
                // Log error | 记录错误
                // If max retries reached, delete | 如果达到最大重试次数，删除任务
                if ($job->attempts() > 3) {
                    $job->delete();
                } else {
                    $job->release(10); // Retry after 10 seconds | 10秒后重试
                }
            }
        } else {
            $job->delete();
        }
    }
}
