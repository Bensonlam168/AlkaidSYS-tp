<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use PHPUnit\Framework\TestCase;
use Alkaid\Workflow\Engine\Nodes\Types\ApprovalNode;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * ApprovalNode 单元测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Nodes
 */
class ApprovalNodeTest extends TestCase
{
    protected ApprovalNode $node;
    protected ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->node = new ApprovalNode('approval_001', [
            'name' => '审批节点',
            'approvers' => ['user_001', 'user_002'],
            'approval_type' => ApprovalNode::APPROVAL_TYPE_SINGLE,
            'timeout' => 3600,
            'require_comment' => false,
        ]);

        $this->context = new ExecutionContext();
        $this->context->setInstanceId('inst_test_001');
        $this->context->setTenantId(100);
    }

    /**
     * 测试节点创建
     */
    public function testNodeCreation(): void
    {
        $this->assertEquals('approval_001', $this->node->getId());
        $this->assertEquals(NodeInterface::TYPE_TASK, $this->node->getType());
        $this->assertEquals(NodeInterface::TASK_USER, $this->node->getSubType());
    }

    /**
     * 测试节点执行返回等待状态
     */
    public function testExecuteReturnsWaitingStatus(): void
    {
        $result = $this->node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_WAITING, $result->getStatus());
        $this->assertArrayHasKey('task_id', $result->getData());
        $this->assertArrayHasKey('approvers', $result->getData());
        $this->assertEquals(['user_001', 'user_002'], $result->getData()['approvers']);
    }

    /**
     * 测试任务数据持久化
     */
    public function testTaskDataPersistence(): void
    {
        // 第一次执行
        $result1 = $this->node->execute($this->context);
        $taskId1 = $result1->getData()['task_id'];

        // 第二次执行应该返回相同的任务
        $result2 = $this->node->execute($this->context);
        $taskId2 = $result2->getData()['task_id'];

        $this->assertEquals($taskId1, $taskId2);
    }

    /**
     * 测试审批通过
     */
    public function testProcessApproveAction(): void
    {
        // 先执行创建任务
        $this->node->execute($this->context);

        // 处理审批通过
        $result = $this->node->processAction(
            $this->context,
            'approve',
            'user_001',
            '同意',
            ['form_field' => 'value']
        );

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals('approved', $result->getData()['status']);
    }

    /**
     * 测试审批拒绝
     */
    public function testProcessRejectAction(): void
    {
        $this->node->setConfig([
            'approvers' => ['user_001'],
            'approval_type' => ApprovalNode::APPROVAL_TYPE_SINGLE,
            'outcome_routes' => [
                'rejected' => ['reject_handler'],
            ],
        ]);

        $this->node->execute($this->context);

        $result = $this->node->processAction(
            $this->context,
            'reject',
            'user_001',
            '拒绝原因'
        );

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals('rejected', $result->getData()['status']);
    }

    /**
     * 测试非授权用户审批
     */
    public function testUnauthorizedApprover(): void
    {
        $this->node->execute($this->context);

        $result = $this->node->processAction(
            $this->context,
            'approve',
            'unauthorized_user',
            '审批意见'
        );

        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
        $this->assertEquals(403, $result->getErrorCode());
    }

    /**
     * 测试顺序审批
     */
    public function testSequentialApproval(): void
    {
        $node = new ApprovalNode('seq_approval', [
            'name' => '顺序审批',
            'approvers' => ['user_001', 'user_002', 'user_003'],
            'approval_type' => ApprovalNode::APPROVAL_TYPE_SEQUENTIAL,
        ]);

        $node->execute($this->context);

        // 第一个审批人通过
        $result1 = $node->processAction($this->context, 'approve', 'user_001', '第一级通过');

        // 还没有完成，因为是顺序审批
        $this->assertEquals(NodeResult::STATUS_WAITING, $result1->getStatus());
    }

    /**
     * 测试配置验证
     */
    public function testConfigValidation(): void
    {
        $validConfig = [
            'approval_type' => ApprovalNode::APPROVAL_TYPE_SINGLE,
            'timeout' => 3600,
            'timeout_action' => ApprovalNode::TIMEOUT_ACTION_AUTO_APPROVE,
        ];
        $this->assertTrue($this->node->validateConfig($validConfig));

        $invalidConfig = [
            'approval_type' => 'invalid_type',
        ];
        $this->assertFalse($this->node->validateConfig($invalidConfig));
    }

    /**
     * 测试获取配置 Schema
     */
    public function testGetConfigSchema(): void
    {
        $schema = $this->node->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('approvers', $schema['properties']);
        $this->assertArrayHasKey('approval_type', $schema['properties']);
        $this->assertArrayHasKey('timeout', $schema['properties']);
    }

    /**
     * 测试审批人表达式解析
     */
    public function testApproverExpressionResolution(): void
    {
        $node = new ApprovalNode('expr_approval', [
            'name' => '表达式审批',
            'approvers' => ['${assigned_approvers}'],
        ]);

        // 设置上下文变量
        $this->context->setVariable('assigned_approvers', ['expr_user_001', 'expr_user_002']);

        $result = $node->execute($this->context);

        $this->assertEquals(['expr_user_001', 'expr_user_002'], $result->getData()['approvers']);
    }

    /**
     * 测试超时配置
     */
    public function testTimeoutConfiguration(): void
    {
        $node = new ApprovalNode('timeout_test', [
            'name' => '超时测试',
            'approvers' => ['user_001'],
            'timeout' => 60,
            'timeout_action' => ApprovalNode::TIMEOUT_ACTION_AUTO_REJECT,
        ]);

        $result = $node->execute($this->context);

        $this->assertNotNull($result->getData()['deadline']);
    }

    /**
     * 测试必须填写意见
     */
    public function testRequireComment(): void
    {
        $node = new ApprovalNode('comment_required', [
            'name' => '需要意见',
            'approvers' => ['user_001'],
            'require_comment' => true,
        ]);

        $node->execute($this->context);

        // 不提供意见应该失败
        $result = $node->processAction($this->context, 'approve', 'user_001', null);
        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());

        // 提供意见应该成功
        $result2 = $node->processAction($this->context, 'approve', 'user_001', '已审核');
        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result2->getStatus());
    }
}
