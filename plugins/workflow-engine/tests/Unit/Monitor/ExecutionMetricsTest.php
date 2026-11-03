<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Monitor;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Alkaid\Workflow\Engine\Monitor\Metrics\ExecutionMetrics;

/**
 * ExecutionMetrics 单元测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Monitor
 */
class ExecutionMetricsTest extends TestCase
{
    // ========== 构造函数测试 ==========

    #[Test]
    public function testBasicConstruction(): void
    {
        $metrics = new ExecutionMetrics('instance-001', 'def-001');

        $this->assertEquals('instance-001', $metrics->getInstanceId());
        $this->assertEquals('def-001', $metrics->getDefinitionId());
        $this->assertGreaterThan(0, $metrics->getStartTime());
        $this->assertNull($metrics->getEndTime());
    }

    #[Test]
    public function testConstructionWithNullDefinition(): void
    {
        $metrics = new ExecutionMetrics('instance-002');

        $this->assertEquals('instance-002', $metrics->getInstanceId());
        $this->assertNull($metrics->getDefinitionId());
    }

    // ========== 节点记录测试 ==========

    #[Test]
    public function testRecordNodeStart(): void
    {
        $metrics = new ExecutionMetrics('instance-003', 'def-001');

        $metrics->recordNodeStart('node-001', 'approval');

        $nodeMetrics = $metrics->getNodeMetrics();
        $this->assertArrayHasKey('node-001', $nodeMetrics);
        $this->assertEquals('node-001', $nodeMetrics['node-001']['node_id']);
        $this->assertEquals('approval', $nodeMetrics['node-001']['node_type']);
        $this->assertEquals('running', $nodeMetrics['node-001']['status']);
        $this->assertEquals(1, $metrics->getNodeCount());
    }

    #[Test]
    public function testRecordNodeEnd(): void
    {
        $metrics = new ExecutionMetrics('instance-004', 'def-001');

        $metrics->recordNodeStart('node-001', 'task');
        usleep(5000); // 5ms
        $metrics->recordNodeEnd('node-001', 'success');

        $nodeMetrics = $metrics->getNodeMetrics();
        $this->assertEquals('success', $nodeMetrics['node-001']['status']);
        $this->assertNotNull($nodeMetrics['node-001']['end_time']);
        $this->assertGreaterThan(0, $nodeMetrics['node-001']['duration']);
        $this->assertEquals(1, $metrics->getCompletedNodeCount());
    }

    #[Test]
    public function testRecordNodeEndWithFailed(): void
    {
        $metrics = new ExecutionMetrics('instance-005', 'def-001');

        $metrics->recordNodeStart('node-001', 'http');
        $metrics->recordNodeEnd('node-001', 'failed', [
            'error' => ['code' => 'ERR_001', 'message' => 'Test error'],
        ]);

        $this->assertEquals(1, $metrics->getFailedNodeCount());
        $this->assertEquals(0, $metrics->getCompletedNodeCount());
        $this->assertCount(1, $metrics->getErrorMetrics());
    }

    #[Test]
    public function testRecordNodeRetry(): void
    {
        $metrics = new ExecutionMetrics('instance-006', 'def-001');

        $metrics->recordNodeStart('node-001', 'http');
        $metrics->recordNodeRetry('node-001');
        $metrics->recordNodeRetry('node-001');

        $nodeMetrics = $metrics->getNodeMetrics();
        $this->assertEquals(2, $nodeMetrics['node-001']['retry_count']);
        $this->assertEquals(2, $metrics->getRetryCount());
    }

    // ========== 统计计算测试 ==========

    #[Test]
    public function testGetTotalDuration(): void
    {
        $metrics = new ExecutionMetrics('instance-007', 'def-001');

        usleep(10000); // 10ms
        $metrics->finish();

        $duration = $metrics->getTotalDuration();
        $this->assertGreaterThan(0.01, $duration);
    }

    #[Test]
    public function testGetTotalDurationMs(): void
    {
        $metrics = new ExecutionMetrics('instance-008', 'def-001');

        usleep(10000); // 10ms
        $metrics->finish();

        $durationMs = $metrics->getTotalDurationMs();
        $this->assertGreaterThan(10, $durationMs);
    }

