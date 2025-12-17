<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Expression\Context;

/**
 * Expression Context | 表达式上下文
 *
 * Manages variables and data for expression evaluation.
 * 管理表达式评估的变量和数据。
 *
 * @package Alkaid\Workflow\Engine\Expression\Context
 */
class ExpressionContext
{
    /**
     * Workflow variables | 工作流变量
     *
     * @var array
     */
    private array $workflowVariables = [];

    /**
     * Process variables | 流程变量
     *
     * @var array
     */
    private array $processVariables = [];

    /**
     * Task variables | 任务变量
     *
     * @var array
     */
    private array $taskVariables = [];

    /**
     * System variables | 系统变量
     *
     * @var array
     */
    private array $systemVariables = [];

    /**
     * User-defined variables | 用户自定义变量
     *
     * @var array
     */
    private array $customVariables = [];

    /**
     * Tenant ID | 租户ID
     *
     * @var int|null
     */
    private ?int $tenantId = null;

    /**
     * User ID | 用户ID
     *
     * @var int|null
     */
    private ?int $userId = null;

    /**
     * Constructor
     *
     * @param array $initialData Initial data to populate context
     */
    public function __construct(array $initialData = [])
    {
        if (!empty($initialData)) {
            $this->populate($initialData);
        }

        $this->initializeSystemVariables();
    }

    /**
     * Initialize system variables | 初始化系统变量
     *
     * @return void
     */
    private function initializeSystemVariables(): void
    {
        $this->systemVariables = [
            'now' => date('Y-m-d H:i:s'),
            'today' => date('Y-m-d'),
            'timestamp' => time(),
            'year' => (int)date('Y'),
            'month' => (int)date('m'),
            'day' => (int)date('d'),
            'hour' => (int)date('H'),
            'minute' => (int)date('i'),
            'second' => (int)date('s'),
            'dayOfWeek' => (int)date('w'),
            'dayOfYear' => (int)date('z'),
            'weekOfYear' => (int)date('W'),
            'isWeekend' => in_array((int)date('w'), [0, 6]),
            'env' => getenv('APP_ENV') ?: 'production',
        ];
    }

    /**
     * Populate context from array | 从数组填充上下文
     *
     * @param array $data
     * @return void
     */
    public function populate(array $data): void
    {
        if (isset($data['workflow'])) {
            $this->workflowVariables = array_merge($this->workflowVariables, $data['workflow']);
        }
        if (isset($data['process'])) {
            $this->processVariables = array_merge($this->processVariables, $data['process']);
        }
        if (isset($data['task'])) {
            $this->taskVariables = array_merge($this->taskVariables, $data['task']);
        }
        if (isset($data['custom'])) {
            $this->customVariables = array_merge($this->customVariables, $data['custom']);
        }
        if (isset($data['tenant_id'])) {
            $this->tenantId = (int)$data['tenant_id'];
        }
        if (isset($data['user_id'])) {
            $this->userId = (int)$data['user_id'];
        }
    }

    /**
     * Set workflow variable | 设置工作流变量
     *
     * @param string $name Variable name
     * @param mixed $value Variable value
     * @return self
     */
    public function setWorkflowVariable(string $name, mixed $value): self
    {
        $this->workflowVariables[$name] = $value;
        return $this;
    }

    /**
     * Get workflow variable | 获取工作流变量
     *
     * @param string $name Variable name
     * @param mixed $default Default value
     * @return mixed
     */
    public function getWorkflowVariable(string $name, mixed $default = null): mixed
    {
        return $this->workflowVariables[$name] ?? $default;
    }

    /**
     * Set process variable | 设置流程变量
     *
     * @param string $name Variable name
     * @param mixed $value Variable value
     * @return self
     */
    public function setProcessVariable(string $name, mixed $value): self
    {
        $this->processVariables[$name] = $value;
        return $this;
    }

    /**
     * Get process variable | 获取流程变量
     *
     * @param string $name Variable name
     * @param mixed $default Default value
     * @return mixed
     */
    public function getProcessVariable(string $name, mixed $default = null): mixed
    {
        return $this->processVariables[$name] ?? $default;
    }

    /**
     * Set task variable | 设置任务变量
     *
     * @param string $name Variable name
     * @param mixed $value Variable value
     * @return self
     */
    public function setTaskVariable(string $name, mixed $value): self
    {
        $this->taskVariables[$name] = $value;
        return $this;
    }

