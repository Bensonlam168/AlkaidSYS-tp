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
use Tests\Traits\DatabaseTransactions;
use think\facade\Db;

/**
 * PluginManager 单元测试
 *
 * ## 数据库隔离（ER-17）
 *
 * 本用例会**真实写库**，且原先没有任何收尾逻辑。实测（从空表起跑）每跑一次会留下：
 *   1. `alkaid_plugin_installations` 一行（tenant_id=0, site_id=0, plugin_key=sample_plugin）；
 *   2. `hook_metrics` **一行** —— `HookMonitor` 在 applyFilter 时记账，是「每跑一次加一行」的无界增长。
 *      （用例自己只在**开头**删 installations，hook_metrics 从来没被清理过。）
 *
 * 这里用 `DatabaseTransactions` 把整个用例罩进事务：`#[Before]` 在 `setUp()` 之前开始事务，
 * `#[After]` 在 `tearDown()` 之后回滚，因此上面两类写入（含 HookMonitor 的埋点）都会被丢弃。
 *
 * 为什么**可以**用事务：本用例只做 INSERT / UPDATE / DELETE，**没有 DDL**
 * （MySQL 的 DDL 会隐式提交，与事务隔离互斥，见 trait 类注释）。
 *
 * 注意：HookManager 是进程内单例，`addFilter` 注册的钩子仍在内存中存活到进程结束 ——
 * 那不是数据库残渣，事务也管不到；本用例靠「每次新建 PluginManager 并显式 register」自洽。
 */
class PluginManagerTest extends ThinkPHPTestCase
{
    use DatabaseTransactions;

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