    #[Test]
    public function testGetAverageNodeDuration(): void
    {
        $metrics = new ExecutionMetrics('instance-009', 'def-001');

        // 节点 1: ~10ms
        $metrics->recordNodeStart('node-001', 'task');
        usleep(10000);
        $metrics->recordNodeEnd('node-001', 'success');

        // 节点 2: ~20ms
        $metrics->recordNodeStart('node-002', 'task');
        usleep(20000);
        $metrics->recordNodeEnd('node-002', 'success');

        $avgDuration = $metrics->getAverageNodeDuration();
        $this->assertGreaterThan(0.01, $avgDuration); // > 10ms
    }

    #[Test]
    public function testGetSlowestNodes(): void
    {
        $metrics = new ExecutionMetrics('instance-010', 'def-001');

        // 快速节点
        $metrics->recordNodeStart('node-fast', 'task');
        usleep(5000);
        $metrics->recordNodeEnd('node-fast', 'success');

        // 慢速节点
        $metrics->recordNodeStart('node-slow', 'http');
        usleep(20000);
        $metrics->recordNodeEnd('node-slow', 'success');

        // 中等节点
        $metrics->recordNodeStart('node-medium', 'approval');
        usleep(10000);
        $metrics->recordNodeEnd('node-medium', 'success');

        $slowest = $metrics->getSlowestNodes(2);

        $this->assertCount(2, $slowest);
        $this->assertEquals('node-slow', $slowest[0]['node_id']);
    }

    #[Test]
    public function testGetFailedNodes(): void
    {
        $metrics = new ExecutionMetrics('instance-011', 'def-001');

        $metrics->recordNodeStart('node-success', 'task');
        $metrics->recordNodeEnd('node-success', 'success');

        $metrics->recordNodeStart('node-failed-1', 'http');
        $metrics->recordNodeEnd('node-failed-1', 'failed');

        $metrics->recordNodeStart('node-failed-2', 'script');
        $metrics->recordNodeEnd('node-failed-2', 'error');

        $failedNodes = $metrics->getFailedNodes();

        $this->assertCount(2, $failedNodes);
    }

    #[Test]
    public function testGetSuccessRate(): void
    {
        $metrics = new ExecutionMetrics('instance-012', 'def-001');

        // 3 成功, 1 失败 = 75% 成功率
        for ($i = 1; $i <= 3; $i++) {
            $metrics->recordNodeStart("success-{$i}", 'task');
            $metrics->recordNodeEnd("success-{$i}", 'success');
        }

        $metrics->recordNodeStart('failed-1', 'http');
        $metrics->recordNodeEnd('failed-1', 'failed');

        $successRate = $metrics->getSuccessRate();
        $this->assertEquals(75.0, $successRate);
    }

    #[Test]
    public function testGetSuccessRateWithNoNodes(): void
    {
        $metrics = new ExecutionMetrics('instance-013', 'def-001');

        $successRate = $metrics->getSuccessRate();
        $this->assertEquals(100.0, $successRate);
    }

    #[Test]
    public function testGetNodeThroughput(): void
    {
        $metrics = new ExecutionMetrics('instance-014', 'def-001');

        // 快速完成 5 个节点
        for ($i = 1; $i <= 5; $i++) {
            $metrics->recordNodeStart("node-{$i}", 'task');
            $metrics->recordNodeEnd("node-{$i}", 'success');
        }

        $metrics->finish();

        $throughput = $metrics->getNodeThroughput();
        $this->assertGreaterThan(0, $throughput);
    }

    // ========== 内存统计测试 ==========

    #[Test]
    public function testGetMemoryStats(): void
    {
        $metrics = new ExecutionMetrics('instance-015', 'def-001');

        $metrics->recordNodeStart('node-001', 'task');
        $metrics->recordNodeEnd('node-001', 'success');

        $memoryStats = $metrics->getMemoryStats();

        $this->assertArrayHasKey('peak_usage', $memoryStats);
        $this->assertArrayHasKey('peak_usage_mb', $memoryStats);
        $this->assertArrayHasKey('current_usage', $memoryStats);
        $this->assertArrayHasKey('current_usage_mb', $memoryStats);
        $this->assertArrayHasKey('node_avg_usage', $memoryStats);

        $this->assertGreaterThan(0, $memoryStats['peak_usage']);
    }

    // ========== 汇总测试 ==========

