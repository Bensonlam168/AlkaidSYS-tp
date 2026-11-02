<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use Domain\Event\AsyncEventJob;
use Domain\Event\EventContext;
use Domain\Event\EventPayload;
use Domain\Event\EventService;
use Infrastructure\Event\EventMonitor;
use Stringable;
use Tests\ThinkPHPTestCase;
use think\facade\Config;
use think\facade\Event as EventFacade;
use think\queue\Job;

/**
 * 事件失败可观测性测试（ER-13）
 *
 * ## 守住的缺陷
 *
 * `EventService::trigger()` 原来有两处「监听器崩了但没人知道」：
 *   1. 优先级监听器循环：异常直接上抛（与「不中断业务」的预期相反，且没有日志）；
 *   2. ThinkPHP 事件分支：`catch (\Throwable) { $failed = true; }` —— 异常被完全吞掉，
 *      连日志都没有，只能事后查 `event_metrics` 里一行 `failed=1`。
 *
 * ## 本文件锁定的契约
 *
 * | 场景 | 期望 |
 * | --- | --- |
 * | 同步监听器抛异常（默认） | `trigger()` 正常返回 payload；产生 error 日志；后续监听器继续执行 |
 * | `EVENT_FAIL_FAST` 等价开关打开 | 原始异常实例原样上抛（同一个对象） |
 * | 默认关闭 | 不抛（回归） |
 * | 多个监听器失败 | 每个失败各记一条，`listener_key` 可区分（闭包也能区分） |
 * | 异步失败 | warning 日志字段与同步一致（event/trace/tenant/site/user/exception/message/mode） |
 * | 异步达到重试上限 | 写 DLQ 并删除任务 |
 * | DLQ 写失败 | 至少留 error 日志，且任务仍被删除（不会无限重试） |
 * | Log 本身故障 | 降级 `error_log()`，事件不会「失败且无痕迹」 |
 *
 * ## 为什么替换容器里的 logger
 *
 * 断言的是日志语义（级别/字段/是否只记一次），不是落盘格式。
 * 用替身 logger 收集记录，既精确又不受日志文件轮转与并发写入干扰；
 * `tearDown()` 会把真实 logger 放回容器（与 PermissionCacheTryScopeTest 同一手法）。
 *
 * @package Tests\Unit\Event
 */
class EventFailureObservabilityTest extends ThinkPHPTestCase
{
    /**
     * 替身 Logger（收集日志记录）
     *
     * @var object{records: array<int, array{level: string, message: string, context: array<string, mixed>}>}
     */
    private object $fakeLogger;

    /**
     * 被替换掉的真实日志实例（tearDown 时还原）
     */
    private ?object $previousLogger = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeLogger = new class () {
            /**
             * 收集到的日志记录
             *
             * @var array<int, array{level: string, message: string, context: array<string, mixed>}>
             */
            public array $records = [];

            public function error(string|Stringable $message, array $context = []): void
            {
                $this->add('error', $message, $context);
            }

            public function warning(string|Stringable $message, array $context = []): void
            {
                $this->add('warning', $message, $context);
            }

            public function info(string|Stringable $message, array $context = []): void
            {
                $this->add('info', $message, $context);
            }

            public function debug(string|Stringable $message, array $context = []): void
            {
                $this->add('debug', $message, $context);
            }

            /**
             * 兼容 EventMonitor::publishAlert 的 `Log::channel('event_alert')`
             *
             * 返回自身即可：本文件的断言只关心最终写入的 level/message/context。
             */
            public function channel(string|array|null $name = null): self
            {
                return $this;
            }

            private function add(string $level, string|Stringable $message, array $context): void
            {
                $message = (string) $message;
                $logKey = $message;

                // EventFailureLogger 会把结构化字段 JSON 拼在日志键后面（ThinkPHP 的 context
                // 只用于占位符替换，不会落盘）。这里把 JSON 解析回数组，方便断言字段。
                $separator = strpos($message, ' ');
                if ($separator !== false) {
                    $decoded = json_decode(substr($message, $separator + 1), true);
                    if (is_array($decoded)) {
                        $logKey = substr($message, 0, $separator);
                        $context = array_merge($context, $decoded);
                    }
                }

                $this->records[] = [
                    'level' => $level,
                    'message' => $logKey,
                    'context' => $context,
                ];
            }
        };

