<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use Infrastructure\Application\ApplicationManager;
use Infrastructure\Application\ApplicationServiceProvider;
use Infrastructure\Plugin\PluginManager;
use Tests\ThinkPHPTestCase;

/**
 * 插件自动加载与应用服务提供者注册测试（缺陷 A5）
 *
 * 覆盖三条契约：
 * 1. `plugin\` 命名空间可被 Composer PSR-4 自动加载（依赖 composer.json 的 autoload.psr-4 映射）；
 * 2. `PluginManager::register()` 可注册 `plugin\` 命名空间下的插件类；
 * 3. `ApplicationServiceProvider` 已在 `app/service.php` 中注册，且容器可解析 `ApplicationManager`。
 *
 * 对应夹具：addons/plugins/_fixture/Fixture.php（长期测试资产）
 */
class PluginAutoloadTest extends ThinkPHPTestCase
{
    /**
     * 契约 1：`plugin\` 命名空间可被自动加载
     *
     * 说明：`::class` 是编译期解析、不会触发自动加载，因此这里必须用 `class_exists()`
     * 才能真正验证 composer 的 PSR-4 映射是否生效。
     */
    public function testPluginNamespaceIsAutoloadable(): void
    {
        $this->assertTrue(
            class_exists('plugin\\_fixture\\Fixture'),
            'plugin\\_fixture\\Fixture 无法自动加载：composer.json 缺少 "plugin\\\\": "addons/plugins/" 的 PSR-4 映射'
        );

        $fixture = new \plugin\_fixture\Fixture('_fixture', ['version' => '0.0.0']);

        $this->assertSame(
            \plugin\_fixture\Fixture::FIXED_MESSAGE,
            $fixture->ping(),
            '夹具插件实例方法应返回固定字符串'
        );
    }

    /**
     * 契约 2：PluginManager::register() 可注册该夹具插件
     *
     * 说明：register() 内部依赖 class_exists()，因此该用例同时是 PSR-4 映射的间接验证；
     * 夹具继承 CorePluginBaseService，满足 PluginManager 的最小契约。
     */
    public function testPluginManagerCanRegisterFixturePlugin(): void
    {
        $manager = new PluginManager($this->app());

        // 注册夹具插件（安装状态表缺失时 PluginManager 内部已降级处理）
        $manager->register('_fixture', \plugin\_fixture\Fixture::class, ['version' => '0.0.0']);

        $plugin = $manager->get('_fixture');

        $this->assertInstanceOf(\plugin\_fixture\Fixture::class, $plugin);
        $this->assertSame('_fixture', $plugin->getKey());
        $this->assertSame(\plugin\_fixture\Fixture::FIXED_MESSAGE, $plugin->ping());
    }

    /**
     * 契约 3：ApplicationServiceProvider 已在 app/service.php 中注册
     *
     * 说明：app/service.php 是 ThinkPHP 的系统服务定义文件，
     * 未注册时 ApplicationManager 的生命周期（install/enable/upgrade）在真实请求中永不执行。
     *
     * ⚠️ 已知阻塞（缺陷 A5，待决策）：
     * `ApplicationServiceProvider` 继承的是 `Infrastructure\DI\AbstractServiceProvider`，
     * 而 ThinkPHP 8 的 `App::register()` 只会调用 `$service->register()`（无参）并在
     * `App::boot()` 中以 `bootService(Service $service)` 强类型启动服务。
     * 直接把该类写入 app/service.php 会触发：
     *   - `ArgumentCountError: Too few arguments to ... register(), 0 passed`
     *   - 即使绕过，`App::boot()` 仍会因类型不匹配抛 `TypeError`
     * 即：每个请求都会 500。因此在其改造为 `think\Service` 子类之前，
     * 本用例保持跳过状态，避免污染全量回归基线。
     */
    public function testApplicationServiceProviderRegisteredInServiceFile(): void
    {
        if (!is_subclass_of(ApplicationServiceProvider::class, \think\Service::class)) {
            $this->markTestSkipped(
                '阻塞：ApplicationServiceProvider 尚未继承 think\\Service，写入 app/service.php 会导致每个请求抛 ArgumentCountError（详见 A5 报告）'
            );
        }

        // 注意：root_path() 会补齐尾部分隔符，这里直接拼接相对路径，避免出现 `app/service.php/`
        $serviceFile = $this->app()->getRootPath() . 'app/service.php';
        $services = require $serviceFile;

        $this->assertIsArray($services, 'app/service.php 必须返回服务类数组');
        $this->assertContains(
            ApplicationServiceProvider::class,
            $services,
            'app/service.php 未注册 ApplicationServiceProvider'
        );

        // 容器应能解析 ApplicationManager（由 provider 的 register() 绑定单例）
        $manager = $this->app()->make(ApplicationManager::class);
        $this->assertInstanceOf(ApplicationManager::class, $manager);
    }

    /**
     * 守卫：app/service.php 中的每一项都必须能被 ThinkPHP 作为服务注册和启动
     *
     * ThinkPHP 8 的 `App::register()` 签名为 `register(Service|string $service)`，
     * 实例化后会以「无参」方式调用 `$service->register()`，并在 `App::boot()` 中
     * 通过 `bootService(Service $service)` 强类型启动。任何非 `think\Service` 子类
     * 写入该文件都会让每个请求致命失败。
     *
     * 本用例用于防止「直接把 ApplicationServiceProvider 塞进 app/service.php」这类
     * 看似修复、实则全站 500 的改动。
     */
    public function testServiceFileEntriesAreThinkServiceSubclasses(): void
    {
        $serviceFile = $this->app()->getRootPath() . 'app/service.php';
        $services = require $serviceFile;

        $this->assertNotEmpty($services, 'app/service.php 不应为空');

        foreach ($services as $service) {
            $class = is_object($service) ? $service::class : (string) $service;
            $this->assertTrue(
                is_subclass_of($class, \think\Service::class),
                sprintf('app/service.php 中的 %s 不是 think\\Service 子类，ThinkPHP 无法注册/启动它', $class)
            );
        }
    }
}
