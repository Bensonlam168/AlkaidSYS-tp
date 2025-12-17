<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Triggers;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\Types\TimerEventTrigger;
use PHPUnit\Framework\TestCase;

/**
 * TimerEventTrigger 单元测试
 */
class TimerEventTriggerTest extends TestCase
{
    private TimerEventTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trigger = new TimerEventTrigger();
    }

    /**
     * 测试触发器类型
     */
    public function testGetType(): void
    {
        $this->assertEquals(TriggerInterface::TYPE_TIMER_EVENT, $this->trigger->getType());
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
     * 测试触发器描述
     */
    public function testGetDescription(): void
    {
        $description = $this->trigger->getDescription();
        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    /**
     * 测试配置 Schema
     */
    public function testGetConfigSchema(): void
    {
        $schema = $this->trigger->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('type', $schema);
        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('timer_type', $schema['properties']);
        $this->assertArrayHasKey('timer_value', $schema['properties']);
    }

    /**
     * 测试有效配置验证
     */
    public function testValidateValidConfig(): void
    {
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_DURATION,
            'timer_value' => 'PT1H',
        ];

        $result = $this->trigger->validateConfig($config);
        $this->assertTrue($result);
    }

    /**
     * 测试缺少必填字段的配置验证
     */
    public function testValidateConfigMissingRequired(): void
    {
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_DURATION,
        ];

        $result = $this->trigger->validateConfig($config);
        $this->assertFalse($result);
    }

    /**
     * 测试注册触发器
     */
    public function testRegister(): void
    {
        $workflowId = 'test-workflow-001';
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_DURATION,
            'timer_value' => 'PT1H',
        ];

        $this->trigger->register($workflowId, $config);

        $this->assertTrue($this->trigger->isRegistered($workflowId));
    }

    /**
     * 测试定时器触发
     */
    public function testTriggerWithTimer(): void
    {
        $workflowId = 'test-workflow-002';
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_DURATION,
            'timer_value' => 'PT1H',
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_TIMER_EVENT);
        $context->setTenantId(100);
        $context->setMeta('is_timer_event', true);
        $context->setMeta('timer_registration_time', date('Y-m-d H:i:s', strtotime('-2 hours')));
        $context->setData(['is_timer_event' => true]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(TriggerInterface::TYPE_TIMER_EVENT, $result->getTriggerType());
    }

    /**
     * 测试非定时器事件不触发
     */
    public function testShouldNotTriggerWithoutTimerEvent(): void
    {
        $workflowId = 'test-workflow-003';
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_DURATION,
            'timer_value' => 'PT1H',
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_TIMER_EVENT);
        $context->setData([]);

        $this->assertFalse($this->trigger->shouldTrigger($context));
    }

    /**
     * 测试计算日期时间触发时间
     */
    public function testCalculateTimeDateFireTime(): void
    {
        $futureDate = date('Y-m-d\TH:i:s', strtotime('+1 day'));
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_DATE,
            'timer_value' => $futureDate,
            'timezone' => 'Asia/Shanghai',
        ];

        $fireTime = $this->trigger->calculateNextFireTime($config);

        $this->assertInstanceOf(\DateTime::class, $fireTime);
    }

    /**
     * 测试计算持续时间触发时间
     */
    public function testCalculateDurationFireTime(): void
    {
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_DURATION,
            'timer_value' => 'PT2H', // 2 hours
            'timezone' => 'Asia/Shanghai',
        ];

        $context = new TriggerContext();
        $context->setMeta('timer_registration_time', date('Y-m-d H:i:s'));

        $fireTime = $this->trigger->calculateNextFireTime($config, $context);

        $this->assertInstanceOf(\DateTime::class, $fireTime);
    }

    /**
     * 测试计算周期触发时间
     */
    public function testCalculateCycleFireTime(): void
    {
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_CYCLE,
            'timer_value' => 'R3/PT1H', // 3 times, every hour
            'timezone' => 'Asia/Shanghai',
        ];

        $context = new TriggerContext();
        $context->setMeta('timer_registration_time', date('Y-m-d H:i:s'));

        $fireTime = $this->trigger->calculateNextFireTime($config, $context);

        $this->assertInstanceOf(\DateTime::class, $fireTime);
    }

    /**
     * 测试无效定时器值
     */
    public function testInvalidTimerValue(): void
    {
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_DURATION,
            'timer_value' => 'invalid',
            'timezone' => 'Asia/Shanghai',
        ];

        $fireTime = $this->trigger->calculateNextFireTime($config);

        $this->assertNull($fireTime);
    }

    /**
     * 测试周期执行次数限制
     */
    public function testCycleMaxRepetitions(): void
    {
        $workflowId = 'test-workflow-004';
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_CYCLE,
            'timer_value' => 'R3/PT1H',
            'max_repetitions' => 3,
        ];

        $this->trigger->register($workflowId, $config);

        // 执行次数未超过限制
        $contextUnderLimit = new TriggerContext();
        $contextUnderLimit->setWorkflowId($workflowId);
        $contextUnderLimit->setTriggerType(TriggerInterface::TYPE_TIMER_EVENT);
        $contextUnderLimit->setMeta('is_timer_event', true);
        $contextUnderLimit->setMeta('timer_execution_count', 2);
        $contextUnderLimit->setMeta('timer_registration_time', date('Y-m-d H:i:s', strtotime('-3 hours')));
        $contextUnderLimit->setMeta('timer_last_execution', date('Y-m-d H:i:s', strtotime('-2 hours')));
        $contextUnderLimit->setData(['is_timer_event' => true]);

        $this->assertTrue($this->trigger->shouldTrigger($contextUnderLimit));

        // 执行次数已达到限制
        $contextAtLimit = new TriggerContext();
        $contextAtLimit->setWorkflowId($workflowId);
        $contextAtLimit->setTriggerType(TriggerInterface::TYPE_TIMER_EVENT);
        $contextAtLimit->setMeta('is_timer_event', true);
        $contextAtLimit->setMeta('timer_execution_count', 3);
        $contextAtLimit->setMeta('timer_registration_time', date('Y-m-d H:i:s', strtotime('-4 hours')));
        $contextAtLimit->setMeta('timer_last_execution', date('Y-m-d H:i:s', strtotime('-1 hour')));
        $contextAtLimit->setData(['is_timer_event' => true]);

        $this->assertFalse($this->trigger->shouldTrigger($contextAtLimit));
    }

    /**
     * 测试触发结果包含执行计数
     */
    public function testTriggerResultContainsExecutionCount(): void
    {
        $workflowId = 'test-workflow-005';
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_DURATION,
            'timer_value' => 'PT1H',
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_TIMER_EVENT);
        $context->setTenantId(100);
        $context->setMeta('is_timer_event', true);
        $context->setMeta('timer_execution_count', 5);
        $context->setMeta('timer_registration_time', date('Y-m-d H:i:s', strtotime('-2 hours')));
        $context->setData(['is_timer_event' => true]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(6, $result->getMeta('execution_count'));
    }

    /**
     * 测试时区设置
     */
    public function testTimezoneConfiguration(): void
    {
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_DATE,
            'timer_value' => '2025-12-31T23:59:59',
            'timezone' => 'America/New_York',
        ];

        $fireTime = $this->trigger->calculateNextFireTime($config);

        $this->assertInstanceOf(\DateTime::class, $fireTime);
        $this->assertEquals('America/New_York', $fireTime->getTimezone()->getName());
    }

    /**
     * 测试定时器类型常量
     */
    public function testTimerTypeConstants(): void
    {
        $this->assertEquals('timeDate', TimerEventTrigger::TIMER_DATE);
        $this->assertEquals('timeDuration', TimerEventTrigger::TIMER_DURATION);
        $this->assertEquals('timeCycle', TimerEventTrigger::TIMER_CYCLE);
    }

    /**
     * 测试事件类型常量
     */
    public function testEventTypeConstants(): void
    {
        $this->assertEquals('start', TimerEventTrigger::EVENT_START);
        $this->assertEquals('intermediate', TimerEventTrigger::EVENT_INTERMEDIATE);
        $this->assertEquals('boundary', TimerEventTrigger::EVENT_BOUNDARY);
    }

    /**
     * 测试周期定时器剩余执行次数
     */
    public function testCycleRemainingRepetitions(): void
    {
        $workflowId = 'test-workflow-006';
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_CYCLE,
            'timer_value' => 'R5/PT1H',
            'max_repetitions' => 5,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_TIMER_EVENT);
        $context->setTenantId(100);
        $context->setMeta('is_timer_event', true);
        $context->setMeta('timer_execution_count', 3);
        $context->setMeta('timer_registration_time', date('Y-m-d H:i:s', strtotime('-4 hours')));
        $context->setMeta('timer_last_execution', date('Y-m-d H:i:s', strtotime('-2 hours')));
        $context->setData(['is_timer_event' => true]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $data = $result->getData();
        $this->assertEquals(5, $data['max_repetitions']);
        $this->assertEquals(1, $data['remaining_repetitions']); // 5 - 4 = 1
    }

    /**
     * 测试取消活动标志
     */
    public function testCancelActivityFlag(): void
    {
        $workflowId = 'test-workflow-007';
        $config = [
            'timer_type' => TimerEventTrigger::TIMER_DURATION,
            'timer_value' => 'PT1H',
            'event_type' => TimerEventTrigger::EVENT_BOUNDARY,
            'cancel_activity' => true,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_TIMER_EVENT);
        $context->setTenantId(100);
        $context->setMeta('is_timer_event', true);
        $context->setMeta('timer_registration_time', date('Y-m-d H:i:s', strtotime('-2 hours')));
        $context->setData(['is_timer_event' => true]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $data = $result->getData();
        $this->assertTrue($data['cancel_activity']);
        $this->assertEquals('boundary', $data['event_type']);
    }

    /**
     * 测试执行数据传递
     */
    public function testExecutionDataPassing(): void
    {
        $workflowId = 'test-workflow-008';
        $executionData = [
            'reminder_type' => 'deadline',
            'custom_field' => 'value',
        ];

        $config = [
            'timer_type' => TimerEventTrigger::TIMER_DURATION,
            'timer_value' => 'PT1H',
            'execution_data' => $executionData,
        ];

        $this->trigger->register($workflowId, $config);

        $context = new TriggerContext();
        $context->setWorkflowId($workflowId);
        $context->setTriggerType(TriggerInterface::TYPE_TIMER_EVENT);
        $context->setTenantId(100);
        $context->setMeta('is_timer_event', true);
        $context->setMeta('timer_registration_time', date('Y-m-d H:i:s', strtotime('-2 hours')));
        $context->setData(['is_timer_event' => true]);

        $result = $this->trigger->trigger($context);

        $this->assertTrue($result->isSuccess());
        $data = $result->getData();
        $this->assertEquals($executionData, $data['execution_data']);
    }
}
