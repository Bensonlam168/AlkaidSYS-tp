<?php

declare(strict_types=1);

namespace Infrastructure\Plugin\Hook;

use Domain\Event\EventContext;
use think\facade\Config;
use think\facade\Log;
use think\facade\Queue;

/**
 * HookManager | 钩子管理器
 *
 * 目标：
 * - 提供 Action / Filter 两类钩子注册与执行能力
 * - 支持优先级（数字越小优先级越高）
 *
 * 说明：
 * - Action：用于副作用处理，不关心返回值
 * - Filter：用于对数据做链式变换，必须返回值
 */
class HookManager
{
    private static ?HookManager $instance = null;

    protected HookMonitor $monitor;
    protected array $config = [];
    protected int $maxAttempts = 3;
    protected int $retryDelaySeconds = 10;

    /**
     * @var array<string, array<int, array<int, callable>>> $actions
     */
    protected array $actions = [];

    /**
     * @var array<string, array<int, array<int, callable>>> $filters
     */
    protected array $filters = [];

    private function __construct()
    {
        $this->config = Config::get('hook', []);
        $this->monitor = new HookMonitor();
        $this->maxAttempts = (int) ($this->config['dlq']['max_attempts'] ?? 3);
        $this->retryDelaySeconds = (int) ($this->config['dlq']['retry_delay_seconds'] ?? 10);
    }

    public static function instance(): HookManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 注册钩子（兼容文档中的 hook() 入口）
     *
     * @param string   $tag      钩子名
     * @param callable $callback 回调
     * @param int      $priority 优先级（数字越小越先执行）
     * @param string   $type     action|filter
     */
    public function hook(string $tag, callable $callback, int $priority = 10, string $type = 'action'): void
    {
        if ($type === 'filter') {
            $this->addFilter($tag, $callback, $priority);
            return;
        }

        $this->addAction($tag, $callback, $priority);
    }

    /**
     * 注册 Action 钩子
     */
    public function addAction(string $tag, callable $callback, int $priority = 10): void
    {
        $this->actions[$tag][$priority][] = $callback;
    }

    /**
     * 注册 Filter 钩子
     */
    public function addFilter(string $tag, callable $callback, int $priority = 10): void
    {
        $this->filters[$tag][$priority][] = $callback;
    }

    /**
     * 触发 Action 钩子
     */
    public function doAction(string $tag, mixed ...$args): void
    {
        $this->doActionWithContext($tag, $args, EventContext::fromGlobals());
    }

    /**
     * 执行 Action 钩子（兼容方法名）
     *
     * @param string $tag 钩子名称
     * @param array $args 参数
     * @return mixed 执行结果
     */
    public function executeAction(string $tag, array $args = []): mixed
    {
        return $this->doActionWithContext($tag, $args, EventContext::fromGlobals(), false, true);
    }

    /**
     * 应用 Filter 链
     *
     * @param string $tag   钩子名
     * @param mixed  $value 初始值
     */
    public function applyFilter(string $tag, mixed $value, mixed ...$args): mixed
    {
        return $this->applyFilterWithContext($tag, $value, $args, EventContext::fromGlobals());
    }

    /**
     * 清空所有钩子（用于测试）
     */
    public function clear(): void
    {
        $this->actions = [];
        $this->filters = [];
    }

    /**
     * 移除指定插件的所有钩子 / Remove all hooks for a specific plugin
     *
     * @param string $pluginId 插件ID / Plugin ID
     */
    public function removePluginHooks(string $pluginId): void
    {
        // 移除 Action 钩子
        foreach ($this->actions as $tag => $priorities) {
            foreach ($priorities as $priority => $callbacks) {
                foreach ($callbacks as $index => $callback) {
                    // 检查回调是否属于指定插件
                    if ($this->isCallbackFromPlugin($callback, $pluginId)) {
                        unset($this->actions[$tag][$priority][$index]);
                    }
                }
                // 移除空的优先级组
                if (empty($this->actions[$tag][$priority])) {
                    unset($this->actions[$tag][$priority]);
                }
            }
            // 移除空的标签组
            if (empty($this->actions[$tag])) {
                unset($this->actions[$tag]);
            }
        }

        // 移除 Filter 钩子
        foreach ($this->filters as $tag => $priorities) {
            foreach ($priorities as $priority => $callbacks) {
                foreach ($callbacks as $index => $callback) {
                    // 检查回调是否属于指定插件
                    if ($this->isCallbackFromPlugin($callback, $pluginId)) {
                        unset($this->filters[$tag][$priority][$index]);
                    }
                }
                // 移除空的优先级组
                if (empty($this->filters[$tag][$priority])) {
                    unset($this->filters[$tag][$priority]);
                }
            }
            // 移除空的标签组
            if (empty($this->filters[$tag])) {
                unset($this->filters[$tag]);
            }
        }
    }

