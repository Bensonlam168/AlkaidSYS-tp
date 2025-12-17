<?php

declare(strict_types=1);

namespace Infrastructure\Plugin\Hook;

use think\facade\Log;

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

    /**
     * @var array<string, array<int, array<int, callable>>> $actions
     */
    protected array $actions = [];

    /**
     * @var array<string, array<int, array<int, callable>>> $filters
     */
    protected array $filters = [];

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
        if (!isset($this->actions[$tag])) {
            return;
        }

        foreach ($this->sortedCallbacks($this->actions[$tag]) as $callback) {
            try {
                $callback(...$args);
            } catch (\Throwable $e) {
                // Action 钩子异常不阻断主流程
                Log::warning('Action hook failed', [
                    'tag' => $tag,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 应用 Filter 链
     *
     * @param string $tag   钩子名
     * @param mixed  $value 初始值
     */
    public function applyFilter(string $tag, mixed $value, mixed ...$args): mixed
    {
        if (!isset($this->filters[$tag])) {
            return $value;
        }

        foreach ($this->sortedCallbacks($this->filters[$tag]) as $callback) {
            try {
                $value = $callback($value, ...$args);
            } catch (\Throwable $e) {
                // Filter 异常不应导致系统崩溃，但会保留当前 value
                Log::warning('Filter hook failed', [
                    'tag' => $tag,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $value;
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
}

