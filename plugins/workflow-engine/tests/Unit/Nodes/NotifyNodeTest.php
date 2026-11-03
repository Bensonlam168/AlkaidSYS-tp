<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use PHPUnit\Framework\TestCase;
use Alkaid\Workflow\Engine\Nodes\Types\NotifyNode;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * NotifyNode 单元测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Nodes
 */
class NotifyNodeTest extends TestCase
{
    protected NotifyNode $node;
    protected ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->node = new NotifyNode('notify_001', [
            'name' => '通知节点',
            'channel' => NotifyNode::CHANNEL_EMAIL,
            'recipients' => ['test@example.com'],
            'subject' => '测试通知',
            'template' => '您有一条新消息: {{message}}',
            'variables' => [
                'message' => '测试消息内容',
            ],
        ]);

        $this->context = new ExecutionContext();
        $this->context->setInstanceId('inst_notify_001');
        $this->context->setTenantId(100);
    }

    /**
     * 测试节点创建
     */
    public function testNodeCreation(): void
    {
        $this->assertEquals('notify_001', $this->node->getId());
        $this->assertEquals(NodeInterface::TYPE_TASK, $this->node->getType());
        $this->assertEquals(NodeInterface::TASK_SEND, $this->node->getSubType());
    }

    /**
     * 测试邮件通知发送
     */
    public function testEmailNotification(): void
    {
        $result = $this->node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(NotifyNode::CHANNEL_EMAIL, $result->getData()['channel']);
        $this->assertEquals(1, $result->getData()['recipients_count']);
    }

    /**
     * 测试短信通知
     */
    public function testSmsNotification(): void
    {
        $node = new NotifyNode('sms_notify', [
            'channel' => NotifyNode::CHANNEL_SMS,
            'recipients' => ['13800138000'],
            'template' => '验证码: {{code}}',
            'variables' => ['code' => '123456'],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(NotifyNode::CHANNEL_SMS, $result->getData()['channel']);
    }

    /**
     * 测试 Webhook 通知
     */
    public function testWebhookNotification(): void
    {
        $node = new NotifyNode('webhook_notify', [
            'channel' => NotifyNode::CHANNEL_WEBHOOK,
            'recipients' => ['system'],
            'webhook_url' => 'https://api.example.com/webhook',
            'template' => '{"event": "workflow_complete"}',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
    }

    /**
     * 测试 Webhook 缺少 URL
     */
    public function testWebhookMissingUrl(): void
    {
        $node = new NotifyNode('webhook_no_url', [
            'channel' => NotifyNode::CHANNEL_WEBHOOK,
            'recipients' => ['system'],
            'template' => '{"event": "test"}',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }

    /**
     * 测试变量解析收件人
     */
    public function testVariableRecipients(): void
    {
        $this->context->setVariable('approvers', ['user1@example.com', 'user2@example.com']);

        $node = new NotifyNode('var_recipients', [
            'channel' => NotifyNode::CHANNEL_EMAIL,
            'recipients' => ['${approvers}'],
            'template' => '通知消息',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(2, $result->getData()['sent_count']);
    }

    /**
     * 测试模板变量替换
     */
    public function testTemplateVariableReplacement(): void
    {
        $this->context->setVariable('order_id', 'ORD-12345');

        $node = new NotifyNode('tpl_notify', [
            'channel' => NotifyNode::CHANNEL_EMAIL,
            'recipients' => ['test@example.com'],
            'template' => '订单 {{order_id}} 已创建',
            'variables' => [
                'order_id' => '${order_id}',
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
    }

    /**
     * 测试优先级设置
     */
    public function testPriorityLevels(): void
    {
        $highPriority = new NotifyNode('high_priority', [
            'channel' => NotifyNode::CHANNEL_EMAIL,
            'recipients' => ['test@example.com'],
            'template' => '紧急通知',
            'priority' => NotifyNode::PRIORITY_HIGH,
        ]);

        $this->assertEquals(NotifyNode::PRIORITY_HIGH, $highPriority->getConfig()['priority']);
    }

    /**
     * 测试无效邮箱地址部分发送
     */
    public function testPartialSendWithInvalidEmail(): void
    {
        $node = new NotifyNode('partial_send', [
            'channel' => NotifyNode::CHANNEL_EMAIL,
            'recipients' => ['valid@example.com', 'invalid-email'],
            'template' => '测试消息',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(NotifyNode::STATUS_PARTIAL, $result->getData()['status']);
        $this->assertEquals(1, $result->getData()['sent_count']);
        $this->assertEquals(1, $result->getData()['failed_count']);
    }

    /**
     * 测试钉钉通知
     */
    public function testDingtalkNotification(): void
    {
        $node = new NotifyNode('dingtalk_notify', [
            'channel' => NotifyNode::CHANNEL_DINGTALK,
            'recipients' => ['user123'],
            'template' => '钉钉消息',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(NotifyNode::CHANNEL_DINGTALK, $result->getData()['channel']);
    }

    /**
     * 测试飞书通知
     */
    public function testFeishuNotification(): void
    {
        $node = new NotifyNode('feishu_notify', [
            'channel' => NotifyNode::CHANNEL_FEISHU,
            'recipients' => ['user456'],
            'template' => '飞书消息',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
    }

    /**
     * 测试站内通知
     */
    public function testInternalNotification(): void
    {
        $node = new NotifyNode('internal_notify', [
            'channel' => NotifyNode::CHANNEL_INTERNAL,
            'recipients' => ['user_001', 'user_002'],
            'template' => '站内消息',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(2, $result->getData()['sent_count']);
    }

    /**
     * 测试配置验证 - 有效配置
     */
    public function testValidConfigValidation(): void
    {
        $validConfig = [
            'channel' => NotifyNode::CHANNEL_EMAIL,
            'recipients' => ['test@example.com'],
        ];
        $this->assertTrue($this->node->validateConfig($validConfig));
    }

    /**
     * 测试配置验证 - 缺少渠道
     */
    public function testInvalidConfigMissingChannel(): void
    {
        $invalidConfig = [
            'recipients' => ['test@example.com'],
        ];
        $this->assertFalse($this->node->validateConfig($invalidConfig));
    }

    /**
     * 测试配置验证 - 缺少收件人
     */
    public function testInvalidConfigMissingRecipients(): void
    {
        $invalidConfig = [
            'channel' => NotifyNode::CHANNEL_EMAIL,
        ];
        $this->assertFalse($this->node->validateConfig($invalidConfig));
    }

    /**
     * 测试配置验证 - 无效渠道
     */
    public function testInvalidConfigInvalidChannel(): void
    {
        $invalidConfig = [
            'channel' => 'invalid_channel',
            'recipients' => ['test@example.com'],
        ];
        $this->assertFalse($this->node->validateConfig($invalidConfig));
    }

    /**
     * 测试配置验证 - Webhook 缺少 URL
     */
    public function testInvalidConfigWebhookMissingUrl(): void
    {
        $invalidConfig = [
            'channel' => NotifyNode::CHANNEL_WEBHOOK,
            'recipients' => ['system'],
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
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('channel', $schema['properties']);
        $this->assertArrayHasKey('recipients', $schema['properties']);
        $this->assertArrayHasKey('template', $schema['properties']);
        $this->assertArrayHasKey('priority', $schema['properties']);
    }

    /**
     * 测试结果存储在上下文中
     */
    public function testResultStoredInContext(): void
    {
        $this->node->execute($this->context);

        $result = $this->context->getVariable('notify_result_notify_001');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('notification_id', $result);
        $this->assertArrayHasKey('status', $result);
    }

    /**
     * 测试不支持的渠道
     */
    public function testUnsupportedChannel(): void
    {
        $node = new NotifyNode('unsupported', [
            'channel' => 'unknown_channel',
            'recipients' => ['test'],
            'template' => 'test',
        ]);

        // Force set config to bypass validation
        $reflection = new \ReflectionClass($node);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);
        $config = $property->getValue($node);
        $config['channel'] = 'unknown_channel';
        $property->setValue($node, $config);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }
}
