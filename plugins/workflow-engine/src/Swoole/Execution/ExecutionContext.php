<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Swoole\Execution;

use Swoole\Coroutine;

/**
 * 协程执行上下文
 *
 * 管理工作流执行过程中的上下文信息，包括变量、节点输出、
 * 租户信息等。支持协程安全的数据访问。
 *
 * @package Alkaid\Workflow\Engine\Swoole\Execution
 */
class ExecutionContext
{
    /**
     * 工作流定义ID
     *
     * @var string|int|null
     */
    protected string|int|null $workflowId = null;

    /**
     * 工作流实例ID
     *
     * @var string|int|null
     */
    protected string|int|null $instanceId = null;

    /**
     * 租户ID
     *
     * @var int|null
     */
    protected ?int $tenantId = null;

    /**
     * 用户ID
     *
     * @var int|null
     */
    protected ?int $userId = null;

    /**
     * 执行变量
     *
     * @var array
     */
    protected array $variables = [];

    /**
     * 节点输出数据
     *
     * @var array<string, array>
     */
    protected array $nodeOutputs = [];

    /**
     * 当前节点ID
     *
     * @var string|null
     */
    protected ?string $currentNodeId = null;

    /**
     * 当前节点名称
     *
     * @var string|null
     */
    protected ?string $currentNodeName = null;

    /**
     * 执行路径（已执行的节点ID列表）
     *
     * @var array<string>
     */
    protected array $executionPath = [];

    /**
     * 执行开始时间
     *
     * @var float
     */
    protected float $startTime;

    /**
     * 是否已取消
     *
     * @var bool
     */
    protected bool $cancelled = false;

    /**
     * 是否已暂停
     *
     * @var bool
     */
    protected bool $suspended = false;

    /**
     * 错误列表
     *
     * @var array
     */
    protected array $errors = [];

    /**
     * 父上下文（用于子流程）
     *
     * @var ExecutionContext|null
     */
    protected ?ExecutionContext $parentContext = null;

    /**
     * 元数据
     *
     * @var array
     */
    protected array $metadata = [];

    /**
     * 协程ID
     *
     * @var int
     */
    protected int $coroutineId;

    /**
     * 超时时间（秒）
     *
     * @var int
     */
    protected int $timeout = 300;

    /**
     * 触发器数据
     *
     * @var array
     */
    protected array $triggerData = [];

    /**
     * 业务键
     *
     * @var string|null
     */
    protected ?string $businessKey = null;

    /**
     * 优先级
     *
     * @var int
     */
    protected int $priority = 5;

    /**
     * 构造函数
     *
     * @param string|int|null $workflowId 工作流定义ID
     * @param string|int|null $instanceId 工作流实例ID
     * @param int|null $tenantId 租户ID
     */
    public function __construct(
        string|int|null $workflowId = null,
        string|int|null $instanceId = null,
        ?int $tenantId = null
    ) {
        $this->workflowId = $workflowId;
        $this->instanceId = $instanceId;
        $this->tenantId = $tenantId;
        $this->startTime = microtime(true);
        $this->coroutineId = Coroutine::getCid() ?: -1;
    }

    /**
     * 创建执行上下文
     *
     * @param array $config 配置数组
     * @return static
     */
    public static function create(array $config = []): static
    {
        $context = new static(
            $config['workflow_id'] ?? null,
            $config['instance_id'] ?? null,
            $config['tenant_id'] ?? null
        );

        if (isset($config['user_id'])) {
            $context->setUserId($config['user_id']);
        }
        if (isset($config['variables'])) {
            $context->setVariables($config['variables']);
        }
        if (isset($config['trigger_data'])) {
            $context->setTriggerData($config['trigger_data']);
        }
        if (isset($config['business_key'])) {
            $context->setBusinessKey($config['business_key']);
        }
        if (isset($config['priority'])) {
            $context->setPriority($config['priority']);
        }
        if (isset($config['timeout'])) {
            $context->setTimeout($config['timeout']);
        }
        if (isset($config['metadata'])) {
            $context->setMetadata($config['metadata']);
        }

        return $context;
    }

    /**
     * 为子流程创建子上下文
     *
     * @param string $subWorkflowId 子工作流ID
     * @param array $inputVariables 输入变量
     * @return static
     */
    public function createChildContext(string $subWorkflowId, array $inputVariables = []): static
    {
        $child = new static($subWorkflowId, null, $this->tenantId);
        $child->setUserId($this->userId);
        $child->setVariables(array_merge($this->variables, $inputVariables));
        $child->setParentContext($this);
        $child->setTimeout($this->timeout);

        return $child;
    }

