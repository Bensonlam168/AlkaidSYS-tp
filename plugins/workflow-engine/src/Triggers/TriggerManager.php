<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\Types\DataEventTrigger;
use Alkaid\Workflow\Engine\Triggers\Types\ManualTrigger;
use Alkaid\Workflow\Engine\Triggers\Types\ScheduleTrigger;
use Alkaid\Workflow\Engine\Triggers\Types\WebhookTrigger;

/**
 * 工作流触发器管理器
 *
 * 负责管理所有触发器类型的注册、分发和执行。
 * 支持手动触发、Webhook 触发、定时触发和数据事件触发。
 *
 * @package Alkaid\Workflow\Engine\Triggers
 */
class TriggerManager
{
    /**
     * 已注册的触发器类型
     * type => TriggerInterface
     *
     * @var array<string, TriggerInterface>
     */
    protected array $triggerTypes = [];

    /**
     * 工作流触发器配置
     * workflowId => [type => config]
     *
     * @var array<string, array>
     */
    protected array $workflowTriggers = [];

    /**
     * 触发事件监听器
     * event => [callable]
     *
     * @var array<string, array>
     */
    protected array $listeners = [];

    /**
     * 默认租户ID
     *
     * @var int|null
     */
    protected ?int $defaultTenantId = null;

    /**
     * 构造函数
     *
     * @param bool $autoRegisterBuiltinTypes 是否自动注册内置触发器类型
     */
    public function __construct(bool $autoRegisterBuiltinTypes = true)
    {
        if ($autoRegisterBuiltinTypes) {
            $this->registerBuiltinTriggerTypes();
        }
    }

    /**
     * 注册内置触发器类型
     *
     * @return void
     */
    protected function registerBuiltinTriggerTypes(): void
    {
        $this->registerTriggerType(new ManualTrigger());
        $this->registerTriggerType(new WebhookTrigger());
        $this->registerTriggerType(new ScheduleTrigger());
        $this->registerTriggerType(new DataEventTrigger());
    }

    /**
     * 注册触发器类型
     *
     * @param TriggerInterface $trigger 触发器实例
     * @return static
     */
    public function registerTriggerType(TriggerInterface $trigger): static
    {
        $this->triggerTypes[$trigger->getType()] = $trigger;
        $this->emit('trigger_type.registered', $trigger);
        return $this;
    }

    /**
     * 注销触发器类型
     *
     * @param string $type 触发器类型
     * @return static
     */
    public function unregisterTriggerType(string $type): static
    {
        if (isset($this->triggerTypes[$type])) {
            $trigger = $this->triggerTypes[$type];
            $trigger->clearRegistrations();
            unset($this->triggerTypes[$type]);
            $this->emit('trigger_type.unregistered', $trigger);
        }
        return $this;
    }

    /**
     * 获取触发器类型实例
     *
     * @param string $type 触发器类型
     * @return TriggerInterface|null
     */
    public function getTriggerType(string $type): ?TriggerInterface
    {
        return $this->triggerTypes[$type] ?? null;
    }

    /**
     * 获取所有已注册的触发器类型
     *
     * @return array<string, TriggerInterface>
     */
    public function getTriggerTypes(): array
    {
        return $this->triggerTypes;
    }

    /**
     * 检查触发器类型是否已注册
     *
     * @param string $type 触发器类型
     * @return bool
     */
    public function hasTriggerType(string $type): bool
    {
        return isset($this->triggerTypes[$type]);
    }

