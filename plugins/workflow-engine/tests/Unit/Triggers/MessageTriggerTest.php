<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\Types\MessageTrigger;
use PHPUnit\Framework\TestCase;

/**
 * MessageTrigger 单元测试
 */
class MessageTriggerTest extends TestCase
{
    private MessageTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trigger = new MessageTrigger();
    }

    /**
     * 测试触发器类型
     */
    public function testGetType(): void
    {
        $this->assertEquals(TriggerInterface::TYPE_MESSAGE, $this->trigger->getType());
    }

    /**
     * 测试触发器名称
     */
    public function testGetName(): void
    {
        $name = $this->trigger->getName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    /**
     * 测试触发器描述
     */
    public function testGetDescription(): void
    {
        $description = $this->trigger->getDescription();
        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    /**
     * 测试配置 Schema
     */
    public function testGetConfigSchema(): void
    {
        $schema = $this->trigger->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('type', $schema);
        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('message_name', $schema['properties']);
        $this->assertArrayHasKey('message_type', $schema['properties']);
    }

    /**
     * 测试有效配置验证
     */
    public function testValidateValidConfig(): void
    {
        $config = [
            'message_name' => 'order.created',
            'message_type' => MessageTrigger::MSG_TYPE_EVENT,
        ];

        $result = $this->trigger->validateConfig($config);
        $this->assertTrue($result);
    }

    /**
     * 测试缺少必填字段的配置验证
     */
    public function testValidateConfigMissingRequired(): void
    {
        $config = [
            'message_type' => MessageTrigger::MSG_TYPE_EVENT,
        ];

        $result = $this->trigger->validateConfig($config);
        $this->assertFalse($result);
    }

    /**
     * 测试注册触发器
     */
    public function testRegister(): void
    {
        $workflowId = 'test-workflow-001';
        $config = ['message_name' => 'test.message'];

        $this->trigger->register($workflowId, $config);

        $this->assertTrue($this->trigger->isRegistered($workflowId));
    }

    /**
     * 测试消息触发
     */
    public function testTriggerWithMessage(): void
    {
        $workflowId = 'test-workflow-002';
        $config = [
            'message_name' => 'order.created',
            'message_type' => MessageTrigger::MSG_TYPE_EVENT,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $context->setTenantId(100);
        $context->setData([
            'message_name' => 'order.created',
            'message_type' => 'event',
            'content' => ['order_id' => 'ORD-001'],
        ]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_MESSAGE, $result->getTriggerType());
    }

    /**
     * 测试消息名称不匹配不触发
     */
    public function testShouldNotTriggerWithDifferentMessageName(): void
    {
        $workflowId = 'test-workflow-003';
        $config = [
            'message_name' => 'expected.message',
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $context->setData([
            'message_name' => 'different.message',
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试消息类型过滤
     */
    public function testMessageTypeFiltering(): void
    {
        $workflowId = 'test-workflow-004';
        $config = [
            'message_name' => 'test.message',
            'message_type' => MessageTrigger::MSG_TYPE_COMMAND,
        ];

        $this->trigger->register($workflowId, $config);

        // EVENT 类型不应该触发
        $contextEvent = new TriggerContext();
        $contextEvent->setWorkflowId($workflowId);
        $contextEvent->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $contextEvent->setData([
            'message_name' => 'test.message',
            'message_type' => 'event',
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextEvent));

        // COMMAND 类型应该触发
        $contextCommand = new TriggerContext();
        $contextCommand->setWorkflowId($workflowId);
        $contextCommand->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $contextCommand->setData([
            'message_name' => 'test.message',
            'message_type' => 'command',
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextCommand));
    }

    /**
     * 测试 ANY 消息类型
     */
    public function testAnyMessageType(): void
    {
        $workflowId = 'test-workflow-005';
        $config = [
            'message_name' => 'test.message',
            'message_type' => MessageTrigger::MSG_TYPE_ANY,
        ];

        $this->trigger->register($workflowId, $config);

        $types = ['event', 'command', 'request', 'response', 'notification'];

        foreach ($types as $type) {
            $context = new TriggerContext();
            $context->setWorkflowId($workflowId);
            $context->setTriggerType(TriggerInterface::TYPE_MESSAGE);
            $context->setData([
                'message_name' => 'test.message',
                'message_type' => $type,
            ]);

            $this->assertTrue(
                $this->trigger->shouldTrigger($context),
                "Should trigger for message type: {$type}"
            );
        }
    }

    /**
     * 测试来源过滤 - 允许的来源
     */
    public function testSourceFilterAllowed(): void
    {
        $workflowId = 'test-workflow-006';
        $config = [
            'message_name' => 'test.message',
            'source_filter' => [
                'allowed_sources' => ['service-a', 'service-b'],
            ],
        ];

        $this->trigger->register($workflowId, $config);

        // 允许的来源
        $contextAllowed = new TriggerContext();
        $contextAllowed->setWorkflowId($workflowId);
        $contextAllowed->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $contextAllowed->setData([
            'message_name' => 'test.message',
            'source' => 'service-a',
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextAllowed));

        // 不允许的来源
        $contextNotAllowed = new TriggerContext();
        $contextNotAllowed->setWorkflowId($workflowId);
        $contextNotAllowed->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $contextNotAllowed->setData([
            'message_name' => 'test.message',
            'source' => 'service-c',
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextNotAllowed));
    }

    /**
     * 测试来源过滤 - 阻止的来源
     */
    public function testSourceFilterBlocked(): void
    {
        $workflowId = 'test-workflow-007';
        $config = [
            'message_name' => 'test.message',
            'source_filter' => [
                'blocked_sources' => ['blocked-service'],
            ],
        ];

        $this->trigger->register($workflowId, $config);

        // 被阻止的来源
        $contextBlocked = new TriggerContext();
        $contextBlocked->setWorkflowId($workflowId);
        $contextBlocked->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $contextBlocked->setData([
            'message_name' => 'test.message',
            'source' => 'blocked-service',
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextBlocked));

        // 其他来源
        $contextOther = new TriggerContext();
        $contextOther->setWorkflowId($workflowId);
        $contextOther->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $contextOther->setData([
            'message_name' => 'test.message',
            'source' => 'other-service',
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextOther));
    }

    /**
     * 测试内容匹配
     */
    public function testContentMatch(): void
    {
        $workflowId = 'test-workflow-008';
        $config = [
            'message_name' => 'test.message',
            'content_match' => [
                ['field' => 'status', 'pattern' => 'active', 'match_type' => 'exact'],
            ],
        ];

        $this->trigger->register($workflowId, $config);

        // 匹配成功
        $contextMatch = new TriggerContext();
        $contextMatch->setWorkflowId($workflowId);
        $contextMatch->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $contextMatch->setData([
            'message_name' => 'test.message',
            'content' => ['status' => 'active'],
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextMatch));

        // 匹配失败
        $contextNoMatch = new TriggerContext();
        $contextNoMatch->setWorkflowId($workflowId);
        $contextNoMatch->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $contextNoMatch->setData([
            'message_name' => 'test.message',
            'content' => ['status' => 'inactive'],
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextNoMatch));
    }

    /**
     * 测试内容匹配类型
     */
    public function testContentMatchTypes(): void
    {
        $workflowId = 'test-workflow-009';

        $testCases = [
            ['match_type' => 'exact', 'pattern' => 'hello', 'value' => 'hello', 'expected' => true],
            ['match_type' => 'exact', 'pattern' => 'hello', 'value' => 'hello world', 'expected' => false],
            ['match_type' => 'contains', 'pattern' => 'world', 'value' => 'hello world', 'expected' => true],
            ['match_type' => 'prefix', 'pattern' => 'hello', 'value' => 'hello world', 'expected' => true],
            ['match_type' => 'suffix', 'pattern' => 'world', 'value' => 'hello world', 'expected' => true],
            ['match_type' => 'regex', 'pattern' => '/^[a-z]+$/', 'value' => 'hello', 'expected' => true],
        ];

        foreach ($testCases as $index => $case) {
            $config = [
                'message_name' => 'test.message',
                'content_match' => [
                    ['field' => 'text', 'pattern' => $case['pattern'], 'match_type' => $case['match_type']],
                ],
            ];

            $this->trigger->clearRegistrations();
            $this->trigger->register($workflowId, $config);

            $context = new TriggerContext();
            $context->setWorkflowId($workflowId);
            $context->setTriggerType(TriggerInterface::TYPE_MESSAGE);
            $context->setData([
                'message_name' => 'test.message',
                'content' => ['text' => $case['value']],
            ]);

            $this->assertEquals(
                $case['expected'],
                $this->trigger->shouldTrigger($context),
                "Failed for match_type: {$case['match_type']} (case {$index})"
            );
        }
    }

    /**
     * 测试优先级阈值
     */
    public function testPriorityThreshold(): void
    {
        $workflowId = 'test-workflow-010';
        $config = [
            'message_name' => 'test.message',
            'priority_threshold' => 50,
        ];

        $this->trigger->register($workflowId, $config);

        // 低优先级不应该触发
        $contextLow = new TriggerContext();
        $contextLow->setWorkflowId($workflowId);
        $contextLow->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $contextLow->setData([
            'message_name' => 'test.message',
            'priority' => 30,
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextLow));

        // 高优先级应该触发
        $contextHigh = new TriggerContext();
        $contextHigh->setWorkflowId($workflowId);
        $contextHigh->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $contextHigh->setData([
            'message_name' => 'test.message',
            'priority' => 60,
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextHigh));
    }

    /**
     * 测试去重键生成
     */
    public function testGenerateDeduplicationKey(): void
    {
        $config = [
            'message_name' => 'test.message',
            'deduplication_enabled' => true,
            'deduplication_key' => 'message_id',
        ];

        $data = ['message_id' => 'MSG-001'];

        $key = $this->trigger->generateDeduplicationKey($data, $config);

        $this->assertNotNull($key);
        $this->assertStringStartsWith('msg_dedup:', $key);
    }

    /**
     * 测试去重禁用时不生成键
     */
    public function testNoDeduplicationKeyWhenDisabled(): void
    {
        $config = [
            'message_name' => 'test.message',
            'deduplication_enabled' => false,
        ];

        $data = ['message_id' => 'MSG-001'];

        $key = $this->trigger->generateDeduplicationKey($data, $config);

        $this->assertNull($key);
    }

    /**
     * 测试触发结果包含关联值
     */
    public function testTriggerResultContainsCorrelationValue(): void
    {
        $workflowId = 'test-workflow-011';
        $config = [
            'message_name' => 'test.message',
            'correlation_key' => 'order_id',
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_MESSAGE);
        $context->setTenantId(100);
        $context->setData([
            'message_name' => 'test.message',
            'order_id' => 'ORD-001',
        ]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('ORD-001', $result->getMeta('correlation_value'));
    }

    /**
     * 测试消息类型常量
     */
    public function testMessageTypeConstants(): void
    {
        $this->assertEquals('command', MessageTrigger::MSG_TYPE_COMMAND);
        $this->assertEquals('event', MessageTrigger::MSG_TYPE_EVENT);
        $this->assertEquals('request', MessageTrigger::MSG_TYPE_REQUEST);
        $this->assertEquals('response', MessageTrigger::MSG_TYPE_RESPONSE);
        $this->assertEquals('notification', MessageTrigger::MSG_TYPE_NOTIFICATION);
        $this->assertEquals('any', MessageTrigger::MSG_TYPE_ANY);
    }

    /**
     * 测试消息来源常量
     */
    public function testMessageSourceConstants(): void
    {
        $this->assertEquals('internal', MessageTrigger::SOURCE_INTERNAL);
        $this->assertEquals('external', MessageTrigger::SOURCE_EXTERNAL);
        $this->assertEquals('system', MessageTrigger::SOURCE_SYSTEM);
        $this->assertEquals('user', MessageTrigger::SOURCE_USER);
        $this->assertEquals('workflow', MessageTrigger::SOURCE_WORKFLOW);
    }
}
