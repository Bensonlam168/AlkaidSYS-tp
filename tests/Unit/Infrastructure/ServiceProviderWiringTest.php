<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use app\AppService;
use Infrastructure\Application\ApplicationManager;
use Infrastructure\Application\ApplicationServiceProvider;
use Infrastructure\DI\AbstractServiceProvider;
use Infrastructure\DI\ServiceProviderManager;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\LogManager;

/**
 * 服务提供者接线测试（收尾 A5）
 *
 * 覆盖三条契约：
 * 1. `AbstractServiceProvider::singleton()` 绑定的闭包可被容器 `make()` 解析
 *    （闭包形参必须带 `think\App` 类型，否则 ThinkPHP 的 `bindParams()` 会抛
 *      `InvalidArgumentException: method param miss:app`）；
 * 2. 完成 provider 注册 + 延迟加载后，`ApplicationManager` 可从容器解析，
 *    且保持单例语义、`application.manager` 别名可用；
 * 3. `ServiceProviderManager` 中确实登记了该延迟提供者，`loadDeferred()` 能加载它。
 *
 * 说明：本测试只使用未初始化的真实容器（`new App()`），不依赖数据库、缓存与日志驱动。
 */
class ServiceProviderWiringTest extends TestCase
{
    /**
     * 真实容器实例（未 initialize，仅提供 PSR-4 自动加载与容器绑定能力）
     */
    private App $app;

    /**
     * 进入本测试前的全局容器实例（用于还原，避免污染其他用例）
     */
    private mixed $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        // `new App()` 会把自身写为框架的全局静态容器实例，门面（Facade）随后都会从它解析。
        // 这里先记录原实例，tearDown 再还原，避免影响其它测试用例。
        $this->previousContainer = Container::getInstance();

