<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Swoole\Interfaces;

use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;
use Alkaid\Workflow\Engine\Swoole\Execution\NodeExecutionResult;
use Alkaid\Workflow\Engine\model\NodeModel;

/**
 * 协程执行器接口
 *
 * 定义协程执行器的标准行为，用于在 Swoole 协程环境中执行工作流节点。
 *
 * @package Alkaid\Workflow\Engine\Swoole\Interfaces
 */
interface CoroutineExecutorInterface
{
    /**
     * 执行节点
     *
     * @param NodeModel $node 节点模型
     * @param ExecutionContext $context 执行上下文
     * @return NodeExecutionResult
     */
    public function executeNode(NodeModel $node, ExecutionContext $context): NodeExecutionResult;

    /**
     * 异步执行节点
     *
     * @param NodeModel $node 节点模型
     * @param ExecutionContext $context 执行上下文
     * @param callable|null $callback 完成回调
     * @return int 协程ID
     */
    public function executeNodeAsync(
        NodeModel $node,
        ExecutionContext $context,
        ?callable $callback = null
    ): int;

    /**
     * 并行执行多个节点
     *
     * @param array<NodeModel> $nodes 节点列表
     * @param ExecutionContext $context 执行上下文
     * @return array<string, NodeExecutionResult>
     */
    public function executeNodesParallel(array $nodes, ExecutionContext $context): array;

    /**
     * 带超时的节点执行
     *
     * @param NodeModel $node 节点模型
     * @param ExecutionContext $context 执行上下文
     * @param int $timeout 超时时间（秒）
     * @return NodeExecutionResult
     */
    public function executeNodeWithTimeout(
        NodeModel $node,
        ExecutionContext $context,
        int $timeout
    ): NodeExecutionResult;

    /**
     * 设置超时时间
     *
     * @param int $timeout 超时时间（秒）
     * @return static
     */
    public function setTimeout(int $timeout): static;

    /**
     * 获取超时时间
     *
     * @return int
     */
    public function getTimeout(): int;

    /**
     * 设置最大重试次数
     *
     * @param int $maxRetries
     * @return static
     */
    public function setMaxRetries(int $maxRetries): static;

    /**
     * 获取最大重试次数
     *
     * @return int
     */
    public function getMaxRetries(): int;

    /**
     * 注册事件监听器
     *
     * @param string $event 事件名称
     * @param callable $listener 监听器
     * @return static
     */
    public function on(string $event, callable $listener): static;

    /**
     * 移除事件监听器
     *
     * @param string $event 事件名称
     * @param callable|null $listener 监听器
     * @return static
     */
    public function off(string $event, ?callable $listener = null): static;
}
