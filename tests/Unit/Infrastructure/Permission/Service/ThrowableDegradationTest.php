<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission\Service;

use Infrastructure\Permission\Cache\PermissionCachePurger;
use Infrastructure\Permission\Service\CasbinService;
use Infrastructure\Permission\Service\PermissionCacheService;
use Infrastructure\Permission\Service\PermissionService;
use Tests\ThinkPHPTestCase;
use think\facade\Config;

/**
 * 「\Throwable 降级边界」测试 | Throwable degradation boundary tests
 *
 * ## 背景（缺陷 R4）
 *
 * `\Error`（含 `\TypeError`）**不继承** `\Exception`。于是 `catch (\Exception)` 抓不到它们：
 * 一个**旁路**（缓存读写、键登记）里的 `\TypeError` 会穿透全部降级逻辑，
 * 最终变成 HTTP 500 —— 实测过的那条链路是
 * `CasbinService::check()` → `$this->purger()->trackKey()` 抛 `\TypeError`
 * → `catch (\Exception)` 漏接 → 500。
 *
 * ## 本文件锁定的两条边界（改与不改的分界线）
 *
 * 1. **旁路必须降级**：缓存是旁路，它的失败与「用户有哪些权限」无关。
 *    缓存侧抛 `\TypeError` 时，业务必须继续，结果由真实数据源给出。
 *    → {@see self::testCacheWriteTypeErrorDegradesToDirectFetch()}
 *    → {@see self::testPermissionCheckCacheTypeErrorDegradesToDirectCheck()}
 *    → {@see self::testCasbinCheckDegradesWhenPurgerThrowsTypeError()}
 *
 * 2. **核心判定不许被吞**：如果「算权限」的那一步本身抛 `\Error`（代码写错了），
 *    绝不能把它替换成「空权限集」—— 那会变成「判错但静默拒绝」，
 *    让真实缺陷以 403 的形式永久潜伏。
 *    → {@see self::testCacheServicePropagatesErrorFromCoreFetcher()}
 *    → {@see self::testPermissionServiceDoesNotSwallowErrorFromCoreFetch()}
 *
 * 这两条边界也是「`PermissionService` 的 `catch (\Exception)` 有意保持不改」的依据：
 * 它的 catch 分支是用 `return []` **替换授权结论**，不是降级。
 *
 * @package Tests\Unit\Infrastructure\Permission\Service
 */
class ThrowableDegradationTest extends ThinkPHPTestCase
{
    /**
     * 造一个「缓存写入必抛 \TypeError」的缓存服务
     *
     * `writeCache()` 是 protected，子类可覆写 —— 这比 mock 静态 `Cache` 门面更稳，
     * 也正好模拟 R3/R4 里 `trackKey()` 抛 `\TypeError` 的真实形态（两者都在 writeCache 内）。
     *
     * @return PermissionCacheService
     */
    private function makeCacheServiceWithBrokenWrite(): PermissionCacheService
    {
        return new class () extends PermissionCacheService {
            protected function writeCache(string $key, mixed $value, int $ttl): void
            {
                throw new \TypeError('模拟缓存侧类型错误：trackKey 收到非法键（R4）');
            }
        };
    }

    /**
     * 场景 1：缓存写入抛 \TypeError → getUserPermissions 仍返回真实数据（降级）
     *
     * 断言重点是「不抛异常」+「返回的是 fetcher 算出来的真实结果」，而不是空数组：
     * 若这里退化成 []，用户的所有权限会瞬间消失（全站 403）。
     */
    public function testCacheWriteTypeErrorDegradesToDirectFetch(): void
    {
        $service = $this->makeCacheServiceWithBrokenWrite();
        $fetched = 0;

        // 用不存在的 userId，确保 L1/L2 一定 miss，从而一定会走到 writeCache
        $result = $service->getUserPermissions(987001, 1, function () use (&$fetched) {
            $fetched++;

            return ['dept:read', 'menu:read'];
        });

        $this->assertSame(['dept:read', 'menu:read'], $result, '缓存写入抛 TypeError 时必须降级为真实数据');
        $this->assertGreaterThanOrEqual(1, $fetched, '降级路径必须真正调用数据获取回调');
    }

    /**
     * 场景 2：单权限检查缓存抛 \TypeError → hasPermission 仍返回真实判定（降级）
     */
    public function testPermissionCheckCacheTypeErrorDegradesToDirectCheck(): void
    {
        $service = $this->makeCacheServiceWithBrokenWrite();
        $checked = 0;

        $result = $service->hasPermission(987002, 1, 'dept:read', function () use (&$checked) {
            $checked++;

            return true;
        });

        $this->assertTrue($result, '缓存写入抛 TypeError 时必须降级为真实判定结果');
        $this->assertGreaterThanOrEqual(1, $checked, '降级路径必须真正调用判定回调');
    }

