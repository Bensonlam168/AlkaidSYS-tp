<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Nodes\Types\GatewayNode;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;
use PHPUnit\Framework\TestCase;

/**
 * GatewayNode 单元测试
 */
class GatewayNodeTest extends TestCase
{
    private ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = new ExecutionContext('workflow_001', 'instance_001', 100);
    }

    /**
     * 测试节点类型
     */
    public function testNodeType(): void
    {
        $node = new GatewayNode('gateway_001');
        $this->assertEquals(NodeInterface::TYPE_GATEWAY, $node->getType());
    }

    /**
     * 测试网关子类型
     */
    public function testGatewaySubType(): void
    {
        // 默认为排他网关
        $node = new GatewayNode('gateway_001');
        $this->assertEquals(NodeInterface::GATEWAY_EXCLUSIVE, $node->getSubType());

        // 并行网关
        $parallelNode = new GatewayNode('gateway_002', [
            'gateway_type' => NodeInterface::GATEWAY_PARALLEL,
        ]);
        $this->assertEquals(NodeInterface::GATEWAY_PARALLEL, $parallelNode->getSubType());
    }

    /**
     * 测试网关方向
     */
    public function testGatewayDirection(): void
    {
        // 默认为分支
        $node = new GatewayNode('gateway_001');
        $this->assertEquals(GatewayNode::DIRECTION_DIVERGING, $node->getDirection());

        // 汇聚
        $convergeNode = new GatewayNode('gateway_002', [
            'direction' => GatewayNode::DIRECTION_CONVERGING,
        ]);
        $this->assertEquals(GatewayNode::DIRECTION_CONVERGING, $convergeNode->getDirection());
    }

    /**
     * 测试排他网关 - 条件匹配
     */
    public function testExclusiveGatewayWithCondition(): void
    {
        $node = new GatewayNode('exclusive_001', [
            'gateway_type' => NodeInterface::GATEWAY_EXCLUSIVE,
            'outputs' => [
                ['target' => 'approve_node', 'condition' => '${amount} > 1000'],
                ['target' => 'auto_approve', 'condition' => '${amount} <= 1000'],
                ['target' => 'default_node', 'default' => true],
            ],
        ]);

        // 金额大于 1000
        $this->context->setVariable('amount', 2000);
        $result = $node->execute($this->context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(['approve_node'], $result->getNextNodes());
    }

    /**
     * 测试排他网关 - 使用默认路径
     */
    public function testExclusiveGatewayUsesDefault(): void
    {
        $node = new GatewayNode('exclusive_002', [
            'gateway_type' => NodeInterface::GATEWAY_EXCLUSIVE,
            'outputs' => [
                ['target' => 'branch_a', 'condition' => '${status} === "approved"'],
                ['target' => 'branch_b', 'condition' => '${status} === "rejected"'],
                ['target' => 'default_branch', 'default' => true],
            ],
        ]);

        // 状态为 pending，没有匹配条件
        $this->context->setVariable('status', 'pending');
        $result = $node->execute($this->context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(['default_branch'], $result->getNextNodes());
    }

    /**
     * 测试排他网关 - 无匹配路径失败
     */
    public function testExclusiveGatewayFailsWithNoMatch(): void
    {
        $node = new GatewayNode('exclusive_003', [
            'gateway_type' => NodeInterface::GATEWAY_EXCLUSIVE,
            'outputs' => [
                ['target' => 'branch_a', 'condition' => '${status} === "approved"'],
            ],
        ]);

        $this->context->setVariable('status', 'pending');
        $result = $node->execute($this->context);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('无法找到满足条件的输出路径', $result->getError());
    }

    /**
     * 测试并行网关 - 分支
     */
    public function testParallelGatewayDiverging(): void
    {
        $node = new GatewayNode('parallel_001', [
            'gateway_type' => NodeInterface::GATEWAY_PARALLEL,
            'direction' => GatewayNode::DIRECTION_DIVERGING,
            'outputs' => ['branch_1', 'branch_2', 'branch_3'],
        ]);

        $result = $node->execute($this->context);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(3, $result->getNextNodes());
        $this->assertTrue($result->getMeta('parallel_execution'));
        $this->assertNotNull($result->getMeta('branch_id'));

        // 检查输出数据
        $data = $result->getData();
        $this->assertEquals('parallel', $data['gateway_type']);
        $this->assertEquals(3, $data['branch_count']);
    }

    /**
     * 测试并行网关 - 无输出路径失败
     */
    public function testParallelGatewayFailsWithNoOutputs(): void
    {
        $node = new GatewayNode('parallel_002', [
            'gateway_type' => NodeInterface::GATEWAY_PARALLEL,
            'outputs' => [],
        ]);

        $result = $node->execute($this->context);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('没有配置输出路径', $result->getError());
    }

    /**
     * 测试并行网关 - 汇聚等待
     */
    public function testParallelGatewayConvergingWaits(): void
    {
        $node = new GatewayNode('parallel_converge_001', [
            'gateway_type' => NodeInterface::GATEWAY_PARALLEL,
            'direction' => GatewayNode::DIRECTION_CONVERGING,
            'inputs' => ['branch_1', 'branch_2', 'branch_3'],
            'outputs' => ['next_node'],
        ]);

        // 第一个分支到达
        $result = $node->execute($this->context);

        $this->assertTrue($result->isWaiting());
        $this->assertTrue($result->getMeta('merge_pending'));

        $data = $result->getData();
        $this->assertEquals(1, $data['arrived_count']);
        $this->assertEquals(3, $data['total_count']);
    }

    /**
     * 测试并行网关 - 汇聚完成
     */
    public function testParallelGatewayConvergingCompletes(): void
    {
        $node = new GatewayNode('parallel_converge_002', [
            'gateway_type' => NodeInterface::GATEWAY_PARALLEL,
            'direction' => GatewayNode::DIRECTION_CONVERGING,
            'inputs' => ['branch_1', 'branch_2'],
            'outputs' => ['next_node'],
        ]);

        // 模拟两个分支依次到达
        $context = new ExecutionContext('workflow_002', 'instance_002', 100);

        // 第一个分支
        $context->setVariable('_current_branch', 'branch_1');
        $result1 = $node->execute($context);
        $this->assertTrue($result1->isWaiting());

        // 第二个分支
        $context->setVariable('_current_branch', 'branch_2');
        $result2 = $node->execute($context);

        $this->assertTrue($result2->isSuccess());
        $this->assertTrue($result2->getMeta('merge_complete'));
        $this->assertEquals(['next_node'], $result2->getNextNodes());
    }

    /**
     * 测试包容网关 - 多条件匹配
     */
    public function testInclusiveGatewayMultipleMatches(): void
    {
        $node = new GatewayNode('inclusive_001', [
            'gateway_type' => NodeInterface::GATEWAY_INCLUSIVE,
            'outputs' => [
                ['target' => 'notify_manager', 'condition' => '${amount} > 500'],
                ['target' => 'notify_finance', 'condition' => '${amount} > 1000'],
                ['target' => 'notify_ceo', 'condition' => '${amount} > 5000'],
                ['target' => 'default_node', 'default' => true],
            ],
        ]);

        // 金额 2000，匹配前两个条件
        $this->context->setVariable('amount', 2000);
        $result = $node->execute($this->context);

        $this->assertTrue($result->isSuccess());
        $nextNodes = $result->getNextNodes();
        $this->assertCount(2, $nextNodes);
        $this->assertContains('notify_manager', $nextNodes);
        $this->assertContains('notify_finance', $nextNodes);
    }

    /**
     * 测试包容网关 - 使用默认路径
     */
    public function testInclusiveGatewayUsesDefault(): void
    {
        $node = new GatewayNode('inclusive_002', [
            'gateway_type' => NodeInterface::GATEWAY_INCLUSIVE,
            'outputs' => [
                ['target' => 'special_branch', 'condition' => '${special} === true'],
                ['target' => 'default_branch', 'default' => true],
            ],
        ]);

        $this->context->setVariable('special', false);
        $result = $node->execute($this->context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(['default_branch'], $result->getNextNodes());
    }

    /**
     * 测试事件网关
     */
    public function testEventGateway(): void
    {
        $node = new GatewayNode('event_001', [
            'gateway_type' => NodeInterface::GATEWAY_EVENT,
            'outputs' => [
                ['target' => 'timeout_handler', 'event' => 'timeout'],
                ['target' => 'message_handler', 'event' => 'message_received'],
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertTrue($result->isWaiting());
        $this->assertTrue($result->getMeta('event_gateway'));

        $data = $result->getData();
        $this->assertEquals('event_based', $data['gateway_type']);
        $this->assertCount(2, $data['expected_events']);
    }

    /**
     * 测试自定义条件评估器
     */
    public function testCustomConditionEvaluator(): void
    {
        $node = new GatewayNode('custom_001', [
            'gateway_type' => NodeInterface::GATEWAY_EXCLUSIVE,
            'outputs' => [
                ['target' => 'branch_a', 'condition' => 'custom_condition_a'],
                ['target' => 'branch_b', 'condition' => 'custom_condition_b'],
            ],
        ]);

        $node->setConditionEvaluator(function (ExecutionContext $ctx, string $condition) {
            return $condition === 'custom_condition_a';
        });

        $result = $node->execute($this->context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(['branch_a'], $result->getNextNodes());
    }

    /**
     * 测试配置 Schema
     */
    public function testConfigSchema(): void
    {
        $node = new GatewayNode('gateway_001');
        $schema = $node->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('gateway_type', $schema['properties']);
        $this->assertArrayHasKey('direction', $schema['properties']);
        $this->assertArrayHasKey('default_output', $schema['properties']);
        $this->assertArrayHasKey('wait_timeout', $schema['properties']);
    }

    /**
     * 测试网关类型常量
     */
    public function testGatewayTypeConstants(): void
    {
        $this->assertEquals('exclusive', NodeInterface::GATEWAY_EXCLUSIVE);
        $this->assertEquals('parallel', NodeInterface::GATEWAY_PARALLEL);
        $this->assertEquals('inclusive', NodeInterface::GATEWAY_INCLUSIVE);
        $this->assertEquals('event_based', NodeInterface::GATEWAY_EVENT);
    }

    /**
     * 测试网关方向常量
     */
    public function testGatewayDirectionConstants(): void
    {
        $this->assertEquals('diverging', GatewayNode::DIRECTION_DIVERGING);
        $this->assertEquals('converging', GatewayNode::DIRECTION_CONVERGING);
    }

    /**
     * 测试转数组
     */
    public function testToArray(): void
    {
        $node = new GatewayNode('gateway_001', [
            'name' => 'test_gateway',
            'gateway_type' => NodeInterface::GATEWAY_PARALLEL,
        ]);

        $array = $node->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('gateway_001', $array['id']);
        $this->assertEquals(NodeInterface::TYPE_GATEWAY, $array['type']);
        $this->assertEquals(NodeInterface::GATEWAY_PARALLEL, $array['sub_type']);
    }

    /**
     * 测试从数组创建
     */
    public function testFromArray(): void
    {
        $data = [
            'id' => 'gateway_from_array',
            'type' => NodeInterface::TYPE_GATEWAY,
            'sub_type' => NodeInterface::GATEWAY_INCLUSIVE,
            'name' => 'array_gateway',
            'config' => [
                'direction' => GatewayNode::DIRECTION_CONVERGING,
            ],
        ];

        $node = GatewayNode::fromArray($data);

        $this->assertEquals('gateway_from_array', $node->getId());
        $this->assertEquals('array_gateway', $node->getName());
        $this->assertEquals(NodeInterface::GATEWAY_INCLUSIVE, $node->getSubType());
        $this->assertEquals(GatewayNode::DIRECTION_CONVERGING, $node->getDirection());
    }

    /**
     * 测试排他网关汇聚（只需一个分支即可继续）
     */
    public function testExclusiveGatewayConverging(): void
    {
        $node = new GatewayNode('exclusive_converge', [
            'gateway_type' => NodeInterface::GATEWAY_EXCLUSIVE,
            'direction' => GatewayNode::DIRECTION_CONVERGING,
            'inputs' => ['branch_1', 'branch_2'],
            'outputs' => ['next_node'],
        ]);

        // 第一个分支到达即可继续
        $result = $node->execute($this->context);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getMeta('merge_complete'));
    }
}
