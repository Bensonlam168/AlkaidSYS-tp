<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Core;

use Alkaid\Workflow\Engine\core\ProcessEngines;
use Alkaid\Workflow\Engine\cfg\Configuration;
use Alkaid\Workflow\Engine\interface\IProcessEngines;
use Alkaid\Workflow\Engine\interface\IDict;
use Alkaid\Workflow\Engine\Tests\TestCase;
use madong\ingenious\libs\utils\Dict;

/**
 * 工作流引擎核心类测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Core
 */
class ProcessEnginesTest extends TestCase
{
    /**
     * @var ProcessEngines 工作流引擎实例
     */
    private ProcessEngines $engine;

    /**
     * 设置测试环境
     */
    protected function setupTestData(): void
    {
        parent::setupTestData();

        // 获取工作流引擎实例
        $this->engine = $this->getService('processEngines');

        if ($this->engine === null) {
            // 如果服务工厂未创建引擎，手动创建一个
            $config = [
                'service_dependencies' => [
                    'processDefineService' => $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessDefineService::class),
                    'processInstanceService' => $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessInstanceService::class),
                    'processTaskService' => $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class),
                    'processInstanceHistoryService' => $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessInstanceHistoryService::class),
                    'processTaskHistoryService' => $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskHistoryService::class),
                ],
            ];

            $this->engine = new ProcessEngines($config);
        }
    }

    /**
     * 测试引擎实例化
     */
    public function testEngineInstantiation(): void
    {
        $this->assertInstanceOf(ProcessEngines::class, $this->engine);
        $this->assertInstanceOf(IProcessEngines::class, $this->engine);
    }

    /**
     * 测试引擎配置
     */
    public function testEngineConfiguration(): void
    {
        // 引擎应该有配置对象
        $this->assertNotNull($this->engine->configuration);

        // 引擎应该可以被重新配置
        $newConfig = [
            'service_dependencies' => [
                'processDefineService' => $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessDefineService::class),
            ],
        ];

        $this->engine->configure($newConfig);
        $this->assertNotNull($this->engine->configuration);
    }

    /**
     * 测试服务获取
     */
    public function testServiceGetters(): void
    {
        // 模拟服务
        $mockDefineService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessDefineService::class);
        $mockInstanceService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessInstanceService::class);
        $mockTaskService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class);

        // 设置到容器
        $container = $this->getContainer();
        $container->register('processDefineService', $mockDefineService);
        $container->register('processInstanceService', $mockInstanceService);
        $container->register('processTaskService', $mockTaskService);

        // 测试服务获取
        $this->assertSame($mockDefineService, $this->engine->processDefineService());
        $this->assertSame($mockInstanceService, $this->engine->processInstanceService());
        $this->assertSame($mockTaskService, $this->engine->processTaskService());
    }

    /**
     * 测试启动工作流实例
     */
    public function testStartProcessInstance(): void
    {
        // 创建模拟定义服务
        $mockDefineService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessDefineService::class);
        $mockDefineService->method('getById')->willReturn(null);

        // 创建模拟实例服务
        $mockInstanceService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessInstanceService::class);
        $mockInstanceService->method('createProcessInstance')->willReturn(null);

        // 创建模拟任务历史服务
        $mockTaskHistoryService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskHistoryService::class);
        $mockTaskHistoryService->method('createHistoryProcessInstance')->willReturn(true);

        // 设置到容器
        $container = $this->getContainer();
        $container->register('processDefineService', $mockDefineService);
        $container->register('processInstanceService', $mockInstanceService);
        $container->register('processTaskHistoryService', $mockTaskHistoryService);

        // 创建测试参数
        $args = new Dict([
            'applicant_name' => '测试用户',
            'amount' => 1000,
        ]);

        // 尝试启动工作流实例（预期失败，因为定义不存在）
        $this->expectException(\Exception::class);
        $this->engine->startProcessInstanceById('nonexistent_id', '1', $args);
    }

    /**
     * 测试执行工作流任务
     */
    public function testExecuteProcessTask(): void
    {
        // 创建模拟任务服务
        $mockTaskService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class);
        $mockTaskService->method('getById')->willReturn(null);

        // 设置到容器
        $container = $this->getContainer();
        $container->register('processTaskService', $mockTaskService);

        // 创建测试参数
        $args = new Dict([
            'approved' => true,
            'comment' => '同意',
        ]);

        // 尝试执行任务（预期失败，因为任务不存在）
        $this->expectException(\Exception::class);
        $result = $this->engine->executeProcessTask('nonexistent_task_id', '1', $args);

        // 应该返回空数组
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 测试执行并跳转任务
     */
    public function testExecuteAndJumpTask(): void
    {
        // 创建模拟任务服务
        $mockTaskService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class);
        $mockTaskService->method('getById')->willReturn(null);

        // 设置到容器
        $container = $this->getContainer();
        $container->register('processTaskService', $mockTaskService);

        // 创建测试参数
        $args = new Dict([
            'approved' => true,
        ]);

        // 测试跳转
        $result = $this->engine->executeAndJumpTask('nonexistent_task_id', '1', $args, 'next_node');

        // 应该返回空数组
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 测试执行并跳转到结束
     */
    public function testExecuteAndJumpToEnd(): void
    {
        // 创建模拟任务服务
        $mockTaskService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class);
        $mockTaskService->method('getById')->willReturn(null);

        // 设置到容器
        $container = $this->getContainer();
        $container->register('processTaskService', $mockTaskService);

        // 创建测试参数
        $args = new Dict([
            'approved' => false,
        ]);

        // 测试跳转到结束
        $result = $this->engine->executeAndJumpToEnd('nonexistent_task_id', '1', $args);

        // 应该返回空数组
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 测试执行并跳转到第一个任务节点
     */
    public function testExecuteAndJumpToFirstTaskNode(): void
    {
        // 创建模拟任务服务
        $mockTaskService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class);
        $mockTaskService->method('getById')->willReturn(null);

        // 设置到容器
        $container = $this->getContainer();
        $container->register('processTaskService', $mockTaskService);

        // 创建测试参数
        $args = new Dict([
            'restart' => true,
        ]);

        // 测试跳转到第一个任务节点
        $result = $this->engine->executeAndJumpToFirstTaskNode('nonexistent_task_id', '1', $args);

        // 应该返回空数组
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 测试引擎类型检查
     */
    public function testEngineTypeChecks(): void
    {
        // 引擎应该实现IProcessEngines接口
        $this->assertInstanceOf(IProcessEngines::class, $this->engine);

        // 引擎应该有processDefineService方法
        $this->assertTrue(method_exists($this->engine, 'processDefineService'));

        // 引擎应该有processInstanceService方法
        $this->assertTrue(method_exists($this->engine, 'processInstanceService'));

        // 引擎应该有processTaskService方法
        $this->assertTrue(method_exists($this->engine, 'processTaskService'));

        // 引擎应该有startProcessInstanceById方法
        $this->assertTrue(method_exists($this->engine, 'startProcessInstanceById'));

        // 引擎应该有executeProcessTask方法
        $this->assertTrue(method_exists($this->engine, 'executeProcessTask'));
    }

    /**
     * 测试配置异常处理
     */
    public function testConfigurationException(): void
    {
        // 使用无效配置创建引擎
        $this->expectException(\Exception::class);

        $invalidEngine = new ProcessEngines([
            'invalid_config' => 'invalid_value',
        ]);
    }
}