    /**
     * Get task variable | 获取任务变量
     *
     * @param string $name Variable name
     * @param mixed $default Default value
     * @return mixed
     */
    public function getTaskVariable(string $name, mixed $default = null): mixed
    {
        return $this->taskVariables[$name] ?? $default;
    }

    /**
     * Set custom variable | 设置自定义变量
     *
     * @param string $name Variable name
     * @param mixed $value Variable value
     * @return self
     */
    public function setCustomVariable(string $name, mixed $value): self
    {
        $this->customVariables[$name] = $value;
        return $this;
    }

    /**
     * Get custom variable | 获取自定义变量
     *
     * @param string $name Variable name
     * @param mixed $default Default value
     * @return mixed
     */
    public function getCustomVariable(string $name, mixed $default = null): mixed
    {
        return $this->customVariables[$name] ?? $default;
    }

    /**
     * Set tenant ID | 设置租户ID
     *
     * @param int|null $tenantId
     * @return self
     */
    public function setTenantId(?int $tenantId): self
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    /**
     * Get tenant ID | 获取租户ID
     *
     * @return int|null
     */
    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    /**
     * Set user ID | 设置用户ID
     *
     * @param int|null $userId
     * @return self
     */
    public function setUserId(?int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * Get user ID | 获取用户ID
     *
     * @return int|null
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * Get all variables merged | 获取合并后的所有变量
     *
     * Priority (lowest to highest):
     * system < workflow < process < task < custom
     *
     * @return array
     */
    public function toArray(): array
    {
        // Refresh system variables
        $this->initializeSystemVariables();

        $merged = array_merge(
            $this->systemVariables,
            $this->workflowVariables,
            $this->processVariables,
            $this->taskVariables,
            $this->customVariables
        );

        // Add context info
        $merged['_tenant_id'] = $this->tenantId;
        $merged['_user_id'] = $this->userId;

        // Add namespaced access
        $merged['workflow'] = $this->workflowVariables;
        $merged['process'] = $this->processVariables;
        $merged['task'] = $this->taskVariables;
        $merged['system'] = $this->systemVariables;

        return $merged;
    }

    /**
     * Get variable by path | 通过路径获取变量
     *
     * Supports dot notation: "workflow.amount", "task.assignee.name"
     *
     * @param string $path Variable path
     * @param mixed $default Default value
     * @return mixed
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $parts = explode('.', $path);
        $data = $this->toArray();

        foreach ($parts as $part) {
            if (!is_array($data) || !array_key_exists($part, $data)) {
                return $default;
            }
            $data = $data[$part];
        }

        return $data;
    }

    /**
     * Check if variable exists | 检查变量是否存在
     *
     * @param string $path Variable path
     * @return bool
     */
    public function has(string $path): bool
    {
        $parts = explode('.', $path);
        $data = $this->toArray();

        foreach ($parts as $part) {
            if (!is_array($data) || !array_key_exists($part, $data)) {
                return false;
            }
            $data = $data[$part];
        }

        return true;
    }

    /**
     * Create context from flat array | 从扁平数组创建上下文
     *
     * @param array $data Flat array of variables
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $context = new self();
        $context->customVariables = $data;
        return $context;
    }

    /**
     * Merge with another context | 与另一个上下文合并
     *
     * @param ExpressionContext $other
     * @return self New merged context
     */
    public function merge(ExpressionContext $other): self
    {
        $merged = new self();
        $merged->workflowVariables = array_merge($this->workflowVariables, $other->workflowVariables);
        $merged->processVariables = array_merge($this->processVariables, $other->processVariables);
        $merged->taskVariables = array_merge($this->taskVariables, $other->taskVariables);
        $merged->customVariables = array_merge($this->customVariables, $other->customVariables);
        $merged->tenantId = $other->tenantId ?? $this->tenantId;
        $merged->userId = $other->userId ?? $this->userId;

        return $merged;
    }

    /**
     * Clear all variables | 清除所有变量
     *
     * @return void
     */
    public function clear(): void
    {
        $this->workflowVariables = [];
        $this->processVariables = [];
        $this->taskVariables = [];
        $this->customVariables = [];
        $this->tenantId = null;
        $this->userId = null;
        $this->initializeSystemVariables();
    }
}
