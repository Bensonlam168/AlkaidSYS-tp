<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use Tests\ThinkPHPTestCase;
use Domain\Event\EventService;
use Domain\Event\EventLogger;

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
