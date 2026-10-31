<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Swoole;

use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;
use Alkaid\Workflow\Engine\Swoole\Execution\NodeExecutionResult;
use Alkaid\Workflow\Engine\model\NodeModel;
use Alkaid\Workflow\Engine\interface\IExecution;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * Swoole 协程执行器
 *
 * 基于 Swoole 协程实现的工作流节点异步执行引擎，
 * 支持协程上下文传递、超时控制、错误处理和重试机制。
 *
 * @package Alkaid\Workflow\Engine\Swoole
 */
class CoroutineExecutor
{
    /**
     * 默认超时时间（秒）
     */
    public const DEFAULT_TIMEOUT = 300;

    /**
     * 默认最大重试次数
     */
    public const DEFAULT_MAX_RETRIES = 3;

    /**
     * 默认重试间隔（毫秒）
     */
    public const DEFAULT_RETRY_DELAY = 1000;

    /**
     * 超时时间（秒）
     *
     * @var int
     */
    protected int $timeout;

    /**
     * 最大重试次数
     *
     * @var int
     */
    protected int $maxRetries;

    /**
     * 重试间隔（毫秒）
     *
     * @var int
     */
    protected int $retryDelay;

    /**
     * 是否启用日志
     *
     * @var bool
     */
    protected bool $enableLogging = true;

    /**
     * 事件监听器
     *
     * @var array<string, callable[]>
     */
    protected array $listeners = [];

    /**
     * 执行中的任务计数
     *
     * @var int
     */
    protected int $runningCount = 0;

    /**
     * 最大并发数
     *
     * @var int
     */
    protected int $maxConcurrency = 100;

    /**
     * 构造函数
     *
     * @param int $timeout 超时时间（秒）
     * @param int $maxRetries 最大重试次数
     * @param int $retryDelay 重试间隔（毫秒）
     */
    public function __construct(
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $retryDelay = self::DEFAULT_RETRY_DELAY
    ) {
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->retryDelay = $retryDelay;
    }

    /**
     * 检查是否在协程环境中
     *
     * @return bool
     */
    public static function isInCoroutine(): bool
    {
        return Coroutine::getCid() > 0;
    }

    /**
     * 执行节点（同步方式，在协程中等待结果）
     *
     * @param NodeModel $node 节点模型
     * @param ExecutionContext $context 执行上下文
     * @param IExecution|null $execution 原始执行对象（用于兼容）
     * @return NodeExecutionResult
     */
    public function executeNode(
        NodeModel $node,
        ExecutionContext $context,
        ?IExecution $execution = null
    ): NodeExecutionResult {
        // 检查上下文状态
        if (!$context->canContinue()) {
            if ($context->isCancelled()) {
                return NodeExecutionResult::cancelled('执行已取消');
            }
            if ($context->isSuspended()) {
                return NodeExecutionResult::pending([], '执行已暂停');
            }
            if ($context->isTimeout()) {
                return NodeExecutionResult::failed('执行超时');
            }
        }

        // 更新当前节点信息
        $nodeId = $this->getNodeId($node);
        $nodeName = $this->getNodeName($node);
        $context->setCurrentNodeId($nodeId);
        $context->setCurrentNodeName($nodeName);

        // 触发节点开始事件
        $this->emit('node.start', $node, $context);

        $result = null;
        $retryCount = 0;

        while ($retryCount <= $this->maxRetries) {
            try {
                // 在协程环境中执行
                if (self::isInCoroutine()) {
                    $result = $this->executeInCurrentCoroutine($node, $context, $execution);
                } else {
                    // 非协程环境，创建新协程执行
                    $result = $this->executeInNewCoroutine($node, $context, $execution);
                }

                // 执行成功，跳出重试循环
                if ($result->isSuccess() || $result->isPending() || $result->isSkipped()) {
                    break;
                }

                // 检查是否可以重试
                if (!$result->canRetry()) {
                    break;
                }

                $retryCount++;
                $result->incrementRetry();

                // 等待重试间隔
                if ($retryCount <= $this->maxRetries) {
                    $this->log('info', sprintf(
                        '节点 %s 执行失败，%d ms 后进行第 %d 次重试',
                        $nodeId,
                        $this->retryDelay,
                        $retryCount
                    ));

                    if (self::isInCoroutine()) {
                        Coroutine::sleep($this->retryDelay / 1000);
                    } else {
                        usleep($this->retryDelay * 1000);
                    }
                }
            } catch (Throwable $e) {
                $result = NodeExecutionResult::fromException($e);
                $result->setNodeId($nodeId);
                $result->setNodeName($nodeName);

                $context->addError($nodeId, $e->getMessage(), $e->getCode());

                // 记录错误日志
                $this->log('error', sprintf(
                    '节点 %s 执行异常: %s',
                    $nodeId,
                    $e->getMessage()
                ), ['exception' => $e]);

                if ($retryCount >= $this->maxRetries) {
                    break;
                }

                $retryCount++;
                $result->incrementRetry();

                if (self::isInCoroutine()) {
                    Coroutine::sleep($this->retryDelay / 1000);
                } else {
                    usleep($this->retryDelay * 1000);
                }
            }
        }

        // 设置节点信息
        $result->setNodeId($nodeId);
        $result->setNodeName($nodeName);

        // 记录节点输出
        if ($result->isSuccess() && !empty($result->getData())) {
            $context->setNodeOutput($nodeId, $result->getData());
        }

        // 添加到执行路径
        $context->addToExecutionPath($nodeId);

        // 触发节点完成事件
        $this->emit('node.complete', $node, $context, $result);

        return $result;
    }

