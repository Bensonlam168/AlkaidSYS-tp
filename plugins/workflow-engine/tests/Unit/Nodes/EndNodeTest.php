<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Nodes\Types\EndNode;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;
use PHPUnit\Framework\TestCase;

/**
 * EndNode 单元测试
 */
class EndNodeTest extends TestCase
{
    private EndNode $node;
    private ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->node = new EndNode('end_001', [
            'name' => 'test_end',
            'display_name' => '测试结束节点',
        ]);

        $this->context = new ExecutionContext('workflow_001', 'instance_001', 100);
    }

    /**
     * 测试节点类型
     */
    public function testNodeType(): void
    {
        $this->assertEquals(NodeInterface::TYPE_END, $this->node->getType());
    }

    /**
     * 测试节点基本属性
     */
    public function testNodeProperties(): void
    {
        $this->assertEquals('end_001', $this->node->getId());
        $this->assertEquals('test_end', $this->node->getName());
        $this->assertEquals('测试结束节点', $this->node->getDisplayName());
        $this->assertStringContainsString('结束节点', $this->node->getDescription());
    }

    /**
     * 测试执行结束节点
     */
    public function testExecuteEndNode(): void
    {
        $result = $this->node->execute($this->context);

        $this->assertInstanceOf(NodeResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('end_001', $result->getNodeId());
        $this->assertEquals('test_end', $result->getNodeName());

        // 结束节点没有下一个节点
        $this->assertEmpty($result->getNextNodes());

        // 检查元数据
        $this->assertEquals('workflow.ended', $result->getMeta('event'));
    }

    /**
     * 测试结束状态
     */
    public function testEndStatus(): void
    {
        // 默认状态
        $result = $this->node->execute($this->context);
        $this->assertEquals(EndNode::END_STATUS_COMPLETED, $result->getMeta('end_status'));

        // 自定义状态
        $terminatedNode = new EndNode('end_002', [
            'name' => 'terminated_end',
            'end_status' => EndNode::END_STATUS_TERMINATED,
        ]);

        $context = new ExecutionContext('workflow_002', 'instance_002', 100);
        $result = $terminatedNode->execute($context);
        $this->assertEquals(EndNode::END_STATUS_TERMINATED, $result->getMeta('end_status'));
    }

    /**
     * 测试结束消息
     */
    public function testEndMessage(): void
    {
        $node = new EndNode('end_003', [
            'name' => 'end_with_message',
            'end_message' => '订单 ${order_id} 处理完成',
        ]);

        $context = new ExecutionContext('workflow_003', 'instance_003', 100);
        $context->setVariable('order_id', 'ORD-001');

        $result = $node->execute($context);
        $data = $result->getData();

        $this->assertEquals('订单 ORD-001 处理完成', $data['message']);
    }

    /**
     * 测试输出参数映射
     */
    public function testOutputMapping(): void
    {
        $node = new EndNode('end_004', [
            'name' => 'end_with_mapping',
            'output_mapping' => [
                'result_status' => 'approval_status',
                'result_comment' => 'approval_comment',
            ],
        ]);

        $context = new ExecutionContext('workflow_004', 'instance_004', 100);
        $context->setVariable('approval_status', 'approved');
        $context->setVariable('approval_comment', '通过审批');
        $context->setVariable('internal_data', '不应输出');

        $result = $node->execute($context);
        $outputData = $result->getData()['output_data'];

        $this->assertEquals('approved', $outputData['result_status']);
        $this->assertEquals('通过审批', $outputData['result_comment']);
        $this->assertArrayNotHasKey('internal_data', $outputData);
    }

    /**
     * 测试无映射时输出所有非内部变量
     */
    public function testOutputAllNonInternalVariables(): void
    {
        $context = new ExecutionContext('workflow_005', 'instance_005', 100);
        $context->setVariable('public_var', 'public_value');
        $context->setVariable('another_var', 'another_value');
        $context->setVariable('_internal_var', 'internal_value');

        $result = $this->node->execute($context);
        $outputData = $result->getData()['output_data'];

        $this->assertArrayHasKey('public_var', $outputData);
        $this->assertArrayHasKey('another_var', $outputData);
        $this->assertArrayNotHasKey('_internal_var', $outputData);
    }

    /**
     * 测试工作流完成变量设置
     */
    public function testWorkflowCompletedVariables(): void
    {
        $this->node->execute($this->context);

        $this->assertTrue($this->context->getVariable('_workflow_completed'));
        $this->assertEquals(EndNode::END_STATUS_COMPLETED, $this->context->getVariable('_workflow_end_status'));
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
        $this->assertArrayHasKey('end_status', $schema['properties']);
        $this->assertArrayHasKey('end_message', $schema['properties']);
        $this->assertArrayHasKey('output_mapping', $schema['properties']);
        $this->assertArrayHasKey('terminate_all', $schema['properties']);
    }

    /**
     * 测试结束状态常量
     */
    public function testEndStatusConstants(): void
    {
        $this->assertEquals('completed', EndNode::END_STATUS_COMPLETED);
        $this->assertEquals('terminated', EndNode::END_STATUS_TERMINATED);
        $this->assertEquals('cancelled', EndNode::END_STATUS_CANCELLED);
        $this->assertEquals('error', EndNode::END_STATUS_ERROR);
    }

    /**
     * 测试转数组
     */
    public function testToArray(): void
    {
        $array = $this->node->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('end_001', $array['id']);
        $this->assertEquals(NodeInterface::TYPE_END, $array['type']);
        $this->assertEquals('test_end', $array['name']);
    }

    /**
     * 测试从数组创建
     */
    public function testFromArray(): void
    {
        $data = [
            'id' => 'end_from_array',
            'type' => NodeInterface::TYPE_END,
            'name' => 'array_end',
            'config' => [
                'end_status' => EndNode::END_STATUS_TERMINATED,
            ],
        ];

        $node = EndNode::fromArray($data);

        $this->assertEquals('end_from_array', $node->getId());
        $this->assertEquals('array_end', $node->getName());
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
        $this->assertArrayHasKey('end_status', $data);
        $this->assertArrayHasKey('ended_at', $data);
        $this->assertArrayHasKey('instance_id', $data);
        $this->assertArrayHasKey('output_data', $data);

        $this->assertEquals('end_001', $data['node_id']);
        $this->assertEquals('test_end', $data['node_name']);
    }
}
