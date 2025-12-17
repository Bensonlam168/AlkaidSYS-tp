<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Nodes\Types\TaskNode;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;
use PHPUnit\Framework\TestCase;

/**
 * TaskNode 单元测试
 */
class TaskNodeTest extends TestCase
{
    private TaskNode $node;
    private ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->node = new TaskNode('task_001', [
            'name' => 'test_task',
            'display_name' => '测试任务节点',
            'task_type' => NodeInterface::TASK_SERVICE,
            'outputs' => ['next_node'],
        ]);

        $this->context = new ExecutionContext('workflow_001', 'instance_001', 100);
    }

    /**
     * 测试节点类型
     */
    public function testNodeType(): void
    {
        $this->assertEquals(NodeInterface::TYPE_TASK, $this->node->getType());
    }

    /**
     * 测试任务子类型
     */
    public function testTaskSubType(): void
    {
        $this->assertEquals(NodeInterface::TASK_SERVICE, $this->node->getSubType());

        // 测试用户任务类型
        $userTask = new TaskNode('user_task_001', [
            'task_type' => NodeInterface::TASK_USER,
        ]);
        $this->assertEquals(NodeInterface::TASK_USER, $userTask->getSubType());
    }

    /**
     * 测试执行服务任务
     */
    public function testExecuteServiceTask(): void
    {
        $result = $this->node->execute($this->context);

        $this->assertInstanceOf(NodeResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('task_001', $result->getNodeId());

        // 检查下一个节点
        $this->assertEquals(['next_node'], $result->getNextNodes());
    }

    /**
     * 测试执行用户任务
     */
    public function testExecuteUserTask(): void
    {
        $node = new TaskNode('user_task_001', [
            'name' => 'approval_task',
            'task_type' => NodeInterface::TASK_USER,
            'assignee' => 'user_123',
            'form' => 'approval_form',
            'priority' => 'high',
            'outputs' => ['next_node'],
        ]);

        $result = $node->execute($this->context);

        // 用户任务返回等待状态
        $this->assertTrue($result->isWaiting());
        $this->assertEquals('user', $result->getMeta('task_type'));
        $this->assertEquals('user_123', $result->getMeta('assignee'));

        // 检查任务数据
        $data = $result->getData();
        $this->assertEquals('user', $data['task_type']);
        $this->assertEquals('user_123', $data['assignee']);
        $this->assertEquals('approval_form', $data['form']);
        $this->assertEquals('high', $data['priority']);
    }

    /**
     * 测试指派人变量替换
     */
    public function testAssigneeVariableReplacement(): void
    {
        $node = new TaskNode('task_002', [
            'task_type' => NodeInterface::TASK_USER,
            'assignee' => '${approver_id}',
            'outputs' => ['next_node'],
        ]);

        $this->context->setVariable('approver_id', 'dynamic_user_456');
        $result = $node->execute($this->context);

        $this->assertEquals('dynamic_user_456', $result->getMeta('assignee'));
    }

    /**
     * 测试候选用户
     */
    public function testCandidateUsers(): void
    {
        $node = new TaskNode('task_003', [
            'task_type' => NodeInterface::TASK_USER,
            'candidate_users' => ['user_1', 'user_2', 'user_3'],
            'outputs' => ['next_node'],
        ]);

        $result = $node->execute($this->context);
        $data = $result->getData();

        $this->assertCount(3, $data['candidate_users']);
        $this->assertContains('user_1', $data['candidate_users']);
    }

    /**
     * 测试候选用户组
     */
    public function testCandidateGroups(): void
    {
        $node = new TaskNode('task_004', [
            'task_type' => NodeInterface::TASK_USER,
            'candidate_groups' => ['managers', 'approvers'],
            'outputs' => ['next_node'],
        ]);

        $result = $node->execute($this->context);
        $data = $result->getData();

        $this->assertCount(2, $data['candidate_groups']);
        $this->assertContains('managers', $data['candidate_groups']);
    }

    /**
     * 测试自定义处理器
     */
    public function testCustomHandler(): void
    {
        $handlerCalled = false;
        $this->node->setHandler(function (ExecutionContext $ctx, TaskNode $node) use (&$handlerCalled) {
            $handlerCalled = true;
            return [
                'custom_result' => 'handler_executed',
                'node_id' => $node->getId(),
            ];
        });

        $result = $this->node->execute($this->context);

        $this->assertTrue($handlerCalled);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('handler_executed', $result->get('custom_result'));
    }

    /**
     * 测试自定义处理器返回 NodeResult
     */
    public function testCustomHandlerReturnsNodeResult(): void
    {
        $this->node->setHandler(function (ExecutionContext $ctx, TaskNode $node) {
            return NodeResult::success(['handler' => 'returned_result'], ['custom_next']);
        });

        $result = $this->node->execute($this->context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('returned_result', $result->get('handler'));
        $this->assertEquals(['custom_next'], $result->getNextNodes());
    }

    /**
     * 测试执行发送任务
     */
    public function testExecuteSendTask(): void
    {
        $node = new TaskNode('send_task_001', [
            'task_type' => NodeInterface::TASK_SEND,
            'message_type' => 'email',
            'recipient' => 'user@example.com',
            'content' => '您的订单 ${order_id} 已确认',
            'outputs' => ['next_node'],
        ]);

        $this->context->setVariable('order_id', 'ORD-12345');
        $result = $node->execute($this->context);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getMeta('requires_send'));

        $data = $result->getData();
        $this->assertEquals('send', $data['task_type']);
        $this->assertEquals('email', $data['message_type']);
        $this->assertEquals('user@example.com', $data['recipient']);
        $this->assertStringContainsString('ORD-12345', $data['content']);
    }

    /**
     * 测试执行接收任务
     */
    public function testExecuteReceiveTask(): void
    {
        $node = new TaskNode('receive_task_001', [
            'task_type' => NodeInterface::TASK_RECEIVE,
            'expected_message_type' => 'payment_confirmation',
            'timeout' => 3600,
            'outputs' => ['next_node'],
        ]);

        $result = $node->execute($this->context);

        $this->assertTrue($result->isWaiting());

        $data = $result->getData();
        $this->assertEquals('receive', $data['task_type']);
        $this->assertEquals('payment_confirmation', $data['message_type']);
        $this->assertEquals(3600, $data['timeout']);
    }

    /**
     * 测试执行手动任务
     */
    public function testExecuteManualTask(): void
    {
        $node = new TaskNode('manual_task_001', [
            'task_type' => NodeInterface::TASK_MANUAL,
            'instructions' => '请检查库存并确认',
            'outputs' => ['next_node'],
        ]);

        $result = $node->execute($this->context);

        $this->assertTrue($result->isWaiting());

        $data = $result->getData();
        $this->assertEquals('manual', $data['task_type']);
        $this->assertEquals('请检查库存并确认', $data['instructions']);
    }

    /**
     * 测试执行业务规则任务
     */
    public function testExecuteBusinessRuleTask(): void
    {
        $node = new TaskNode('rule_task_001', [
            'task_type' => NodeInterface::TASK_BUSINESS_RULE,
            'rule_id' => 'discount_calculation',
            'outputs' => ['next_node'],
        ]);

        $result = $node->execute($this->context);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getMeta('requires_rule_execution'));

        $data = $result->getData();
        $this->assertEquals('business_rule', $data['task_type']);
        $this->assertEquals('discount_calculation', $data['rule_id']);
    }

    /**
     * 测试业务规则任务无规则ID
     */
    public function testBusinessRuleTaskWithoutRuleId(): void
    {
        $node = new TaskNode('rule_task_002', [
            'task_type' => NodeInterface::TASK_BUSINESS_RULE,
            'outputs' => ['next_node'],
        ]);

        $result = $node->execute($this->context);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('业务规则ID', $result->getError());
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
        $this->assertArrayHasKey('task_type', $schema['properties']);
        $this->assertArrayHasKey('assignee', $schema['properties']);
        $this->assertArrayHasKey('candidate_users', $schema['properties']);
        $this->assertArrayHasKey('form', $schema['properties']);
        $this->assertArrayHasKey('service_class', $schema['properties']);
    }

    /**
     * 测试任务类型常量
     */
    public function testTaskTypeConstants(): void
    {
        $this->assertEquals('user', NodeInterface::TASK_USER);
        $this->assertEquals('service', NodeInterface::TASK_SERVICE);
        $this->assertEquals('script', NodeInterface::TASK_SCRIPT);
        $this->assertEquals('send', NodeInterface::TASK_SEND);
        $this->assertEquals('receive', NodeInterface::TASK_RECEIVE);
        $this->assertEquals('manual', NodeInterface::TASK_MANUAL);
        $this->assertEquals('business_rule', NodeInterface::TASK_BUSINESS_RULE);
    }

    /**
     * 测试转数组
     */
    public function testToArray(): void
    {
        $array = $this->node->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('task_001', $array['id']);
        $this->assertEquals(NodeInterface::TYPE_TASK, $array['type']);
        $this->assertEquals(NodeInterface::TASK_SERVICE, $array['sub_type']);
        $this->assertEquals('test_task', $array['name']);
    }

    /**
     * 测试从数组创建
     */
    public function testFromArray(): void
    {
        $data = [
            'id' => 'task_from_array',
            'type' => NodeInterface::TYPE_TASK,
            'sub_type' => NodeInterface::TASK_USER,
            'name' => 'array_task',
            'config' => [
                'assignee' => 'user_001',
            ],
        ];

        $node = TaskNode::fromArray($data);

        $this->assertEquals('task_from_array', $node->getId());
        $this->assertEquals('array_task', $node->getName());
        $this->assertEquals(NodeInterface::TASK_USER, $node->getSubType());
    }

    /**
     * 测试暂停状态下不执行
     */
    public function testCannotExecuteWhenPaused(): void
    {
        $this->context->pause('测试暂停');

        $this->assertFalse($this->node->canExecute($this->context));
    }
}
