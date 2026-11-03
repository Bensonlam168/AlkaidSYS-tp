<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers\Types;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;

/**
 * 抽象触发器基类
 *
 * 提供触发器的通用实现，子类只需实现特定的触发逻辑
 *
 * @package Alkaid\Workflow\Engine\Triggers\Types
 */
abstract class AbstractTrigger implements TriggerInterface
{
    /**
     * 已注册的工作流配置
     * workflowId => config
     *
     * @var array<string, array>
     */
    protected array $registrations = [];

    /**
     * 配置验证错误
     *
     * @var array
     */
    protected array $validationErrors = [];

    /**
     * 获取触发器类型（子类实现）
     */
    abstract public function getType(): string;

    /**
     * 获取触发器名称（子类实现）
     */
    abstract public function getName(): string;

    /**
     * 获取触发器描述（子类实现）
     */
    abstract public function getDescription(): string;

    /**
     * 获取配置 Schema（子类实现）
     */
    abstract public function getConfigSchema(): array;

    /**
     * 执行触发逻辑（子类实现）
     *
     * @param TriggerContext $context 触发上下文
     * @param array $config 触发器配置
     * @return TriggerResult
     */
    abstract protected function doTrigger(TriggerContext $context, array $config): TriggerResult;

    /**
     * 验证触发器配置
     *
     * @param array $config 配置数据
     * @return bool 验证是否通过
     */
    public function validateConfig(array $config): bool
    {
        $this->validationErrors = [];
        $schema = $this->getConfigSchema();

        // 检查必填字段
        foreach ($schema['required'] ?? [] as $field) {
            if (!isset($config[$field]) || $config[$field] === '') {
                $this->validationErrors[] = sprintf('字段 "%s" 是必填的', $field);
            }
        }

        // 检查字段类型
        foreach ($schema['properties'] ?? [] as $field => $rules) {
            if (!isset($config[$field])) {
                continue;
            }

            $value = $config[$field];
            $type = $rules['type'] ?? null;

            if ($type && !$this->validateType($value, $type)) {
                $this->validationErrors[] = sprintf('字段 "%s" 类型必须为 %s', $field, $type);
            }

            // 检查枚举值
            if (isset($rules['enum']) && !in_array($value, $rules['enum'], true)) {
                $this->validationErrors[] = sprintf(
                    '字段 "%s" 的值必须是以下之一：%s',
                    $field,
                    implode(', ', $rules['enum'])
                );
            }

            // 检查最小值
            if (isset($rules['minimum']) && $value < $rules['minimum']) {
                $this->validationErrors[] = sprintf('字段 "%s" 最小值为 %s', $field, $rules['minimum']);
            }

            // 检查最大值
            if (isset($rules['maximum']) && $value > $rules['maximum']) {
                $this->validationErrors[] = sprintf('字段 "%s" 最大值为 %s', $field, $rules['maximum']);
            }

            // 检查最小长度
            if (isset($rules['minLength']) && strlen((string)$value) < $rules['minLength']) {
                $this->validationErrors[] = sprintf('字段 "%s" 最小长度为 %d', $field, $rules['minLength']);
            }

            // 检查最大长度
            if (isset($rules['maxLength']) && strlen((string)$value) > $rules['maxLength']) {
                $this->validationErrors[] = sprintf('字段 "%s" 最大长度为 %d', $field, $rules['maxLength']);
            }

            // 检查正则模式
            if (isset($rules['pattern']) && !preg_match($rules['pattern'], (string)$value)) {
                $this->validationErrors[] = sprintf('字段 "%s" 格式不正确', $field);
            }
        }

        return empty($this->validationErrors);
    }

