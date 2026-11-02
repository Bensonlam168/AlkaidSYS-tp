<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission\Casbin;

use Casbin\Model\Model;
use Infrastructure\Permission\Casbin\DatabaseAdapter;
use Tests\Support\ThrowingCacheDriver;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

/**
 * `DatabaseAdapter::loadPolicy()` 的缓存降级测试 | Casbin policy-cache degradation tests
 *
 * ## 缺陷（本次修复的对象）
 *
 * 旧实现把「读缓存」和「读数据库」放在**同一个** try 里，且缓存读没有独立兜底：
 *
 * ```php
 * $cachedPolicies = Cache::get('casbin:policy:full');   // Redis 抖一下就抛
 * ...
 * } catch (\Throwable $e) { Log::error(...); throw $e; }
 * ```
 *
 * 于是只要打开 `casbin.policy_cache_enabled`，「Redis 读失败」就会让 `loadPolicy()` 抛异常
 * → 鉴权链路 500。它还把上一轮给 `shouldReload()` 加的兜底完全抵消：
 * `shouldReload()` 读失败会保守返回 true → 触发 reload → 恰好走到这里抛。
 *
 * 同一类问题的另一半是写路径：`Cache::set()` 也在外层 try 里，于是
 * 「策略已经从 DB 成功读出来，只是没写进 Redis」同样会被升级成 500。
 *
 * ## 本文件锁定的四条边界
 *
 * 1. 缓存读抛 `\TypeError`（不是 `\Exception`）→ `loadPolicy()` **不抛**，且从 DB 正常加载，
 *    并记 warning（不是 error）→ {@see self::testBrokenCacheFallsBackToDatabase()}
 * 2. 缓存内容损坏（不是数组 / 缺段 / 规则不是字符串列表）→ 当 miss，从 DB 加载
 *    → {@see self::testMalformedCachePayloadFallsBackToDatabase()}
 * 3. 数据库加载失败 → **原样上抛**（回归：这条路必须继续失败）
 *    → {@see self::testDatabaseFailureIsStillRethrown()}
 * 4. 缓存正常命中 → 只恢复缓存内容、不查库（回归）
 *    → {@see self::testValidCacheHitSkipsDatabase()}
 *
 * @package Tests\Unit\Infrastructure\Permission\Casbin
 */
class DatabaseAdapterCacheFallbackTest extends ThinkPHPTestCase
{
    /**
     * 故障缓存 store 的配置名（唯一，避免撞上测试基类的 file/redis）
     */
    private const BROKEN_STORE = 'casbin_policy_broken_store';

    /**
     * 策略缓存键（与 DatabaseAdapter::POLICY_CACHE_KEY 保持一致）
     */
    private const CACHE_KEY = 'casbin:policy:full';

    /**
     * 原始 `cache.default`
     */
    private mixed $originalDefaultStore = null;

