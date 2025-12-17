<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;
use PHPUnit\Framework\TestCase;

/**
 * TriggerResult 单元测试
 */
class TriggerResultTest extends TestCase
{
    /**
     * 测试成功结果创建
     */
    public function testSuccessResultCreation(): void
    {
        $data = ['workflow_id' => 'test-001', 'triggered_at' => time()];

        $result = TriggerResult::success($data);

        $this->assertInstanceOf(TriggerResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailed());
        $this->assertFalse($result->isSkipped());
        $this->assertFalse($result->isPending());
        $this->assertEquals(TriggerResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals($data, $result->getData());
    }

    /**
     * 测试失败结果创建
     */
    public function testFailedResultCreation(): void
    {
        $errorMessage = '触发器配置无效';

        $result = TriggerResult::failed($errorMessage);

        $this->assertInstanceOf(TriggerResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailed());
        $this->assertFalse($result->isSkipped());
        $this->assertFalse($result->isPending());
        $this->assertEquals(TriggerResult::STATUS_FAILED, $result->getStatus());
        $this->assertEquals($errorMessage, $result->getError());
    }

    /**
     * 测试跳过结果创建
     */
    public function testSkippedResultCreation(): void
    {
        $reason = '触发条件不满足';

        $result = TriggerResult::skipped($reason);

        $this->assertInstanceOf(TriggerResult::class, $result);
        // skipped 状态的 success 字段为 true，但 isSuccess() 返回 false 因为状态不是 SUCCESS
        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isFailed());
        $this->assertTrue($result->isSkipped());
        $this->assertFalse($result->isPending());
        $this->assertEquals(TriggerResult::STATUS_SKIPPED, $result->getStatus());
        $this->assertEquals($reason, $result->getData()['reason']);
    }

    /**
     * 测试待处理结果创建
     */
    public function testPendingResultCreation(): void
    {
        $pendingInfo = ['queue_id' => 'queue-123', 'position' => 5];

        $result = TriggerResult::pending($pendingInfo);

        $this->assertInstanceOf(TriggerResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isFailed());
        $this->assertFalse($result->isSkipped());
        $this->assertTrue($result->isPending());
        $this->assertEquals(TriggerResult::STATUS_PENDING, $result->getStatus());
        $this->assertEquals($pendingInfo, $result->getData());
    }

    /**
     * 测试从异常创建结果
     */
    public function testFromExceptionCreation(): void
    {
        $exception = new \Exception('测试异常消息', 500);

        $result = TriggerResult::fromException($exception);

        $this->assertTrue($result->isFailed());
        $this->assertEquals(TriggerResult::STATUS_FAILED, $result->getStatus());
        $this->assertStringContainsString('测试异常消息', $result->getError());
        $this->assertEquals(500, $result->getErrorCode());
        $this->assertEquals(\Exception::class, $result->getMeta('exception_class'));
    }

    /**
     * 测试设置触发器类型
     */
    public function testSetTriggerType(): void
    {
        $result = TriggerResult::success([]);

        $result->setTriggerType(TriggerInterface::TYPE_MANUAL);

        $this->assertEquals(TriggerInterface::TYPE_MANUAL, $result->getTriggerType());
    }

    /**
     * 测试设置工作流ID
     */
    public function testSetWorkflowId(): void
    {
        $result = TriggerResult::success([]);

        $result->setWorkflowId('workflow-123');

        $this->assertEquals('workflow-123', $result->getWorkflowId());
    }

    /**
     * 测试设置元数据
     */
    public function testSetMeta(): void
    {
        $result = TriggerResult::success([]);

        $result->setMeta('key1', 'value1');
        $result->setMeta('key2', ['nested' => 'value']);

        $this->assertEquals('value1', $result->getMeta('key1'));
        $this->assertEquals(['nested' => 'value'], $result->getMeta('key2'));
        $this->assertNull($result->getMeta('nonexistent'));
        $this->assertEquals('default', $result->getMeta('nonexistent', 'default'));
    }

