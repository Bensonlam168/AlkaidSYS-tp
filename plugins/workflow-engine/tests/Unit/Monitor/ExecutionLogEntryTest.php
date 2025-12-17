<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Tests\Unit\Monitor;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Alkaid\Workflow\Engine\Monitor\ExecutionLogEntry;

/**
 * ExecutionLogEntry 单元测试
 *
 * @package Alkaid\Workflow\Tests\Unit\Monitor
 */
class ExecutionLogEntryTest extends TestCase
{
    // ========== 构造函数测试 ==========

    #[Test]
    public function testBasicConstruction(): void
    {
        $entry = new ExecutionLogEntry(
            'instance-001',
            ExecutionLogEntry::EVENT_INSTANCE_STARTED,
            'Test message'
        );

        $this->assertEquals('instance-001', $entry->getInstanceId());
        $this->assertEquals(ExecutionLogEntry::EVENT_INSTANCE_STARTED, $entry->getEventType());
        $this->assertEquals('Test message', $entry->getMessage());
        $this->assertEquals(ExecutionLogEntry::LEVEL_INFO, $entry->getLevel());
        $this->assertNotEmpty($entry->getId());
        $this->assertGreaterThan(0, $entry->getTimestamp());
    }

    #[Test]
    public function testConstructionWithOptions(): void
    {
        $entry = new ExecutionLogEntry(
            'instance-002',
            ExecutionLogEntry::EVENT_NODE_FAILED,
            'Error occurred',
            [
                'node_id' => 'node-001',
                'node_type' => 'approval',
                'level' => ExecutionLogEntry::LEVEL_ERROR,
                'context' => ['error_code' => 'ERR_001'],
                'tenant_id' => 100,
                'user_id' => 200,
                'duration' => 1.5,
                'metadata' => ['key' => 'value'],
            ]
        );

        $this->assertEquals('node-001', $entry->getNodeId());
        $this->assertEquals('approval', $entry->getNodeType());
        $this->assertEquals(ExecutionLogEntry::LEVEL_ERROR, $entry->getLevel());
        $this->assertEquals(['error_code' => 'ERR_001'], $entry->getContext());
        $this->assertEquals(100, $entry->getTenantId());
        $this->assertEquals(200, $entry->getUserId());
        $this->assertEquals(1.5, $entry->getDuration());
        $this->assertEquals(['key' => 'value'], $entry->getMetadata());
    }

    // ========== 工厂方法测试 ==========

    #[Test]
    public function testInstanceStarted(): void
    {
        $entry = ExecutionLogEntry::instanceStarted(
            'instance-003',
            'def-001',
            ['var1' => 'value1'],
            ['tenant_id' => 100]
        );

        $this->assertEquals(ExecutionLogEntry::EVENT_INSTANCE_STARTED, $entry->getEventType());
        $this->assertEquals('def-001', $entry->getContext()['definition_id']);
        $this->assertEquals(['var1' => 'value1'], $entry->getContext()['initial_variables']);
        $this->assertEquals(100, $entry->getTenantId());
    }

    #[Test]
    public function testInstanceCompleted(): void
    {
        $entry = ExecutionLogEntry::instanceCompleted(
            'instance-004',
            ['output' => 'done'],
            5.5
        );

        $this->assertEquals(ExecutionLogEntry::EVENT_INSTANCE_COMPLETED, $entry->getEventType());
        $this->assertEquals(['output' => 'done'], $entry->getContext()['result']);
        $this->assertEquals(5.5, $entry->getDuration());
    }

    #[Test]
    public function testInstanceFailed(): void
    {
        $entry = ExecutionLogEntry::instanceFailed(
            'instance-005',
            'ERR_TIMEOUT',
            'Execution timeout'
        );

        $this->assertEquals(ExecutionLogEntry::EVENT_INSTANCE_FAILED, $entry->getEventType());
        $this->assertEquals(ExecutionLogEntry::LEVEL_ERROR, $entry->getLevel());
        $this->assertEquals('ERR_TIMEOUT', $entry->getContext()['error_code']);
        $this->assertEquals('Execution timeout', $entry->getContext()['error_message']);
    }

