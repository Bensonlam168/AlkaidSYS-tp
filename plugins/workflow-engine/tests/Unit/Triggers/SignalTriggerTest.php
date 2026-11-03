<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\Types\SignalTrigger;
use PHPUnit\Framework\TestCase;

/**
 * SignalTrigger 单元测试
 */
class SignalTriggerTest extends TestCase
{
    private SignalTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trigger = new SignalTrigger();
    }

    /**
     * 测试触发器类型
     */
    public function testGetType(): void
    {
        $this->assertEquals(TriggerInterface::TYPE_SIGNAL, $this->trigger->getType());
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
        $this->assertArrayHasKey('signal_name', $schema['properties']);
        $this->assertArrayHasKey('signal_scope', $schema['properties']);
    }

    /**
     * 测试有效配置验证
     */
    public function testValidateValidConfig(): void
    {
        $config = [
            'signal_name' => 'order.completed',
            'signal_scope' => SignalTrigger::SCOPE_TENANT,
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
            'signal_scope' => SignalTrigger::SCOPE_TENANT,
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
        $config = ['signal_name' => 'test.signal'];

        $this->trigger->register($workflowId, $config);

        $this->assertTrue($this->trigger->isRegistered($workflowId));
    }

    /**
     * 测试信号触发
     */
    public function testTriggerWithSignal(): void
    {
        $workflowId = 'test-workflow-002';
        $config = [
            'signal_name' => 'order.completed',
            'signal_scope' => SignalTrigger::SCOPE_TENANT,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $context->setTenantId(100);
        $context->setData([
            'signal_name' => 'order.completed',
            'scope' => 'tenant',
            'data' => ['order_id' => 'ORD-001'],
        ]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_SIGNAL, $result->getTriggerType());
    }

    /**
     * 测试信号名称不匹配不触发
     */
    public function testShouldNotTriggerWithDifferentSignalName(): void
    {
        $workflowId = 'test-workflow-003';
        $config = [
            'signal_name' => 'expected.signal',
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $context->setData([
            'signal_name' => 'different.signal',
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试全局作用域信号
     */
    public function testGlobalScopeSignal(): void
    {
        $workflowId = 'test-workflow-004';
        $config = [
            'signal_name' => 'global.signal',
            'signal_scope' => SignalTrigger::SCOPE_GLOBAL,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $context->setTenantId(100);
        $context->setData([
            'signal_name' => 'global.signal',
            'scope' => 'global',
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试租户作用域信号
     */
    public function testTenantScopeSignal(): void
    {
        $workflowId = 'test-workflow-005';
        $config = [
            'signal_name' => 'tenant.signal',
            'signal_scope' => SignalTrigger::SCOPE_TENANT,
        ];

        $this->trigger->register($workflowId, $config);

        // 同租户应该触发
        $contextSameTenant = new TriggerContext();
        $contextSameTenant->setWorkflowId($workflowId);
        $contextSameTenant->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $contextSameTenant->setTenantId(100);
        $contextSameTenant->setData([
            'signal_name' => 'tenant.signal',
            'tenant_id' => 100,
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextSameTenant));

        // 不同租户不应该触发
        $contextDifferentTenant = new TriggerContext();
        $contextDifferentTenant->setWorkflowId($workflowId);
        $contextDifferentTenant->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $contextDifferentTenant->setTenantId(100);
        $contextDifferentTenant->setData([
            'signal_name' => 'tenant.signal',
            'tenant_id' => 200,
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextDifferentTenant));
    }

    /**
     * 测试信号 TTL
     */
    public function testSignalTTL(): void
    {
        $workflowId = 'test-workflow-006';
        $config = [
            'signal_name' => 'test.signal',
            'ttl' => 3600, // 1 hour
        ];

        $this->trigger->register($workflowId, $config);

        // 新信号应该触发
        $contextNew = new TriggerContext();
        $contextNew->setWorkflowId($workflowId);
        $contextNew->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $contextNew->setData([
            'signal_name' => 'test.signal',
            'timestamp' => time(),
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextNew));

        // 过期信号不应该触发
        $contextExpired = new TriggerContext();
        $contextExpired->setWorkflowId($workflowId);
        $contextExpired->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $contextExpired->setData([
            'signal_name' => 'test.signal',
            'timestamp' => time() - 7200, // 2 hours ago
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextExpired));
    }

    /**
     * 测试关联键匹配
     */
    public function testCorrelationKeyMatching(): void
    {
        $workflowId = 'test-workflow-007';
        $config = [
            'signal_name' => 'test.signal',
            'correlation_keys' => [
                ['name' => 'order_id', 'path' => 'data.order_id'],
            ],
        ];

        $this->trigger->register($workflowId, $config);

        // 带关联键的上下文
        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $context->setMeta('correlation.order_id', 'ORD-001');
        $context->setData([
            'signal_name' => 'test.signal',
            'data' => ['order_id' => 'ORD-001'],
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($context));

        // 关联键不匹配
        $contextNoMatch = new TriggerContext();
        $contextNoMatch->setWorkflowId($workflowId);
        $contextNoMatch->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $contextNoMatch->setMeta('correlation.order_id', 'ORD-001');
        $contextNoMatch->setData([
            'signal_name' => 'test.signal',
            'data' => ['order_id' => 'ORD-002'],
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextNoMatch));
    }

    /**
     * 测试过滤表达式
     */
    public function testFilterExpression(): void
    {
        $workflowId = 'test-workflow-008';
        $config = [
            'signal_name' => 'test.signal',
            'catch_mode' => SignalTrigger::CATCH_FILTERED,
            'filter_expression' => 'data.amount > 100',
        ];

        $this->trigger->register($workflowId, $config);

        // 条件满足
        $contextPass = new TriggerContext();
        $contextPass->setWorkflowId($workflowId);
        $contextPass->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $contextPass->setData([
            'signal_name' => 'test.signal',
            'data' => ['amount' => 150],
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextPass));

        // 条件不满足
        $contextFail = new TriggerContext();
        $contextFail->setWorkflowId($workflowId);
        $contextFail->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $contextFail->setData([
            'signal_name' => 'test.signal',
            'data' => ['amount' => 50],
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextFail));
    }

    /**
     * 测试数据 Schema 验证
     */
    public function testDataSchemaValidation(): void
    {
        $workflowId = 'test-workflow-009';
        $config = [
            'signal_name' => 'test.signal',
            'data_schema' => [
                'required' => ['order_id', 'status'],
            ],
        ];

        $this->trigger->register($workflowId, $config);

        // 数据完整
        $contextValid = new TriggerContext();
        $contextValid->setWorkflowId($workflowId);
        $contextValid->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $contextValid->setData([
            'signal_name' => 'test.signal',
            'data' => ['order_id' => 'ORD-001', 'status' => 'completed'],
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextValid));

        // 缺少必填字段
        $contextInvalid = new TriggerContext();
        $contextInvalid->setWorkflowId($workflowId);
        $contextInvalid->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $contextInvalid->setData([
            'signal_name' => 'test.signal',
            'data' => ['order_id' => 'ORD-001'],
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextInvalid));
    }

    /**
     * 测试触发结果包含关联值
     */
    public function testTriggerResultContainsCorrelations(): void
    {
        $workflowId = 'test-workflow-010';
        $config = [
            'signal_name' => 'test.signal',
            'correlation_keys' => [
                ['name' => 'order_id', 'path' => 'data.order_id'],
            ],
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_SIGNAL);
        $context->setTenantId(100);
        $context->setData([
            'signal_name' => 'test.signal',
            'data' => ['order_id' => 'ORD-001'],
        ]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $correlations = $result->getMeta('correlations');
        $this->assertIsArray($correlations);
        $this->assertEquals('ORD-001', $correlations['order_id']);
    }

    /**
     * 测试创建信号上下文
     */
    public function testCreateSignalContext(): void
    {
        $context = SignalTrigger::createSignalContext(
            'order.completed',
            ['order_id' => 'ORD-001'],
            SignalTrigger::SCOPE_TENANT,
            100
        );

        $this->assertEquals('order.completed', $context['signal_name']);
        $this->assertEquals('tenant', $context['scope']);
        $this->assertEquals(100, $context['tenant_id']);
        $this->assertArrayHasKey('signal_id', $context);
        $this->assertArrayHasKey('timestamp', $context);
    }

    /**
     * 测试信号作用域常量
     */
    public function testScopeConstants(): void
    {
        $this->assertEquals('global', SignalTrigger::SCOPE_GLOBAL);
        $this->assertEquals('tenant', SignalTrigger::SCOPE_TENANT);
        $this->assertEquals('process', SignalTrigger::SCOPE_PROCESS);
        $this->assertEquals('instance', SignalTrigger::SCOPE_INSTANCE);
    }

    /**
     * 测试捕获模式常量
     */
    public function testCatchModeConstants(): void
    {
        $this->assertEquals('all', SignalTrigger::CATCH_ALL);
        $this->assertEquals('first', SignalTrigger::CATCH_FIRST);
        $this->assertEquals('filtered', SignalTrigger::CATCH_FILTERED);
    }
}
