<?php

declare(strict_types=1);

namespace Tests\Integration\Event;

use Domain\Event\EventContext;
use Domain\Event\EventPayload;
use Infrastructure\Event\EventMonitor;
use Tests\ThinkPHPTestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * 事件监控阈值与告警集成测试
 *
 * 覆盖慢事件/失败率/死信告警路径，确保落库与日志降级逻辑可用。
 */
class EventMonitorThresholdTest extends ThinkPHPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearTables();
    }

    protected function tearDown(): void
    {
        $this->clearTables();
        parent::tearDown();
    }

    /**
     * 慢事件与失败率告警：记录一次失败事件，触发 slow 和 failure_rate 告警并落库。
     */
    public function testSlowAndFailureRateAlertsWrittenToAlertsTable(): void
    {
        // 配置低阈值，确保单条记录就能触发
        // NOTE: ThinkPHP Config::set 对嵌套配置更稳妥，这里按完整结构写入。
        Config::set([
            'event' => [
                'monitor' => [
                    'slow_ms' => 1,
                    'failure_rate_window_seconds' => 300,
                    'failure_rate_threshold' => 0.1,
                    'failure_rate_min_samples' => 1,
                ],
            ],
        ]);

        $monitor = new EventMonitor();
        $ctx = new EventContext('trace-threshold', 33, 7, 18);
        $payload = new EventPayload('monitor.threshold', ['k' => 'v'], $ctx);

        // 记录一次失败且耗时超阈值的事件
        $monitor->recordMetric('monitor.threshold', $payload, 5.12, true);

        // 验证告警落库：慢事件 + 失败率（以及 failed 告警）应插入
        $alerts = Db::table('event_alerts')
            ->where('event', 'monitor.threshold')
            ->column('alert_key');

        $this->assertContains('event.alert.slow', $alerts);
        $this->assertContains('event.alert.failed', $alerts);
        $this->assertContains('event.alert.failure_rate', $alerts);
    }

    /**
     * 死信写入与告警：进入 DLQ 时应落库并产生告警。
     */
    public function testDeadLetterWritesToDlqAndPublishesAlert(): void
    {
        $monitor = new EventMonitor();
        $ctx = new EventContext('trace-dlq', 44, 0, 99);
        $payload = ['foo' => 'bar'];

        $monitor->recordDeadLetter('monitor.dlq', $ctx, new \RuntimeException('dlq error'), 3, 2002, $payload);

        $dlq = Db::table('event_dead_letters')
            ->where('event', 'monitor.dlq')
            ->where('trace_id', 'trace-dlq')
            ->find();
        $this->assertNotEmpty($dlq, '死信应落库');
        $this->assertEquals(2002, (int) $dlq['code']);

        $alert = Db::table('event_alerts')
            ->where('alert_key', 'event.alert.dlq')
            ->where('event', 'monitor.dlq')
            ->find();
        $this->assertNotEmpty($alert, '死信应产生告警记录');
    }

    private function clearTables(): void
    {
        Db::table('event_metrics')->where('event', 'monitor.threshold')->delete();
        Db::table('event_dead_letters')->where('event', 'monitor.dlq')->delete();
        Db::table('event_alerts')->whereIn('alert_key', [
            'event.alert.slow',
            'event.alert.failure_rate',
            'event.alert.dlq',
        ])->delete();
    }
}