    /**
     * 获取工作流定义ID
     *
     * @return string|int|null
     */
    public function getWorkflowId(): string|int|null
    {
        return $this->workflowId;
    }

    /**
     * 设置工作流定义ID
     *
     * @param string|int|null $workflowId
     * @return $this
     */
    public function setWorkflowId(string|int|null $workflowId): static
    {
        $this->workflowId = $workflowId;
        return $this;
    }

    /**
     * 获取工作流实例ID
     *
     * @return string|int|null
     */
    public function getInstanceId(): string|int|null
    {
        return $this->instanceId;
    }

    /**
     * 设置工作流实例ID
     *
     * @param string|int|null $instanceId
     * @return $this
     */
    public function setInstanceId(string|int|null $instanceId): static
    {
        $this->instanceId = $instanceId;
        return $this;
    }

    /**
     * 获取租户ID
     *
     * @return int|null
     */
    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    /**
     * 设置租户ID
     *
     * @param int|null $tenantId
     * @return $this
     */
    public function setTenantId(?int $tenantId): static
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    /**
     * 获取用户ID
     *
     * @return int|null
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * 设置用户ID
     *
     * @param int|null $userId
     * @return $this
     */
    public function setUserId(?int $userId): static
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * 获取所有变量
     *
     * @return array
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * 设置所有变量
     *
     * @param array $variables
     * @return $this
     */
    public function setVariables(array $variables): static
    {
        $this->variables = $variables;
        return $this;
    }

    /**
     * 获取单个变量
     *
     * @param string $name 变量名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getVariable(string $name, mixed $default = null): mixed
    {
        return $this->variables[$name] ?? $default;
    }

    /**
     * 设置单个变量
     *
     * @param string $name 变量名
     * @param mixed $value 变量值
     * @return $this
     */
    public function setVariable(string $name, mixed $value): static
    {
        $this->variables[$name] = $value;
        return $this;
    }

    /**
     * 检查变量是否存在
     *
     * @param string $name 变量名
     * @return bool
     */
    public function hasVariable(string $name): bool
    {
        return array_key_exists($name, $this->variables);
    }

    /**
     * 删除变量
     *
     * @param string $name 变量名
     * @return $this
     */
    public function removeVariable(string $name): static
    {
        unset($this->variables[$name]);
        return $this;
    }

    /**
     * 合并变量
     *
     * @param array $variables
     * @return $this
     */
    public function mergeVariables(array $variables): static
    {
        $this->variables = array_merge($this->variables, $variables);
        return $this;
    }

    /**
     * 设置节点输出
     *
     * @param string $nodeId 节点ID
     * @param array $data 输出数据
     * @return $this
     */
    public function setNodeOutput(string $nodeId, array $data): static
    {
        $this->nodeOutputs[$nodeId] = $data;
        return $this;
    }

    /**
     * 获取节点输出
     *
     * @param string $nodeId 节点ID
     * @return array|null
     */
    public function getNodeOutput(string $nodeId): ?array
    {
        return $this->nodeOutputs[$nodeId] ?? null;
    }

    /**
     * 获取所有节点输出
     *
     * @return array
     */
    public function getAllNodeOutputs(): array
    {
        return $this->nodeOutputs;
    }

    /**
     * 检查节点是否有输出
     *
     * @param string $nodeId 节点ID
     * @return bool
     */
    public function hasNodeOutput(string $nodeId): bool
    {
        return isset($this->nodeOutputs[$nodeId]);
    }

    /**
     * 获取当前节点ID
     *
     * @return string|null
     */
    public function getCurrentNodeId(): ?string
    {
        return $this->currentNodeId;
    }

    /**
     * 设置当前节点ID
     *
     * @param string|null $nodeId
     * @return $this
     */
    public function setCurrentNodeId(?string $nodeId): static
    {
        $this->currentNodeId = $nodeId;
        return $this;
    }

    /**
     * 获取当前节点名称
     *
     * @return string|null
     */
    public function getCurrentNodeName(): ?string
    {
        return $this->currentNodeName;
    }

    /**
     * 设置当前节点名称
     *
     * @param string|null $nodeName
     * @return $this
     */
    public function setCurrentNodeName(?string $nodeName): static
    {
        $this->currentNodeName = $nodeName;
        return $this;
    }

    /**
     * 添加到执行路径
     *
     * @param string $nodeId 节点ID
     * @return $this
     */
    public function addToExecutionPath(string $nodeId): static
    {
        $this->executionPath[] = $nodeId;
        return $this;
    }

