<?php

declare(strict_types=1);

namespace Tests\Unit\Plugin;

use Infrastructure\Plugin\Hook\HookManager;
use Tests\ThinkPHPTestCase;

/**
 * HookManager 单元测试
 */
class HookManagerTest extends ThinkPHPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 确保全局 hook/do_action/apply_filter 可用（ThinkPHP 单测环境默认不一定加载 app/common.php）
        require_once dirname(__DIR__, 3) . '/app/common.php';
    }

    protected function tearDown(): void
    {
        HookManager::instance()->clear();
        parent::tearDown();
    }

    public function testActionHookRespectsPriority(): void
    {
        $calls = [];

        \hook('DemoAction', function () use (&$calls) {
            $calls[] = 'p20';
        }, 20, 'action');

        \hook('DemoAction', function () use (&$calls) {
            $calls[] = 'p10';
        }, 10, 'action');

        \do_action('DemoAction');

        $this->assertSame(['p10', 'p20'], $calls);
    }

    public function testFilterHookChainsValue(): void
    {
        \hook('DemoFilter', function (int $value) {
            return $value + 1;
        }, 20, 'filter');

        \hook('DemoFilter', function (int $value) {
            return $value * 2;
        }, 10, 'filter');

        $result = \apply_filter('DemoFilter', 3);

        // 先 *2 => 6，再 +1 => 7
        $this->assertSame(7, $result);
    }
}