        $this->previousLogger = $this->app()->make('log');
        $this->app()->instance('log', $this->fakeLogger);
    }

    protected function tearDown(): void
    {
        if ($this->previousLogger !== null) {
            $this->app()->instance('log', $this->previousLogger);
            $this->previousLogger = null;
        }

        \Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1. 同步监听器抛异常 → 业务不中断 + 产生 error 日志
    // -----------------------------------------------------------------

    public function testSyncListenerFailureIsLoggedWithoutInterruptingBusiness(): void
    {
        $service = new EventService($this->silentMonitor());
        $service->listenWithPriority('er13.order.created', static function (): void {
            throw new \RuntimeException('listener boom');
        });

        $context = new EventContext('trace-sync', 100, 5, 42);
        $payload = $service->trigger('er13.order.created', ['id' => 1], $context);

        // 业务不中断：payload 原样返回
        $this->assertSame('er13.order.created', $payload->getEvent());
        $this->assertSame(['id' => 1], $payload->getData());

        $log = $this->findLog('event.listener.failed', 'er13.order.created');
        $this->assertNotNull($log, '监听器异常必须产生 error 日志，不能静默吞掉');
        $this->assertSame('error', $log['level'], '监听器崩溃属于 error，不是可忽略的降级');

        $contextData = $log['context'];
        $this->assertSame('er13.order.created', $contextData['event']);
        $this->assertSame('closure', $contextData['listener']);
        $this->assertSame('priority', $contextData['phase']);
        $this->assertNotEmpty($contextData['listener_key'], '闭包监听器也要有可区分的存储键');
        $this->assertSame(\RuntimeException::class, $contextData['exception']);
        $this->assertSame('listener boom', $contextData['message']);
        $this->assertSame('listener boom', $contextData['error'], '保留 error 字段兼容既有检索');
        $this->assertSame('sync', $contextData['mode']);
        $this->assertSame('trace-sync', $contextData['trace_id']);
        $this->assertSame(100, $contextData['tenant_id']);
        $this->assertSame(5, $contextData['site_id']);
        $this->assertSame(42, $contextData['user_id']);
    }

    // -----------------------------------------------------------------
    // 2. 严格模式开启 → 异常原样上抛
    // -----------------------------------------------------------------

    public function testFailFastModeRethrowsOriginalException(): void
    {
        // 显式覆盖，避免依赖本机 .env / 环境变量
        $service = new EventService($this->silentMonitor(), true);
        $boom = new \RuntimeException('strict mode boom');

        $service->listenWithPriority('er13.strict.event', static function () use ($boom): void {
            throw $boom;
        });

        try {
            $service->trigger('er13.strict.event');
            $this->fail('严格模式下监听器异常必须原样上抛');
        } catch (\RuntimeException $e) {
            $this->assertSame($boom, $e, '上抛的必须是监听器抛出的同一个异常实例，不能被包装/替换');
        }

        // 严格模式也先留日志：排障时既能看到现场，又能让调用方立刻失败
        $this->assertNotNull(
            $this->findLog('event.listener.failed', 'er13.strict.event'),
            '严格模式同样要记录失败日志'
        );
    }

    // -----------------------------------------------------------------
    // 3. 默认关闭 → 不抛（回归）
    // -----------------------------------------------------------------

    public function testFailFastIsDisabledByDefault(): void
    {
        $service = new EventService($this->silentMonitor());
        $this->assertFalse($service->isFailFastEnabled(), 'EVENT_FAIL_FAST 默认必须为 false');

        $service->listenWithPriority('er13.default.event', static function (): void {
            throw new \RuntimeException('default mode boom');
        });

        $payload = $service->trigger('er13.default.event');
        $this->assertSame('er13.default.event', $payload->getEvent());
    }

    /**
     * 严格模式也可以通过配置打开（EVENT_FAIL_FAST 最终落到 config/event.php 的 fail_fast）
     */
    public function testFailFastCanBeEnabledByConfig(): void
    {
        // ThinkPHP 的 Config::set 只接受「数组 + 段名」，不接受 a.b.c 点语法。
        // 先 get 一次触发生成完整 event 配置，避免 set 覆盖掉 monitor/dlq 段。
        Config::get('event.fail_fast', false);
        Config::set(['fail_fast' => true], 'event');

        try {
            $service = new EventService($this->silentMonitor());
            $this->assertTrue($service->isFailFastEnabled(), '配置里的 event.fail_fast 必须能被 EventService 读到');
        } finally {
            // 配置是进程级共享的（ThinkPHPTestCase 不重置 Config），必须还原
            Config::set(['fail_fast' => false], 'event');
        }
    }

    // -----------------------------------------------------------------
    // 4. 多个监听器：第一个崩、第二个仍执行（锁定现有语义）
    // -----------------------------------------------------------------

    public function testOtherListenersStillRunWhenOneFails(): void
    {
        $service = new EventService($this->silentMonitor());
        $order = [];

        $service->listenWithPriority('er13.multi.event', static function () use (&$order): void {
            $order[] = 'first';
            throw new \RuntimeException('first boom');
        }, 5);

        $service->listenWithPriority('er13.multi.event', static function () use (&$order): void {
            $order[] = 'second';
        }, 10);

        $service->listenWithPriority('er13.multi.event', static function () use (&$order): void {
            $order[] = 'third';
        }, 15);

        $payload = $service->trigger('er13.multi.event');

        $this->assertSame('er13.multi.event', $payload->getEvent());
        $this->assertSame(
            ['first', 'second', 'third'],
            $order,
            '一个监听器失败不能阻断同事件后续监听器（优先级顺序保持不变）'
        );
        $this->assertCount(1, $this->logsFor('event.listener.failed', 'er13.multi.event'));
    }

    // -----------------------------------------------------------------
    // 5. 多个监听器都失败 → 每个各记一条，且用 listener_key 区分
    // -----------------------------------------------------------------

    public function testEachFailingListenerGetsItsOwnLogEntry(): void
    {
        $service = new EventService($this->silentMonitor());
        $lastRan = false;

        $service->listenWithPriority('er13.multi.failure', static function (): void {
            throw new \RuntimeException('first boom');
        }, 5);

        $service->listenWithPriority('er13.multi.failure', static function (): void {
            throw new \LogicException('second boom');
        }, 10);

        $service->listenWithPriority('er13.multi.failure', static function () use (&$lastRan): void {
            $lastRan = true;
        }, 15);

        $service->trigger('er13.multi.failure');

        $this->assertTrue($lastRan, '两次失败后，第三个监听器仍必须执行');

        $logs = $this->logsFor('event.listener.failed', 'er13.multi.failure');
        $this->assertCount(2, $logs, '两个监听器失败必须产生两条日志，不能只记第一条');

        $exceptions = array_map(
            static fn (array $log): string => (string) $log['context']['exception'],
            $logs
        );
        $this->assertContains(\RuntimeException::class, $exceptions);
        $this->assertContains(\LogicException::class, $exceptions);

        $listenerKeys = array_map(
            static fn (array $log): string => (string) $log['context']['listener_key'],
            $logs
        );
        $this->assertCount(2, array_unique($listenerKeys), '闭包监听器也必须能用 listener_key 区分');
    }

    // -----------------------------------------------------------------
    // 6. ThinkPHP 事件系统分支的异常同样不得静默
    // -----------------------------------------------------------------

    public function testThinkPhpEventListenerFailureIsLoggedAndSwallowed(): void
    {
        $eventName = 'er13.thinkphp.event';

        EventFacade::listen($eventName, static function (): void {
            throw new \LogicException('thinkphp listener boom');
        });

        try {
            $service = new EventService($this->silentMonitor());
            $payload = $service->trigger($eventName, ['k' => 'v']);

            $this->assertSame($eventName, $payload->getEvent());
            $this->assertSame(['k' => 'v'], $payload->getData());
        } finally {
            // think\Event 的监听器是进程级共享的（ThinkPHPTestCase 不清理），必须显式移除
            EventFacade::remove($eventName);
        }

        $log = $this->findLog('event.listener.failed', $eventName);
        $this->assertNotNull($log, 'ThinkPHP 事件分支的异常原来被完全吞掉，现在必须留日志');
        $this->assertSame('error', $log['level']);
        $this->assertSame('thinkphp', $log['context']['phase']);
        $this->assertNull($log['context']['listener'], '拿不到具体监听器时显式记 null');
        $this->assertSame(\LogicException::class, $log['context']['exception']);
        $this->assertSame('thinkphp listener boom', $log['context']['message']);
        $this->assertSame('sync', $log['context']['mode']);
    }

    // -----------------------------------------------------------------
    // 7. 异步重试：结构化日志字段与同步一致
    // -----------------------------------------------------------------

    public function testAsyncEventJobRetryLogsStructuredFieldsAndReleases(): void
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('attempts')->once()->andReturn(1);
        $job->shouldReceive('release')->once()->with(10);
        $job->shouldReceive('delete')->never();

        $data = [
            'event' => 'er13.async.retry',
            'params' => ['id' => 7],
            'context' => [
                'trace_id' => 'trace-async-retry',
                'tenant_id' => 7,
                'site_id' => 2,
                'user_id' => 9,
            ],
            'service_class' => Er13AlwaysFailingEventService::class,
        ];

        $asyncJob = new AsyncEventJob($this->silentMonitor());
        $asyncJob->fire($job, $data);

        $log = $this->findLog('event.async.failed', 'er13.async.retry');
        $this->assertNotNull($log, '异步失败必须留日志');
        $this->assertSame('warning', $log['level'], '可重试的异步失败保持 warning；进入 DLQ 才升级为 error');

        $context = $log['context'];
        $this->assertSame('er13.async.retry', $context['event']);
        $this->assertSame('async', $context['mode']);
        $this->assertSame(1, $context['attempts']);
        $this->assertSame(\RuntimeException::class, $context['exception']);
        $this->assertSame('simulate async failure', $context['message']);
        $this->assertSame('trace-async-retry', $context['trace_id']);
        $this->assertSame(7, $context['tenant_id']);
        $this->assertSame(2, $context['site_id']);
        $this->assertSame(9, $context['user_id']);
    }

    /**
     * 关键联动：trigger 默认吞掉监听器异常后，异步任务仍必须让任务失败以触发重试
     *
     * 若 AsyncEventJob 只看 trigger 有没有抛异常，这里会误判成功并 delete 任务，
     * 结果是「监听器每次都没执行、但队列任务永远是成功的」—— 比静默更糟。
     */
    public function testAsyncJobRetriesWhenTriggerSwallowsListenerFailure(): void
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('attempts')->once()->andReturn(1);
        $job->shouldReceive('release')->once()->with(10);
        $job->shouldReceive('delete')->never();

        $data = [
            'event' => 'er13.async.listener.failure',
            'params' => [],
            'context' => ['trace_id' => 'trace-async-listener', 'tenant_id' => 8],
            'service_class' => Er13ListenerFailingEventService::class,
        ];

        $asyncJob = new AsyncEventJob($this->silentMonitor());
        $asyncJob->fire($job, $data);

        $listenerLog = $this->findLog('event.listener.failed', 'er13.async.listener.failure');
        $this->assertNotNull($listenerLog, '监听器失败要留下带 listener 标识的 error 日志');
        $this->assertSame(\RuntimeException::class, $listenerLog['context']['exception']);
        $this->assertSame('async listener boom', $listenerLog['context']['message']);
        $this->assertSame('async', $listenerLog['context']['mode'], '队列任务触发时应标注 mode=async');

        $asyncLog = $this->findLog('event.async.failed', 'er13.async.listener.failure');
        $this->assertNotNull(
            $asyncLog,
            'trigger 吞掉监听器异常后，AsyncEventJob 必须主动让任务失败，否则队列不会重试'
        );
        $this->assertSame('async', $asyncLog['context']['mode']);
        $this->assertSame(1, $asyncLog['context']['attempts']);
    }

    // -----------------------------------------------------------------
    // 8. 异步达到重试上限 → 写 DLQ + 删除任务
    // -----------------------------------------------------------------
    public function testAsyncEventJobWritesDeadLetterWhenAttemptsExceeded(): void
    {
        $monitor = new class () extends EventMonitor {
            /**
             * 捕获到的死信记录
             *
             * @var array<int, array<string, mixed>>
             */
            public array $deadLetters = [];

            public function recordDeadLetter(
                string $event,
                ?EventContext $context,
                \Throwable $e,
                int $attempts,
                int $code = 2001,
                mixed $payload = null
            ): void {
                $this->deadLetters[] = [
                    'event' => $event,
                    'context' => $context,
                    'exception' => $e,
                    'attempts' => $attempts,
                    'code' => $code,
                    'payload' => $payload,
                ];
            }
        };

        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('attempts')->once()->andReturn(4);
        $job->shouldReceive('delete')->once();
        $job->shouldReceive('release')->never();

        $data = [
            'event' => 'er13.async.dlq',
            'params' => ['id' => 1],
            'context' => [
                'trace_id' => 'trace-async-dlq',
                'tenant_id' => 11,
                'site_id' => 3,
                'user_id' => 13,
            ],
            'service_class' => Er13AlwaysFailingEventService::class,
        ];

        $asyncJob = new AsyncEventJob($monitor);
        $asyncJob->fire($job, $data);

        $this->assertCount(1, $monitor->deadLetters, '超过重试上限必须写死信');
        $deadLetter = $monitor->deadLetters[0];
        $this->assertSame('er13.async.dlq', $deadLetter['event']);
        $this->assertSame(4, $deadLetter['attempts']);
        $this->assertSame(2001, $deadLetter['code'], '非 2001/2002 的业务异常统一落 2001');
        $this->assertSame('simulate async failure', $deadLetter['exception']->getMessage());
        $this->assertSame('trace-async-dlq', $deadLetter['context']?->getTraceId());
        $this->assertSame('er13.async.dlq', $deadLetter['payload']['event']);
        $this->assertSame(['id' => 1], $deadLetter['payload']['params']);

        // 进入死信时同时保留一条 warning 日志（attempts 已是最终值）
        $log = $this->findLog('event.async.failed', 'er13.async.dlq');
        $this->assertNotNull($log);
        $this->assertSame(4, $log['context']['attempts']);
    }

    // -----------------------------------------------------------------
    // 9. DLQ 写失败 → 至少留日志，且任务仍被删除
    // -----------------------------------------------------------------

    public function testAsyncEventJobLogsWhenDeadLetterWriteFails(): void
    {
        $monitor = new class () extends EventMonitor {
            public function recordDeadLetter(
                string $event,
                ?EventContext $context,
                \Throwable $e,
                int $attempts,
                int $code = 2001,
                mixed $payload = null
            ): void {
                throw new \RuntimeException('dead letter table unavailable');
            }
        };

        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('attempts')->once()->andReturn(4);
        $job->shouldReceive('delete')->once();
        $job->shouldReceive('release')->never();

        $data = [
            'event' => 'er13.async.dlq.failure',
            'params' => ['id' => 2],
            'context' => ['trace_id' => 'trace-dlq-failure', 'tenant_id' => 1],
            'service_class' => Er13AlwaysFailingEventService::class,
        ];

        $asyncJob = new AsyncEventJob($monitor);
        $asyncJob->fire($job, $data);

        $log = $this->findLog('event.dlq.record_failed', 'er13.async.dlq.failure');
        $this->assertNotNull($log, 'DLQ 写失败必须至少留日志，不能「任务丢了 + 日志也没有」');
        $this->assertSame('error', $log['level']);
        $this->assertSame(\RuntimeException::class, $log['context']['exception']);
        $this->assertSame('dead letter table unavailable', $log['context']['message']);
        $this->assertSame(4, $log['context']['attempts']);
        $this->assertSame(\RuntimeException::class, $log['context']['original_exception']);
        $this->assertSame('simulate async failure', $log['context']['original_message']);
        $this->assertSame(['id' => 2], $log['context']['payload'], 'DLQ 没写成时，payload 至少留在日志里');
        $this->assertSame('trace-dlq-failure', $log['context']['trace_id']);
    }

    // -----------------------------------------------------------------
    // 10. 日志系统本身故障 → error_log() 兜底
    // -----------------------------------------------------------------

    public function testEventMonitorFallsBackToErrorLogWhenLoggerItselfFails(): void
    {
        // 换成「所有写入都抛异常」的 logger，模拟通道未配置 / 磁盘满 / 日志驱动故障
        $this->app()->instance('log', new class () {
            public function error(string|Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log backend down');
            }

            public function warning(string|Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log backend down');
            }

            public function info(string|Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log backend down');
            }

            public function debug(string|Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log backend down');
            }

            public function channel(string|array|null $name = null): self
            {
                return $this;
            }
        });

        $errorLogFile = tempnam(sys_get_temp_dir(), 'er13_error_log_');
        $this->assertNotFalse($errorLogFile, '无法创建临时 error_log 文件');

        $previousErrorLog = ini_set('error_log', $errorLogFile);

        try {
            // 指向不存在的 DLQ 表，强制走「落库不可用 → 写日志」的降级路径
            $monitor = new class () extends EventMonitor {
                protected string $dlqTable = 'event_dead_letters_er13_missing';
            };

            $monitor->recordDeadLetter(
                'er13.errorlog.event',
                new EventContext('trace-errorlog', 1, 1, 1),
                new \RuntimeException('original failure'),
                4,
                2001,
                ['a' => 1]
            );
        } finally {
            ini_set('error_log', $previousErrorLog === false ? '' : $previousErrorLog);
        }

        $content = (string) file_get_contents($errorLogFile);
        @unlink($errorLogFile);

        $this->assertStringContainsString('[event.failure] event.dlq', $content, 'Log 抛异常时必须降级 error_log');
        $this->assertStringContainsString('log_error=RuntimeException', $content);
        $this->assertStringContainsString('er13.errorlog.event', $content);
        $this->assertStringContainsString('original failure', $content);
    }

    // -----------------------------------------------------------------
    // 11. 监控自身抛异常 → 业务不中断（第二道防线）
    // -----------------------------------------------------------------

    public function testEventServiceSurvivesMonitorFailure(): void
    {
        $monitor = new class () extends EventMonitor {
            public function recordMetric(string $event, EventPayload $payload, float $durationMs, bool $failed): void
            {
                throw new \RuntimeException('monitor down');
            }
        };

        $service = new EventService($monitor);
        $service->listenWithPriority('er13.monitor.down', static function (): void {
            // 监听器本身正常
        });

        $payload = $service->trigger('er13.monitor.down');

        $this->assertSame('er13.monitor.down', $payload->getEvent());

        $log = $this->findLog('event.monitor.record_metric_failed', 'er13.monitor.down');
        $this->assertNotNull($log, '监控故障也要留痕，否则又变成静默失败');
        $this->assertSame('warning', $log['level']);
        $this->assertSame(\RuntimeException::class, $log['context']['exception']);
        $this->assertSame('monitor down', $log['context']['message']);
    }

    // -----------------------------------------------------------------
    // 辅助方法
    // -----------------------------------------------------------------

    /**
     * 静默监控器：本文件多数用例只关心监听器失败日志，屏蔽落库/告警噪音
     */
    private function silentMonitor(): EventMonitor
    {
        return new class () extends EventMonitor {
            public function recordMetric(string $event, EventPayload $payload, float $durationMs, bool $failed): void
            {
                // no-op
            }
        };
    }

    /**
     * 按日志键与事件名查找第一条日志
     *
     * @return array{level: string, message: string, context: array<string, mixed>}|null
     */
    private function findLog(string $key, ?string $event = null): ?array
    {
        return $this->logsFor($key, $event)[0] ?? null;
    }

    /**
     * 按日志键与事件名收集日志
     *
     * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    private function logsFor(string $key, ?string $event = null): array
    {
        $matched = [];

        foreach ($this->fakeLogger->records as $record) {
            if ($record['message'] !== $key) {
                continue;
            }
            if ($event !== null && ($record['context']['event'] ?? null) !== $event) {
                continue;
            }
            $matched[] = $record;
        }

        return $matched;
    }
}

/**
 * 测试用异步失败服务：触发即抛异常，用于验证重试 / DLQ 路径。
 */
class Er13AlwaysFailingEventService extends EventService
{
    public function trigger(string $event, $params = null, ?EventContext $context = null): EventPayload
    {
        throw new \RuntimeException('simulate async failure');
    }
}

/**
 * 测试用「监听器失败」服务：父类 trigger 会吞掉异常并正常返回，用于验证
 * AsyncEventJob 能识别 payload 上的失败记录并让任务重试。
 */
class Er13ListenerFailingEventService extends EventService
{
    public function __construct()
    {
        parent::__construct(new class () extends EventMonitor {
            public function recordMetric(string $event, EventPayload $payload, float $durationMs, bool $failed): void
            {
                // no-op
            }
        });
    }

    public function trigger(string $event, $params = null, ?EventContext $context = null): EventPayload
    {
        $this->listenWithPriority($event, static function (): void {
            throw new \RuntimeException('async listener boom');
        });

        return parent::trigger($event, $params, $context);
    }
}
