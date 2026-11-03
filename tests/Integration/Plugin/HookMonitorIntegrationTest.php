<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin;

use Domain\Event\EventContext;
use Infrastructure\Plugin\Hook\HookMonitor;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 钩子监控/死信集成测试，需先执行迁移创建 hook_* 表。
 */
class HookMonitorIntegrationTest extends ThinkPHPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->tablesReady()) {
            $this->markTestSkipped('hook monitor tables not migrated');
        }
    }

    public function testRecordMetricWritesDb(): void
    {
        $monitor = new HookMonitor();
        $ctx = new EventContext('trace-hook-metric', 1, 0, 2);

        $monitor->recordMetric('hook.metric.demo', 'action', $ctx, 123.45, false);

        $row = Db::table('hook_metrics')
            ->where('hook', 'hook.metric.demo')
            ->where('trace_id', 'trace-hook-metric')
            ->find();

        $this->assertNotEmpty($row);
        $this->assertEquals(123.45, (float) $row['duration_ms']);
        $this->assertSame(0, (int) $row['failed']);
    }

    public function testDeadLetterWritesDbAndAlert(): void
    {
        $monitor = new HookMonitor();
        $ctx = new EventContext('trace-hook-dlq', 9, 0, 8);
        $payload = ['foo' => 'bar'];

        $monitor->recordDeadLetter('hook.dlq.demo', $ctx, new \RuntimeException('demo error', 2002), 3, 2002, $payload);

        $dlq = Db::table('hook_dead_letters')
            ->where('hook', 'hook.dlq.demo')
            ->where('trace_id', 'trace-hook-dlq')
            ->find();
        $this->assertNotEmpty($dlq);
        $this->assertEquals(2002, (int) $dlq['code']);

        $alert = Db::table('hook_alerts')
            ->where('alert_key', 'hook.alert.dlq')
            ->where('hook', 'hook.dlq.demo')
            ->find();
        $this->assertNotEmpty($alert);
    }

    private function tablesReady(): bool
    {
        try {
            return Db::table('hook_metrics')->getFields() !== null
                && Db::table('hook_dead_letters')->getFields() !== null
                && Db::table('hook_alerts')->getFields() !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