    /**
     * 在当前协程中执行节点
     *
     * @param NodeModel $node
     * @param ExecutionContext $context
     * @param IExecution|null $execution
     * @return NodeExecutionResult
     */
    protected function executeInCurrentCoroutine(
        NodeModel $node,
        ExecutionContext $context,
        ?IExecution $execution = null
    ): NodeExecutionResult {
        // 更新协程ID
        $context->updateCoroutineId();

        $startTime = microtime(true);

        try {
            // 如果提供了原始执行对象，使用它
            if ($execution !== null) {
                $node->execute($execution);
                $result = NodeExecutionResult::success([
                    'execution_data' => $this->extractExecutionData($execution)
                ]);
            } else {
                // 使用新的执行方式
                $result = $this->executeNodeLogic($node, $context);
            }

            $result->markComplete();
            return $result;
        } catch (Throwable $e) {
            return NodeExecutionResult::fromException($e);
        }
    }

    /**
     * 在新协程中执行节点
     *
     * @param NodeModel $node
     * @param ExecutionContext $context
     * @param IExecution|null $execution
     * @return NodeExecutionResult
     */
    protected function executeInNewCoroutine(
        NodeModel $node,
        ExecutionContext $context,
        ?IExecution $execution = null
    ): NodeExecutionResult {
        // 使用 Channel 进行协程通信
        $channel = new Channel(1);
        $timeout = $context->getTimeout() ?: $this->timeout;

        // 创建协程执行
        Coroutine::create(function () use ($node, $context, $execution, $channel) {
            try {
                // 更新协程ID
                $context->updateCoroutineId();

                if ($execution !== null) {
                    $node->execute($execution);
                    $result = NodeExecutionResult::success([
                        'execution_data' => $this->extractExecutionData($execution)
                    ]);
                } else {
                    $result = $this->executeNodeLogic($node, $context);
                }

                $result->markComplete();
                $channel->push($result);
            } catch (Throwable $e) {
                $channel->push(NodeExecutionResult::fromException($e));
            }
        });

        // 等待执行结果，带超时
        $result = $channel->pop($timeout);

        if ($result === false) {
            // 超时
            return NodeExecutionResult::failed(
                sprintf('节点执行超时（%d秒）', $timeout)
            )->setRetryable(true);
        }

        return $result;
    }

    /**
     * 执行节点逻辑（不使用原始 IExecution）
     *
     * @param NodeModel $node
     * @param ExecutionContext $context
     * @return NodeExecutionResult
     */
    protected function executeNodeLogic(NodeModel $node, ExecutionContext $context): NodeExecutionResult
    {
        // 这里提供一个基础实现，子类可以重写
        // 实际使用时，应该通过适配器桥接到原有的执行逻辑

        $nodeType = $this->getNodeType($node);

        switch ($nodeType) {
            case 'start':
                return NodeExecutionResult::success(['type' => 'start']);

            case 'end':
                return NodeExecutionResult::success(['type' => 'end']);

            case 'task':
                // 任务节点通常需要人工处理，返回挂起状态
                return NodeExecutionResult::pending(['type' => 'task'], '等待任务处理');

            case 'decision':
                // 决策节点需要评估条件
                return $this->executeDecisionNode($node, $context);

            case 'fork':
                // 分支节点
                return NodeExecutionResult::success(['type' => 'fork']);

            case 'join':
                // 汇聚节点
                return $this->executeJoinNode($node, $context);

            default:
                return NodeExecutionResult::success(['type' => $nodeType]);
        }
    }

