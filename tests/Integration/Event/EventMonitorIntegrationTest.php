<?php

declare(strict_types=1);

namespace Tests\Integration\Event;

use Domain\Event\EventContext;
use Domain\Event\EventPayload;
use Infrastructure\Event\EventMonitor;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 事件监控落库集成测试（T-049）
 *
 * 验证 EventMonitor 在表存在时可以落库（event_metrics/event_dead_letters）。
 */
class EventMonitorIntegrationTest extends ThinkPHPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTables();
        $this->truncateTables();
    }

    protected function tearDown(): void
    {
        $this->truncateTables();
        parent::tearDown();
    }

    public function testRecordMetricWritesToDatabaseWhenTableExists(): void
    {
        $monitor = new EventMonitor();

        $ctx = new EventContext('trace-metric', 100, 2, 9);
        $payload = new EventPayload('metric.test', ['foo' => 'bar'], $ctx);

        $monitor->recordMetric('metric.test', $payload, 12.34, false);

        $row = Db::table('event_metrics')
            ->where('event', 'metric.test')
            ->where('trace_id', 'trace-metric')
            ->find();

        $this->assertNotEmpty($row);
        $this->assertEquals(100, (int) $row['tenant_id']);
        $this->assertEquals(2, (int) $row['site_id']);
        $this->assertEquals(9, (int) $row['user_id']);
        $this->assertEquals(0, (int) $row['failed']);
    }

    public function testRecordDeadLetterWritesToDatabaseWhenTableExists(): void
    {
        $monitor = new EventMonitor();
        $ctx = new EventContext('trace-dlq', 101, 3, 10);

        $monitor->recordDeadLetter('dlq.test', $ctx, new \RuntimeException('boom', 2002), 5, 2002, [
            'event' => 'dlq.test',
            'params' => ['id' => 1],
        ]);

        $row = Db::table('event_dead_letters')
            ->where('event', 'dlq.test')
            ->where('trace_id', 'trace-dlq')
            ->find();

        $this->assertNotEmpty($row);
        $this->assertEquals(2002, (int) $row['code']);
        $this->assertEquals(5, (int) $row['attempts']);
        $this->assertStringContainsString('boom', (string) $row['error']);
        $this->assertNotEmpty($row['payload']);
    }

    /**
     * 确保表存在：测试环境可能未执行 migrate:run，因此在这里兜底创建。
     */
    protected function ensureTables(): void
    {
        Db::execute(
            'CREATE TABLE IF NOT EXISTS `event_metrics` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event` VARCHAR(128) NOT NULL,
                `trace_id` VARCHAR(64) NULL,
                `tenant_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `site_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `duration_ms` DECIMAL(10,2) NOT NULL DEFAULT 0,
                `failed` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_event_created_at` (`event`, `created_at`),
                KEY `idx_tenant_site_failed` (`tenant_id`, `site_id`, `failed`),
                KEY `idx_trace_id` (`trace_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Db::execute(
            'CREATE TABLE IF NOT EXISTS `event_dead_letters` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` INT UNSIGNED NOT NULL DEFAULT 2001,
                `event` VARCHAR(128) NOT NULL,
                `trace_id` VARCHAR(64) NULL,
                `tenant_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `site_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `error` TEXT NULL,
                `payload` TEXT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dlq_event_created_at` (`event`, `created_at`),
                KEY `idx_dlq_code_created_at` (`code`, `created_at`),
                KEY `idx_dlq_tenant_site` (`tenant_id`, `site_id`),
                KEY `idx_dlq_trace_id` (`trace_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    protected function truncateTables(): void
    {
        try {
            Db::execute('TRUNCATE TABLE `event_metrics`');
        } catch (\Throwable) {
            // 忽略
        }
        try {
            Db::execute('TRUNCATE TABLE `event_dead_letters`');
        } catch (\Throwable) {
            // 忽略
        }
    }
}