    /**
     * 为工作流注册触发器
     *
     * @param string $workflowId 工作流ID
     * @param string $triggerType 触发器类型
     * @param array $config 触发器配置
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function registerTrigger(string $workflowId, string $triggerType, array $config = []): bool
    {
        $trigger = $this->getTriggerType($triggerType);
        if (!$trigger) {
            throw new \InvalidArgumentException("未知的触发器类型: {$triggerType}");
        }

        // 验证配置
        if (!$trigger->validateConfig($config)) {
            $errors = $trigger->getValidationErrors();
            throw new \InvalidArgumentException(
                '触发器配置验证失败: ' . implode('; ', $errors)
            );
        }

        // 注册到触发器
        $trigger->register($workflowId, $config);

        // 保存工作流触发器配置
        if (!isset($this->workflowTriggers[$workflowId])) {
            $this->workflowTriggers[$workflowId] = [];
        }
        $this->workflowTriggers[$workflowId][$triggerType] = $config;

        $this->emit('trigger.registered', [
            'workflow_id' => $workflowId,
            'trigger_type' => $triggerType,
            'config' => $config,
        ]);

        return true;
    }

    /**
     * 取消工作流触发器注册
     *
     * @param string $workflowId 工作流ID
     * @param string|null $triggerType 触发器类型（为空则取消所有）
     * @return bool
     */
    public function unregisterTrigger(string $workflowId, ?string $triggerType = null): bool
    {
        if ($triggerType) {
            // 取消特定类型的触发器
            $trigger = $this->getTriggerType($triggerType);
            if ($trigger) {
                $trigger->unregister($workflowId);
            }
            if (isset($this->workflowTriggers[$workflowId][$triggerType])) {
                unset($this->workflowTriggers[$workflowId][$triggerType]);
            }
        } else {
            // 取消该工作流的所有触发器
            foreach ($this->triggerTypes as $trigger) {
                $trigger->unregister($workflowId);
            }
            unset($this->workflowTriggers[$workflowId]);
        }

        $this->emit('trigger.unregistered', [
            'workflow_id' => $workflowId,
            'trigger_type' => $triggerType,
        ]);

        return true;
    }

