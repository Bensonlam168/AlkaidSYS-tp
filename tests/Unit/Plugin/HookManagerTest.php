<?php

declare(strict_types=1);

namespace Tests\Unit\Plugin;

use Infrastructure\Plugin\Hook\HookManager;
use Infrastructure\Plugin\Hook\HookResult;
use Domain\Event\EventContext;
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

    public function testActionHookStopPropagation(): void
    {
        $calls = [];

        \hook('StopAction', function () use (&$calls) {
            $calls[] = 'first';
            return HookResult::stop();
        }, 5, 'action');

        \hook('StopAction', function () use (&$calls) {
            $calls[] = 'second';
        }, 10, 'action');

        \do_action('StopAction');

        $this->assertSame(['first'], $calls);
    }

    public function testFilterStopPropagationKeepsCurrentValue(): void
    {
        \hook('StopFilter', function (int $value) {
            return HookResult::stop($value + 1);
        }, 5, 'filter');

        \hook('StopFilter', function (int $value) {
            return $value + 100;
        }, 10, 'filter');

        $result = \apply_filter('StopFilter', 1);

        $this->assertSame(2, $result);
    }

    public function testAsyncCoroutineUsesRunner(): void
    {
        $calls = [];
        $runnerInvoked = false;

        \hook('AsyncAction', function (array $payload, EventContext $ctx = null) use (&$calls) {
            $calls[] = $payload['foo'] ?? null;
            $calls[] = $ctx?->getTraceId();
        }, 10, 'action');

        $runner = function (callable $fn) use (&$runnerInvoked) {
            $runnerInvoked = true;
            $fn();
        };

        HookManager::instance()->doActionAsyncCoroutine('AsyncAction', [['foo' => 'bar']], new EventContext('trace-abc', 1, 0, 2), $runner);

        $this->assertTrue($runnerInvoked);
        $this->assertSame(['bar', 'trace-abc'], $calls);
    }

    public function testRemovePluginHooksClearsCallbacks(): void
    {
        $plugin = new class () {
            public function getKey(): string
            {
                return 'demo_plugin';
            }

            public function onCall(array &$log): void
            {
                $log[] = 'called';
            }
        };

        $log = [];
        \hook('PluginAction', [$plugin, 'onCall'], 10, 'action');
        HookManager::instance()->removePluginHooks('demo_plugin');

        \do_action('PluginAction', $log);

        $this->assertSame([], $log);
    }
}
