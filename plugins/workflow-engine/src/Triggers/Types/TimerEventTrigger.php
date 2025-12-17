<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers\Types;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;

/**
 * Timer Event Trigger | 定时器事件触发器
 *
 * Triggers workflow based on timer events (BPMN compliant).
 * Supports different timer types: date, duration, and cycle.
 * 基于定时器事件触发工作流（符合 BPMN 标准）。
 * 支持不同的定时器类型：日期、持续时间和周期。
 *
 * Timer types (ISO 8601):
 * - timeDate: Specific date/time (e.g., "2024-12-31T23:59:59")
 * - timeDuration: Duration from now (e.g., "PT1H30M" = 1 hour 30 minutes)
 * - timeCycle: Repeating cycle (e.g., "R3/PT1H" = repeat 3 times every hour)
 *
 * Note: This is different from ScheduleTrigger which is for cron-based scheduling.
 * TimerEventTrigger is designed for BPMN timer events within workflow execution.
 *
 * @package Alkaid\Workflow\Engine\Triggers\Types
 */
class TimerEventTrigger extends AbstractTrigger
{
    /**
     * Timer types | 定时器类型
     */
    public const TIMER_DATE = 'timeDate';         // Specific date/time
    public const TIMER_DURATION = 'timeDuration'; // Duration from reference time
    public const TIMER_CYCLE = 'timeCycle';       // Repeating cycle

    /**
     * Timer event types | 定时器事件类型
     */
    public const EVENT_START = 'start';           // Start event timer
    public const EVENT_INTERMEDIATE = 'intermediate'; // Intermediate catch event
    public const EVENT_BOUNDARY = 'boundary';     // Boundary timer event

    /**
     * Get trigger type | 获取触发器类型
     */
    public function getType(): string
    {
        return TriggerInterface::TYPE_TIMER_EVENT;
    }

    /**
     * Get trigger name | 获取触发器名称
     */
    public function getName(): string
    {
        return '定时器事件触发器';
    }

    /**
     * Get trigger description | 获取触发器描述
     */
    public function getDescription(): string
    {
        return 'BPMN 2.0 定时器事件触发器，支持指定时间、持续时间和周期性触发';
    }

