<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\Types\FormSubmitTrigger;
use PHPUnit\Framework\TestCase;

/**
 * FormSubmitTrigger 单元测试
 */
class FormSubmitTriggerTest extends TestCase
{
    private FormSubmitTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trigger = new FormSubmitTrigger();
    }

    /**
     * 测试触发器类型
     */
    public function testGetType(): void
    {
        $this->assertEquals(TriggerInterface::TYPE_FORM_SUBMIT, $this->trigger->getType());
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
        $this->assertArrayHasKey('form_id', $schema['properties']);
        $this->assertArrayHasKey('action', $schema['properties']);
    }

    /**
     * 测试空配置验证通过
     */
    public function testValidateEmptyConfig(): void
    {
        $result = $this->trigger->validateConfig([]);
        $this->assertTrue($result);
    }

    /**
     * 测试有效配置验证通过
     */
    public function testValidateValidConfig(): void
    {
        $config = [
            'form_id' => 'user_registration',
            'form_collection' => 'users',
            'action' => FormSubmitTrigger::ACTION_CREATE,
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
        $config = ['form_id' => 'test_form'];

        $this->trigger->register($workflowId, $config);

        $this->assertTrue($this->trigger->isRegistered($workflowId));
        $this->assertEquals($config, $this->trigger->getWorkflowConfig($workflowId));
    }

    /**
     * 测试取消注册触发器
     */
    public function testUnregister(): void
    {
        $workflowId = 'test-workflow-002';
        $this->trigger->register($workflowId, []);

        $this->assertTrue($this->trigger->isRegistered($workflowId));

        $this->trigger->unregister($workflowId);

        $this->assertFalse($this->trigger->isRegistered($workflowId));
    }

    /**
     * 测试表单提交触发
     */
    public function testTriggerWithFormSubmit(): void
    {
        $workflowId = 'test-workflow-003';
        $config = [
            'form_id' => 'order_form',
            'form_collection' => 'orders',
            'action' => FormSubmitTrigger::ACTION_CREATE,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_FORM_SUBMIT);
        $context->setTenantId(100);
        $context->setUserId(1);
        $context->setData([
            'form_id' => 'order_form',
            'collection' => 'orders',
            'action' => 'create',
            'form_data' => [
                'customer_name' => 'John Doe',
                'amount' => 100.00,
            ],
        ]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_FORM_SUBMIT, $result->getTriggerType());
    }

    /**
     * 测试表单ID不匹配不触发
     */
    public function testShouldNotTriggerWithDifferentFormId(): void
    {
        $workflowId = 'test-workflow-004';
        $config = [
            'form_id' => 'expected_form',
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_FORM_SUBMIT);
        $context->setData([
            'form_id' => 'different_form',
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试操作类型过滤
     */
    public function testActionTypeFiltering(): void
    {
        $workflowId = 'test-workflow-005';
        $config = [
            'form_id' => 'test_form',
            'action' => FormSubmitTrigger::ACTION_UPDATE,
        ];

        $this->trigger->register($workflowId, $config);

        // CREATE 操作不应该触发
        $contextCreate = new TriggerContext();
        $contextCreate->setWorkflowId($workflowId);
        $contextCreate->setTriggerType(TriggerInterface::TYPE_FORM_SUBMIT);
        $contextCreate->setData([
            'form_id' => 'test_form',
            'action' => 'create',
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextCreate));

        // UPDATE 操作应该触发
        $contextUpdate = new TriggerContext();
        $contextUpdate->setWorkflowId($workflowId);
        $contextUpdate->setTriggerType(TriggerInterface::TYPE_FORM_SUBMIT);
        $contextUpdate->setData([
            'form_id' => 'test_form',
            'action' => 'update',
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextUpdate));
    }

    /**
     * 测试条件过滤
     */
    public function testConditionFiltering(): void
    {
        $workflowId = 'test-workflow-006';
        $config = [
            'form_id' => 'test_form',
            'conditions' => [
                ['field' => 'status', 'operator' => 'eq', 'value' => 'active'],
                ['field' => 'amount', 'operator' => 'gt', 'value' => 100],
            ],
        ];

        $this->trigger->register($workflowId, $config);

        // 条件不满足
        $contextFail = new TriggerContext();
        $contextFail->setWorkflowId($workflowId);
        $contextFail->setTriggerType(TriggerInterface::TYPE_FORM_SUBMIT);
        $contextFail->setData([
            'form_id' => 'test_form',
            'form_data' => [
                'status' => 'inactive',
                'amount' => 50,
            ],
        ]);

        $this->assertFalse($this->trigger->shouldTrigger($contextFail));

        // 条件满足
        $contextPass = new TriggerContext();
        $contextPass->setWorkflowId($workflowId);
        $contextPass->setTriggerType(TriggerInterface::TYPE_FORM_SUBMIT);
        $contextPass->setData([
            'form_id' => 'test_form',
            'form_data' => [
                'status' => 'active',
                'amount' => 150,
            ],
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($contextPass));
    }

    /**
     * 测试字段映射
     */
    public function testFieldMapping(): void
    {
        $workflowId = 'test-workflow-007';
        $config = [
            'form_id' => 'test_form',
            'field_mapping' => [
                'workflow_name' => 'customer_name',
                'workflow_amount' => 'order.total',
            ],
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_FORM_SUBMIT);
        $context->setTenantId(100);
        $context->setData([
            'form_id' => 'test_form',
            'form_data' => [
                'customer_name' => 'Jane Doe',
                'order' => ['total' => 250.00],
            ],
        ]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $data = $result->getData();
        $this->assertEquals('Jane Doe', $data['mapped_data']['workflow_name']);
        $this->assertEquals(250.00, $data['mapped_data']['workflow_amount']);
    }

    /**
     * 测试 ANY 操作类型
     */
    public function testAnyActionType(): void
    {
        $workflowId = 'test-workflow-008';
        $config = [
            'form_id' => 'test_form',
            'action' => FormSubmitTrigger::ACTION_ANY,
        ];

        $this->trigger->register($workflowId, $config);

        $actions = ['create', 'update', 'delete'];

        foreach ($actions as $action) {
            $context = new TriggerContext();
            $context->setWorkflowId($workflowId);
            $context->setTriggerType(TriggerInterface::TYPE_FORM_SUBMIT);
            $context->setData([
                'form_id' => 'test_form',
                'action' => $action,
            ]);

            $this->assertTrue(
                $this->trigger->shouldTrigger($context),
                "Should trigger for action: {$action}"
            );
        }
    }

    /**
     * 测试条件运算符
     */
    public function testConditionOperators(): void
    {
        $workflowId = 'test-workflow-009';

        $testCases = [
            ['operator' => 'eq', 'value' => 'test', 'actual' => 'test', 'expected' => true],
            ['operator' => 'eq', 'value' => 'test', 'actual' => 'other', 'expected' => false],
            ['operator' => 'neq', 'value' => 'test', 'actual' => 'other', 'expected' => true],
            ['operator' => 'gt', 'value' => 10, 'actual' => 15, 'expected' => true],
            ['operator' => 'gte', 'value' => 10, 'actual' => 10, 'expected' => true],
            ['operator' => 'lt', 'value' => 10, 'actual' => 5, 'expected' => true],
            ['operator' => 'lte', 'value' => 10, 'actual' => 10, 'expected' => true],
            ['operator' => 'in', 'value' => ['a', 'b', 'c'], 'actual' => 'b', 'expected' => true],
            ['operator' => 'contains', 'value' => 'test', 'actual' => 'this is a test', 'expected' => true],
        ];

        foreach ($testCases as $index => $case) {
            $config = [
                'form_id' => 'test_form',
                'conditions' => [
                    ['field' => 'test_field', 'operator' => $case['operator'], 'value' => $case['value']],
                ],
            ];

            $this->trigger->clearRegistrations();
            $this->trigger->register($workflowId, $config);

            $context = new TriggerContext();
            $context->setWorkflowId($workflowId);
            $context->setTriggerType(TriggerInterface::TYPE_FORM_SUBMIT);
            $context->setData([
                'form_id' => 'test_form',
                'form_data' => ['test_field' => $case['actual']],
            ]);

            $this->assertEquals(
                $case['expected'],
                $this->trigger->shouldTrigger($context),
                "Failed for operator: {$case['operator']} (case {$index})"
            );
        }
    }

    /**
     * 测试嵌套字段条件
     */
    public function testNestedFieldConditions(): void
    {
        $workflowId = 'test-workflow-010';
        $config = [
            'form_id' => 'test_form',
            'conditions' => [
                ['field' => 'user.profile.age', 'operator' => 'gte', 'value' => 18],
            ],
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_FORM_SUBMIT);
        $context->setData([
            'form_id' => 'test_form',
            'form_data' => [
                'user' => [
                    'profile' => [
                        'age' => 25,
                    ],
                ],
            ],
        ]);

        $this->assertTrue($this->trigger->shouldTrigger($context));
    }
}