    #[Test]
    public function testNodeEntered(): void
    {
        $entry = ExecutionLogEntry::nodeEntered(
            'instance-006',
            'node-001',
            'approval'
        );

        $this->assertEquals(ExecutionLogEntry::EVENT_NODE_ENTERED, $entry->getEventType());
        $this->assertEquals('node-001', $entry->getNodeId());
        $this->assertEquals('approval', $entry->getNodeType());
    }

    #[Test]
    public function testNodeExecuting(): void
    {
        $entry = ExecutionLogEntry::nodeExecuting(
            'instance-007',
            'node-002',
            'http',
            ['url' => 'https://api.example.com']
        );

        $this->assertEquals(ExecutionLogEntry::EVENT_NODE_EXECUTING, $entry->getEventType());
        $this->assertEquals(['url' => 'https://api.example.com'], $entry->getContext());
    }

    #[Test]
    public function testNodeCompleted(): void
    {
        $entry = ExecutionLogEntry::nodeCompleted(
            'instance-008',
            'node-003',
            'task',
            ['status' => 'approved'],
            2.5
        );

        $this->assertEquals(ExecutionLogEntry::EVENT_NODE_COMPLETED, $entry->getEventType());
        $this->assertEquals(['status' => 'approved'], $entry->getContext()['result']);
        $this->assertEquals(2.5, $entry->getDuration());
    }

    #[Test]
    public function testNodeFailed(): void
    {
        $entry = ExecutionLogEntry::nodeFailed(
            'instance-009',
            'node-004',
            'http',
            'HTTP_500',
            'Internal server error'
        );

        $this->assertEquals(ExecutionLogEntry::EVENT_NODE_FAILED, $entry->getEventType());
        $this->assertEquals(ExecutionLogEntry::LEVEL_ERROR, $entry->getLevel());
        $this->assertEquals('HTTP_500', $entry->getContext()['error_code']);
    }

    #[Test]
    public function testTaskCreated(): void
    {
        $entry = ExecutionLogEntry::taskCreated(
            'instance-010',
            'task-001',
            'node-005',
            ['user-1', 'user-2']
        );

        $this->assertEquals(ExecutionLogEntry::EVENT_TASK_CREATED, $entry->getEventType());
        $this->assertEquals('task-001', $entry->getContext()['task_id']);
        $this->assertEquals(['user-1', 'user-2'], $entry->getContext()['assignees']);
    }

    #[Test]
    public function testVariableChanged(): void
    {
        $entry = ExecutionLogEntry::variableChanged(
            'instance-011',
            'amount',
            100,
            200
        );

        $this->assertEquals(ExecutionLogEntry::EVENT_VARIABLE_CHANGED, $entry->getEventType());
        $this->assertEquals(ExecutionLogEntry::LEVEL_DEBUG, $entry->getLevel());
        $this->assertEquals('amount', $entry->getContext()['variable_name']);
        $this->assertEquals(100, $entry->getContext()['old_value']);
        $this->assertEquals(200, $entry->getContext()['new_value']);
    }

    // ========== 序列化测试 ==========