    /**
     * 检查回调是否属于指定插件 / Check if callback belongs to specific plugin
     *
     * @param callable $callback 回调函数 / Callback function
     * @param string $pluginId 插件ID / Plugin ID
     * @return bool 是否属于插件 / Whether belongs to plugin
     */
    private function isCallbackFromPlugin(callable $callback, string $pluginId): bool
    {
        // 对于对象方法，检查对象是否为插件实例
        if (is_array($callback) && is_object($callback[0])) {
            $object = $callback[0];
            // 检查对象是否有 getIdentifier / getKey 方法
            if (method_exists($object, 'getIdentifier') && $object->getIdentifier() === $pluginId) {
                return true;
            }

            if (method_exists($object, 'getKey') && $object->getKey() === $pluginId) {
                return true;
            }
        }

        // 对于闭包，无法直接判断，暂时返回 false
        return false;
    }

    /**
     * 获取所有已注册的钩子标签 / Get all registered hook tags
     *
     * @return array<string> 钩子标签列表 / List of hook tags
     */
    public function getRegisteredTags(): array
    {
        return array_unique(array_merge(
            array_keys($this->actions),
            array_keys($this->filters)
        ));
    }

    /**
     * 获取指定标签的钩子数量 / Get hook count for specific tag
     *
     * @param string $tag 钩子标签 / Hook tag
     * @return int 钩子数量 / Hook count
     */
    public function getHookCount(string $tag): int
    {
        $actionCount = isset($this->actions[$tag]) ?
            array_sum(array_map('count', $this->actions[$tag])) : 0;
        $filterCount = isset($this->filters[$tag]) ?
            array_sum(array_map('count', $this->filters[$tag])) : 0;

        return $actionCount + $filterCount;
    }

    /**
     * 将 callback 按优先级排序并展开
     *
     * @param array<int, array<int, callable>> $byPriority
     * @return array<int, callable>
     */
    protected function sortedCallbacks(array $byPriority): array
    {
        ksort($byPriority);
        $callbacks = [];
        foreach ($byPriority as $items) {
            foreach ($items as $callback) {
                $callbacks[] = $callback;
            }
        }
        return $callbacks;
    }

    /**
     * 支持上下文/停止传播/监控的 Action 执行。
     *
     * @param array<int, mixed> $args
     * @param bool $propagateException 是否向上抛出异常（异步队列用来重试）
     * @param bool $returnLastResult 是否返回最后一次执行结果（executeAction 兼容）
     */
    public function doActionWithContext(
        string $tag,
        array $args = [],
        ?EventContext $context = null,
        bool $propagateException = false,
        bool $returnLastResult = false
    ): mixed {
        if (!isset($this->actions[$tag])) {
            return $returnLastResult ? null : null;
        }

        $ctx = $context ?? EventContext::fromGlobals();
        $start = microtime(true);
        $failed = false;
        $result = null;

        foreach ($this->sortedCallbacks($this->actions[$tag]) as $callback) {
            try {
                $result = $callback(...$this->buildCallbackArgs($callback, $args, $ctx));
                if ($this->shouldStopPropagation($result)) {
                    break;
                }
            } catch (\Throwable $e) {
                $failed = true;
                Log::warning('Action hook failed', [
                    'tag' => $tag,
                    'error' => $e->getMessage(),
                    'trace_id' => $ctx->getTraceId(),
                ]);
                if ($propagateException) {
                    $this->monitor->recordMetric($tag, 'action', $ctx, (microtime(true) - $start) * 1000, true);
                    throw $e;
                }
            }
        }

        $this->monitor->recordMetric($tag, 'action', $ctx, (microtime(true) - $start) * 1000, $failed);

        return $returnLastResult ? $result : null;
    }