    /**
     * Get configuration schema | 获取配置 Schema
     */
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'title' => '触发器名称',
                    'description' => '触发器的显示名称',
                    'maxLength' => 100,
                ],
                'timer_type' => [
                    'type' => 'string',
                    'title' => '定时器类型',
                    'description' => '定时器的类型',
                    'enum' => [self::TIMER_DATE, self::TIMER_DURATION, self::TIMER_CYCLE],
                    'default' => self::TIMER_DURATION,
                ],
                'timer_value' => [
                    'type' => 'string',
                    'title' => '定时器值',
                    'description' => 'ISO 8601 格式的时间表达式',
                ],
                'event_type' => [
                    'type' => 'string',
                    'title' => '事件类型',
                    'description' => '定时器事件的类型',
                    'enum' => [self::EVENT_START, self::EVENT_INTERMEDIATE, self::EVENT_BOUNDARY],
                    'default' => self::EVENT_START,
                ],
                'reference_time' => [
                    'type' => 'string',
                    'title' => '参考时间',
                    'description' => '持续时间计算的参考时间（变量路径）',
                ],
                'cancel_activity' => [
                    'type' => 'boolean',
                    'title' => '取消活动',
                    'description' => '对于边界事件，是否取消关联的活动',
                    'default' => false,
                ],
                'max_repetitions' => [
                    'type' => 'integer',
                    'title' => '最大重复次数',
                    'description' => '周期定时器的最大执行次数（0 = 无限）',
                    'minimum' => 0,
                    'maximum' => 10000,
                    'default' => 0,
                ],
                'timezone' => [
                    'type' => 'string',
                    'title' => '时区',
                    'description' => '定时器使用的时区',
                    'default' => 'Asia/Shanghai',
                ],
                'end_date' => [
                    'type' => 'string',
                    'title' => '结束日期',
                    'description' => '周期定时器的结束日期',
                ],
                'skip_missed' => [
                    'type' => 'boolean',
                    'title' => '跳过错过的执行',
                    'description' => '是否跳过系统停机期间错过的执行',
                    'default' => true,
                ],
                'execution_data' => [
                    'type' => 'object',
                    'title' => '执行数据',
                    'description' => '定时器触发时传递的数据',
                ],
            ],
            'required' => ['timer_type', 'timer_value'],
        ];
    }

    /**
     * Check trigger conditions | 检查触发条件
     *
     * @param TriggerContext $context
     * @return bool
     */
    protected function checkTriggerConditions(TriggerContext $context): bool
    {
        $workflowId = $context->getWorkflowId();
        if (!$workflowId) {
            return false;
        }

        $config = $this->getWorkflowConfig($workflowId);
        if (!$config) {
            return false;
        }

        $data = $context->getData();

        // Check if this is a timer event
        $isTimerEvent = $data['is_timer_event'] ?? $context->getMeta('is_timer_event', false);
        if (!$isTimerEvent) {
            return false;
        }

        // Get timer info from context
        $timerType = $config['timer_type'] ?? self::TIMER_DURATION;
        $timerValue = $config['timer_value'] ?? null;

        if (!$timerValue) {
            return false;
        }

        // Check if timer should fire based on type
        return match ($timerType) {
            self::TIMER_DATE => $this->checkTimeDateTrigger($timerValue, $config),
            self::TIMER_DURATION => $this->checkTimeDurationTrigger($timerValue, $config, $context),
            self::TIMER_CYCLE => $this->checkTimeCycleTrigger($timerValue, $config, $context),
            default => false,
        };
    }

    /**
     * Check time date trigger | 检查日期时间触发
     *
     * @param string $timerValue ISO 8601 date/time
     * @param array $config
     * @return bool
     */
    protected function checkTimeDateTrigger(string $timerValue, array $config): bool
    {
        $timezone = $config['timezone'] ?? 'Asia/Shanghai';
        $tz = new \DateTimeZone($timezone);

        try {
            $targetTime = new \DateTime($timerValue, $tz);
            $now = new \DateTime('now', $tz);

            // Timer fires if current time is at or past target time
            return $now >= $targetTime;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check time duration trigger | 检查持续时间触发
     *
     * @param string $timerValue ISO 8601 duration (e.g., PT1H30M)
     * @param array $config
     * @param TriggerContext $context
     * @return bool
     */
    protected function checkTimeDurationTrigger(string $timerValue, array $config, TriggerContext $context): bool
    {
        // Get reference time
        $referenceTime = $this->getReferenceTime($config, $context);
        if (!$referenceTime) {
            return false;
        }

        try {
            $interval = new \DateInterval($timerValue);
            $targetTime = clone $referenceTime;
            $targetTime->add($interval);

            $timezone = $config['timezone'] ?? 'Asia/Shanghai';
            $now = new \DateTime('now', new \DateTimeZone($timezone));

            return $now >= $targetTime;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check time cycle trigger | 检查周期触发
     *
     * @param string $timerValue ISO 8601 repeating interval (e.g., R3/PT1H)
     * @param array $config
     * @param TriggerContext $context
     * @return bool
     */
    protected function checkTimeCycleTrigger(string $timerValue, array $config, TriggerContext $context): bool
    {
        // Parse cycle expression: R[n]/duration or R[n]/start/duration
        $parsed = $this->parseCycleExpression($timerValue);
        if (!$parsed) {
            return false;
        }

        $maxRepetitions = $parsed['repetitions'] ?? ($config['max_repetitions'] ?? 0);
        $executionCount = $context->getMeta('timer_execution_count', 0);

        // Check max repetitions
        if ($maxRepetitions > 0 && $executionCount >= $maxRepetitions) {
            return false;
        }

        // Check end date
        if (!empty($config['end_date'])) {
            $timezone = $config['timezone'] ?? 'Asia/Shanghai';
            $tz = new \DateTimeZone($timezone);
            try {
                $endDate = new \DateTime($config['end_date'], $tz);
                $now = new \DateTime('now', $tz);
                if ($now > $endDate) {
                    return false;
                }
            } catch (\Exception $e) {
                // Ignore invalid end date
            }
        }

        // Check if it's time for next execution
        return $this->checkCycleExecution($parsed, $config, $context);
    }

    /**
     * Parse cycle expression | 解析周期表达式
     *
     * @param string $expression
     * @return array|null
     */
    protected function parseCycleExpression(string $expression): ?array
    {
        // Format: R[n]/duration or R[n]/start/duration
        if (!preg_match('/^R(\d*)\/(.+)$/', $expression, $matches)) {
            return null;
        }

        $repetitions = $matches[1] !== '' ? (int)$matches[1] : 0; // 0 = infinite
        $rest = $matches[2];

        // Check if there's a start time
        $parts = explode('/', $rest, 2);
        if (count($parts) === 2) {
            return [
                'repetitions' => $repetitions,
                'start' => $parts[0],
                'duration' => $parts[1],
            ];
        }

        return [
            'repetitions' => $repetitions,
            'start' => null,
            'duration' => $parts[0],
        ];
    }

    /**
     * Check cycle execution | 检查周期执行
     *
     * @param array $parsed
     * @param array $config
     * @param TriggerContext $context
     * @return bool
     */
    protected function checkCycleExecution(array $parsed, array $config, TriggerContext $context): bool
    {
        $timezone = $config['timezone'] ?? 'Asia/Shanghai';
        $tz = new \DateTimeZone($timezone);
        $now = new \DateTime('now', $tz);

        // Get last execution time
        $lastExecution = $context->getMeta('timer_last_execution');
        if ($lastExecution) {
            $lastTime = new \DateTime($lastExecution, $tz);
        } else {
            // Use start time or registration time
            if (!empty($parsed['start'])) {
                $lastTime = new \DateTime($parsed['start'], $tz);
            } else {
                $registrationTime = $context->getMeta('timer_registration_time');
                $lastTime = $registrationTime
                    ? new \DateTime($registrationTime, $tz)
                    : $now;
            }
        }

        try {
            $interval = new \DateInterval($parsed['duration']);
            $nextTime = clone $lastTime;
            $nextTime->add($interval);

            return $now >= $nextTime;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get reference time | 获取参考时间
     *
     * @param array $config
     * @param TriggerContext $context
     * @return \DateTime|null
     */
    protected function getReferenceTime(array $config, TriggerContext $context): ?\DateTime
    {
        $timezone = $config['timezone'] ?? 'Asia/Shanghai';
        $tz = new \DateTimeZone($timezone);

        // Check for explicit reference time in config
        if (!empty($config['reference_time'])) {
            $refValue = $context->getMeta($config['reference_time']);
            if ($refValue) {
                try {
                    return new \DateTime($refValue, $tz);
                } catch (\Exception $e) {
                    // Fallback to default
                }
            }
        }

        // Use registration time as default reference
        $registrationTime = $context->getMeta('timer_registration_time');
        if ($registrationTime) {
            try {
                return new \DateTime($registrationTime, $tz);
            } catch (\Exception $e) {
                // Fallback to now
            }
        }

        return new \DateTime('now', $tz);
    }

    /**
     * Execute trigger logic | 执行触发逻辑
     *
     * @param TriggerContext $context
     * @param array $config
     * @return TriggerResult
     */
    protected function doTrigger(TriggerContext $context, array $config): TriggerResult
    {
        $timerType = $config['timer_type'] ?? self::TIMER_DURATION;
        $eventType = $config['event_type'] ?? self::EVENT_START;
        $executionCount = $context->getMeta('timer_execution_count', 0) + 1;

        $triggerData = [
            'trigger_type' => $this->getType(),
            'trigger_name' => $this->getName(),
            'triggered_at' => date('Y-m-d H:i:s'),
            'tenant_id' => $context->getTenantId(),
            'timer_type' => $timerType,
            'timer_value' => $config['timer_value'],
            'event_type' => $eventType,
            'execution_count' => $executionCount,
            'cancel_activity' => $config['cancel_activity'] ?? false,
            'execution_data' => $config['execution_data'] ?? [],
            'timezone' => $config['timezone'] ?? 'Asia/Shanghai',
        ];

        // Add cycle-specific data
        if ($timerType === self::TIMER_CYCLE) {
            $parsed = $this->parseCycleExpression($config['timer_value']);
            $maxRepetitions = $parsed['repetitions'] ?? ($config['max_repetitions'] ?? 0);

            $triggerData['max_repetitions'] = $maxRepetitions;
            $triggerData['remaining_repetitions'] = $maxRepetitions > 0
                ? max(0, $maxRepetitions - $executionCount)
                : -1; // -1 = infinite
            $triggerData['is_last_execution'] = $maxRepetitions > 0 && $executionCount >= $maxRepetitions;
        }

        return TriggerResult::success($triggerData)
            ->setMeta('timer_type', $timerType)
            ->setMeta('event_type', $eventType)
            ->setMeta('execution_count', $executionCount)
            ->setMeta('timer_last_execution', date('Y-m-d H:i:s'));
    }

    /**
     * Calculate next fire time | 计算下次触发时间
     *
     * @param array $config
     * @param TriggerContext|null $context
     * @return \DateTime|null
     */
    public function calculateNextFireTime(array $config, ?TriggerContext $context = null): ?\DateTime
    {
        $timerType = $config['timer_type'] ?? self::TIMER_DURATION;
        $timerValue = $config['timer_value'] ?? null;
        $timezone = $config['timezone'] ?? 'Asia/Shanghai';
        $tz = new \DateTimeZone($timezone);

        if (!$timerValue) {
            return null;
        }

        try {
            return match ($timerType) {
                self::TIMER_DATE => new \DateTime($timerValue, $tz),
                self::TIMER_DURATION => $this->calculateDurationFireTime($timerValue, $config, $context),
                self::TIMER_CYCLE => $this->calculateCycleFireTime($timerValue, $config, $context),
                default => null,
            };
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Calculate duration fire time | 计算持续时间触发时间
     *
     * @param string $duration
     * @param array $config
     * @param TriggerContext|null $context
     * @return \DateTime|null
     */
    protected function calculateDurationFireTime(string $duration, array $config, ?TriggerContext $context): ?\DateTime
    {
        $referenceTime = $context ? $this->getReferenceTime($config, $context) : new \DateTime();
        if (!$referenceTime) {
            return null;
        }

        try {
            $interval = new \DateInterval($duration);
            $fireTime = clone $referenceTime;
            $fireTime->add($interval);
            return $fireTime;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Calculate cycle fire time | 计算周期触发时间
     *
     * @param string $cycle
     * @param array $config
     * @param TriggerContext|null $context
     * @return \DateTime|null
     */
    protected function calculateCycleFireTime(string $cycle, array $config, ?TriggerContext $context): ?\DateTime
    {
        $parsed = $this->parseCycleExpression($cycle);
        if (!$parsed) {
            return null;
        }

        $timezone = $config['timezone'] ?? 'Asia/Shanghai';
        $tz = new \DateTimeZone($timezone);

        $lastExecution = $context?->getMeta('timer_last_execution');

        try {
            if ($lastExecution) {
                $lastTime = new \DateTime($lastExecution, $tz);
            } elseif (!empty($parsed['start'])) {
                $lastTime = new \DateTime($parsed['start'], $tz);
            } else {
                $lastTime = new \DateTime('now', $tz);
            }

            $interval = new \DateInterval($parsed['duration']);
            $fireTime = clone $lastTime;
            $fireTime->add($interval);
            return $fireTime;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Register timer | 注册定时器时的钩子
     *
     * @param string $workflowId
     * @param array $config
     */
    protected function onRegister(string $workflowId, array $config): void
    {
        // Store registration time for duration calculations
        $config['_registration_time'] = date('Y-m-d H:i:s');
        $this->registrations[$workflowId] = $config;
    }
}
