<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use PHPUnit\Framework\TestCase;

/**
 * TriggerContext 单元测试
 */
class TriggerContextTest extends TestCase
{
    /**
     * 测试手动触发上下文创建
     */
    public function testManualContextCreation(): void
    {
        $workflowId = 'test-workflow-001';
        $tenantId = 100;
        $userId = 1;
        $data = ['key' => 'value'];

        $context = TriggerContext::manual($workflowId, $tenantId, $userId, $data);

        $this->assertInstanceOf(TriggerContext::class, $context);
        $this->assertEquals(TriggerInterface::TYPE_MANUAL, $context->getTriggerType());
        $this->assertEquals('manual', $context->getSource());
        $this->assertEquals($workflowId, $context->getWorkflowId());
        $this->assertEquals($data, $context->getData());
        $this->assertEquals($userId, $context->getUserId());
        $this->assertEquals($tenantId, $context->getTenantId());
        $this->assertTrue($context->isManual());
        $this->assertFalse($context->isWebhook());
        $this->assertFalse($context->isSchedule());
        $this->assertFalse($context->isDataEvent());
    }

    /**
     * 测试 Webhook 触发上下文创建
     */
    public function testWebhookContextCreation(): void
    {
        $workflowId = 'test-workflow-002';
        $data = ['payload' => 'test'];
        $headers = ['X-Custom-Header' => 'value', 'Content-Type' => 'application/json'];
        $query = ['token' => 'abc123'];

        $context = TriggerContext::webhook($workflowId, $data, $headers, $query);

        $this->assertInstanceOf(TriggerContext::class, $context);
        $this->assertEquals(TriggerInterface::TYPE_WEBHOOK, $context->getTriggerType());
        $this->assertEquals('webhook', $context->getSource());
        $this->assertEquals($workflowId, $context->getWorkflowId());
        $this->assertEquals($data, $context->getData());
        $this->assertEquals($headers, $context->getHeaders());
        $this->assertEquals('value', $context->getHeader('X-Custom-Header'));
        $this->assertEquals($query, $context->getQuery());
        $this->assertTrue($context->isWebhook());
        $this->assertFalse($context->isManual());
    }

    /**
     * 测试定时触发上下文创建
     */
    public function testScheduleContextCreation(): void
    {
        $workflowId = 'test-workflow-003';
        $cronExpression = '0 9 * * *';
        $scheduledTime = (float) strtotime('2024-01-01 09:00:00');

        $context = TriggerContext::schedule($workflowId, $cronExpression, $scheduledTime);

        $this->assertInstanceOf(TriggerContext::class, $context);
        $this->assertEquals(TriggerInterface::TYPE_SCHEDULE, $context->getTriggerType());
        $this->assertEquals('schedule', $context->getSource());
        $this->assertEquals($workflowId, $context->getWorkflowId());
        $this->assertEquals($cronExpression, $context->getCronExpression());
        $this->assertEquals($scheduledTime, $context->getScheduledTime());
        $this->assertTrue($context->isSchedule());
        $this->assertFalse($context->isManual());
    }

    /**
     * 测试数据事件触发上下文创建
     */
    public function testDataEventContextCreation(): void
    {
        $workflowId = 'test-workflow-004';
        $eventName = 'created';
        $eventData = [
            'collection' => 'users',
            'id' => 123,
            'data' => ['name' => 'John'],
        ];
        $tenantId = 300;

        $context = TriggerContext::dataEvent($workflowId, $eventName, $eventData, $tenantId);

        $this->assertInstanceOf(TriggerContext::class, $context);
        $this->assertEquals(TriggerInterface::TYPE_DATA_EVENT, $context->getTriggerType());
        $this->assertEquals('data_event', $context->getSource());
        $this->assertEquals($workflowId, $context->getWorkflowId());
        $this->assertEquals($eventName, $context->getEventName());
        $this->assertEquals($eventData, $context->getEventData());
        $this->assertEquals($tenantId, $context->getTenantId());
        $this->assertTrue($context->isDataEvent());
        $this->assertFalse($context->isManual());
    }