    /**
     * 支持上下文/停止传播/监控的 Filter 链。
     *
     * @param array<int, mixed> $args
     */
    public function applyFilterWithContext(
        string $tag,
        mixed $value,
        array $args = [],
        ?EventContext $context = null
    ): mixed {
        if (!isset($this->filters[$tag])) {
            return $value;
        }

        $ctx = $context ?? EventContext::fromGlobals();
        $start = microtime(true);
        $failed = false;
        $current = $value;

        foreach ($this->sortedCallbacks($this->filters[$tag]) as $callback) {
            try {
                $result = $callback(...$this->buildCallbackArgs($callback, array_merge([$current], $args), $ctx));
                if ($result instanceof HookResult) {
                    $current = $result->getValue();
                    if ($result->shouldStopPropagation()) {
                        break;
                    }
                    continue;
                }

                if ($this->shouldStopPropagation($result)) {
                    break;
                }

                $current = $result;
            } catch (\Throwable $e) {
                $failed = true;
                Log::warning('Filter hook failed', [
                    'tag' => $tag,
                    'error' => $e->getMessage(),
                    'trace_id' => $ctx->getTraceId(),
                ]);
            }
        }

        $this->monitor->recordMetric($tag, 'filter', $ctx, (microtime(true) - $start) * 1000, $failed);

        return $current;
    }

    /**
     * 异步执行 Action 钩子：无协程时回退队列。
     *
     * @param array<int, mixed> $args
     */
    public function doActionAsync(
        string $tag,
        array $args = [],
        ?EventContext $context = null,
        string $queue = 'default'
    ): void {
        $ctx = $context ?? EventContext::fromGlobals();
        Queue::push(AsyncHookJob::class, [
            'tag' => $tag,
            'args' => $args,
            'context' => $ctx->toArray(),
        ], $queue);
    }

    /**
     * 使用协程执行 Action 钩子，无协程时回退队列保持异步语义。
     *
     * @param array<int, mixed> $args
     * @param callable|null $coroutineRunner 自定义协程执行器，签名 fn(callable $fn): void
     */
    public function doActionAsyncCoroutine(
        string $tag,
        array $args = [],
        ?EventContext $context = null,
        ?callable $coroutineRunner = null
    ): void {
        $ctx = $context ?? EventContext::fromGlobals();
        $runner = $coroutineRunner ?? $this->defaultCoroutineRunner();
        if ($runner !== null) {
            $runner(function () use ($tag, $args, $ctx) {
                $this->doActionWithContext($tag, $args, $ctx);
            });
            return;
        }

        $this->doActionAsync($tag, $args, $ctx);
    }

    /**
     * 判断是否应停止传播：返回 false 或 HookResult::stop()。
     */
    protected function shouldStopPropagation(mixed $result): bool
    {
        if ($result === false) {
            return true;
        }

        return $result instanceof HookResult && $result->shouldStopPropagation();
    }

    /**
     * 根据回调参数个数决定是否注入上下文，避免破坏已有签名。
     *
     * @param array<int, mixed> $args
     * @return array<int, mixed>
     */
    protected function buildCallbackArgs(callable $callback, array $args, EventContext $context): array
    {
        try {
            if (is_array($callback)) {
                $ref = new \ReflectionMethod($callback[0], $callback[1]);
            } elseif (is_string($callback) && str_contains($callback, '::')) {
                $ref = new \ReflectionMethod($callback);
            } else {
                $ref = new \ReflectionFunction($callback);
            }

            if ($ref->isVariadic() || $ref->getNumberOfParameters() > count($args)) {
                return [...$args, $context];
            }
        } catch (\Throwable) {
            // 反射失败时保持原样，避免影响旧逻辑
        }

        return $args;
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
}