    /**
     * 场景 3（本次缺陷的原点）：Casbin 缓存旁路抛 \TypeError → check() 仍返回鉴权结果
     *
     * 复刻实测链路：`check()` 在算完 enforce 结果后写缓存，写缓存时 `purger()->trackKey()`
     * 抛 `\TypeError`。旧写法 `catch (\Exception)` 漏接 → 整个请求 500；
     * 正确行为是记一次降级、照常返回 enforce 的结果（本用例没有任何策略 → false）。
     *
     * 【为什么 resource 用随机串】
     *   `check()` 先读缓存再算：`Cache::set()` 发生在 `trackKey()` **之前**，
     *   所以即便本用例「写缓存」这一步抛错，`false` 也已经被写进缓存了。
     *   若用固定 resource，第二次运行本用例时首次读就会命中，直接 `return`，
     *   根本走不到降级分支 —— 用例会变成「第一次绿、第二次红」的假测试。
     *   用每次唯一的 resource 保证必然 miss；结束时尽力删掉本用例产生的键。
     */
    public function testCasbinCheckDegradesWhenPurgerThrowsTypeError(): void
    {
        // 关掉策略自动重载，把用例锁定在「写缓存」这一步，避免 reloadPolicy 参与干扰
        $originalReloadTtl  = Config::get('casbin.reload_ttl');
        $originalCacheOn    = Config::get('casbin.cache_enabled');
        $originalLogEnabled = Config::get('casbin.cache_degradation_log_enabled');
        Config::set(['casbin.reload_ttl' => 0]);
        Config::set(['casbin.cache_enabled' => true]);
        Config::set(['casbin.cache_degradation_log_enabled' => false]);

        // 每次运行唯一的 resource，确保缓存必然 miss（见方法注释）
        $resource = 'r4_throwable_' . bin2hex(random_bytes(6));
        $cacheKey = "casbin:check:987003:1:{$resource}:read";

        try {
            $service = new class () extends CasbinService {
                /**
                 * 直接让「取缓存清除器」这一步抛 \TypeError，等价于 trackKey() 内部抛错
                 */
                protected function purger(): PermissionCachePurger
                {
                    throw new \TypeError('模拟 purger 类型错误：trackKey 收到非法键（R4）');
                }
            };

            $result = $service->check(987003, 1, $resource, 'read');

            $this->assertIsBool($result, '缓存旁路抛 TypeError 时 check() 必须返回布尔判定结果，而不是抛异常');
            $this->assertFalse($result, '本用例没有任何 casbin 策略，判定应为 false');

            $stats = $service->getCacheDegradationStats();
            $this->assertGreaterThanOrEqual(1, $stats['degradation_count'], '必须记录一次缓存降级');
            $this->assertIsString($stats['last_degradation_reason']);
            $this->assertStringContainsString('模拟 purger 类型错误', $stats['last_degradation_reason']);
        } finally {
            // 尽力清掉本用例写入的缓存键，避免污染后续运行
            \think\facade\Cache::delete($cacheKey);
            Config::set(['casbin.reload_ttl' => $originalReloadTtl]);
            Config::set(['casbin.cache_enabled' => $originalCacheOn]);
            Config::set(['casbin.cache_degradation_log_enabled' => $originalLogEnabled]);
        }
    }

    /**
     * 场景 4：核心数据源抛 \Error → 缓存服务**不得**把它换成空结果（应上抛）
     *
     * 缓存服务只负责「提速」，不负责「替核心数据源回答问题」。
     * 若这里被吞成 []，调用方会把「代码写错了」当成「这个用户没有权限」。
     */
    public function testCacheServicePropagatesErrorFromCoreFetcher(): void
    {
        $service = new PermissionCacheService();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('模拟核心读取的类型错误（R4）');

        $service->getUserPermissions(987004, 1, function () {
            throw new \TypeError('模拟核心读取的类型错误（R4）');
        });
    }

    /**
     * 场景 5：权限判定核心路径抛 \Error → PermissionService 必须上抛
     *
     * 这是「不改 `PermissionService::getUserPermissions()` 的 `catch (\Exception)`」的锁定用例：
     * 它的 catch 分支是 `return []`，属于**用假结果替换授权结论**，而非降级。
     * 换成 `\Throwable` 会让「核心算错了」永远表现为 403，缺陷再也暴露不出来。
     */
    public function testPermissionServiceDoesNotSwallowErrorFromCoreFetch(): void
    {
        $service = new class () extends PermissionService {
            /**
             * 固定租户，避免用例依赖测试库里的 users 行
             */
            protected function getTenantId(int $userId): ?int
            {
                return 1;
            }

            /**
             * 核心读取：抛 \TypeError 模拟「代码写错了」
             */
            protected function fetchUserPermissions(int $userId, int $tenantId): array
            {
                throw new \TypeError('模拟核心权限读取的类型错误（R4）');
            }
        };

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('模拟核心权限读取的类型错误（R4）');

        // 用不存在的 userId，确保缓存不命中（缓存键含 userId）
        $service->hasPermission(987005, 'dept:read');
    }
}
