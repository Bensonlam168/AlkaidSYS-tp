<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use PHPUnit\Framework\TestCase;
use Alkaid\Workflow\Engine\Nodes\Types\DelayNode;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * DelayNode 单元测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Nodes
 */
class DelayNodeTest extends TestCase
{
    protected ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new ExecutionContext();
        $this->context->setInstanceId('inst_delay_001');
        $this->context->setTenantId(100);
    }

    /**
     * 测试节点创建
     */
    public function testNodeCreation(): void
    {
        $node = new DelayNode('delay_001', [
            'name' => '延时节点',
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => 3600,
            'duration_unit' => DelayNode::UNIT_SECONDS,
        ]);

        $this->assertEquals('delay_001', $node->getId());
        $this->assertEquals(NodeInterface::TYPE_EVENT, $node->getType());
    }

    /**
     * 测试固定时长延时
     */
    public function testDurationDelay(): void
    {
        $node = new DelayNode('delay_duration', [
            'name' => '时长延时',
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => 60,
            'duration_unit' => DelayNode::UNIT_SECONDS,
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_WAITING, $result->getStatus());
        $this->assertArrayHasKey('target_time', $result->getData());
        $this->assertArrayHasKey('remaining_seconds', $result->getData());
        $this->assertGreaterThan(0, $result->getData()['remaining_seconds']);
    }

    /**
     * 测试时长单位转换
     */
    public function testDurationUnits(): void
    {
        $node = new DelayNode('delay_units', [
            'name' => '单位测试',
        ]);

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('convertToSeconds');
        $method->setAccessible(true);

        // 秒
        $this->assertEquals(60, $method->invoke($node, 60, DelayNode::UNIT_SECONDS));

        // 分钟
        $this->assertEquals(120, $method->invoke($node, 2, DelayNode::UNIT_MINUTES));

        // 小时
        $this->assertEquals(7200, $method->invoke($node, 2, DelayNode::UNIT_HOURS));

        // 天
        $this->assertEquals(172800, $method->invoke($node, 2, DelayNode::UNIT_DAYS));

        // 周
        $this->assertEquals(1209600, $method->invoke($node, 2, DelayNode::UNIT_WEEKS));
    }

    /**
     * 测试指定时间延时
     */
    public function testUntilDelay(): void
    {
        $targetTime = (new \DateTimeImmutable('+1 hour'))->format('c');

        $node = new DelayNode('delay_until', [
            'name' => '指定时间',
            'delay_type' => DelayNode::TYPE_UNTIL,
            'until_time' => $targetTime,
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_WAITING, $result->getStatus());
        $this->assertEquals($targetTime, $result->getData()['target_time']);
    }

    /**
     * 测试延时已过期
     */
    public function testDelayAlreadyElapsed(): void
    {
        $pastTime = (new \DateTimeImmutable('-1 hour'))->format('c');

        $node = new DelayNode('delay_past', [
            'name' => '已过期',
            'delay_type' => DelayNode::TYPE_UNTIL,
            'until_time' => $pastTime,
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertArrayHasKey('completed_at', $result->getData());
    }

    /**
     * 测试零延时
     */
    public function testZeroDelay(): void
    {
        $node = new DelayNode('delay_zero', [
            'name' => '零延时',
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => 0,
            'duration_unit' => DelayNode::UNIT_SECONDS,
        ]);

        $result = $node->execute($this->context);

        // 零延时应该立即完成
        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
    }

    /**
     * 测试定时器数据持久化
     */
    public function testTimerDataPersistence(): void
    {
        $node = new DelayNode('delay_persist', [
            'name' => '持久化测试',
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => 3600,
            'duration_unit' => DelayNode::UNIT_SECONDS,
        ]);

        // 第一次执行
        $result1 = $node->execute($this->context);
        $timerId1 = $result1->getData()['timer_id'];
        $targetTime1 = $result1->getData()['target_time'];

        // 第二次执行应该返回相同的定时器
        $result2 = $node->execute($this->context);
        $timerId2 = $result2->getData()['timer_id'];
        $targetTime2 = $result2->getData()['target_time'];

        $this->assertEquals($timerId1, $timerId2);
        $this->assertEquals($targetTime1, $targetTime2);
    }

    /**
     * 测试跳过延时
     */
    public function testSkipDelay(): void
    {
        $node = new DelayNode('delay_skip', [
            'name' => '跳过测试',
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => 3600,
        ]);

        // 先创建定时器
        $node->execute($this->context);

        // 跳过延时
        $result = $node->skip($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(DelayNode::STATUS_SKIPPED, $result->getData()['status']);
    }

    /**
     * 测试剩余时间格式化
     */
    public function testRemainingTimeFormatting(): void
    {
        $node = new DelayNode('delay_format', [
            'name' => '格式化测试',
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => 90061, // 1天1小时1分1秒
            'duration_unit' => DelayNode::UNIT_SECONDS,
        ]);

        $result = $node->execute($this->context);

        $remaining = $result->getData()['remaining_human'];
        $this->assertStringContainsString('天', $remaining);
        $this->assertStringContainsString('小时', $remaining);
        $this->assertStringContainsString('分钟', $remaining);
    }

    /**
     * 测试表达式变量解析
     */
    public function testExpressionVariableResolution(): void
    {
        $node = new DelayNode('delay_expr', [
            'name' => '表达式',
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => '${delay_seconds}',
            'duration_unit' => DelayNode::UNIT_SECONDS,
        ]);

        $this->context->setVariable('delay_seconds', 120);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_WAITING, $result->getStatus());
        // 应该有约120秒的延时
        $this->assertGreaterThan(100, $result->getData()['remaining_seconds']);
    }

    /**
     * 测试跳过周末
     */
    public function testSkipWeekends(): void
    {
        $node = new DelayNode('delay_weekend', [
            'name' => '跳过周末',
        ]);

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('skipWeekends');
        $method->setAccessible(true);

        // 创建一个周六
        $saturday = new \DateTimeImmutable('next Saturday');
        $result = $method->invoke($node, $saturday);

        // 应该跳到周一
        $this->assertEquals(1, (int)$result->format('w')); // 1 = Monday
    }

    /**
     * 测试跳过假期
     */
    public function testSkipHolidays(): void
    {
        $node = new DelayNode('delay_holiday', [
            'name' => '跳过假期',
        ]);

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('skipHolidays');
        $method->setAccessible(true);

        $targetDate = new \DateTimeImmutable('2025-01-01');
        $holidays = ['2025-01-01', '2025-01-02'];

        $result = $method->invoke($node, $targetDate, $holidays);

        $this->assertEquals('2025-01-03', $result->format('Y-m-d'));
    }

    /**
     * 测试Cron字段匹配
     */
    public function testCronFieldMatching(): void
    {
        $node = new DelayNode('delay_cron', [
            'name' => 'Cron测试',
        ]);

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('matchesCronField');
        $method->setAccessible(true);

        // 通配符匹配所有
        $this->assertTrue($method->invoke($node, '*', 5));
        $this->assertTrue($method->invoke($node, '*', 30));

        // 精确匹配
        $this->assertTrue($method->invoke($node, '5', 5));
        $this->assertFalse($method->invoke($node, '5', 6));

        // 逗号分隔
        $this->assertTrue($method->invoke($node, '1,5,10', 5));
        $this->assertFalse($method->invoke($node, '1,5,10', 7));

        // 步进值
        $this->assertTrue($method->invoke($node, '*/5', 0));
        $this->assertTrue($method->invoke($node, '*/5', 10));
        $this->assertFalse($method->invoke($node, '*/5', 7));
    }

    /**
     * 测试配置验证
     */
    public function testConfigValidation(): void
    {
        $node = new DelayNode('delay_valid', [
            'name' => '验证测试',
        ]);

        // 有效时长配置
        $validDuration = [
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => 60,
            'duration_unit' => DelayNode::UNIT_SECONDS,
        ];
        $this->assertTrue($node->validateConfig($validDuration));

        // 有效指定时间配置
        $validUntil = [
            'delay_type' => DelayNode::TYPE_UNTIL,
            'until_time' => '2025-12-31T23:59:59+08:00',
        ];
        $this->assertTrue($node->validateConfig($validUntil));

        // 无效配置 - until类型但缺少until_time
        $invalidUntil = [
            'delay_type' => DelayNode::TYPE_UNTIL,
        ];
        $this->assertFalse($node->validateConfig($invalidUntil));

        // 无效配置 - cron类型但缺少表达式
        $invalidCron = [
            'delay_type' => DelayNode::TYPE_CRON,
        ];
        $this->assertFalse($node->validateConfig($invalidCron));

        // 无效配置 - 无效的延时类型
        $invalidType = [
            'delay_type' => 'invalid_type',
        ];
        $this->assertFalse($node->validateConfig($invalidType));

        // 无效配置 - 负数时长
        $invalidNegative = [
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => -10,
        ];
        $this->assertFalse($node->validateConfig($invalidNegative));

        // 无效配置 - 无效的时长单位
        $invalidUnit = [
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => 60,
            'duration_unit' => 'invalid_unit',
        ];
        $this->assertFalse($node->validateConfig($invalidUnit));

        // 无效配置 - cron表达式格式错误
        $invalidCronFormat = [
            'delay_type' => DelayNode::TYPE_CRON,
            'cron_expression' => '* * *', // 不完整
        ];
        $this->assertFalse($node->validateConfig($invalidCronFormat));
    }

    /**
     * 测试获取配置 Schema
     */
    public function testGetConfigSchema(): void
    {
        $node = new DelayNode('delay_schema', [
            'name' => '配置测试',
        ]);
        $schema = $node->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('delay_type', $schema['properties']);
        $this->assertArrayHasKey('duration', $schema['properties']);
        $this->assertArrayHasKey('duration_unit', $schema['properties']);
        $this->assertArrayHasKey('until_time', $schema['properties']);
        $this->assertArrayHasKey('cron_expression', $schema['properties']);
        $this->assertArrayHasKey('timezone', $schema['properties']);
        $this->assertArrayHasKey('skip_weekends', $schema['properties']);
        $this->assertArrayHasKey('business_hours_only', $schema['properties']);
    }

    /**
     * 测试时区配置
     */
    public function testTimezoneConfiguration(): void
    {
        $node = new DelayNode('delay_tz', [
            'name' => '时区测试',
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => 60,
            'timezone' => 'America/New_York',
        ]);

        $result = $node->execute($this->context);

        // 确保能正常执行
        $this->assertEquals(NodeResult::STATUS_WAITING, $result->getStatus());
    }

    /**
     * 测试多种延时类型
     */
    public function testDelayTypes(): void
    {
        $types = [
            DelayNode::TYPE_DURATION,
            DelayNode::TYPE_UNTIL,
            DelayNode::TYPE_EXPRESSION,
            DelayNode::TYPE_CRON,
        ];

        foreach ($types as $type) {
            $node = new DelayNode("delay_{$type}", [
                'name' => "类型{$type}测试",
            ]);
            $schema = $node->getConfigSchema();
            $validTypes = $schema['properties']['delay_type']['enum'];

            $this->assertContains($type, $validTypes, "Type {$type} should be valid");
        }
    }

    /**
     * 测试工作时间配置
     */
    public function testBusinessHoursConfiguration(): void
    {
        $node = new DelayNode('delay_biz', [
            'name' => '工作时间',
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => 8,
            'duration_unit' => DelayNode::UNIT_HOURS,
            'business_hours_only' => true,
            'business_hours_start' => '09:00',
            'business_hours_end' => '18:00',
        ]);

        // 确保配置被接受
        $this->assertTrue($node->validateConfig([
            'delay_type' => DelayNode::TYPE_DURATION,
            'duration' => 8,
            'business_hours_only' => true,
        ]));
    }
}
