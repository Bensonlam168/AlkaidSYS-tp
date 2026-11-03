<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Monitor\Metrics;

/**
 * 指标聚合器
 *
 * 聚合多个工作流实例的性能指标，提供统计分析。
 *
 * @package Alkaid\Workflow\Engine\Monitor\Metrics
 */
class MetricsAggregator
{
    /**
     * 时间窗口常量
     */
    public const WINDOW_MINUTE = 60;
    public const WINDOW_HOUR = 3600;
    public const WINDOW_DAY = 86400;
    public const WINDOW_WEEK = 604800;

    /**
     * @var array 指标数据点
     */
    private array $dataPoints = [];

    /**
     * @var int 数据保留时间（秒）
     */
    private int $retentionPeriod;

    /**
     * 构造函数
     *
     * @param int $retentionPeriod 数据保留时间（秒）
     */
    public function __construct(int $retentionPeriod = 86400)
    {
        $this->retentionPeriod = $retentionPeriod;
    }

    /**
     * 添加指标数据点
     *
     * @param string $metricName 指标名称
     * @param float $value 指标值
     * @param array $tags 标签
     * @return void
     */
    public function addDataPoint(string $metricName, float $value, array $tags = []): void
    {
        $timestamp = microtime(true);

        if (!isset($this->dataPoints[$metricName])) {
            $this->dataPoints[$metricName] = [];
        }

        $this->dataPoints[$metricName][] = [
            'value' => $value,
            'timestamp' => $timestamp,
            'tags' => $tags,
        ];

        // 清理过期数据
        $this->cleanupExpiredData($metricName);
    }

    /**
     * 添加实例完成指标
     *
     * @param ExecutionMetrics $metrics 实例指标
     * @return void
     */
    public function addInstanceMetrics(ExecutionMetrics $metrics): void
    {
        $summary = $metrics->getSummary();
        $tags = [
            'definition_id' => $summary['definition_id'],
        ];

        $this->addDataPoint('instance.duration', $summary['total_duration_ms'], $tags);
        $this->addDataPoint('instance.node_count', (float) $summary['node_count'], $tags);
        $this->addDataPoint('instance.success_rate', $summary['success_rate'], $tags);
        $this->addDataPoint('instance.retry_count', (float) $summary['retry_count'], $tags);
        $this->addDataPoint('instance.error_count', (float) $summary['error_count'], $tags);

        // 节点级别指标
        foreach ($metrics->getNodeMetrics() as $nodeMetric) {
            if ($nodeMetric['duration'] !== null) {
                $nodeTags = array_merge($tags, [
                    'node_type' => $nodeMetric['node_type'],
                ]);
                $this->addDataPoint('node.duration', $nodeMetric['duration'] * 1000, $nodeTags);
            }
        }
    }