    /**
     * 触发工作流
     *
     * @param TriggerContext $context 触发上下文
     * @return TriggerResult
     */
    public function trigger(TriggerContext $context): TriggerResult
    {
        $triggerType = $context->getTriggerType();
        $trigger = $this->getTriggerType($triggerType);

        if (!$trigger) {
            return TriggerResult::failed("未知的触发器类型: {$triggerType}");
        }

        $this->emit('trigger.before', $context);

        $result = $trigger->trigger($context);

        $this->emit('trigger.after', [
            'context' => $context,
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * 手动触发工作流
     *
     * @param string $workflowId 工作流ID
     * @param int|null $tenantId 租户ID
     * @param int|null $userId 用户ID
     * @param array $data 输入数据
     * @return TriggerResult
     */
    public function triggerManual(
        string $workflowId,
        ?int $tenantId = null,
        ?int $userId = null,
        array $data = []
    ): TriggerResult {
        $context = TriggerContext::manual($workflowId, $tenantId ?? $this->defaultTenantId, $userId, $data);
        return $this->trigger($context);
    }

    /**
     * 通过 Webhook 触发工作流
     *
     * @param string $workflowId 工作流ID
     * @param array $data 请求数据
     * @param array $headers 请求头
     * @param array $query 查询参数
     * @param int|null $tenantId 租户ID
     * @return TriggerResult
     */
    public function triggerWebhook(
        string $workflowId,
        array $data = [],
        array $headers = [],
        array $query = [],
        ?int $tenantId = null
    ): TriggerResult {
        $context = TriggerContext::webhook($workflowId, $data, $headers, $query, $tenantId ?? $this->defaultTenantId);
        return $this->trigger($context);
    }

    /**
     * 通过定时任务触发工作流
     *
     * @param string $workflowId 工作流ID
     * @param string $cronExpression Cron 表达式
     * @param float|null $scheduledTime 计划执行时间
     * @param array $data 附加数据
     * @return TriggerResult
     */
    public function triggerSchedule(
        string $workflowId,
        string $cronExpression,
        ?float $scheduledTime = null,
        array $data = []
    ): TriggerResult {
        $context = TriggerContext::schedule($workflowId, $cronExpression, $scheduledTime, $data);
        return $this->trigger($context);
    }

    /**
     * 通过数据事件触发工作流
     *
     * @param string $workflowId 工作流ID
     * @param string $eventType 事件类型
     * @param array $eventData 事件数据
     * @param int|null $tenantId 租户ID
     * @return TriggerResult
     */
    public function triggerDataEvent(
        string $workflowId,
        string $eventType,
        array $eventData,
        ?int $tenantId = null
    ): TriggerResult {
        $context = TriggerContext::dataEvent($workflowId, $eventType, $eventData, $tenantId ?? $this->defaultTenantId);
        return $this->trigger($context);
    }

    /**
     * 批量触发数据事件（针对多个工作流）
     *
     * @param string $eventType 事件类型
     * @param array $eventData 事件数据
     * @param int|null $tenantId 租户ID
     * @return array<string, TriggerResult> workflowId => TriggerResult
     */
    public function dispatchDataEvent(
        string $eventType,
        array $eventData,
        ?int $tenantId = null
    ): array {
        $results = [];
        $dataEventTrigger = $this->getTriggerType(TriggerInterface::TYPE_DATA_EVENT);

        if (!$dataEventTrigger) {
            return $results;
        }

        // 遍历所有注册了数据事件触发器的工作流
        foreach ($dataEventTrigger->getRegisteredWorkflows() as $workflowId) {
            $context = TriggerContext::dataEvent(
                $workflowId,
                $eventType,
                $eventData,
                $tenantId ?? $this->defaultTenantId
            );

            // 检查是否应该触发
            if ($dataEventTrigger->shouldTrigger($context)) {
                $results[$workflowId] = $this->trigger($context);
            }
        }

        return $results;
    }

    /**
     * 检查定时任务并触发
     *
     * @return array<string, TriggerResult> workflowId => TriggerResult
     */
    public function checkAndTriggerSchedules(): array
    {
        $results = [];
        $scheduleTrigger = $this->getTriggerType(TriggerInterface::TYPE_SCHEDULE);

        if (!$scheduleTrigger instanceof ScheduleTrigger) {
            return $results;
        }

        $now = time();

        foreach ($scheduleTrigger->getRegisteredWorkflows() as $workflowId) {
            $nextExecution = $scheduleTrigger->getNextExecution($workflowId);

            if ($nextExecution && $nextExecution <= $now) {
                $config = $scheduleTrigger->getWorkflowConfig($workflowId);
                $context = TriggerContext::schedule(
                    $workflowId,
                    $config['cron_expression'] ?? '',
                    $nextExecution,
                    $config['input_data'] ?? []
                );

                $results[$workflowId] = $this->trigger($context);
            }
        }

        return $results;
    }

    /**
     * 获取工作流的触发器配置
     *
     * @param string $workflowId 工作流ID
     * @return array
     */
    public function getWorkflowTriggers(string $workflowId): array
    {
        return $this->workflowTriggers[$workflowId] ?? [];
    }

    /**
     * 获取工作流特定类型的触发器配置
     *
     * @param string $workflowId 工作流ID
     * @param string $triggerType 触发器类型
     * @return array|null
     */
    public function getWorkflowTriggerConfig(string $workflowId, string $triggerType): ?array
    {
        return $this->workflowTriggers[$workflowId][$triggerType] ?? null;
    }

    /**
     * 检查工作流是否注册了特定触发器
     *
     * @param string $workflowId 工作流ID
     * @param string $triggerType 触发器类型
     * @return bool
     */
    public function hasWorkflowTrigger(string $workflowId, string $triggerType): bool
    {
        $trigger = $this->getTriggerType($triggerType);
        return $trigger && $trigger->isRegistered($workflowId);
    }

    /**
     * 设置默认租户ID
     *
     * @param int|null $tenantId 租户ID
     * @return static
     */
    public function setDefaultTenantId(?int $tenantId): static
    {
        $this->defaultTenantId = $tenantId;
        return $this;
    }

    /**
     * 获取默认租户ID
     *
     * @return int|null
     */
    public function getDefaultTenantId(): ?int
    {
        return $this->defaultTenantId;
    }

    /**
     * 添加事件监听器
     *
     * @param string $event 事件名称
     * @param callable $listener 监听器
     * @return static
     */
    public function on(string $event, callable $listener): static
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][] = $listener;
        return $this;
    }

    /**
     * 移除事件监听器
     *
     * @param string $event 事件名称
     * @param callable|null $listener 监听器（为空则移除所有）
     * @return static
     */
    public function off(string $event, ?callable $listener = null): static
    {
        if (!isset($this->listeners[$event])) {
            return $this;
        }

        if ($listener === null) {
            unset($this->listeners[$event]);
        } else {
            $this->listeners[$event] = array_filter(
                $this->listeners[$event],
                fn ($l) => $l !== $listener
            );
        }

        return $this;
    }

    /**
     * 触发事件
     *
     * @param string $event 事件名称
     * @param mixed $data 事件数据
     * @return void
     */
    protected function emit(string $event, mixed $data = null): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            try {
                $listener($data);
            } catch (\Throwable $e) {
                // 记录错误但不中断执行
                error_log("触发器事件监听器错误 [{$event}]: " . $e->getMessage());
            }
        }
    }

    /**
     * 获取所有已注册的触发器类型信息
     *
     * @return array
     */
    public function getTriggerTypesInfo(): array
    {
        $info = [];
        foreach ($this->triggerTypes as $type => $trigger) {
            $info[$type] = [
                'type' => $trigger->getType(),
                'name' => $trigger->getName(),
                'description' => $trigger->getDescription(),
                'config_schema' => $trigger->getConfigSchema(),
                'registered_workflows' => $trigger->getRegisteredWorkflows(),
            ];
        }
        return $info;
    }

    /**
     * 获取 Webhook 触发器的 URL
     *
     * @param string $workflowId 工作流ID
     * @return string|null
     */
    public function getWebhookUrl(string $workflowId): ?string
    {
        $webhookTrigger = $this->getTriggerType(TriggerInterface::TYPE_WEBHOOK);
        if ($webhookTrigger instanceof WebhookTrigger) {
            return $webhookTrigger->getWebhookUrl($workflowId);
        }
        return null;
    }

    /**
     * 获取定时触发器的下次执行时间
     *
     * @param string $workflowId 工作流ID
     * @return int|null
     */
    public function getNextScheduleExecution(string $workflowId): ?int
    {
        $scheduleTrigger = $this->getTriggerType(TriggerInterface::TYPE_SCHEDULE);
        if ($scheduleTrigger instanceof ScheduleTrigger) {
            return $scheduleTrigger->getNextExecution($workflowId);
        }
        return null;
    }

    /**
     * 清理所有注册
     *
     * @return void
     */
    public function clearAll(): void
    {
        foreach ($this->triggerTypes as $trigger) {
            $trigger->clearRegistrations();
        }
        $this->workflowTriggers = [];
        $this->emit('manager.cleared');
    }

    /**
     * 从配置数组创建触发器管理器
     *
     * @param array $config 配置数组
     * @return static
     */
    public static function fromConfig(array $config): static
    {
        $manager = new static($config['auto_register_builtin'] ?? true);

        if (isset($config['default_tenant_id'])) {
            $manager->setDefaultTenantId($config['default_tenant_id']);
        }

        // 注册工作流触发器
        foreach ($config['workflows'] ?? [] as $workflowId => $triggers) {
            foreach ($triggers as $triggerType => $triggerConfig) {
                $manager->registerTrigger($workflowId, $triggerType, $triggerConfig);
            }
        }

        return $manager;
    }

    /**
     * 导出当前配置
     *
     * @return array
     */
    public function exportConfig(): array
    {
        return [
            'auto_register_builtin' => true,
            'default_tenant_id' => $this->defaultTenantId,
            'workflows' => $this->workflowTriggers,
            'trigger_types' => array_keys($this->triggerTypes),
        ];
    }
}
