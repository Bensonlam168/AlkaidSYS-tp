<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Generator;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;

/**
 * CRUD Code Generator | CRUD代码生成器
 *
 * Generates complete CRUD code (Controller, Routes, Tests) for a Collection.
 * 为Collection生成完整的CRUD代码（控制器、路由、测试）。
 *
 * @package Infrastructure\Lowcode\Generator
 */
class CrudGenerator
{
    protected ControllerGenerator $controllerGenerator;
    protected RouteGenerator $routeGenerator;
    protected TestGenerator $testGenerator;

    /**
     * Constructor | 构造函数
     *
     * @param ControllerGenerator $controllerGenerator Controller generator | 控制器生成器
     * @param RouteGenerator $routeGenerator Route generator | 路由生成器
     * @param TestGenerator $testGenerator Test generator | 测试生成器
     */
    public function __construct(
        ControllerGenerator $controllerGenerator,
        RouteGenerator $routeGenerator,
        TestGenerator $testGenerator
    ) {
        $this->controllerGenerator = $controllerGenerator;
        $this->routeGenerator = $routeGenerator;
        $this->testGenerator = $testGenerator;
    }

    /**
     * Generate complete CRUD code | 生成完整的CRUD代码
     *
     * @param CollectionInterface $collection Collection instance | Collection实例
     * @param array $options Generation options | 生成选项
     * @return array Generated files | 生成的文件
     */
    public function generate(CollectionInterface $collection, array $options = []): array
    {
        $files = [];

        // Generate controller | 生成控制器
        if ($options['controller'] ?? true) {
            $files['controller'] = $this->controllerGenerator->generate($collection, $options);
        }

        // Generate routes | 生成路由
        if ($options['routes'] ?? true) {
            $files['routes'] = $this->routeGenerator->generate($collection, $options);
        }

        // Generate tests | 生成测试
        if ($options['tests'] ?? true) {
            $files['tests'] = $this->testGenerator->generate($collection, $options);
        }

        return $files;
    }

    /**
     * Get generation summary | 获取生成摘要
     *
     * @param array $files Generated files | 生成的文件
     * @return array Summary | 摘要
     */
    public function getSummary(array $files): array
    {
        return [
            'total_files' => count($files),
            'files' => array_keys($files),
            'controller' => $files['controller'] ?? null,
            'routes' => $files['routes'] ?? null,
            'tests' => $files['tests'] ?? null,
        ];
    }
}
