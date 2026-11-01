<?php

declare(strict_types=1);

namespace Tests\Integration\Permission;

use Infrastructure\Permission\Cache\PermissionCacheInvalidator;
use Infrastructure\Permission\Cache\PermissionCachePurger;
use Infrastructure\Permission\Service\PermissionCacheService;
use InvalidArgumentException;
use Tests\ThinkPHPTestCase;
use think\cache\driver\File as FileDriver;
use think\facade\Cache;

/**
 * 权限缓存「按前缀清除」测试（R1 回归防护）
 * Permission cache prefix-scoped purge tests (R1 regression guard)
 *
 * ## 守住的缺陷
 *
 * 历史实现 `PermissionCacheService::clearAllCache()` 用 `Cache::clear()`：
 * - Redis 驱动 → `FLUSHDB`，**整个库**（含 `session:*` 会话、`rl:*` 限流计数）被清空；
 * - File 驱动 → `rmdir(缓存目录)`，低代码/表单/casbin 策略缓存一并被删。
 *
 * 现在改为「严格前缀白名单 + 驱动自适应」：
 * Redis 走 `SCAN` + 批量 `DEL`，其它驱动走键索引精确删除，
 * **任何情况都不退化成 `Cache::clear()`**。
 *
 * ## 本测试跑在哪个驱动上
 *
 * 测试环境默认驱动固定为 `File`（`Tests\ThinkPHPTestCase` 把 `cache.default` 钉成 `file`，
 * 前缀由 `CACHE_FILE_PREFIX=test` 注入）—— 正好覆盖「不可枚举驱动」这条最难的路径。
 * 真实 Redis（SCAN）路径的取证见交付报告的手工脚本输出，其算法由
 * `tests/Unit/Infrastructure/Permission/Cache/RedisScanPurgerTest` 用替身逐条断言。
 *
 * @package Tests\Integration\Permission
 */
class PermissionCachePurgeTest extends ThinkPHPTestCase
{
    /**
     * 测试用用户 / 租户 ID（与其它测试的夹具错开）
     */
    private const TENANT_ID = 990301;
    private const USER_ID = 990302;
    private const OTHER_USER_ID = 990303;

    /**
     * 本用例写入的非权限键（必须存活）
     *
     * @var array<string, string>
     */
    private const BYSTANDER_KEYS = [
        'session' => 'session:purge-test-sid',
        'ratelimit' => 'rl:dev:user:purge-test:token_bucket',
        'lowcode' => 'lowcode:collection:990301:purge_test',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupKeys();

        // 造「旁观者」键：会话 / 限流 / 低代码 —— 它们与权限缓存同库，是最容易被误删的邻居
        foreach (self::BYSTANDER_KEYS as $label => $key) {
            Cache::set($key, 'keep-' . $label, 300);
        }

        $this->purger()->purge([]);
    }

    protected function tearDown(): void
    {
        $this->cleanupKeys();

        parent::tearDown();
    }

    /**
     * 清理本用例可能残留的键
     */
    private function cleanupKeys(): void
    {
        foreach (self::BYSTANDER_KEYS as $key) {
            Cache::delete($key);
        }

        // 权限缓存：直接用清除器收尾（同时清空索引）
        try {
            $this->purger()->purge([
                PermissionCachePurger::PREFIX_L1_PERMISSION,
                PermissionCachePurger::PREFIX_L2_PERMISSION,
                PermissionCachePurger::PREFIX_PERMISSION,
            ]);
        } catch (\Throwable $e) {
            // 收尾失败不该掩盖真实断言
        }

        Cache::delete(\Infrastructure\Permission\Cache\CacheKeyIndex::INDEX_KEY);
    }

    /**
     * 取清除器（默认为测试环境的 File 驱动）
     *
     * @return PermissionCachePurger
     */
    private function purger(): PermissionCachePurger
    {
        return new PermissionCachePurger();
    }

    /**
     * 预热某个用户的 L1 / L2 / 单权限检查缓存
     *
     * @param int $userId
     * @return void
     */
    private function warmPermissionCache(int $userId): void
    {
        $service = new PermissionCacheService();

        $service->getUserPermissions($userId, self::TENANT_ID, static function () {
            return ['dashboard:view'];
        });

        $service->hasPermission($userId, self::TENANT_ID, 'dashboard:view', static function () {
            return true;
        });
    }

