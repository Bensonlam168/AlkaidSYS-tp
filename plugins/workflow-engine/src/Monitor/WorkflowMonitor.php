<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Monitor;

use Alkaid\Workflow\Engine\Monitor\Interfaces\WorkflowMonitorInterface;
use Alkaid\Workflow\Engine\Monitor\Metrics\ExecutionMetrics;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;

/**
 * 工作流监控器
 *
 * 提供工作流执行的完整监控功能，包括：
 * - 实例状态跟踪
 * - 节点执行记录
 * - 性能指标收集
 * - 实时事件推送
 *
 * @package Alkaid\Workflow\Engine\Monitor
 */
class WorkflowMonitor implements WorkflowMonitorInterface
{
    /**
     * 实例状态常量
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var array<string, array> 活跃实例状态
     */
    private array $activeInstances = [];

    /**
     * @var array<string, ExecutionMetrics> 实例指标
     */
    private array $instanceMetrics = [];

    /**
     * @var array<string, ExecutionLogEntry[]> 实例日志
     */
    private array $instanceLogs = [];

    /**
     * @var array<string, array> 节点执行记录
     */
    private array $nodeExecutions = [];

    /**
     * @var array<string, callable[]> 事件订阅
     */
    private array $subscriptions = [];

    /**
     * @var int 最大日志条目数（每个实例）
     */
    private int $maxLogsPerInstance = 1000;

    /**
     * @var bool 是否启用详细日志
     */
    private bool $verboseLogging = true;

    /**
     * @var bool 是否启用指标收集
     */
    private bool $metricsEnabled = true;

    /**
     * 构造函数
     *
     * @param array $config 配置选项
     */
    public function __construct(array $config = [])
    {
        $this->maxLogsPerInstance = $config['max_logs_per_instance'] ?? 1000;
        $this->verboseLogging = $config['verbose_logging'] ?? true;
        $this->metricsEnabled = $config['metrics_enabled'] ?? true;
    }

    // ========== 实例监控 ==========

