<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Tests\Unit\Monitor;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Alkaid\Workflow\Engine\Monitor\WorkflowMonitor;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;

/**
 * WorkflowMonitor 单元测试
 *
 * @package Alkaid\Workflow\Tests\Unit\Monitor
 */
class WorkflowMonitorTest extends TestCase
{
    private WorkflowMonitor $monitor;

    protected function setUp(): void
    {
        TenantContext::clearAll();
        TenantContext::initialize(100, 1);
        $this->monitor = new WorkflowMonitor();
    }

    protected function tearDown(): void
    {
        TenantContext::clearAll();
    }

    // ========== 实例监控测试 ==========

    #[Test]
    public function testStartMonitoring(): void
    {
        $instanceId = 'test-instance-001';
        $metadata = [
            'definition_id' => 'def-001',
            'variables' => ['key' => 'value'],
        ];

        $this->monitor->startMonitoring($instanceId, $metadata);

        $status = $this->monitor->getInstanceStatus($instanceId);

        $this->assertEquals($instanceId, $status['instance_id']);
        $this->assertEquals(100, $status['tenant_id']);
        $this->assertEquals('def-001', $status['definition_id']);
        $this->assertEquals(WorkflowMonitor::STATUS_RUNNING, $status['status']);
        $this->assertNotNull($status['started_at']);
    }

    #[Test]
    public function testStopMonitoring(): void
    {
        $instanceId = 'test-instance-002';

        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);
        $this->monitor->stopMonitoring($instanceId);

        $status = $this->monitor->getInstanceStatus($instanceId);