    /**
     * 场景 1：清除全部权限缓存后 —— 权限键被删，会话/限流/低代码键必须存活
     */
    public function testClearAllCacheDeletesPermissionKeysButKeepsBystanders(): void
    {
        $this->warmPermissionCache(self::USER_ID);

        $l1Key = 'l1:permission:tenant:' . self::TENANT_ID . ':user:' . self::USER_ID . ':permissions';
        $l2Key = 'l2:permission:tenant:' . self::TENANT_ID . ':user:' . self::USER_ID . ':permissions';
        $checkKey = 'permission:tenant:' . self::TENANT_ID . ':user:' . self::USER_ID . ':check:' . md5('dashboard:view');

        // 前置断言：四类键都真的存在（否则「删掉了」可能是假绿）
        $this->assertNotNull(Cache::get($l1Key), 'L1 权限缓存应已写入');
        $this->assertNotNull(Cache::get($l2Key), 'L2 权限缓存应已写入');
        $this->assertNotNull(Cache::get($checkKey), '单权限检查缓存应已写入');
        foreach (self::BYSTANDER_KEYS as $label => $key) {
            $this->assertNotNull(Cache::get($key), "[{$label}] 旁观者键应已写入");
        }

        (new PermissionCacheService())->clearAllCache();

        // 权限键：必须被删
        $this->assertNull(Cache::get($l1Key), 'L1 权限缓存必须被清除');
        $this->assertNull(Cache::get($l2Key), 'L2 权限缓存必须被清除');
        $this->assertNull(Cache::get($checkKey), '单权限检查缓存必须被清除');

        // 旁观者键：必须完好（这就是 R1 的核心安全断言）
        foreach (self::BYSTANDER_KEYS as $label => $key) {
            $this->assertSame(
                'keep-' . $label,
                Cache::get($key),
                "[{$label}] 非权限缓存绝不能被一起清掉（R1）"
            );
        }
    }

    /**
     * 场景 2：幂等 —— 连跑两次不抛异常，第二次删除 0 个键
     */
    public function testClearAllCacheIsIdempotent(): void
    {
        $this->warmPermissionCache(self::USER_ID);

        $service = new PermissionCacheService();

        $service->clearAllCache();
        $first = $this->purger()->purge([
            PermissionCachePurger::PREFIX_L1_PERMISSION,
            PermissionCachePurger::PREFIX_L2_PERMISSION,
            PermissionCachePurger::PREFIX_PERMISSION,
        ]);

        $service->clearAllCache();
        $second = $this->purger()->purge([
            PermissionCachePurger::PREFIX_L1_PERMISSION,
            PermissionCachePurger::PREFIX_L2_PERMISSION,
            PermissionCachePurger::PREFIX_PERMISSION,
        ]);

        $this->assertSame(0, $first['deleted'], '第一次重复清除时已无可删的键');
        $this->assertSame(0, $second['deleted'], '第二次重复清除必须同样为 0，且不抛异常');

        // 旁观者依旧存活
        $this->assertSame('keep-session', Cache::get(self::BYSTANDER_KEYS['session']));
    }

    /**
     * 场景 3：白名单拒绝 —— 非权限前缀（session / 限流 / 空串）一律拒绝执行
     */
    public function testRejectsPrefixesOutsideWhitelist(): void
    {
        $forbidden = [
            'session:' => '会话键',
            'rl:' => '限流键',
            'lowcode:' => '低代码键',
            '' => '空前缀（等价于清空全库）',
        ];

        foreach ($forbidden as $prefix => $label) {
            try {
                $this->purger()->purge([$prefix]);
                $this->fail("前缀「{$prefix}」（{$label}）必须被拒绝");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('拒绝', $e->getMessage(), "[{$label}] 异常信息应说明拒绝原因");
            }
        }

        // 被拒绝之后，旁观者键仍然完好
        $this->assertSame('keep-session', Cache::get(self::BYSTANDER_KEYS['session']));
        $this->assertSame('keep-ratelimit', Cache::get(self::BYSTANDER_KEYS['ratelimit']));
    }

    /**
     * 场景 4：精确删除同样受白名单约束（一个都不能删）
     */
    public function testDeleteKeysRejectsKeysOutsideWhitelist(): void
    {
        $purger = $this->purger();

        try {
            $purger->deleteKeys([
                'l1:permission:tenant:1:user:1:permissions',
                'session:purge-test-sid', // 混入一个越界键
            ]);
            $this->fail('混入白名单外的键时必须整批拒绝');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('session:purge-test-sid', $e->getMessage());
        }

        // 整批拒绝 = 连合法的那个也没删
        $this->assertSame('keep-session', Cache::get(self::BYSTANDER_KEYS['session']));
    }

