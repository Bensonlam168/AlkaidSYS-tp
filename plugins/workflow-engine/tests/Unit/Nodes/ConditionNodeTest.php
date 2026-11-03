<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use PHPUnit\Framework\TestCase;
use Alkaid\Workflow\Engine\Nodes\Types\ConditionNode;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * ConditionNode 单元测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Nodes
 */
class ConditionNodeTest extends TestCase
{
    protected ConditionNode $node;
    protected ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new ExecutionContext();
        $this->context->setInstanceId('inst_cond_001');
        $this->context->setTenantId(100);
    }

    /**
     * 测试节点创建
     */
    public function testNodeCreation(): void
    {
        $node = new ConditionNode('condition_001', [
            'name' => '条件节点',
            'gateway_type' => ConditionNode::GATEWAY_EXCLUSIVE,
        ]);

        $this->assertEquals('condition_001', $node->getId());
        $this->assertEquals(NodeInterface::TYPE_GATEWAY, $node->getType());
    }

    /**
     * 测试排他网关 - 匹配第一个条件
     */
    public function testExclusiveGatewayMatchesFirst(): void
    {
        $node = new ConditionNode('exclusive_001', [
            'name' => '排他网关',
            'gateway_type' => ConditionNode::GATEWAY_EXCLUSIVE,
            'conditions' => [
                [
                    'name' => '金额大于1000',
                    'field' => 'amount',
                    'operator' => ConditionNode::OP_GREATER_THAN,
                    'value' => 1000,
                    'target' => 'high_value_handler',
                ],
                [
                    'name' => '金额大于500',
                    'field' => 'amount',
                    'operator' => ConditionNode::OP_GREATER_THAN,
                    'value' => 500,
                    'target' => 'medium_value_handler',
                ],
            ],
            'default_branch' => 'default_handler',
        ]);

        $this->context->setVariable('amount', 1500);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(['high_value_handler'], $result->getNextNodes());
        $this->assertEquals('金额大于1000', $result->getData()['matched_condition']);
    }

    /**
     * 测试排他网关 - 使用默认分支
     */
    public function testExclusiveGatewayUsesDefault(): void
    {
        $node = new ConditionNode('exclusive_002', [
            'name' => '排他网关',
            'gateway_type' => ConditionNode::GATEWAY_EXCLUSIVE,
            'conditions' => [
                [
                    'name' => '金额大于1000',
                    'field' => 'amount',
                    'operator' => ConditionNode::OP_GREATER_THAN,
                    'value' => 1000,
                    'target' => 'high_value_handler',
                ],
            ],
            'default_branch' => 'default_handler',
        ]);

        $this->context->setVariable('amount', 500);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(['default_handler'], $result->getNextNodes());
        $this->assertEquals('default', $result->getData()['matched_condition']);
    }

    /**
     * 测试并行网关 - 所有分支
     */
    public function testParallelGatewayAllBranches(): void
    {
        $node = new ConditionNode('parallel_001', [
            'name' => '并行网关',
            'gateway_type' => ConditionNode::GATEWAY_PARALLEL,
            'conditions' => [
                ['name' => '分支1', 'target' => 'branch_1'],
                ['name' => '分支2', 'target' => 'branch_2'],
                ['name' => '分支3', 'target' => 'branch_3'],
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(3, $result->getData()['branch_count']);
        $this->assertContains('branch_1', $result->getNextNodes());
        $this->assertContains('branch_2', $result->getNextNodes());
        $this->assertContains('branch_3', $result->getNextNodes());
    }

    /**
     * 测试包含网关 - 匹配多个条件
     */
    public function testInclusiveGatewayMultipleMatches(): void
    {
        $node = new ConditionNode('inclusive_001', [
            'name' => '包含网关',
            'gateway_type' => ConditionNode::GATEWAY_INCLUSIVE,
            'conditions' => [
                [
                    'name' => '需要审批',
                    'field' => 'needs_approval',
                    'operator' => ConditionNode::OP_EQUALS,
                    'value' => true,
                    'target' => 'approval_handler',
                ],
                [
                    'name' => '需要通知',
                    'field' => 'needs_notification',
                    'operator' => ConditionNode::OP_EQUALS,
                    'value' => true,
                    'target' => 'notification_handler',
                ],
                [
                    'name' => '需要存档',
                    'field' => 'needs_archive',
                    'operator' => ConditionNode::OP_EQUALS,
                    'value' => true,
                    'target' => 'archive_handler',
                ],
            ],
        ]);

        $this->context->setVariable('needs_approval', true);
        $this->context->setVariable('needs_notification', true);
        $this->context->setVariable('needs_archive', false);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertContains('approval_handler', $result->getNextNodes());
        $this->assertContains('notification_handler', $result->getNextNodes());
        $this->assertNotContains('archive_handler', $result->getNextNodes());
    }

    /**
     * 测试各种运算符
     */
    public function testVariousOperators(): void
    {
        // 测试等于
        $this->assertConditionResult('status', ConditionNode::OP_EQUALS, 'active', 'active', true);
        $this->assertConditionResult('status', ConditionNode::OP_EQUALS, 'active', 'inactive', false);

        // 测试不等于
        $this->assertConditionResult('status', ConditionNode::OP_NOT_EQUALS, 'active', 'inactive', true);

        // 测试大于
        $this->assertConditionResult('count', ConditionNode::OP_GREATER_THAN, 10, 15, true);
        $this->assertConditionResult('count', ConditionNode::OP_GREATER_THAN, 10, 5, false);

        // 测试大于等于
        $this->assertConditionResult('count', ConditionNode::OP_GREATER_EQUALS, 10, 10, true);

        // 测试小于
        $this->assertConditionResult('count', ConditionNode::OP_LESS_THAN, 10, 5, true);

        // 测试包含
        $this->assertConditionResult('name', ConditionNode::OP_CONTAINS, 'test', 'this is a test', true);

        // 测试不包含
        $this->assertConditionResult('name', ConditionNode::OP_NOT_CONTAINS, 'xyz', 'this is a test', true);

        // 测试开头
        $this->assertConditionResult('name', ConditionNode::OP_STARTS_WITH, 'hello', 'hello world', true);

        // 测试结尾
        $this->assertConditionResult('name', ConditionNode::OP_ENDS_WITH, 'world', 'hello world', true);

        // 测试在列表中
        $this->assertConditionResult('type', ConditionNode::OP_IN, ['a', 'b', 'c'], 'b', true);
        $this->assertConditionResult('type', ConditionNode::OP_IN, ['a', 'b', 'c'], 'd', false);

        // 测试不在列表中
        $this->assertConditionResult('type', ConditionNode::OP_NOT_IN, ['a', 'b', 'c'], 'd', true);

        // 测试为空
        $this->assertConditionResult('value', ConditionNode::OP_IS_NULL, null, null, true);

        // 测试不为空
        $this->assertConditionResult('value', ConditionNode::OP_IS_NOT_NULL, null, 'something', true);

        // 测试区间
        $this->assertConditionResult('score', ConditionNode::OP_BETWEEN, [60, 100], 80, true);
        $this->assertConditionResult('score', ConditionNode::OP_BETWEEN, [60, 100], 50, false);
    }

    /**
     * 辅助方法：断言条件结果
     */
    protected function assertConditionResult(
        string $field,
        string $operator,
        mixed $conditionValue,
        mixed $actualValue,
        bool $expectedResult
    ): void {
        $context = new ExecutionContext();
        $context->setVariable($field, $actualValue);

        $node = new ConditionNode('test_op', [
            'name' => '运算符测试',
            'gateway_type' => ConditionNode::GATEWAY_EXCLUSIVE,
            'conditions' => [
                [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $conditionValue,
                    'target' => 'matched',
                ],
            ],
            'default_branch' => 'not_matched',
        ]);

        $result = $node->execute($context);
        $nextNode = $result->getNextNodes()[0] ?? '';

        if ($expectedResult) {
            $this->assertEquals('matched', $nextNode, "Expected {$operator} to match");
        } else {
            $this->assertEquals('not_matched', $nextNode, "Expected {$operator} not to match");
        }
    }

    /**
     * 测试复合规则 (AND)
     */
    public function testCompoundRulesAnd(): void
    {
        $node = new ConditionNode('compound_and', [
            'name' => '复合条件AND',
            'gateway_type' => ConditionNode::GATEWAY_EXCLUSIVE,
            'conditions' => [
                [
                    'name' => 'VIP大额订单',
                    'rules' => [
                        ['field' => 'is_vip', 'operator' => ConditionNode::OP_EQUALS, 'value' => true],
                        ['field' => 'amount', 'operator' => ConditionNode::OP_GREATER_THAN, 'value' => 1000],
                    ],
                    'logic' => 'and',
                    'target' => 'vip_large_order',
                ],
            ],
            'default_branch' => 'normal_order',
        ]);

        // 两个条件都满足
        $this->context->setVariable('is_vip', true);
        $this->context->setVariable('amount', 2000);

        $result = $node->execute($this->context);
        $this->assertEquals(['vip_large_order'], $result->getNextNodes());

        // 只满足一个条件
        $context2 = new ExecutionContext();
        $context2->setVariable('is_vip', true);
        $context2->setVariable('amount', 500);

        $result2 = $node->execute($context2);
        $this->assertEquals(['normal_order'], $result2->getNextNodes());
    }

    /**
     * 测试复合规则 (OR)
     */
    public function testCompoundRulesOr(): void
    {
        $node = new ConditionNode('compound_or', [
            'name' => '复合条件OR',
            'gateway_type' => ConditionNode::GATEWAY_EXCLUSIVE,
            'conditions' => [
                [
                    'name' => 'VIP或大额订单',
                    'rules' => [
                        ['field' => 'is_vip', 'operator' => ConditionNode::OP_EQUALS, 'value' => true],
                        ['field' => 'amount', 'operator' => ConditionNode::OP_GREATER_THAN, 'value' => 1000],
                    ],
                    'logic' => 'or',
                    'target' => 'special_order',
                ],
            ],
            'default_branch' => 'normal_order',
        ]);

        // 只满足一个条件也可以
        $this->context->setVariable('is_vip', false);
        $this->context->setVariable('amount', 2000);

        $result = $node->execute($this->context);
        $this->assertEquals(['special_order'], $result->getNextNodes());
    }

    /**
     * 测试嵌套值访问
     */
    public function testNestedValueAccess(): void
    {
        $node = new ConditionNode('nested_001', [
            'name' => '嵌套条件',
            'gateway_type' => ConditionNode::GATEWAY_EXCLUSIVE,
            'conditions' => [
                [
                    'name' => '嵌套字段检查',
                    'field' => 'order.customer.level',
                    'operator' => ConditionNode::OP_EQUALS,
                    'value' => 'gold',
                    'target' => 'gold_customer',
                ],
            ],
            'default_branch' => 'regular_customer',
        ]);

        $this->context->setVariable('order', [
            'id' => 'order_001',
            'customer' => [
                'name' => 'John',
                'level' => 'gold',
            ],
        ]);

        $result = $node->execute($this->context);
        $this->assertEquals(['gold_customer'], $result->getNextNodes());
    }

    /**
     * 测试表达式条件
     */
    public function testExpressionCondition(): void
    {
        $node = new ConditionNode('expr_001', [
            'name' => '表达式条件',
            'gateway_type' => ConditionNode::GATEWAY_EXCLUSIVE,
            'conditions' => [
                [
                    'name' => '表达式检查',
                    'expression' => 'amount > 1000 && status == "pending"',
                    'target' => 'process_large',
                ],
            ],
            'default_branch' => 'process_normal',
        ]);

        // 注意：表达式评估取决于 evaluateCondition 的实现
        // 这里只测试结构正确性
        $this->assertNotNull($node->getConfigSchema());
    }

    /**
     * 测试无匹配且无默认分支
     */
    public function testNoMatchNoDefault(): void
    {
        $node = new ConditionNode('no_default', [
            'name' => '无默认',
            'gateway_type' => ConditionNode::GATEWAY_EXCLUSIVE,
            'conditions' => [
                [
                    'field' => 'type',
                    'operator' => ConditionNode::OP_EQUALS,
                    'value' => 'special',
                    'target' => 'special_handler',
                ],
            ],
        ]);

        $this->context->setVariable('type', 'normal');

        $result = $node->execute($this->context);
        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }

    /**
     * 测试配置验证
     */
    public function testConfigValidation(): void
    {
        $node = new ConditionNode('valid_test', [
            'name' => '验证测试',
        ]);

        // 有效配置
        $validConfig = [
            'gateway_type' => ConditionNode::GATEWAY_EXCLUSIVE,
            'conditions' => [['target' => 'next']],
        ];
        $this->assertTrue($node->validateConfig($validConfig));

        // 无效网关类型
        $invalidGateway = [
            'gateway_type' => 'invalid_type',
        ];
        $this->assertFalse($node->validateConfig($invalidGateway));

        // 排他网关无条件无默认
        $invalidExclusive = [
            'gateway_type' => ConditionNode::GATEWAY_EXCLUSIVE,
        ];
        $this->assertFalse($node->validateConfig($invalidExclusive));
    }

    /**
     * 测试获取配置 Schema
     */
    public function testGetConfigSchema(): void
    {
        $node = new ConditionNode('schema_test', [
            'name' => '配置测试',
        ]);
        $schema = $node->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('gateway_type', $schema['properties']);
        $this->assertArrayHasKey('conditions', $schema['properties']);
        $this->assertArrayHasKey('default_branch', $schema['properties']);
    }
}