    /**
     * @inheritDoc
     */
    public function startMonitoring(string $instanceId, array $metadata = []): void
    {
        $tenantId = TenantContext::getTenantId();

        $this->activeInstances[$instanceId] = [
            'instance_id' => $instanceId,
            'tenant_id' => $tenantId,
            'definition_id' => $metadata['definition_id'] ?? null,
            'status' => self::STATUS_RUNNING,
            'started_at' => microtime(true),
            'updated_at' => microtime(true),
            'current_node' => null,
            'metadata' => $metadata,
        ];

        // 初始化指标收集器
        if ($this->metricsEnabled) {
            $this->instanceMetrics[$instanceId] = new ExecutionMetrics(
                $instanceId,
                $metadata['definition_id'] ?? null
            );
        }

        // 初始化日志
        $this->instanceLogs[$instanceId] = [];

        // 记录启动日志
        $this->addLog(ExecutionLogEntry::instanceStarted(
            $instanceId,
            $metadata['definition_id'] ?? 'unknown',
            $metadata['variables'] ?? [],
            ['tenant_id' => $tenantId]
        ));

        // 推送事件
        $this->pushEvent($instanceId, 'instance.started', [
            'instance_id' => $instanceId,
            'definition_id' => $metadata['definition_id'] ?? null,
            'started_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function stopMonitoring(string $instanceId): void
    {
        if (!isset($this->activeInstances[$instanceId])) {
            return;
        }

        $instance = $this->activeInstances[$instanceId];

        // 完成指标收集
        if (isset($this->instanceMetrics[$instanceId])) {
            $this->instanceMetrics[$instanceId]->finish();
        }

        // 记录完成日志
        $duration = microtime(true) - $instance['started_at'];
        $this->addLog(ExecutionLogEntry::instanceCompleted(
            $instanceId,
            ['status' => $instance['status']],
            $duration,
            ['tenant_id' => $instance['tenant_id']]
        ));

        // 更新状态
        if ($instance['status'] === self::STATUS_RUNNING) {
            $this->activeInstances[$instanceId]['status'] = self::STATUS_COMPLETED;
        }

        $this->activeInstances[$instanceId]['completed_at'] = microtime(true);
        $this->activeInstances[$instanceId]['updated_at'] = microtime(true);

        // 推送事件
        $this->pushEvent($instanceId, 'instance.stopped', [
            'instance_id' => $instanceId,
            'status' => $this->activeInstances[$instanceId]['status'],
            'duration' => $duration,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getInstanceStatus(string $instanceId): array
    {
        if (!isset($this->activeInstances[$instanceId])) {
            return [
                'instance_id' => $instanceId,
                'status' => 'unknown',
                'error' => 'Instance not found in monitor',
            ];
        }

        $instance = $this->activeInstances[$instanceId];
        $metrics = $this->instanceMetrics[$instanceId] ?? null;

        return [
            'instance_id' => $instanceId,
            'tenant_id' => $instance['tenant_id'],
            'definition_id' => $instance['definition_id'],
            'status' => $instance['status'],
            'started_at' => $instance['started_at'],
            'updated_at' => $instance['updated_at'],
            'completed_at' => $instance['completed_at'] ?? null,
            'current_node' => $instance['current_node'],
            'duration_ms' => $metrics ? $metrics->getTotalDurationMs() : null,
            'node_count' => $metrics ? $metrics->getNodeCount() : 0,
            'completed_nodes' => $metrics ? $metrics->getCompletedNodeCount() : 0,
            'failed_nodes' => $metrics ? $metrics->getFailedNodeCount() : 0,
            'success_rate' => $metrics ? $metrics->getSuccessRate() : 100,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getActiveInstances(?int $tenantId = null): array
    {
        $instances = [];

        foreach ($this->activeInstances as $instanceId => $instance) {
            if ($instance['status'] !== self::STATUS_RUNNING &&
                $instance['status'] !== self::STATUS_SUSPENDED) {
                continue;
            }

            if ($tenantId !== null && $instance['tenant_id'] !== $tenantId) {
                continue;
            }

            $instances[] = $this->getInstanceStatus($instanceId);
        }

        return $instances;
    }

    /**
     * 更新实例状态
     *
     * @param string $instanceId 实例 ID
     * @param string $status 新状态
     * @param array $options 选项
     * @return void
     */
    public function updateInstanceStatus(string $instanceId, string $status, array $options = []): void
    {
        if (!isset($this->activeInstances[$instanceId])) {
            return;
        }

        $oldStatus = $this->activeInstances[$instanceId]['status'];
        $this->activeInstances[$instanceId]['status'] = $status;
        $this->activeInstances[$instanceId]['updated_at'] = microtime(true);

        if (isset($options['current_node'])) {
            $this->activeInstances[$instanceId]['current_node'] = $options['current_node'];
        }

        // 特殊状态处理
        if ($status === self::STATUS_FAILED) {
            $this->addLog(ExecutionLogEntry::instanceFailed(
                $instanceId,
                $options['error_code'] ?? 'UNKNOWN',
                $options['error_message'] ?? 'Unknown error',
                ['tenant_id' => $this->activeInstances[$instanceId]['tenant_id']]
            ));
        }

        // 推送状态变更事件
        $this->pushEvent($instanceId, 'instance.status_changed', [
            'old_status' => $oldStatus,
            'new_status' => $status,
            'options' => $options,
        ]);
    }

    // ========== 节点监控 ==========

    /**
     * @inheritDoc
     */
    public function recordNodeStart(
        string $instanceId,
        string $nodeId,
        string $nodeType,
        array $context = []
    ): string {
        $executionId = $this->generateExecutionId($instanceId, $nodeId);

        $this->nodeExecutions[$executionId] = [
            'execution_id' => $executionId,
            'instance_id' => $instanceId,
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'status' => 'running',
            'started_at' => microtime(true),
            'ended_at' => null,
            'duration' => null,
            'context' => $context,
            'result' => null,
            'error' => null,
        ];

        // 更新当前节点
        if (isset($this->activeInstances[$instanceId])) {
            $this->activeInstances[$instanceId]['current_node'] = $nodeId;
            $this->activeInstances[$instanceId]['updated_at'] = microtime(true);
        }

        // 记录指标
        if (isset($this->instanceMetrics[$instanceId])) {
            $this->instanceMetrics[$instanceId]->recordNodeStart($nodeId, $nodeType);
        }

        // 记录日志
        if ($this->verboseLogging) {
            $this->addLog(ExecutionLogEntry::nodeEntered(
                $instanceId,
                $nodeId,
                $nodeType,
                ['tenant_id' => $this->activeInstances[$instanceId]['tenant_id'] ?? null]
            ));
        }

        // 推送事件
        $this->pushEvent($instanceId, 'node.started', [
            'execution_id' => $executionId,
            'node_id' => $nodeId,
            'node_type' => $nodeType,
        ]);

        return $executionId;
    }

    /**
     * @inheritDoc
     */
    public function recordNodeComplete(
        string $executionId,
        string $status,
        array $result = []
    ): void {
        if (!isset($this->nodeExecutions[$executionId])) {
            return;
        }

        $execution = &$this->nodeExecutions[$executionId];
        $execution['status'] = $status;
        $execution['ended_at'] = microtime(true);
        $execution['duration'] = $execution['ended_at'] - $execution['started_at'];
        $execution['result'] = $result;

        $instanceId = $execution['instance_id'];
        $nodeId = $execution['node_id'];
        $nodeType = $execution['node_type'];

        // 记录指标
        if (isset($this->instanceMetrics[$instanceId])) {
            $this->instanceMetrics[$instanceId]->recordNodeEnd($nodeId, $status);
        }

        // 记录日志
        $this->addLog(ExecutionLogEntry::nodeCompleted(
            $instanceId,
            $nodeId,
            $nodeType,
            $result,
            $execution['duration'],
            ['tenant_id' => $this->activeInstances[$instanceId]['tenant_id'] ?? null]
        ));

        // 推送事件
        $this->pushEvent($instanceId, 'node.completed', [
            'execution_id' => $executionId,
            'node_id' => $nodeId,
            'status' => $status,
            'duration_ms' => $execution['duration'] * 1000,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function recordNodeError(
        string $executionId,
        string $errorCode,
        string $errorMessage,
        array $errorContext = []
    ): void {
        if (!isset($this->nodeExecutions[$executionId])) {
            return;
        }

        $execution = &$this->nodeExecutions[$executionId];
        $execution['status'] = 'failed';
        $execution['ended_at'] = microtime(true);
        $execution['duration'] = $execution['ended_at'] - $execution['started_at'];
        $execution['error'] = [
            'code' => $errorCode,
            'message' => $errorMessage,
            'context' => $errorContext,
        ];

        $instanceId = $execution['instance_id'];
        $nodeId = $execution['node_id'];
        $nodeType = $execution['node_type'];

        // 记录指标
        if (isset($this->instanceMetrics[$instanceId])) {
            $this->instanceMetrics[$instanceId]->recordNodeEnd($nodeId, 'failed', [
                'error' => $execution['error'],
            ]);
        }

        // 记录日志
        $this->addLog(ExecutionLogEntry::nodeFailed(
            $instanceId,
            $nodeId,
            $nodeType,
            $errorCode,
            $errorMessage,
            ['tenant_id' => $this->activeInstances[$instanceId]['tenant_id'] ?? null]
        ));

        // 推送事件
        $this->pushEvent($instanceId, 'node.failed', [
            'execution_id' => $executionId,
            'node_id' => $nodeId,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * 记录节点重试
     *
     * @param string $executionId 执行 ID
     * @param int $retryCount 重试次数
     * @param string $reason 重试原因
     * @return void
     */
    public function recordNodeRetry(string $executionId, int $retryCount, string $reason = ''): void
    {
        if (!isset($this->nodeExecutions[$executionId])) {
            return;
        }

        $execution = $this->nodeExecutions[$executionId];
        $instanceId = $execution['instance_id'];
        $nodeId = $execution['node_id'];

        // 记录指标
        if (isset($this->instanceMetrics[$instanceId])) {
            $this->instanceMetrics[$instanceId]->recordNodeRetry($nodeId);
        }

        // 推送事件
        $this->pushEvent($instanceId, 'node.retry', [
            'execution_id' => $executionId,
            'node_id' => $nodeId,
            'retry_count' => $retryCount,
            'reason' => $reason,
        ]);
    }

    // ========== 执行日志 ==========

    /**
     * @inheritDoc
     */
    public function getExecutionLogs(string $instanceId, array $options = []): array
    {
        if (!isset($this->instanceLogs[$instanceId])) {
            return [];
        }

        $logs = $this->instanceLogs[$instanceId];

        // 过滤日志级别
        if (isset($options['level'])) {
            $level = $options['level'];
            $logs = array_filter($logs, fn ($log) => $log->getLevel() === $level);
        }

        // 过滤事件类型
        if (isset($options['event_type'])) {
            $eventType = $options['event_type'];
            $logs = array_filter($logs, fn ($log) => $log->getEventType() === $eventType);
        }

        // 过滤节点
        if (isset($options['node_id'])) {
            $nodeId = $options['node_id'];
            $logs = array_filter($logs, fn ($log) => $log->getNodeId() === $nodeId);
        }

        // 时间范围过滤
        if (isset($options['start_time'])) {
            $startTime = $options['start_time'];
            $logs = array_filter($logs, fn ($log) => $log->getTimestamp() >= $startTime);
        }
        if (isset($options['end_time'])) {
            $endTime = $options['end_time'];
            $logs = array_filter($logs, fn ($log) => $log->getTimestamp() <= $endTime);
        }

        // 分页
        $offset = $options['offset'] ?? 0;
        $limit = $options['limit'] ?? 100;
        $logs = array_slice(array_values($logs), $offset, $limit);

        // 转换为数组
        return array_map(fn ($log) => $log->toArray(), $logs);
    }

    /**
     * @inheritDoc
     */
    public function getNodeHistory(string $instanceId, string $nodeId): array
    {
        $history = [];

        foreach ($this->nodeExecutions as $execution) {
            if ($execution['instance_id'] === $instanceId &&
                $execution['node_id'] === $nodeId) {
                $history[] = $execution;
            }
        }

        // 按开始时间排序
        usort($history, fn ($a, $b) => $a['started_at'] <=> $b['started_at']);

        return $history;
    }

    /**
     * 添加日志条目
     *
     * @param ExecutionLogEntry $entry 日志条目
     * @return void
     */
    public function addLog(ExecutionLogEntry $entry): void
    {
        $instanceId = $entry->getInstanceId();

        if (!isset($this->instanceLogs[$instanceId])) {
            $this->instanceLogs[$instanceId] = [];
        }

        $this->instanceLogs[$instanceId][] = $entry;

        // 限制日志数量
        if (count($this->instanceLogs[$instanceId]) > $this->maxLogsPerInstance) {
            array_shift($this->instanceLogs[$instanceId]);
        }

        // 触发 ThinkPHP 事件（用于持久化）
        $this->triggerThinkPHPEvent('workflow.monitor.log', $entry->toArray());
    }

    // ========== 性能指标 ==========

    /**
     * @inheritDoc
     */
    public function getInstanceMetrics(string $instanceId): array
    {
        if (!isset($this->instanceMetrics[$instanceId])) {
            return [
                'instance_id' => $instanceId,
                'error' => 'Metrics not found',
            ];
        }

        return $this->instanceMetrics[$instanceId]->getDetailedMetrics();
    }

    /**
     * @inheritDoc
     */
    public function getDefinitionMetrics(string $definitionId, array $options = []): array
    {
        $metrics = [];
        $durations = [];
        $successRates = [];
        $nodeCounts = [];

        foreach ($this->instanceMetrics as $instanceMetrics) {
            if ($instanceMetrics->getDefinitionId() !== $definitionId) {
                continue;
            }

            $summary = $instanceMetrics->getSummary();
            $durations[] = $summary['total_duration_ms'];
            $successRates[] = $summary['success_rate'];
            $nodeCounts[] = $summary['node_count'];
        }

        $count = count($durations);

        return [
            'definition_id' => $definitionId,
            'instance_count' => $count,
            'avg_duration_ms' => $count > 0 ? array_sum($durations) / $count : 0,
            'min_duration_ms' => $count > 0 ? min($durations) : 0,
            'max_duration_ms' => $count > 0 ? max($durations) : 0,
            'avg_success_rate' => $count > 0 ? array_sum($successRates) / $count : 100,
            'avg_node_count' => $count > 0 ? array_sum($nodeCounts) / $count : 0,
            'total_nodes_executed' => array_sum($nodeCounts),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getSystemMetrics(?int $tenantId = null): array
    {
        $activeCount = 0;
        $completedCount = 0;
        $failedCount = 0;
        $totalDuration = 0;
        $totalNodes = 0;

        foreach ($this->activeInstances as $instance) {
            if ($tenantId !== null && $instance['tenant_id'] !== $tenantId) {
                continue;
            }

            switch ($instance['status']) {
                case self::STATUS_RUNNING:
                case self::STATUS_SUSPENDED:
                    $activeCount++;
                    break;
                case self::STATUS_COMPLETED:
                    $completedCount++;
                    break;
                case self::STATUS_FAILED:
                case self::STATUS_CANCELLED:
                    $failedCount++;
                    break;
            }
        }

        foreach ($this->instanceMetrics as $instanceId => $metrics) {
            $instance = $this->activeInstances[$instanceId] ?? null;
            if ($tenantId !== null && ($instance['tenant_id'] ?? null) !== $tenantId) {
                continue;
            }

            $totalDuration += $metrics->getTotalDurationMs();
            $totalNodes += $metrics->getCompletedNodeCount();
        }

        $totalCount = $activeCount + $completedCount + $failedCount;

        return [
            'tenant_id' => $tenantId,
            'timestamp' => microtime(true),
            'instances' => [
                'active' => $activeCount,
                'completed' => $completedCount,
                'failed' => $failedCount,
                'total' => $totalCount,
            ],
            'performance' => [
                'avg_duration_ms' => $totalCount > 0 ? $totalDuration / $totalCount : 0,
                'total_nodes_executed' => $totalNodes,
                'success_rate' => $totalCount > 0
                    ? ($completedCount / $totalCount) * 100
                    : 100,
            ],
            'memory' => [
                'current_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_usage_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ],
        ];
    }

    // ========== 事件订阅 ==========

    /**
     * @inheritDoc
     */
    public function subscribeToInstance(string $instanceId, callable $callback): string
    {
        $subscriptionId = $this->generateSubscriptionId($instanceId);

        if (!isset($this->subscriptions[$instanceId])) {
            $this->subscriptions[$instanceId] = [];
        }

        $this->subscriptions[$instanceId][$subscriptionId] = $callback;

        return $subscriptionId;
    }

    /**
     * @inheritDoc
     */
    public function unsubscribe(string $subscriptionId): void
    {
        foreach ($this->subscriptions as $instanceId => $callbacks) {
            if (isset($callbacks[$subscriptionId])) {
                unset($this->subscriptions[$instanceId][$subscriptionId]);
                break;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function pushEvent(string $instanceId, string $eventType, array $eventData = []): void
    {
        $event = [
            'instance_id' => $instanceId,
            'event_type' => $eventType,
            'data' => $eventData,
            'timestamp' => microtime(true),
        ];

        // 通知订阅者
        if (isset($this->subscriptions[$instanceId])) {
            foreach ($this->subscriptions[$instanceId] as $callback) {
                try {
                    $callback($event);
                } catch (\Throwable $e) {
                    // 静默处理回调错误
                }
            }
        }

        // 触发 ThinkPHP 事件
        $this->triggerThinkPHPEvent('workflow.monitor.event', $event);
    }

    // ========== 配置方法 ==========

    /**
     * 设置最大日志条目数
     *
     * @param int $max 最大数量
     * @return void
     */
    public function setMaxLogsPerInstance(int $max): void
    {
        $this->maxLogsPerInstance = max(1, $max);
    }

    /**
     * 设置是否启用详细日志
     *
     * @param bool $enabled 是否启用
     * @return void
     */
    public function setVerboseLogging(bool $enabled): void
    {
        $this->verboseLogging = $enabled;
    }

    /**
     * 设置是否启用指标收集
     *
     * @param bool $enabled 是否启用
     * @return void
     */
    public function setMetricsEnabled(bool $enabled): void
    {
        $this->metricsEnabled = $enabled;
    }

    /**
     * 清理已完成实例的监控数据
     *
     * @param int $maxAge 最大保留时间（秒）
     * @return int 清理的实例数量
     */
    public function cleanup(int $maxAge = 3600): int
    {
        $now = microtime(true);
        $cleaned = 0;

        foreach ($this->activeInstances as $instanceId => $instance) {
            if (!in_array($instance['status'], [self::STATUS_RUNNING, self::STATUS_SUSPENDED])) {
                $completedAt = $instance['completed_at'] ?? $instance['updated_at'];
                if (($now - $completedAt) > $maxAge) {
                    unset($this->activeInstances[$instanceId]);
                    unset($this->instanceMetrics[$instanceId]);
                    unset($this->instanceLogs[$instanceId]);
                    unset($this->subscriptions[$instanceId]);
                    $cleaned++;
                }
            }
        }

        // 清理节点执行记录
        foreach ($this->nodeExecutions as $executionId => $execution) {
            if (!isset($this->activeInstances[$execution['instance_id']])) {
                unset($this->nodeExecutions[$executionId]);
            }
        }

        return $cleaned;
    }

    // ========== 私有方法 ==========

    /**
     * 生成执行 ID
     *
     * @param string $instanceId 实例 ID
     * @param string $nodeId 节点 ID
     * @return string
     */
    private function generateExecutionId(string $instanceId, string $nodeId): string
    {
        return sprintf(
            'exec_%s_%s_%s',
            substr(md5($instanceId), 0, 8),
            substr(md5($nodeId), 0, 8),
            substr(md5(uniqid((string) mt_rand(), true)), 0, 8)
        );
    }

    /**
     * 生成订阅 ID
     *
     * @param string $instanceId 实例 ID
     * @return string
     */
    private function generateSubscriptionId(string $instanceId): string
    {
        return sprintf(
            'sub_%s_%s',
            substr(md5($instanceId), 0, 8),
            substr(md5(uniqid((string) mt_rand(), true)), 0, 8)
        );
    }

    /**
     * 安全触发 ThinkPHP 事件
     *
     * 在单元测试环境中，Event 门面可能不可用，此方法提供安全的降级处理。
     *
     * @param string $eventName 事件名称
     * @param mixed $eventData 事件数据
     * @return void
     */
    private function triggerThinkPHPEvent(string $eventName, mixed $eventData): void
    {
        try {
            if (class_exists(\think\facade\Event::class)) {
                \think\facade\Event::trigger($eventName, $eventData);
            }
        } catch (\Throwable $e) {
            // 静默处理：在测试环境或 Event 未配置时不抛出异常
        }
    }
}
