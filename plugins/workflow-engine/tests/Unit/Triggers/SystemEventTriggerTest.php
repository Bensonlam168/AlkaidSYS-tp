<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\Types\SystemEventTrigger;
use PHPUnit\Framework\TestCase;

/**
 * SystemEventTrigger 单元测试
 */
class SystemEventTriggerTest extends TestCase
{
    private SystemEventTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trigger = new SystemEventTrigger();
    }

    /**
     * 测试触发器类型
     */
    public function testGetType(): void
    {
        $this->assertEquals(TriggerInterface::TYPE_SYSTEM_EVENT, $this->trigger->getType());
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
        $this->assertArrayHasKey('event_category', $schema['properties']);
        $this->assertArrayHasKey('event_name', $schema['properties']);
        $this->assertArrayHasKey('entity_type', $schema['properties']);
    }

    /**
     * 测试有效配置验证
     */
    public function testValidateValidConfig(): void
    {
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_ORDER,
            'event_name' => SystemEventTrigger::EVENT_ORDER_CREATED,
        ];

        $result = $this->trigger->validateConfig($config);
        $this->assertTrue($result);
    }

    /**
     * 测试注册触发器
     */
    public function testRegister(): void
    {
        $workflowId = 'test-workflow-001';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_USER,
            'event_name' => SystemEventTrigger::EVENT_USER_REGISTERED,
        ];

        $this->trigger->register($workflowId, $config);

        $this->assertTrue($this->trigger->isRegistered($workflowId));
    }

    /**
     * 测试系统事件触发
     */
    public function testTriggerWithSystemEvent(): void
    {
        $workflowId = 'test-workflow-002';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_ORDER,
            'event_name' => SystemEventTrigger::EVENT_ORDER_CREATED,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $context->setTenantId(100);
        $context->setData([
            'event_category' => 'order',
            'event_name' => 'order.created',
            'entity_type' => 'order',
            'entity_id' => 'ORD-001',
            'entity_data' => ['amount' => 100.00],
        ]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_SYSTEM_EVENT, $result->getTriggerType());
    }

    /**
     * 测试事件分类不匹配不触发
     */
    public function testShouldNotTriggerWithDifferentCategory(): void
    {
        $workflowId = 'test-workflow-003';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_ORDER,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $context->setData([
            'event_category' => 'user',
            'event_name' => 'user.registered',
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试事件名称不匹配不触发
     */
    public function testShouldNotTriggerWithDifferentEventName(): void
    {
        $workflowId = 'test-workflow-004';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_ORDER,
            'event_name' => SystemEventTrigger::EVENT_ORDER_CREATED,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $context->setData([
            'event_category' => 'order',
            'event_name' => 'order.paid',
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试事件模式匹配
     */
    public function testEventPatternMatching(): void
    {
        $workflowId = 'test-workflow-005';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_ORDER,
            'event_pattern' => 'order.*',
        ];

        $this->trigger->register($workflowId, $config);

        // order.created 应该匹配 order.*
        $contextMatch = new TriggerContext();
        $contextMatch->setWorkflowId($workflowId);
        $contextMatch->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $contextMatch->setData([
            'event_category' => 'order',
            'event_name' => 'order.created',
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextMatch));

        // user.created 不应该匹配 order.*
        $contextNoMatch = new TriggerContext();
        $contextNoMatch->setWorkflowId($workflowId);
        $contextNoMatch->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $contextNoMatch->setData([
            'event_category' => 'order',
            'event_name' => 'user.created',
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextNoMatch));
    }

    /**
     * 测试实体类型过滤
     */
    public function testEntityTypeFiltering(): void
    {
        $workflowId = 'test-workflow-006';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_CUSTOM,
            'entity_type' => 'product',
        ];

        $this->trigger->register($workflowId, $config);

        // 匹配的实体类型
        $contextMatch = new TriggerContext();
        $contextMatch->setWorkflowId($workflowId);
        $contextMatch->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $contextMatch->setData([
            'entity_type' => 'product',
            'event_name' => 'product.updated',
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextMatch));

        // 不匹配的实体类型
        $contextNoMatch = new TriggerContext();
        $contextNoMatch->setWorkflowId($workflowId);
        $contextNoMatch->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $contextNoMatch->setData([
            'entity_type' => 'order',
            'event_name' => 'order.created',
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextNoMatch));
    }

    /**
     * 测试条件过滤
     */
    public function testConditionFiltering(): void
    {
        $workflowId = 'test-workflow-007';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_ORDER,
            'conditions' => [
                ['field' => 'entity_data.amount', 'operator' => 'gt', 'value' => 100],
            ],
        ];

        $this->trigger->register($workflowId, $config);

        // 条件满足
        $contextPass = new TriggerContext();
        $contextPass->setWorkflowId($workflowId);
        $contextPass->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $contextPass->setData([
            'event_category' => 'order',
            'event_name' => 'order.created',
            'entity_data' => ['amount' => 150],
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextPass));

        // 条件不满足
        $contextFail = new TriggerContext();
        $contextFail->setWorkflowId($workflowId);
        $contextFail->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $contextFail->setData([
            'event_category' => 'order',
            'event_name' => 'order.created',
            'entity_data' => ['amount' => 50],
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextFail));
    }

    /**
     * 测试字段映射
     */
    public function testFieldMapping(): void
    {
        $workflowId = 'test-workflow-008';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_ORDER,
            'field_mapping' => [
                'workflow_order_id' => 'entity_id',
                'workflow_amount' => 'entity_data.total',
            ],
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $context->setTenantId(100);
        $context->setData([
            'event_category' => 'order',
            'event_name' => 'order.created',
            'entity_id' => 'ORD-001',
            'entity_data' => ['total' => 250.00],
        ]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $data = $result->getData();
        $this->assertEquals('ORD-001', $data['mapped_data']['workflow_order_id']);
        $this->assertEquals(250.00, $data['mapped_data']['workflow_amount']);
    }

    /**
     * 测试条件运算符
     */
    public function testConditionOperators(): void
    {
        $workflowId = 'test-workflow-009';

        $testCases = [
            ['operator' => 'eq', 'value' => 'active', 'actual' => 'active', 'expected' => true],
            ['operator' => 'eq', 'value' => 'active', 'actual' => 'inactive', 'expected' => false],
            ['operator' => 'neq', 'value' => 'active', 'actual' => 'inactive', 'expected' => true],
            ['operator' => 'gt', 'value' => 10, 'actual' => 15, 'expected' => true],
            ['operator' => 'gte', 'value' => 10, 'actual' => 10, 'expected' => true],
            ['operator' => 'lt', 'value' => 10, 'actual' => 5, 'expected' => true],
            ['operator' => 'lte', 'value' => 10, 'actual' => 10, 'expected' => true],
            ['operator' => 'in', 'value' => ['a', 'b', 'c'], 'actual' => 'b', 'expected' => true],
            ['operator' => 'nin', 'value' => ['a', 'b', 'c'], 'actual' => 'd', 'expected' => true],
            ['operator' => 'contains', 'value' => 'test', 'actual' => 'this is a test', 'expected' => true],
        ];

        foreach ($testCases as $index => $case) {
            $config = [
                'event_category' => SystemEventTrigger::CATEGORY_CUSTOM,
                'conditions' => [
                    ['field' => 'test_field', 'operator' => $case['operator'], 'value' => $case['value']],
                ],
            ];

            $this->trigger->clearRegistrations();
            $this->trigger->register($workflowId, $config);

            $context = new TriggerContext();
            $context->setWorkflowId($workflowId);
            $context->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
            $context->setData([
                'test_field' => $case['actual'],
            ]);

            $this->assertEquals(
                $case['expected'],
                $this->trigger->shouldTrigger($context),
                "Failed for operator: {$case['operator']} (case {$index})"
            );
        }
    }

    /**
     * 测试从事件名称提取分类
     */
    public function testCategoryExtractionFromEventName(): void
    {
        $workflowId = 'test-workflow-010';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_USER,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $context->setData([
            'event_name' => 'user.registered', // 从事件名提取分类
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试触发结果包含实体信息
     */
    public function testTriggerResultContainsEntityInfo(): void
    {
        $workflowId = 'test-workflow-011';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_ORDER,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $context->setTenantId(100);
        $context->setData([
            'event_category' => 'order',
            'event_name' => 'order.created',
            'entity_type' => 'order',
            'entity_id' => 'ORD-001',
        ]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('order.created', $result->getMeta('event_name'));
        $this->assertEquals('order', $result->getMeta('entity_type'));
        $this->assertEquals('ORD-001', $result->getMeta('entity_id'));
    }

    /**
     * 测试创建系统事件上下文
     */
    public function testCreateEventContext(): void
    {
        $context = SystemEventTrigger::createEventContext(
            'order.created',
            'order',
            'ORD-001',
            ['amount' => 100],
            100
        );

        $this->assertEquals('order.created', $context['event_name']);
        $this->assertEquals('order', $context['event_category']);
        $this->assertEquals('order', $context['entity_type']);
        $this->assertEquals('ORD-001', $context['entity_id']);
        $this->assertEquals(['amount' => 100], $context['entity_data']);
        $this->assertEquals(100, $context['tenant_id']);
        $this->assertArrayHasKey('event_id', $context);
        $this->assertArrayHasKey('timestamp', $context);
    }

    /**
     * 测试事件分类常量
     */
    public function testCategoryConstants(): void
    {
        $this->assertEquals('user', SystemEventTrigger::CATEGORY_USER);
        $this->assertEquals('order', SystemEventTrigger::CATEGORY_ORDER);
        $this->assertEquals('payment', SystemEventTrigger::CATEGORY_PAYMENT);
        $this->assertEquals('inventory', SystemEventTrigger::CATEGORY_INVENTORY);
        $this->assertEquals('product', SystemEventTrigger::CATEGORY_PRODUCT);
        $this->assertEquals('customer', SystemEventTrigger::CATEGORY_CUSTOMER);
        $this->assertEquals('employee', SystemEventTrigger::CATEGORY_EMPLOYEE);
        $this->assertEquals('document', SystemEventTrigger::CATEGORY_DOCUMENT);
        $this->assertEquals('approval', SystemEventTrigger::CATEGORY_APPROVAL);
        $this->assertEquals('notification', SystemEventTrigger::CATEGORY_NOTIFICATION);
        $this->assertEquals('system', SystemEventTrigger::CATEGORY_SYSTEM);
        $this->assertEquals('custom', SystemEventTrigger::CATEGORY_CUSTOM);
    }

    /**
     * 测试用户事件常量
     */
    public function testUserEventConstants(): void
    {
        $this->assertEquals('user.registered', SystemEventTrigger::EVENT_USER_REGISTERED);
        $this->assertEquals('user.login', SystemEventTrigger::EVENT_USER_LOGIN);
        $this->assertEquals('user.logout', SystemEventTrigger::EVENT_USER_LOGOUT);
        $this->assertEquals('user.updated', SystemEventTrigger::EVENT_USER_UPDATED);
        $this->assertEquals('user.deleted', SystemEventTrigger::EVENT_USER_DELETED);
        $this->assertEquals('user.password_changed', SystemEventTrigger::EVENT_USER_PASSWORD_CHANGED);
        $this->assertEquals('user.role_changed', SystemEventTrigger::EVENT_USER_ROLE_CHANGED);
    }

    /**
     * 测试订单事件常量
     */
    public function testOrderEventConstants(): void
    {
        $this->assertEquals('order.created', SystemEventTrigger::EVENT_ORDER_CREATED);
        $this->assertEquals('order.paid', SystemEventTrigger::EVENT_ORDER_PAID);
        $this->assertEquals('order.shipped', SystemEventTrigger::EVENT_ORDER_SHIPPED);
        $this->assertEquals('order.delivered', SystemEventTrigger::EVENT_ORDER_DELIVERED);
        $this->assertEquals('order.cancelled', SystemEventTrigger::EVENT_ORDER_CANCELLED);
        $this->assertEquals('order.refunded', SystemEventTrigger::EVENT_ORDER_REFUNDED);
        $this->assertEquals('order.completed', SystemEventTrigger::EVENT_ORDER_COMPLETED);
    }

    /**
     * 测试审批事件常量
     */
    public function testApprovalEventConstants(): void
    {
        $this->assertEquals('approval.submitted', SystemEventTrigger::EVENT_APPROVAL_SUBMITTED);
        $this->assertEquals('approval.approved', SystemEventTrigger::EVENT_APPROVAL_APPROVED);
        $this->assertEquals('approval.rejected', SystemEventTrigger::EVENT_APPROVAL_REJECTED);
        $this->assertEquals('approval.withdrawn', SystemEventTrigger::EVENT_APPROVAL_WITHDRAWN);
        $this->assertEquals('approval.escalated', SystemEventTrigger::EVENT_APPROVAL_ESCALATED);
    }

    /**
     * 测试文档事件常量
     */
    public function testDocumentEventConstants(): void
    {
        $this->assertEquals('document.created', SystemEventTrigger::EVENT_DOCUMENT_CREATED);
        $this->assertEquals('document.updated', SystemEventTrigger::EVENT_DOCUMENT_UPDATED);
        $this->assertEquals('document.deleted', SystemEventTrigger::EVENT_DOCUMENT_DELETED);
        $this->assertEquals('document.shared', SystemEventTrigger::EVENT_DOCUMENT_SHARED);
        $this->assertEquals('document.downloaded', SystemEventTrigger::EVENT_DOCUMENT_DOWNLOADED);
    }

    /**
     * 测试获取支持的事件列表
     */
    public function testGetSupportedEvents(): void
    {
        $events = SystemEventTrigger::getSupportedEvents();

        $this->assertIsArray($events);
        $this->assertArrayHasKey(SystemEventTrigger::CATEGORY_USER, $events);
        $this->assertArrayHasKey(SystemEventTrigger::CATEGORY_ORDER, $events);
        $this->assertArrayHasKey(SystemEventTrigger::CATEGORY_APPROVAL, $events);
        $this->assertArrayHasKey(SystemEventTrigger::CATEGORY_DOCUMENT, $events);

        $this->assertContains(SystemEventTrigger::EVENT_USER_REGISTERED, $events[SystemEventTrigger::CATEGORY_USER]);
        $this->assertContains(SystemEventTrigger::EVENT_ORDER_CREATED, $events[SystemEventTrigger::CATEGORY_ORDER]);
    }

    /**
     * 测试自定义分类
     */
    public function testCustomCategory(): void
    {
        $workflowId = 'test-workflow-012';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_CUSTOM,
            'event_name' => 'custom.my_event',
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $context->setTenantId(100);
        $context->setData([
            'event_category' => 'custom',
            'event_name' => 'custom.my_event',
            'payload' => ['custom_data' => 'value'],
        ]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
    }

    /**
     * 测试包含实体数据配置
     */
    public function testIncludeEntityDataConfig(): void
    {
        $workflowId = 'test-workflow-013';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_ORDER,
            'include_entity_data' => true,
        ];

        $this->trigger->register($workflowId, $config);

        $entityData = ['order_id' => 'ORD-001', 'items' => [['sku' => 'SKU-001']]];

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $context->setTenantId(100);
        $context->setData([
            'event_category' => 'order',
            'event_name' => 'order.created',
            'entity_data' => $entityData,
        ]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $data = $result->getData();
        $this->assertEquals($entityData, $data['entity_data']);
    }

    /**
     * 测试优先级配置
     */
    public function testPriorityConfig(): void
    {
        $workflowId = 'test-workflow-014';
        $config = [
            'event_category' => SystemEventTrigger::CATEGORY_ORDER,
            'priority' => 80,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SYSTEM_EVENT);
        $context->setTenantId(100);
        $context->setData([
            'event_category' => 'order',
            'event_name' => 'order.created',
        ]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $data = $result->getData();
        $this->assertEquals(80, $data['priority']);
    }
}
