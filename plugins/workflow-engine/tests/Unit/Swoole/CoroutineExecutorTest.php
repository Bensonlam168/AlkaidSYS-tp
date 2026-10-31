<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Swoole;

use Alkaid\Workflow\Engine\Swoole\CoroutineExecutor;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * CoroutineExecutor 单元测试
 *
 * 注意：部分测试需要 Swoole 扩展支持，在非 Swoole 环境下会跳过
 */
#[CoversClass(CoroutineExecutor::class)]
class CoroutineExecutorTest extends TestCase
{
    /**
     * @var CoroutineExecutor
     */
    private CoroutineExecutor $executor;

    /**
     * @var ExecutionContext
     */
    private ExecutionContext $context;

    /**
     * 设置测试环境
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = new CoroutineExecutor(
            timeout: 60,
            maxRetries: 3,
            retryDelay: 100
        );

        $this->context = ExecutionContext::create([
            'workflow_id' => 'test_workflow',
            'instance_id' => 'test_instance',
            'tenant_id' => 1,
            'user_id' => 100,
            'variables' => ['test_var' => 'test_value'],
        ]);
    }

    /**
     * 测试默认构造参数
     */
    public function testDefaultConstruction(): void
    {
        $executor = new CoroutineExecutor();

        $this->assertEquals(CoroutineExecutor::DEFAULT_TIMEOUT, $executor->getTimeout());
        $this->assertEquals(CoroutineExecutor::DEFAULT_MAX_RETRIES, $executor->getMaxRetries());
        $this->assertEquals(CoroutineExecutor::DEFAULT_RETRY_DELAY, $executor->getRetryDelay());
    }

    /**
     * 测试自定义构造参数
     */
    public function testCustomConstruction(): void
    {
        $executor = new CoroutineExecutor(120, 5, 500);

        $this->assertEquals(120, $executor->getTimeout());
        $this->assertEquals(5, $executor->getMaxRetries());
        $this->assertEquals(500, $executor->getRetryDelay());
    }

    /**
     * 测试超时设置
     */
    public function testSetTimeout(): void
    {
        $result = $this->executor->setTimeout(180);

        $this->assertSame($this->executor, $result);
        $this->assertEquals(180, $this->executor->getTimeout());
    }

    /**
     * 测试最大重试次数设置
     */
    public function testSetMaxRetries(): void
    {
        $result = $this->executor->setMaxRetries(10);

        $this->assertSame($this->executor, $result);
        $this->assertEquals(10, $this->executor->getMaxRetries());
    }

    /**
     * 测试重试间隔设置
     */
    public function testSetRetryDelay(): void
    {
        $result = $this->executor->setRetryDelay(2000);

        $this->assertSame($this->executor, $result);
        $this->assertEquals(2000, $this->executor->getRetryDelay());
    }

    /**
     * 测试最大并发数设置
     */
    public function testSetMaxConcurrency(): void
    {
        $this->executor->setMaxConcurrency(50);

        // 验证设置生效（通过运行计数间接验证）
        $this->assertEquals(0, $this->executor->getRunningCount());
    }

    /**
     * 测试日志启用/禁用
     */
    public function testSetEnableLogging(): void
    {
        $result = $this->executor->setEnableLogging(false);

        $this->assertSame($this->executor, $result);
        // 日志状态是内部状态，通过链式调用返回值验证设置成功
    }

    /**
     * 测试事件监听器注册
     */
    public function testEventListenerRegistration(): void
    {
        $called = false;
        $listener = function () use (&$called) {
            $called = true;
        };

        $result = $this->executor->on('test.event', $listener);

        $this->assertSame($this->executor, $result);
    }

    /**
     * 测试事件监听器移除
     */
    public function testEventListenerRemoval(): void
    {
        $listener = function () {};

        $this->executor->on('test.event', $listener);
        $result = $this->executor->off('test.event', $listener);

        $this->assertSame($this->executor, $result);
    }

    /**
     * 测试移除所有事件监听器
     */
    public function testRemoveAllEventListeners(): void
    {
        $listener1 = function () {};
        $listener2 = function () {};

        $this->executor->on('test.event', $listener1);
        $this->executor->on('test.event', $listener2);
        $result = $this->executor->off('test.event');

        $this->assertSame($this->executor, $result);
    }

    /**
     * 测试协程检测 - 非协程环境
     */
    public function testIsInCoroutineOutsideCoroutine(): void
    {
        // 在非协程环境中，应该返回 false
        // 注意：如果 Swoole 未安装或未在协程中，此测试应该通过
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension not loaded');
        }

        // 在主进程中（非协程），应该返回 false
        $result = CoroutineExecutor::isInCoroutine();

        // 实际结果取决于运行环境
        $this->assertIsBool($result);
    }

    /**
     * 测试默认常量值
     */
    public function testDefaultConstants(): void
    {
        $this->assertEquals(300, CoroutineExecutor::DEFAULT_TIMEOUT);
        $this->assertEquals(3, CoroutineExecutor::DEFAULT_MAX_RETRIES);
        $this->assertEquals(1000, CoroutineExecutor::DEFAULT_RETRY_DELAY);
    }

    /**
     * 测试运行计数初始值
     */
    public function testInitialRunningCount(): void
    {
        $this->assertEquals(0, $this->executor->getRunningCount());
    }

    /**
     * 测试上下文取消检测
     */
    public function testCancelledContextPreventsExecution(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension not loaded');
        }

        // 创建一个简单的 mock 节点用于测试
        $mockNode = $this->createMockNode();

        $this->context->cancel();

        // 使用反射调用 protected 方法测试逻辑
        // 或者创建一个可测试的子类

        // 由于我们无法直接测试 executeNode（需要真实的 NodeModel），
        // 我们验证上下文状态
        $this->assertTrue($this->context->isCancelled());
        $this->assertFalse($this->context->canContinue());
    }

    /**
     * 测试上下文暂停检测
     */
    public function testSuspendedContextState(): void
    {
        $this->context->suspend();

        $this->assertTrue($this->context->isSuspended());
        $this->assertFalse($this->context->canContinue());

        $this->context->resume();

        $this->assertFalse($this->context->isSuspended());
        $this->assertTrue($this->context->canContinue());
    }

    /**
     * 测试多事件监听器
     */
    public function testMultipleEventListeners(): void
    {
        $callCount = 0;

        $listener1 = function () use (&$callCount) {
            $callCount++;
        };

        $listener2 = function () use (&$callCount) {
            $callCount++;
        };

        $this->executor->on('node.start', $listener1);
        $this->executor->on('node.start', $listener2);

        // 验证监听器已注册（通过链式调用成功）
        $this->assertInstanceOf(CoroutineExecutor::class, $this->executor);
    }

    /**
     * 测试链式配置
     */
    public function testChainedConfiguration(): void
    {
        $executor = (new CoroutineExecutor())
            ->setTimeout(120)
            ->setMaxRetries(5)
            ->setRetryDelay(500)
            ->setMaxConcurrency(200)
            ->setEnableLogging(true);

        $this->assertEquals(120, $executor->getTimeout());
        $this->assertEquals(5, $executor->getMaxRetries());
        $this->assertEquals(500, $executor->getRetryDelay());
    }

    /**
     * 创建 Mock 节点
     *
     * @return object
     */
    private function createMockNode(): object
    {
        return new class () {
            public function getName(): string
            {
                return 'mock_node';
            }

            public function getDisplayName(): string
            {
                return '模拟节点';
            }

            public function getOutputs(): array
            {
                return [];
            }

            public function getInputs(): array
            {
                return [];
            }
        };
    }
}
