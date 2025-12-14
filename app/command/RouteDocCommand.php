<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * Route Documentation Generator Command | 路由文档生成命令
 *
 * Generates API route documentation from registered routes.
 * 从注册的路由生成 API 路由文档。
 *
 * Usage | 用法:
 *   php think route:doc              # Generate route documentation
 *   php think route:doc --output=... # Specify output file
 *   php think route:doc --format=md  # Specify output format (md/json)
 *
 * @package app\command
 */
class RouteDocCommand extends Command
{
    /**
     * Default output path | 默认输出路径
     */
    protected const DEFAULT_OUTPUT = 'docs/technical-specs/api/route-reference.md';

    /**
     * Configure command | 配置命令
     */
    protected function configure(): void
    {
        $this->setName('route:doc')
            ->setDescription('Generate API route documentation | 生成 API 路由文档')
            ->addOption('output', 'o', Option::VALUE_OPTIONAL, 'Output file path', self::DEFAULT_OUTPUT)
            ->addOption('format', 'f', Option::VALUE_OPTIONAL, 'Output format (md/json)', 'md');
    }

    /**
     * Execute command | 执行命令
     */
    protected function execute(Input $input, Output $output): int
    {
        $outputPath = $input->getOption('output');
        $format = $input->getOption('format');

        $output->writeln('<info>Generating route documentation...</info>');
        $output->writeln('<info>生成路由文档中...</info>');

        // Load all route files | 加载所有路由文件
        $this->loadRoutes();

        // Get route list | 获取路由列表
        $routes = $this->getRouteList();

        if (empty($routes)) {
            $output->writeln('<comment>No routes found | 未找到路由</comment>');
            return 0;
        }

        $routeCount = count($routes);
        $output->writeln(sprintf('<info>Found %d routes | 找到 %d 条路由</info>', $routeCount, $routeCount));

        // Generate documentation | 生成文档
        if ($format === 'json') {
            $content = $this->generateJson($routes);
        } else {
            $content = $this->generateMarkdown($routes);
        }

        // Write to file | 写入文件
        $fullPath = $this->app->getRootPath() . $outputPath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, $content);

        $output->writeln(sprintf('<info>Documentation generated: %s</info>', $outputPath));
        $output->writeln(sprintf('<info>文档已生成: %s</info>', $outputPath));