    /**
     * 原始 `casbin` 配置
     *
     * @var array<string, mixed>
     */
    private array $originalCasbinConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->app()->initialized()) {
            $this->app()->initialize();

            // 【为什么要立刻还原】`App::initialize()` 内部会 `think\initializer\Error::init()`，
            // 用 set_error_handler/set_exception_handler 注册 ThinkPHP 自己的处理器，覆盖掉
            // PHPUnit 的；PHPUnit 11 会因此把用例判为 Risky（`failOnRisky=true` 下即失败）。
            // 这与 `ThinkPHPTestCase::runHttp()` 里的处理完全一致 —— 区别只是本用例不经过 HTTP。
            restore_error_handler();
            restore_exception_handler();

            // ★ 必须同时告知基类「已经还原过」：基类的 runHttp() 会在首次调用时再还原一次，
            // 那会多弹一层（把 PHPUnit 自己的处理器也弹掉），表现为后续用例
            // 「removed error handlers other than its own」的 Risky —— 实测踩过。
            self::$errorHandlersRestored = true;
        }

        $this->originalDefaultStore = Config::get('cache.default');
        $this->originalCasbinConfig = (array) Config::get('casbin', []);

        // 清掉可能残留的策略缓存，避免用例之间互相影响
        $this->forgetPolicyCache();
    }

    protected function tearDown(): void
    {
        // 兜底还原：用例中途断言失败时也不能把故障驱动/开启的缓存开关留给后续用例
        $this->restoreNormalCacheStore();
        Config::set($this->originalCasbinConfig, 'casbin');
        $this->forgetPolicyCache();

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // 场景 1：缓存读失败 → 当 miss，从 DB 加载（不抛）
    // -----------------------------------------------------------------------

    /**
     * 缓存整体不可用（读/写/删全抛 `\TypeError`）→ loadPolicy 不抛，策略从 DB 装出来
     *
     * 同时锁定：
     *   - 记的是 **warning**（文案含 `cache read failed`），不是 error；
     *   - 不会出现 `Casbin policy loading failed` 这条 error（那是「装不出策略」专用）；
     *   - 写缓存失败也被吞掉（同一个故障驱动会让 `Cache::set()` 抛）。
     */
    public function testBrokenCacheFallsBackToDatabase(): void
    {
        $this->enablePolicyCache();
        $this->useBrokenCacheStore();

        $model   = $this->newModel();
        $records = $this->captureLogRecordsDuring(function () use ($model): void {
            // 旧实现：这一行会抛 \TypeError（门面 → 管理器 → 驱动的 get()）
            (new DatabaseAdapter())->loadPolicy($model);
        });

        // 缓存坏了，但 DB 是好的 → 策略必须装出来（而不是空模型 = 静默全拒绝）
        $this->assertNotEmpty($model->getPolicy('g', 'g'), '缓存故障时必须回落到 DB 并装出角色分配');
        $this->assertNotEmpty($model->getPolicy('p', 'p'), '缓存故障时必须回落到 DB 并装出权限分配');

        $warnings = $this->filterLogs($records, 'warning', 'cache read failed');
        $this->assertNotEmpty(
            $warnings,
            '缓存读失败必须记 warning（可降级），实际日志：' . json_encode($records, JSON_UNESCAPED_UNICODE)
        );

        $this->assertSame(
            [],
            $this->filterLogs($records, 'error', 'Casbin policy loading failed'),
            '缓存降级不得被记成「策略加载失败」（那是装不出策略才该出现的 error）'
        );
    }

    // -----------------------------------------------------------------------
    // 场景 2：缓存内容损坏 → 当 miss
    // -----------------------------------------------------------------------

    /**
     * 缓存内容不是合法结构 → 当 miss，从 DB 加载
     *
     * 逐条覆盖会被写坏的几种形态：
     *   a) 根本不是数组（字符串）；
     *   b) 缺 `p` 段（只有角色、没有权限 → 若不拦，会得到「全 403」的静默错误策略集）；
     *   c) 规则不是 list（关联键会让 `Policy::addPolicy()` 的 `implode` 字段错位）；
     *   d) 规则里混入非字符串（`implode` 会抛 TypeError）。
     */
    public function testMalformedCachePayloadFallsBackToDatabase(): void
    {
        $this->enablePolicyCache();

        // 用测试库里绝不可能存在的 id（库内用户/角色 id 是个位到几百量级）
        $marker = ['user:888001', 'role:888001', 'tenant:888001'];

        $payloads = [
            'not-an-array'         => 'this is not a policy payload',
            'missing-p-section'    => ['g' => [$marker]],
            'rule-not-a-list'      => [
                'g' => [['sub' => 'user:888001', 'role' => 'role:888001', 'dom' => 'tenant:888001']],
                'p' => [],
            ],
            'rule-with-non-string' => [
                'g' => [['user:888001', ['role:888001'], 'tenant:888001']],
                'p' => [],
            ],
        ];

        foreach ($payloads as $label => $payload) {
            $this->forgetPolicyCache();
            Cache::set(self::CACHE_KEY, $payload, 60);

            $model = $this->newModel();

            // 不应抛异常
            (new DatabaseAdapter())->loadPolicy($model);

            $this->assertNotEmpty(
                $model->getPolicy('g', 'g'),
                sprintf('缓存载荷「%s」应被判为 miss 并从 DB 加载，实际模型为空', $label)
            );

            // 证明走的是 DB 而不是把坏载荷硬塞进去：这条伪造规则不可能来自测试库
            $leaked = array_filter($model->getPolicy('g', 'g'), static fn ($rule): bool => $rule === $marker);
            $this->assertSame(
                [],
                $leaked,
                sprintf('缓存载荷「%s」里的伪造规则不得被应用', $label)
            );
        }
    }

    // -----------------------------------------------------------------------
    // 场景 3：DB 加载失败 → 原样上抛（回归）
    // -----------------------------------------------------------------------

    /**
     * 数据库加载失败必须继续上抛（缓存降级不能把「真的装不出策略」也吞掉）
     *
     * 手法：子类覆写 `loadRoleAssignments()` 直接抛 —— 等价于「DB 连不上 / 表缺失」。
     * 这里刻意**开启**策略缓存：即使缓存路径可用，DB 失败也不能被降级逻辑掩盖。
     */
    public function testDatabaseFailureIsStillRethrown(): void
    {
        $this->enablePolicyCache();
        $this->forgetPolicyCache();

        $adapter = new class () extends DatabaseAdapter {
            protected function loadRoleAssignments(Model $model): int
            {
                throw new \RuntimeException('模拟数据库不可用：user_roles 查询失败');
            }
        };

        $model = $this->newModel();

        $thrown = null;
        try {
            $adapter->loadPolicy($model);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'DB 加载失败必须原样上抛，不得被缓存降级吞掉');
        $this->assertSame('模拟数据库不可用：user_roles 查询失败', $thrown->getMessage());
    }

    // -----------------------------------------------------------------------
    // 场景 4：缓存正常命中 → 不查库（回归）
    // -----------------------------------------------------------------------

    /**
     * 合法缓存命中 → 只恢复缓存内容，不触发任何 SQL
     */
    public function testValidCacheHitSkipsDatabase(): void
    {
        $this->enablePolicyCache();

        $cached = [
            // 用不可能出现在测试库里的标识，确认模型内容「只来自缓存」
            'g' => [['user:888001', 'role:888001', 'tenant:888001']],
            'p' => [['role:888001', 'tenant:888001', 'cached_only_resource', 'read']],
        ];
        Cache::set(self::CACHE_KEY, $cached, 60);

        $statements = [];
        $manager    = $this->app()->make('db');
        $listenProperty = new \ReflectionProperty($manager, 'listen');
        $listenProperty->setAccessible(true);
        $baselineListeners = $listenProperty->getValue($manager);

        Db::listen(static function ($sql) use (&$statements): void {
            $statements[] = (string) $sql;
        });

        try {
            $model = $this->newModel();
            (new DatabaseAdapter())->loadPolicy($model);
        } finally {
            $listenProperty->setValue($manager, $baselineListeners);
        }

        $this->assertSame($cached['g'], $model->getPolicy('g', 'g'), '命中缓存时必须只恢复缓存里的角色分配');
        $this->assertSame($cached['p'], $model->getPolicy('p', 'p'), '命中缓存时必须只恢复缓存里的权限分配');
        $this->assertSame([], $statements, '命中缓存时不应产生任何 SQL，实际：' . json_encode($statements));
    }

    // -----------------------------------------------------------------------
    // 辅助方法
    // -----------------------------------------------------------------------

    /**
     * 新建一个干净的 Casbin 模型（与 DatabaseAdapterTest 同一份模型文件）
     */
    private function newModel(): Model
    {
        $model = new Model();
        $model->loadModel(__DIR__ . '/../../../../../config/casbin-model.conf');

        return $model;
    }

    /**
     * 打开策略缓存开关（两参形式：见 CasbinShouldReloadTest 对 Config::set 签名的说明）
     */
    private function enablePolicyCache(): void
    {
        Config::set(['policy_cache_enabled' => true], 'casbin');
    }

    /**
     * 把默认缓存切到「一读就抛 \TypeError」的驱动
     */
    private function useBrokenCacheStore(): void
    {
        $stores = (array) Config::get('cache.stores', []);
        $stores[self::BROKEN_STORE] = ['type' => ThrowingCacheDriver::class];

        Config::set([
            'default' => self::BROKEN_STORE,
            'stores'  => $stores,
        ], 'cache');

        $this->cacheManager()->forgetDriver(self::BROKEN_STORE);
        // 默认 store 换了名字，旧的默认驱动实例也丢掉，避免继续命中正常缓存
        $this->cacheManager()->forgetDriver((string) ($this->originalDefaultStore ?? 'file'));
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
        $manager->forgetDriver((string) $originalDefault);
    }

    /**
     * 删除策略缓存键（吞掉异常：此时默认 store 可能正是「读/写必抛」的替身）
     */
    private function forgetPolicyCache(): void
    {
        try {
            Cache::delete(self::CACHE_KEY);
        } catch (\Throwable) {
            // 故障驱动下删除也会抛，忽略即可
        }
    }

    /**
     * 取缓存管理器（`Cache` 门面的根对象）
     */
    private function cacheManager(): \think\Cache
    {
        return $this->app()->make('cache');
    }

    /**
     * 在回调执行期间捕获 ThinkPHP 日志记录
     *
     * 与 `CasbinShouldReloadTest` 同一手法：CLI 下日志通道会即时落盘并清空内存记录，
     * 因此监听 `LogWrite` 事件，而不是读 `Log::getLog()`。
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
            $app->event->remove(\think\event\LogWrite::class);
        }

        return $records;
    }

    /**
     * 按级别 + 文案片段过滤日志记录
     *
     * @param array<int, array{type: string, message: string}> $records 日志记录
     * @param string $level   级别（warning / error / info）
     * @param string $needle  文案片段
     * @return array<int, array{type: string, message: string}>
     */
    private function filterLogs(array $records, string $level, string $needle): array
    {
        return array_values(array_filter(
            $records,
            static fn (array $record): bool => $record['type'] === $level
                && str_contains($record['message'], $needle)
        ));
    }
}