        // 根目录指向项目根，使 provider 计算 addons/apps 路径时与真实请求一致
        $this->app = new App(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);
    }

    protected function tearDown(): void
    {
        // 还原全局容器实例，防止测试间状态污染
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    /**
     * 契约 1：`singleton()` 形态的闭包绑定必须能被 `make()` 解析
     *
     * 修复前：`AbstractServiceProvider::singleton()` 内部包装闭包写作
     * `function ($app) use ($concrete)`，缺少 `App` 类型声明，导致
     * `Container::bindParams()` 无法通过依赖注入补参 → `method param miss:app`。
     */
    public function testProviderSingletonBindingIsResolvable(): void
    {
        $provider = new class () extends AbstractServiceProvider {
            public function register(App $app): void
            {
                // 与 ApplicationServiceProvider::register() 完全相同的写法：
                // 工厂闭包形参带 App 类型
                $this->singleton($app, ServiceProviderWiringDemoService::class, function (App $app) {
                    return new ServiceProviderWiringDemoService($app->getRootPath());
                });
            }
        };

        $provider->register($this->app);

        // 修复前此处抛 InvalidArgumentException: method param miss:app
        $service = $this->app->make(ServiceProviderWiringDemoService::class);

        $this->assertInstanceOf(ServiceProviderWiringDemoService::class, $service);
        $this->assertSame($this->app->getRootPath(), $service->marker, '工厂闭包应收到当前容器实例');

        // 单例语义：重复解析返回同一实例
        $this->assertSame($service, $this->app->make(ServiceProviderWiringDemoService::class));
    }

    /**
     * 契约 2：注册 + 延迟加载后 ApplicationManager 可解析
     *
     * 等价于 `app\AppService::boot()` 中追加的接线序列，便于在单元测试里直接驱动。
     * 修复前：`loadDeferred()` 触发的 `register()` 内部绑定即失败，且容器中
     * 既无 `ApplicationManager` 绑定也无 `application.manager` 别名。
     */
    public function testApplicationManagerResolvableAfterBoot(): void
    {
        $providerManager = $this->wireApplicationProvider($this->app);

        $this->assertTrue(
            $providerManager->isLoaded(ApplicationServiceProvider::class),
            'loadDeferred() 后延迟提供者应被标记为已加载'
        );

        // 修复前：make() 直接抛错（未绑定 + 构造依赖 root_path() 助手）
        $applicationManager = $this->app->make(ApplicationManager::class);

        $this->assertInstanceOf(ApplicationManager::class, $applicationManager);
        $this->assertSame(
            $this->app->getRootPath() . 'addons/apps',
            $applicationManager->getBasePath(),
            'provider 应把 addons/apps 作为应用基础路径注入'
        );

        // 单例语义：provider 绑定的是单例
        $this->assertSame(
            $applicationManager,
            $this->app->make(ApplicationManager::class),
            'ApplicationManager 应为容器内单例'
        );

        // 别名可用（ApplicationServiceProvider::register() 中声明）
        $this->assertInstanceOf(ApplicationManager::class, $this->app->make('application.manager'));
    }

    /**
     * 契约 3：provider 已登记在 ServiceProviderManager 中并可被延迟加载
     */
    public function testApplicationServiceProviderRegisteredInProviderManager(): void
    {
        $providerManager = new ServiceProviderManager($this->app);
        $providerManager->register(ApplicationServiceProvider::class);

        // 延迟提供者：注册时只登记映射，不立即执行 register()
        $this->assertTrue($providerManager->isDeferredService(ApplicationManager::class));
        $this->assertTrue($providerManager->isDeferredService('application.manager'));
        $this->assertFalse($providerManager->isLoaded(ApplicationServiceProvider::class));

        // 修复前：加载时绑定失败，直接抛 method param miss:app
        $this->assertTrue($providerManager->loadDeferred(ApplicationManager::class));
        $this->assertTrue($providerManager->isLoaded(ApplicationServiceProvider::class));

        // 未登记的延迟服务应安全返回 false（不抛异常）
        $this->assertFalse($providerManager->loadDeferred('not.exists.service'));
    }

    /**
     * 契约 4：`app\AppService::boot()` 真实接线（含异常兜底不抛出）
     *
     * 注：`AppService::boot()` 先执行插件加载（PluginManager），插件包表不可用时会
     * 调用日志门面降级记录；这里用桩替换日志管理器，避免单元测试依赖真实日志驱动。
     */
    public function testAppServiceBootRegistersApplicationProvider(): void
    {
        $this->app->instance(LogManager::class, new class () {
            public function info(...$args): void
            {
            }

            public function warning(...$args): void
            {
            }

            public function error(...$args): void
            {
            }

            public function debug(...$args): void
            {
            }
        });

        // 修复前：boot() 不会抛错（尚无接线代码），但容器中没有 provider 管理器
        (new AppService($this->app))->boot();

        $providerManager = $this->app->make(ServiceProviderManager::class);

        $this->assertInstanceOf(ServiceProviderManager::class, $providerManager);
        $this->assertTrue(
            $providerManager->isLoaded(ApplicationServiceProvider::class),
            'AppService::boot() 应完成 ApplicationServiceProvider 的注册与延迟加载'
        );
        $this->assertInstanceOf(ApplicationManager::class, $this->app->make(ApplicationManager::class));
    }

    /**
     * 复刻 `app\AppService::boot()` 中的 provider 接线序列
     *
     * 保持与生产代码一致的调用顺序：
     * register() 登记延迟映射 → loadDeferred() 触发 register()/boot() → 回填 Manager 实例。
     */
    private function wireApplicationProvider(App $app): ServiceProviderManager
    {
        $providerManager = new ServiceProviderManager($app);
        $providerManager->register(ApplicationServiceProvider::class);
        $providerManager->loadDeferred(ApplicationManager::class);
        $app->instance(ServiceProviderManager::class, $providerManager);

        return $providerManager;
    }
}

/**
 * 测试用演示服务：用于验证 `singleton()` 闭包绑定是否可解析
 */
class ServiceProviderWiringDemoService
{
    public function __construct(public readonly string $marker = '')
    {
    }
}