    /**
     * 获取执行路径
     *
     * @return array
     */
    public function getExecutionPath(): array
    {
        return $this->executionPath;
    }

    /**
     * 检查节点是否已执行
     *
     * @param string $nodeId 节点ID
     * @return bool
     */
    public function hasExecuted(string $nodeId): bool
    {
        return in_array($nodeId, $this->executionPath, true);
    }

    /**
     * 获取执行开始时间
     *
     * @return float
     */
    public function getStartTime(): float
    {
        return $this->startTime;
    }

    /**
     * 获取执行持续时间（毫秒）
     *
     * @return float
     */
    public function getDuration(): float
    {
        return (microtime(true) - $this->startTime) * 1000;
    }

    /**
     * 取消执行
     *
     * @param string|null $reason 取消原因
     * @return $this
     */
    public function cancel(?string $reason = null): static
    {
        $this->cancelled = true;
        if ($reason !== null) {
            $this->setMeta('cancel_reason', $reason);
        }
        return $this;
    }

    /**
     * 检查是否已取消
     *
     * @return bool
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * 暂停执行
     *
     * @param string|null $reason 暂停原因
     * @return $this
     */
    public function suspend(?string $reason = null): static
    {
        $this->suspended = true;
        if ($reason !== null) {
            $this->setMeta('suspend_reason', $reason);
        }
        return $this;
    }

    /**
     * 暂停执行（别名）
     *
     * @param string|null $reason 暂停原因
     * @return $this
     */
    public function pause(?string $reason = null): static
    {
        return $this->suspend($reason);
    }

    /**
     * 恢复执行
     *
     * @return $this
     */
    public function resume(): static
    {
        $this->suspended = false;
        return $this;
    }

    /**
     * 检查是否已暂停
     *
     * @return bool
     */
    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    /**
     * 检查是否已暂停（别名）
     *
     * @return bool
     */
    public function isPaused(): bool
    {
        return $this->isSuspended();
    }

    /**
     * 检查是否可以继续执行
     *
     * @return bool
     */
    public function canContinue(): bool
    {
        return !$this->cancelled && !$this->suspended && !$this->isTimeout();
    }

    /**
     * 添加错误
     *
     * @param string $nodeId 节点ID
     * @param string $error 错误信息
     * @param int|null $code 错误码
     * @return $this
     */
    public function addError(string $nodeId, string $error, ?int $code = null): static
    {
        $this->errors[] = [
            'node_id' => $nodeId,
            'error' => $error,
            'code' => $code,
            'time' => microtime(true),
        ];
        return $this;
    }

    /**
     * 获取所有错误
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 检查是否有错误
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * 获取最后一个错误
     *
     * @return array|null
     */
    public function getLastError(): ?array
    {
        return empty($this->errors) ? null : end($this->errors);
    }

    /**
     * 清除错误
     *
     * @return $this
     */
    public function clearErrors(): static
    {
        $this->errors = [];
        return $this;
    }

    /**
     * 获取父上下文
     *
     * @return ExecutionContext|null
     */
    public function getParentContext(): ?ExecutionContext
    {
        return $this->parentContext;
    }

    /**
     * 设置父上下文
     *
     * @param ExecutionContext|null $parentContext
     * @return $this
     */
    public function setParentContext(?ExecutionContext $parentContext): static
    {
        $this->parentContext = $parentContext;
        return $this;
    }

    /**
     * 检查是否为子上下文
     *
     * @return bool
     */
    public function isChildContext(): bool
    {
        return $this->parentContext !== null;
    }

    /**
     * 获取根上下文
     *
     * @return ExecutionContext
     */
    public function getRootContext(): ExecutionContext
    {
        $context = $this;
        while ($context->parentContext !== null) {
            $context = $context->parentContext;
        }
        return $context;
    }

