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
     * Route group definitions | 路由分组定义
     *
     * 键为「路径段前缀」（不含首尾斜杠），值为文档中的章节标题。
     *
     * 匹配规则（见 matchPrefix()）：按**路径段**做前缀匹配，即
     * `/v1/admin` 命中 `/v1/admin` 与 `/v1/admin/tenants`，
     * 但**不**命中 `/v1/administrator` 这类「同名开头的其它段」。
     * 旧实现用 str_contains($rule, $prefix) 做子串匹配，任何路径里出现
     * admin / auth 字样都会被误分（例如将来出现 /v1/authoring 会被塞进 auth）。
     *
     * **顺序即匹配优先级**：先命中先归属，取第一个匹配项。
     * 因此约定：**深（长）前缀必须排在其父前缀之前**
     * （如 v1/lowcode/collections 必须排在假想的 v1/lowcode 之前），
     * 否则父前缀会把子前缀的路由全部吃掉。
     * 当前 6 个前缀在段边界上互不重叠，顺序对结果无影响，
     * 这里保留「认证 → 低代码 → 管理 → 工作流 → 调试」的历史章节顺序，减少读者困惑。
     *
     * ⚠️ 新增顶层前缀（如未来的 v1/plugin、v1/system、v1/health）时，
     * 必须在此登记，否则该前缀的路由会静默落入 Other 桶。
     *
     * @var array<string, string>
     */
    protected const ROUTE_GROUPS = [
        'v1/auth' => 'Authentication Routes | 认证路由',
        'v1/lowcode/collections' => 'Lowcode Collection Routes | 低代码集合路由',
        'v1/lowcode/forms' => 'Lowcode Form Routes | 低代码表单路由',
        'v1/admin' => 'Admin Routes | 管理路由',
        'v1/workflow' => 'Workflow Routes | 工作流路由',
        'debug' => 'Debug Routes | 调试路由',
    ];

    /**
     * Fallback group key | 兜底分组的键
     *
     * 恒排在最后，且不参与前缀匹配（其它前缀全部不命中时才落进来）。
     */
    protected const OTHER_GROUP_KEY = 'other';

    /**
     * Fallback group title | 兜底分组的标题
     */
    protected const OTHER_GROUP_TITLE = 'Other Routes | 其他路由';

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
        // 先按路径、再按方法排序：同一路径（如 GET/POST /v1/admin/tenants）
        // 的先后顺序因此被完全固定，输出与 usort 的稳定性无关（幂等性要求）。
        usort($routes, fn ($a, $b) => strcmp($a['rule'], $b['rule']) ?: strcmp($a['method'], $b['method']));

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
     *
     * @param array $routes Route list | 路由列表
     * @return array Grouped routes (empty groups removed) | 分组后的路由（空分组已移除）
     */
    protected function groupRoutes(array $routes): array
    {
        // 按 ROUTE_GROUPS 的定义顺序建桶，bucket 顺序即文档章节顺序 | Build buckets in declared order
        $groups = [];
        foreach (self::ROUTE_GROUPS as $prefix => $title) {
            $groups[$prefix] = ['title' => $title, 'routes' => []];
        }
        // 兜底桶恒在最后 | Fallback bucket always last
        $groups[self::OTHER_GROUP_KEY] = ['title' => self::OTHER_GROUP_TITLE, 'routes' => []];

        foreach ($routes as $route) {
            $groups[$this->resolveGroupKey($route['rule'])]['routes'][] = $route;
        }

        // Remove empty groups | 移除空分组
        return array_filter($groups, fn ($g) => !empty($g['routes']));
    }

    /**
     * Resolve the group key of a single route | 判定单条路由所属分组
     *
     * @param string $rule Route rule, e.g. `/v1/admin/tenants/<id>` | 路由规则
     * @return string Matched prefix, or OTHER_GROUP_KEY | 命中的前缀，未命中则为 other
     */
    protected function resolveGroupKey(string $rule): string
    {
        $path = trim($rule, '/');

        foreach (array_keys(self::ROUTE_GROUPS) as $prefix) {
            if ($this->matchPrefix($path, $prefix)) {
                return $prefix;
            }
        }

        return self::OTHER_GROUP_KEY;
    }

    /**
     * Segment-aware prefix match | 路径段前缀匹配
     *
     * 命中 `p` 与其子路径 `p/xxx`，但不命中 `pXXX`：
     *   matchPrefix('v1/admin/tenants', 'v1/admin') => true
     *   matchPrefix('v1/admin', 'v1/admin')         => true
     *   matchPrefix('v1/administrator', 'v1/admin') => false（旧 str_contains 会误判为 true）
     *   matchPrefix('v1/authoring', 'v1/auth')      => false（同上）
     *
     * 没有沿用 str_starts_with(trim($rule,'/'), $prefix) 的裸字符前缀，
     * 是因为裸字符前缀仍会命中 v1/administrator 这类「同名开头的另一段」，
     * 只是把 str_contains 的误判范围缩小了一半，问题没根除。
     *
     * @param string $path   Normalized path (leading/trailing slashes trimmed) | 去首尾斜杠的路径
     * @param string $prefix Segment prefix (no slashes) | 不含斜杠的段前缀
     */
    protected function matchPrefix(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
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

        foreach ($grouped as $prefix => $group) {
            $lines[] = sprintf('## %s', $group['title']);
            $lines[] = '';

            // 兜底桶加一句说明：为什么这些路由不值得单独建桶 | Explain the fallback bucket
            if ($prefix === self::OTHER_GROUP_KEY) {
                $lines[] = '> 未命中任何已登记路径前缀的路由归入此桶（框架内置路由、示例路由等）。'
                    . '它们不在 `v1/` API 命名空间下，条数少且互不相关，单独建桶只会得到 1~2 条的碎片章节。';
                $lines[] = '';
            }

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
        // 说明列里的 `|` 必须转义成 `\|`：否则这一行会变成 3 个单元格，
        // 与 2 列的表头不匹配（GFM 下多余单元格被丢弃，中英说明只剩英文）。
        $lines[] = '| File | Description |';
        $lines[] = '|------|-------------|';
        $lines[] = '| `route/auth.php` | Authentication routes \| 认证路由 |';
        $lines[] = '| `route/lowcode.php` | Lowcode API routes \| 低代码 API 路由 |';
        $lines[] = '| `route/admin.php` | Admin routes \| 管理路由 |';
        $lines[] = '| `route/workflow.php` | Workflow engine API routes \| 工作流引擎 API 路由 |';
        $lines[] = '| `route/app.php` | Application routes \| 应用路由 |';
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '**Generated by**: `php think route:doc`';
        // 时间戳只精确到「日」：精确到秒会让每次重生成都产生 diff，
        // 破坏「同一天内重复生成产物逐字节相同」的幂等性（route:verify 需要文档稳定）。
        $lines[] = sprintf('**Last Updated**: %s', date('Y-m-d'));
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Format middleware for display | 格式化中间件显示
     *
     * `getRuleList()` 里 `option['middleware']` 的元素是 **[类名, 参数数组] 元组**，
     * 形如 `['app\middleware\Permission', ['dept.read']]`，
     * 不是类名字符串。旧实现只处理 `is_string($m)`，元组被整条跳过，
     * 结果 104 条实际挂了 Auth 中间件的路由在文档里全都显示成 `-`
     * （被误读成「无中间件」）。这里同时兼容元组与字符串两种形式，
     * 并把参数一并展示为 `Permission:dept.read`，读者才能看出这是被哪个权限码守着的。
     *
     * @param mixed $middleware Middleware option | 中间件配置
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
            // 形式 1：[类名, 参数数组] 元组（ThinkPHP 8 的 Rule::middleware() 载体）| tuple form
            if (is_array($m)) {
                $name = $this->middlewareName((string) ($m[0] ?? ''));
                $params = $m[1] ?? [];
                if (!is_array($params)) {
                    $params = [$params];
                }
                $params = array_values(array_filter(
                    array_map(static fn ($p) => is_scalar($p) ? (string) $p : '', $params),
                    static fn ($p) => $p !== ''
                ));
                if ($params !== []) {
                    $name .= ':' . implode(',', $params);
                }
                if ($name !== '') {
                    $names[] = $name;
                }
                continue;
            }

            // 形式 2：类名字符串，可能带 `:参数` 后缀 | plain string form
            if (is_string($m)) {
                $names[] = $this->middlewareName($m);
            }
        }

        return implode(', ', array_unique($names)) ?: '-';
    }

    /**
     * Extract short middleware name | 提取中间件短名
     *
     * `app\middleware\Permission` → `Permission`；
     * 带参数后缀的 `app\middleware\Permission:dept.read` → `Permission`。
     *
     * @param string $middleware Middleware class name | 中间件类名
     */
    protected function middlewareName(string $middleware): string
    {
        // Extract class name | 提取类名
        $parts = explode('\\', $middleware);
        $name = (string) end($parts);
        // Remove ::class suffix if present
        $name = str_replace('::class', '', $name);
        // Handle middleware with parameters | 剥掉参数后缀
        if (str_contains($name, ':')) {
            [$name] = explode(':', $name);
        }

        return trim($name);
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
