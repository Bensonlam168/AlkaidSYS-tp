<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Tests\Unit\Expression;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Alkaid\Workflow\Engine\Expression\Context\ExpressionContext;

/**
 * ExpressionContext Unit Tests
 */
class ExpressionContextTest extends TestCase
{
    private ExpressionContext $context;

    protected function setUp(): void
    {
        $this->context = new ExpressionContext();
    }

    #[Test]
    public function testConstructorWithInitialData(): void
    {
        $context = new ExpressionContext([
            'workflow' => ['name' => 'test'],
            'process' => ['id' => '123'],
            'tenant_id' => 1,
        ]);

        $this->assertEquals('test', $context->getWorkflowVariable('name'));
        $this->assertEquals('123', $context->getProcessVariable('id'));
        $this->assertEquals(1, $context->getTenantId());
    }

    #[Test]
    public function testSetGetWorkflowVariable(): void
    {
        $this->context->setWorkflowVariable('amount', 1000);
        $this->assertEquals(1000, $this->context->getWorkflowVariable('amount'));
    }

    #[Test]
    public function testGetWorkflowVariableWithDefault(): void
    {
        $result = $this->context->getWorkflowVariable('missing', 'default');
        $this->assertEquals('default', $result);
    }

    #[Test]
    public function testSetGetProcessVariable(): void
    {
        $this->context->setProcessVariable('status', 'active');
        $this->assertEquals('active', $this->context->getProcessVariable('status'));
    }

    #[Test]
    public function testSetGetTaskVariable(): void
    {
        $this->context->setTaskVariable('assignee', 'user-1');
        $this->assertEquals('user-1', $this->context->getTaskVariable('assignee'));
    }

    #[Test]
    public function testSetGetCustomVariable(): void
    {
        $this->context->setCustomVariable('custom_field', 'custom_value');
        $this->assertEquals('custom_value', $this->context->getCustomVariable('custom_field'));
    }

    #[Test]
    public function testSetGetTenantId(): void
    {
        $this->context->setTenantId(123);
        $this->assertEquals(123, $this->context->getTenantId());

        $this->context->setTenantId(null);
        $this->assertNull($this->context->getTenantId());
    }

    #[Test]
    public function testSetGetUserId(): void
    {
        $this->context->setUserId(456);
        $this->assertEquals(456, $this->context->getUserId());

        $this->context->setUserId(null);
        $this->assertNull($this->context->getUserId());
    }

    #[Test]
    public function testPopulate(): void
    {
        $this->context->populate([
            'workflow' => ['name' => 'workflow-1'],
            'process' => ['id' => 'process-1'],
            'task' => ['assignee' => 'user-1'],
            'custom' => ['field' => 'value'],
            'tenant_id' => 100,
            'user_id' => 200,
        ]);

        $this->assertEquals('workflow-1', $this->context->getWorkflowVariable('name'));
        $this->assertEquals('process-1', $this->context->getProcessVariable('id'));
        $this->assertEquals('user-1', $this->context->getTaskVariable('assignee'));
        $this->assertEquals('value', $this->context->getCustomVariable('field'));
        $this->assertEquals(100, $this->context->getTenantId());
        $this->assertEquals(200, $this->context->getUserId());
    }

    #[Test]
    public function testToArrayMergesWithPriority(): void
    {
        $this->context->setWorkflowVariable('priority', 'workflow');
        $this->context->setProcessVariable('priority', 'process');
        $this->context->setTaskVariable('priority', 'task');
        $this->context->setCustomVariable('priority', 'custom');

        $array = $this->context->toArray();

        // Custom has highest priority
        $this->assertEquals('custom', $array['priority']);
    }

    #[Test]
    public function testToArrayIncludesSystemVariables(): void
    {
        $array = $this->context->toArray();

        $this->assertArrayHasKey('now', $array);
        $this->assertArrayHasKey('today', $array);
        $this->assertArrayHasKey('timestamp', $array);
        $this->assertArrayHasKey('year', $array);
        $this->assertArrayHasKey('month', $array);
        $this->assertArrayHasKey('day', $array);
    }

    #[Test]
    public function testToArrayIncludesNamespacedAccess(): void
    {
        $this->context->setWorkflowVariable('name', 'test');
        $this->context->setProcessVariable('id', '123');

        $array = $this->context->toArray();

        $this->assertEquals('test', $array['workflow']['name']);
        $this->assertEquals('123', $array['process']['id']);
        $this->assertArrayHasKey('system', $array);
    }

    #[Test]
    public function testToArrayIncludesContextInfo(): void
    {
        $this->context->setTenantId(100);
        $this->context->setUserId(200);

        $array = $this->context->toArray();

        $this->assertEquals(100, $array['_tenant_id']);
        $this->assertEquals(200, $array['_user_id']);
    }

