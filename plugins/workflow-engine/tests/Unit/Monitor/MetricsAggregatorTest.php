<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Tests\Unit\Monitor;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Alkaid\Workflow\Engine\Monitor\Metrics\MetricsAggregator;
use Alkaid\Workflow\Engine\Monitor\Metrics\ExecutionMetrics;

/**
 * MetricsAggregator 单元测试
 *
 * @package Alkaid\Workflow\Tests\Unit\Monitor
 */
class MetricsAggregatorTest extends TestCase
{
    private MetricsAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new MetricsAggregator();
    }

    // ========== 数据点测试 ==========

    #[Test]
    public function testAddDataPoint(): void
    {
        $this->aggregator->addDataPoint('test.metric', 100.0, ['tag1' => 'value1']);

        $stats = $this->aggregator->getStats('test.metric');

        $this->assertEquals(1, $stats['count']);
        $this->assertEquals(100.0, $stats['sum']);
        $this->assertEquals(100.0, $stats['avg']);
    }

    #[Test]
    public function testAddMultipleDataPoints(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->aggregator->addDataPoint('test.metric', (float) $i);
        }

        $stats = $this->aggregator->getStats('test.metric');

        $this->assertEquals(10, $stats['count']);
        $this->assertEquals(55.0, $stats['sum']); // 1+2+...+10 = 55
        $this->assertEquals(5.5, $stats['avg']);
        $this->assertEquals(1.0, $stats['min']);
        $this->assertEquals(10.0, $stats['max']);
    }

    #[Test]
    public function testAddInstanceMetrics(): void
    {
        $execMetrics = new ExecutionMetrics('instance-001', 'def-001');
        $execMetrics->recordNodeStart('node-001', 'task');
        usleep(5000);
        $execMetrics->recordNodeEnd('node-001', 'success');
        $execMetrics->finish();

        $this->aggregator->addInstanceMetrics($execMetrics);

        $durationStats = $this->aggregator->getStats('instance.duration');
        $this->assertEquals(1, $durationStats['count']);
        $this->assertGreaterThan(0, $durationStats['avg']);
    }

    // ========== 统计测试 ==========

    #[Test]
    public function testGetStatsForEmptyMetric(): void
    {
        $stats = $this->aggregator->getStats('nonexistent.metric');

        $this->assertEquals(0, $stats['count']);
        $this->assertEquals(0, $stats['sum']);
        $this->assertEquals(0, $stats['avg']);
    }

    #[Test]
    public function testGetStatsWithPercentiles(): void
    {
        // 添加 100 个数据点
        for ($i = 1; $i <= 100; $i++) {
            $this->aggregator->addDataPoint('percentile.test', (float) $i);
        }

        $stats = $this->aggregator->getStats('percentile.test');

        $this->assertArrayHasKey('percentiles', $stats);
        $this->assertArrayHasKey('p50', $stats['percentiles']);
        $this->assertArrayHasKey('p75', $stats['percentiles']);
        $this->assertArrayHasKey('p90', $stats['percentiles']);
        $this->assertArrayHasKey('p95', $stats['percentiles']);
        $this->assertArrayHasKey('p99', $stats['percentiles']);

        // p50 应该接近 50
        $this->assertGreaterThanOrEqual(49, $stats['percentiles']['p50']);
        $this->assertLessThanOrEqual(51, $stats['percentiles']['p50']);
    }

    #[Test]
    public function testGetStatsWithStddev(): void
    {
        // 添加有变化的数据
        $values = [10, 20, 30, 40, 50];
        foreach ($values as $value) {
            $this->aggregator->addDataPoint('stddev.test', (float) $value);
        }

        $stats = $this->aggregator->getStats('stddev.test');

        $this->assertArrayHasKey('stddev', $stats);
        $this->assertGreaterThan(0, $stats['stddev']);
    }

    #[Test]
    public function testGetStatsWithTimeFilter(): void
    {
        $now = microtime(true);

        // 添加"旧"数据点（通过直接添加数据）
        $this->aggregator->addDataPoint('time.test', 100.0);

        usleep(10000); // 10ms

        // 添加"新"数据点
        $this->aggregator->addDataPoint('time.test', 200.0);

        $stats = $this->aggregator->getStats('time.test', [
            'start_time' => $now,
        ]);

        $this->assertEquals(2, $stats['count']);
    }

    // ========== 时间序列测试 ==========

    #[Test]
    public function testGetTimeSeries(): void
    {
        // 添加数据点
        for ($i = 0; $i < 10; $i++) {
            $this->aggregator->addDataPoint('series.test', (float) ($i * 10));
        }

        $series = $this->aggregator->getTimeSeries('series.test', 60, 10);

        $this->assertArrayHasKey('metric', $series);
        $this->assertArrayHasKey('window', $series);
        $this->assertArrayHasKey('bucket_size', $series);
        $this->assertArrayHasKey('series', $series);
        $this->assertEquals('series.test', $series['metric']);
    }

    #[Test]
    public function testGetTimeSeriesWithAggregation(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->aggregator->addDataPoint('agg.test', (float) $i);
        }

        $sumSeries = $this->aggregator->getTimeSeries('agg.test', 60, 60, ['aggregation' => 'sum']);
        $avgSeries = $this->aggregator->getTimeSeries('agg.test', 60, 60, ['aggregation' => 'avg']);

        // 不同聚合方式应该产生不同结果
        $this->assertArrayHasKey('aggregation', $sumSeries);
        $this->assertEquals('sum', $sumSeries['aggregation']);
        $this->assertEquals('avg', $avgSeries['aggregation']);
    }

    // ========== 分组统计测试 ==========

    #[Test]
    public function testGetStatsByTag(): void
    {
        $this->aggregator->addDataPoint('tag.test', 100.0, ['type' => 'A']);
        $this->aggregator->addDataPoint('tag.test', 200.0, ['type' => 'A']);
        $this->aggregator->addDataPoint('tag.test', 300.0, ['type' => 'B']);

        $statsByTag = $this->aggregator->getStatsByTag('tag.test', 'type');

        $this->assertArrayHasKey('groups', $statsByTag);
        $this->assertArrayHasKey('A', $statsByTag['groups']);
        $this->assertArrayHasKey('B', $statsByTag['groups']);
        $this->assertEquals(2, $statsByTag['groups']['A']['count']);
        $this->assertEquals(1, $statsByTag['groups']['B']['count']);
        $this->assertEquals(150.0, $statsByTag['groups']['A']['avg']);
    }

    #[Test]
    public function testGetStatsByTagWithUnknownTag(): void
    {
        $this->aggregator->addDataPoint('unknown.tag', 100.0); // 没有标签

        $statsByTag = $this->aggregator->getStatsByTag('unknown.tag', 'type');

        $this->assertArrayHasKey('unknown', $statsByTag['groups']);
    }

    // ========== 速率和吞吐量测试 ==========

    #[Test]
    public function testGetRate(): void
    {
        // 添加数据点
        for ($i = 0; $i < 10; $i++) {
            $this->aggregator->addDataPoint('rate.test', 1.0);
        }

        $rate = $this->aggregator->getRate('rate.test', 60);

        $this->assertGreaterThan(0, $rate);
    }

    #[Test]
    public function testGetThroughput(): void
    {
        // 添加实例和节点数据
        for ($i = 0; $i < 5; $i++) {
            $this->aggregator->addDataPoint('instance.duration', 100.0);
            $this->aggregator->addDataPoint('node.duration', 10.0);
            $this->aggregator->addDataPoint('node.duration', 20.0);
        }

        $throughput = $this->aggregator->getThroughput(60);

        $this->assertArrayHasKey('instances_per_second', $throughput);
        $this->assertArrayHasKey('nodes_per_second', $throughput);
        $this->assertArrayHasKey('window_seconds', $throughput);
    }

    // ========== 错误率测试 ==========

    #[Test]
    public function testGetErrorRate(): void
    {
        // 80% 成功率 = 20% 错误率
        $this->aggregator->addDataPoint('instance.success_rate', 100.0);
        $this->aggregator->addDataPoint('instance.success_rate', 100.0);
        $this->aggregator->addDataPoint('instance.success_rate', 100.0);
        $this->aggregator->addDataPoint('instance.success_rate', 100.0);
        $this->aggregator->addDataPoint('instance.success_rate', 0.0); // 失败

        $errorRate = $this->aggregator->getErrorRate(60);

        $this->assertEquals(20.0, $errorRate);
    }

    #[Test]
    public function testGetErrorRateWithNoData(): void
    {
        $errorRate = $this->aggregator->getErrorRate(60);

        $this->assertEquals(0, $errorRate);
    }

    // ========== 健康状态测试 ==========

    #[Test]
    public function testGetHealthStatusHealthy(): void
    {
        // 添加健康的指标
        for ($i = 0; $i < 10; $i++) {
            $this->aggregator->addDataPoint('instance.success_rate', 100.0);
            $this->aggregator->addDataPoint('instance.duration', 1000.0); // 1秒
        }

        $health = $this->aggregator->getHealthStatus();

        $this->assertEquals('healthy', $health['status']);
        $this->assertEmpty($health['issues']);
    }

    #[Test]
    public function testGetHealthStatusWarning(): void
    {
        // 添加 6% 错误率的指标（超过 5% 警告阈值）
        for ($i = 0; $i < 94; $i++) {
            $this->aggregator->addDataPoint('instance.success_rate', 100.0);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->aggregator->addDataPoint('instance.success_rate', 0.0);
        }

        $health = $this->aggregator->getHealthStatus();

        $this->assertEquals('warning', $health['status']);
        $this->assertNotEmpty($health['issues']);
    }

    #[Test]
    public function testGetHealthStatusCritical(): void
    {
        // 添加 15% 错误率的指标（超过 10% 严重阈值）
        for ($i = 0; $i < 85; $i++) {
            $this->aggregator->addDataPoint('instance.success_rate', 100.0);
        }
        for ($i = 0; $i < 15; $i++) {
            $this->aggregator->addDataPoint('instance.success_rate', 0.0);
        }

        $health = $this->aggregator->getHealthStatus();

        $this->assertEquals('critical', $health['status']);
    }

    #[Test]
    public function testGetHealthStatusWithCustomThresholds(): void
    {
        // 添加 3% 错误率
        for ($i = 0; $i < 97; $i++) {
            $this->aggregator->addDataPoint('instance.success_rate', 100.0);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->aggregator->addDataPoint('instance.success_rate', 0.0);
        }

        // 使用更严格的阈值
        $health = $this->aggregator->getHealthStatus([
            'error_rate_warning' => 2,
            'error_rate_critical' => 5,
        ]);

        $this->assertEquals('warning', $health['status']);
    }

    // ========== 仪表盘数据测试 ==========

    #[Test]
    public function testGetDashboardData(): void
    {
        // 添加一些测试数据
        for ($i = 0; $i < 10; $i++) {
            $this->aggregator->addDataPoint('instance.duration', 100.0 + $i * 10, ['definition_id' => 'def-001']);
            $this->aggregator->addDataPoint('instance.success_rate', 100.0);
            $this->aggregator->addDataPoint('node.duration', 50.0, ['node_type' => 'task']);
        }

        $dashboard = $this->aggregator->getDashboardData(3600);

        $this->assertArrayHasKey('health', $dashboard);
        $this->assertArrayHasKey('throughput', $dashboard);
        $this->assertArrayHasKey('duration_stats', $dashboard);
        $this->assertArrayHasKey('success_rate_stats', $dashboard);
        $this->assertArrayHasKey('by_definition', $dashboard);
        $this->assertArrayHasKey('by_node_type', $dashboard);
        $this->assertArrayHasKey('duration_series', $dashboard);
        $this->assertArrayHasKey('error_rate', $dashboard);
    }

    // ========== 清理测试 ==========

    #[Test]
    public function testClear(): void
    {
        $this->aggregator->addDataPoint('test.metric', 100.0);

        $statsBefore = $this->aggregator->getStats('test.metric');
        $this->assertEquals(1, $statsBefore['count']);

        $this->aggregator->clear();

        $statsAfter = $this->aggregator->getStats('test.metric');
        $this->assertEquals(0, $statsAfter['count']);
    }

    // ========== 常量测试 ==========

    #[Test]
    public function testWindowConstants(): void
    {
        $this->assertEquals(60, MetricsAggregator::WINDOW_MINUTE);
        $this->assertEquals(3600, MetricsAggregator::WINDOW_HOUR);
        $this->assertEquals(86400, MetricsAggregator::WINDOW_DAY);
        $this->assertEquals(604800, MetricsAggregator::WINDOW_WEEK);
    }
}
