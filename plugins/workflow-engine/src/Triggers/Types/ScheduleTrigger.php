<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers\Types;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;

/**
 * 定时触发器
 *
 * 基于 Cron 表达式的定时触发器，支持定期执行工作流。
 * 可以配置时区、重复规则、过期时间等。
 *
 * @package Alkaid\Workflow\Engine\Triggers\Types
 */
class ScheduleTrigger extends AbstractTrigger
{
    /**
     * 默认时区
     */
    protected string $defaultTimezone = 'Asia/Shanghai';

    /**
     * 获取触发器类型
     */
    public function getType(): string
    {
        return TriggerInterface::TYPE_SCHEDULE;
    }

    /**
     * 获取触发器名称
     */
    public function getName(): string
    {
        return '定时触发器';
    }

    /**
     * 获取触发器描述
     */
    public function getDescription(): string
    {
        return '基于 Cron 表达式定时触发工作流，适用于周期性任务';
    }

    /**
     * 获取配置 Schema
     */
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'title' => '触发器名称',
                    'description' => '定时任务的显示名称',
                    'maxLength' => 100,
                ],
                'cron_expression' => [
                    'type' => 'string',
                    'title' => 'Cron 表达式',
                    'description' => '标准 Cron 表达式（分 时 日 月 周）',
                    'pattern' => '/^(\*|([0-9]|1[0-9]|2[0-9]|3[0-9]|4[0-9]|5[0-9])|\*\/([0-9]|1[0-9]|2[0-9]|3[0-9]|4[0-9]|5[0-9])) (\*|([0-9]|1[0-9]|2[0-3])|\*\/([0-9]|1[0-9]|2[0-3])) (\*|([1-9]|1[0-9]|2[0-9]|3[0-1])|\*\/([1-9]|1[0-9]|2[0-9]|3[0-1])) (\*|([1-9]|1[0-2])|\*\/([1-9]|1[0-2])) (\*|([0-6])|\*\/([0-6]))$/',
                ],
                'timezone' => [
                    'type' => 'string',
                    'title' => '时区',
                    'description' => '执行时使用的时区',
                    'default' => 'Asia/Shanghai',
                ],
                'start_date' => [
                    'type' => 'string',
                    'title' => '开始日期',
                    'description' => '调度开始日期（ISO 8601 格式）',
                    'format' => 'date-time',
                ],
                'end_date' => [
                    'type' => 'string',
                    'title' => '结束日期',
                    'description' => '调度结束日期（ISO 8601 格式）',
                    'format' => 'date-time',
                ],
                'max_executions' => [
                    'type' => 'integer',
                    'title' => '最大执行次数',
                    'description' => '最大执行次数（0 表示不限制）',
                    'minimum' => 0,
                    'default' => 0,
                ],
                'catch_up' => [
                    'type' => 'boolean',
                    'title' => '补执行',
                    'description' => '是否补执行错过的调度',
                    'default' => false,
                ],
                'enabled' => [
                    'type' => 'boolean',
                    'title' => '启用',
                    'description' => '是否启用此定时任务',
                    'default' => true,
                ],
                'retry_on_failure' => [
                    'type' => 'boolean',
                    'title' => '失败重试',
                    'description' => '执行失败时是否重试',
                    'default' => true,
                ],
                'retry_count' => [
                    'type' => 'integer',
                    'title' => '重试次数',
                    'description' => '失败后最大重试次数',
                    'minimum' => 0,
                    'maximum' => 10,
                    'default' => 3,
                ],
                'retry_interval' => [
                    'type' => 'integer',
                    'title' => '重试间隔',
                    'description' => '重试间隔（秒）',
                    'minimum' => 1,
                    'maximum' => 3600,
                    'default' => 60,
                ],
                'input_data' => [
                    'type' => 'object',
                    'title' => '输入数据',
                    'description' => '每次执行时传递的固定数据',
                ],
            ],
            'required' => ['cron_expression'],
        ];
    }

    /**
     * 验证 Cron 表达式
     *
     * @param string $expression Cron 表达式
     * @return bool
     */
    public function validateCronExpression(string $expression): bool
    {
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            return false;
        }

        $ranges = [
            [0, 59],   // 分钟
            [0, 23],   // 小时
            [1, 31],   // 日
            [1, 12],   // 月
            [0, 6],    // 周几
        ];

        foreach ($parts as $i => $part) {
            if (!$this->validateCronPart($part, $ranges[$i][0], $ranges[$i][1])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 验证 Cron 表达式的单个部分
     *
     * @param string $part 部分
     * @param int $min 最小值
     * @param int $max 最大值
     * @return bool
     */
    protected function validateCronPart(string $part, int $min, int $max): bool
    {
        // 星号
        if ($part === '*') {
            return true;
        }

        // 步长（*/n）
        if (preg_match('/^\*\/(\d+)$/', $part, $matches)) {
            return (int)$matches[1] >= 1;
        }

        // 范围（n-m）
        if (preg_match('/^(\d+)-(\d+)$/', $part, $matches)) {
            $start = (int)$matches[1];
            $end = (int)$matches[2];
            return $start >= $min && $end <= $max && $start <= $end;
        }

        // 列表（n,m,o）
        if (str_contains($part, ',')) {
            $values = explode(',', $part);
            foreach ($values as $value) {
                $num = (int)$value;
                if ($num < $min || $num > $max) {
                    return false;
                }
            }
            return true;
        }

        // 单个数字
        if (is_numeric($part)) {
            $num = (int)$part;
            return $num >= $min && $num <= $max;
        }

        return false;
    }

    /**
     * 验证触发器配置
     *
     * @param array $config 配置数据
     * @return bool 验证是否通过
     */
    public function validateConfig(array $config): bool
    {
        // 先调用父类验证
        if (!parent::validateConfig($config)) {
            return false;
        }

        // 验证 Cron 表达式
        if (!empty($config['cron_expression'])) {
            if (!$this->validateCronExpression($config['cron_expression'])) {
                $this->validationErrors[] = 'Cron 表达式格式不正确';
                return false;
            }
        }

        // 验证日期范围
        if (!empty($config['start_date']) && !empty($config['end_date'])) {
            $startDate = strtotime($config['start_date']);
            $endDate = strtotime($config['end_date']);
            if ($startDate && $endDate && $startDate >= $endDate) {
                $this->validationErrors[] = '结束日期必须晚于开始日期';
                return false;
            }
        }

        return true;
    }

    /**
     * 注册时的钩子方法
     *
     * @param string $workflowId 工作流ID
     * @param array $config 配置
     * @return void
     */
    protected function onRegister(string $workflowId, array $config): void
    {
        // 计算下次执行时间
        $nextExecutionTime = $this->calculateNextExecution(
            $config['cron_expression'],
            $config['timezone'] ?? $this->defaultTimezone
        );

        $this->registrations[$workflowId]['next_execution'] = $nextExecutionTime;
        $this->registrations[$workflowId]['execution_count'] = 0;
        $this->registrations[$workflowId]['last_execution'] = null;

        // 这里可以将调度任务添加到队列系统
        // 例如：使用 ThinkPHP 的定时任务或消息队列
    }

    /**
     * 计算下次执行时间
     *
     * @param string $cronExpression Cron 表达式
     * @param string $timezone 时区
     * @param int|null $fromTimestamp 起始时间戳
     * @return int|null 下次执行的时间戳
     */
    public function calculateNextExecution(
        string $cronExpression,
        string $timezone = 'Asia/Shanghai',
        ?int $fromTimestamp = null
    ): ?int {
        $fromTimestamp = $fromTimestamp ?? time();

        try {
            $tz = new \DateTimeZone($timezone);
            $from = (new \DateTime())->setTimestamp($fromTimestamp)->setTimezone($tz);

            // 解析 Cron 表达式
            $parts = preg_split('/\s+/', trim($cronExpression));
            if (count($parts) !== 5) {
                return null;
            }

            [$minute, $hour, $day, $month, $weekday] = $parts;

            // 简单实现：检查未来 366 天内的每分钟
            $maxIterations = 366 * 24 * 60;
            $current = clone $from;
            $current->modify('+1 minute');
            $current->setTime((int)$current->format('H'), (int)$current->format('i'), 0);

            for ($i = 0; $i < $maxIterations; $i++) {
                if ($this->matchesCron($current, $minute, $hour, $day, $month, $weekday)) {
                    return $current->getTimestamp();
                }
                $current->modify('+1 minute');
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 检查时间是否匹配 Cron 表达式
     *
     * @param \DateTime $dateTime 时间
     * @param string $minute 分钟规则
     * @param string $hour 小时规则
     * @param string $day 日期规则
     * @param string $month 月份规则
     * @param string $weekday 星期规则
     * @return bool
     */
    protected function matchesCron(
        \DateTime $dateTime,
        string $minute,
        string $hour,
        string $day,
        string $month,
        string $weekday
    ): bool {
        return $this->matchesCronPart((int)$dateTime->format('i'), $minute)
            && $this->matchesCronPart((int)$dateTime->format('G'), $hour)
            && $this->matchesCronPart((int)$dateTime->format('j'), $day)
            && $this->matchesCronPart((int)$dateTime->format('n'), $month)
            && $this->matchesCronPart((int)$dateTime->format('w'), $weekday);
    }

    /**
     * 检查值是否匹配 Cron 规则
     *
     * @param int $value 值
     * @param string $rule 规则
     * @return bool
     */
    protected function matchesCronPart(int $value, string $rule): bool
    {
        // 星号匹配所有
        if ($rule === '*') {
            return true;
        }

        // 步长
        if (preg_match('/^\*\/(\d+)$/', $rule, $matches)) {
            return $value % (int)$matches[1] === 0;
        }

        // 范围
        if (preg_match('/^(\d+)-(\d+)$/', $rule, $matches)) {
            return $value >= (int)$matches[1] && $value <= (int)$matches[2];
        }

        // 列表
        if (str_contains($rule, ',')) {
            $values = array_map('intval', explode(',', $rule));
            return in_array($value, $values, true);
        }

        // 单个值
        return $value === (int)$rule;
    }

    /**
     * 检查触发条件
     *
     * @param TriggerContext $context 触发上下文
     * @return bool
     */
    protected function checkTriggerConditions(TriggerContext $context): bool
    {
        if (!$context->isSchedule()) {
            return false;
        }

        $workflowId = $context->getWorkflowId();
        if (!$workflowId || !$this->isRegistered($workflowId)) {
            return false;
        }

        $config = $this->getWorkflowConfig($workflowId);

        // 检查是否启用
        if (($config['enabled'] ?? true) === false) {
            return false;
        }

        // 检查开始日期
        if (!empty($config['start_date'])) {
            $startDate = strtotime($config['start_date']);
            if ($startDate && time() < $startDate) {
                return false;
            }
        }

        // 检查结束日期
        if (!empty($config['end_date'])) {
            $endDate = strtotime($config['end_date']);
            if ($endDate && time() > $endDate) {
                return false;
            }
        }

        // 检查最大执行次数
        if (!empty($config['max_executions']) && $config['max_executions'] > 0) {
            $executionCount = $this->registrations[$workflowId]['execution_count'] ?? 0;
            if ($executionCount >= $config['max_executions']) {
                return false;
            }
        }

        return true;
    }

    /**
     * 执行触发逻辑
     *
     * @param TriggerContext $context 触发上下文
     * @param array $config 触发器配置
     * @return TriggerResult
     */
    protected function doTrigger(TriggerContext $context, array $config): TriggerResult
    {
        $workflowId = $context->getWorkflowId();

        // 更新执行统计
        $this->registrations[$workflowId]['execution_count'] =
            ($this->registrations[$workflowId]['execution_count'] ?? 0) + 1;
        $this->registrations[$workflowId]['last_execution'] = time();

        // 计算下次执行时间
        $nextExecution = $this->calculateNextExecution(
            $config['cron_expression'],
            $config['timezone'] ?? $this->defaultTimezone
        );
        $this->registrations[$workflowId]['next_execution'] = $nextExecution;

        // 构建触发数据
        $triggerData = [
            'trigger_type' => $this->getType(),
            'trigger_name' => $config['name'] ?? $this->getName(),
            'cron_expression' => $config['cron_expression'],
            'timezone' => $config['timezone'] ?? $this->defaultTimezone,
            'scheduled_time' => $context->getScheduledTime(),
            'actual_time' => time(),
            'execution_count' => $this->registrations[$workflowId]['execution_count'],
            'next_execution' => $nextExecution,
            'next_execution_formatted' => $nextExecution ? date('Y-m-d H:i:s', $nextExecution) : null,
            'input_data' => $config['input_data'] ?? [],
        ];

        // 合并上下文数据
        if (!empty($context->getData())) {
            $triggerData['context_data'] = $context->getData();
        }

        return TriggerResult::success($triggerData)
            ->setMeta('execution_count', $this->registrations[$workflowId]['execution_count'])
            ->setMeta('next_execution', $nextExecution);
    }

    /**
     * 获取下次执行时间
     *
     * @param string $workflowId 工作流ID
     * @return int|null
     */
    public function getNextExecution(string $workflowId): ?int
    {
        return $this->registrations[$workflowId]['next_execution'] ?? null;
    }

    /**
     * 获取执行次数
     *
     * @param string $workflowId 工作流ID
     * @return int
     */
    public function getExecutionCount(string $workflowId): int
    {
        return $this->registrations[$workflowId]['execution_count'] ?? 0;
    }

    /**
     * 获取上次执行时间
     *
     * @param string $workflowId 工作流ID
     * @return int|null
     */
    public function getLastExecution(string $workflowId): ?int
    {
        return $this->registrations[$workflowId]['last_execution'] ?? null;
    }

    /**
     * 设置默认时区
     *
     * @param string $timezone 时区
     * @return static
     */
    public function setDefaultTimezone(string $timezone): static
    {
        $this->defaultTimezone = $timezone;
        return $this;
    }

    /**
     * 解析 Cron 表达式为人类可读格式
     *
     * @param string $expression Cron 表达式
     * @return string
     */
    public function describeCronExpression(string $expression): string
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            return '无效的 Cron 表达式';
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        // 简单描述
        $descriptions = [];

        if ($minute === '*' && $hour === '*') {
            $descriptions[] = '每分钟';
        } elseif ($minute === '0' && $hour === '*') {
            $descriptions[] = '每小时整点';
        } elseif ($minute === '0' && $hour === '0') {
            $descriptions[] = '每天凌晨';
        } elseif ($hour !== '*') {
            $descriptions[] = sprintf('每天 %s:%s', $hour, $minute === '0' ? '00' : $minute);
        }

        if ($weekday !== '*') {
            $weekdays = ['日', '一', '二', '三', '四', '五', '六'];
            $descriptions[] = sprintf('周%s', $weekdays[(int)$weekday] ?? $weekday);
        }

        if ($day !== '*') {
            $descriptions[] = sprintf('每月 %s 日', $day);
        }

        if ($month !== '*') {
            $descriptions[] = sprintf('%s 月', $month);
        }

        return implode('，', $descriptions) ?: $expression;
    }
}