    #[Test]
    public function testToArray(): void
    {
        $entry = new ExecutionLogEntry(
            'instance-012',
            ExecutionLogEntry::EVENT_NODE_COMPLETED,
            'Node done',
            [
                'node_id' => 'node-001',
                'node_type' => 'task',
                'context' => ['result' => 'success'],
                'duration' => 1.5,
            ]
        );

        $array = $entry->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('instance_id', $array);
        $this->assertArrayHasKey('node_id', $array);
        $this->assertArrayHasKey('node_type', $array);
        $this->assertArrayHasKey('event_type', $array);
        $this->assertArrayHasKey('level', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('context', $array);
        $this->assertArrayHasKey('timestamp', $array);
        $this->assertArrayHasKey('formatted_timestamp', $array);
        $this->assertArrayHasKey('duration', $array);
        $this->assertArrayHasKey('metadata', $array);

        $this->assertEquals('instance-012', $array['instance_id']);
        $this->assertEquals('node-001', $array['node_id']);
    }

    #[Test]
    public function testFromArray(): void
    {
        $data = [
            'id' => 'log-001',
            'instance_id' => 'instance-013',
            'node_id' => 'node-002',
            'node_type' => 'approval',
            'event_type' => ExecutionLogEntry::EVENT_NODE_COMPLETED,
            'level' => ExecutionLogEntry::LEVEL_INFO,
            'message' => 'Test from array',
            'context' => ['key' => 'value'],
            'tenant_id' => 100,
            'user_id' => 200,
            'timestamp' => 1702800000.123456,
            'duration' => 2.5,
            'metadata' => ['meta' => 'data'],
        ];

        $entry = ExecutionLogEntry::fromArray($data);

        $this->assertEquals('log-001', $entry->getId());
        $this->assertEquals('instance-013', $entry->getInstanceId());
        $this->assertEquals('node-002', $entry->getNodeId());
        $this->assertEquals('approval', $entry->getNodeType());
        $this->assertEquals(ExecutionLogEntry::EVENT_NODE_COMPLETED, $entry->getEventType());
        $this->assertEquals('Test from array', $entry->getMessage());
        $this->assertEquals(100, $entry->getTenantId());
        $this->assertEquals(2.5, $entry->getDuration());
    }

    // ========== 格式化测试 ==========

    #[Test]
    public function testGetFormattedTimestamp(): void
    {
        $entry = new ExecutionLogEntry(
            'instance-014',
            ExecutionLogEntry::EVENT_INSTANCE_STARTED,
            'Test'
        );

        $formatted = $entry->getFormattedTimestamp('Y-m-d H:i:s');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $formatted);
    }

    #[Test]
    public function testSetMetadata(): void
    {
        $entry = new ExecutionLogEntry(
            'instance-015',
            ExecutionLogEntry::EVENT_NODE_EXECUTING,
            'Test'
        );

        $result = $entry->setMetadata('custom_key', 'custom_value');

        $this->assertSame($entry, $result); // 链式调用
        $this->assertEquals('custom_value', $entry->getMetadata()['custom_key']);
    }

    // ========== 常量测试 ==========

    #[Test]
    public function testLevelConstants(): void
    {
        $this->assertEquals('debug', ExecutionLogEntry::LEVEL_DEBUG);
        $this->assertEquals('info', ExecutionLogEntry::LEVEL_INFO);
        $this->assertEquals('warning', ExecutionLogEntry::LEVEL_WARNING);
        $this->assertEquals('error', ExecutionLogEntry::LEVEL_ERROR);
        $this->assertEquals('critical', ExecutionLogEntry::LEVEL_CRITICAL);
    }

    #[Test]
    public function testEventTypeConstants(): void
    {
        $this->assertEquals('instance.started', ExecutionLogEntry::EVENT_INSTANCE_STARTED);
        $this->assertEquals('instance.completed', ExecutionLogEntry::EVENT_INSTANCE_COMPLETED);
        $this->assertEquals('instance.failed', ExecutionLogEntry::EVENT_INSTANCE_FAILED);
        $this->assertEquals('node.entered', ExecutionLogEntry::EVENT_NODE_ENTERED);
        $this->assertEquals('node.executing', ExecutionLogEntry::EVENT_NODE_EXECUTING);
        $this->assertEquals('node.completed', ExecutionLogEntry::EVENT_NODE_COMPLETED);
        $this->assertEquals('node.failed', ExecutionLogEntry::EVENT_NODE_FAILED);
        $this->assertEquals('task.created', ExecutionLogEntry::EVENT_TASK_CREATED);
        $this->assertEquals('variable.changed', ExecutionLogEntry::EVENT_VARIABLE_CHANGED);
    }
}
