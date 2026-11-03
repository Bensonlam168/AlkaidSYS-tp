<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * Delay Node | 延时节点
 *
 * A timer node that pauses workflow execution for a specified duration
 * or until a specific time. Supports various delay strategies.
 * 暂停工作流执行指定时长或直到特定时间的定时器节点。
 * 支持多种延时策略。
 *
 * Configuration options:
 * - delay_type: string - 'duration', 'until', 'expression', 'cron'
 * - duration: int - Delay duration in seconds (for 'duration' type)
 * - duration_unit: string - 'seconds', 'minutes', 'hours', 'days'
 * - until_time: string - ISO 8601 datetime (for 'until' type)
 * - time_expression: string - Expression that resolves to datetime
 * - cron_expression: string - Cron expression for recurring delays
 * - timezone: string - Timezone for time calculations
 * - skip_weekends: bool - Skip weekend days
 * - skip_holidays: array - List of holiday dates to skip
 * - business_hours_only: bool - Only count business hours
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
class DelayNode extends AbstractNode
{
    /**
     * Delay types | 延时类型
     */
    public const TYPE_DURATION = 'duration';     // Fixed duration delay
    public const TYPE_UNTIL = 'until';           // Delay until specific time
    public const TYPE_EXPRESSION = 'expression'; // Dynamic time from expression
    public const TYPE_CRON = 'cron';             // Cron-based timing

    /**
     * Duration units | 时长单位
     */
    public const UNIT_SECONDS = 'seconds';
    public const UNIT_MINUTES = 'minutes';
    public const UNIT_HOURS = 'hours';
    public const UNIT_DAYS = 'days';
    public const UNIT_WEEKS = 'weeks';
    public const UNIT_MONTHS = 'months';

    /**
     * Delay status | 延时状态
     */
    public const STATUS_WAITING = 'waiting';
    public const STATUS_READY = 'ready';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Constructor
     *
     * @param string|null $id Node ID
     * @param array $config Node configuration
     */
    public function __construct(?string $id = null, array $config = [])
    {
        $config['sub_type'] = $config['sub_type'] ?? 'timer';
        parent::__construct($id, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return NodeInterface::TYPE_EVENT;
    }

    /**
     * {@inheritDoc}
     */
    public function getSubType(): ?string
    {
        return $this->config['sub_type'] ?? 'timer';
    }

    /**
     * {@inheritDoc}
     */
    public function setConfig(array $config): static
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Execute the delay node | 执行延时节点
     *
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function doExecute(ExecutionContext $context): NodeResult
    {
        // Get or create timer data
        $timerData = $this->getOrCreateTimerData($context);

        // Check if delay has elapsed
        if ($this->isDelayElapsed($timerData)) {
            return $this->handleDelayComplete($timerData, $context);
        }

        // Still waiting
        return NodeResult::waiting([
            'timer_id' => $timerData['timer_id'],
            'delay_type' => $timerData['delay_type'],
            'target_time' => $timerData['target_time'],
            'remaining_seconds' => $this->getRemainingSeconds($timerData),
            'remaining_human' => $this->formatRemainingTime($timerData),
            'status' => self::STATUS_WAITING,
            'node_id' => $this->id,
            'node_name' => $this->name,
        ], '等待延时');
    }

    /**
     * Get or create timer data | 获取或创建定时器数据
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function getOrCreateTimerData(ExecutionContext $context): array
    {
        $timerKey = "delay_timer_{$this->id}";
        $existingTimer = $context->getVariable($timerKey);

        if ($existingTimer !== null) {
            return $existingTimer;
        }

        $timerId = $this->generateTimerId($context);
        $delayType = $this->getConfigValue('delay_type', self::TYPE_DURATION);
        $targetTime = $this->calculateTargetTime($delayType, $context);

        $timerData = [
            'timer_id' => $timerId,
            'node_id' => $this->id,
            'node_name' => $this->name,
            'instance_id' => $context->getInstanceId(),
            'tenant_id' => $context->getTenantId(),
            'delay_type' => $delayType,
            'created_at' => (new \DateTimeImmutable())->format('c'),
            'target_time' => $targetTime->format('c'),
            'timezone' => $this->getConfigValue('timezone', 'Asia/Shanghai'),
            'status' => self::STATUS_WAITING,
        ];

        $context->setVariable($timerKey, $timerData);

        return $timerData;
    }

    /**
     * Generate timer ID | 生成定时器ID
     *
     * @param ExecutionContext $context
     * @return string
     */
    protected function generateTimerId(ExecutionContext $context): string
    {
        return sprintf(
            'timer_%s_%s_%s',
            $context->getInstanceId() ?? 'unknown',
            $this->id,
            bin2hex(random_bytes(4))
        );
    }

    /**
     * Calculate target time | 计算目标时间
     *
     * @param string $delayType
     * @param ExecutionContext $context
     * @return \DateTimeImmutable
     */
    protected function calculateTargetTime(string $delayType, ExecutionContext $context): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone($this->getConfigValue('timezone', 'Asia/Shanghai'));
        $now = new \DateTimeImmutable('now', $timezone);

        return match ($delayType) {
            self::TYPE_DURATION => $this->calculateDurationTarget($now, $context),
            self::TYPE_UNTIL => $this->calculateUntilTarget($context, $timezone),
            self::TYPE_EXPRESSION => $this->calculateExpressionTarget($context, $timezone),
            self::TYPE_CRON => $this->calculateCronTarget($now),
            default => $now,
        };
    }

