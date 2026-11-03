<?php

declare(strict_types=1);

namespace Tests\Feature\Route;

use Tests\ThinkPHPTestCase;

/**
 * 管理端路由可达性测试 | ER-05 路由遮蔽缺陷的回归防护
 *
 * ## 守住的缺陷
 *
 * `config/route.php` 的 `route_complete_match = false` 让规则变成**前缀匹配**：
 * `PUT v1/admin/roles/:id`（`update`）会吃掉 `PUT v1/admin/roles/:id/permissions`
 * （`setPermissions`）。结果不是 404，而是**静默走错动作** ——
 * 请求返回 200 和 `role.updated`，"设置权限"实际改的是角色名/描述。
 *
 * 这类缺陷不会报错、不会 500，只能靠"这条路由到底进了哪个动作"来发现，
 * 所以本用例用框架**自己的**匹配逻辑（`think\Route::check()`，与真实请求同一套代码）
 * 重新解析每条 `v1/admin/**` 路由的样例 URL，断言「命中的目标 == 这条规则本该命中的目标」。
 *
 * ## 为什么只审 v1/admin/**
 *
 * `route/workflow.php` 与 `route/lowcode.php` 里存在同类型的**既有**遮蔽（见 ER-05 报告
 * 的清单），修它们不在本次改动范围。本用例把断言范围钉在本次负责的 `v1/admin/**` 上：
 * 既能防止本次修复被回退，也不会因为别人的既有缺陷长期红着。
 *
 * @package Tests\Feature\Route
 */
class AdminRouteReachabilityTest extends ThinkPHPTestCase
{
    /**
     * 只审计这个前缀下的路由
     */
    private const AUDIT_PREFIX = '/v1/admin/';

    /**
     * `v1/admin/**` 的规则数下限
     *
     * 防止"一条规则都没收集到 → 断言全部通过"这种假绿。
     */
    private const MIN_EXPECTED_RULES = 40;

    /**
     * 每条管理端路由都必须能命中它自己注册的那个动作
     */
    public function testEveryAdminRouteReachesItsOwnAction(): void
    {
        $app = $this->app();
        $app->bind(\think\Request::class, \app\Request::class);

        // 注册路由（include_once：进程内只注册一次）
        foreach (glob($app->getRootPath() . 'route/*.php') as $file) {
            include_once $file;
        }

        $router = $app->route;

        // `Route::check()` 读的是 `$this->request`（真实请求里由 dispatch() 写入），这里反射注入
        $requestProperty = new \ReflectionProperty(\think\Route::class, 'request');
        $requestProperty->setAccessible(true);

        $shadowed = [];
        $audited = 0;

        foreach ($router->getRuleList() as $item) {
            $rule = (string) ($item['rule'] ?? '');
            if ($rule === '' || str_contains($rule, '<MISS>')) {
                continue;
            }

            $path = '/' . ltrim($rule, '/');
            if (!str_starts_with($path, self::AUDIT_PREFIX)) {
                continue;
            }

            // `Route::any()` 注册的规则 method 是 `*`；审计时用 GET 代表即可
            $method = strtoupper((string) ($item['method'] ?: 'GET'));
            if ($method === '*') {
                $method = 'GET';
            }

            $expected = $this->normalizeTarget($item['route']);

            // 样例 URL：路由变量一律填 '1'（本仓库所有 pattern 都接受它）
            $sample = (string) preg_replace('/<\w+\??>/', '1', $rule);

            $request = $app->make(\think\Request::class);
            $request->setMethod($method);
            $request->setPathinfo($sample);
            $requestProperty->setValue($router, $request);

            // 与真实请求完全一致的匹配（第二参数 = config('route.route_complete_match') = false）
            $dispatch = $router->check(str_replace('/', '|', $sample), false);

            $audited++;

            if ($dispatch === false) {
                $shadowed[] = sprintf('%s %s -> 无规则命中（期望 %s）', $method, $path, $expected);
                continue;
            }

            $actual = $this->normalizeTarget($dispatch->getDispatch());

            if ($actual !== $expected) {
                $shadowed[] = sprintf('%s %s -> 实际命中 %s（期望 %s）', $method, $path, $actual, $expected);
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_EXPECTED_RULES,
            $audited,
            sprintf('审计到的 v1/admin 规则只有 %d 条，低于下限（说明路由没注册上，审计会假绿）', $audited)
        );

        $this->assertSame(
            [],
            $shadowed,
            "以下管理端路由被更早注册的规则前缀匹配遮蔽（请求会静默走错动作）：\n" . implode("\n", $shadowed)
        );
    }

    /**
     * 把路由目标规范成 `Controller@method`
     *
     * `Rule::parseRule()` 会把 `'a\b\C@method'` 拆成 `['a\b\C', 'method']`；
     * 闭包/`Controller/action` 形式也统一成同一形态。
     *
     * @param mixed $target 路由目标
     * @return string
     */
    private function normalizeTarget($target): string
    {
        if ($target instanceof \Closure) {
            return 'Closure';
        }

        if (is_array($target) && count($target) === 2) {
            return implode('@', $target);
        }

        if (is_string($target) && !str_contains($target, '@') && substr_count($target, '/') === 1) {
            // `index/hello` 这类两段式写法等价于 `index@hello`
            return str_replace('/', '@', $target);
        }

        return is_string($target) ? $target : (string) json_encode($target);
    }
}
