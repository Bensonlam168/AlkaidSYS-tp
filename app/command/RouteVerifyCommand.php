<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * Route Verification Command | 路由校验命令
 *
 * Verifies that registered routes match the documentation.
 * 校验注册的路由与文档是否一致。
 *
 * Usage | 用法:
 *   php think route:verify              # Verify routes against documentation
 *   php think route:verify --doc=...    # Specify documentation file
 *   php think route:verify --strict     # Fail on any difference
 *
 * Exit codes | 退出码:
 *   0 - Routes match documentation | 路由与文档一致
 *   1 - Routes differ from documentation | 路由与文档不一致
 *
 * @package app\command
 */
class RouteVerifyCommand extends Command
{
    /**
     * Default documentation path | 默认文档路径
     */
    protected const DEFAULT_DOC = 'docs/technical-specs/api/route-reference.md';

    /**
     * Configure command | 配置命令
     */
    protected function configure(): void
    {
        $this->setName('route:verify')
            ->setDescription('Verify routes against documentation | 校验路由与文档一致性')
            ->addOption('doc', 'd', Option::VALUE_OPTIONAL, 'Documentation file path', self::DEFAULT_DOC)
            ->addOption('strict', 's', Option::VALUE_NONE, 'Fail on any difference');
    }

    /**
     * Execute command | 执行命令
     */
    protected function execute(Input $input, Output $output): int
    {
        $docPath = $input->getOption('doc');
        $strict = $input->getOption('strict');

        $output->writeln('<info>Verifying routes against documentation...</info>');
        $output->writeln('<info>校验路由与文档一致性...</info>');
        $output->writeln('');

        // Load all route files | 加载所有路由文件
        $this->loadRoutes();

        // Get current routes | 获取当前路由
        $currentRoutes = $this->getRouteList();

        // Read documentation | 读取文档
        $fullPath = $this->app->getRootPath() . $docPath;
        if (!file_exists($fullPath)) {
            $output->writeln(sprintf('<error>Documentation not found: %s</error>', $docPath));
            $output->writeln('<comment>Run `php think route:doc` to generate it.</comment>');
            return 1;
        }

        $docContent = file_get_contents($fullPath);
        $docRoutes = $this->parseDocumentation($docContent);

        // Compare routes | 比较路由
        $result = $this->compareRoutes($currentRoutes, $docRoutes);

        // Output results | 输出结果
        $this->outputResults($output, $result);

        // Determine exit code | 确定退出码
        $hasIssues = !empty($result['missing']) || !empty($result['extra']);
        if ($strict && !empty($result['different'])) {
            $hasIssues = true;
        }

        if ($hasIssues) {
            $output->writeln('');
            $output->writeln('<error>Routes do not match documentation!</error>');
            $output->writeln('<error>路由与文档不一致！</error>');
            $output->writeln('<comment>Run `php think route:doc` to update documentation.</comment>');
            return 1;
        }

        $output->writeln('');
        $output->writeln('<info>✓ Routes match documentation | 路由与文档一致</info>');
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

            $method = strtoupper($rule['method'] ?? 'GET');
            $path = '/' . ltrim($rule['rule'] ?? '', '/');

            $routes[$method . ' ' . $path] = [
                'method' => $method,
                'path' => $path,
                'controller' => $this->formatController($route),
            ];
        }

        return $routes;
    }

    /**
     * Parse documentation to extract routes | 解析文档提取路由
     */
    protected function parseDocumentation(string $content): array
    {
        $routes = [];

        // Match table rows | 匹配表格行
        // Format: | METHOD | `path` | Controller | Middleware |
        preg_match_all('/\|\s*(\w+)\s*\|\s*`([^`]+)`\s*\|\s*([^|]+)\s*\|/', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $method = strtoupper(trim($match[1]));
            $path = trim($match[2]);
            $controller = trim($match[3]);

            // Skip header row | 跳过表头行
            if ($method === 'METHOD' || $path === 'Route') {
                continue;
            }

            $routes[$method . ' ' . $path] = [
                'method' => $method,
                'path' => $path,
                'controller' => $controller,
            ];
        }

        return $routes;
    }

    /**
     * Compare current routes with documented routes | 比较当前路由与文档路由
     */
    protected function compareRoutes(array $current, array $documented): array
    {
        $missing = [];  // In current but not in doc | 在当前但不在文档中
        $extra = [];    // In doc but not in current | 在文档但不在当前中
        $different = []; // Different controller | 控制器不同

        // Find missing routes (in current but not documented)
        foreach ($current as $key => $route) {
            if (!isset($documented[$key])) {
                $missing[] = $route;
            } elseif ($route['controller'] !== $documented[$key]['controller']) {
                $different[] = [
                    'route' => $route,
                    'documented' => $documented[$key]['controller'],
                    'actual' => $route['controller'],
                ];
            }
        }

        // Find extra routes (documented but not in current)
        foreach ($documented as $key => $route) {
            if (!isset($current[$key])) {
                $extra[] = $route;
            }
        }

        return [
            'missing' => $missing,
            'extra' => $extra,
            'different' => $different,
            'total_current' => count($current),
            'total_documented' => count($documented),
        ];
    }

    /**
     * Output comparison results | 输出比较结果
     */
    protected function outputResults(Output $output, array $result): void
    {
        $output->writeln(sprintf(
            'Current routes: %d | Documented routes: %d',
            $result['total_current'],
            $result['total_documented']
        ));
        $output->writeln('');

        if (!empty($result['missing'])) {
            $output->writeln('<comment>Routes not in documentation (需要添加到文档):</comment>');
            foreach ($result['missing'] as $route) {
                $output->writeln(sprintf(
                    '  + %s %s -> %s',
                    $route['method'],
                    $route['path'],
                    $route['controller']
                ));
            }
            $output->writeln('');
        }

        if (!empty($result['extra'])) {
            $output->writeln('<comment>Routes in documentation but not registered (需要从文档移除):</comment>');
            foreach ($result['extra'] as $route) {
                $output->writeln(sprintf(
                    '  - %s %s -> %s',
                    $route['method'],
                    $route['path'],
                    $route['controller']
                ));
            }
            $output->writeln('');
        }

        if (!empty($result['different'])) {
            $output->writeln('<comment>Routes with different controllers (控制器不一致):</comment>');
            foreach ($result['different'] as $diff) {
                $output->writeln(sprintf(
                    '  ~ %s %s',
                    $diff['route']['method'],
                    $diff['route']['path']
                ));
                $output->writeln(sprintf('    Doc: %s', $diff['documented']));
                $output->writeln(sprintf('    Now: %s', $diff['actual']));
            }
            $output->writeln('');
        }

        if (empty($result['missing']) && empty($result['extra']) && empty($result['different'])) {
            $output->writeln('<info>No differences found | 未发现差异</info>');
        }
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
