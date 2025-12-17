<?php

declare(strict_types=1);

namespace plugin\sample_plugin;

use Infrastructure\Plugin\CorePluginBaseService;
use Infrastructure\Plugin\Hook\HookManager;

/**
 * 示例插件（仅用于单测）
 */
class Plugin extends CorePluginBaseService
{
    protected function registerHooks(HookManager $hookManager): void
    {
        // 注册一个 filter，用于验证 enable() 后可被 apply_filter 调用
        $hookManager->addFilter('SampleFilter', function (int $value) {
            return $value + 100;
        }, 10);
    }
}

namespace Tests\Unit\Plugin;

use Infrastructure\Plugin\PluginManager;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * PluginManager 单元测试
 */
class PluginManagerTest extends ThinkPHPTestCase
{
    public function testRegisterAndEnablePluginRegistersHooks(): void
    {
        // 清理可能残留的安装记录，避免测试受数据库状态影响
        try {
            Db::name('alkaid_plugin_installations')
                ->where('plugin_key', 'sample_plugin')
                ->delete();
        } catch (\Throwable $e) {
            // 未执行迁移时忽略
        }

        $manager = new PluginManager($this->app());
        $manager->register('sample_plugin', \plugin\sample_plugin\Plugin::class, ['version' => '1.0.0']);

        $plugin = $manager->get('sample_plugin');
        $this->assertNotNull($plugin);
        $this->assertFalse($plugin->isInstalled());
        $this->assertFalse($plugin->isEnabled());

        $manager->enable('sample_plugin');

        $this->assertTrue($plugin->isInstalled());
        $this->assertTrue($plugin->isEnabled());

        $this->assertSame(101, $manager->applyFilter('SampleFilter', 1));
    }
}
