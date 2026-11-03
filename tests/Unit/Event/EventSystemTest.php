<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use Tests\ThinkPHPTestCase;
use Domain\Event\EventService;
use Domain\Event\EventLogger;
use Domain\Event\EventContext;
use Domain\Event\EventResult;
use Domain\Event\AsyncEventJob;
use Domain\Event\EventPayload;

/**
 * Event System Unit Tests | 事件系统单元测试
 */
class EventSystemTest extends ThinkPHPTestCase
{
    protected EventService $eventService;
    protected EventLogger $eventLogger;
    protected array $executionOrder = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventService = new EventService();
        $this->eventLogger = new EventLogger();
        $this->executionOrder = [];
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Test event listener registration | 测试事件监听器注册
     */
    public function testEventListenerRegistration()
    {
        $called = false;

        $this->eventService->listenWithPriority('test.event', function () use (&$called) {
            $called = true;
        });

        $this->eventService->trigger('test.event');

        $this->assertTrue($called);
    }

    /**
     * Test event priority execution | 测试事件优先级执行
     */
    public function testEventPriorityExecution()
    {
        $order = [];

        $this->eventService->listenWithPriority('priority.test', function () use (&$order) {
            $order[] = 'second';
        }, 10);

        $this->eventService->listenWithPriority('priority.test', function () use (&$order) {
            $order[] = 'first';
        }, 5); // Higher priority (lower number)

        $this->eventService->listenWithPriority('priority.test', function () use (&$order) {
            $order[] = 'third';
        }, 15);

        $this->eventService->trigger('priority.test');

        $this->assertEquals(['first', 'second', 'third'], $order);
    }

    /**
     * Test event with parameters | 测试带参数的事件
     */
    public function testEventWithParameters()
    {
        $receivedParams = null;

        $this->eventService->listenWithPriority('param.test', function ($params) use (&$receivedParams) {
            $receivedParams = $params;
        });

        $params = ['key' => 'value', 'number' => 123];
        $this->eventService->trigger('param.test', $params);

        $this->assertEquals($params, $receivedParams);
    }

    /**
     * Test EventLogger logs trigger | 测试EventLogger记录触发
     */
    public function testEventLoggerLogsTrigger()
    {
        // This test would require database access
        // Marking as incomplete for now
        $this->markTestIncomplete('Requires database connection');

        // $this->eventLogger->logTrigger('test.event', ['param' => 'value']);
        // Verify insertion in database
    }

    /**
     * Test EventLogger logs success | 测试EventLogger记录成功
     */
    public function testEventLoggerLogsSuccess()
    {
        $this->markTestIncomplete('Requires database connection');

        // $this->eventLogger->logSuccess('test.event', 'TestListener');
        // Verify insertion in database
    }

    /**
     * Test EventLogger logs failure | 测试EventLogger记录失败
     */
    public function testEventLoggerLogsFailure()
    {
        $this->markTestIncomplete('Requires database connection');

        // $this->eventLogger->logFailure('test.event', 'TestListener', 'Error message');
        // Verify insertion in database
    }

    /**
     * Test multiple listeners on same event | 测试同一事件的多个监听器
     */
    public function testMultipleListenersOnSameEvent()
    {
        $count = 0;

        $this->eventService->listenWithPriority('multi.test', function () use (&$count) {
            $count++;
        });

        $this->eventService->listenWithPriority('multi.test', function () use (&$count) {
            $count++;
        });

        $this->eventService->listenWithPriority('multi.test', function () use (&$count) {
            $count++;
        });

        $this->eventService->trigger('multi.test');

        $this->assertEquals(3, $count);
    }

    public function testStopPropagationRespectsPriorityAndReturnFlag(): void
    {
        $order = [];

        $this->eventService->listenWithPriority('stop.test', function () use (&$order) {
            $order[] = 'first';
            return EventResult::stop();
        }, 5);

        $this->eventService->listenWithPriority('stop.test', function () use (&$order) {
            $order[] = 'second';
        }, 10);

        $payload = $this->eventService->trigger('stop.test', ['foo' => 'bar']);

        $this->assertEquals(['first'], $order);
        $this->assertTrue($payload->isPropagationStopped());
    }

    public function testContextPropagationProvidesTraceAndTenant(): void
    {
        $context = new EventContext('trace-123', 100, 5, 42, ['role' => 'admin']);
        $received = [];

        $this->eventService->listenWithPriority('context.test', function ($data, $payload) use (&$received) {
            $received['data'] = $data;
            $ctx = $payload->getContext();
            $received['trace_id'] = $ctx->getTraceId();
            $received['tenant_id'] = $ctx->getTenantId();
            $received['site_id'] = $ctx->getSiteId();
            $received['user_id'] = $ctx->getUserId();
            $received['extra'] = $ctx->getExtra();
        });

        $params = ['hello' => 'world'];
        $this->eventService->trigger('context.test', $params, $context);

        $this->assertSame($params, $received['data']);
        $this->assertSame('trace-123', $received['trace_id']);
        $this->assertSame(100, $received['tenant_id']);
        $this->assertSame(5, $received['site_id']);
        $this->assertSame(42, $received['user_id']);
        $this->assertSame(['role' => 'admin'], $received['extra']);
    }

