<?php

declare(strict_types=1);

namespace app\command\lowcode;

use app\command\base\LowcodeCommand;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\Generator\CrudGenerator;
use Infrastructure\Lowcode\Generator\ControllerGenerator;
use Infrastructure\Lowcode\Generator\RouteGenerator;
use Infrastructure\Lowcode\Generator\TestGenerator;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * Generate Lowcode Code Command | 生成低代码代码命令
 *
 * Generates CRUD code (Controller, Routes, Tests) for a Collection.
 * 为Collection生成CRUD代码（控制器、路由、测试）。
 *
 * Usage: php think lowcode:generate crud Product
 * 用法: php think lowcode:generate crud Product
 *
 * @package app\command\lowcode
 */
class GenerateCommand extends LowcodeCommand
{
    /**
     * Configure command | 配置命令
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('lowcode:generate')
            ->setDescription('Generate CRUD code for a Collection | 为Collection生成CRUD代码')
            ->addArgument('type', Argument::REQUIRED, 'Generation type (crud/controller/routes/tests) | 生成类型（crud/controller/routes/tests）')
            ->addArgument('name', Argument::REQUIRED, 'Collection name | Collection名称')
            ->addOption('tenant-id', null, Option::VALUE_OPTIONAL, 'Tenant ID (default: 0) | 租户ID（默认：0）', '0')
            ->addOption('force', 'f', Option::VALUE_NONE, 'Force overwrite existing files | 强制覆盖现有文件')
            ->addOption('no-tests', null, Option::VALUE_NONE, 'Skip test generation | 跳过测试生成')
            ->addOption('no-routes', null, Option::VALUE_NONE, 'Skip route generation | 跳过路由生成')
            ->addOption('append-routes', null, Option::VALUE_NONE, 'Append routes to route file | 追加路由到路由文件');
    }

    /**
     * Execute command | 执行命令
     *
     * @param Input $input Input instance | 输入实例
     * @param Output $output Output instance | 输出实例
     * @return int Exit code | 退出码
     */
    protected function execute(Input $input, Output $output): int
    {
        try {
            $this->section('Generating Lowcode Code | 生成低代码代码');

            // Get arguments and options | 获取参数和选项
            $type = $input->getArgument('type');
            $name = $input->getArgument('name');
            $tenantId = $this->getTenantId();
            $force = $input->getOption('force');
            $noTests = $input->getOption('no-tests');
            $noRoutes = $input->getOption('no-routes');
            $appendRoutes = $input->getOption('append-routes');

            // Validate type | 验证类型
            $validTypes = ['crud', 'controller', 'routes', 'tests'];
            if (!in_array($type, $validTypes, true)) {
                $this->error("Invalid type: {$type}. Valid types: " . implode(', ', $validTypes));
                return 1;
            }

            // Get collection | 获取Collection
            $collectionManager = app()->make(CollectionManager::class);
            $collection = $collectionManager->get($name, $tenantId);

            if (!$collection) {
                $this->error("Collection '{$name}' not found.");
                $this->info("Create it first: php think lowcode:create-model {$name}");
                return 1;
            }

            $this->success("Found collection: {$name}");

            // Prepare generation options | 准备生成选项
            $options = [
                'force' => $force,
                'controller' => $type === 'crud' || $type === 'controller',
                'routes' => ($type === 'crud' || $type === 'routes') && !$noRoutes,
                'tests' => ($type === 'crud' || $type === 'tests') && !$noTests,
                'append_to_file' => $appendRoutes,
            ];

            // Generate code | 生成代码
            $this->info('Generating code...');
            $files = $this->generateCode($collection, $type, $options);

            // Display results | 显示结果
            $this->displayResults($files);

            $this->success('Code generation completed!');

            // Show next steps | 显示下一步操作
            $this->showNextSteps($name, $files);

            return 0;
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Generate code based on type | 根据类型生成代码
     *
     * @param \Domain\Lowcode\Collection\Interfaces\CollectionInterface $collection Collection instance | Collection实例
     * @param string $type Generation type | 生成类型
     * @param array $options Generation options | 生成选项
     * @return array Generated files | 生成的文件
     */
    protected function generateCode($collection, string $type, array $options): array
    {
        $files = [];

        if ($type === 'crud') {
            // Generate complete CRUD | 生成完整CRUD
            $generator = new CrudGenerator(
                new ControllerGenerator(),
                new RouteGenerator(),
                new TestGenerator()
            );
            $files = $generator->generate($collection, $options);
        } elseif ($type === 'controller') {
            // Generate controller only | 仅生成控制器
            $generator = new ControllerGenerator();
            $files['controller'] = $generator->generate($collection, $options);
        } elseif ($type === 'routes') {
            // Generate routes only | 仅生成路由
            $generator = new RouteGenerator();
            $files['routes'] = $generator->generate($collection, $options);
        } elseif ($type === 'tests') {
            // Generate tests only | 仅生成测试
            $generator = new TestGenerator();
            $files['tests'] = $generator->generate($collection, $options);
        }

        return $files;
    }

    /**
     * Display generation results | 显示生成结果
     *
     * @param array $files Generated files | 生成的文件
     * @return void
     */
    protected function displayResults(array $files): void
    {
        $this->output->writeln('');
        $this->info('Generated files:');

        foreach ($files as $type => $path) {
            if (is_string($path)) {
                $this->output->writeln("  [{$type}] {$path}");
            }
        }

        $this->output->writeln('');
        $this->info('Total files generated: ' . count($files));
    }

    /**
     * Show next steps | 显示下一步操作
     *
     * @param string $name Collection name | Collection名称
     * @param array $files Generated files | 生成的文件
     * @return void
     */
    protected function showNextSteps(string $name, array $files): void
    {
        $this->output->writeln('');
        $this->info('Next steps:');

        if (isset($files['controller'])) {
            $this->output->writeln("  1. Review the generated controller: {$files['controller']}");
        }

        if (isset($files['routes'])) {
            $this->output->writeln('  2. Include the route file in your route configuration');
        }

        if (isset($files['tests'])) {
            $this->output->writeln("  3. Run tests: php think test {$files['tests']}");
        }

        $resourceName = strtolower($name);
        $this->output->writeln("  4. Test the API: GET /v1/{$resourceName}");
        $this->output->writeln('');
    }
}