    /**
     * 获取指标统计
     *
     * @param string $metricName 指标名称
     * @param array $options 选项
     * @return array
     */
    public function getStats(string $metricName, array $options = []): array
    {
        $data = $this->getFilteredData($metricName, $options);

        if (empty($data)) {
            return [
                'metric' => $metricName,
                'count' => 0,
                'sum' => 0,
                'avg' => 0,
                'min' => 0,
                'max' => 0,
                'stddev' => 0,
                'percentiles' => [],
            ];
        }

        $values = array_column($data, 'value');
        sort($values);

        $count = count($values);
        $sum = array_sum($values);
        $avg = $sum / $count;
        $min = min($values);
        $max = max($values);

        // 计算标准差
        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $avg, 2);
        }
        $stddev = sqrt($variance / $count);

        // 计算百分位数
        $percentiles = [
            'p50' => $this->percentile($values, 50),
            'p75' => $this->percentile($values, 75),
            'p90' => $this->percentile($values, 90),
            'p95' => $this->percentile($values, 95),
            'p99' => $this->percentile($values, 99),
        ];

        return [
            'metric' => $metricName,
            'count' => $count,
            'sum' => round($sum, 2),
            'avg' => round($avg, 2),
            'min' => round($min, 2),
            'max' => round($max, 2),
            'stddev' => round($stddev, 2),
            'percentiles' => $percentiles,
        ];
    }

    /**
     * 获取时间序列数据
     *
     * @param string $metricName 指标名称
     * @param int $window 时间窗口（秒）
     * @param int $bucketSize 桶大小（秒）
     * @param array $options 选项
     * @return array
     */
    public function getTimeSeries(
        string $metricName,
        int $window = 3600,
        int $bucketSize = 60,
        array $options = []
    ): array {
        $now = microtime(true);
        $startTime = $now - $window;

        $options['start_time'] = $startTime;
        $options['end_time'] = $now;

        $data = $this->getFilteredData($metricName, $options);
        $buckets = [];

        // 初始化桶
        for ($t = $startTime; $t < $now; $t += $bucketSize) {
            $bucketKey = (int) ($t / $bucketSize) * $bucketSize;
            $buckets[$bucketKey] = [
                'timestamp' => $bucketKey,
                'values' => [],
            ];
        }

        // 将数据分配到桶
        foreach ($data as $point) {
            $bucketKey = (int) ($point['timestamp'] / $bucketSize) * $bucketSize;
            if (isset($buckets[$bucketKey])) {
                $buckets[$bucketKey]['values'][] = $point['value'];
            }
        }

        // 计算每个桶的聚合值
        $aggregation = $options['aggregation'] ?? 'avg';
        $series = [];

        foreach ($buckets as $bucket) {
            $values = $bucket['values'];
            $value = empty($values) ? null : match ($aggregation) {
                'sum' => array_sum($values),
                'avg' => array_sum($values) / count($values),
                'min' => min($values),
                'max' => max($values),
                'count' => (float) count($values),
                default => array_sum($values) / count($values),
            };

            $series[] = [
                'timestamp' => $bucket['timestamp'],
                'datetime' => date('Y-m-d H:i:s', (int) $bucket['timestamp']),
                'value' => $value !== null ? round($value, 2) : null,
            ];
        }

        return [
            'metric' => $metricName,
            'window' => $window,
            'bucket_size' => $bucketSize,
            'aggregation' => $aggregation,
            'series' => $series,
        ];
    }

    /**
     * 获取按标签分组的统计
     *
     * @param string $metricName 指标名称
     * @param string $groupByTag 分组标签
     * @param array $options 选项
     * @return array
     */
    public function getStatsByTag(string $metricName, string $groupByTag, array $options = []): array
    {
        $data = $this->getFilteredData($metricName, $options);
        $groups = [];

        foreach ($data as $point) {
            $tagValue = $point['tags'][$groupByTag] ?? 'unknown';
            if (!isset($groups[$tagValue])) {
                $groups[$tagValue] = [];
            }
            $groups[$tagValue][] = $point;
        }

        $result = [];
        foreach ($groups as $tagValue => $groupData) {
            $values = array_column($groupData, 'value');
            $count = count($values);

            $result[$tagValue] = [
                'tag_value' => $tagValue,
                'count' => $count,
                'sum' => round(array_sum($values), 2),
                'avg' => round(array_sum($values) / $count, 2),
                'min' => round(min($values), 2),
                'max' => round(max($values), 2),
            ];
        }

        return [
            'metric' => $metricName,
            'group_by' => $groupByTag,
            'groups' => $result,
        ];
    }

    /**
     * 获取速率（每秒）
     *
     * @param string $metricName 指标名称
     * @param int $window 时间窗口（秒）
     * @param array $options 选项
     * @return float
     */
    public function getRate(string $metricName, int $window = 60, array $options = []): float
    {
        $now = microtime(true);
        $options['start_time'] = $now - $window;
        $options['end_time'] = $now;

        $data = $this->getFilteredData($metricName, $options);
        $count = count($data);

        return $window > 0 ? $count / $window : 0;
    }

    /**
     * 获取吞吐量
     *
     * @param int $window 时间窗口（秒）
     * @param array $options 选项
     * @return array
     */
    public function getThroughput(int $window = 60, array $options = []): array
    {
        return [
            'instances_per_second' => round($this->getRate('instance.duration', $window, $options), 2),
            'nodes_per_second' => round($this->getRate('node.duration', $window, $options), 2),
            'window_seconds' => $window,
        ];
    }

    /**
     * 获取错误率
     *
     * @param int $window 时间窗口（秒）
     * @param array $options 选项
     * @return float 错误率 (0-100)
     */
    public function getErrorRate(int $window = 60, array $options = []): float
    {
        $now = microtime(true);
        $options['start_time'] = $now - $window;
        $options['end_time'] = $now;

        $successData = $this->getFilteredData('instance.success_rate', $options);

        if (empty($successData)) {
            return 0;
        }

        $totalSuccessRate = array_sum(array_column($successData, 'value'));
        $avgSuccessRate = $totalSuccessRate / count($successData);

        return 100 - $avgSuccessRate;
    }

    /**
     * 获取健康状态
     *
     * @param array $thresholds 阈值配置
     * @return array
     */
    public function getHealthStatus(array $thresholds = []): array
    {
        $defaultThresholds = [
            'error_rate_warning' => 5,
            'error_rate_critical' => 10,
            'avg_duration_warning' => 5000, // 5秒
            'avg_duration_critical' => 10000, // 10秒
        ];

        $thresholds = array_merge($defaultThresholds, $thresholds);

        $errorRate = $this->getErrorRate(300); // 5分钟窗口
        $durationStats = $this->getStats('instance.duration', [
            'start_time' => microtime(true) - 300,
        ]);

        $status = 'healthy';
        $issues = [];

        // 检查错误率
        if ($errorRate >= $thresholds['error_rate_critical']) {
            $status = 'critical';
            $issues[] = "Error rate is critical: {$errorRate}%";
        } elseif ($errorRate >= $thresholds['error_rate_warning']) {
            $status = $status === 'healthy' ? 'warning' : $status;
            $issues[] = "Error rate is elevated: {$errorRate}%";
        }

        // 检查平均响应时间
        if ($durationStats['avg'] >= $thresholds['avg_duration_critical']) {
            $status = 'critical';
            $issues[] = "Average duration is critical: {$durationStats['avg']}ms";
        } elseif ($durationStats['avg'] >= $thresholds['avg_duration_warning']) {
            $status = $status === 'healthy' ? 'warning' : $status;
            $issues[] = "Average duration is elevated: {$durationStats['avg']}ms";
        }

        return [
            'status' => $status,
            'error_rate' => round($errorRate, 2),
            'avg_duration_ms' => round($durationStats['avg'], 2),
            'issues' => $issues,
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 获取仪表盘数据
     *
     * @param int $window 时间窗口（秒）
     * @return array
     */
    public function getDashboardData(int $window = 3600): array
    {
        $options = ['start_time' => microtime(true) - $window];

        return [
            'health' => $this->getHealthStatus(),
            'throughput' => $this->getThroughput($window),
            'duration_stats' => $this->getStats('instance.duration', $options),
            'success_rate_stats' => $this->getStats('instance.success_rate', $options),
            'by_definition' => $this->getStatsByTag('instance.duration', 'definition_id', $options),
            'by_node_type' => $this->getStatsByTag('node.duration', 'node_type', $options),
            'duration_series' => $this->getTimeSeries('instance.duration', $window, 60),
            'error_rate' => $this->getErrorRate($window),
        ];
    }

    /**
     * 清空所有数据
     *
     * @return void
     */
    public function clear(): void
    {
        $this->dataPoints = [];
    }

    // ========== 私有方法 ==========

    /**
     * 获取过滤后的数据
     *
     * @param string $metricName 指标名称
     * @param array $options 选项
     * @return array
     */
    private function getFilteredData(string $metricName, array $options = []): array
    {
        if (!isset($this->dataPoints[$metricName])) {
            return [];
        }

        $data = $this->dataPoints[$metricName];

        // 时间过滤
        if (isset($options['start_time'])) {
            $data = array_filter($data, fn ($p) => $p['timestamp'] >= $options['start_time']);
        }
        if (isset($options['end_time'])) {
            $data = array_filter($data, fn ($p) => $p['timestamp'] <= $options['end_time']);
        }

        // 标签过滤
        if (isset($options['tags'])) {
            foreach ($options['tags'] as $key => $value) {
                $data = array_filter($data, fn ($p) => ($p['tags'][$key] ?? null) === $value);
            }
        }

        return array_values($data);
    }

    /**
     * 清理过期数据
     *
     * @param string $metricName 指标名称
     * @return void
     */
    private function cleanupExpiredData(string $metricName): void
    {
        if (!isset($this->dataPoints[$metricName])) {
            return;
        }

        $cutoff = microtime(true) - $this->retentionPeriod;

        $this->dataPoints[$metricName] = array_filter(
            $this->dataPoints[$metricName],
            fn ($p) => $p['timestamp'] >= $cutoff
        );
    }

    /**
     * 计算百分位数
     *
     * @param array $sortedValues 已排序的值数组
     * @param float $percentile 百分位数 (0-100)
     * @return float
     */
    private function percentile(array $sortedValues, float $percentile): float
    {
        if (empty($sortedValues)) {
            return 0;
        }

        $count = count($sortedValues);
        $index = ($percentile / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $fraction = $index - $lower;

        if ($lower === $upper || $upper >= $count) {
            return round($sortedValues[$lower], 2);
        }

        return round(
            $sortedValues[$lower] * (1 - $fraction) + $sortedValues[$upper] * $fraction,
            2
        );
    }
}
