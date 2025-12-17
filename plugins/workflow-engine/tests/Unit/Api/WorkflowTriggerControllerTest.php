<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Alkaid\Workflow\Engine\Triggers\TriggerManager;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;
use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;

/**
 * WorkflowTriggerController 单元测试
 *
 * 测试工作流触发器 API 的核心功能
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Api
 */
class WorkflowTriggerControllerTest extends TestCase
{
    private TriggerManager $triggerManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->triggerManager = new TriggerManager(true);
    }

    /**
     * 测试触发器管理器初始化
     */
    public function testTriggerManagerInitialization(): void
    {
        $this->assertNotNull($this->triggerManager);

        // 检查内置触发器类型是否注册
        $types = $this->triggerManager->getTriggerTypes();
        $this->assertArrayHasKey('manual', $types);
        $this->assertArrayHasKey('webhook', $types);
        $this->assertArrayHasKey('schedule', $types);
        $this->assertArrayHasKey('data_event', $types);
    }

    /**
     * 测试获取触发器类型
     */
    public function testGetTriggerType(): void
    {
        $manualTrigger = $this->triggerManager->getTriggerType('manual');
        $this->assertNotNull($manualTrigger);
        $this->assertEquals('manual', $manualTrigger->getType());

        $webhookTrigger = $this->triggerManager->getTriggerType('webhook');
        $this->assertNotNull($webhookTrigger);
        $this->assertEquals('webhook', $webhookTrigger->getType());
    }

    /**
     * 测试获取不存在的触发器类型
     */
    public function testGetNonexistentTriggerType(): void
    {
        $trigger = $this->triggerManager->getTriggerType('nonexistent');
        $this->assertNull($trigger);
    }

    /**
     * 测试触发器上下文创建 - 手动触发
     */
    public function testTriggerContextCreationManual(): void
    {
        $context = TriggerContext::manual(
            'wf_001',
            100,
            1001,
            ['amount' => 1000, 'applicant' => 'user_001']
        );

        $this->assertEquals(TriggerInterface::TYPE_MANUAL, $context->getTriggerType());
        $this->assertEquals('wf_001', $context->getWorkflowId());
        $this->assertEquals(100, $context->getTenantId());
        $this->assertEquals(1001, $context->getUserId());
        $this->assertEquals(1000, $context->getData()['amount']);
    }

    /**
     * 测试触发器上下文创建 - Webhook 触发
     */
    public function testTriggerContextCreationWebhook(): void
    {
        $context = TriggerContext::webhook(
            'wf_002',
            ['payload' => 'test'],
            ['Authorization' => 'Bearer token'],
            ['param' => 'value']
        );

        $this->assertEquals(TriggerInterface::TYPE_WEBHOOK, $context->getTriggerType());
        $this->assertEquals('wf_002', $context->getWorkflowId());
        $this->assertArrayHasKey('payload', $context->getData());
    }

    /**
     * 测试触发器结果创建
     */
    public function testTriggerResultCreation(): void
    {
        // 成功结果
        $successResult = TriggerResult::success(
            ['key' => 'value'],
            'inst_001'
        );

        $this->assertTrue($successResult->isSuccess());
        $this->assertEquals(TriggerResult::STATUS_SUCCESS, $successResult->getStatus());
        $this->assertEquals('inst_001', $successResult->getInstanceId());
        $this->assertEquals(['key' => 'value'], $successResult->getData());

        // 失败结果
        $failureResult = TriggerResult::failed(
            'Validation failed',
            400
        );

        $this->assertFalse($failureResult->isSuccess());
        $this->assertEquals(TriggerResult::STATUS_FAILED, $failureResult->getStatus());
        $this->assertEquals('Validation failed', $failureResult->getError());
        $this->assertEquals(400, $failureResult->getErrorCode());
    }

    /**
     * 测试手动触发器配置验证
     */
    public function testManualTriggerConfigValidation(): void
    {
        $manualTrigger = $this->triggerManager->getTriggerType('manual');

        // 有效配置（空配置也是有效的）
        $emptyConfig = [];
        $this->assertTrue($manualTrigger->validateConfig($emptyConfig));

        // 有配置选项
        $validConfig = [
            'name' => 'Test Trigger',
            'confirm_required' => true,
            'confirm_message' => 'Are you sure?',
        ];
        $this->assertTrue($manualTrigger->validateConfig($validConfig));
    }

    /**
     * 测试 Webhook 触发器配置验证
     */
    public function testWebhookTriggerConfigValidation(): void
    {
        $webhookTrigger = $this->triggerManager->getTriggerType('webhook');

        // 有效配置
        $validConfig = [
            'name' => 'Test Webhook',
            'secret' => 'my_webhook_secret',
            'allowed_ips' => ['192.168.1.1', '10.0.0.1'],
            'allowed_methods' => ['POST', 'PUT'],
        ];
        $this->assertTrue($webhookTrigger->validateConfig($validConfig));
    }

    /**
     * 测试定时触发器配置验证
     */
    public function testScheduleTriggerConfigValidation(): void
    {
        $scheduleTrigger = $this->triggerManager->getTriggerType('schedule');

        // 有效配置 - 使用简单的 cron 表达式（每天上午9点）
        $validConfig = [
            'name' => 'Daily Trigger',
            'cron_expression' => '0 9 * * *',
            'timezone' => 'Asia/Shanghai',
        ];
        $this->assertTrue($scheduleTrigger->validateConfig($validConfig));

        // 无效配置（缺少 cron_expression）
        $invalidConfig = [
            'timezone' => 'UTC',
        ];
        $this->assertFalse($scheduleTrigger->validateConfig($invalidConfig));
        $errors = $scheduleTrigger->getValidationErrors();
        $this->assertNotEmpty($errors);
    }

    /**
     * 测试数据事件触发器配置验证
     */
    public function testDataEventTriggerConfigValidation(): void
    {
        $dataEventTrigger = $this->triggerManager->getTriggerType('data_event');

        // 有效配置（使用 event_types 数组）
        $validConfig = [
            'collection' => 'orders',
            'event_types' => ['created', 'updated'],
            'conditions' => [
                ['field' => 'status', 'operator' => '=', 'value' => 'pending'],
            ],
        ];
        $this->assertTrue($dataEventTrigger->validateConfig($validConfig));

        // 无效配置（缺少必填字段）
        $invalidConfig = [
            'collection' => 'orders',
            // 缺少 event_types
        ];
        $this->assertFalse($dataEventTrigger->validateConfig($invalidConfig));
    }

    /**
     * 测试触发条件检查
     */
    public function testTriggerShouldTrigger(): void
    {
        $manualTrigger = $this->triggerManager->getTriggerType('manual');

        $context = TriggerContext::manual(
            'wf_001',
            100,
            1001,
            ['action' => 'submit']
        );

        // 手动触发器应该总是可以触发
        $shouldTrigger = $manualTrigger->shouldTrigger($context);
        $this->assertTrue($shouldTrigger);
    }

    /**
     * 测试注册工作流触发器
     */
    public function testRegisterTrigger(): void
    {
        $config = [
            'name' => 'Manual Start',
            'confirm_required' => false,
        ];

        $result = $this->triggerManager->registerTrigger('wf_001', 'manual', $config);
        $this->assertTrue($result);

        // 检查触发器是否可用
        $manualTrigger = $this->triggerManager->getTriggerType('manual');
        $this->assertTrue($manualTrigger->isRegistered('wf_001'));
    }

    /**
     * 测试注销工作流触发器
     */
    public function testUnregisterTrigger(): void
    {
        // 先注册
        $this->triggerManager->registerTrigger('wf_002', 'webhook', ['name' => 'Test Webhook']);

        // 确认已注册
        $webhookTrigger = $this->triggerManager->getTriggerType('webhook');
        $this->assertTrue($webhookTrigger->isRegistered('wf_002'));

        // 注销
        $this->triggerManager->unregisterTrigger('wf_002', 'webhook');

        // 确认已注销
        $this->assertFalse($webhookTrigger->isRegistered('wf_002'));
    }

    /**
     * 测试事件监听
     */
    public function testEventListener(): void
    {
        $eventFired = false;
        $receivedData = null;

        $this->triggerManager->on('trigger.registered', function ($data) use (&$eventFired, &$receivedData) {
            $eventFired = true;
            $receivedData = $data;
        });

        // 注册触发器会触发事件
        $this->triggerManager->registerTrigger('wf_event_test', 'manual', []);

        $this->assertTrue($eventFired);
        $this->assertEquals('wf_event_test', $receivedData['workflow_id']);
    }

    /**
     * 测试设置默认租户ID
     */
    public function testSetDefaultTenantId(): void
    {
        $this->triggerManager->setDefaultTenantId(100);
        $this->assertEquals(100, $this->triggerManager->getDefaultTenantId());
    }

    /**
     * 测试检查触发器类型是否存在
     */
    public function testHasTriggerType(): void
    {
        $this->assertTrue($this->triggerManager->hasTriggerType('manual'));
        $this->assertTrue($this->triggerManager->hasTriggerType('webhook'));
        $this->assertTrue($this->triggerManager->hasTriggerType('schedule'));
        $this->assertTrue($this->triggerManager->hasTriggerType('data_event'));
        $this->assertFalse($this->triggerManager->hasTriggerType('nonexistent'));
    }

    /**
     * 测试获取触发器配置 Schema
     */
    public function testGetTriggerConfigSchema(): void
    {
        $manualTrigger = $this->triggerManager->getTriggerType('manual');
        $schema = $manualTrigger->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('type', $schema);
        $this->assertArrayHasKey('properties', $schema);
    }

    /**
     * 测试触发器名称和描述
     */
    public function testTriggerNameAndDescription(): void
    {
        $manualTrigger = $this->triggerManager->getTriggerType('manual');

        $this->assertNotEmpty($manualTrigger->getName());
        $this->assertNotEmpty($manualTrigger->getDescription());
    }
}
