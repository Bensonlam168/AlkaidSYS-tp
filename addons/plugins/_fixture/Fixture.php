<?php

declare(strict_types=1);

namespace plugin\_fixture;

use Infrastructure\Plugin\CorePluginBaseService;
use Infrastructure\Plugin\Hook\HookManager;

/**
 * 插件自动加载测试夹具（长期测试资产，请勿删除）
 *
 * 用途：验证 `composer.json` 中 `plugin\` PSR-4 映射（plugin\ => addons/plugins/）真实生效，
 * 并验证 `Infrastructure\Plugin\PluginManager::register()` 能够接受该命名空间下的插件类。
 *
 * 说明：
 * - 本目录刻意不提供 plugin.json，`PluginScanner` 只识别 plugin.json，因此不会被扫描器误收录；
 * - 本类不注册任何真实钩子，仅用于自动加载与注册链路的契约测试。
 *
 * 对应测试：tests/Unit/Infrastructure/PluginAutoloadTest.php
 */
class Fixture extends CorePluginBaseService
{
    /**
     * 固定返回字符串（用于断言方法可正常调用）
     */
    public const FIXED_MESSAGE = 'plugin-autoload-fixture';

    /**
     * 返回固定字符串，证明类被正确加载且方法可调用
     */
    public function ping(): string
    {
        return self::FIXED_MESSAGE;
    }

    /**
     * 注册插件钩子
     *
     * 夹具不需要任何钩子，保持空实现即可（父类要求必须实现）。
     */
    protected function registerHooks(HookManager $hookManager): void
    {
        // 夹具不注册钩子
    }
}
