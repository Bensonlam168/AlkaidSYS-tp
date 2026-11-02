<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission\Service;

use Infrastructure\Permission\Service\CasbinService;
use Tests\Support\ThrowingCacheDriver;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;
use think\facade\Config;

/**
 * `CasbinService::shouldReload()` 的缓存故障兜底测试（R6）
 * Cache-failure fallback tests for `CasbinService::shouldReload()`
 *
 * ## 缺陷
 *
 * 旧实现的 `shouldReload()` 里直接 `Cache::get('casbin_last_reload', 0)`，**没有任何 try/catch**。
 * 而它在**每次鉴权**的路径上被调用（`check()` 与 `getUserPermissions()` 各一次），
 * 于是「读一次缓存失败」= 每一次鉴权 500 —— 缓存是旁路，它的失败不该决定请求能不能鉴权。
 *
 * ## 本文件锁定的四条边界
 *
 * 1. 缓存读抛 `\TypeError`（不是 `\Exception`）→ `shouldReload()` **不抛**，且返回
 *    **true**（保守：无法证明内存策略是新的 → 重载），并记录 warning（可区分于其它故障）
 *    → {@see self::testCacheReadTypeErrorFallsBackToReload()}
 * 2. 正常缓存：`lastReload` 在 TTL 内 → false；超出 TTL → true（回归）
 *    → {@see self::testShouldReloadFollowsTtl()}
 * 3. `reload_ttl = 0`（显式关闭自动刷新）→ 恒 false（回归）
 *    → {@see self::testShouldReloadDisabledWhenTtlZero()}
 * 4. `reload_ttl = 0` **且** 缓存读失败 → 仍为 false：关闭语义不因缓存故障被推翻
 *    （否则故障期会变成「每个请求都重载」，与运维意图相反）
 *    → {@see self::testTtlZeroWinsOverCacheFailure()}
 *
 * 另外端到端锁定「缓存故障不升级为 500」：整个 `check()` 在缓存读/写/清全部抛
 * `\TypeError` 时仍返回布尔判定 → {@see self::testCheckStillReturnsDecisionWhenCacheBroken()}
 *
 * @package Tests\Unit\Infrastructure\Permission\Service
 */
class CasbinShouldReloadTest extends ThinkPHPTestCase
{
    /**
     * 故障缓存 store 的配置名（唯一，避免撞上测试基类的 file/redis）
     */
    private const BROKEN_STORE = 'r6_broken_store';

    /**
     * 原始 `cache.default`（本用例改了要在 finally 里还原）
     */
    private mixed $originalDefaultStore = null;

