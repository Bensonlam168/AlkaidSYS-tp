<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Expression\Functions;

/**
 * Workflow Functions Provider | 工作流函数提供器
 *
 * Provides workflow-specific functions for expressions.
 * 为表达式提供工作流特定的函数。
 *
 * Available functions:
 * - var(name, default?) - Get variable
 * - hasVar(name) - Check if variable exists
 * - setVar(name, value) - Set variable (returns value)
 * - workflow(key, default?) - Get workflow variable
 * - process(key, default?) - Get process variable
 * - task(key, default?) - Get task variable
 * - system(key, default?) - Get system variable
 * - input(key, default?) - Get input data
 * - output(nodeId, key?, default?) - Get node output
 * - nodeStatus(nodeId) - Get node status
 * - isCompleted(nodeId) - Check if node completed
 * - isFailed(nodeId) - Check if node failed
 * - isPending(nodeId) - Check if node pending
 * - getNodeResult(nodeId) - Get node result
 * - currentUser() - Get current user ID
 * - currentTenant() - Get current tenant ID
 * - assignee(taskId?) - Get task assignee
 * - hasRole(role) - Check if user has role
 * - hasPermission(permission) - Check if user has permission
 * - env(key, default?) - Get environment variable
 * - config(key, default?) - Get configuration value
 *
 * @package Alkaid\Workflow\Engine\Expression\Functions
 */