    /**
     * 执行决策节点
     *
     * @param NodeModel $node
     * @param ExecutionContext $context
     * @return NodeExecutionResult
     */
    protected function executeDecisionNode(NodeModel $node, ExecutionContext $context): NodeExecutionResult
    {
        // 获取节点配置的表达式
        $expr = $node->getExpr ?? null;

        if (empty($expr)) {
            // 没有表达式，选择第一个输出
            $outputs = $node->getOutputs();
            if (!empty($outputs)) {
                $firstOutput = reset($outputs);
                return NodeExecutionResult::success([
                    'type' => 'decision',
                    'selected_path' => $firstOutput->getTo()
                ], $firstOutput->getTo());
            }
            return NodeExecutionResult::success(['type' => 'decision']);
        }

        // 使用变量评估表达式
        $variables = $context->getVariables();

        // 这里应该集成表达式引擎进行评估
        // 暂时返回成功
        return NodeExecutionResult::success([
            'type' => 'decision',
            'expression' => $expr,
            'variables' => $variables
        ]);
    }

    /**
     * 执行汇聚节点
     *
     * @param NodeModel $node
     * @param ExecutionContext $context
     * @return NodeExecutionResult
     */
    protected function executeJoinNode(NodeModel $node, ExecutionContext $context): NodeExecutionResult
    {
        // 检查所有输入分支是否都已完成
        $inputs = $node->getInputs();
        $executionPath = $context->getExecutionPath();

        $completedCount = 0;
        $totalCount = count($inputs);

        foreach ($inputs as $input) {
            $sourceNodeId = $input->getFrom();
            if (in_array($sourceNodeId, $executionPath, true)) {
                $completedCount++;
            }
        }

        if ($completedCount < $totalCount) {
            // 还有分支未完成，等待
            return NodeExecutionResult::pending([
                'type' => 'join',
                'completed' => $completedCount,
                'total' => $totalCount
            ], sprintf('等待分支完成 (%d/%d)', $completedCount, $totalCount));
        }

        return NodeExecutionResult::success([
            'type' => 'join',
            'completed' => $completedCount,
            'total' => $totalCount
        ]);
    }

    /**
     * 异步执行节点（不等待结果）
     *
     * @param NodeModel $node
     * @param ExecutionContext $context
     * @param callable|null $callback 完成回调
     * @return int 协程ID
     */
    public function executeNodeAsync(
        NodeModel $node,
        ExecutionContext $context,
        ?callable $callback = null
    ): int {
        // 检查并发限制
        if ($this->runningCount >= $this->maxConcurrency) {
            throw new \RuntimeException(sprintf(
                '超过最大并发数限制（%d）',
                $this->maxConcurrency
            ));
        }

        $this->runningCount++;

        $cid = Coroutine::create(function () use ($node, $context, $callback) {
            try {
                $result = $this->executeNode($node, $context);

                if ($callback !== null) {
                    $callback($result, $context);
                }
            } finally {
                $this->runningCount--;
            }
        });

        return $cid ?: -1;
    }

    /**
     * 并行执行多个节点
     *
     * @param array<NodeModel> $nodes 节点列表
     * @param ExecutionContext $context 执行上下文
     * @return array<string, NodeExecutionResult> 节点ID => 执行结果
     */
    public function executeNodesParallel(array $nodes, ExecutionContext $context): array
    {
        if (empty($nodes)) {
            return [];
        }

        $results = [];
        $channels = [];

        // 为每个节点创建 Channel
        foreach ($nodes as $node) {
            $nodeId = $this->getNodeId($node);
            $channels[$nodeId] = new Channel(1);
        }

        // 并行执行所有节点
        foreach ($nodes as $node) {
            $nodeId = $this->getNodeId($node);
            $channel = $channels[$nodeId];
            $childContext = $context->clone(); // 克隆上下文避免冲突

            Coroutine::create(function () use ($node, $childContext, $channel) {
                try {
                    $result = $this->executeNode($node, $childContext);
                    $channel->push($result);
                } catch (Throwable $e) {
                    $channel->push(NodeExecutionResult::fromException($e));
                }
            });
        }

        // 收集所有结果
        $timeout = $context->getTimeout() ?: $this->timeout;
        foreach ($channels as $nodeId => $channel) {
            $result = $channel->pop($timeout);
            if ($result === false) {
                $results[$nodeId] = NodeExecutionResult::failed('执行超时');
            } else {
                $results[$nodeId] = $result;
            }
        }

        return $results;
    }

