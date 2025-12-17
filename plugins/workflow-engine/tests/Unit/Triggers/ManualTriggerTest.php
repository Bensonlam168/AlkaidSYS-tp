<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\Types\ManualTrigger;
use PHPUnit\Framework\TestCase;

/**
 * ManualTrigger 单元测试
 */
class ManualTriggerTest extends TestCase
{
    private ManualTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trigger = new ManualTrigger();
    }

    /**
     * 测试触发器类型
     */
    public function testGetType(): void
    {
        $this->assertEquals(TriggerInterface::TYPE_MANUAL, $this->trigger->getType());
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
            'name' => '手动触发测试',
            'description' => '测试描述',
            'confirm_required' => true,
            'confirm_message' => '确认执行？',
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
        $config = ['name' => '测试触发器'];

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
     * 测试获取已注册工作流列表
     */
    public function testGetRegisteredWorkflows(): void
    {
        $this->trigger->register('workflow-001', []);
        $this->trigger->register('workflow-002', []);
        $this->trigger->register('workflow-003', []);

        $workflows = $this->trigger->getRegisteredWorkflows();

        $this->assertCount(3, $workflows);
        $this->assertContains('workflow-001', $workflows);
        $this->assertContains('workflow-002', $workflows);
        $this->assertContains('workflow-003', $workflows);
    }

    /**
     * 测试手动触发应该触发
     */
    public function testShouldTriggerWithManualContext(): void
    {
        $workflowId = 'test-workflow-003';
        $this->trigger->register($workflowId, []);

        $context = TriggerContext::manual($workflowId, 100, 1, ['test' => 'data']);

        $this->assertTrue($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试非手动触发不应该触发
     */
    public function testShouldNotTriggerWithNonManualContext(): void
    {
        $workflowId = 'test-workflow-004';
        $this->trigger->register($workflowId, []);

        $context = TriggerContext::webhook($workflowId, [], []);

        $this->assertFalse($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试执行手动触发
     */
    public function testTrigger(): void
    {
        $workflowId = 'test-workflow-005';
        $userId = 123;
        $tenantId = 456;
        $inputData = ['order_id' => 'ORD-001'];

        $this->trigger->register($workflowId, ['name' => '手动触发测试']);
        $context = TriggerContext::manual($workflowId, $tenantId, $userId, $inputData);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_MANUAL, $result->getTriggerType());
        $this->assertEquals($workflowId, $result->getWorkflowId());

        $data = $result->getData();
        $this->assertEquals($userId, $data['triggered_by']);
        $this->assertEquals($tenantId, $data['tenant_id']);
        $this->assertEquals($inputData, $data['input_data']);
    }

    /**
     * 测试未注册工作流的手动触发
     */
    public function testTriggerUnregisteredWorkflow(): void
    {
        $workflowId = 'unregistered-workflow';
        $context = TriggerContext::manual($workflowId, null, 1);

        $result = $this->trigger->trigger($context);

        // 手动触发器允许未注册工作流的触发
        $this->assertTrue($result->isSuccess());
    }

    /**
     * 测试带权限检查的触发
     */
    public function testTriggerWithPermissionRequired(): void
    {
        $workflowId = 'test-workflow-006';
        $config = [
            'name' => '需要权限的触发器',
            'permission_required' => 'workflow.execute',
        ];

        $this->trigger->register($workflowId, $config);

        // 没有权限的用户
        $contextWithoutPermission = TriggerContext::manual($workflowId, null, 1);

        // 有权限的用户
        $contextWithPermission = TriggerContext::manual($workflowId, null, 2);
        $contextWithPermission->setMeta('user_permissions', ['workflow.execute', 'workflow.view']);

        // 没有权限应该被跳过
        $resultWithoutPermission = $this->trigger->trigger($contextWithoutPermission);
        $this->assertTrue($resultWithoutPermission->isSkipped());

        // 有权限应该成功
        $resultWithPermission = $this->trigger->trigger($contextWithPermission);
        $this->assertTrue($resultWithPermission->isSuccess());
    }

    /**
     * 测试输入验证
     */
    public function testValidateInput(): void
    {
        $schema = [
            'required' => ['name', 'email'],
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
            ],
        ];

        // 有效输入
        $validInput = ['name' => 'John', 'email' => 'john@example.com'];
        $this->assertTrue($this->trigger->validateInput($validInput, $schema));

        // 缺少必填字段
        $invalidInput = ['name' => 'John'];
        $this->assertFalse($this->trigger->validateInput($invalidInput, $schema));

        // 空 schema 总是通过
        $this->assertTrue($this->trigger->validateInput([], []));
    }

    /**
     * 测试清除所有注册
     */
    public function testClearRegistrations(): void
    {
        $this->trigger->register('workflow-001', []);
        $this->trigger->register('workflow-002', []);

        $this->assertCount(2, $this->trigger->getRegisteredWorkflows());

        $this->trigger->clearRegistrations();

        $this->assertCount(0, $this->trigger->getRegisteredWorkflows());
    }

    /**
     * 测试配置验证错误
     */
    public function testGetValidationErrors(): void
    {
        // 手动触发器的配置都是可选的，所以我们测试一个超长名称
        $config = [
            'name' => str_repeat('a', 200), // 超过 maxLength 100
        ];

        $this->trigger->validateConfig($config);
        $errors = $this->trigger->getValidationErrors();

        $this->assertNotEmpty($errors);
    }
}