    /**
     * Calculate duration-based target | 计算基于时长的目标时间
     *
     * @param \DateTimeImmutable $from
     * @param ExecutionContext $context
     * @return \DateTimeImmutable
     */
    protected function calculateDurationTarget(\DateTimeImmutable $from, ExecutionContext $context): \DateTimeImmutable
    {
        $duration = $this->getConfigValue('duration', 0);
        $unit = $this->getConfigValue('duration_unit', self::UNIT_SECONDS);

        // Resolve duration if it's an expression
        if (is_string($duration) && str_starts_with($duration, '${')) {
            $varName = substr($duration, 2, -1);
            $duration = (int)$context->getVariable($varName);
        }

        $seconds = $this->convertToSeconds($duration, $unit);
        $target = $from->modify("+{$seconds} seconds");

        // Apply business rules
        if ($this->getConfigValue('business_hours_only', false)) {
            $target = $this->adjustForBusinessHours($target, $from, $seconds);
        }

        if ($this->getConfigValue('skip_weekends', false)) {
            $target = $this->skipWeekends($target);
        }

        $holidays = $this->getConfigValue('skip_holidays', []);
        if (!empty($holidays)) {
            $target = $this->skipHolidays($target, $holidays);
        }

        return $target;
    }

    /**
     * Convert duration to seconds | 将时长转换为秒
     *
     * @param int $duration
     * @param string $unit
     * @return int
     */
    protected function convertToSeconds(int $duration, string $unit): int
    {
        return match ($unit) {
            self::UNIT_MINUTES => $duration * 60,
            self::UNIT_HOURS => $duration * 3600,
            self::UNIT_DAYS => $duration * 86400,
            self::UNIT_WEEKS => $duration * 604800,
            self::UNIT_MONTHS => $duration * 2592000, // 30 days approximation
            default => $duration,
        };
    }

    /**
     * Calculate until-based target | 计算直到型目标时间
     *
     * @param ExecutionContext $context
     * @param \DateTimeZone $timezone
     * @return \DateTimeImmutable
     */
    protected function calculateUntilTarget(ExecutionContext $context, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $untilTime = $this->getConfigValue('until_time', '');

        // Resolve if expression
        if (str_starts_with($untilTime, '${')) {
            $varName = substr($untilTime, 2, -1);
            $untilTime = $context->getVariable($varName);
        }

        try {
            return new \DateTimeImmutable($untilTime, $timezone);
        } catch (\Exception) {
            // Default to 1 hour from now
            return new \DateTimeImmutable('+1 hour', $timezone);
        }
    }

    /**
     * Calculate expression-based target | 计算表达式型目标时间
     *
     * @param ExecutionContext $context
     * @param \DateTimeZone $timezone
     * @return \DateTimeImmutable
     */
    protected function calculateExpressionTarget(ExecutionContext $context, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $expression = $this->getConfigValue('time_expression', '');

        // Evaluate expression
        $result = $this->evaluateCondition($expression, $context);

        if ($result instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($result);
        }

        if (is_string($result)) {
            try {
                return new \DateTimeImmutable($result, $timezone);
            } catch (\Exception) {
                // Fall through to default
            }
        }

        if (is_int($result)) {
            // Assume Unix timestamp
            return (new \DateTimeImmutable('@' . $result))->setTimezone($timezone);
        }

        // Default to 1 hour from now
        return new \DateTimeImmutable('+1 hour', $timezone);
    }