class WorkflowFunctions extends AbstractFunctionProvider
{
    /**
     * Runtime context for variable access
     *
     * @var array
     */
    private array $runtimeContext = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->registerWorkflowFunctions();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'workflow';
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return '工作流专用函数';
    }

    /**
     * Set runtime context | 设置运行时上下文
     *
     * @param array $context
     * @return void
     */
    public function setRuntimeContext(array $context): void
    {
        $this->runtimeContext = $context;
    }

    /**
     * Get runtime context | 获取运行时上下文
     *
     * @return array
     */
    public function getRuntimeContext(): array
    {
        return $this->runtimeContext;
    }

    /**
     * Register workflow functions | 注册工作流函数
     *
     * @return void
     */
    private function registerWorkflowFunctions(): void
    {
        // var(name, default?) - Get variable from context
        $this->registerFunction('var', function (string $name, mixed $default = null): mixed {
            return $this->getFromPath($this->runtimeContext, $name, $default);
        });

        // hasVar(name) - Check if variable exists
        $this->registerFunction('hasVar', function (string $name): bool {
            return $this->hasPath($this->runtimeContext, $name);
        });

        // setVar(name, value) - Set variable and return value
        $this->registerFunction('setVar', function (string $name, mixed $value): mixed {
            $this->setPath($this->runtimeContext, $name, $value);
            return $value;
        });

        // workflow(key, default?) - Get workflow variable
        $this->registerFunction('workflow', function (string $key, mixed $default = null): mixed {
            return $this->runtimeContext['workflow'][$key] ?? $default;
        });

        // process(key, default?) - Get process variable
        $this->registerFunction('process', function (string $key, mixed $default = null): mixed {
            return $this->runtimeContext['process'][$key] ?? $default;
        });

        // task(key, default?) - Get task variable
        $this->registerFunction('task', function (string $key, mixed $default = null): mixed {
            return $this->runtimeContext['task'][$key] ?? $default;
        });

        // system(key, default?) - Get system variable
        $this->registerFunction('system', function (string $key, mixed $default = null): mixed {
            return $this->runtimeContext['system'][$key] ?? $default;
        });

        // input(key, default?) - Get input data
        $this->registerFunction('input', function (string $key, mixed $default = null): mixed {
            return $this->runtimeContext['input'][$key] ?? $default;
        });

        // output(nodeId, key?, default?) - Get node output
        $this->registerFunction('output', function (string $nodeId, ?string $key = null, mixed $default = null): mixed {
            $outputs = $this->runtimeContext['outputs'] ?? [];
            if (!isset($outputs[$nodeId])) {
                return $default;
            }
            if ($key === null) {
                return $outputs[$nodeId];
            }
            return $outputs[$nodeId][$key] ?? $default;
        });

        // nodeStatus(nodeId) - Get node execution status
        $this->registerFunction('nodeStatus', function (string $nodeId): string {
            return $this->runtimeContext['nodeStatuses'][$nodeId] ?? 'unknown';
        });

        // isCompleted(nodeId) - Check if node is completed
        $this->registerFunction('isCompleted', function (string $nodeId): bool {
            $status = $this->runtimeContext['nodeStatuses'][$nodeId] ?? null;
            return $status === 'completed' || $status === 'success';
        });

        // isFailed(nodeId) - Check if node failed
        $this->registerFunction('isFailed', function (string $nodeId): bool {
            $status = $this->runtimeContext['nodeStatuses'][$nodeId] ?? null;
            return $status === 'failed' || $status === 'error';
        });

        // isPending(nodeId) - Check if node is pending
        $this->registerFunction('isPending', function (string $nodeId): bool {
            $status = $this->runtimeContext['nodeStatuses'][$nodeId] ?? 'pending';
            return $status === 'pending' || $status === 'waiting';
        });

        // isRunning(nodeId) - Check if node is running
        $this->registerFunction('isRunning', function (string $nodeId): bool {
            $status = $this->runtimeContext['nodeStatuses'][$nodeId] ?? null;
            return $status === 'running' || $status === 'in_progress';
        });

        // isSkipped(nodeId) - Check if node was skipped
        $this->registerFunction('isSkipped', function (string $nodeId): bool {
            $status = $this->runtimeContext['nodeStatuses'][$nodeId] ?? null;
            return $status === 'skipped';
        });

        // getNodeResult(nodeId) - Get node execution result
        $this->registerFunction('getNodeResult', function (string $nodeId): mixed {
            return $this->runtimeContext['nodeResults'][$nodeId] ?? null;
        });

        // getNodeError(nodeId) - Get node error message
        $this->registerFunction('getNodeError', function (string $nodeId): ?string {
            return $this->runtimeContext['nodeErrors'][$nodeId] ?? null;
        });

        // currentUser() - Get current user ID
        $this->registerFunction('currentUser', function (): ?int {
            return $this->runtimeContext['_user_id'] ?? null;
        });

        // currentTenant() - Get current tenant ID
        $this->registerFunction('currentTenant', function (): ?int {
            return $this->runtimeContext['_tenant_id'] ?? null;
        });

        // assignee(taskId?) - Get task assignee
        $this->registerFunction('assignee', function (?string $taskId = null): mixed {
            if ($taskId === null) {
                return $this->runtimeContext['task']['assignee'] ?? null;
            }
            return $this->runtimeContext['taskAssignees'][$taskId] ?? null;
        });

        // initiator() - Get workflow initiator
        $this->registerFunction('initiator', function (): mixed {
            return $this->runtimeContext['workflow']['initiator']
                ?? $this->runtimeContext['process']['initiator']
                ?? null;
        });

        // hasRole(role) - Check if current user has role
        $this->registerFunction('hasRole', function (string $role): bool {
            $roles = $this->runtimeContext['_user_roles'] ?? [];
            return in_array($role, $roles, true);
        });

        // hasAnyRole(roles) - Check if current user has any of the roles
        $this->registerFunction('hasAnyRole', function (array $roles): bool {
            $userRoles = $this->runtimeContext['_user_roles'] ?? [];
            return count(array_intersect($roles, $userRoles)) > 0;
        });

        // hasAllRoles(roles) - Check if current user has all roles
        $this->registerFunction('hasAllRoles', function (array $roles): bool {
            $userRoles = $this->runtimeContext['_user_roles'] ?? [];
            return count(array_diff($roles, $userRoles)) === 0;
        });

        // hasPermission(permission) - Check if current user has permission
        $this->registerFunction('hasPermission', function (string $permission): bool {
            $permissions = $this->runtimeContext['_user_permissions'] ?? [];
            return in_array($permission, $permissions, true);
        });

        // env(key, default?) - Get environment variable
        $this->registerFunction('env', function (string $key, mixed $default = null): mixed {
            $value = getenv($key);
            return $value !== false ? $value : $default;
        });

        // config(key, default?) - Get configuration value
        $this->registerFunction('config', function (string $key, mixed $default = null): mixed {
            return $this->runtimeContext['_config'][$key] ?? $default;
        });

        // instanceId() - Get current workflow instance ID
        $this->registerFunction('instanceId', function (): ?string {
            return $this->runtimeContext['_instance_id'] ?? null;
        });

        // workflowId() - Get current workflow definition ID
        $this->registerFunction('workflowId', function (): ?string {
            return $this->runtimeContext['_workflow_id'] ?? null;
        });

        // currentNodeId() - Get current node ID
        $this->registerFunction('currentNodeId', function (): ?string {
            return $this->runtimeContext['_current_node_id'] ?? null;
        });

        // executionPath() - Get execution path (list of executed node IDs)
        $this->registerFunction('executionPath', function (): array {
            return $this->runtimeContext['_execution_path'] ?? [];
        });

        // hasExecuted(nodeId) - Check if node has been executed
        $this->registerFunction('hasExecuted', function (string $nodeId): bool {
            $path = $this->runtimeContext['_execution_path'] ?? [];
            return in_array($nodeId, $path, true);
        });

        // loopIndex() - Get current loop index (for loop nodes)
        $this->registerFunction('loopIndex', function (): int {
            return $this->runtimeContext['_loop_index'] ?? 0;
        });

        // loopItem() - Get current loop item
        $this->registerFunction('loopItem', function (): mixed {
            return $this->runtimeContext['_loop_item'] ?? null;
        });

        // loopCount() - Get total loop count
        $this->registerFunction('loopCount', function (): int {
            return $this->runtimeContext['_loop_count'] ?? 0;
        });

        // isFirstIteration() - Check if first iteration
        $this->registerFunction('isFirstIteration', function (): bool {
            return ($this->runtimeContext['_loop_index'] ?? 0) === 0;
        });

        // isLastIteration() - Check if last iteration
        $this->registerFunction('isLastIteration', function (): bool {
            $index = $this->runtimeContext['_loop_index'] ?? 0;
            $count = $this->runtimeContext['_loop_count'] ?? 0;
            return $count > 0 && $index === $count - 1;
        });

        // branch(condition, trueValue, falseValue) - Conditional branching
        $this->registerFunction('branch', function (bool $condition, mixed $trueValue, mixed $falseValue): mixed {
            return $condition ? $trueValue : $falseValue;
        });

        // coalesce(...values) - Return first non-null value
        $this->registerFunction('coalesce', function (...$values): mixed {
            foreach ($values as $value) {
                if ($value !== null) {
                    return $value;
                }
            }
            return null;
        });

        // default(value, default) - Return default if value is null/empty
        $this->registerFunction('default', function (mixed $value, mixed $default): mixed {
            return empty($value) ? $default : $value;
        });

        // throw(message) - Throw an exception (for validation)
        $this->registerFunction('throw', function (string $message): never {
            throw new \RuntimeException($message);
        });

        // debug(value) - Return value for debugging (logged)
        $this->registerFunction('debug', function (mixed $value): mixed {
            // In production, this would log to workflow debug log
            return $value;
        });
    }

    /**
     * Get value from path | 从路径获取值
     *
     * @param array $data
     * @param string $path
     * @param mixed $default
     * @return mixed
     */
    private function getFromPath(array $data, string $path, mixed $default = null): mixed
    {
        $keys = explode('.', $path);
        foreach ($keys as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return $default;
            }
            $data = $data[$key];
        }
        return $data;
    }

    /**
     * Check if path exists | 检查路径是否存在
     *
     * @param array $data
     * @param string $path
     * @return bool
     */
    private function hasPath(array $data, string $path): bool
    {
        $keys = explode('.', $path);
        foreach ($keys as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return false;
            }
            $data = $data[$key];
        }
        return true;
    }

    /**
     * Set value at path | 在路径设置值
     *
     * @param array &$data
     * @param string $path
     * @param mixed $value
     * @return void
     */
    private function setPath(array &$data, string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $current = &$data;
        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        $current = $value;
    }
}
