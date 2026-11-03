<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Swoole;

use Alkaid\Workflow\Engine\Swoole\Execution\NodeExecutionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * NodeExecutionResult 单元测试
 */
#[CoversClass(NodeExecutionResult::class)]
class NodeExecutionResultTest extends TestCase
{
    /**
     * 测试创建成功结果
     */
    public function testCreateSuccessResult(): void
    {
        $data = ['key' => 'value'];
        $nextNodeId = 'node_2';

        $result = NodeExecutionResult::success($data, $nextNodeId);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailed());
        $this->assertFalse($result->isPending());
        $this->assertEquals(NodeExecutionResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals($data, $result->getData());
        $this->assertEquals($nextNodeId, $result->getNextNodeId());
        $this->assertNull($result->getError());
    }

    /**
     * 测试创建挂起结果
     */
    public function testCreatePendingResult(): void
    {
        $data = ['task_id' => 123];
        $message = '等待审批';

        $result = NodeExecutionResult::pending($data, $message);

        $this->assertTrue($result->isPending());
        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isFailed());
        $this->assertEquals(NodeExecutionResult::STATUS_PENDING, $result->getStatus());
        $this->assertEquals($data, $result->getData());
        $this->assertEquals($message, $result->getError());
    }

    /**
     * 测试创建失败结果
     */
    public function testCreateFailedResult(): void
    {
        $error = '执行失败';
        $errorCode = 500;
        $errorStack = 'Stack trace here...';

        $result = NodeExecutionResult::failed($error, $errorCode, $errorStack);

        $this->assertTrue($result->isFailed());
        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isPending());
        $this->assertEquals(NodeExecutionResult::STATUS_FAILED, $result->getStatus());
        $this->assertEquals($error, $result->getError());
        $this->assertEquals($errorCode, $result->getErrorCode());
        $this->assertEquals($errorStack, $result->getErrorStack());
    }

    /**
     * 测试创建跳过结果
     */
    public function testCreateSkippedResult(): void
    {
        $reason = '条件不满足';
        $nextNodeId = 'node_3';

        $result = NodeExecutionResult::skipped($reason, $nextNodeId);

        $this->assertTrue($result->isSkipped());
        $this->assertFalse($result->isSuccess());
        $this->assertEquals(NodeExecutionResult::STATUS_SKIPPED, $result->getStatus());
        $this->assertEquals(['reason' => $reason], $result->getData());
        $this->assertEquals($nextNodeId, $result->getNextNodeId());
    }

    /**
     * 测试创建取消结果
     */
    public function testCreateCancelledResult(): void
    {
        $reason = '用户取消';

        $result = NodeExecutionResult::cancelled($reason);

        $this->assertTrue($result->isCancelled());
        $this->assertFalse($result->isSuccess());
        $this->assertEquals(NodeExecutionResult::STATUS_CANCELLED, $result->getStatus());
        $this->assertEquals(['reason' => $reason], $result->getData());
    }

    /**
     * 测试从异常创建结果
     */
    public function testCreateFromException(): void
    {
        $exception = new \RuntimeException('测试异常', 123);

        $result = NodeExecutionResult::fromException($exception);

        $this->assertTrue($result->isFailed());
        $this->assertEquals('测试异常', $result->getError());
        $this->assertEquals(123, $result->getErrorCode());
        $this->assertNotNull($result->getErrorStack());
    }

    /**
     * 测试状态常量
     */
    public function testStatusConstants(): void
    {
        $this->assertEquals('SUCCESS', NodeExecutionResult::STATUS_SUCCESS);
        $this->assertEquals('PENDING', NodeExecutionResult::STATUS_PENDING);
        $this->assertEquals('FAILED', NodeExecutionResult::STATUS_FAILED);
        $this->assertEquals('SKIPPED', NodeExecutionResult::STATUS_SKIPPED);
        $this->assertEquals('CANCELLED', NodeExecutionResult::STATUS_CANCELLED);
    }

    /**
     * 测试数据操作
     */
    public function testDataOperations(): void
    {
        $result = new NodeExecutionResult();

        // 测试设置数据
        $result->setData(['foo' => 'bar']);
        $this->assertEquals(['foo' => 'bar'], $result->getData());

        // 测试获取单个键
        $this->assertEquals('bar', $result->get('foo'));
        $this->assertEquals('default', $result->get('nonexistent', 'default'));

        // 测试设置单个键
        $result->set('baz', 'qux');
        $this->assertEquals('qux', $result->get('baz'));

        // 测试合并数据
        $result->mergeData(['new' => 'data']);
        $this->assertEquals('data', $result->get('new'));
        $this->assertEquals('bar', $result->get('foo'));
    }

    /**
     * 测试节点信息
     */
    public function testNodeInfo(): void
    {
        $result = new NodeExecutionResult();

        $result->setNodeId('node_1')
            ->setNodeName('开始节点');

        $this->assertEquals('node_1', $result->getNodeId());
        $this->assertEquals('开始节点', $result->getNodeName());
    }

    /**
     * 测试重试机制
     */
    public function testRetryMechanism(): void
    {
        $result = NodeExecutionResult::failed('错误');

        $this->assertEquals(0, $result->getRetryCount());
        $this->assertTrue($result->canRetry());

        // 增加重试次数
        $result->incrementRetry();
        $this->assertEquals(1, $result->getRetryCount());
        $this->assertTrue($result->canRetry());

        // 设置最大重试次数
        $result->setMaxRetries(2);
        $result->incrementRetry();
        $this->assertEquals(2, $result->getRetryCount());
        $this->assertFalse($result->canRetry());

        // 禁用重试
        $result->setRetryable(false);
        $this->assertFalse($result->canRetry());
    }

    /**
     * 测试成功结果不可重试
     */
    public function testSuccessResultNotRetryable(): void
    {
        $result = NodeExecutionResult::success();

        $this->assertFalse($result->canRetry());
    }

    /**
     * 测试元数据操作
     */
    public function testMetadataOperations(): void
    {
        $result = new NodeExecutionResult();

        // 测试设置元数据
        $result->setMetadata(['meta_key' => 'meta_value']);
        $this->assertEquals(['meta_key' => 'meta_value'], $result->getMetadata());

        // 测试获取单个元数据
        $this->assertEquals('meta_value', $result->getMeta('meta_key'));
        $this->assertEquals('default', $result->getMeta('nonexistent', 'default'));

        // 测试设置单个元数据
        $result->setMeta('another_key', 'another_value');
        $this->assertEquals('another_value', $result->getMeta('another_key'));
    }

    /**
     * 测试执行时长
     */
    public function testDuration(): void
    {
        $result = new NodeExecutionResult();

        // 模拟一些执行时间
        usleep(10000); // 10ms

        $result->markComplete();

        $this->assertGreaterThan(0, $result->getDuration());
    }

    /**
     * 测试完成状态检查
     */
    public function testIsCompleted(): void
    {
        $successResult = NodeExecutionResult::success();
        $this->assertTrue($successResult->isCompleted());

        $failedResult = NodeExecutionResult::failed('error');
        $this->assertTrue($failedResult->isCompleted());

        $skippedResult = NodeExecutionResult::skipped();
        $this->assertTrue($skippedResult->isCompleted());

        $cancelledResult = NodeExecutionResult::cancelled();
        $this->assertTrue($cancelledResult->isCompleted());

        $pendingResult = NodeExecutionResult::pending();
        $this->assertFalse($pendingResult->isCompleted());
    }

    /**
     * 测试需要等待检查
     */
    public function testNeedsWait(): void
    {
        $pendingResult = NodeExecutionResult::pending();
        $this->assertTrue($pendingResult->needsWait());

        $successResult = NodeExecutionResult::success();
        $this->assertFalse($successResult->needsWait());
    }

    /**
     * 测试转换为数组
     */
    public function testToArray(): void
    {
        $result = NodeExecutionResult::success(['data' => 'test'], 'next_node')
            ->setNodeId('node_1')
            ->setNodeName('测试节点');

        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertEquals(NodeExecutionResult::STATUS_SUCCESS, $array['status']);
        $this->assertEquals(['data' => 'test'], $array['data']);
        $this->assertEquals('next_node', $array['next_node_id']);
        $this->assertEquals('node_1', $array['node_id']);
        $this->assertEquals('测试节点', $array['node_name']);
        $this->assertArrayHasKey('duration', $array);
        $this->assertArrayHasKey('retry_count', $array);
    }

    /**
     * 测试转换为 JSON
     */
    public function testToJson(): void
    {
        $result = NodeExecutionResult::success(['key' => 'value']);

        $json = $result->toJson();

        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertEquals(NodeExecutionResult::STATUS_SUCCESS, $decoded['status']);
        $this->assertEquals(['key' => 'value'], $decoded['data']);
    }

    /**
     * 测试从数组创建
     */
    public function testFromArray(): void
    {
        $data = [
            'status' => NodeExecutionResult::STATUS_SUCCESS,
            'data' => ['key' => 'value'],
            'next_node_id' => 'node_2',
            'error' => null,
            'error_code' => null,
            'node_id' => 'node_1',
            'node_name' => '测试节点',
            'retry_count' => 2,
            'retryable' => false,
            'metadata' => ['meta' => 'data'],
        ];

        $result = NodeExecutionResult::fromArray($data);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(['key' => 'value'], $result->getData());
        $this->assertEquals('node_2', $result->getNextNodeId());
        $this->assertEquals('node_1', $result->getNodeId());
        $this->assertEquals('测试节点', $result->getNodeName());
        $this->assertEquals(2, $result->getRetryCount());
        $this->assertEquals(['meta' => 'data'], $result->getMetadata());
    }

    /**
     * 测试链式调用
     */
    public function testChainedCalls(): void
    {
        $result = (new NodeExecutionResult())
            ->setStatus(NodeExecutionResult::STATUS_SUCCESS)
            ->setData(['key' => 'value'])
            ->setNextNodeId('next')
            ->setNodeId('current')
            ->setNodeName('当前节点')
            ->setMeta('meta_key', 'meta_value')
            ->markComplete();

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(['key' => 'value'], $result->getData());
        $this->assertEquals('next', $result->getNextNodeId());
        $this->assertEquals('current', $result->getNodeId());
        $this->assertEquals('当前节点', $result->getNodeName());
        $this->assertEquals('meta_value', $result->getMeta('meta_key'));
        $this->assertGreaterThan(0, $result->getDuration());
    }
}