    /**
     * 带超时的节点执行
     *
     * @param NodeModel $node
     * @param ExecutionContext $context
     * @param int $timeout 超时时间（秒）
     * @return NodeExecutionResult
     */
    public function executeNodeWithTimeout(
        NodeModel $node,
        ExecutionContext $context,
        int $timeout
    ): NodeExecutionResult {
        $originalTimeout = $context->getTimeout();
        $context->setTimeout($timeout);

        try {
            return $this->executeNode($node, $context);
        } finally {
            $context->setTimeout($originalTimeout);
        }
    }

    /**
     * 从执行对象提取数据
     *
     * @param IExecution $execution
     * @return array
     */
    protected function extractExecutionData(IExecution $execution): array
    {
        $data = [];

        // 提取可用的数据
        if (method_exists($execution, 'getArgs')) {
            $data['args'] = $execution->getArgs();
        }
        if (method_exists($execution, 'getProcessTaskList')) {
            $data['task_list'] = $execution->getProcessTaskList();
        }

        return $data;
    }

    /**
     * 获取节点ID
     *
     * @param NodeModel $node
     * @return string
     */
    protected function getNodeId(NodeModel $node): string
    {
        if (method_exists($node, 'getName')) {
            return (string) $node->getName();
        }
        if (method_exists($node, 'getId')) {
            return (string) $node->getId();
        }
        return spl_object_hash($node);
    }

    /**
     * 获取节点名称
     *
     * @param NodeModel $node
     * @return string
     */
    protected function getNodeName(NodeModel $node): string
    {
        if (method_exists($node, 'getDisplayName')) {
            return (string) $node->getDisplayName();
        }
        return $this->getNodeId($node);
    }

    /**
     * 获取节点类型
     *
     * @param NodeModel $node
     * @return string
     */
    protected function getNodeType(NodeModel $node): string
    {
        $className = get_class($node);
        $shortName = basename(str_replace('\\', '/', $className));

        // 移除 "Model" 后缀并转小写
        $type = strtolower(str_replace('Model', '', $shortName));

        return $type ?: 'unknown';
    }

    /**
     * 注册事件监听器
     *
     * @param string $event 事件名称
     * @param callable $listener 监听器
     * @return $this
     */
    public function on(string $event, callable $listener): static
    {
        $this->listeners[$event][] = $listener;
        return $this;
    }

    /**
     * 移除事件监听器
     *
     * @param string $event 事件名称
     * @param callable|null $listener 监听器，为空则移除所有
     * @return $this
     */
    public function off(string $event, ?callable $listener = null): static
    {
        if ($listener === null) {
            unset($this->listeners[$event]);
        } else {
            $this->listeners[$event] = array_filter(
                $this->listeners[$event] ?? [],
                fn ($l) => $l !== $listener
            );
        }
        return $this;
    }

    /**
     * 触发事件
     *
     * @param string $event 事件名称
     * @param mixed ...$args 参数
     * @return void
     */
    protected function emit(string $event, mixed ...$args): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener(...$args);
            } catch (Throwable $e) {
                $this->log('error', sprintf(
                    '事件监听器执行失败: %s - %s',
                    $event,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * 记录日志
     *
     * @param string $level 日志级别
     * @param string $message 日志消息
     * @param array $context 上下文
     * @return void
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (!$this->enableLogging) {
            return;
        }

        // 使用简单的日志输出，实际项目中应该注入日志服务
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        error_log(sprintf(
            '[%s] [%s] [CoroutineExecutor] %s%s',
            $timestamp,
            strtoupper($level),
            $message,
            $contextStr
        ));
    }

    /**
     * 设置超时时间
     *
     * @param int $timeout
     * @return $this
     */
    public function setTimeout(int $timeout): static
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * 获取超时时间
     *
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * 设置最大重试次数
     *
     * @param int $maxRetries
     * @return $this
     */
    public function setMaxRetries(int $maxRetries): static
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    /**
     * 获取最大重试次数
     *
     * @return int
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * 设置重试间隔
     *
     * @param int $retryDelay 毫秒
     * @return $this
     */
    public function setRetryDelay(int $retryDelay): static
    {
        $this->retryDelay = $retryDelay;
        return $this;
    }

    /**
     * 获取重试间隔
     *
     * @return int
     */
    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    /**
     * 启用/禁用日志
     *
     * @param bool $enable
     * @return $this
     */
    public function setEnableLogging(bool $enable): static
    {
        $this->enableLogging = $enable;
        return $this;
    }

    /**
     * 设置最大并发数
     *
     * @param int $maxConcurrency
     * @return $this
     */
    public function setMaxConcurrency(int $maxConcurrency): static
    {
        $this->maxConcurrency = $maxConcurrency;
        return $this;
    }

    /**
     * 获取当前运行任务数
     *
     * @return int
     */
    public function getRunningCount(): int
    {
        return $this->runningCount;
    }
}
