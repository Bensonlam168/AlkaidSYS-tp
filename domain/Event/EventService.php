<?php

declare(strict_types=1);

namespace Domain\Event;

use think\facade\Config;
use think\facade\Event;
use think\facade\Queue;
use Infrastructure\Event\EventMonitor;

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

    protected EventMonitor $monitor;

    /**
     * 严格模式覆盖值（null 表示按配置 event.fail_fast / EVENT_FAIL_FAST 解析）
     *
     * 显式覆盖主要给测试与排障使用：无需改环境变量即可验证「开启后原样上抛」。
     */
    protected ?bool $failFastOverride;

    /**
     * 当前分发模式：sync|async（仅用于失败日志，不改变执行方式）
     *
     * 队列任务在调用 trigger 前通过 setDispatchMode('async') 标注；同一实例默认 sync。
     */
    protected string $dispatchMode = 'sync';

    public function __construct(?EventMonitor $monitor = null, ?bool $failFast = null)
    {
        $this->monitor = $monitor ?? new EventMonitor();
        $this->failFastOverride = $failFast;
    }

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
     * 获取指定事件的优先级监听器（按优先级排序）。
     *
     * @return array<int, callable>
     */
    public function getPriorityListeners(string $event): array
    {
        if (!isset($this->priorities[$event]) || !isset($this->priorityListeners[$event])) {
            return [];
        }

        $sortedKeys = $this->priorities[$event];
        asort($sortedKeys);
        $listeners = [];

        foreach (array_keys($sortedKeys) as $listenerKey) {
            if (isset($this->priorityListeners[$event][$listenerKey])) {
                $listeners[] = $this->priorityListeners[$event][$listenerKey];
            }
        }

        return $listeners;
    }

    public function setMonitor(EventMonitor $monitor): void
    {
        $this->monitor = $monitor;
    }

    /**
     * 标注当前分发模式（仅影响失败日志的 mode 字段）
     *
     * 队列任务在 worker 里调用 trigger 时，执行本身是同步的，但业务语义属于「异步事件」。
     * 让任务在调用前标注 async，失败日志里才不会把队列事件误报成同步事件。
     */
    public function setDispatchMode(string $mode): void
    {
        $this->dispatchMode = $mode === 'async' ? 'async' : 'sync';
    }

    /**
     * Trigger event with priority sorting | 按优先级触发事件
     *
     * @param string $event Event name | 事件名称
     * @param mixed $params Event parameters | 事件参数
     * @param EventContext|null $context Event context | 事件上下文
     * @return EventPayload
     */
    public function trigger(string $event, $params = null, ?EventContext $context = null): EventPayload
    {
        $payload = $this->buildPayload($event, $params, $context);
        $start = microtime(true);
        $failed = false;

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
                        try {
                            $result = call_user_func_array($listener, $this->buildListenerArgs($listener, $payload));
                        } catch (\Throwable $e) {
                            // ER-13：监听器崩溃必须留下结构化 error 日志。
                            // 一次失败记一条，并带上 listener 标识，循环内多个监听器失败时也能区分。
                            $failed = true;
                            $payload->addListenerFailure($e);
                            $this->reportListenerFailure($event, $listener, $listenerKey, $payload, $e, 'priority');

                            if ($this->isFailFastEnabled()) {
                                // 严格模式：原样上抛（仅用于测试/排障，默认关闭，见 EVENT_FAIL_FAST）
                                throw $e;
                            }

                            // 默认语义：单个监听器失败不阻断同事件的后续监听器
                            continue;
                        }

                        if ($this->shouldStopPropagation($payload, $result)) {
                            $payload->stopPropagation();
                            break;
                        }
                    } else {
                        $failed = true;
                    }
                }
            }
        }

        // Stop propagation: do not continue to ThinkPHP event system | 停止传播：不继续触发 ThinkPHP 事件
        if ($payload->isPropagationStopped()) {
            return $payload;
        }

        // Also trigger through ThinkPHP's event system for non-priority listeners
        // 同时通过ThinkPHP事件系统触发非优先级监听器（传递 EventPayload 以便透传上下文）
        try {
            Event::trigger($event, $payload);
        } catch (\Throwable $e) {
            // ER-13：这里原来只有 `$failed = true`，连日志都没有 —— 监听器崩了完全不可观测。
            // ThinkPHP 的 Event::trigger 不暴露「具体哪个监听器失败」，因此 listener 记为 null，
            // 用 phase=thinkphp 标明失败来源。
            $failed = true;
            $payload->addListenerFailure($e);
            $this->reportListenerFailure($event, null, null, $payload, $e, 'thinkphp');

            if ($this->isFailFastEnabled()) {
                // 严格模式：原样上抛，供测试与排障定位
                throw $e;
            }
        }

        $this->recordMetrics($event, $payload, $start, $failed);

        return $payload;
    }

    /**
     * 是否启用「监听器失败严格模式」
     *
     * 取值优先级：
     *   1. 构造函数显式覆盖（测试/排障）；
     *   2. config/event.php 的 fail_fast，默认读环境变量 EVENT_FAIL_FAST，未设置时为 false。
     *
     * 为什么默认 false：事件监听器通常是旁路能力（通知、审计、插件扩展），
     * 监听器写错不应该把已经成功的主流程（如下单、入库）变成 500。
     * 严格模式只用于「让问题立刻暴露」的测试与灰度排障场景。
     */
    public function isFailFastEnabled(): bool
    {
        if ($this->failFastOverride !== null) {
            return $this->failFastOverride;
        }

        try {
            return (bool) Config::get('event.fail_fast', false);
        } catch (\Throwable) {
            // 读取开关本身失败时按默认（不抛）处理，绝不因此中断业务
            return false;
        }
    }

    /**
     * 记录一次同步监听器失败（统一字段 + 日志系统故障时 error_log 兜底）
     *
     * @param string        $event       事件名
     * @param callable|null $listener    监听器；ThinkPHP 事件系统内部监听器拿不到时为 null
     * @param string|null   $listenerKey 监听器存储键（闭包也能区分）
     * @param EventPayload  $payload     事件载荷（提供上下文）
     * @param \Throwable    $e           监听器抛出的原始异常
     * @param string        $phase       priority|thinkphp
     */
    protected function reportListenerFailure(
        string $event,
        ?callable $listener,
        ?string $listenerKey,
        EventPayload $payload,
        \Throwable $e,
        string $phase
    ): void {
        EventFailureLogger::report(
            'event.listener.failed',
            $event,
            $this->describeListener($listener),
            $payload->getContext(),
            $e,
            $this->dispatchMode,
            [
                'phase' => $phase,
                'listener_key' => $listenerKey,
            ]
        );
    }

    /**
     * 生成可读的监听器标识：class@method / closure / 函数名 / 字符串监听器
     *
     * 注意：标识只用于日志区分，不参与任何逻辑判断。
     */
    protected function describeListener(?callable $listener): ?string
    {
        if ($listener === null) {
            return null;
        }

        if ($listener instanceof \Closure) {
            return 'closure';
        }

        if (is_string($listener)) {
            return $listener;
        }

        if (is_array($listener)) {
            $target = $listener[0] ?? null;
            $method = $listener[1] ?? '__invoke';

            if (is_object($target)) {
                return $target::class . '@' . $method;
            }

            return is_string($target) ? $target . '@' . $method : 'unknown@' . $method;
        }

        if (is_object($listener)) {
            return $listener::class . '@__invoke';
        }

        return 'unknown';
    }

    /**
     * Trigger event asynchronously via queue | 通过队列异步触发事件
     *
     * @param string $event Event name | 事件名称
     * @param mixed $params Event parameters | 事件参数
     * @param EventContext|null $context Event context | 事件上下文
     * @param string $queue Queue name | 队列名称
     * @return void
     */
    public function triggerAsync(string $event, $params = null, ?EventContext $context = null, string $queue = 'default'): void
    {
        $payload = $this->buildPayload($event, $params, $context);

        // Push to queue | 推送到队列
        Queue::push(AsyncEventJob::class, [
            'event' => $event,
            'params' => $payload->getData(),
            'context' => $payload->getContext()->toArray(),
        ], $queue);
    }

    /**
     * 使用协程异步触发事件（优先使用 Swoole go），无协程环境则回退到队列。
     *
     * @param string $event
     * @param mixed $params
     * @param EventContext|null $context
     * @param callable|null $coroutineRunner 自定义协程执行器（测试可注入），签名 fn(callable $fn): void
     */
    public function triggerAsyncCoroutine(string $event, $params = null, ?EventContext $context = null, ?callable $coroutineRunner = null): void
    {
        $payload = $this->buildPayload($event, $params, $context);
        $runner = $coroutineRunner ?? $this->defaultCoroutineRunner();

        if ($runner !== null) {
            $runner(function () use ($payload) {
                $this->trigger($payload->getEvent(), $payload->getData(), $payload->getContext());
            });
            return;
        }

        // 无协程环境时退回队列保证异步语义
        $this->triggerAsync($event, $params, $context);
    }

    /**
     * 获取默认协程执行器：Swoole 提供 go() 时返回闭包，否则 null。
     */
    protected function defaultCoroutineRunner(): ?callable
    {
        if (function_exists('go')) {
            return static function (callable $fn): void {
                go($fn);
            };
        }

        return null;
    }

    /**
     * 记录事件审计与指标（耗时/失败）——当前实现简单写日志，可后续接入指标系统。
     */
    protected function recordMetrics(string $event, EventPayload $payload, float $start, bool $failed): void
    {
        $durationMs = (microtime(true) - $start) * 1000;

        try {
            $this->monitor->recordMetric($event, $payload, $durationMs, $failed);
        } catch (\Throwable $e) {
            // 监控自身故障不能反噬业务：记录 warning 后继续返回 payload（EventMonitor 内部已尽力降级，
            // 这里是第二道防线，防止自定义/替换的 monitor 直接抛异常）
            EventFailureLogger::report(
                'event.monitor.record_metric_failed',
                $event,
                null,
                $payload->getContext(),
                $e,
                $this->dispatchMode,
                ['phase' => 'monitor'],
                'warning'
            );
        }
    }

    /**
     * 构造事件载荷，保证上下文存在（无请求则降级为空上下文）。
     */
    protected function buildPayload(string $event, mixed $params, ?EventContext $context): EventPayload
    {
        if ($params instanceof EventPayload) {
            return $params;
        }

        return new EventPayload($event, $params, $context ?? EventContext::fromGlobals());
    }

    /**
     * 根据监听器签名构造调用参数：
     * - 兼容历史：仅 1 个参数时传 data（而不是 payload）
     * - 新增能力：2 个参数时传 (data, payload)，以支持上下文与 stopPropagation
     */
    protected function buildListenerArgs(callable $listener, EventPayload $payload): array
    {
        $paramCount = 1;

        try {
            if (is_array($listener)) {
                $ref = new \ReflectionMethod($listener[0], $listener[1]);
                $paramCount = $ref->getNumberOfParameters();
            } elseif ($listener instanceof \Closure) {
                $ref = new \ReflectionFunction($listener);
                $paramCount = $ref->getNumberOfParameters();
            } elseif (is_object($listener) && method_exists($listener, '__invoke')) {
                $ref = new \ReflectionMethod($listener, '__invoke');
                $paramCount = $ref->getNumberOfParameters();
            }
        } catch (\Throwable) {
            // 反射失败则按最小兼容（1 参数）处理
            $paramCount = 1;
        }

        if ($paramCount >= 2) {
            return [$payload->getData(), $payload];
        }

        return [$payload->getData()];
    }

    /**
     * 判断是否应停止传播：
     * - 监听器显式返回 false
     * - 返回 EventResult 且标记 stopPropagation
     * - payload 已被监听器调用 stopPropagation()
     */
    protected function shouldStopPropagation(EventPayload $payload, mixed $listenerResult): bool
    {
        if ($payload->isPropagationStopped()) {
            return true;
        }

        if ($listenerResult === false) {
            return true;
        }

        return $listenerResult instanceof EventResult && $listenerResult->shouldStopPropagation();
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