    /**
     * 原始 `casbin.reload_ttl`
     */
    private mixed $originalReloadTtl = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultStore = Config::get('cache.default');
        $this->originalReloadTtl   = Config::get('casbin.reload_ttl');
    }

    protected function tearDown(): void
    {
        // 兜底还原：用例中途失败（断言抛错）时也不能把故障驱动留给后续用例
        $this->restoreNormalCacheStore();

        // 只在「原本确实有值」时还原：若原本为 null，把它写回 null 会让
        // `(int) null = 0` 变成「关闭自动刷新」，进而污染同进程的后续用例
        if ($this->originalReloadTtl !== null) {
            $this->setReloadTtl((int) $this->originalReloadTtl);
        }

        parent::tearDown();
    }

    /**
     * 造一个「可观测 shouldReload()」的服务实例
     *
     * `shouldReload()` 是 protected —— 外部本就不该直接调它，因此不为了测试把可见性改成 public
     * （那会把实现细节变成公开 API），而是用子类开放一个探针方法。
     *
     * 注意：构造 `CasbinService` 会创建 Enforcer 并从 DB 载入策略（缓存无关），
     * 所以本方法必须在**切换故障缓存之前**调用，避免把「构造期」也卷进故障场景。
     *
     * @return CasbinService 带探针的服务实例 | Service instance with probe method
     */
    private function makeProbeService(): CasbinService
    {
        return new class () extends CasbinService {
            /**
             * 探针：转发到 protected 的 shouldReload()
             */
            public function probeShouldReload(): bool
            {
                return $this->shouldReload();
            }
        };
    }

    /**
     * 把默认缓存切到「一读就抛 \TypeError」的驱动
     *
     * 走的是框架真实解析链路：`Cache::get()` → `think\Cache` → 默认 store → 驱动。
     * 因此这里只改配置（store 类型 + 默认 store 名），不改任何业务代码。
     *
     * 【必须用 Config::set(array $片段, 'cache') 的两参形式】
     *   ThinkPHP 8 的 `think\Config::set()` 签名是 `set(array $config, ?string $name = null)`：
     *   第一个参数是**要合并的片段**，第二个才是「一级配置名」。
     *   写成 `Config::set(['cache.default' => 'x'])` 时，点号键会变成**顶层的扁平键**
     *   （`config['cache.default']`），`Config::get('cache.default')` 根本读不到 ——
     *   静默无效（本文件最初就踩过这个坑。仓库里部分既有用例仍是那种写法，见交付报告的风险项）。
     */
    private function useBrokenCacheStore(): void
    {
        $stores = (array) Config::get('cache.stores', []);
        $stores[self::BROKEN_STORE] = ['type' => ThrowingCacheDriver::class];

        // stores 必须整体写回：Config::set 的合并是 array_merge（浅合并），
        // 只写 ['stores' => ['r6_broken_store' => ...]] 会把 file / redis 两个 store 挤掉。
        Config::set([
            'default' => self::BROKEN_STORE,
            'stores'  => $stores,
        ], 'cache');

        // 若同名 store 曾被解析过（同一进程内多方法），丢弃缓存实例，确保用新配置重建
        $this->cacheManager()->forgetDriver(self::BROKEN_STORE);
    }

    /**
     * 还原默认缓存并丢弃故障驱动实例
     */
    private function restoreNormalCacheStore(): void
    {
        $originalDefault = $this->originalDefaultStore ?? 'file';

        $stores = (array) Config::get('cache.stores', []);
        unset($stores[self::BROKEN_STORE]);

        Config::set([
            'default' => $originalDefault,
            'stores'  => $stores,
        ], 'cache');

        $manager = $this->cacheManager();
        $manager->forgetDriver(self::BROKEN_STORE);
        // 默认 store 恢复后，把它的实例也丢弃，避免继续命中故障期创建的实例
        $manager->forgetDriver($originalDefault);
    }

    /**
     * 设置 `casbin.reload_ttl`（同样是两参形式，见 useBrokenCacheStore() 的说明）
     *
     * @param int $ttl 刷新间隔（秒）| Reload interval in seconds
     */
    private function setReloadTtl(int $ttl): void
    {
        Config::set(['reload_ttl' => $ttl], 'casbin');
    }

    /**
     * 取缓存管理器（`Cache` 门面的根对象）
     *
     * @return \think\Cache
     */
    private function cacheManager(): \think\Cache
    {
        return $this->app()->make('cache');
    }

    /**
     * 场景 1：缓存读抛 `\TypeError` → `shouldReload()` 不抛，返回 true，并记 warning
     *
     * 断言三点：
     *   - **不抛异常**（旧实现会抛 → 每次鉴权 500）；
     *   - 返回 **true**（保守重载，而不是「静默当作不需要重载」）；
     *   - 记了一条 `warning`，文案能区分「缓存读失败」与其它 Casbin 故障。
     */
    public function testCacheReadTypeErrorFallsBackToReload(): void
    {
        $service = $this->makeProbeService();

        // TTL 用正常值，确保走到「读缓存」这一步（reload_ttl = 0 会提前返回 false）
        $this->setReloadTtl(300);
        $this->useBrokenCacheStore();

        $result = null;
        $records = $this->captureLogRecordsDuring(function () use ($service, &$result): void {
            // 旧实现在这一行抛 \TypeError（门面 → 管理器 → 驱动的 get()）
            $result = $service->probeShouldReload();
        });

        $this->assertTrue(
            $result,
            '缓存读失败时必须保守返回 true（认为需要重载），不能静默当作「不需要重载」'
        );

        $warnings = array_values(array_filter(
            $records,
            static fn (array $record): bool => $record['type'] === 'warning'
                && str_contains($record['message'], 'cache read failed')
        ));

        $this->assertNotEmpty(
            $warnings,
            '缓存读失败必须记 warning，且文案要能区分「缓存读失败」与其它 Casbin 故障，实际日志：'
            . json_encode($records, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 场景 2：正常缓存下的 TTL 判定（回归）
     *
     * 目录：`lastReload` 在 TTL 内 → false；超出 TTL → true；无时间戳（首次）→ true。
     */
    public function testShouldReloadFollowsTtl(): void
    {
        $service = $this->makeProbeService();
        $this->setReloadTtl(300);

        // 刚重载过 → 不重载
        Cache::set('casbin_last_reload', time());
        $this->assertFalse($service->probeShouldReload(), 'TTL 内不应重载');

        // 超出 TTL → 重载
        Cache::set('casbin_last_reload', time() - 301);
        $this->assertTrue($service->probeShouldReload(), '超出 TTL 必须重载');

        // 没有时间戳（首次 / 被清理）→ 视为 0 → 重载
        Cache::delete('casbin_last_reload');
        $this->assertTrue($service->probeShouldReload(), '无 lastReload 时按「从未重载」处理');

        Cache::delete('casbin_last_reload');
    }

    /**
     * 场景 3：`reload_ttl = 0` → 恒 false（回归）
     */
    public function testShouldReloadDisabledWhenTtlZero(): void
    {
        $service = $this->makeProbeService();
        $this->setReloadTtl(0);

        // 即便 lastReload 是很久以前，也不重载
        Cache::set('casbin_last_reload', time() - 99999);
        $this->assertFalse($service->probeShouldReload(), 'reload_ttl = 0 表示关闭自动刷新');
        Cache::delete('casbin_last_reload');

        // 连 lastReload 都没有，也不重载（不读缓存也能得出结论）
        $this->assertFalse($service->probeShouldReload(), 'reload_ttl = 0 时不应因缺少时间戳而重载');
    }

    /**
     * 场景 4：`reload_ttl = 0` 优先于「缓存读失败 → true」
     *
     * 这条锁定顺序上的设计决定：`reload_ttl = 0` 是运维**显式关闭**自动刷新，
     * 它的语义不应依赖缓存是否可用。否则缓存故障期会被「读失败 → true」推翻，
     * 变成「每个请求都全量重载策略」—— 恰好是关闭自动刷新想避免的事。
     */
    public function testTtlZeroWinsOverCacheFailure(): void
    {
        $service = $this->makeProbeService();
        $this->setReloadTtl(0);
        $this->useBrokenCacheStore();

        $result = null;
        // 不抛即可；返回 false 由下面的断言确认
        $this->captureLogRecordsDuring(function () use ($service, &$result): void {
            $result = $service->probeShouldReload();
        });

        $this->assertFalse($result, 'reload_ttl = 0 时，即使缓存读失败也必须保持「不自动刷新」');
    }

    /**
     * 场景 5：缓存整体故障（读/写/清都抛 `\TypeError`）→ `check()` 仍返回布尔判定
     *
     * 这是本缺陷在业务侧的最终表现面：Redis 挂掉时鉴权不能 500。
     * `check()` 内部会：读检查缓存（失败 → 降级）→ shouldReload（失败 → 重载）
     * → reloadPolicy（写时间戳/清缓存失败 → 只记 warning）→ enforce（真实判定）。
     * 任何一步都不许把异常抛到调用方。
     */
    public function testCheckStillReturnsDecisionWhenCacheBroken(): void
    {
        // 先按正常缓存构造服务（Enforcer 初始化需要 DB 与「非故障」的构造环境）
        $service = $this->makeProbeService();
        $this->setReloadTtl(300);
        Config::set(['cache_enabled' => true], 'casbin');

        $this->useBrokenCacheStore();

        // 用不存在的用户/资源：判定结果必为 false，且不依赖 DB 里的任何授权数据
        $result = $service->check(9876001, 1, 'r6_broken_cache_probe', 'read');

        $this->assertFalse($result, '没有任何策略命中时判定应为 false，且不得因缓存故障抛异常');
        $this->assertGreaterThanOrEqual(
            1,
            $service->getCacheDegradationStats()['degradation_count'],
            '缓存故障必须被记录为降级事件（可观测）'
        );
    }

    /**
     * 在回调执行期间捕获 ThinkPHP 日志记录
     *
     * 与 `tests/Unit/Auth/JwtSecretFailFastTest` 同一手法：CLI 下日志通道会即时落盘并清空
     * 内存记录，因此监听 `LogWrite` 事件，而不是读 `Log::getLog()`。
     * 监听器在 finally 中移除，避免污染同进程内的其它用例。
     *
     * @param callable $callback 需要被捕获日志的回调
     * @return array<int, array{type: string, message: string}>
     */
    private function captureLogRecordsDuring(callable $callback): array
    {
        $records = [];
        $app     = $this->app();

        \think\facade\Log::listen(function ($event) use (&$records): void {
            foreach ($event->log as $record) {
                $records[] = [
                    'type'    => (string) $record->type,
                    'message' => (string) $record->message,
                ];
            }
        });

        try {
            $callback();
            // 非 CLI（懒写入）场景下需显式 flush 才会触发 LogWrite 事件
            \think\facade\Log::save();
        } finally {
            // 移除本次注册的 LogWrite 监听，避免跨用例污染
            $app->event->remove(\think\event\LogWrite::class);
        }

        return $records;
    }
}
