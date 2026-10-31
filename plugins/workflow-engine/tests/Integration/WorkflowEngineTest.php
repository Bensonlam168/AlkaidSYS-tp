<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Integration;

use Alkaid\Workflow\Engine\Integration\WorkflowBootstrap;
use Alkaid\Workflow\Engine\Tests\TestCase;

/**
 * 工作流引擎集成测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Integration
 */
class WorkflowEngineTest extends TestCase
{
    /**
     * 测试工作流引擎初始化
     */
    public function testWorkflowEngineInitialization(): void
    {
        // 使用自定义测试配置
        $testConfig = [
            'extension_parameters' => [
                'debug' => true,
                'tenant_id' => 1,
            ],
        ];

        // 初始化工作流引擎
        $adapter = WorkflowBootstrap::initialize($testConfig);

        // 检查是否初始化成功
        $this->assertNotNull($adapter);
        $this->assertTrue(WorkflowBootstrap::isInitialized());

        // 检查核心组件
        $container = WorkflowBootstrap::getContainer();
        $this->assertNotNull($container);

        $engine = WorkflowBootstrap::getEngine();
        $this->assertNotNull($engine);

        // 检查服务
        $this->assertNotNull(WorkflowBootstrap::getDefineService());
        $this->assertNotNull(WorkflowBootstrap::getInstanceService());
        $this->assertNotNull(WorkflowBootstrap::getTaskService());
    }

    /**
     * 测试工作流引擎健康检查
     */
    public function testWorkflowEngineHealthCheck(): void
    {
        // 初始化工作流引擎
        WorkflowBootstrap::initialize();

        // 执行健康检查
        $health = WorkflowBootstrap::healthCheck();

        // 验证健康检查结果
        $this->assertArrayHasKeys($health, ['status', 'timestamp', 'checks']);
        $this->assertIsArray($health['checks']);

        // 检查各个组件状态
        $this->assertArrayHasKeys($health['checks'], ['container']);
        $this->assertEquals('ok', $health['checks']['container']['status']);
    }

    /**
     * 测试工作流引擎统计信息
     */
    public function testWorkflowEngineStatistics(): void
    {
        // 初始化工作流引擎
        WorkflowBootstrap::initialize();

        // 获取统计信息
        $stats = WorkflowBootstrap::getStatistics();

        // 验证统计信息
        $this->assertArrayHasKeys($stats, ['initialized', 'tenant_id', 'debug_mode', 'container_ready']);
        $this->assertTrue($stats['initialized']);
        $this->assertTrue($stats['container_ready']);
    }

    /**
     * 测试工作流定义服务
     */
    public function testWorkflowDefinitionService(): void
    {
        // 初始化工作流引擎
        WorkflowBootstrap::initialize();

        // 获取定义服务
        $defineService = WorkflowBootstrap::getDefineService();

        // 验证服务存在
        $this->assertNotNull($defineService);

        // 验证服务类型
        $this->assertInstanceOf(
            \Alkaid\Workflow\Engine\interface\services\IProcessDefineService::class,
            $defineService
        );
    }

    /**
     * 测试工作流实例服务
     */
    public function testWorkflowInstanceService(): void
    {
        // 初始化工作流引擎
        WorkflowBootstrap::initialize();

        // 获取实例服务
        $instanceService = WorkflowBootstrap::getInstanceService();

        // 验证服务存在
        $this->assertNotNull($instanceService);

        // 验证服务类型
        $this->assertInstanceOf(
            \Alkaid\Workflow\Engine\interface\services\IProcessInstanceService::class,
            $instanceService
        );
    }

    /**
     * 测试工作流任务服务
     */
    public function testWorkflowTaskService(): void
    {
        // 初始化工作流引擎
        WorkflowBootstrap::initialize();

        // 获取任务服务
        $taskService = WorkflowBootstrap::getTaskService();

        // 验证服务存在
        $this->assertNotNull($taskService);

        // 验证服务类型
        $this->assertInstanceOf(
            \Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class,
            $taskService
        );
    }

