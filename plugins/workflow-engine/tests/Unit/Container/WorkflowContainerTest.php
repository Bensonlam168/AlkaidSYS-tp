<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Container;

use Alkaid\Workflow\Engine\Container\WorkflowContainer;
use Alkaid\Workflow\Engine\Container\ServiceFactory;
use Alkaid\Workflow\Engine\Tests\TestCase;

/**
 * 工作流容器测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Container
 */
class WorkflowContainerTest extends TestCase
{
    /**
     * 测试容器初始化
     */
    public function testContainerInitialization(): void
    {
        $container = $this->getContainer();

        // 容器应该已初始化
        $this->assertTrue($container->isInitialized());

        // 容器应该可以获取
        $this->assertInstanceOf(WorkflowContainer::class, $container);
    }

    /**
     * 测试服务注册和获取
     */
    public function testServiceRegistrationAndRetrieval(): void
    {
        $container = $this->getContainer();

        // 注册测试服务
        $testService = new \stdClass();
        $testService->name = 'test_service';

        $container->register('test_service', $testService);

        // 应该能够获取服务
        $this->assertTrue($container->has('test_service'));

        $retrievedService = $container->get('test_service');
        $this->assertSame($testService, $retrievedService);
        $this->assertEquals('test_service', $retrievedService->name);
    }

    /**
     * 测试批量服务注册
     */
    public function testBatchServiceRegistration(): void
    {
        $container = $this->getContainer();

        $services = [
            'service1' => new \stdClass(),
            'service2' => new \stdClass(),
            'service3' => new \stdClass(),
        ];

        $container->registerBatch($services);

        // 所有服务都应该存在
        foreach (array_keys($services) as $name) {
            $this->assertTrue($container->has($name));
        }
    }

    /**
     * 测试获取核心服务
     */
    public function testGetCoreServices(): void
    {
        $container = $this->getContainer();

        // 核心服务应该存在
        $this->assertContainerHasService('processEngines');
        $this->assertContainerHasService('configuration');
        $this->assertContainerHasService('processDefineService');
        $this->assertContainerHasService('processInstanceService');
        $this->assertContainerHasService('processTaskService');
    }

    /**
     * 测试节点解析器获取
     */
    public function testGetNodeParser(): void
    {
        $container = $this->getContainer();

        // 所有节点解析器都应该存在
        $this->assertNotNull($container->getNodeParser('start'));
        $this->assertNotNull($container->getNodeParser('end'));
        $this->assertNotNull($container->getNodeParser('task'));
        $this->assertNotNull($container->getNodeParser('decision'));

        // 不存在的解析器应该返回null
        $this->assertNull($container->getNodeParser('nonexistent'));
    }

    /**
     * 测试配置获取
     */
    public function testConfigRetrieval(): void
    {
        $container = $this->getContainer();

        // 应该能够获取配置
        $this->assertTrue($container->getConfig('debug'));
        $this->assertEquals(1, $container->getConfig('tenant_id'));
        $this->assertEquals('test_', $container->getConfig('database_prefix'));
        $this->assertEquals('array', $container->getConfig('cache_driver'));
        $this->assertEquals('test', $container->getConfig('logger_channel'));

        // 不存在的配置应该返回默认值
        $this->assertEquals('default', $container->getConfig('nonexistent', 'default'));
    }

    /**
     * 测试服务不存在的情况
     *
     * 契约说明（为什么断言「返回 null」而不是「抛异常」）：
     * - 已初始化容器的 get() 走 ServiceContext::find()，其既有语义就是「找不到返回 null」
     *   （见 src/Core/ServiceContext.php:70-73）；
     * - 引擎内部所有消费者都按这个口径判空：WorkflowContainer::getNodeParser() 先 has() 再 get()，
     *   ProcessServiceTrait 的 processXxxService() 返回类型均为 ?IProcessXxxService，
     *   ModelParser 用「!= null」判断；改实现去抛异常会让 has()/get() 存在性口径分裂，
     *   且所有已用 has() 守住的调用点都要再加 try/catch；
     * - 未初始化态的 get() 抛异常行为另有 testGetOnUninitializedContainerThrows 覆盖。
     * 原用例把「工作流容器尚未初始化」这条**未初始化态**的消息挂在**已初始化**容器上断言，
     * 任何实现都不可能让它成立，属于用例自身矛盾，故按实际契约修正。
     */
    public function testNonExistentService(): void
    {
        $container = $this->getContainer();

        // 不存在的服务
        $this->assertFalse($container->has('nonexistent_service'));

        // 已初始化的容器：未知服务按「缺失」处理，返回 null 而非抛异常
        $this->assertNull($container->get('nonexistent_service'));
    }

    /**
     * 测试未初始化容器上调用 get() 的错误处理
     *
     * 补上原用例真正想表达的语义：只有**未初始化**时 get() 才抛「工作流容器尚未初始化」。
     * 该分支此前没有任何用例覆盖。
     */
    public function testGetOnUninitializedContainerThrows(): void
    {
        $container = new WorkflowContainer();

        $this->assertFalse($container->isInitialized());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('工作流容器尚未初始化');
        $container->get('any_service');
    }

    /**
     * 测试容器重置
     */
    public function testContainerReset(): void
    {
        $container = $this->getContainer();

        // 注册服务
        $container->register('test_service', new \stdClass());
        $this->assertTrue($container->has('test_service'));

        // 重置容器
        $container->reset();

        // 服务应该不存在
        $this->assertFalse($container->has('test_service'));
        $this->assertFalse($container->isInitialized());
    }

    /**
     * 测试服务工厂单例
     */
    public function testServiceFactorySingleton(): void
    {
        // 获取两个容器实例
        $container1 = ServiceFactory::getContainer();
        $container2 = ServiceFactory::getContainer();

        // 应该是同一个实例
        $this->assertSame($container1, $container2);
    }

    /**
     * 测试服务工厂重置
     */
    public function testServiceFactoryReset(): void
    {
        $container = ServiceFactory::getContainer();

        // 注册服务
        ServiceFactory::getContainer()->register('test_service', new \stdClass());
        $this->assertTrue(ServiceFactory::getContainer()->has('test_service'));

        // 重置工厂
        ServiceFactory::reset();

        // 应该抛出异常，因为容器已被重置
        $this->expectException(\RuntimeException::class);
        ServiceFactory::getContainer();
    }

    /**
     * 测试未初始化容器的错误处理
     */
    public function testUninitializedContainerError(): void
    {
        $container = new WorkflowContainer();

        // 未初始化的容器应该返回false
        $this->assertFalse($container->has('test_service'));
        $this->assertFalse($container->isInitialized());

        // 尝试在未初始化时注册服务应该抛出异常
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('工作流容器尚未初始化');
        $container->register('test_service', new \stdClass());
    }

    /**
     * 测试配置覆盖
     */
    public function testConfigOverride(): void
    {
        // 使用自定义配置创建容器
        $customConfig = [
            'extension_parameters' => [
                'custom_key' => 'custom_value',
                'debug' => false,
            ],
        ];

        $customContainer = new WorkflowContainer();
        $customContainer->initialize($customConfig);

        // 配置应该被覆盖
        $this->assertEquals('custom_value', $customContainer->getConfig('custom_key'));
        $this->assertFalse($customContainer->getConfig('debug'));
    }
}