    /**
     * 场景 5：单用户清除只影响该用户（检查缓存按用户前缀清）
     */
    public function testClearUserCacheOnlyAffectsTargetUser(): void
    {
        $this->warmPermissionCache(self::USER_ID);
        $this->warmPermissionCache(self::OTHER_USER_ID);

        $otherCheckKey = 'permission:tenant:' . self::TENANT_ID . ':user:' . self::OTHER_USER_ID . ':check:' . md5('dashboard:view');
        $targetCheckKey = 'permission:tenant:' . self::TENANT_ID . ':user:' . self::USER_ID . ':check:' . md5('dashboard:view');

        $this->assertNotNull(Cache::get($otherCheckKey));
        $this->assertNotNull(Cache::get($targetCheckKey));

        (new PermissionCacheService())->clearUserCache(self::USER_ID, self::TENANT_ID);

        $this->assertNull(Cache::get($targetCheckKey), '目标用户的检查缓存必须被清除');
        $this->assertNotNull(Cache::get($otherCheckKey), '其它用户的检查缓存不能被误删');
        $this->assertSame('keep-ratelimit', Cache::get(self::BYSTANDER_KEYS['ratelimit']));
    }

    /**
     * 场景 6：File 驱动走索引策略，且报告里明确写出 driver/strategy
     */
    public function testFileDriverUsesIndexStrategy(): void
    {
        $this->warmPermissionCache(self::USER_ID);

        $report = $this->purger()->purge([PermissionCachePurger::PREFIX_L2_PERMISSION]);

        $this->assertSame('File', $report['driver'], '测试环境默认驱动是 File');
        $this->assertSame(
            'index',
            $report['strategy'],
            'File 驱动键名被 md5 化，无法按前缀枚举，必须走索引策略'
        );
        $this->assertGreaterThan(0, $report['deleted'], '至少应删掉一条 L2 权限缓存');
    }

    /**
     * 场景 7：清缓存失败**不影响业务**（不抛异常）
     */
    public function testPurgeFailureDoesNotThrow(): void
    {
        // 注入一个「delete() 必抛」的 File 驱动
        $throwingStore = $this->createThrowingFileDriver();
        $failingPurger = new PermissionCachePurger($throwingStore);

        // 先让索引里有可命中的键（走真实写入路径，索引才认得）
        $this->warmPermissionCache(self::USER_ID);

        // (a) 清除器本身会抛（由调用方决定如何处理）
        $this->expectException(\RuntimeException::class);
        $failingPurger->purge([PermissionCachePurger::PREFIX_L2_PERMISSION]);
    }

    /**
     * 场景 8：`clearAllCache()` / `invalidateUser()` 内部吞掉缓存故障，只记 warning
     */
    public function testCacheFailureIsSwallowedByServiceAndInvalidator(): void
    {
        $throwingStore = $this->createThrowingFileDriver();
        $failingPurger = new PermissionCachePurger($throwingStore);

        $this->warmPermissionCache(self::USER_ID);

        // 子类化以便注入会抛异常的清除器
        $service = new class ($failingPurger) extends PermissionCacheService {
            public function __construct(private PermissionCachePurger $injectedPurger)
            {
            }

            protected function purger(): PermissionCachePurger
            {
                return $this->injectedPurger;
            }
        };

        // 不抛异常即为通过（内部 catch + Log::warning）
        $service->clearAllCache();
        $service->clearUserCache(self::USER_ID, self::TENANT_ID);

        $report = (new PermissionCacheInvalidator($failingPurger))
            ->invalidateUser(self::USER_ID, self::TENANT_ID);

        $this->assertFalse($report['success'], '失效失败必须如实反映在返回值里');
        $this->assertSame(0, $report['deleted']);
        $this->assertSame('failed', $report['strategy']);

        // 旁观者键始终没被动过
        $this->assertSame('keep-session', Cache::get(self::BYSTANDER_KEYS['session']));
    }

    /**
     * 场景 9：批量失效阈值 —— 超过 100 个用户时升级为整族清除
     */
    public function testBulkInvalidationEscalatesBeyondThreshold(): void
    {
        $pairs = [];
        for ($i = 0; $i < PermissionCacheInvalidator::BULK_USER_THRESHOLD + 5; $i++) {
            $pairs[] = ['user_id' => 800000 + $i, 'tenant_id' => self::TENANT_ID];
        }

        $report = (new PermissionCacheInvalidator())->invalidateUsers($pairs);

        $this->assertTrue($report['success']);
        $this->assertArrayHasKey(
            'escalated_from_users',
            $report,
            '超过阈值必须升级为「整族清除」，而不是逐用户拼键'
        );
        $this->assertSame(count($pairs), $report['escalated_from_users']);
    }

    /**
     * 造一个「delete() 必抛」的 File 驱动（模拟缓存层故障）
     *
     * @return FileDriver
     */
    private function createThrowingFileDriver(): FileDriver
    {
        return new class ($this->app(), ['path' => $this->app()->getRuntimePath() . 'cache-throwing/']) extends FileDriver {
            public function delete($name): bool
            {
                throw new \RuntimeException('模拟缓存故障：delete() 失败');
            }
        };
    }
}
