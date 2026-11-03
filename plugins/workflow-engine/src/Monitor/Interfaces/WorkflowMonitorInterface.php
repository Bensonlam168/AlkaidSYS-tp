<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Monitor\Interfaces;

/**
 * 工作流监控器接口
 *
 * 定义工作流执行监控的标准接口。
 *
 * @package Alkaid\Workflow\Engine\Monitor\Interfaces
 */
interface WorkflowMonitorInterface
{
    // ========== 实例监控 ==========

    /**
     * 开始监控工作流实例
     *
     * @param string $instanceId 实例 ID
     * @param array $metadata 元数据
     * @return void
     */
    public function startMonitoring(string $instanceId, array $metadata = []): void;

    /**
     * 停止监控工作流实例
     *
     * @param string $instanceId 实例 ID
     * @return void
     */
    public function stopMonitoring(string $instanceId): void;

    /**
     * 获取实例当前状态
     *
     * @param string $instanceId 实例 ID
     * @return array 状态信息
     */
    public function getInstanceStatus(string $instanceId): array;

    /**
     * 获取所有活跃实例
     *
     * @param int|null $tenantId 租户 ID（可选）
     * @return array 活跃实例列表
     */
    public function getActiveInstances(?int $tenantId = null): array;

    // ========== 节点监控 ==========

    /**
     * 记录节点开始执行
     *
     * @param string $instanceId 实例 ID
     * @param string $nodeId 节点 ID
     * @param string $nodeType 节点类型
     * @param array $context 执行上下文
     * @return string 执行记录 ID
     */
    public function recordNodeStart(
        string $instanceId,
        string $nodeId,
        string $nodeType,
        array $context = []
    ): string;

    /**
     * 记录节点执行完成
     *
     * @param string $executionId 执行记录 ID
     * @param string $status 执行状态
     * @param array $result 执行结果
     * @return void
     */
    public function recordNodeComplete(
        string $executionId,
        string $status,
        array $result = []
    ): void;

    /**
     * 记录节点执行失败
     *
     * @param string $executionId 执行记录 ID
     * @param string $errorCode 错误码
     * @param string $errorMessage 错误消息
     * @param array $errorContext 错误上下文
     * @return void
     */
    public function recordNodeError(
        string $executionId,
        string $errorCode,
        string $errorMessage,
        array $errorContext = []
    ): void;

    // ========== 执行日志 ==========

    /**
     * 获取实例执行日志
     *
     * @param string $instanceId 实例 ID
     * @param array $options 查询选项
     * @return array 执行日志列表
     */
    public function getExecutionLogs(string $instanceId, array $options = []): array;

    /**
     * 获取节点执行历史
     *
     * @param string $instanceId 实例 ID
     * @param string $nodeId 节点 ID
     * @return array 节点执行历史
     */
    public function getNodeHistory(string $instanceId, string $nodeId): array;

    // ========== 性能指标 ==========

    /**
     * 获取实例性能指标
     *
     * @param string $instanceId 实例 ID
     * @return array 性能指标
     */
    public function getInstanceMetrics(string $instanceId): array;

    /**
     * 获取工作流定义的聚合指标
     *
     * @param string $definitionId 定义 ID
     * @param array $options 查询选项
     * @return array 聚合指标
     */
    public function getDefinitionMetrics(string $definitionId, array $options = []): array;

    /**
     * 获取系统整体指标
     *
     * @param int|null $tenantId 租户 ID（可选）
     * @return array 系统指标
     */
    public function getSystemMetrics(?int $tenantId = null): array;

    // ========== 事件订阅 ==========

    /**
     * 订阅实例事件
     *
     * @param string $instanceId 实例 ID
     * @param callable $callback 回调函数
     * @return string 订阅 ID
     */
    public function subscribeToInstance(string $instanceId, callable $callback): string;

    /**
     * 取消订阅
     *
     * @param string $subscriptionId 订阅 ID
     * @return void
     */
    public function unsubscribe(string $subscriptionId): void;

    /**
     * 推送事件
     *
     * @param string $instanceId 实例 ID
     * @param string $eventType 事件类型
     * @param array $eventData 事件数据
     * @return void
     */
    public function pushEvent(string $instanceId, string $eventType, array $eventData = []): void;
}
