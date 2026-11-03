<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerManager;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;
use Alkaid\Workflow\Engine\Triggers\Types\DataEventTrigger;
use Alkaid\Workflow\Engine\Triggers\Types\ManualTrigger;
use Alkaid\Workflow\Engine\Triggers\Types\ScheduleTrigger;
use Alkaid\Workflow\Engine\Triggers\Types\WebhookTrigger;
use PHPUnit\Framework\TestCase;

/**
 * TriggerManager 单元测试
 */
class TriggerManagerTest extends TestCase
{
    private TriggerManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new TriggerManager(true);
    }

    protected function tearDown(): void
    {
        $this->manager->clearAll();
        parent::tearDown();
    }

    /**
     * 测试构造函数自动注册内置触发器
     */
    public function testAutoRegisterBuiltinTriggerTypes(): void
    {
        $manager = new TriggerManager(true);

        $this->assertTrue($manager->hasTriggerType(TriggerInterface::TYPE_MANUAL));
        $this->assertTrue($manager->hasTriggerType(TriggerInterface::TYPE_WEBHOOK));
        $this->assertTrue($manager->hasTriggerType(TriggerInterface::TYPE_SCHEDULE));
        $this->assertTrue($manager->hasTriggerType(TriggerInterface::TYPE_DATA_EVENT));
    }

    /**
     * 测试不自动注册内置触发器
     */
    public function testNoAutoRegisterBuiltinTriggerTypes(): void
    {
        $manager = new TriggerManager(false);

        $this->assertFalse($manager->hasTriggerType(TriggerInterface::TYPE_MANUAL));
        $this->assertFalse($manager->hasTriggerType(TriggerInterface::TYPE_WEBHOOK));
        $this->assertFalse($manager->hasTriggerType(TriggerInterface::TYPE_SCHEDULE));
        $this->assertFalse($manager->hasTriggerType(TriggerInterface::TYPE_DATA_EVENT));
    }

    /**
     * 测试获取触发器类型实例
     */
    public function testGetTriggerType(): void
    {
        $manualTrigger = $this->manager->getTriggerType(TriggerInterface::TYPE_MANUAL);
        $this->assertInstanceOf(ManualTrigger::class, $manualTrigger);

        $webhookTrigger = $this->manager->getTriggerType(TriggerInterface::TYPE_WEBHOOK);
        $this->assertInstanceOf(WebhookTrigger::class, $webhookTrigger);

        $scheduleTrigger = $this->manager->getTriggerType(TriggerInterface::TYPE_SCHEDULE);
        $this->assertInstanceOf(ScheduleTrigger::class, $scheduleTrigger);

        $dataEventTrigger = $this->manager->getTriggerType(TriggerInterface::TYPE_DATA_EVENT);
        $this->assertInstanceOf(DataEventTrigger::class, $dataEventTrigger);

        $nonexistent = $this->manager->getTriggerType('nonexistent');
        $this->assertNull($nonexistent);
    }

    /**
     * 测试获取所有触发器类型
     */
    public function testGetTriggerTypes(): void
    {
        $types = $this->manager->getTriggerTypes();

        $this->assertIsArray($types);
        $this->assertCount(4, $types);
        $this->assertArrayHasKey(TriggerInterface::TYPE_MANUAL, $types);
        $this->assertArrayHasKey(TriggerInterface::TYPE_WEBHOOK, $types);
        $this->assertArrayHasKey(TriggerInterface::TYPE_SCHEDULE, $types);
        $this->assertArrayHasKey(TriggerInterface::TYPE_DATA_EVENT, $types);
    }

    /**
     * 测试注册自定义触发器类型
     */
    public function testRegisterCustomTriggerType(): void
    {
        $manager = new TriggerManager(false);
        $customTrigger = new ManualTrigger();

        $result = $manager->registerTriggerType($customTrigger);

        $this->assertSame($manager, $result); // 测试链式调用
        $this->assertTrue($manager->hasTriggerType(TriggerInterface::TYPE_MANUAL));
    }

    /**
     * 测试注销触发器类型
     */
    public function testUnregisterTriggerType(): void
    {
        $this->assertTrue($this->manager->hasTriggerType(TriggerInterface::TYPE_MANUAL));

        $this->manager->unregisterTriggerType(TriggerInterface::TYPE_MANUAL);

        $this->assertFalse($this->manager->hasTriggerType(TriggerInterface::TYPE_MANUAL));
    }

    /**
     * 测试为工作流注册触发器
     */
    public function testRegisterTrigger(): void
    {
        $workflowId = 'test-workflow-001';
        $config = ['name' => '测试手动触发器'];

        $result = $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_MANUAL, $config);

        $this->assertTrue($result);
        $this->assertTrue($this->manager->hasWorkflowTrigger($workflowId, TriggerInterface::TYPE_MANUAL));
        $this->assertEquals($config, $this->manager->getWorkflowTriggerConfig($workflowId, TriggerInterface::TYPE_MANUAL));
    }

    /**
     * 测试注册未知触发器类型抛出异常
     */
    public function testRegisterUnknownTriggerTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未知的触发器类型');

        $this->manager->registerTrigger('workflow-id', 'unknown_type', []);
    }

    /**
     * 测试取消注册触发器
     */
    public function testUnregisterTrigger(): void
    {
        $workflowId = 'test-workflow-002';
        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_MANUAL, []);

        $this->assertTrue($this->manager->hasWorkflowTrigger($workflowId, TriggerInterface::TYPE_MANUAL));

        $result = $this->manager->unregisterTrigger($workflowId, TriggerInterface::TYPE_MANUAL);

        $this->assertTrue($result);
        $this->assertFalse($this->manager->hasWorkflowTrigger($workflowId, TriggerInterface::TYPE_MANUAL));
    }

    /**
     * 测试取消注册工作流所有触发器
     */
    public function testUnregisterAllTriggersForWorkflow(): void
    {
        $workflowId = 'test-workflow-003';
        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_MANUAL, []);
        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_WEBHOOK, []);

        $this->manager->unregisterTrigger($workflowId);

        $this->assertFalse($this->manager->hasWorkflowTrigger($workflowId, TriggerInterface::TYPE_MANUAL));
        $this->assertFalse($this->manager->hasWorkflowTrigger($workflowId, TriggerInterface::TYPE_WEBHOOK));
    }

    /**
     * 测试手动触发
     */
    public function testTriggerManual(): void
    {
        $workflowId = 'test-workflow-004';
        $data = ['order_id' => 'ORD-001'];
        $userId = 123;
        $tenantId = 456;

        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_MANUAL, []);

        $result = $this->manager->triggerManual($workflowId, $tenantId, $userId, $data);

        $this->assertInstanceOf(TriggerResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_MANUAL, $result->getTriggerType());
        $this->assertEquals($workflowId, $result->getWorkflowId());
    }

    /**
     * 测试 Webhook 触发
     */
    public function testTriggerWebhook(): void
    {
        $workflowId = 'test-workflow-005';
        $data = ['payload' => 'test'];
        $headers = ['X-Custom' => 'value'];
        $query = ['token' => 'abc'];

        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_WEBHOOK, []);

        $result = $this->manager->triggerWebhook($workflowId, $data, $headers, $query);

        $this->assertInstanceOf(TriggerResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_WEBHOOK, $result->getTriggerType());
    }

    /**
     * 测试定时触发
     */
    public function testTriggerSchedule(): void
    {
        $workflowId = 'test-workflow-006';
        $cronExpression = '0 9 * * *';
        $scheduledTime = (float) time();

        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_SCHEDULE, [
            'cron_expression' => $cronExpression,
        ]);

        $result = $this->manager->triggerSchedule($workflowId, $cronExpression, $scheduledTime);

        $this->assertInstanceOf(TriggerResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_SCHEDULE, $result->getTriggerType());
    }

    /**
     * 测试数据事件触发
     */
    public function testTriggerDataEvent(): void
    {
        $workflowId = 'test-workflow-007';
        $eventType = 'created';
        $eventData = [
            'collection' => 'users',
            'id' => 123,
            'data' => ['name' => 'John'],
        ];
        $tenantId = 100;

        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_DATA_EVENT, [
            'collection' => 'users',
            'event_types' => ['created'],
        ]);

        $result = $this->manager->triggerDataEvent($workflowId, $eventType, $eventData, $tenantId);

        $this->assertInstanceOf(TriggerResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_DATA_EVENT, $result->getTriggerType());
    }

    /**
     * 测试使用上下文触发
     */
    public function testTriggerWithContext(): void
    {
        $workflowId = 'test-workflow-008';
        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_MANUAL, []);

        $context = TriggerContext::manual($workflowId, null, 1, ['test' => 'data']);

        $result = $this->manager->trigger($context);

        $this->assertTrue($result->isSuccess());
    }

    /**
     * 测试触发未知类型返回失败结果
     */
    public function testTriggerUnknownTypeReturnsFailed(): void
    {
        $manager = new TriggerManager(false);

        $context = TriggerContext::manual('workflow-id', null, 1);

        $result = $manager->trigger($context);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('未知的触发器类型', $result->getError());
    }

    /**
     * 测试设置默认租户ID
     */
    public function testSetDefaultTenantId(): void
    {
        $this->manager->setDefaultTenantId(999);

        $this->assertEquals(999, $this->manager->getDefaultTenantId());
    }

    /**
     * 测试事件监听器
     */
    public function testEventListeners(): void
    {
        $triggeredEvents = [];

        $this->manager->on('trigger.before', function ($context) use (&$triggeredEvents) {
            $triggeredEvents[] = 'trigger.before';
        });

        $this->manager->on('trigger.after', function ($data) use (&$triggeredEvents) {
            $triggeredEvents[] = 'trigger.after';
        });

        $workflowId = 'test-workflow-009';
        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_MANUAL, []);

        $this->manager->triggerManual($workflowId);

        $this->assertContains('trigger.before', $triggeredEvents);
        $this->assertContains('trigger.after', $triggeredEvents);
    }

    /**
     * 测试移除事件监听器
     */
    public function testRemoveEventListener(): void
    {
        $callCount = 0;
        $listener = function () use (&$callCount) {
            $callCount++;
        };

        $this->manager->on('trigger.before', $listener);

        $workflowId = 'test-workflow-010';
        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_MANUAL, []);

        $this->manager->triggerManual($workflowId);
        $this->assertEquals(1, $callCount);

        $this->manager->off('trigger.before', $listener);

        $this->manager->triggerManual($workflowId);
        $this->assertEquals(1, $callCount); // 不应该增加
    }

    /**
     * 测试获取工作流触发器配置
     */
    public function testGetWorkflowTriggers(): void
    {
        $workflowId = 'test-workflow-011';
        $manualConfig = ['name' => '手动触发器'];
        $webhookConfig = ['name' => 'Webhook触发器'];

        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_MANUAL, $manualConfig);
        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_WEBHOOK, $webhookConfig);

        $triggers = $this->manager->getWorkflowTriggers($workflowId);

        $this->assertCount(2, $triggers);
        $this->assertEquals($manualConfig, $triggers[TriggerInterface::TYPE_MANUAL]);
        $this->assertEquals($webhookConfig, $triggers[TriggerInterface::TYPE_WEBHOOK]);
    }

    /**
     * 测试获取触发器类型信息
     */
    public function testGetTriggerTypesInfo(): void
    {
        $info = $this->manager->getTriggerTypesInfo();

        $this->assertIsArray($info);
        $this->assertCount(4, $info);

        foreach ($info as $type => $typeInfo) {
            $this->assertArrayHasKey('type', $typeInfo);
            $this->assertArrayHasKey('name', $typeInfo);
            $this->assertArrayHasKey('description', $typeInfo);
            $this->assertArrayHasKey('config_schema', $typeInfo);
            $this->assertArrayHasKey('registered_workflows', $typeInfo);
        }
    }

    /**
     * 测试清除所有注册
     */
    public function testClearAll(): void
    {
        $this->manager->registerTrigger('workflow-001', TriggerInterface::TYPE_MANUAL, []);
        $this->manager->registerTrigger('workflow-002', TriggerInterface::TYPE_WEBHOOK, []);

        $this->manager->clearAll();

        $this->assertEmpty($this->manager->getWorkflowTriggers('workflow-001'));
        $this->assertEmpty($this->manager->getWorkflowTriggers('workflow-002'));
    }

    /**
     * 测试从配置创建管理器
     */
    public function testFromConfig(): void
    {
        $config = [
            'auto_register_builtin' => true,
            'default_tenant_id' => 100,
            'workflows' => [
                'workflow-001' => [
                    TriggerInterface::TYPE_MANUAL => ['name' => '手动触发器'],
                ],
                'workflow-002' => [
                    TriggerInterface::TYPE_SCHEDULE => ['cron_expression' => '0 9 * * *'],
                ],
            ],
        ];

        $manager = TriggerManager::fromConfig($config);

        $this->assertEquals(100, $manager->getDefaultTenantId());
        $this->assertTrue($manager->hasWorkflowTrigger('workflow-001', TriggerInterface::TYPE_MANUAL));
        $this->assertTrue($manager->hasWorkflowTrigger('workflow-002', TriggerInterface::TYPE_SCHEDULE));
    }

    /**
     * 测试导出配置
     */
    public function testExportConfig(): void
    {
        $this->manager->setDefaultTenantId(200);
        $this->manager->registerTrigger('workflow-001', TriggerInterface::TYPE_MANUAL, ['name' => 'Test']);

        $config = $this->manager->exportConfig();

        $this->assertArrayHasKey('auto_register_builtin', $config);
        $this->assertArrayHasKey('default_tenant_id', $config);
        $this->assertArrayHasKey('workflows', $config);
        $this->assertArrayHasKey('trigger_types', $config);
        $this->assertEquals(200, $config['default_tenant_id']);
        $this->assertArrayHasKey('workflow-001', $config['workflows']);
    }

    /**
     * 测试获取 Webhook URL
     */
    public function testGetWebhookUrl(): void
    {
        $workflowId = 'test-workflow-012';
        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_WEBHOOK, []);

        $url = $this->manager->getWebhookUrl($workflowId);

        $this->assertIsString($url);
        $this->assertStringContainsString('/api/workflow/webhook/', $url);
    }

    /**
     * 测试获取定时触发下次执行时间
     */
    public function testGetNextScheduleExecution(): void
    {
        $workflowId = 'test-workflow-013';
        $this->manager->registerTrigger($workflowId, TriggerInterface::TYPE_SCHEDULE, [
            'cron_expression' => '0 9 * * *',
        ]);

        $nextExecution = $this->manager->getNextScheduleExecution($workflowId);

        $this->assertIsInt($nextExecution);
        $this->assertGreaterThan(time(), $nextExecution);
    }
}
