<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeRegistry;
use Alkaid\Workflow\Engine\Nodes\Types\EndNode;
use Alkaid\Workflow\Engine\Nodes\Types\StartNode;
use Alkaid\Workflow\Engine\Nodes\Types\TaskNode;
use PHPUnit\Framework\TestCase;

/**
 * NodeRegistry 单元测试
 */
class NodeRegistryTest extends TestCase
{
    private NodeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new NodeRegistry(true);
    }

    /**
     * 测试自动注册内置类型
     */
    public function testAutoRegisterBuiltinTypes(): void
    {
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_START));
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_END));
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_TASK));
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_GATEWAY));
    }

    /**
     * 测试不自动注册内置类型
     */
    public function testNoAutoRegisterBuiltinTypes(): void
    {
        $registry = new NodeRegistry(false);

        $this->assertFalse($registry->has(NodeInterface::TYPE_START));
        $this->assertFalse($registry->has(NodeInterface::TYPE_END));
        $this->assertFalse($registry->has(NodeInterface::TYPE_TASK));
        $this->assertFalse($registry->has(NodeInterface::TYPE_GATEWAY));
    }

    /**
     * 测试注册任务子类型
     */
    public function testTaskSubTypesRegistered(): void
    {
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_TASK, NodeInterface::TASK_USER));
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_TASK, NodeInterface::TASK_SERVICE));
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_TASK, NodeInterface::TASK_SCRIPT));
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_TASK, NodeInterface::TASK_SEND));
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_TASK, NodeInterface::TASK_RECEIVE));
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_TASK, NodeInterface::TASK_MANUAL));
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_TASK, NodeInterface::TASK_BUSINESS_RULE));
    }

    /**
     * 测试注册网关子类型
     */
    public function testGatewaySubTypesRegistered(): void
    {
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_GATEWAY, NodeInterface::GATEWAY_EXCLUSIVE));
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_GATEWAY, NodeInterface::GATEWAY_PARALLEL));
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_GATEWAY, NodeInterface::GATEWAY_INCLUSIVE));
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_GATEWAY, NodeInterface::GATEWAY_EVENT));
    }

    /**
     * 测试注册自定义类型
     */
    public function testRegisterCustomType(): void
    {
        $registry = new NodeRegistry(false);

        $result = $registry->register('custom', StartNode::class);

        $this->assertSame($registry, $result); // 链式调用
        $this->assertTrue($registry->has('custom'));
        $this->assertEquals(StartNode::class, $registry->getClass('custom'));
    }

    /**
     * 测试注册带子类型
     */
    public function testRegisterWithSubType(): void
    {
        $registry = new NodeRegistry(false);

        $registry->register('custom', TaskNode::class, 'special');

        $this->assertTrue($registry->has('custom', 'special'));
        $this->assertEquals(TaskNode::class, $registry->getClass('custom', 'special'));
    }

    /**
     * 测试注销类型
     */
    public function testUnregister(): void
    {
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_START));

        $result = $this->registry->unregister(NodeInterface::TYPE_START);

        $this->assertSame($this->registry, $result); // 链式调用
        $this->assertFalse($this->registry->has(NodeInterface::TYPE_START));
    }

    /**
     * 测试注销带子类型
     */
    public function testUnregisterWithSubType(): void
    {
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_TASK, NodeInterface::TASK_USER));

        $this->registry->unregister(NodeInterface::TYPE_TASK, NodeInterface::TASK_USER);

        $this->assertFalse($this->registry->has(NodeInterface::TYPE_TASK, NodeInterface::TASK_USER));
        // 基础类型仍然存在
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_TASK));
    }

    /**
     * 测试获取类回退到基础类型
     */
    public function testGetClassFallsBackToBaseType(): void
    {
        $registry = new NodeRegistry(false);
        $registry->register(NodeInterface::TYPE_TASK, TaskNode::class);

        // 查找未注册的子类型，应该回退到基础类型
        $class = $registry->getClass(NodeInterface::TYPE_TASK, 'unknown_subtype');

        $this->assertEquals(TaskNode::class, $class);
    }

    /**
     * 测试获取不存在的类返回 null
     */
    public function testGetClassReturnsNullForUnknown(): void
    {
        $class = $this->registry->getClass('unknown_type');

        $this->assertNull($class);
    }

    /**
     * 测试创建节点实例
     */
    public function testCreateNode(): void
    {
        $node = $this->registry->create(NodeInterface::TYPE_START, null, [
            'name' => 'created_start',
        ]);

        $this->assertInstanceOf(StartNode::class, $node);
        $this->assertEquals('created_start', $node->getName());
    }

    /**
     * 测试创建带子类型的节点
     */
    public function testCreateNodeWithSubType(): void
    {
        $node = $this->registry->create(NodeInterface::TYPE_TASK, NodeInterface::TASK_USER, [
            'name' => 'user_task',
        ]);

        $this->assertInstanceOf(TaskNode::class, $node);
        $this->assertEquals(NodeInterface::TASK_USER, $node->getSubType());
    }

    /**
     * 测试创建未注册类型抛出异常
     */
    public function testCreateThrowsForUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未注册的节点类型');

        $this->registry->create('unknown_type');
    }

    /**
     * 测试从数组创建节点
     */
    public function testCreateFromArray(): void
    {
        $data = [
            'id' => 'node_from_array',
            'type' => NodeInterface::TYPE_END,
            'name' => 'end_node',
            'config' => [
                'end_status' => 'completed',
            ],
        ];

        $node = $this->registry->createFromArray($data);

        $this->assertInstanceOf(EndNode::class, $node);
        $this->assertEquals('node_from_array', $node->getId());
        $this->assertEquals('end_node', $node->getName());
    }

    /**
     * 测试从数组创建缺少类型抛出异常
     */
    public function testCreateFromArrayThrowsWithoutType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('缺少类型字段');

        $this->registry->createFromArray(['name' => 'no_type']);
    }

    /**
     * 测试获取已注册类型列表
     */
    public function testGetRegisteredTypes(): void
    {
        $types = $this->registry->getRegisteredTypes();

        $this->assertIsArray($types);
        $this->assertArrayHasKey(NodeInterface::TYPE_START, $types);
        $this->assertArrayHasKey(NodeInterface::TYPE_END, $types);
        $this->assertArrayHasKey(NodeInterface::TYPE_TASK, $types);
        $this->assertArrayHasKey(NodeInterface::TYPE_GATEWAY, $types);

        // 检查子类型
        $this->assertContains(NodeInterface::TASK_USER, $types[NodeInterface::TYPE_TASK]);
        $this->assertContains(NodeInterface::GATEWAY_PARALLEL, $types[NodeInterface::TYPE_GATEWAY]);
    }

    /**
     * 测试获取类型信息
     */
    public function testGetTypesInfo(): void
    {
        $info = $this->registry->getTypesInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey(NodeInterface::TYPE_START, $info);

        $startInfo = $info[NodeInterface::TYPE_START];
        $this->assertEquals(NodeInterface::TYPE_START, $startInfo['type']);
        $this->assertNull($startInfo['sub_type']);
        $this->assertEquals(StartNode::class, $startInfo['class']);
        $this->assertArrayHasKey('config_schema', $startInfo);
    }

    /**
     * 测试批量注册
     */
    public function testRegisterMany(): void
    {
        $registry = new NodeRegistry(false);

        $result = $registry->registerMany([
            'custom_start' => StartNode::class,
            'custom_end' => EndNode::class,
            'task:custom' => TaskNode::class,
        ]);

        $this->assertSame($registry, $result); // 链式调用
        $this->assertTrue($registry->has('custom_start'));
        $this->assertTrue($registry->has('custom_end'));
        $this->assertTrue($registry->has('task', 'custom'));
    }

    /**
     * 测试清除所有注册
     */
    public function testClear(): void
    {
        $this->assertTrue($this->registry->has(NodeInterface::TYPE_START));

        $result = $this->registry->clear();

        $this->assertSame($this->registry, $result); // 链式调用
        $this->assertFalse($this->registry->has(NodeInterface::TYPE_START));
        $this->assertFalse($this->registry->has(NodeInterface::TYPE_END));
        $this->assertEmpty($this->registry->getRegisteredTypes());
    }

    /**
     * 测试单例模式
     */
    public function testSingletonMode(): void
    {
        $this->registry->enableSingletonMode(true);

        $node1 = $this->registry->create(NodeInterface::TYPE_START);
        $node2 = $this->registry->create(NodeInterface::TYPE_START);

        $this->assertSame($node1, $node2);
    }

    /**
     * 测试禁用单例模式
     */
    public function testDisableSingletonMode(): void
    {
        $this->registry->enableSingletonMode(false);

        $node1 = $this->registry->create(NodeInterface::TYPE_START);
        $node2 = $this->registry->create(NodeInterface::TYPE_START);

        $this->assertNotSame($node1, $node2);
    }

    /**
     * 测试获取类型列表
     */
    public function testGetTypeList(): void
    {
        $list = $this->registry->getTypeList();

        $this->assertIsArray($list);
        $this->assertGreaterThan(0, count($list));

        // 检查列表项结构
        $item = $list[0];
        $this->assertArrayHasKey('key', $item);
        $this->assertArrayHasKey('type', $item);
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayHasKey('description', $item);
    }

    /**
     * 测试从配置创建
     */
    public function testFromConfig(): void
    {
        $config = [
            'auto_register_builtin' => false,
            'singleton_mode' => true,
            'types' => [
                NodeInterface::TYPE_START => StartNode::class,
                NodeInterface::TYPE_END => EndNode::class,
            ],
        ];

        $registry = NodeRegistry::fromConfig($config);

        $this->assertTrue($registry->has(NodeInterface::TYPE_START));
        $this->assertTrue($registry->has(NodeInterface::TYPE_END));
        $this->assertFalse($registry->has(NodeInterface::TYPE_TASK));

        // 测试单例模式生效
        $node1 = $registry->create(NodeInterface::TYPE_START);
        $node2 = $registry->create(NodeInterface::TYPE_START);
        $this->assertSame($node1, $node2);
    }

    /**
     * 测试导出配置
     */
    public function testExportConfig(): void
    {
        $this->registry->enableSingletonMode(true);

        $config = $this->registry->exportConfig();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('auto_register_builtin', $config);
        $this->assertArrayHasKey('singleton_mode', $config);
        $this->assertArrayHasKey('types', $config);

        $this->assertTrue($config['singleton_mode']);
        $this->assertArrayHasKey(NodeInterface::TYPE_START, $config['types']);
    }

    /**
     * 测试子类型自动添加到配置
     */
    public function testSubTypeAddedToConfig(): void
    {
        $node = $this->registry->create(NodeInterface::TYPE_TASK, NodeInterface::TASK_USER, [
            'name' => 'test_task',
        ]);

        $this->assertEquals(NodeInterface::TASK_USER, $node->getSubType());
    }
}
