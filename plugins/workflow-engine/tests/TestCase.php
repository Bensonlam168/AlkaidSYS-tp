<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Alkaid\Workflow\Engine\Container\ServiceFactory;
use Alkaid\Workflow\Engine\Container\WorkflowContainer;

/**
 * 工作流引擎测试基类
 *
 * @package Alkaid\Workflow\Engine\Tests
 */
abstract class TestCase extends PHPUnitTestCase
{
    /**
     * @var WorkflowContainer|null 工作流容器实例
     */
    protected ?WorkflowContainer $container = null;

    /**
     * @var array 测试配置
     */
    protected array $testConfig = [];

    /**
     * 设置测试环境
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 初始化测试配置
        $this->initTestConfig();

        // 创建测试容器
        $this->createTestContainer();

        // 设置测试数据
        $this->setupTestData();
    }

    /**
     * 清理测试环境
     */
    protected function tearDown(): void
    {
        // 清理测试数据
        $this->cleanupTestData();

        // 重置容器
        if ($this->container) {
            $this->container->reset();
            $this->container = null;
        }

        // 重置服务工厂
        ServiceFactory::reset();

        parent::tearDown();
    }

    /**
     * 初始化测试配置
     */
    protected function initTestConfig(): void
    {
        $this->testConfig = [
            'service_dependencies' => [
                'processEngines' => \Alkaid\Workflow\Engine\Core\ProcessEngines::class,
                'configuration' => \Alkaid\Workflow\Engine\cfg\Configuration::class,

                // 节点解析器
                'startParser' => \Alkaid\Workflow\Engine\parser\handler\StartParser::class,
                'endParser' => \Alkaid\Workflow\Engine\parser\handler\EndParser::class,
                'taskParser' => \Alkaid\Workflow\Engine\parser\handler\TaskParser::class,
                'decisionParser' => \Alkaid\Workflow\Engine\parser\handler\DecisionParser::class,
                'forkParser' => \Alkaid\Workflow\Engine\parser\handler\ForkParser::class,
                'joinParser' => \Alkaid\Workflow\Engine\parser\handler\JoinParser::class,
                'customParser' => \Alkaid\Workflow\Engine\parser\handler\CustomParser::class,
                'wfSubProcessParser' => \Alkaid\Workflow\Engine\parser\handler\WfSubProcessParser::class,

                // 事件服务
                'processEventService' => \Alkaid\Workflow\Engine\event\ProcessEventService::class,
                'schedulerProcessEventListener' => \Alkaid\Workflow\Engine\scheduling\SchedulerIProcessEventListener::class,

                // 数据访问层（测试实现）
                'processDefineRepository' => \Alkaid\Workflow\Engine\Tests\Mock\MockProcessDefineRepository::class,
                'processInstanceRepository' => \Alkaid\Workflow\Engine\Tests\Mock\MockProcessInstanceRepository::class,
                'processTaskRepository' => \Alkaid\Workflow\Engine\Tests\Mock\MockProcessTaskRepository::class,
                'workflowEventRepository' => \Alkaid\Workflow\Engine\Tests\Mock\MockWorkflowEventRepository::class,

                // 管理器
                'nodeManager' => \Alkaid\Workflow\Engine\Multitenant\NodeManager::class,
                'triggerManager' => \Alkaid\Workflow\Engine\Triggers\TriggerManager::class,
                'expressionEngine' => \Alkaid\Workflow\Engine\Expression\ExpressionEngine::class,
            ],

            'dynamic_dependencies' => [],

            'extension_parameters' => [
                'debug' => true,
                'tenant_id' => 1,
                'database_prefix' => 'test_',
                'cache_driver' => 'array',
                'logger_channel' => 'test',
            ],

            'configuration_class' => \Alkaid\Workflow\Engine\cfg\Configuration::class,
        ];
    }

    /**
     * 创建测试容器
     */
    protected function createTestContainer(): void
    {
        try {
            $this->container = ServiceFactory::createContainer($this->testConfig);
        } catch (\Exception $e) {
            $this->container = null;
        }
    }

    /**
     * 设置测试数据
     */
    protected function setupTestData(): void
    {
        // 子类可以重写此方法来设置特定的测试数据
    }

    /**
     * 清理测试数据
     */
    protected function cleanupTestData(): void
    {
        // 子类可以重写此方法来清理测试数据
    }

    /**
     * 获取工作流容器
     *
     * @return WorkflowContainer
     */
    protected function getContainer(): WorkflowContainer
    {
        if (!$this->container) {
            $this->createTestContainer();
        }

        return $this->container;
    }

    /**
     * 获取服务
     *
     * @param string $name 服务名称
     * @return mixed
     */
    protected function getService(string $name)
    {
        $container = $this->getContainer();
        return $container->has($name) ? $container->get($name) : null;
    }

    /**
     * 创建测试用的工作流定义ID
     */
    protected function createTestWorkflowDefinitionId(): string
    {
        return 'test_workflow_' . uniqid();
    }

    /**
     * 创建测试用的工作流实例ID
     */
    protected function createTestWorkflowInstanceId(): string
    {
        return 'test_instance_' . uniqid();
    }

    /**
     * 创建测试用的工作流任务ID
     */
    protected function createTestWorkflowTaskId(): string
    {
        return 'test_task_' . uniqid();
    }

    /**
     * 创建测试用的租户ID
     */
    protected function createTestTenantId(): int
    {
        return rand(1000, 9999);
    }

    /**
     * 创建测试用的用户ID
     */
    protected function createTestUserId(): int
    {
        return rand(1000, 9999);
    }

    /**
     * 断言容器有指定服务
     *
     * @param string $name 服务名称
     */
    protected function assertContainerHasService(string $name): void
    {
        $container = $this->getContainer();
        $this->assertTrue($container->has($name), "容器应该有服务: {$name}");
    }

    /**
     * 断言容器没有指定服务
     *
     * @param string $name 服务名称
     */
    protected function assertContainerDoesNotHaveService(string $name): void
    {
        $container = $this->getContainer();
        $this->assertFalse($container->has($name), "容器不应该有服务: {$name}");
    }

    /**
     * 断言服务是指定类的实例
     *
     * @param string $name 服务名称
     * @param string $className 类名
     */
    protected function assertServiceInstanceOf(string $name, string $className): void
    {
        $service = $this->getService($name);
        $this->assertNotNull($service, "服务 {$name} 不应该为空");
        $this->assertInstanceOf($className, $service, "服务 {$name} 应该是 {$className} 的实例");
    }

    /**
     * 断言数组包含指定的键
     *
     * @param array $array 数组
     * @param string|array $keys 键
     */
    protected function assertArrayHasKeys(array $array, $keys): void
    {
        $keys = is_array($keys) ? $keys : [$keys];
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $array, "数组应该包含键: {$key}");
        }
    }

    /**
     * 断言数组不包含指定的键
     *
     * @param array $array 数组
     * @param string|array $keys 键
     */
    protected function assertArrayDoesNotHaveKeys(array $array, $keys): void
    {
        $keys = is_array($keys) ? $keys : [$keys];
        foreach ($keys as $key) {
            $this->assertArrayNotHasKey($key, $array, "数组不应该包含键: {$key}");
        }
    }
}
