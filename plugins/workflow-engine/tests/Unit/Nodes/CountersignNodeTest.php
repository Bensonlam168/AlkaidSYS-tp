<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use PHPUnit\Framework\TestCase;
use Alkaid\Workflow\Engine\Nodes\Types\CountersignNode;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * CountersignNode 单元测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Nodes
 */
class CountersignNodeTest extends TestCase
{
    protected CountersignNode $node;
    protected ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->node = new CountersignNode('countersign_001', [
            'name' => '会签节点',
            'approvers' => ['user_001', 'user_002', 'user_003'],
            'completion_strategy' => CountersignNode::STRATEGY_ALL,
            'allow_abstain' => false,
        ]);

        $this->context = new ExecutionContext();
        $this->context->setInstanceId('inst_csign_001');
        $this->context->setTenantId(100);
    }

    /**
     * 测试节点创建
     */
    public function testNodeCreation(): void
    {
        $this->assertEquals('countersign_001', $this->node->getId());
        $this->assertEquals(NodeInterface::TYPE_TASK, $this->node->getType());
    }

    /**
     * 测试执行返回等待状态
     */
    public function testExecuteReturnsWaitingStatus(): void
    {
        $result = $this->node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_WAITING, $result->getStatus());
        $this->assertArrayHasKey('votes', $result->getData());
        $this->assertEquals(3, $result->getData()['votes']['total']);
        $this->assertEquals(0, $result->getData()['progress']);
    }

    /**
     * 测试全部通过策略
     */
    public function testAllApprovalStrategy(): void
    {
        $this->node->execute($this->context);

        // 第一个投票
        $result1 = $this->node->processVote($this->context, 'user_001', CountersignNode::VOTE_APPROVE, '同意1');
        $this->assertEquals(NodeResult::STATUS_WAITING, $result1->getStatus());

        // 第二个投票
        $result2 = $this->node->processVote($this->context, 'user_002', CountersignNode::VOTE_APPROVE, '同意2');
        $this->assertEquals(NodeResult::STATUS_WAITING, $result2->getStatus());

        // 第三个投票 - 应该完成
        $result3 = $this->node->processVote($this->context, 'user_003', CountersignNode::VOTE_APPROVE, '同意3');
        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result3->getStatus());
        $this->assertEquals('approved', $result3->getData()['status']);
    }

    /**
     * 测试一票否决（全部通过策略下）
     */
    public function testOneRejectVetosAll(): void
    {
        $this->node->setConfig([
            'approvers' => ['user_001', 'user_002', 'user_003'],
            'completion_strategy' => CountersignNode::STRATEGY_ALL,
            'outcome_routes' => [
                'rejected' => ['reject_node'],
            ],
        ]);

        $this->node->execute($this->context);

        // 第一个投票
        $this->node->processVote($this->context, 'user_001', CountersignNode::VOTE_APPROVE, '同意');

        // 第二个拒绝 - 应该直接结束
        $result = $this->node->processVote($this->context, 'user_002', CountersignNode::VOTE_REJECT, '不同意');
        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals('rejected', $result->getData()['status']);
    }

    /**
     * 测试多数通过策略
     */
    public function testMajorityApprovalStrategy(): void
    {
        $node = new CountersignNode('majority_csign', [
            'name' => '多数通过',
            'approvers' => ['user_001', 'user_002', 'user_003'],
            'completion_strategy' => CountersignNode::STRATEGY_MAJORITY,
        ]);

        $node->execute($this->context);

        // 第一个通过
        $result1 = $node->processVote($this->context, 'user_001', CountersignNode::VOTE_APPROVE, '同意');
        $this->assertEquals(NodeResult::STATUS_WAITING, $result1->getStatus());

        // 第二个通过 - 已超过50%，应该完成
        $result2 = $node->processVote($this->context, 'user_002', CountersignNode::VOTE_APPROVE, '同意');
        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result2->getStatus());
        $this->assertEquals('approved', $result2->getData()['status']);
    }

    /**
     * 测试百分比策略
     */
    public function testPercentageStrategy(): void
    {
        $node = new CountersignNode('percentage_csign', [
            'name' => '百分比通过',
            'approvers' => ['user_001', 'user_002', 'user_003', 'user_004', 'user_005'],
            'completion_strategy' => CountersignNode::STRATEGY_PERCENTAGE,
            'completion_value' => 60, // 需要60%通过
        ]);

        $node->execute($this->context);

        // 3人通过 = 60%
        $node->processVote($this->context, 'user_001', CountersignNode::VOTE_APPROVE, '同意');
        $node->processVote($this->context, 'user_002', CountersignNode::VOTE_APPROVE, '同意');
        $result = $node->processVote($this->context, 'user_003', CountersignNode::VOTE_APPROVE, '同意');

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
    }

    /**
     * 测试固定数量策略
     */
    public function testCountStrategy(): void
    {
        $node = new CountersignNode('count_csign', [
            'name' => '固定数量',
            'approvers' => ['user_001', 'user_002', 'user_003', 'user_004', 'user_005'],
            'completion_strategy' => CountersignNode::STRATEGY_COUNT,
            'completion_value' => 2, // 只需要2人通过
        ]);

        $node->execute($this->context);

        $node->processVote($this->context, 'user_001', CountersignNode::VOTE_APPROVE, '同意');
        $result = $node->processVote($this->context, 'user_002', CountersignNode::VOTE_APPROVE, '同意');

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
    }

    /**
     * 测试弃权投票
     */
    public function testAbstainVote(): void
    {
        $node = new CountersignNode('abstain_csign', [
            'name' => '允许弃权',
            'approvers' => ['user_001', 'user_002'],
            'completion_strategy' => CountersignNode::STRATEGY_ALL,
            'allow_abstain' => true,
        ]);

        $node->execute($this->context);

        // 弃权应该被接受
        $result = $node->processVote($this->context, 'user_001', CountersignNode::VOTE_ABSTAIN, '弃权');
        $this->assertEquals(NodeResult::STATUS_WAITING, $result->getStatus());
    }

    /**
     * 测试不允许弃权
     */
    public function testAbstainNotAllowed(): void
    {
        $this->node->execute($this->context);

        $result = $this->node->processVote($this->context, 'user_001', CountersignNode::VOTE_ABSTAIN, '弃权');
        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }

    /**
     * 测试重复投票
     */
    public function testDuplicateVote(): void
    {
        $this->node->execute($this->context);

        // 第一次投票成功
        $this->node->processVote($this->context, 'user_001', CountersignNode::VOTE_APPROVE, '同意');

        // 重复投票应该失败
        $result = $this->node->processVote($this->context, 'user_001', CountersignNode::VOTE_REJECT, '改为拒绝');
        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }

    /**
     * 测试非授权用户投票
     */
    public function testUnauthorizedVoter(): void
    {
        $this->node->execute($this->context);

        $result = $this->node->processVote($this->context, 'unauthorized_user', CountersignNode::VOTE_APPROVE, '同意');
        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
        $this->assertEquals(403, $result->getErrorCode());
    }

    /**
     * 测试进度计算
     */
    public function testProgressCalculation(): void
    {
        $this->node->execute($this->context);

        // 投票前进度为0
        $result1 = $this->node->execute($this->context);
        $this->assertEquals(0, $result1->getData()['progress']);

        // 一人投票后进度约33%
        $this->node->processVote($this->context, 'user_001', CountersignNode::VOTE_APPROVE, '同意');
        $result2 = $this->node->execute($this->context);
        $this->assertGreaterThan(30, $result2->getData()['progress']);
    }

    /**
     * 测试配置验证
     */
    public function testConfigValidation(): void
    {
        // 有效配置
        $validConfig = [
            'approvers' => ['user_001'],
            'completion_strategy' => CountersignNode::STRATEGY_MAJORITY,
        ];
        $this->assertTrue($this->node->validateConfig($validConfig));

        // 无效配置 - 缺少审批人
        $invalidConfig = [
            'completion_strategy' => CountersignNode::STRATEGY_ALL,
        ];
        $this->assertFalse($this->node->validateConfig($invalidConfig));

        // 无效配置 - 无效策略
        $invalidStrategy = [
            'approvers' => ['user_001'],
            'completion_strategy' => 'invalid_strategy',
        ];
        $this->assertFalse($this->node->validateConfig($invalidStrategy));
    }

    /**
     * 测试获取配置 Schema
     */
    public function testGetConfigSchema(): void
    {
        $schema = $this->node->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('approvers', $schema['properties']);
        $this->assertArrayHasKey('completion_strategy', $schema['properties']);
        $this->assertArrayHasKey('allow_abstain', $schema['properties']);
    }

    /**
     * 测试投票汇总
     */
    public function testVotesSummary(): void
    {
        $this->node->execute($this->context);

        // 只投一票，检查等待状态下的投票汇总
        $this->node->processVote($this->context, 'user_001', CountersignNode::VOTE_APPROVE, '同意');

        $result = $this->node->execute($this->context);
        $votes = $result->getData()['votes'];

        $this->assertEquals(3, $votes['total']);
        $this->assertEquals(1, $votes['voted']);
        $this->assertEquals(1, $votes['approve']);
        $this->assertEquals(0, $votes['reject']);
        $this->assertEquals(2, $votes['pending']);
    }
}