    public function testTriggerAsyncPushesContextToQueue(): void
    {
        $context = new EventContext('trace-async', 7, 2, 9);
        $mockQueue = \Mockery::mock();
        // 绑定队列到容器，避免 Facade 找不到 'queue' 服务
        $this->app()->bind('queue', $mockQueue);

        $mockQueue->shouldReceive('push')
            ->once()
            ->with(
                \Domain\Event\AsyncEventJob::class,
                \Mockery::on(function ($data) use ($context) {
                    return $data['event'] === 'async.queue'
                        && $data['params'] === ['foo' => 'bar']
                        && $data['context']['trace_id'] === $context->getTraceId()
                        && $data['context']['tenant_id'] === $context->getTenantId()
                        && $data['context']['site_id'] === $context->getSiteId()
                        && $data['context']['user_id'] === $context->getUserId();
                }),
                'default'
            );

        $this->eventService->triggerAsync('async.queue', ['foo' => 'bar'], $context);

        $this->assertTrue(true); // 触发 Mockery 断言，避免 Risky
    }

    public function testAsyncCoroutineUsesCustomRunner(): void
    {
        $context = new EventContext('trace-co', 1, 1, 1);
        $ran = false;
        $runner = function (callable $fn) use (&$ran) {
            $ran = true;
            $fn(); // 同步执行以便测试覆盖
        };

        $received = [];
        $this->eventService->listenWithPriority('co.event', function ($data, $payload) use (&$received) {
            $received = [
                'data' => $data,
                'trace_id' => $payload->getContext()->getTraceId(),
            ];
        });

        $this->eventService->triggerAsyncCoroutine('co.event', ['hello' => 'co'], $context, $runner);

        $this->assertTrue($ran);
        $this->assertSame(['hello' => 'co'], $received['data']);
        $this->assertSame('trace-co', $received['trace_id']);
    }

    public function testAsyncEventJobRetriesAndStops(): void
    {
        $job = \Mockery::mock(\think\queue\Job::class);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release')->once()->with(10);
        $job->shouldReceive('delete')->never();

        $data = [
            'event' => 'failed.event',
            'params' => [],
            'context' => ['trace_id' => 'trace-fail'],
            'service_class' => FailedEventServiceStub::class,
        ];

        $asyncJob = new AsyncEventJob();
        $asyncJob->fire($job, $data);

        $this->assertTrue(true); // Mockery 断言
    }

    public function testAsyncEventJobDeletesWhenMaxAttemptsExceeded(): void
    {
        $job = \Mockery::mock(\think\queue\Job::class);
        $job->shouldReceive('attempts')->andReturn(4);
        $job->shouldReceive('delete')->once();
        $job->shouldReceive('release')->never();

        $data = [
            'event' => 'failed.event',
            'params' => [],
            'context' => ['trace_id' => 'trace-fail'],
            'service_class' => FailedEventServiceStub::class,
        ];

        $asyncJob = new AsyncEventJob();
        $asyncJob->fire($job, $data);

        $this->assertTrue(true); // Mockery 断言
    }

    public function testDeadLetterLoggedWhenExceeded(): void
    {
        $job = \Mockery::mock(\think\queue\Job::class);
        $job->shouldReceive('attempts')->andReturn(5);
        $job->shouldReceive('delete')->once();
        $job->shouldReceive('release')->never();

        // 捕获日志通过 Mockery facade 替代，这里仅断言代码路径
        $data = [
            'event' => 'failed.event',
            'params' => [],
            'context' => ['trace_id' => 'trace-dlq'],
            'service_class' => FailedEventServiceStub::class,
        ];

        $asyncJob = new AsyncEventJob();
        $asyncJob->fire($job, $data);

        $this->assertTrue(true); // 路径触发后删除
    }

    /**
     * Test getListenerKey for different listener types | 测试不同监听器类型的键生成
     */
    public function testGetListenerKey()
    {
        // Test with closure | 测试闭包
        $closureListener = function () {};
        $this->eventService->listenWithPriority('test', $closureListener);

        // Test with array listener | 测试数组监听器
        $arrayListener = [$this, 'testGetListenerKey'];
        $this->eventService->listenWithPriority('test2', $arrayListener);

        // If no exception, test passes | 如果没有异常，测试通过
        $this->assertTrue(true);
    }
}

/**
 * 测试用失败服务，触发时抛出异常以验证重试逻辑。
 */
class FailedEventServiceStub extends EventService
{
    public function trigger(string $event, $params = null, ?EventContext $context = null): EventPayload
    {
        throw new \RuntimeException('simulate failure');
    }
}
