<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use Alkaid\Workflow\Engine\Nodes\NodeResult;
use PHPUnit\Framework\TestCase;

/**
 * NodeResult 单元测试
 */
class NodeResultTest extends TestCase
{
    /**
     * 测试成功结果创建
     */
    public function testSuccessResultCreation(): void
    {
        $data = ['key' => 'value', 'count' => 10];
        $nextNodes = ['node_2', 'node_3'];

        $result = NodeResult::success($data, $nextNodes);

        $this->assertInstanceOf(NodeResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailed());
        $this->assertFalse($result->isWaiting());
        $this->assertFalse($result->isPending());
        $this->assertFalse($result->isSkipped());
        $this->assertTrue($result->isCompleted());
        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals($data, $result->getData());
        $this->assertEquals($nextNodes, $result->getNextNodes());
    }

    /**
     * 测试失败结果创建
     */
    public function testFailedResultCreation(): void
    {
        $errorMessage = '节点执行失败';
        $errorCode = 1001;

        $result = NodeResult::failed($errorMessage, $errorCode);

        $this->assertTrue($result->isFailed());
        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isCompleted());
        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
        $this->assertEquals($errorMessage, $result->getError());
        $this->assertEquals($errorCode, $result->getErrorCode());
    }

    /**
     * 测试等待结果创建
     */
    public function testWaitingResultCreation(): void
    {
        $data = ['task_id' => 'task_123'];
        $message = '等待用户处理';

        $result = NodeResult::waiting($data, $message);

        $this->assertTrue($result->isWaiting());
        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isCompleted());
        $this->assertEquals(NodeResult::STATUS_WAITING, $result->getStatus());
        $this->assertEquals($data, $result->getData());
        $this->assertEquals($message, $result->getMeta('message'));
    }

    /**
     * 测试待处理结果创建
     */
    public function testPendingResultCreation(): void
    {
        $data = ['queue_id' => 'queue_456'];

        $result = NodeResult::pending($data);

        $this->assertTrue($result->isPending());
        $this->assertFalse($result->isCompleted());
        $this->assertEquals(NodeResult::STATUS_PENDING, $result->getStatus());
        $this->assertEquals($data, $result->getData());
    }

    /**
     * 测试跳过结果创建
     */
    public function testSkippedResultCreation(): void
    {
        $reason = '条件不满足';
        $nextNodes = ['node_4'];

        $result = NodeResult::skipped($reason, $nextNodes);

        $this->assertTrue($result->isSkipped());
        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isCompleted());
        $this->assertEquals(NodeResult::STATUS_SKIPPED, $result->getStatus());
        $this->assertEquals($reason, $result->getData()['reason']);
        $this->assertEquals($nextNodes, $result->getNextNodes());
    }

    /**
     * 测试取消结果创建
     */
    public function testCancelledResultCreation(): void
    {
        $reason = '用户取消';

        $result = NodeResult::cancelled($reason);

        $this->assertTrue($result->isCancelled());
        $this->assertTrue($result->isCompleted());
        $this->assertEquals(NodeResult::STATUS_CANCELLED, $result->getStatus());
        $this->assertEquals($reason, $result->getData()['reason']);
    }

    /**
     * 测试从异常创建结果
     */
    public function testFromExceptionCreation(): void
    {
        $exception = new \RuntimeException('测试异常', 500);

        $result = NodeResult::fromException($exception);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('测试异常', $result->getError());
        $this->assertEquals(500, $result->getErrorCode());
        $this->assertEquals(\RuntimeException::class, $result->getMeta('exception_class'));
    }

    /**
     * 测试数据操作方法
     */
    public function testDataMethods(): void
    {
        $result = NodeResult::success(['initial' => 'data']);

        // 测试 get
        $this->assertEquals('data', $result->get('initial'));
        $this->assertNull($result->get('nonexistent'));
        $this->assertEquals('default', $result->get('nonexistent', 'default'));

        // 测试 set
        $result->set('new_key', 'new_value');
        $this->assertEquals('new_value', $result->get('new_key'));

        // 测试 mergeData
        $result->mergeData(['merged' => 'value', 'count' => 5]);
        $this->assertEquals('value', $result->get('merged'));
        $this->assertEquals(5, $result->get('count'));
        $this->assertEquals('data', $result->get('initial')); // 保留原有数据
    }

    /**
     * 测试元数据操作
     */
    public function testMetadataMethods(): void
    {
        $result = NodeResult::success([]);

        $result->setMeta('custom_key', 'custom_value');
        $result->setMeta('nested', ['a' => 1, 'b' => 2]);

        $this->assertEquals('custom_value', $result->getMeta('custom_key'));
        $this->assertEquals(['a' => 1, 'b' => 2], $result->getMeta('nested'));
        $this->assertNull($result->getMeta('nonexistent'));
        $this->assertEquals('default', $result->getMeta('nonexistent', 'default'));

        // 获取所有元数据
        $allMeta = $result->getMeta();
        $this->assertIsArray($allMeta);
        $this->assertArrayHasKey('custom_key', $allMeta);
    }

    /**
     * 测试节点信息设置
     */
    public function testNodeInfo(): void
    {
        $result = NodeResult::success([]);

        $result->setNodeInfo('node_123', '审批节点');

        $this->assertEquals('node_123', $result->getNodeId());
        $this->assertEquals('审批节点', $result->getNodeName());
    }

    /**
     * 测试下一个节点操作
     */
    public function testNextNodesMethods(): void
    {
        $result = NodeResult::success([], ['node_1']);

        // 测试添加节点
        $result->addNextNode('node_2');
        $this->assertEquals(['node_1', 'node_2'], $result->getNextNodes());

        // 测试重复添加（不应重复）
        $result->addNextNode('node_2');
        $this->assertCount(2, $result->getNextNodes());

        // 测试设置节点
        $result->setNextNodes(['node_3', 'node_4']);
        $this->assertEquals(['node_3', 'node_4'], $result->getNextNodes());
    }

    /**
     * 测试时间相关方法
     */
    public function testTimeMethods(): void
    {
        $before = microtime(true);
        $result = NodeResult::success([]);
        $after = microtime(true);

        // 测试开始时间
        $this->assertGreaterThanOrEqual($before, $result->getStartTime());
        $this->assertLessThanOrEqual($after, $result->getStartTime());

        // 测试结束时间（成功结果应该有结束时间）
        $this->assertNotNull($result->getEndTime());

        // 测试执行时长
        $duration = $result->getDuration();
        $this->assertIsFloat($duration);
        $this->assertGreaterThanOrEqual(0, $duration);
    }

    /**
     * 测试 markEnd 方法
     */
    public function testMarkEnd(): void
    {
        $result = NodeResult::pending([]);

        // pending 状态没有结束时间
        $this->assertNull($result->getEndTime());

        $result->markEnd();

        $this->assertNotNull($result->getEndTime());

        // 再次调用不应更改
        $firstEndTime = $result->getEndTime();
        usleep(1000); // 等待 1ms
        $result->markEnd();
        $this->assertEquals($firstEndTime, $result->getEndTime());
    }

    /**
     * 测试转数组
     */
    public function testToArray(): void
    {
        $result = NodeResult::success(['key' => 'value'], ['next_node'])
            ->setNodeInfo('node_001', '测试节点')
            ->setMeta('custom', 'meta_value');

        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertEquals(NodeResult::STATUS_SUCCESS, $array['status']);
        $this->assertTrue($array['success']);
        $this->assertEquals(['key' => 'value'], $array['data']);
        $this->assertEquals(['next_node'], $array['next_nodes']);
        $this->assertEquals('node_001', $array['node_id']);
        $this->assertEquals('测试节点', $array['node_name']);
        $this->assertEquals('meta_value', $array['metadata']['custom']);
        $this->assertArrayHasKey('start_time', $array);
        $this->assertArrayHasKey('end_time', $array);
        $this->assertArrayHasKey('duration_ms', $array);
    }

    /**
     * 测试从数组创建
     */
    public function testFromArray(): void
    {
        $data = [
            'status' => NodeResult::STATUS_SUCCESS,
            'data' => ['key' => 'value'],
            'error' => null,
            'next_nodes' => ['node_2'],
            'metadata' => ['custom' => 'value'],
            'node_id' => 'node_123',
            'node_name' => '测试节点',
        ];

        $result = NodeResult::fromArray($data);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(['key' => 'value'], $result->getData());
        $this->assertEquals(['node_2'], $result->getNextNodes());
        $this->assertEquals('value', $result->getMeta('custom'));
        $this->assertEquals('node_123', $result->getNodeId());
        $this->assertEquals('测试节点', $result->getNodeName());
    }

    /**
     * 测试状态常量
     */
    public function testStatusConstants(): void
    {
        $this->assertEquals('SUCCESS', NodeResult::STATUS_SUCCESS);
        $this->assertEquals('FAILED', NodeResult::STATUS_FAILED);
        $this->assertEquals('PENDING', NodeResult::STATUS_PENDING);
        $this->assertEquals('SKIPPED', NodeResult::STATUS_SKIPPED);
        $this->assertEquals('WAITING', NodeResult::STATUS_WAITING);
        $this->assertEquals('CANCELLED', NodeResult::STATUS_CANCELLED);
    }

    /**
     * 测试链式调用
     */
    public function testFluentInterface(): void
    {
        $result = NodeResult::success(['initial' => 'data'])
            ->setNodeInfo('node_1', 'Node 1')
            ->setMeta('key1', 'value1')
            ->set('new_key', 'new_value')
            ->addNextNode('node_2')
            ->mergeData(['merged' => 'data']);

        $this->assertEquals('node_1', $result->getNodeId());
        $this->assertEquals('value1', $result->getMeta('key1'));
        $this->assertEquals('new_value', $result->get('new_key'));
        $this->assertContains('node_2', $result->getNextNodes());
        $this->assertEquals('data', $result->get('merged'));
    }
}