        return 0;
    }

    /**
     * Load all route files | 加载所有路由文件
     */
    protected function loadRoutes(): void
    {
        $routePath = $this->app->getRootPath() . 'route/';
        $files = glob($routePath . '*.php');

        foreach ($files as $file) {
            include_once $file;
        }
    }

    /**
     * Get route list | 获取路由列表
     *
     * @return array Route list | 路由列表
     */
    protected function getRouteList(): array
    {
        $rules = $this->app->route->getRuleList();
        $routes = [];

        foreach ($rules as $rule) {
            $route = $rule['route'] ?? '';
            if ($route instanceof \Closure) {
                $route = 'Closure';
            } elseif (is_array($route)) {
                $route = json_encode($route);
            }

            $routes[] = [
                'method' => strtoupper($rule['method'] ?? 'GET'),
                'rule' => '/' . ltrim($rule['rule'] ?? '', '/'),
                'route' => $route,
                'option' => $rule['option'] ?? [],
            ];
        }

        // Sort by rule | 按规则排序
        usort($routes, fn ($a, $b) => strcmp($a['rule'], $b['rule']));

        return $routes;
    }

    /**
     * Generate JSON output | 生成 JSON 输出
     */
    protected function generateJson(array $routes): string
    {
        return json_encode([
            'version' => '1.0.0',
            'generated_at' => date('Y-m-d H:i:s'),
            'routes' => $routes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Generate Markdown output | 生成 Markdown 输出
     */
    protected function generateMarkdown(array $routes): string
    {
        $grouped = $this->groupRoutes($routes);
        return $this->renderMarkdown($grouped);
    }

    /**
     * Group routes by prefix | 按前缀分组路由
     */
    protected function groupRoutes(array $routes): array
    {
        $groups = [
            'auth' => ['title' => 'Authentication Routes | 认证路由', 'routes' => []],
            'lowcode/collections' => ['title' => 'Lowcode Collection Routes | 低代码集合路由', 'routes' => []],
            'lowcode/forms' => ['title' => 'Lowcode Form Routes | 低代码表单路由', 'routes' => []],
            'admin' => ['title' => 'Admin Routes | 管理路由', 'routes' => []],
            'debug' => ['title' => 'Debug Routes | 调试路由', 'routes' => []],
            'other' => ['title' => 'Other Routes | 其他路由', 'routes' => []],
        ];

        foreach ($routes as $route) {
            $rule = $route['rule'];
            $matched = false;

            foreach (array_keys($groups) as $prefix) {
                if ($prefix === 'other') {
                    continue;
                }
                if (str_contains($rule, $prefix)) {
                    $groups[$prefix]['routes'][] = $route;
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $groups['other']['routes'][] = $route;
            }
        }

        // Remove empty groups | 移除空分组
        return array_filter($groups, fn ($g) => !empty($g['routes']));
    }

    /**
     * Render Markdown document | 渲染 Markdown 文档
     */
    protected function renderMarkdown(array $grouped): string
    {
        $lines = [];
        $lines[] = '# API Route Reference | API 路由参考';
        $lines[] = '';
        $lines[] = sprintf('> Version: 1.0.0 | Last Updated: %s', date('Y-m-d'));
        $lines[] = '> Auto-generated by `php think route:doc`';
        $lines[] = '';
        $lines[] = '## Overview | 概述';
        $lines[] = '';
        $lines[] = 'This document lists all API routes in AlkaidSYS-tp.';
        $lines[] = '本文档列出 AlkaidSYS-tp 中的所有 API 路由。';
        $lines[] = '';

        foreach ($grouped as $group) {
            $lines[] = sprintf('## %s', $group['title']);
            $lines[] = '';
            $lines[] = '| Method | Route | Controller | Middleware |';
            $lines[] = '|--------|-------|------------|------------|';

            foreach ($group['routes'] as $route) {
                $middleware = $this->formatMiddleware($route['option']['middleware'] ?? []);
                $controller = $this->formatController($route['route']);
                $lines[] = sprintf(
                    '| %s | `%s` | %s | %s |',
                    $route['method'],
                    $route['rule'],
                    $controller,
                    $middleware
                );
            }

            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## Route Files | 路由文件';
        $lines[] = '';
        $lines[] = '| File | Description |';
        $lines[] = '|------|-------------|';
        $lines[] = '| `route/auth.php` | Authentication routes | 认证路由 |';
        $lines[] = '| `route/lowcode.php` | Lowcode API routes | 低代码 API 路由 |';
        $lines[] = '| `route/admin.php` | Admin routes | 管理路由 |';
        $lines[] = '| `route/app.php` | Application routes | 应用路由 |';
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '**Generated by**: `php think route:doc`';
        $lines[] = sprintf('**Last Updated**: %s', date('Y-m-d H:i:s'));
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Format middleware for display | 格式化中间件显示
     */
    protected function formatMiddleware(mixed $middleware): string
    {
        if (empty($middleware)) {
            return '-';
        }

        if (is_string($middleware)) {
            $middleware = [$middleware];
        }

        $names = [];
        foreach ($middleware as $m) {
            if (is_string($m)) {
                // Extract class name | 提取类名
                $parts = explode('\\', $m);
                $name = end($parts);
                // Remove ::class suffix if present
                $name = str_replace('::class', '', $name);
                // Handle middleware with parameters
                if (str_contains($name, ':')) {
                    [$name] = explode(':', $name);
                }
                $names[] = $name;
            }
        }

        return implode(', ', array_unique($names)) ?: '-';
    }

    /**
     * Format controller for display | 格式化控制器显示
     */
    protected function formatController(string $route): string
    {
        if ($route === 'Closure') {
            return 'Closure';
        }

        // Extract controller@method | 提取控制器@方法
        $parts = explode('@', $route);
        if (count($parts) === 2) {
            $controller = $parts[0];
            $method = $parts[1];

            // Extract class name | 提取类名
            $classParts = explode('\\', $controller);
            $className = end($classParts);

            return sprintf('%s@%s', $className, $method);
        }

        return $route;
    }
}
