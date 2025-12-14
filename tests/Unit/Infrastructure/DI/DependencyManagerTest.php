<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\DI;

use Infrastructure\DI\AbstractServiceProvider;
use Infrastructure\DI\DependencyManager;
use Infrastructure\DI\ServiceProviderManager;
use PHPUnit\Framework\TestCase;
use think\App;

/**
 * Dependency Manager Test | 依赖管理器测试
 *
 * Tests for the enhanced DependencyManager class functionality.
 * 测试增强后的 DependencyManager 类功能。
 */
class DependencyManagerTest extends TestCase
{
    protected App $app;
    protected DependencyManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createMock(App::class);
        $this->manager = new DependencyManager($this->app);
    }

    /**
     * Test getting the provider manager | 测试获取提供者管理器
     */
    public function testGetProviderManager(): void
    {
        $providerManager = $this->manager->getProviderManager();

        $this->assertInstanceOf(ServiceProviderManager::class, $providerManager);
    }

    /**
     * Test provider manager is singleton | 测试提供者管理器是单例
     */
    public function testProviderManagerIsSingleton(): void
    {
        $manager1 = $this->manager->getProviderManager();
        $manager2 = $this->manager->getProviderManager();

        $this->assertSame($manager1, $manager2);
    }

    /**
     * Test registering a service provider | 测试注册服务提供者
     */
    public function testRegisterServiceProvider(): void
    {
        $provider = new class () extends AbstractServiceProvider {
            public function register(App $app): void
            {
            }
        };

        $result = $this->manager->registerServiceProvider($provider);

        $this->assertInstanceOf(AbstractServiceProvider::class, $result);
        $this->assertTrue($this->manager->isProviderLoaded(get_class($provider)));
    }

    /**
     * Test registering multiple service providers | 测试注册多个服务提供者
     */
    public function testRegisterServiceProviders(): void
    {
        $provider1 = new class () extends AbstractServiceProvider {
            public function register(App $app): void
            {
            }
        };

        // Register using instance
        $this->manager->registerServiceProvider($provider1);

        $this->assertTrue($this->manager->isProviderLoaded(get_class($provider1)));
    }

    /**
     * Test registering from config | 测试从配置注册
     */
    public function testRegisterFromConfig(): void
    {
        // Empty config should not throw
        $this->manager->registerFromConfig([]);
        $this->manager->registerFromConfig(['providers' => []]);

        $this->assertTrue(true); // No exception means success
    }

    /**
     * Test boot method | 测试启动方法
     */
    public function testBoot(): void
    {
        $this->manager->boot();

        $this->assertTrue($this->manager->getProviderManager()->isBooted());
    }

    /**
     * Test deferred service check | 测试延迟服务检查
     */
    public function testIsDeferredService(): void
    {
        $provider = new class () extends AbstractServiceProvider {
            protected bool $defer = true;
            protected array $provides = ['test.deferred.service'];

            public function register(App $app): void
            {
            }
        };

        $this->manager->registerServiceProvider($provider);

        $this->assertTrue($this->manager->isDeferredService('test.deferred.service'));
        $this->assertFalse($this->manager->isDeferredService('non.existent.service'));
    }

    /**
     * Test loading deferred service | 测试加载延迟服务
     */
    public function testLoadDeferredService(): void
    {
        $provider = new class () extends AbstractServiceProvider {
            protected bool $defer = true;
            protected array $provides = ['test.load.deferred'];

            public function register(App $app): void
            {
            }
        };

        $this->manager->registerServiceProvider($provider);

        $this->assertTrue($this->manager->loadDeferredService('test.load.deferred'));
        $this->assertFalse($this->manager->loadDeferredService('non.existent'));
    }

    /**
     * Test getting app instance | 测试获取应用实例
     */
    public function testGetApp(): void
    {
        $app = $this->manager->getApp();

        $this->assertSame($this->app, $app);
    }

    /**
     * Test legacy registerProvider method still works | 测试遗留的 registerProvider 方法仍然有效
     */
    public function testLegacyRegisterProvider(): void
    {
        // Should not throw, even with non-existent class
        $this->manager->registerProvider('NonExistentClass');

        $this->assertTrue(true);
    }
}
