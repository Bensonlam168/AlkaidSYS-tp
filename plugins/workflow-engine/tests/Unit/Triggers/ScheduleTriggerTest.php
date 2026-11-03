<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\Types\ScheduleTrigger;
use PHPUnit\Framework\TestCase;

/**
 * ScheduleTrigger 单元测试
 */
class ScheduleTriggerTest extends TestCase
{
    private ScheduleTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trigger = new ScheduleTrigger();
    }

    /**
     * 测试触发器类型
     */
    public function testGetType(): void
    {
        $this->assertEquals(TriggerInterface::TYPE_SCHEDULE, $this->trigger->getType());
    }

    /**
     * 测试触发器名称
     */
    public function testGetName(): void
    {
        $name = $this->trigger->getName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    /**
     * 测试配置 Schema
     */
    public function testGetConfigSchema(): void
    {
        $schema = $this->trigger->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('cron_expression', $schema['properties']);
        $this->assertArrayHasKey('timezone', $schema['properties']);
    }

    /**
     * 测试有效 Cron 表达式验证
     */
    public function testValidateCronExpressionValid(): void
    {
        $validExpressions = [
            '* * * * *',        // 每分钟
            '0 * * * *',        // 每小时整点
            '0 9 * * *',        // 每天 9 点
            '0 9 * * 1',        // 每周一 9 点
            '30 9 1 * *',       // 每月 1 日 9:30
            '*/5 * * * *',      // 每 5 分钟
            '0 */2 * * *',      // 每 2 小时
            '0 9-17 * * *',     // 9 点到 17 点每小时
            '0 9,12,18 * * *',  // 9 点、12 点、18 点
        ];

        foreach ($validExpressions as $expression) {
            $this->assertTrue(
                $this->trigger->validateCronExpression($expression),
                "表达式 '{$expression}' 应该有效"
            );
        }
    }

    /**
     * 测试无效 Cron 表达式验证
     */
    public function testValidateCronExpressionInvalid(): void
    {
        $invalidExpressions = [
            '',                 // 空
            '* * *',            // 不完整
            '* * * * * *',      // 太多部分
            '60 * * * *',       // 分钟超出范围
            '* 24 * * *',       // 小时超出范围
            '* * 32 * *',       // 日期超出范围
            '* * * 13 *',       // 月份超出范围
            '* * * * 7',        // 星期超出范围
        ];

        foreach ($invalidExpressions as $expression) {
            $this->assertFalse(
                $this->trigger->validateCronExpression($expression),
                "表达式 '{$expression}' 应该无效"
            );
        }
    }

    /**
     * 测试配置验证
     */
    public function testValidateConfig(): void
    {
        // 有效配置
        $validConfig = [
            'cron_expression' => '0 9 * * *',
            'timezone' => 'Asia/Shanghai',
        ];
        $this->assertTrue($this->trigger->validateConfig($validConfig));

        // 无效 Cron 表达式
        $invalidConfig = [
            'cron_expression' => 'invalid',
        ];
        $this->assertFalse($this->trigger->validateConfig($invalidConfig));

        // 开始日期晚于结束日期
        $invalidDateConfig = [
            'cron_expression' => '0 9 * * *',
            'start_date' => '2025-12-31',
            'end_date' => '2025-01-01',
        ];
        $this->assertFalse($this->trigger->validateConfig($invalidDateConfig));
    }

    /**
     * 测试注册触发器
     */
    public function testRegister(): void
    {
        $workflowId = 'test-workflow-001';
        $config = [
            'cron_expression' => '0 9 * * *',
            'timezone' => 'Asia/Shanghai',
        ];

        $this->trigger->register($workflowId, $config);

        $this->assertTrue($this->trigger->isRegistered($workflowId));
        $this->assertNotNull($this->trigger->getNextExecution($workflowId));
        $this->assertEquals(0, $this->trigger->getExecutionCount($workflowId));
    }

    /**
     * 测试计算下次执行时间
     */
    public function testCalculateNextExecution(): void
    {
        // 每天 9 点
        $nextExecution = $this->trigger->calculateNextExecution('0 9 * * *', 'Asia/Shanghai');

        $this->assertIsInt($nextExecution);
        $this->assertGreaterThan(time(), $nextExecution);

        // 检查时间是 9:00
        $dateTime = new \DateTime();
        $dateTime->setTimestamp($nextExecution);
        $dateTime->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        $this->assertEquals('09', $dateTime->format('H'));
        $this->assertEquals('00', $dateTime->format('i'));
    }

    /**
     * 测试不同时区的下次执行时间
     */
    public function testCalculateNextExecutionDifferentTimezones(): void
    {
        $cronExpression = '0 12 * * *'; // 每天中午 12 点

        $shanghaiNext = $this->trigger->calculateNextExecution($cronExpression, 'Asia/Shanghai');
        $utcNext = $this->trigger->calculateNextExecution($cronExpression, 'UTC');

        // 两个时区的下次执行时间应该不同（除非恰好在同一天）
        // UTC 比上海时间慢 8 小时
        $this->assertNotEquals($shanghaiNext, $utcNext);
    }

    /**
     * 测试触发条件检查
     */
    public function testShouldTrigger(): void
    {
        $workflowId = 'test-workflow-002';
        $config = [
            'cron_expression' => '0 9 * * *',
            'enabled' => true,
        ];

        $this->trigger->register($workflowId, $config);

        // 正确的定时触发上下文应该触发
        $context = TriggerContext::schedule($workflowId, '0 9 * * *', time());
        $this->assertTrue($this->trigger->shouldTrigger($context));

        // 非定时触发上下文不应该触发
        $manualContext = TriggerContext::manual($workflowId);
        $this->assertFalse($this->trigger->shouldTrigger($manualContext));
    }

    /**
     * 测试禁用状态不触发
     */
    public function testShouldNotTriggerWhenDisabled(): void
    {
        $workflowId = 'test-workflow-003';
        $config = [
            'cron_expression' => '0 9 * * *',
            'enabled' => false,
        ];

        $this->trigger->register($workflowId, $config);

        $context = TriggerContext::schedule($workflowId, '0 9 * * *', time());
        $this->assertFalse($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试执行触发
     */
    public function testTrigger(): void
    {
        $workflowId = 'test-workflow-004';
        $cronExpression = '0 9 * * *';
        $config = [
            'name' => '每日定时任务',
            'cron_expression' => $cronExpression,
            'timezone' => 'Asia/Shanghai',
            'input_data' => ['batch' => true],
        ];

        $this->trigger->register($workflowId, $config);

        $scheduledTime = time();
        $context = TriggerContext::schedule($workflowId, $cronExpression, $scheduledTime);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_SCHEDULE, $result->getTriggerType());

        $data = $result->getData();
        $this->assertEquals($cronExpression, $data['cron_expression']);
        $this->assertEquals('Asia/Shanghai', $data['timezone']);
        $this->assertEquals(1, $data['execution_count']);
        $this->assertArrayHasKey('next_execution', $data);

        // 检查执行次数增加
        $this->assertEquals(1, $this->trigger->getExecutionCount($workflowId));
    }

    /**
     * 测试最大执行次数限制
     */
    public function testMaxExecutionsLimit(): void
    {
        $workflowId = 'test-workflow-005';
        $config = [
            'cron_expression' => '0 9 * * *',
            'max_executions' => 2,
        ];

        $this->trigger->register($workflowId, $config);

        $context = TriggerContext::schedule($workflowId, '0 9 * * *', time());

        // 第一次执行
        $result1 = $this->trigger->trigger($context);
        $this->assertTrue($result1->isSuccess());

        // 第二次执行
        $result2 = $this->trigger->trigger($context);
        $this->assertTrue($result2->isSuccess());

        // 第三次应该被跳过（达到最大执行次数）
        $result3 = $this->trigger->trigger($context);
        $this->assertTrue($result3->isSkipped());
    }

    /**
     * 测试开始日期限制
     */
    public function testStartDateLimit(): void
    {
        $workflowId = 'test-workflow-006';
        $futureDate = date('Y-m-d H:i:s', strtotime('+1 year'));
        $config = [
            'cron_expression' => '0 9 * * *',
            'start_date' => $futureDate,
        ];

        $this->trigger->register($workflowId, $config);

        $context = TriggerContext::schedule($workflowId, '0 9 * * *', time());
        $this->assertFalse($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试结束日期限制
     */
    public function testEndDateLimit(): void
    {
        $workflowId = 'test-workflow-007';
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));
        $config = [
            'cron_expression' => '0 9 * * *',
            'end_date' => $pastDate,
        ];

        $this->trigger->register($workflowId, $config);

        $context = TriggerContext::schedule($workflowId, '0 9 * * *', time());
        $this->assertFalse($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试设置默认时区
     */
    public function testSetDefaultTimezone(): void
    {
        $this->trigger->setDefaultTimezone('America/New_York');

        // 验证链式调用
        $result = $this->trigger->setDefaultTimezone('Europe/London');
        $this->assertInstanceOf(ScheduleTrigger::class, $result);
    }

    /**
     * 测试描述 Cron 表达式
     */
    public function testDescribeCronExpression(): void
    {
        // 每分钟
        $desc1 = $this->trigger->describeCronExpression('* * * * *');
        $this->assertIsString($desc1);
        $this->assertNotEmpty($desc1);

        // 每小时整点
        $desc2 = $this->trigger->describeCronExpression('0 * * * *');
        $this->assertIsString($desc2);

        // 每天凌晨
        $desc3 = $this->trigger->describeCronExpression('0 0 * * *');
        $this->assertIsString($desc3);

        // 无效表达式
        $desc4 = $this->trigger->describeCronExpression('invalid');
        $this->assertStringContainsString('无效', $desc4);
    }

    /**
     * 测试获取上次执行时间
     */
    public function testGetLastExecution(): void
    {
        $workflowId = 'test-workflow-008';
        $config = [
            'cron_expression' => '0 9 * * *',
        ];

        $this->trigger->register($workflowId, $config);

        // 初始应该为 null
        $this->assertNull($this->trigger->getLastExecution($workflowId));

        // 执行后应该有值
        $context = TriggerContext::schedule($workflowId, '0 9 * * *', time());
        $this->trigger->trigger($context);

        $lastExecution = $this->trigger->getLastExecution($workflowId);
        $this->assertIsInt($lastExecution);
        $this->assertLessThanOrEqual(time(), $lastExecution);
    }

    /**
     * 测试 Cron 部分验证 - 范围
     */
    public function testCronPartValidationRange(): void
    {
        // 有效范围
        $this->assertTrue($this->trigger->validateCronExpression('0 9-17 * * *'));
        $this->assertTrue($this->trigger->validateCronExpression('* * 1-15 * *'));

        // 无效范围（起始大于结束）
        $this->assertFalse($this->trigger->validateCronExpression('0 17-9 * * *'));
    }

    /**
     * 测试 Cron 部分验证 - 列表
     */
    public function testCronPartValidationList(): void
    {
        // 有效列表
        $this->assertTrue($this->trigger->validateCronExpression('0 9,12,18 * * *'));
        $this->assertTrue($this->trigger->validateCronExpression('0,15,30,45 * * * *'));
    }

    /**
     * 测试 Cron 部分验证 - 步长
     */
    public function testCronPartValidationStep(): void
    {
        // 有效步长
        $this->assertTrue($this->trigger->validateCronExpression('*/5 * * * *'));
        $this->assertTrue($this->trigger->validateCronExpression('* */2 * * *'));
        $this->assertTrue($this->trigger->validateCronExpression('* * */7 * *'));
    }
}
