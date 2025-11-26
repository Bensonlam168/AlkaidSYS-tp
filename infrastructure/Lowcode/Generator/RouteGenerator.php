<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Generator;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;

/**
 * Route Generator | 路由生成器
 *
 * Generates route definitions for a Collection.
 * 为Collection生成路由定义。
 *
 * @package Infrastructure\Lowcode\Generator
 */
class RouteGenerator
{
    /**
     * Generate route definitions | 生成路由定义
     *
     * @param CollectionInterface $collection Collection instance | Collection实例
     * @param array $options Generation options | 生成选项
     * @return string Route definitions | 路由定义
     */
    public function generate(CollectionInterface $collection, array $options = []): string
    {
        $name = $collection->getName();
        $resourceName = $this->getResourceName($name);
        $controllerClass = $this->getControllerClass($name, $options);

        $routes = $this->generateRouteCode($resourceName, $controllerClass);

        // Optionally append to route file | 可选地追加到路由文件
        if ($options['append_to_file'] ?? false) {
            $this->appendToRouteFile($routes, $options);
        }

        return $routes;
    }

    /**
     * Get resource name from collection name | 从Collection名称获取资源名称
     *
     * @param string $name Collection name | Collection名称
     * @return string Resource name | 资源名称
     */
    protected function getResourceName(string $name): string
    {
        // Convert to lowercase and use underscores | 转换为小写并使用下划线
        return strtolower($name);
    }

    /**
     * Get controller class name | 获取控制器类名
     *
     * @param string $name Collection name | Collection名称
     * @param array $options Generation options | 生成选项
     * @return string Controller class | 控制器类
     */
    protected function getControllerClass(string $name, array $options): string
    {
        $namespace = $options['namespace'] ?? 'app\\controller\\lowcode';
        $parts = explode('_', $name);
        $parts = array_map('ucfirst', $parts);
        $className = implode('', $parts) . 'Controller';

        return $namespace . '\\' . $className;
    }

    /**
     * Generate route code | 生成路由代码
     *
     * @param string $resourceName Resource name | 资源名称
     * @param string $controllerClass Controller class | 控制器类
     * @return string Route code | 路由代码
     */
    protected function generateRouteCode(string $resourceName, string $controllerClass): string
    {
        return <<<PHP

// {$resourceName} routes (auto-generated)
Route::group('/{$resourceName}', function () {
    Route::get('/', '{$controllerClass}@index');
    Route::get('/<id>', '{$controllerClass}@read');
    Route::post('/', '{$controllerClass}@create');
    Route::put('/<id>', '{$controllerClass}@update');
    Route::delete('/<id>', '{$controllerClass}@delete');
})->middleware(['auth', 'permission', 'tenant', 'site']);

PHP;
    }

    /**
     * Append routes to route file | 追加路由到路由文件
     *
     * @param string $routes Route code | 路由代码
     * @param array $options Generation options | 生成选项
     * @return void
     */
    protected function appendToRouteFile(string $routes, array $options): void
    {
        $routeFile = $options['route_file'] ?? app()->getRootPath() . 'route/lowcode.php';

        if (!file_exists($routeFile)) {
            // Create route file with header | 创建带头部的路由文件
            $header = <<<PHP
<?php

declare(strict_types=1);

use think\facade\Route;

/**
 * Lowcode Routes | 低代码路由
 *
 * Auto-generated routes for lowcode collections.
 * 自动生成的低代码集合路由。
 */


PHP;
            file_put_contents($routeFile, $header);
        }

        file_put_contents($routeFile, $routes, FILE_APPEND);
    }
}
