<?php

declare(strict_types=1);

namespace Domain\Event;

use think\facade\Db;

/**
 * Event Logger | 事件日志记录器
 *
 * Records event execution history for debugging and auditing.
 * 记录事件执行历史，用于调试和审计。
 *
 * @package Domain\Event
 */
class EventLogger
{
    /**
     * Log event trigger | 记录事件触发
     *
     * @param string $event Event name | 事件名称
     * @param mixed $params Event parameters | 事件参数
     * @return void
     */
    public function logTrigger(string $event, mixed $params = null): void
    {
        Db::name('lowcode_event_logs')->insert([
            'event' => $event,
            'params' => json_encode($params),
            'status' => 'triggered',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Log successful event execution | 记录事件执行成功
     *
     * @param string $event Event name | 事件名称
     * @param string $listener Listener identifier | 监听器标识
     * @return void
     */
    public function logSuccess(string $event, string $listener): void
    {
        Db::name('lowcode_event_logs')->insert([
            'event' => $event,
            'listener' => $listener,
            'status' => 'success',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Log event execution failure | 记录事件执行失败
     *
     * @param string $event Event name | 事件名称
     * @param string $listener Listener identifier | 监听器标识
     * @param string $error Error message | 错误信息
     * @return void
     */
    public function logFailure(string $event, string $listener, string $error): void
    {
        Db::name('lowcode_event_logs')->insert([
            'event' => $event,
            'listener' => $listener,
            'status' => 'failed',
            'error' => $error,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