    /**
     * 测试链式调用
     */
    public function testFluentInterface(): void
    {
        $result = TriggerResult::success(['test' => 'data'])
            ->setTriggerType(TriggerInterface::TYPE_WEBHOOK)
            ->setWorkflowId('workflow-456')
            ->setMeta('custom', 'meta-value');

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_WEBHOOK, $result->getTriggerType());
        $this->assertEquals('workflow-456', $result->getWorkflowId());
        $this->assertEquals('meta-value', $result->getMeta('custom'));
    }

    /**
     * 测试转数组
     */
    public function testToArray(): void
    {
        $data = ['key' => 'value'];
        $result = TriggerResult::success($data)
            ->setTriggerType(TriggerInterface::TYPE_MANUAL)
            ->setWorkflowId('workflow-789')
            ->setMeta('meta_key', 'meta_value');

        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertEquals(TriggerResult::STATUS_SUCCESS, $array['status']);
        $this->assertTrue($array['success']);
        $this->assertEquals($data, $array['data']);
        $this->assertEquals(TriggerInterface::TYPE_MANUAL, $array['trigger_type']);
        $this->assertEquals('workflow-789', $array['workflow_id']);
        $this->assertArrayHasKey('metadata', $array);
        $this->assertEquals('meta_value', $array['metadata']['meta_key']);
        $this->assertArrayHasKey('start_time', $array);
        $this->assertArrayHasKey('end_time', $array);
    }

    /**
     * 测试失败结果的数组转换包含错误信息
     */
    public function testFailedResultToArrayContainsError(): void
    {
        $errorMessage = '配置验证失败';
        $result = TriggerResult::failed($errorMessage);

        $array = $result->toArray();

        $this->assertFalse($array['success']);
        $this->assertEquals($errorMessage, $array['error']);
    }

    /**
     * 测试从异常创建的结果包含异常信息
     */
    public function testFromExceptionResultToArrayContainsExceptionInfo(): void
    {
        $exception = new \InvalidArgumentException('无效参数', 400);
        $result = TriggerResult::fromException($exception);

        $array = $result->toArray();

        $this->assertFalse($array['success']);
        $this->assertStringContainsString('无效参数', $array['error']);
        $this->assertEquals(\InvalidArgumentException::class, $array['metadata']['exception_class']);
    }

    /**
     * 测试开始时间
     */
    public function testStartTime(): void
    {
        $before = microtime(true);
        $result = TriggerResult::success([]);
        $after = microtime(true);

        $startTime = $result->getStartTime();

        $this->assertGreaterThanOrEqual($before, $startTime);
        $this->assertLessThanOrEqual($after, $startTime);
    }

    /**
     * 测试获取数据中的特定键
     */
    public function testGetDataValue(): void
    {
        $data = [
            'user_id' => 123,
            'nested' => [
                'key' => 'nested_value',
            ],
        ];
        $result = TriggerResult::success($data);

        $this->assertEquals(123, $result->getData()['user_id']);
        $this->assertEquals('nested_value', $result->getData()['nested']['key']);
    }

    /**
     * 测试空数据的成功结果
     */
    public function testSuccessWithEmptyData(): void
    {
        $result = TriggerResult::success([]);

        $this->assertTrue($result->isSuccess());
        $this->assertEmpty($result->getData());
    }

    /**
     * 测试状态常量值
     */
    public function testStatusConstants(): void
    {
        $this->assertEquals('SUCCESS', TriggerResult::STATUS_SUCCESS);
        $this->assertEquals('FAILED', TriggerResult::STATUS_FAILED);
        $this->assertEquals('SKIPPED', TriggerResult::STATUS_SKIPPED);
        $this->assertEquals('PENDING', TriggerResult::STATUS_PENDING);
    }

    /**
     * 测试 get 方法获取数据
     */
    public function testGetMethod(): void
    {
        $result = TriggerResult::success(['key' => 'value', 'number' => 42]);

        $this->assertEquals('value', $result->get('key'));
        $this->assertEquals(42, $result->get('number'));
        $this->assertNull($result->get('nonexistent'));
        $this->assertEquals('default', $result->get('nonexistent', 'default'));
    }

    /**
     * 测试执行时长
     */
    public function testDuration(): void
    {
        $result = TriggerResult::success([]);

        $duration = $result->getDuration();

        $this->assertIsFloat($duration);
        $this->assertGreaterThanOrEqual(0, $duration);
    }

    /**
     * 测试带实例ID的成功结果
     */
    public function testSuccessWithInstanceId(): void
    {
        $instanceId = 'instance-abc-123';
        $result = TriggerResult::success(['test' => 'data'], $instanceId);

        $this->assertEquals($instanceId, $result->getInstanceId());
    }

    /**
     * 测试带消息的待处理结果
     */
    public function testPendingWithMessage(): void
    {
        $message = '正在排队中';
        $result = TriggerResult::pending(['position' => 5], $message);

        $this->assertEquals($message, $result->getMeta('message'));
    }

    /**
     * 测试失败结果带错误码
     */
    public function testFailedWithErrorCode(): void
    {
        $result = TriggerResult::failed('错误消息', 1001);

        $this->assertEquals('错误消息', $result->getError());
        $this->assertEquals(1001, $result->getErrorCode());
    }

    /**
     * 测试 fromArray 方法
     */
    public function testFromArray(): void
    {
        $data = [
            'status' => TriggerResult::STATUS_SUCCESS,
            'success' => true,
            'data' => ['key' => 'value'],
            'error' => null,
            'trigger_type' => TriggerInterface::TYPE_MANUAL,
            'workflow_id' => 'workflow-test',
            'metadata' => ['custom' => 'meta'],
        ];

        $result = TriggerResult::fromArray($data);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(['key' => 'value'], $result->getData());
        $this->assertEquals(TriggerInterface::TYPE_MANUAL, $result->getTriggerType());
        $this->assertEquals('workflow-test', $result->getWorkflowId());
        $this->assertEquals('meta', $result->getMeta('custom'));
    }

    /**
     * 测试 set 方法
     */
    public function testSetMethod(): void
    {
        $result = TriggerResult::success([]);

        $result->set('key1', 'value1');
        $result->set('key2', 42);

        $this->assertEquals('value1', $result->get('key1'));
        $this->assertEquals(42, $result->get('key2'));
    }

    /**
     * 测试 mergeData 方法
     */
    public function testMergeData(): void
    {
        $result = TriggerResult::success(['original' => 'data']);

        $result->mergeData(['additional' => 'value', 'number' => 100]);

        $this->assertEquals('data', $result->get('original'));
        $this->assertEquals('value', $result->get('additional'));
        $this->assertEquals(100, $result->get('number'));
    }
}