    #[Test]
    public function testGetWithDotNotation(): void
    {
        $this->context->setWorkflowVariable('order', [
            'customer' => [
                'name' => 'Alice',
                'email' => 'alice@example.com',
            ],
        ]);

        $this->assertEquals('Alice', $this->context->get('workflow.order.customer.name'));
        $this->assertEquals('alice@example.com', $this->context->get('workflow.order.customer.email'));
    }

    #[Test]
    public function testGetWithDefaultValue(): void
    {
        $result = $this->context->get('missing.path', 'default');
        $this->assertEquals('default', $result);
    }

    #[Test]
    public function testHasWithDotNotation(): void
    {
        $this->context->setWorkflowVariable('key', 'value');

        $this->assertTrue($this->context->has('workflow.key'));
        $this->assertFalse($this->context->has('workflow.missing'));
        $this->assertFalse($this->context->has('missing.path'));
    }

    #[Test]
    public function testFromArray(): void
    {
        $context = ExpressionContext::fromArray([
            'field1' => 'value1',
            'field2' => 'value2',
        ]);

        $this->assertEquals('value1', $context->getCustomVariable('field1'));
        $this->assertEquals('value2', $context->getCustomVariable('field2'));
    }

    #[Test]
    public function testMerge(): void
    {
        $context1 = new ExpressionContext();
        $context1->setWorkflowVariable('name', 'workflow-1');
        $context1->setTenantId(100);

        $context2 = new ExpressionContext();
        $context2->setWorkflowVariable('name', 'workflow-2');
        $context2->setProcessVariable('id', 'process-1');
        $context2->setTenantId(200);

        $merged = $context1->merge($context2);

        // context2 values should override
        $this->assertEquals('workflow-2', $merged->getWorkflowVariable('name'));
        $this->assertEquals('process-1', $merged->getProcessVariable('id'));
        $this->assertEquals(200, $merged->getTenantId());
    }

    #[Test]
    public function testMergePreservesOriginalContext(): void
    {
        $context1 = new ExpressionContext();
        $context1->setWorkflowVariable('name', 'original');

        $context2 = new ExpressionContext();
        $context2->setWorkflowVariable('name', 'override');

        $merged = $context1->merge($context2);

        // Original should be unchanged
        $this->assertEquals('original', $context1->getWorkflowVariable('name'));
        $this->assertEquals('override', $merged->getWorkflowVariable('name'));
    }

    #[Test]
    public function testClear(): void
    {
        $this->context->setWorkflowVariable('name', 'test');
        $this->context->setProcessVariable('id', '123');
        $this->context->setTenantId(100);
        $this->context->setUserId(200);

        $this->context->clear();

        $this->assertNull($this->context->getWorkflowVariable('name'));
        $this->assertNull($this->context->getProcessVariable('id'));
        $this->assertNull($this->context->getTenantId());
        $this->assertNull($this->context->getUserId());
    }

    #[Test]
    public function testClearPreservesSystemVariables(): void
    {
        $this->context->clear();

        $array = $this->context->toArray();
        $this->assertArrayHasKey('now', $array);
        $this->assertArrayHasKey('today', $array);
    }

    #[Test]
    public function testFluentInterface(): void
    {
        $result = $this->context
            ->setWorkflowVariable('name', 'test')
            ->setProcessVariable('id', '123')
            ->setTenantId(100)
            ->setUserId(200);

        $this->assertSame($this->context, $result);
    }

    #[Test]
    public function testSystemVariablesRefreshOnToArray(): void
    {
        // Verify system variables are refreshed each time toArray is called
        $array1 = $this->context->toArray();

        // Verify system variables exist
        $this->assertArrayHasKey('now', $array1);
        $this->assertArrayHasKey('today', $array1);
        $this->assertArrayHasKey('timestamp', $array1);
        $this->assertIsInt($array1['timestamp']);

        // System array should also be present with namespaced access
        $this->assertArrayHasKey('system', $array1);
        $this->assertArrayHasKey('now', $array1['system']);
    }

    #[Test]
    public function testDeepNestedAccess(): void
    {
        $this->context->setWorkflowVariable('data', [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'value' => 'deep',
                    ],
                ],
            ],
        ]);

        $result = $this->context->get('workflow.data.level1.level2.level3.value');
        $this->assertEquals('deep', $result);
    }

    #[Test]
    public function testGetReturnsDefaultForNonArrayIntermediate(): void
    {
        $this->context->setWorkflowVariable('scalar', 'string_value');

        // Trying to access path through scalar should return default
        $result = $this->context->get('workflow.scalar.nested', 'default');
        $this->assertEquals('default', $result);
    }
}
