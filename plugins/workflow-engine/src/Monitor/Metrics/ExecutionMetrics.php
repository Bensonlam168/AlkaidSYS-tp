<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Monitor\Metrics;

/**
 * 执行指标收集器
 *
 * 收集和计算工作流执行的性能指标。
 *
 * @package Alkaid\Workflow\Engine\Monitor\Metrics
 */
class ExecutionMetrics
{
    /**
     * 指标类型常量
     */
    public const METRIC_DURATION = 'duration';
    public const METRIC_NODE_COUNT = 'node_count';
    public const METRIC_ERROR_COUNT = 'error_count';
    public const METRIC_RETRY_COUNT = 'retry_count';
    public const METRIC_MEMORY_USAGE = 'memory_usage';
    public const METRIC_CPU_TIME = 'cpu_time';

    private string $instanceId;
    private ?string $definitionId;
    private float $startTime;
    private ?float $endTime = null;
    private array $nodeMetrics = [];
    private array $errorMetrics = [];
    private array $customMetrics = [];
    private int $nodeCount = 0;
    private int $completedNodeCount = 0;
    private int $failedNodeCount = 0;
    private int $retryCount = 0;
    private float $peakMemoryUsage = 0;

    /**
     * 构造函数
     *
     * @param string $instanceId 实例 ID
     * @param string|null $definitionId 定义 ID
     */
    public function __construct(string $instanceId, ?string $definitionId = null)
    {
        $this->instanceId = $instanceId;
        $this->definitionId = $definitionId;
        $this->startTime = microtime(true);
        $this->updateMemoryUsage();
    }

    /**
     * 记录节点开始执行
     *
     * @param string $nodeId 节点 ID
     * @param string $nodeType 节点类型
     * @return void
     */
    public function recordNodeStart(string $nodeId, string $nodeType): void
    {
        $this->nodeMetrics[$nodeId] = [
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'start_time' => microtime(true),
            'end_time' => null,
            'duration' => null,
            'status' => 'running',
            'retry_count' => 0,
            'memory_before' => memory_get_usage(true),
            'memory_after' => null,
            'error' => null,
        ];
        $this->nodeCount++;
        $this->updateMemoryUsage();
    }

    /**
     * 记录节点执行完成
     *
     * @param string $nodeId 节点 ID
     * @param string $status 状态 (success, failed, skipped)
     * @param array $options 选项
     * @return void
     */
    public function recordNodeEnd(string $nodeId, string $status, array $options = []): void
    {
        if (!isset($this->nodeMetrics[$nodeId])) {
            return;
        }

        $endTime = microtime(true);
        $this->nodeMetrics[$nodeId]['end_time'] = $endTime;
        $this->nodeMetrics[$nodeId]['duration'] = $endTime - $this->nodeMetrics[$nodeId]['start_time'];
        $this->nodeMetrics[$nodeId]['status'] = $status;
        $this->nodeMetrics[$nodeId]['memory_after'] = memory_get_usage(true);

        if ($status === 'success' || $status === 'completed') {
            $this->completedNodeCount++;
        } elseif ($status === 'failed' || $status === 'error') {
            $this->failedNodeCount++;
            if (isset($options['error'])) {
                $this->nodeMetrics[$nodeId]['error'] = $options['error'];
                $this->recordError($nodeId, $options['error']);
            }
        }

        $this->updateMemoryUsage();
    }

    /**
     * 记录节点重试
     *
     * @param string $nodeId 节点 ID
     * @return void
     */
    public function recordNodeRetry(string $nodeId): void
    {
        if (isset($this->nodeMetrics[$nodeId])) {
            $this->nodeMetrics[$nodeId]['retry_count']++;
        }
        $this->retryCount++;
    }

    /**
     * 记录错误
     *
     * @param string $nodeId 节点 ID
     * @param array $error 错误信息
     * @return void
     */
    public function recordError(string $nodeId, array $error): void
    {
        $this->errorMetrics[] = [
            'node_id' => $nodeId,
            'error_code' => $error['code'] ?? 'UNKNOWN',
            'error_message' => $error['message'] ?? 'Unknown error',
            'timestamp' => microtime(true),
        ];
    }

