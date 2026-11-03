<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\Types\DataEventTrigger;
use PHPUnit\Framework\TestCase;

/**
 * DataEventTrigger 单元测试
 */
class DataEventTriggerTest extends TestCase
{
    private DataEventTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trigger = new DataEventTrigger();
    }

    /**
     * 测试触发器类型
     */
    public function testGetType(): void
    {
        $this->assertEquals(TriggerInterface::TYPE_DATA_EVENT, $this->trigger->getType());
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
     * 测试配置 Schema
     */
    public function testGetConfigSchema(): void
    {
        $schema = $this->trigger->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('collection', $schema['properties']);
        $this->assertArrayHasKey('event_types', $schema['properties']);
        $this->assertArrayHasKey('watch_fields', $schema['properties']);
        $this->assertArrayHasKey('conditions', $schema['properties']);
    }

    /**
     * 测试配置验证
     */
    public function testValidateConfig(): void
    {
        $validConfig = [
            'collection' => 'users',
            'event_types' => ['created', 'updated'],
        ];

        $this->assertTrue($this->trigger->validateConfig($validConfig));
    }

    /**
     * 测试必填字段验证
     */
    public function testValidateConfigRequiredFields(): void
    {
        // 缺少必填字段
        $invalidConfig = [];
        $this->assertFalse($this->trigger->validateConfig($invalidConfig));

        $errors = $this->trigger->getValidationErrors();
        $this->assertNotEmpty($errors);
    }

    /**
     * 测试注册触发器
     */
    public function testRegister(): void
    {
        $workflowId = 'test-workflow-001';
        $config = [
            'collection' => 'orders',
            'event_types' => ['created'],
        ];

        $this->trigger->register($workflowId, $config);

        $this->assertTrue($this->trigger->isRegistered($workflowId));
        $this->assertEquals($config, $this->trigger->getWorkflowConfig($workflowId));
    }

    /**
     * 测试数据创建事件触发
     */
    public function testTriggerCreatedEvent(): void
    {
        $workflowId = 'test-workflow-002';
        $config = [
            'collection' => 'users',
            'event_types' => ['created'],
        ];

        $this->trigger->register($workflowId, $config);

        $eventData = [
            'collection' => 'users',
            'id' => 123,
            'data' => ['name' => 'John', 'email' => 'john@example.com'],
        ];

        $context = TriggerContext::dataEvent($workflowId, 'created', $eventData, 100);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_DATA_EVENT, $result->getTriggerType());

        $data = $result->getData();
        $this->assertEquals('created', $data['event_type']);
        $this->assertEquals('users', $data['collection']);
        $this->assertEquals(123, $data['record_id']);
    }

    /**
     * 测试数据更新事件触发
     */
    public function testTriggerUpdatedEvent(): void
    {
        $workflowId = 'test-workflow-003';
        $config = [
            'collection' => 'orders',
            'event_types' => ['updated'],
            'include_old_values' => true,
        ];

        $this->trigger->register($workflowId, $config);

        $eventData = [
            'collection' => 'orders',
            'id' => 456,
            'data' => ['status' => 'shipped', 'updated_at' => time()],
            'old_data' => ['status' => 'pending'],
            'changed_fields' => ['status', 'updated_at'],
            'changes' => [
                'status' => ['old' => 'pending', 'new' => 'shipped'],
            ],
        ];

        $context = TriggerContext::dataEvent($workflowId, 'updated', $eventData, 100);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());

        $data = $result->getData();
        $this->assertEquals('updated', $data['event_type']);
        $this->assertArrayHasKey('old_data', $data);
        $this->assertArrayHasKey('changed_fields', $data);
    }

    /**
     * 测试事件类型过滤
     */
    public function testEventTypeFiltering(): void
    {
        $workflowId = 'test-workflow-004';
        $config = [
            'collection' => 'products',
            'event_types' => ['created'], // 只监听创建事件
        ];

        $this->trigger->register($workflowId, $config);

        // 更新事件不应该触发
        $updateContext = TriggerContext::dataEvent($workflowId, 'updated', [
            'collection' => 'products',
            'id' => 1,
            'data' => [],
        ], 100);

        $this->assertFalse($this->trigger->shouldTrigger($updateContext));

        // 创建事件应该触发
        $createContext = TriggerContext::dataEvent($workflowId, 'created', [
            'collection' => 'products',
            'id' => 1,
            'data' => [],
        ], 100);

        $this->assertTrue($this->trigger->shouldTrigger($createContext));
    }

    /**
     * 测试集合过滤
     */
    public function testCollectionFiltering(): void
    {
        $workflowId = 'test-workflow-005';
        $config = [
            'collection' => 'users',
            'event_types' => ['created'],
        ];

        $this->trigger->register($workflowId, $config);

        // 不同集合的事件不应该触发
        $wrongCollectionContext = TriggerContext::dataEvent($workflowId, 'created', [
            'collection' => 'orders',
            'id' => 1,
            'data' => [],
        ], 100);

        $this->assertFalse($this->trigger->shouldTrigger($wrongCollectionContext));

        // 正确集合的事件应该触发
        $correctCollectionContext = TriggerContext::dataEvent($workflowId, 'created', [
            'collection' => 'users',
            'id' => 1,
            'data' => [],
        ], 100);

        $this->assertTrue($this->trigger->shouldTrigger($correctCollectionContext));
    }

    /**
     * 测试字段监听
     */
    public function testWatchFields(): void
    {
        $workflowId = 'test-workflow-006';
        $config = [
            'collection' => 'orders',
            'event_types' => ['updated'],
            'watch_fields' => ['status', 'amount'], // 只监听这两个字段
        ];

        $this->trigger->register($workflowId, $config);

        // 监听字段的变更应该触发
        $watchedFieldContext = TriggerContext::dataEvent($workflowId, 'updated', [
            'collection' => 'orders',
            'id' => 1,
            'data' => ['status' => 'completed'],
            'changed_fields' => ['status'],
        ], 100);

        $this->assertTrue($this->trigger->shouldTrigger($watchedFieldContext));

        // 非监听字段的变更不应该触发
        $unwatchedFieldContext = TriggerContext::dataEvent($workflowId, 'updated', [
            'collection' => 'orders',
            'id' => 1,
            'data' => ['notes' => 'test'],
            'changed_fields' => ['notes'],
        ], 100);

        $this->assertFalse($this->trigger->shouldTrigger($unwatchedFieldContext));
    }

    /**
     * 测试忽略字段
     */
    public function testIgnoreFields(): void
    {
        $workflowId = 'test-workflow-007';
        $config = [
            'collection' => 'users',
            'event_types' => ['updated'],
            'ignore_fields' => ['updated_at', 'last_login'],
        ];

        $this->trigger->register($workflowId, $config);

        // 只有忽略字段变更不应该触发
        $onlyIgnoredContext = TriggerContext::dataEvent($workflowId, 'updated', [
            'collection' => 'users',
            'id' => 1,
            'data' => ['updated_at' => time()],
            'changed_fields' => ['updated_at'],
        ], 100);

        $this->assertFalse($this->trigger->shouldTrigger($onlyIgnoredContext));

        // 包含非忽略字段应该触发
        $withOtherFieldContext = TriggerContext::dataEvent($workflowId, 'updated', [
            'collection' => 'users',
            'id' => 1,
            'data' => ['name' => 'New Name', 'updated_at' => time()],
            'changed_fields' => ['name', 'updated_at'],
        ], 100);

        $this->assertTrue($this->trigger->shouldTrigger($withOtherFieldContext));
    }

    /**
     * 测试条件过滤
     */
    public function testConditionsFiltering(): void
    {
        $workflowId = 'test-workflow-008';
        $config = [
            'collection' => 'orders',
            'event_types' => ['created'],
            'conditions' => [
                ['field' => 'status', 'operator' => '=', 'value' => 'paid'],
                ['field' => 'amount', 'operator' => '>', 'value' => 100],
            ],
        ];

        $this->trigger->register($workflowId, $config);

        // 满足条件应该触发
        $matchingContext = TriggerContext::dataEvent($workflowId, 'created', [
            'collection' => 'orders',
            'id' => 1,
            'data' => ['status' => 'paid', 'amount' => 200],
        ], 100);

        $this->assertTrue($this->trigger->shouldTrigger($matchingContext));

        // 不满足条件不应该触发
        $notMatchingContext = TriggerContext::dataEvent($workflowId, 'created', [
            'collection' => 'orders',
            'id' => 2,
            'data' => ['status' => 'pending', 'amount' => 200],
        ], 100);

        $this->assertFalse($this->trigger->shouldTrigger($notMatchingContext));
    }

    /**
     * 测试各种条件操作符
     */
    public function testConditionOperators(): void
    {
        $workflowId = 'test-workflow-009';

        // 测试 in 操作符
        $config = [
            'collection' => 'products',
            'event_types' => ['created'],
            'conditions' => [
                ['field' => 'category', 'operator' => 'in', 'value' => ['electronics', 'clothing']],
            ],
        ];

        $this->trigger->register($workflowId, $config);

        $inMatchContext = TriggerContext::dataEvent($workflowId, 'created', [
            'collection' => 'products',
            'id' => 1,
            'data' => ['category' => 'electronics'],
        ], 100);

        $this->assertTrue($this->trigger->shouldTrigger($inMatchContext));

        $notInMatchContext = TriggerContext::dataEvent($workflowId, 'created', [
            'collection' => 'products',
            'id' => 2,
            'data' => ['category' => 'food'],
        ], 100);

        $this->assertFalse($this->trigger->shouldTrigger($notInMatchContext));
    }

    /**
     * 测试 contains 操作符
     */
    public function testContainsOperator(): void
    {
        $workflowId = 'test-workflow-010';
        $config = [
            'collection' => 'posts',
            'event_types' => ['created'],
            'conditions' => [
                ['field' => 'title', 'operator' => 'contains', 'value' => 'important'],
            ],
        ];

        $this->trigger->register($workflowId, $config);

        $matchContext = TriggerContext::dataEvent($workflowId, 'created', [
            'collection' => 'posts',
            'id' => 1,
            'data' => ['title' => 'This is an important announcement'],
        ], 100);

        $this->assertTrue($this->trigger->shouldTrigger($matchContext));

        $notMatchContext = TriggerContext::dataEvent($workflowId, 'created', [
            'collection' => 'posts',
            'id' => 2,
            'data' => ['title' => 'Regular post'],
        ], 100);

        $this->assertFalse($this->trigger->shouldTrigger($notMatchContext));
    }

    /**
     * 测试租户隔离
     */
    public function testTenantIsolation(): void
    {
        $workflowId = 'test-workflow-011';
        $config = [
            'collection' => 'users',
            'event_types' => ['created'],
            'tenant_isolated' => true,
            'tenant_id' => 100,
        ];

        $this->trigger->register($workflowId, $config);

        // 同一租户应该触发
        $sameTenantContext = TriggerContext::dataEvent($workflowId, 'created', [
            'collection' => 'users',
            'id' => 1,
            'data' => [],
        ], 100);

        $this->assertTrue($this->trigger->shouldTrigger($sameTenantContext));

        // 不同租户不应该触发
        $differentTenantContext = TriggerContext::dataEvent($workflowId, 'created', [
            'collection' => 'users',
            'id' => 2,
            'data' => [],
        ], 200);

        $this->assertFalse($this->trigger->shouldTrigger($differentTenantContext));
    }

    /**
     * 测试从模型事件创建上下文
     */
    public function testCreateContextFromModelEvent(): void
    {
        $workflowId = 'test-workflow-012';
        $eventType = 'updated';
        $collection = 'orders';
        $data = ['status' => 'shipped', 'amount' => 100];
        $oldData = ['status' => 'pending', 'amount' => 100];
        $tenantId = 100;

        $context = DataEventTrigger::createContextFromModelEvent(
            $workflowId,
            $eventType,
            $collection,
            $data,
            $oldData,
            $tenantId
        );

        $this->assertInstanceOf(TriggerContext::class, $context);
        $this->assertEquals(TriggerInterface::TYPE_DATA_EVENT, $context->getTriggerType());
        $this->assertEquals($workflowId, $context->getWorkflowId());
        $this->assertEquals($eventType, $context->getEventName());
        $this->assertEquals($tenantId, $context->getTenantId());

        $eventData = $context->getEventData();
        $this->assertEquals($collection, $eventData['collection']);
        $this->assertEquals(['status'], $eventData['changed_fields']);
        $this->assertArrayHasKey('changes', $eventData);
    }

    /**
     * 测试获取支持的事件类型
     */
    public function testGetSupportedEventTypes(): void
    {
        $eventTypes = DataEventTrigger::getSupportedEventTypes();

        $this->assertIsArray($eventTypes);
        $this->assertArrayHasKey('created', $eventTypes);
        $this->assertArrayHasKey('updated', $eventTypes);
        $this->assertArrayHasKey('deleted', $eventTypes);
        $this->assertArrayHasKey('field_changed', $eventTypes);
    }

    /**
     * 测试获取支持的操作符
     */
    public function testGetSupportedOperators(): void
    {
        $operators = DataEventTrigger::getSupportedOperators();

        $this->assertIsArray($operators);
        $this->assertArrayHasKey('=', $operators);
        $this->assertArrayHasKey('!=', $operators);
        $this->assertArrayHasKey('>', $operators);
        $this->assertArrayHasKey('<', $operators);
        $this->assertArrayHasKey('in', $operators);
        $this->assertArrayHasKey('contains', $operators);
    }

    /**
     * 测试删除事件
     */
    public function testDeletedEvent(): void
    {
        $workflowId = 'test-workflow-013';
        $config = [
            'collection' => 'users',
            'event_types' => ['deleted'],
        ];

        $this->trigger->register($workflowId, $config);

        $context = TriggerContext::dataEvent($workflowId, 'deleted', [
            'collection' => 'users',
            'id' => 999,
            'data' => ['id' => 999, 'name' => 'Deleted User'],
        ], 100);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('deleted', $result->getData()['event_type']);
    }
}
