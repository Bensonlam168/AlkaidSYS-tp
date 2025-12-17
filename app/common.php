<?php

// 应用公共文件

use Infrastructure\Plugin\Hook\HookManager;

if (!function_exists('hook')) {
    /**
     * 注册钩子（Action/Filter）
     *
     * @param string   $tag      钩子名称
     * @param callable $callback 回调
     * @param int      $priority 优先级（数字越小优先级越高）
     * @param string   $type     action|filter
     */
    function hook(string $tag, callable $callback, int $priority = 10, string $type = 'action'): void
    {
        HookManager::instance()->hook($tag, $callback, $priority, $type);
    }
}

if (!function_exists('do_action')) {
    /**
     * 触发 Action Hook（不关心返回值）
     */
    function do_action(string $tag, mixed ...$args): void
    {
        HookManager::instance()->doAction($tag, ...$args);
    }
}

if (!function_exists('apply_filter')) {
    /**
     * 应用 Filter Hook（链式变换并返回最终值）
     *
     * @param mixed $value 初始值
     */
    function apply_filter(string $tag, mixed $value, mixed ...$args): mixed
    {
        return HookManager::instance()->applyFilter($tag, $value, ...$args);
    }
}
