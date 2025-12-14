<?php

declare(strict_types=1);

namespace Tests\Traits;

use think\App;

/**
 * Mock Container Trait | Mock 容器管理 Trait
 *
 * Provides unified mock binding and cleanup for ThinkPHP container.
 * Prevents Mockery binding leakage across test classes when using static App instances.
 *
 * 为 ThinkPHP 容器提供统一的 Mock 绑定和清理机制。
 * 防止在使用静态 App 实例时，Mockery 绑定跨测试类泄露。
 *
 * @package Tests\Traits
 */
trait MockContainerTrait
{
    /**
     * Track bound mock abstracts for cleanup | 追踪已绑定的 Mock 抽象类以便清理
     *
     * @var array<string>
     */
    protected array $boundMockAbstracts = [];

    /**
     * Bind a mock instance to the container and track it for cleanup
     * 将 Mock 实例绑定到容器并追踪以便后续清理
     *
     * @param string $abstract The abstract class/interface name | 抽象类/接口名称
     * @param object $mock The mock instance | Mock 实例
     * @return void
     */
    protected function bindMock(string $abstract, object $mock): void
    {
        $this->getContainerApp()->bind($abstract, static fn () => $mock);
        $this->boundMockAbstracts[] = $abstract;
    }

    /**
     * Bind a mock instance directly (singleton-like) | 直接绑定 Mock 实例（类似单例）
     *
     * @param string $abstract The abstract class/interface name | 抽象类/接口名称
     * @param object $mock The mock instance | Mock 实例
     * @return void
     */
    protected function bindMockInstance(string $abstract, object $mock): void
    {
        $this->getContainerApp()->instance($abstract, $mock);
        $this->boundMockAbstracts[] = $abstract;
    }

    /**
     * Cleanup all bound mocks from the container | 从容器中清理所有已绑定的 Mock
     *
     * Should be called in tearDown() after Mockery::close()
     * 应在 tearDown() 中调用 Mockery::close() 后调用
     *
     * @return void
     */
    protected function cleanupMocks(): void
    {
        $app = $this->getContainerApp();

        foreach ($this->boundMockAbstracts as $abstract) {
            // Remove the binding by unbinding it
            // ThinkPHP 的 Container 没有直接的 unbind 方法，但可以通过重新绑定为 null 或原类来清理
            // 这里我们通过检查是否存在并尝试删除绑定
            if ($app->has($abstract)) {
                // 重新绑定为类自身（如果是类）或删除绑定
                try {
                    // 尝试将绑定重置为类本身
                    if (class_exists($abstract) || interface_exists($abstract)) {
                        $app->bind($abstract, $abstract);
                    }
                } catch (\Throwable $e) {
                    // 忽略清理错误，容器可能已在其他地方被重置
                }
            }
        }

        $this->boundMockAbstracts = [];
    }

    /**
     * Get the ThinkPHP App instance | 获取 ThinkPHP App 实例
     *
     * This method should be implemented by the test class or inherited from ThinkPHPTestCase
     * 此方法应由测试类实现或从 ThinkPHPTestCase 继承
     *
     * @return App
     */
    abstract protected function getContainerApp(): App;
}