        $this->assertEquals(WorkflowMonitor::STATUS_COMPLETED, $status['status']);
        $this->assertNotNull($status['completed_at']);
    }

    #[Test]
    public function testGetInstanceStatusForUnknownInstance(): void
    {
        $status = $this->monitor->getInstanceStatus('unknown-instance');

        $this->assertEquals('unknown', $status['status']);
        $this->assertArrayHasKey('error', $status);
    }

    #[Test]
    public function testGetActiveInstances(): void
    {
        $this->monitor->startMonitoring('instance-1', ['definition_id' => 'def-001']);
        $this->monitor->startMonitoring('instance-2', ['definition_id' => 'def-002']);
        $this->monitor->startMonitoring('instance-3', ['definition_id' => 'def-001']);
        $this->monitor->stopMonitoring('instance-3');

        $activeInstances = $this->monitor->getActiveInstances();

        $this->assertCount(2, $activeInstances);
    }

    #[Test]
    public function testGetActiveInstancesFilteredByTenant(): void
    {
        $this->monitor->startMonitoring('instance-1', ['definition_id' => 'def-001']);

        // 切换租户
        TenantContext::clearAll();
        TenantContext::initialize(200, 2);
        $this->monitor->startMonitoring('instance-2', ['definition_id' => 'def-002']);

        // 按租户过滤
        $tenant100Instances = $this->monitor->getActiveInstances(100);
        $tenant200Instances = $this->monitor->getActiveInstances(200);

        $this->assertCount(1, $tenant100Instances);
        $this->assertCount(1, $tenant200Instances);
    }

    #[Test]
    public function testUpdateInstanceStatus(): void
    {
        $instanceId = 'test-instance-003';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);

        $this->monitor->updateInstanceStatus($instanceId, WorkflowMonitor::STATUS_SUSPENDED);

        $status = $this->monitor->getInstanceStatus($instanceId);
        $this->assertEquals(WorkflowMonitor::STATUS_SUSPENDED, $status['status']);
    }

    #[Test]
    public function testUpdateInstanceStatusWithFailure(): void
    {
        $instanceId = 'test-instance-004';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);

        $this->monitor->updateInstanceStatus($instanceId, WorkflowMonitor::STATUS_FAILED, [
            'error_code' => 'ERR_001',
            'error_message' => 'Test error',
        ]);

        $status = $this->monitor->getInstanceStatus($instanceId);
        $this->assertEquals(WorkflowMonitor::STATUS_FAILED, $status['status']);
    }

    // ========== 节点监控测试 ==========

    #[Test]
    public function testRecordNodeStart(): void
    {
        $instanceId = 'test-instance-005';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);

        $executionId = $this->monitor->recordNodeStart(
            $instanceId,
            'node-001',
            'approval',
            ['data' => 'test']
        );

        $this->assertNotEmpty($executionId);
        $this->assertStringStartsWith('exec_', $executionId);

        $status = $this->monitor->getInstanceStatus($instanceId);
        $this->assertEquals('node-001', $status['current_node']);
    }

    #[Test]
    public function testRecordNodeComplete(): void
    {
        $instanceId = 'test-instance-006';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);

        $executionId = $this->monitor->recordNodeStart($instanceId, 'node-001', 'task');

        // 模拟执行延迟
        usleep(10000); // 10ms

        $this->monitor->recordNodeComplete($executionId, 'success', ['output' => 'done']);

        $history = $this->monitor->getNodeHistory($instanceId, 'node-001');

        $this->assertCount(1, $history);
        $this->assertEquals('success', $history[0]['status']);
        $this->assertNotNull($history[0]['duration']);
        $this->assertGreaterThan(0, $history[0]['duration']);
    }

    #[Test]
    public function testRecordNodeError(): void
    {
        $instanceId = 'test-instance-007';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);

        $executionId = $this->monitor->recordNodeStart($instanceId, 'node-001', 'http');

        $this->monitor->recordNodeError(
            $executionId,
            'HTTP_500',
            'Internal server error',
            ['url' => 'https://api.example.com']
        );

        $history = $this->monitor->getNodeHistory($instanceId, 'node-001');

        $this->assertEquals('failed', $history[0]['status']);
        $this->assertEquals('HTTP_500', $history[0]['error']['code']);
    }

    #[Test]
    public function testRecordNodeRetry(): void
    {
        $instanceId = 'test-instance-008';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);

        $executionId = $this->monitor->recordNodeStart($instanceId, 'node-001', 'http');
        $this->monitor->recordNodeRetry($executionId, 1, 'Connection timeout');

        $metrics = $this->monitor->getInstanceMetrics($instanceId);

        $this->assertEquals(1, $metrics['summary']['retry_count']);
    }

    // ========== 执行日志测试 ==========

    #[Test]
    public function testGetExecutionLogs(): void
    {
        $instanceId = 'test-instance-009';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);
        $this->monitor->recordNodeStart($instanceId, 'node-001', 'task');
        $this->monitor->stopMonitoring($instanceId);

        $logs = $this->monitor->getExecutionLogs($instanceId);

        $this->assertNotEmpty($logs);
        $this->assertArrayHasKey('event_type', $logs[0]);
        $this->assertArrayHasKey('message', $logs[0]);
        $this->assertArrayHasKey('timestamp', $logs[0]);
    }

    #[Test]
    public function testGetExecutionLogsWithFilters(): void
    {
        $instanceId = 'test-instance-010';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);

        $exec1 = $this->monitor->recordNodeStart($instanceId, 'node-001', 'task');
        $this->monitor->recordNodeComplete($exec1, 'success');

        $exec2 = $this->monitor->recordNodeStart($instanceId, 'node-002', 'http');
        $this->monitor->recordNodeError($exec2, 'ERR', 'Error');

        // 过滤节点
        $node1Logs = $this->monitor->getExecutionLogs($instanceId, ['node_id' => 'node-001']);
        $this->assertGreaterThan(0, count($node1Logs));
        foreach ($node1Logs as $log) {
            if ($log['node_id'] !== null) {
                $this->assertEquals('node-001', $log['node_id']);
            }
        }

        // 过滤级别
        $errorLogs = $this->monitor->getExecutionLogs($instanceId, ['level' => 'error']);
        $this->assertGreaterThan(0, count($errorLogs));
    }

    #[Test]
    public function testGetNodeHistory(): void
    {
        $instanceId = 'test-instance-011';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);

        // 执行同一节点多次
        for ($i = 0; $i < 3; $i++) {
            $execId = $this->monitor->recordNodeStart($instanceId, 'node-001', 'task');
            $this->monitor->recordNodeComplete($execId, 'success');
        }

        $history = $this->monitor->getNodeHistory($instanceId, 'node-001');

        $this->assertCount(3, $history);
    }

    // ========== 性能指标测试 ==========

    #[Test]
    public function testGetInstanceMetrics(): void
    {
        $instanceId = 'test-instance-012';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);

        $exec1 = $this->monitor->recordNodeStart($instanceId, 'node-001', 'task');
        usleep(5000);
        $this->monitor->recordNodeComplete($exec1, 'success');

        $exec2 = $this->monitor->recordNodeStart($instanceId, 'node-002', 'approval');
        usleep(5000);
        $this->monitor->recordNodeComplete($exec2, 'success');

        $this->monitor->stopMonitoring($instanceId);

        $metrics = $this->monitor->getInstanceMetrics($instanceId);

        $this->assertArrayHasKey('summary', $metrics);
        $this->assertEquals($instanceId, $metrics['summary']['instance_id']);
        $this->assertEquals(2, $metrics['summary']['node_count']);
        $this->assertEquals(2, $metrics['summary']['completed_node_count']);
        $this->assertEquals(100, $metrics['summary']['success_rate']);
        $this->assertGreaterThan(0, $metrics['summary']['total_duration_ms']);
    }

    #[Test]
    public function testGetDefinitionMetrics(): void
    {
        $definitionId = 'def-metrics-test';

        // 创建多个实例
        for ($i = 1; $i <= 3; $i++) {
            $instanceId = "metrics-instance-{$i}";
            $this->monitor->startMonitoring($instanceId, ['definition_id' => $definitionId]);

            $execId = $this->monitor->recordNodeStart($instanceId, 'node-001', 'task');
            usleep(1000);
            $this->monitor->recordNodeComplete($execId, 'success');

            $this->monitor->stopMonitoring($instanceId);
        }

        $metrics = $this->monitor->getDefinitionMetrics($definitionId);

        $this->assertEquals($definitionId, $metrics['definition_id']);
        $this->assertEquals(3, $metrics['instance_count']);
        $this->assertGreaterThan(0, $metrics['avg_duration_ms']);
    }

    #[Test]
    public function testGetSystemMetrics(): void
    {
        $this->monitor->startMonitoring('sys-instance-1', ['definition_id' => 'def-001']);
        $this->monitor->startMonitoring('sys-instance-2', ['definition_id' => 'def-002']);
        $this->monitor->stopMonitoring('sys-instance-2');

        $metrics = $this->monitor->getSystemMetrics();

        $this->assertArrayHasKey('instances', $metrics);
        $this->assertEquals(1, $metrics['instances']['active']);
        $this->assertEquals(1, $metrics['instances']['completed']);
        $this->assertEquals(2, $metrics['instances']['total']);
        $this->assertArrayHasKey('performance', $metrics);
        $this->assertArrayHasKey('memory', $metrics);
    }

    // ========== 事件订阅测试 ==========

    #[Test]
    public function testSubscribeAndPushEvent(): void
    {
        $instanceId = 'event-instance-001';
        $receivedEvents = [];

        $subscriptionId = $this->monitor->subscribeToInstance($instanceId, function ($event) use (&$receivedEvents) {
            $receivedEvents[] = $event;
        });

        $this->assertNotEmpty($subscriptionId);

        $this->monitor->pushEvent($instanceId, 'test.event', ['data' => 'test']);

        $this->assertCount(1, $receivedEvents);
        $this->assertEquals('test.event', $receivedEvents[0]['event_type']);
    }

    #[Test]
    public function testUnsubscribe(): void
    {
        $instanceId = 'event-instance-002';
        $receivedEvents = [];

        $subscriptionId = $this->monitor->subscribeToInstance($instanceId, function ($event) use (&$receivedEvents) {
            $receivedEvents[] = $event;
        });

        $this->monitor->pushEvent($instanceId, 'event.1', []);
        $this->assertCount(1, $receivedEvents);

        $this->monitor->unsubscribe($subscriptionId);

        $this->monitor->pushEvent($instanceId, 'event.2', []);
        $this->assertCount(1, $receivedEvents); // 仍然是1，因为已取消订阅
    }

    // ========== 配置测试 ==========

    #[Test]
    public function testSetMaxLogsPerInstance(): void
    {
        $this->monitor->setMaxLogsPerInstance(5);

        $instanceId = 'config-instance-001';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);

        // 生成超过限制的日志
        for ($i = 0; $i < 10; $i++) {
            $execId = $this->monitor->recordNodeStart($instanceId, "node-{$i}", 'task');
            $this->monitor->recordNodeComplete($execId, 'success');
        }

        $logs = $this->monitor->getExecutionLogs($instanceId);
        $this->assertLessThanOrEqual(5, count($logs));
    }

    #[Test]
    public function testSetVerboseLogging(): void
    {
        $this->monitor->setVerboseLogging(false);

        $instanceId = 'verbose-instance-001';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);
        $execId = $this->monitor->recordNodeStart($instanceId, 'node-001', 'task');
        $this->monitor->recordNodeComplete($execId, 'success');

        $logs = $this->monitor->getExecutionLogs($instanceId);

        // 非详细模式下，节点进入日志不记录
        $nodeEnteredLogs = array_filter($logs, fn ($l) => $l['event_type'] === 'node.entered');
        $this->assertEmpty($nodeEnteredLogs);
    }

    #[Test]
    public function testCleanup(): void
    {
        $instanceId = 'cleanup-instance-001';
        $this->monitor->startMonitoring($instanceId, ['definition_id' => 'def-001']);
        $this->monitor->stopMonitoring($instanceId);

        // 立即清理（maxAge = 0）
        $cleaned = $this->monitor->cleanup(0);

        $this->assertEquals(1, $cleaned);

        $status = $this->monitor->getInstanceStatus($instanceId);
        $this->assertEquals('unknown', $status['status']);
    }
}