    /**
     * 记录自定义指标
     *
     * @param string $name 指标名称
     * @param mixed $value 指标值
     * @param array $tags 标签
     * @return void
     */
    public function recordCustomMetric(string $name, mixed $value, array $tags = []): void
    {
        $this->customMetrics[$name] = [
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true),
        ];
    }

    /**
     * 完成指标收集
     *
     * @return void
     */
    public function finish(): void
    {
        $this->endTime = microtime(true);
        $this->updateMemoryUsage();
    }

    /**
     * 获取总执行时长（秒）
     *
     * @return float
     */
    public function getTotalDuration(): float
    {
        $endTime = $this->endTime ?? microtime(true);
        return $endTime - $this->startTime;
    }

    /**
     * 获取总执行时长（毫秒）
     *
     * @return float
     */
    public function getTotalDurationMs(): float
    {
        return $this->getTotalDuration() * 1000;
    }

    /**
     * 获取平均节点执行时长（秒）
     *
     * @return float
     */
    public function getAverageNodeDuration(): float
    {
        $totalDuration = 0;
        $count = 0;

        foreach ($this->nodeMetrics as $metric) {
            if ($metric['duration'] !== null) {
                $totalDuration += $metric['duration'];
                $count++;
            }
        }

        return $count > 0 ? $totalDuration / $count : 0;
    }

    /**
     * 获取最慢的节点
     *
     * @param int $limit 数量限制
     * @return array
     */
    public function getSlowestNodes(int $limit = 5): array
    {
        $nodes = array_filter($this->nodeMetrics, fn ($m) => $m['duration'] !== null);
        usort($nodes, fn ($a, $b) => $b['duration'] <=> $a['duration']);
        return array_slice($nodes, 0, $limit);
    }

    /**
     * 获取失败的节点
     *
     * @return array
     */
    public function getFailedNodes(): array
    {
        return array_filter(
            $this->nodeMetrics,
            fn ($m) => in_array($m['status'], ['failed', 'error'])
        );
    }

    /**
     * 获取成功率
     *
     * @return float 0-100
     */
    public function getSuccessRate(): float
    {
        if ($this->nodeCount === 0) {
            return 100.0;
        }
        return ($this->completedNodeCount / $this->nodeCount) * 100;
    }

    /**
     * 获取节点吞吐量（节点/秒）
     *
     * @return float
     */
    public function getNodeThroughput(): float
    {
        $duration = $this->getTotalDuration();
        return $duration > 0 ? $this->completedNodeCount / $duration : 0;
    }

    /**
     * 获取内存使用统计
     *
     * @return array
     */
    public function getMemoryStats(): array
    {
        $memoryUsages = [];
        foreach ($this->nodeMetrics as $metric) {
            if ($metric['memory_after'] !== null) {
                $memoryUsages[] = $metric['memory_after'] - $metric['memory_before'];
            }
        }

        return [
            'peak_usage' => $this->peakMemoryUsage,
            'peak_usage_mb' => round($this->peakMemoryUsage / 1024 / 1024, 2),
            'current_usage' => memory_get_usage(true),
            'current_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'node_avg_usage' => count($memoryUsages) > 0 ? array_sum($memoryUsages) / count($memoryUsages) : 0,
        ];
    }

    /**
     * 更新内存使用峰值
     *
     * @return void
     */
    private function updateMemoryUsage(): void
    {
        $current = memory_get_usage(true);
        if ($current > $this->peakMemoryUsage) {
            $this->peakMemoryUsage = $current;
        }
    }

    /**
     * 获取摘要
     *
     * @return array
     */
    public function getSummary(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'definition_id' => $this->definitionId,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'total_duration_ms' => $this->getTotalDurationMs(),
            'total_duration_s' => round($this->getTotalDuration(), 3),
            'node_count' => $this->nodeCount,
            'completed_node_count' => $this->completedNodeCount,
            'failed_node_count' => $this->failedNodeCount,
            'retry_count' => $this->retryCount,
            'success_rate' => round($this->getSuccessRate(), 2),
            'avg_node_duration_ms' => round($this->getAverageNodeDuration() * 1000, 2),
            'throughput_nodes_per_sec' => round($this->getNodeThroughput(), 2),
            'error_count' => count($this->errorMetrics),
            'memory' => $this->getMemoryStats(),
        ];
    }

    /**
     * 获取详细指标
     *
     * @return array
     */
    public function getDetailedMetrics(): array
    {
        return [
            'summary' => $this->getSummary(),
            'node_metrics' => $this->nodeMetrics,
            'error_metrics' => $this->errorMetrics,
            'custom_metrics' => $this->customMetrics,
            'slowest_nodes' => $this->getSlowestNodes(),
            'failed_nodes' => array_values($this->getFailedNodes()),
        ];
    }

    /**
     * 转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->getDetailedMetrics();
    }

    // ========== Getters ==========

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function getDefinitionId(): ?string
    {
        return $this->definitionId;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getEndTime(): ?float
    {
        return $this->endTime;
    }

    public function getNodeMetrics(): array
    {
        return $this->nodeMetrics;
    }

    public function getErrorMetrics(): array
    {
        return $this->errorMetrics;
    }

    public function getCustomMetrics(): array
    {
        return $this->customMetrics;
    }

    public function getNodeCount(): int
    {
        return $this->nodeCount;
    }

    public function getCompletedNodeCount(): int
    {
        return $this->completedNodeCount;
    }

    public function getFailedNodeCount(): int
    {
        return $this->failedNodeCount;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getPeakMemoryUsage(): float
    {
        return $this->peakMemoryUsage;
    }
}
