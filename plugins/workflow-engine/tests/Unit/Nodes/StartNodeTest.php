<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Nodes\Types\StartNode;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;
use PHPUnit\Framework\TestCase;

/**
 * StartNode 单元测试
 */
class StartNodeTest extends TestCase
{
    private StartNode $node;
    private ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->node = new StartNode('start_001', [
            'name' => 'test_start',
            'display_name' => '测试开始节点',
            'outputs' => ['task_001', 'task_002'],
        ]);

        $this->context = new ExecutionContext('workflow_001', 'instance_001', 100);
    }

    /**
     * 测试节点类型
     */
    public function testNodeType(): void
    {
        $this->assertEquals(NodeInterface::TYPE_START, $this->node->getType());
    }

    /**
     * 测试节点基本属性
     */
    public function testNodeProperties(): void
    {
        $this->assertEquals('start_001', $this->node->getId());
        $this->assertEquals('test_start', $this->node->getName());
        $this->assertEquals('测试开始节点', $this->node->getDisplayName());
        $this->assertStringContainsString('开始节点', $this->node->getDescription());
    }

    /**
     * 测试执行开始节点
     */
    public function testExecuteStartNode(): void
    {
        $result = $this->node->execute($this->context);

        $this->assertInstanceOf(NodeResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('start_001', $result->getNodeId());
        $this->assertEquals('test_start', $result->getNodeName());

        // 检查下一个节点
        $nextNodes = $result->getNextNodes();
        $this->assertCount(2, $nextNodes);
        $this->assertContains('task_001', $nextNodes);
        $this->assertContains('task_002', $nextNodes);

        // 检查元数据
        $this->assertEquals('workflow.started', $result->getMeta('event'));
    }

    /**
     * 测试初始变量设置
     */
    public function testInitialVariables(): void
    {
        $node = new StartNode('start_002', [
            'name' => 'start_with_vars',
            'initial_variables' => [
                'status' => 'pending',
                'priority' => 'high',
            ],
            'outputs' => ['next_node'],
        ]);

        $context = new ExecutionContext('workflow_002', 'instance_002', 100);
        $result = $node->execute($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('pending', $context->getVariable('status'));
        $this->assertEquals('high', $context->getVariable('priority'));
    }

    /**
     * 测试输入参数映射
     */
    public function testInputMapping(): void
    {
        $node = new StartNode('start_003', [
            'name' => 'start_with_mapping',
            'input_mapping' => [
                'mapped_order_id' => 'order_id',
                'mapped_user' => 'user_id',
            ],
            'outputs' => ['next_node'],
        ]);

        $context = new ExecutionContext('workflow_003', 'instance_003', 100);
        $context->setVariable('order_id', 'ORD-001');
        $context->setVariable('user_id', 'USER-001');

        $result = $node->execute($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('ORD-001', $context->getVariable('mapped_order_id'));
        $this->assertEquals('USER-001', $context->getVariable('mapped_user'));
    }

    /**
     * 测试节点在取消状态下不执行
     */
    public function testCannotExecuteWhenCancelled(): void
    {
        $context = new ExecutionContext('workflow_004', 'instance_004', 100);
        $context->cancel('测试取消');

        $this->assertFalse($this->node->canExecute($context));
    }

    /**
     * 测试配置 Schema
     */
    public function testConfigSchema(): void
    {
        $schema = $this->node->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('input_mapping', $schema['properties']);
        $this->assertArrayHasKey('initial_variables', $schema['properties']);
        $this->assertArrayHasKey('form_schema', $schema['properties']);
    }

    /**
     * 测试转数组
     */
    public function testToArray(): void
    {
        $array = $this->node->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('start_001', $array['id']);
        $this->assertEquals(NodeInterface::TYPE_START, $array['type']);
        $this->assertEquals('test_start', $array['name']);
        $this->assertEquals('测试开始节点', $array['display_name']);
    }

    /**
     * 测试从数组创建
     */
    public function testFromArray(): void
    {
        $data = [
            'id' => 'start_from_array',
            'type' => NodeInterface::TYPE_START,
            'name' => 'array_start',
            'display_name' => '从数组创建',
            'outputs' => ['node_1'],
        ];

        $node = StartNode::fromArray($data);

        $this->assertEquals('start_from_array', $node->getId());
        $this->assertEquals('array_start', $node->getName());
        $this->assertEquals('从数组创建', $node->getDisplayName());
    }

    /**
     * 测试输出数据包含正确信息
     */
    public function testOutputDataContainsCorrectInfo(): void
    {
        $result = $this->node->execute($this->context);
        $data = $result->getData();

        $this->assertArrayHasKey('node_id', $data);
        $this->assertArrayHasKey('node_name', $data);
        $this->assertArrayHasKey('started_at', $data);
        $this->assertArrayHasKey('instance_id', $data);
        $this->assertArrayHasKey('tenant_id', $data);

        $this->assertEquals('start_001', $data['node_id']);
        $this->assertEquals('test_start', $data['node_name']);
        $this->assertEquals('instance_001', $data['instance_id']);
        $this->assertEquals(100, $data['tenant_id']);
    }
}