    /**
     * 测试服务工厂单例
     */
    public function testServiceFactorySingleton(): void
    {
        // 初始化工作流引擎
        WorkflowBootstrap::initialize();

        // 获取容器两次
        $container1 = WorkflowBootstrap::getContainer();
        $container2 = WorkflowBootstrap::getContainer();

        // 应该是同一个实例
        $this->assertSame($container1, $container2);
    }

    /**
     * 测试工作流引擎重置
     */
    public function testWorkflowEngineReset(): void
    {
        // 初始化工作流引擎
        WorkflowBootstrap::initialize();

        // 验证已初始化
        $this->assertTrue(WorkflowBootstrap::isInitialized());

        // 重置引擎
        WorkflowBootstrap::reset();

        // 验证已重置
        $this->assertFalse(WorkflowBootstrap::isInitialized());
    }

    /**
     * 测试快速启动工作流（模拟）
     */
    public function testQuickStartWorkflow(): void
    {
        // 初始化工作流引擎
        WorkflowBootstrap::initialize();

        // 创建测试参数
        $params = [
            'applicant_name' => '测试用户',
            'amount' => 1000,
            'description' => '测试申请',
        ];

        // 由于没有真实的数据库和实现，启动会失败，但验证接口可用
        $this->expectException(\Exception::class);

        WorkflowBootstrap::startWorkflow(
            'test_workflow_id',
            $params,
            1
        );
    }

    /**
     * 测试快速执行任务（模拟）
     */
    public function testQuickExecuteTask(): void
    {
        // 初始化工作流引擎
        WorkflowBootstrap::initialize();

        // 创建测试参数
        $params = [
            'approved' => true,
            'comment' => '同意申请',
        ];

        // 由于没有真实的任务，执行会失败，但验证接口可用
        $this->expectException(\Exception::class);

        WorkflowBootstrap::executeTask(
            'test_task_id',
            $params,
            1
        );
    }

    /**
     * 测试未初始化时的错误处理
     */
    public function testUninitializedErrorHandling(): void
    {
        // 确保引擎未初始化
        WorkflowBootstrap::reset();

        // 尝试获取组件应该抛出异常
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('工作流引擎尚未初始化');

        WorkflowBootstrap::getEngine();
    }

    /**
     * 测试配置传递
     */
    public function testConfigPassing(): void
    {
        $customConfig = [
            'extension_parameters' => [
                'custom_key' => 'custom_value',
                'debug' => false,
                'tenant_id' => 999,
            ],
        ];

        // 使用自定义配置初始化
        $adapter = WorkflowBootstrap::initialize($customConfig);

        // 验证配置生效
        $container = $adapter->getContainer();
        $this->assertEquals('custom_value', $container->getConfig('custom_key'));
        $this->assertFalse($container->getConfig('debug'));
        $this->assertEquals(999, $container->getConfig('tenant_id'));
    }

    /**
     * 测试适配器获取
     */
    public function testAdapterRetrieval(): void
    {
        // 初始化工作流引擎
        $adapter = WorkflowBootstrap::initialize();

        // 获取适配器
        $retrievedAdapter = WorkflowBootstrap::getAdapter();

        // 应该是同一个实例
        $this->assertSame($adapter, $retrievedAdapter);

        // 验证适配器类型
        $this->assertInstanceOf(
            \Alkaid\Workflow\Engine\Integration\ThinkPHPAdapter::class,
            $retrievedAdapter
        );
    }

    /**
     * 测试容器访问
     */
    public function testContainerAccess(): void
    {
        // 初始化工作流引擎
        WorkflowBootstrap::initialize();

        // 获取容器
        $container = WorkflowBootstrap::getContainer();

        // 验证容器类型
        $this->assertInstanceOf(
            \Alkaid\Workflow\Engine\Container\WorkflowContainer::class,
            $container
        );

        // 验证容器已初始化
        $this->assertTrue($container->isInitialized());

        // 验证核心服务存在
        $this->assertTrue($container->has('processEngines'));
        $this->assertTrue($container->has('processDefineService'));
        $this->assertTrue($container->has('processInstanceService'));
        $this->assertTrue($container->has('processTaskService'));
    }

    /**
     * 清理测试环境
     */
    protected function tearDown(): void
    {
        // 重置工作流引擎
        WorkflowBootstrap::reset();

        parent::tearDown();
    }
}
