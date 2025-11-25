<?php

declare(strict_types=1);

namespace Domain\Event;

use think\facade\Event;

/**
 * Event Service | 事件服务
 *
 * Provides event listening with priority support and asynchronous processing.
 * 提供带优先级支持和异步处理的事件监听。
 *
 * @package Domain\Event
 */
class EventService
{
    /**
     * Priority mapping | 优先级映射
     * @var array<string, array<string, int>>
     */
    protected array $priorities = [];

    /**
     * Priority listeners storage | 优先级监听器存储
     * @var array<string, array<string, callable>>
     */
    protected array $priorityListeners = [];

    /**
     * Register event listener with priority | 注册带优先级的事件监听器
     *
     * Lower priority number means higher execution priority.
     * 优先级数字越小，执行优先级越高。
     *
     * @param string $event Event name | 事件名称
     * @param callable $listener Listener callback | 监听器回调
     * @param int $priority Priority (lower number = higher priority) | 优先级（数字越小优先级越高）
     * @return void
     */
    public function listenWithPriority(string $event, callable $listener, int $priority = 10): void
    {
        // Store priority | 存储优先级
        $listenerKey = $this->getListenerKey($listener);

        if (!isset($this->priorities[$event])) {
            $this->priorities[$event] = [];
        }
        $this->priorities[$event][$listenerKey] = $priority;

        // Store the actual listener | 存储实际的监听器
        if (!isset($this->priorityListeners[$event])) {
            $this->priorityListeners[$event] = [];
        }
        $this->priorityListeners[$event][$listenerKey] = $listener;
    }

    /**
     * Trigger event with priority sorting | 按优先级触发事件
     *
     * @param string $event Event name | 事件名称
     * @param mixed $params Event parameters | 事件参数
     * @return mixed
     */
    public function trigger(string $event, $params = null)
    {
        // Sort listeners by priority if we have any | 如果有监听器，按优先级排序
        if (isset($this->priorities[$event]) && count($this->priorities[$event]) > 0) {
            // Get sorted listener keys | 获取排序后的监听器键
            $sortedKeys = $this->priorities[$event];
            asort($sortedKeys); // Sort by value (priority) ascending | 按值（优先级）升序排序

            // Trigger each listener in priority order | 按优先级顺序触发每个监听器
            foreach (array_keys($sortedKeys) as $listenerKey) {
                if (isset($this->priorityListeners[$event][$listenerKey])) {
                    $listener = $this->priorityListeners[$event][$listenerKey];
                    if (is_callable($listener)) {
                        call_user_func($listener, $params);
                    }
                }
            }

            return true;
        }

        // Also trigger through ThinkPHP's event system for non-priority listeners
        // 同时通过ThinkPHP事件系统触发非优先级监听器
        return Event::trigger($event, $params);
    }

    /**
     * Trigger event asynchronously via queue | 通过队列异步触发事件
     *
     * @param string $event Event name | 事件名称
     * @param mixed $params Event parameters | 事件参数
     * @param string $queue Queue name | 队列名称
     * @return void
     */
    public function triggerAsync(string $event, $params = null, string $queue = 'default'): void
    {
        // Push to queue | 推送到队列
        \think\facade\Queue::push(AsyncEventJob::class, [
            'event' => $event,
            'params' => $params,
        ], $queue);
    }

    /**
     * Get listener key for storage | 获取监听器的存储键
     *
     * @param mixed $listener Listener | 监听器
     * @return string
     */
    protected function getListenerKey($listener): string
    {
        if (is_string($listener)) {
            return $listener;
        }

        if ($listener instanceof \Closure) {
            return spl_object_hash($listener);
        }

        if (is_array($listener)) {
            if (is_object($listener[0])) {
                return spl_object_hash($listener[0]) . '::' . $listener[1];
            }
            return $listener[0] . '::' . $listener[1];
        }

        return spl_object_hash($listener);
    }
}