    /**
     * 验证值类型
     *
     * @param mixed $value 值
     * @param string $type 期望类型
     * @return bool
     */
    protected function validateType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer', 'int' => is_int($value),
            'number' => is_numeric($value),
            'boolean', 'bool' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value) || is_array($value),
            'null' => is_null($value),
            default => true,
        };
    }

    /**
     * 获取配置验证错误信息
     *
     * @return array 错误信息列表
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * 注册触发器
     *
     * @param string $workflowId 工作流ID
     * @param array $config 触发器配置
     * @return void
     */
    public function register(string $workflowId, array $config): void
    {
        if (!$this->validateConfig($config)) {
            throw new \InvalidArgumentException(
                '触发器配置验证失败：' . implode('; ', $this->validationErrors)
            );
        }

        $this->registrations[$workflowId] = $config;
        $this->onRegister($workflowId, $config);
    }

    /**
     * 注册时的钩子方法（子类可覆盖）
     *
     * @param string $workflowId 工作流ID
     * @param array $config 配置
     * @return void
     */
    protected function onRegister(string $workflowId, array $config): void
    {
        // 默认空实现，子类可覆盖
    }

    /**
     * 取消注册触发器
     *
     * @param string $workflowId 工作流ID
     * @return void
     */
    public function unregister(string $workflowId): void
    {
        if (isset($this->registrations[$workflowId])) {
            $config = $this->registrations[$workflowId];
            $this->onUnregister($workflowId, $config);
            unset($this->registrations[$workflowId]);
        }
    }

    /**
     * 取消注册时的钩子方法（子类可覆盖）
     *
     * @param string $workflowId 工作流ID
     * @param array $config 配置
     * @return void
     */
    protected function onUnregister(string $workflowId, array $config): void
    {
        // 默认空实现，子类可覆盖
    }

    /**
     * 检查是否应该触发
     *
     * @param TriggerContext $context 触发上下文
     * @return bool 是否应该触发
     */
    public function shouldTrigger(TriggerContext $context): bool
    {
        $workflowId = $context->getWorkflowId();

        // 检查工作流是否已注册
        if ($workflowId && !$this->isRegistered($workflowId)) {
            return false;
        }

        // 检查触发器类型是否匹配
        if ($context->getTriggerType() !== $this->getType()) {
            return false;
        }

        // 子类可以覆盖此方法添加更多条件
        return $this->checkTriggerConditions($context);
    }

    /**
     * 检查触发条件（子类可覆盖）
     *
     * @param TriggerContext $context 触发上下文
     * @return bool
     */
    protected function checkTriggerConditions(TriggerContext $context): bool
    {
        return true;
    }

    /**
     * 执行触发
     *
     * @param TriggerContext $context 触发上下文
     * @return TriggerResult 触发结果
     */
    public function trigger(TriggerContext $context): TriggerResult
    {
        $workflowId = $context->getWorkflowId();
        $config = $workflowId ? ($this->registrations[$workflowId] ?? []) : [];

        try {
            // 检查是否应该触发
            if (!$this->shouldTrigger($context)) {
                return TriggerResult::skipped('触发条件不满足')
                    ->setTriggerType($this->getType())
                    ->setWorkflowId($workflowId);
            }

            // 执行触发逻辑
            $result = $this->doTrigger($context, $config);

            // 设置触发器信息
            $result->setTriggerType($this->getType())
                ->setWorkflowId($workflowId);

            return $result;
        } catch (\Throwable $e) {
            return TriggerResult::fromException($e)
                ->setTriggerType($this->getType())
                ->setWorkflowId($workflowId);
        }
    }

    /**
     * 检查触发器是否已注册
     *
     * @param string $workflowId 工作流ID
     * @return bool 是否已注册
     */
    public function isRegistered(string $workflowId): bool
    {
        return isset($this->registrations[$workflowId]);
    }

    /**
     * 获取已注册的工作流ID列表
     *
     * @return array 工作流ID列表
     */
    public function getRegisteredWorkflows(): array
    {
        return array_keys($this->registrations);
    }

    /**
     * 获取工作流的配置
     *
     * @param string $workflowId 工作流ID
     * @return array|null 配置数据
     */
    public function getWorkflowConfig(string $workflowId): ?array
    {
        return $this->registrations[$workflowId] ?? null;
    }

    /**
     * 清除所有注册
     *
     * @return void
     */
    public function clearRegistrations(): void
    {
        foreach ($this->registrations as $workflowId => $config) {
            $this->onUnregister($workflowId, $config);
        }
        $this->registrations = [];
    }
}