    /**
     * 获取元数据
     *
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * 设置元数据
     *
     * @param array $metadata
     * @return $this
     */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * 获取指定键的元数据
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * 设置指定键的元数据
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setMeta(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * 获取协程ID
     *
     * @return int
     */
    public function getCoroutineId(): int
    {
        return $this->coroutineId;
    }

    /**
     * 更新协程ID为当前协程
     *
     * @return $this
     */
    public function updateCoroutineId(): static
    {
        $this->coroutineId = Coroutine::getCid() ?: -1;
        return $this;
    }

    /**
     * 检查是否在协程中
     *
     * @return bool
     */
    public function isInCoroutine(): bool
    {
        return $this->coroutineId > 0;
    }

    /**
     * 获取超时时间（秒）
     *
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * 设置超时时间（秒）
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
     * 检查是否已超时
     *
     * @return bool
     */
    public function isTimeout(): bool
    {
        return $this->getDuration() > ($this->timeout * 1000);
    }

    /**
     * 获取剩余时间（毫秒）
     *
     * @return float
     */
    public function getRemainingTime(): float
    {
        $remaining = ($this->timeout * 1000) - $this->getDuration();
        return max(0, $remaining);
    }

    /**
     * 获取触发器数据
     *
     * @return array
     */
    public function getTriggerData(): array
    {
        return $this->triggerData;
    }

    /**
     * 设置触发器数据
     *
     * @param array $triggerData
     * @return $this
     */
    public function setTriggerData(array $triggerData): static
    {
        $this->triggerData = $triggerData;
        return $this;
    }

    /**
     * 获取业务键
     *
     * @return string|null
     */
    public function getBusinessKey(): ?string
    {
        return $this->businessKey;
    }

    /**
     * 设置业务键
     *
     * @param string|null $businessKey
     * @return $this
     */
    public function setBusinessKey(?string $businessKey): static
    {
        $this->businessKey = $businessKey;
        return $this;
    }

    /**
     * 获取优先级
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * 设置优先级
     *
     * @param int $priority
     * @return $this
     */
    public function setPriority(int $priority): static
    {
        $this->priority = max(1, min(10, $priority));
        return $this;
    }

    /**
     * 克隆上下文（用于分支执行）
     *
     * @return static
     */
    public function clone(): static
    {
        $clone = new static($this->workflowId, $this->instanceId, $this->tenantId);
        $clone->userId = $this->userId;
        $clone->variables = $this->variables;
        $clone->nodeOutputs = $this->nodeOutputs;
        $clone->executionPath = $this->executionPath;
        $clone->metadata = $this->metadata;
        $clone->triggerData = $this->triggerData;
        $clone->businessKey = $this->businessKey;
        $clone->priority = $this->priority;
        $clone->timeout = $this->timeout;

        return $clone;
    }

    /**
     * 转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'workflow_id' => $this->workflowId,
            'instance_id' => $this->instanceId,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'variables' => $this->variables,
            'node_outputs' => $this->nodeOutputs,
            'current_node_id' => $this->currentNodeId,
            'current_node_name' => $this->currentNodeName,
            'execution_path' => $this->executionPath,
            'start_time' => $this->startTime,
            'duration' => $this->getDuration(),
            'cancelled' => $this->cancelled,
            'suspended' => $this->suspended,
            'errors' => $this->errors,
            'metadata' => $this->metadata,
            'coroutine_id' => $this->coroutineId,
            'timeout' => $this->timeout,
            'trigger_data' => $this->triggerData,
            'business_key' => $this->businessKey,
            'priority' => $this->priority,
        ];
    }

    /**
     * 转换为JSON字符串
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 从数组恢复上下文
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $context = new static(
            $data['workflow_id'] ?? null,
            $data['instance_id'] ?? null,
            $data['tenant_id'] ?? null
        );

        if (isset($data['user_id'])) {
            $context->setUserId($data['user_id']);
        }
        if (isset($data['variables'])) {
            $context->setVariables($data['variables']);
        }
        if (isset($data['node_outputs'])) {
            $context->nodeOutputs = $data['node_outputs'];
        }
        if (isset($data['current_node_id'])) {
            $context->setCurrentNodeId($data['current_node_id']);
        }
        if (isset($data['current_node_name'])) {
            $context->setCurrentNodeName($data['current_node_name']);
        }
        if (isset($data['execution_path'])) {
            $context->executionPath = $data['execution_path'];
        }
        if (isset($data['cancelled'])) {
            $context->cancelled = $data['cancelled'];
        }
        if (isset($data['suspended'])) {
            $context->suspended = $data['suspended'];
        }
        if (isset($data['errors'])) {
            $context->errors = $data['errors'];
        }
        if (isset($data['metadata'])) {
            $context->setMetadata($data['metadata']);
        }
        if (isset($data['timeout'])) {
            $context->setTimeout($data['timeout']);
        }
        if (isset($data['trigger_data'])) {
            $context->setTriggerData($data['trigger_data']);
        }
        if (isset($data['business_key'])) {
            $context->setBusinessKey($data['business_key']);
        }
        if (isset($data['priority'])) {
            $context->setPriority($data['priority']);
        }

        return $context;
    }
}