    /**
     * Calculate cron-based target | 计算 Cron 型目标时间
     *
     * @param \DateTimeImmutable $from
     * @return \DateTimeImmutable
     */
    protected function calculateCronTarget(\DateTimeImmutable $from): \DateTimeImmutable
    {
        $cronExpression = $this->getConfigValue('cron_expression', '');

        if (empty($cronExpression)) {
            return $from->modify('+1 hour');
        }

        // Simple cron parsing (minute hour day month weekday)
        $parts = explode(' ', trim($cronExpression));
        if (count($parts) !== 5) {
            return $from->modify('+1 hour');
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        // Find next matching time (simplified implementation)
        $target = $from->modify('+1 minute');
        $maxIterations = 366 * 24 * 60; // Max 1 year ahead

        for ($i = 0; $i < $maxIterations; $i++) {
            if ($this->matchesCron($target, $minute, $hour, $day, $month, $weekday)) {
                return $target;
            }
            $target = $target->modify('+1 minute');
        }

        // Fallback
        return $from->modify('+1 hour');
    }

    /**
     * Check if datetime matches cron expression | 检查时间是否匹配 Cron 表达式
     *
     * @param \DateTimeImmutable $dt
     * @param string $minute
     * @param string $hour
     * @param string $day
     * @param string $month
     * @param string $weekday
     * @return bool
     */
    protected function matchesCron(
        \DateTimeImmutable $dt,
        string $minute,
        string $hour,
        string $day,
        string $month,
        string $weekday
    ): bool {
        return $this->matchesCronField($minute, (int)$dt->format('i')) &&
               $this->matchesCronField($hour, (int)$dt->format('G')) &&
               $this->matchesCronField($day, (int)$dt->format('j')) &&
               $this->matchesCronField($month, (int)$dt->format('n')) &&
               $this->matchesCronField($weekday, (int)$dt->format('w'));
    }

    /**
     * Match cron field | 匹配 Cron 字段
     *
     * @param string $pattern
     * @param int $value
     * @return bool
     */
    protected function matchesCronField(string $pattern, int $value): bool
    {
        if ($pattern === '*') {
            return true;
        }

        // Handle comma-separated values
        if (str_contains($pattern, ',')) {
            $values = array_map('intval', explode(',', $pattern));
            return in_array($value, $values, true);
        }

        // Handle step values (*/5)
        if (str_starts_with($pattern, '*/')) {
            $step = (int)substr($pattern, 2);
            return $step > 0 && $value % $step === 0;
        }

        return (int)$pattern === $value;
    }

    /**
     * Adjust for business hours | 调整为工作时间
     *
     * @param \DateTimeImmutable $target
     * @param \DateTimeImmutable $from
     * @param int $totalSeconds
     * @return \DateTimeImmutable
     */
    protected function adjustForBusinessHours(
        \DateTimeImmutable $target,
        \DateTimeImmutable $from,
        int $totalSeconds
    ): \DateTimeImmutable {
        $businessStart = $this->getConfigValue('business_hours_start', '09:00');
        $businessEnd = $this->getConfigValue('business_hours_end', '18:00');

        [$startHour, $startMin] = array_map('intval', explode(':', $businessStart));
        [$endHour, $endMin] = array_map('intval', explode(':', $businessEnd));

        $dailySeconds = (($endHour - $startHour) * 3600) + (($endMin - $startMin) * 60);
        $remainingSeconds = $totalSeconds;
        $current = $from;

        while ($remainingSeconds > 0) {
            // Move to next business start if outside hours
            $currentHour = (int)$current->format('G');
            $currentMin = (int)$current->format('i');
            $currentSeconds = $currentHour * 3600 + $currentMin * 60;
            $startSeconds = $startHour * 3600 + $startMin * 60;
            $endSeconds = $endHour * 3600 + $endMin * 60;

            if ($currentSeconds < $startSeconds) {
                $current = $current->setTime($startHour, $startMin);
            } elseif ($currentSeconds >= $endSeconds) {
                $current = $current->modify('+1 day')->setTime($startHour, $startMin);
            }

            // Skip weekends
            while (in_array((int)$current->format('w'), [0, 6], true)) {
                $current = $current->modify('+1 day');
            }

            // Calculate available seconds today
            $availableToday = $endSeconds - ($currentHour * 3600 + $currentMin * 60);
            if ($availableToday <= 0) {
                $current = $current->modify('+1 day')->setTime($startHour, $startMin);
                continue;
            }

            if ($remainingSeconds <= $availableToday) {
                return $current->modify("+{$remainingSeconds} seconds");
            }

            $remainingSeconds -= $availableToday;
            $current = $current->modify('+1 day')->setTime($startHour, $startMin);
        }

        return $current;
    }

    /**
     * Skip weekends | 跳过周末
     *
     * @param \DateTimeImmutable $target
     * @return \DateTimeImmutable
     */
    protected function skipWeekends(\DateTimeImmutable $target): \DateTimeImmutable
    {
        while (in_array((int)$target->format('w'), [0, 6], true)) {
            $target = $target->modify('+1 day');
        }

        return $target;
    }

    /**
     * Skip holidays | 跳过假期
     *
     * @param \DateTimeImmutable $target
     * @param array $holidays
     * @return \DateTimeImmutable
     */
    protected function skipHolidays(\DateTimeImmutable $target, array $holidays): \DateTimeImmutable
    {
        $dateStr = $target->format('Y-m-d');

        while (in_array($dateStr, $holidays, true)) {
            $target = $target->modify('+1 day');
            $dateStr = $target->format('Y-m-d');
        }

        return $target;
    }

    /**
     * Check if delay has elapsed | 检查延时是否已过
     *
     * @param array $timerData
     * @return bool
     */
    protected function isDelayElapsed(array $timerData): bool
    {
        $targetTime = new \DateTimeImmutable($timerData['target_time']);
        $now = new \DateTimeImmutable();

        return $now >= $targetTime;
    }

    /**
     * Get remaining seconds | 获取剩余秒数
     *
     * @param array $timerData
     * @return int
     */
    protected function getRemainingSeconds(array $timerData): int
    {
        $targetTime = new \DateTimeImmutable($timerData['target_time']);
        $now = new \DateTimeImmutable();

        $diff = $targetTime->getTimestamp() - $now->getTimestamp();

        return max(0, $diff);
    }

    /**
     * Format remaining time for display | 格式化剩余时间用于显示
     *
     * @param array $timerData
     * @return string
     */
    protected function formatRemainingTime(array $timerData): string
    {
        $seconds = $this->getRemainingSeconds($timerData);

        if ($seconds <= 0) {
            return '已到期';
        }

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}天";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}小时";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}分钟";
        }
        if ($secs > 0 && empty($parts)) {
            $parts[] = "{$secs}秒";
        }

        return implode(' ', $parts);
    }

    /**
     * Handle delay complete | 处理延时完成
     *
     * @param array $timerData
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function handleDelayComplete(array $timerData, ExecutionContext $context): NodeResult
    {
        // Update timer status
        $timerKey = "delay_timer_{$this->id}";
        $timerData['status'] = self::STATUS_READY;
        $timerData['completed_at'] = (new \DateTimeImmutable())->format('c');
        $context->setVariable($timerKey, $timerData);

        // Store delay result
        $context->setVariable("delay_result_{$this->id}", [
            'timer_id' => $timerData['timer_id'],
            'target_time' => $timerData['target_time'],
            'completed_at' => $timerData['completed_at'],
            'status' => self::STATUS_READY,
        ]);

        return NodeResult::success([
            'timer_id' => $timerData['timer_id'],
            'delay_type' => $timerData['delay_type'],
            'target_time' => $timerData['target_time'],
            'completed_at' => $timerData['completed_at'],
            'message' => '延时完成',
        ], $this->getDefaultNextNodes());
    }

    /**
     * Skip delay (for testing or manual override) | 跳过延时
     *
     * @param ExecutionContext $context
     * @return NodeResult
     */
    public function skip(ExecutionContext $context): NodeResult
    {
        $timerKey = "delay_timer_{$this->id}";
        $timerData = $context->getVariable($timerKey);

        if ($timerData === null) {
            $timerData = $this->getOrCreateTimerData($context);
        }

        $timerData['status'] = self::STATUS_SKIPPED;
        $timerData['skipped_at'] = (new \DateTimeImmutable())->format('c');
        $context->setVariable($timerKey, $timerData);

        return NodeResult::success([
            'timer_id' => $timerData['timer_id'],
            'status' => self::STATUS_SKIPPED,
            'message' => '延时已跳过',
        ], $this->getDefaultNextNodes());
    }

    /**
     * Get configuration schema | 获取配置 Schema
     *
     * @return array
     */
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'delay_type' => [
                    'type' => 'string',
                    'enum' => [
                        self::TYPE_DURATION,
                        self::TYPE_UNTIL,
                        self::TYPE_EXPRESSION,
                        self::TYPE_CRON,
                    ],
                    'default' => self::TYPE_DURATION,
                    'description' => '延时类型',
                ],
                'duration' => [
                    'oneOf' => [
                        ['type' => 'integer', 'minimum' => 0],
                        ['type' => 'string'],
                    ],
                    'description' => '延时时长',
                ],
                'duration_unit' => [
                    'type' => 'string',
                    'enum' => [
                        self::UNIT_SECONDS,
                        self::UNIT_MINUTES,
                        self::UNIT_HOURS,
                        self::UNIT_DAYS,
                        self::UNIT_WEEKS,
                        self::UNIT_MONTHS,
                    ],
                    'default' => self::UNIT_SECONDS,
                    'description' => '时长单位',
                ],
                'until_time' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => '目标时间（ISO 8601 格式）',
                ],
                'time_expression' => [
                    'type' => 'string',
                    'description' => '时间表达式',
                ],
                'cron_expression' => [
                    'type' => 'string',
                    'pattern' => '^[\\*\\d,\\-\\/\\s]+$',
                    'description' => 'Cron 表达式',
                ],
                'timezone' => [
                    'type' => 'string',
                    'default' => 'Asia/Shanghai',
                    'description' => '时区',
                ],
                'skip_weekends' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => '跳过周末',
                ],
                'skip_holidays' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'format' => 'date'],
                    'description' => '跳过的假期日期',
                ],
                'business_hours_only' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => '仅计算工作时间',
                ],
                'business_hours_start' => [
                    'type' => 'string',
                    'pattern' => '^\\d{2}:\\d{2}$',
                    'default' => '09:00',
                    'description' => '工作时间开始',
                ],
                'business_hours_end' => [
                    'type' => 'string',
                    'pattern' => '^\\d{2}:\\d{2}$',
                    'default' => '18:00',
                    'description' => '工作时间结束',
                ],
            ],
            'required' => ['delay_type'],
        ];
    }

    /**
     * Validate configuration | 验证配置
     *
     * @param array $config
     * @return bool
     */
    public function validateConfig(array $config = []): bool
    {
        $configToValidate = !empty($config) ? $config : $this->config;

        // Validate delay type
        $delayType = $configToValidate['delay_type'] ?? self::TYPE_DURATION;
        $validTypes = [self::TYPE_DURATION, self::TYPE_UNTIL, self::TYPE_EXPRESSION, self::TYPE_CRON];
        if (!in_array($delayType, $validTypes, true)) {
            return false;
        }

        // Type-specific validation
        switch ($delayType) {
            case self::TYPE_DURATION:
                $duration = $configToValidate['duration'] ?? 0;
                if (is_numeric($duration) && $duration < 0) {
                    return false;
                }
                break;

            case self::TYPE_UNTIL:
                if (empty($configToValidate['until_time'])) {
                    return false;
                }
                break;

            case self::TYPE_EXPRESSION:
                if (empty($configToValidate['time_expression'])) {
                    return false;
                }
                break;

            case self::TYPE_CRON:
                if (empty($configToValidate['cron_expression'])) {
                    return false;
                }
                // Basic cron format validation
                $parts = explode(' ', $configToValidate['cron_expression']);
                if (count($parts) !== 5) {
                    return false;
                }
                break;
        }

        // Validate duration unit
        if (isset($configToValidate['duration_unit'])) {
            $validUnits = [
                self::UNIT_SECONDS,
                self::UNIT_MINUTES,
                self::UNIT_HOURS,
                self::UNIT_DAYS,
                self::UNIT_WEEKS,
                self::UNIT_MONTHS,
            ];
            if (!in_array($configToValidate['duration_unit'], $validUnits, true)) {
                return false;
            }
        }

        return parent::validateConfig($configToValidate);
    }
}