    /**
     * 测试从数组创建上下文
     */
    public function testFromArrayCreation(): void
    {
        $array = [
            'trigger_type' => TriggerInterface::TYPE_MANUAL,
            'source' => 'api',
            'workflow_id' => 'test-workflow-005',
            'tenant_id' => 500,
            'user_id' => 50,
            'data' => ['test' => 'data'],
            'metadata' => ['custom' => 'meta'],
        ];

        $context = TriggerContext::fromArray($array);

        $this->assertEquals(TriggerInterface::TYPE_MANUAL, $context->getTriggerType());
        $this->assertEquals('api', $context->getSource());
        $this->assertEquals('test-workflow-005', $context->getWorkflowId());
        $this->assertEquals(500, $context->getTenantId());
        $this->assertEquals(50, $context->getUserId());
        $this->assertEquals(['test' => 'data'], $context->getData());
        $this->assertEquals('meta', $context->getMeta('custom'));
    }

    /**
     * 测试上下文转数组
     */
    public function testToArray(): void
    {
        $context = TriggerContext::manual('workflow-id', 100, 10, ['key' => 'value']);
        $context->setIpAddress('192.168.1.1');
        $context->setUserAgent('Test Agent');
        $context->setMeta('custom_key', 'custom_value');

        $array = $context->toArray();

        $this->assertIsArray($array);
        $this->assertEquals(TriggerInterface::TYPE_MANUAL, $array['trigger_type']);
        $this->assertEquals('workflow-id', $array['workflow_id']);
        $this->assertEquals(10, $array['user_id']);
        $this->assertEquals(100, $array['tenant_id']);
        $this->assertEquals(['key' => 'value'], $array['data']);
        $this->assertEquals('192.168.1.1', $array['ip_address']);
        $this->assertEquals('Test Agent', $array['user_agent']);
        $this->assertEquals('custom_value', $array['metadata']['custom_key']);
    }

    /**
     * 测试元数据操作
     */
    public function testMetadataOperations(): void
    {
        $context = TriggerContext::manual('workflow-id');

        // 设置元数据
        $context->setMeta('key1', 'value1');
        $context->setMeta('key2', ['nested' => 'value']);

        // 获取元数据
        $this->assertEquals('value1', $context->getMeta('key1'));
        $this->assertEquals(['nested' => 'value'], $context->getMeta('key2'));

        // 获取不存在的元数据（带默认值）
        $this->assertEquals('default', $context->getMeta('nonexistent', 'default'));
        $this->assertNull($context->getMeta('nonexistent'));

        // 获取全部元数据
        $metadata = $context->getMetadata();
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('key1', $metadata);
        $this->assertArrayHasKey('key2', $metadata);
    }

    /**
     * 测试 header 不区分大小写获取
     */
    public function testHeaderCaseInsensitiveAccess(): void
    {
        $headers = [
            'Content-Type' => 'application/json',
            'X-Custom-Header' => 'custom-value',
        ];

        $context = TriggerContext::webhook('workflow-id', [], $headers);

        // 测试不同大小写都能获取
        $this->assertEquals('application/json', $context->getHeader('Content-Type'));
        $this->assertEquals('application/json', $context->getHeader('content-type'));
        $this->assertEquals('application/json', $context->getHeader('CONTENT-TYPE'));
    }

    /**
     * 测试触发时间
     */
    public function testTriggeredAt(): void
    {
        $before = microtime(true);
        $context = TriggerContext::manual('workflow-id');
        $after = microtime(true);

        $triggeredAt = $context->getTriggeredAt();

        $this->assertGreaterThanOrEqual($before, $triggeredAt);
        $this->assertLessThanOrEqual($after, $triggeredAt);
    }

    /**
     * 测试 IP 地址和 User Agent 设置
     */
    public function testIpAddressAndUserAgent(): void
    {
        $context = TriggerContext::manual('workflow-id');

        $context->setIpAddress('10.0.0.1');
        $context->setUserAgent('Mozilla/5.0');

        $this->assertEquals('10.0.0.1', $context->getIpAddress());
        $this->assertEquals('Mozilla/5.0', $context->getUserAgent());
    }

    /**
     * 测试克隆和修改
     */
    public function testCloneAndModify(): void
    {
        $original = TriggerContext::manual('workflow-id', 100, 1, ['original' => 'data']);

        // 虽然没有显式的 clone 方法，但可以通过 fromArray 创建副本
        $array = $original->toArray();
        $array['workflow_id'] = 'new-workflow-id';
        $array['data'] = ['new' => 'data'];

        $modified = TriggerContext::fromArray($array);

        $this->assertEquals('workflow-id', $original->getWorkflowId());
        $this->assertEquals('new-workflow-id', $modified->getWorkflowId());
        $this->assertEquals(['original' => 'data'], $original->getData());
        $this->assertEquals(['new' => 'data'], $modified->getData());
    }
}