    #[Test]
    public function testGetSummary(): void
    {
        $metrics = new ExecutionMetrics('instance-016', 'def-001');

        $metrics->recordNodeStart('node-001', 'task');
        usleep(5000);
        $metrics->recordNodeEnd('node-001', 'success');

        $metrics->recordNodeStart('node-002', 'http');
        usleep(5000);
        $metrics->recordNodeEnd('node-002', 'failed');

        $metrics->finish();

        $summary = $metrics->getSummary();

        $this->assertEquals('instance-016', $summary['instance_id']);
        $this->assertEquals('def-001', $summary['definition_id']);
        $this->assertNotNull($summary['start_time']);
        $this->assertNotNull($summary['end_time']);
        $this->assertGreaterThan(0, $summary['total_duration_ms']);
        $this->assertEquals(2, $summary['node_count']);
        $this->assertEquals(1, $summary['completed_node_count']);
        $this->assertEquals(1, $summary['failed_node_count']);
        $this->assertEquals(50.0, $summary['success_rate']);
        $this->assertArrayHasKey('memory', $summary);
    }

    #[Test]
    public function testGetDetailedMetrics(): void
    {
        $metrics = new ExecutionMetrics('instance-017', 'def-001');

        $metrics->recordNodeStart('node-001', 'task');
        $metrics->recordNodeEnd('node-001', 'success');
        $metrics->recordCustomMetric('custom_metric', 42, ['env' => 'test']);

        $detailed = $metrics->getDetailedMetrics();

        $this->assertArrayHasKey('summary', $detailed);
        $this->assertArrayHasKey('node_metrics', $detailed);
        $this->assertArrayHasKey('error_metrics', $detailed);
        $this->assertArrayHasKey('custom_metrics', $detailed);
        $this->assertArrayHasKey('slowest_nodes', $detailed);
        $this->assertArrayHasKey('failed_nodes', $detailed);

        $this->assertArrayHasKey('custom_metric', $detailed['custom_metrics']);
        $this->assertEquals(42, $detailed['custom_metrics']['custom_metric']['value']);
    }

    #[Test]
    public function testToArray(): void
    {
        $metrics = new ExecutionMetrics('instance-018', 'def-001');
        $metrics->finish();

        $array = $metrics->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('summary', $array);
        $this->assertArrayHasKey('node_metrics', $array);
    }

    // ========== 自定义指标测试 ==========

    #[Test]
    public function testRecordCustomMetric(): void
    {
        $metrics = new ExecutionMetrics('instance-019', 'def-001');

        $metrics->recordCustomMetric('response_time', 150.5, ['endpoint' => '/api/test']);
        $metrics->recordCustomMetric('request_count', 100);

        $customMetrics = $metrics->getCustomMetrics();

        $this->assertCount(2, $customMetrics);
        $this->assertEquals(150.5, $customMetrics['response_time']['value']);
        $this->assertEquals(['endpoint' => '/api/test'], $customMetrics['response_time']['tags']);
    }

    // ========== 错误记录测试 ==========

    #[Test]
    public function testRecordError(): void
    {
        $metrics = new ExecutionMetrics('instance-020', 'def-001');

        $metrics->recordError('node-001', [
            'code' => 'ERR_TIMEOUT',
            'message' => 'Connection timeout',
        ]);

        $errors = $metrics->getErrorMetrics();

        $this->assertCount(1, $errors);
        $this->assertEquals('node-001', $errors[0]['node_id']);
        $this->assertEquals('ERR_TIMEOUT', $errors[0]['error_code']);
        $this->assertEquals('Connection timeout', $errors[0]['error_message']);
    }

    // ========== 常量测试 ==========

    #[Test]
    public function testMetricConstants(): void
    {
        $this->assertEquals('duration', ExecutionMetrics::METRIC_DURATION);
        $this->assertEquals('node_count', ExecutionMetrics::METRIC_NODE_COUNT);
        $this->assertEquals('error_count', ExecutionMetrics::METRIC_ERROR_COUNT);
        $this->assertEquals('retry_count', ExecutionMetrics::METRIC_RETRY_COUNT);
        $this->assertEquals('memory_usage', ExecutionMetrics::METRIC_MEMORY_USAGE);
        $this->assertEquals('cpu_time', ExecutionMetrics::METRIC_CPU_TIME);
    }
}
