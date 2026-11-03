<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Permission\Cache;

use Infrastructure\Permission\Cache\CacheKeyIndex;
use Infrastructure\Permission\Cache\PermissionCachePurger;
use RuntimeException;
use Tests\ThinkPHPTestCase;
use think\cache\Driver as CacheDriver;
use think\contract\CacheHandlerInterface;
use think\facade\Cache;

/**
 * 清除器「store 类型」回归测试（Swoole 常驻进程下的连接池代理）
 * Permission cache purger store-type regression tests (Swoole pool proxy)
 *
 * ## 守住的回归
 *
 * 提交 `515f1a6` 里 `PermissionCachePurger` 把 store 声明成 `?think\cache\Driver`：
 *
 * ```php
 * private ?CacheDriver $store;                                 // 旧
 * public function __construct(?CacheDriver $store = null, ...) // 旧
 * ```
 *
 * 而 **Swoole 常驻进程**下 `Cache::store()` 返回 `think\swoole\pool\proxy\Store`
 * （`think\swoole\Pool` 把每个 store 都包成连接池代理，见
 * `vendor/topthink/think-swoole/src/pool/Cache::createDriver()`）。
 * 它 `extends Proxy implements CacheHandlerInterface`，**不继承** `think\cache\Driver`，
 * 于是赋值即 `TypeError`：
 *
 * ```
 * Cannot assign think\swoole\pool\proxy\Store to property
 * Infrastructure\Permission\Cache\PermissionCachePurger::$store of type ?think\cache\Driver
 * ```
 *
 * 触发点不在「清缓存」而在**写缓存**：`PermissionCacheService::writeCache()` →
 * `trackKey()` → `canEnumerateByPrefix()` → `store()` → TypeError。`trackKey()` 只
 * try/catch 了索引写入，`canEnumerateByPrefix()` 在 try 之外；而调用方的
 * `catch (\Exception)` **抓不到 `Error`**，于是「权限缓存变冷后的第一次权限检查」500。
 *
 * ## 这组用例怎么造出「Swoole 代理」
 *
 * 用**只实现 `CacheHandlerInterface`、不继承 `Driver`** 的替身：
 * - `InterfaceOnlyStoreStub`：连 `handler()` / `getCacheKey()` / `__call` 都没有
 *   —— 证明「缺高级方法」时走索引分支而不是「Call to undefined method」；
 * - `ProxyShapedStoreStub`：接口方法显式转发 + `__call()` 兜底转发，
 *   与 `think\swoole\pool\Proxy` 同构 —— 证明「代理形态的 store 仍能走 SCAN」。
 *
 * 为什么不直接 `new \think\swoole\pool\proxy\Store(...)`：它的构造函数要真连接池
 * （`Smf\ConnectionPool\ConnectionPool` + `Coroutine` 上下文），在 phpunit 里造不出来；
 * 本组用例验证的是**类型契约与能力探测**，用同构替身比伪造一个 Swoole 运行时更可靠。
 * 真实链路的端到端取证（DELETE 缓存 + 连续 6 次 GET）见交付报告。
 *
 * @package Tests\Unit\Infrastructure\Permission\Cache
 */
class PermissionCachePurgerStoreTypeTest extends ThinkPHPTestCase
{
    /**
     * 本用例使用的逻辑缓存键
     */
    private const L2_USER_A = 'l2:permission:tenant:1:user:900001:permissions';
    private const L2_USER_B = 'l2:permission:tenant:1:user:900002:permissions';

    /**
     * 非权限键（旁观者，任何情况下都不该被删）
     */
    private const BYSTANDER = 'session:purger-store-type-test';

    protected function setUp(): void
    {
        parent::setUp();

        // 键索引落在真实（测试环境 File）缓存里，用例之间必须清干净，否则前一条用例登记的键会串味
        Cache::delete(CacheKeyIndex::INDEX_KEY);
    }

    protected function tearDown(): void
    {
        Cache::delete(CacheKeyIndex::INDEX_KEY);

        parent::tearDown();
    }

    /**
     * 场景 1（核心回归）：store 不是 `think\cache\Driver`，但实现了 `CacheHandlerInterface`
     *
     * 改前：构造本类即 `TypeError`（属性类型不兼容）。
     * 改后：构造、`driverName()`、能力探测全部正常，且**不抛任何异常**。
     */
    public function testAcceptsStoreImplementingInterfaceButNotCacheDriver(): void
    {
        $store = new InterfaceOnlyStoreStub();

        // 前置断言：替身必须「不是 Driver、但实现了接口」——否则这条用例测不到回归点
        $this->assertNotInstanceOf(CacheDriver::class, $store);
        $this->assertInstanceOf(CacheHandlerInterface::class, $store);

        $purger = new PermissionCachePurger($store);

        $this->assertSame(
            'InterfaceOnlyStoreStub',
            $purger->driverName(),
            'driverName() 取的是 store 的类名，替身应当原样返回'
        );
        $this->assertFalse(
            $purger->canEnumerateByPrefix(),
            '没有 handler() 的 store 必须判为「不可枚举」，而不是抛错'
        );
    }

    /**
     * 场景 2（回归的真正触发点）：`trackKey()` 在非 Driver store 上不得抛异常
     *
     * 现场 500 的调用链是「写缓存 → trackKey → canEnumerateByPrefix → store()」，
     * 因此这条断言比「构造不抛」更贴近缺陷：缓存变冷后的第一次权限检查就走这里。
     */
    public function testTrackKeyDoesNotThrowForStoreThatIsNotCacheDriver(): void
    {
        $purger = new PermissionCachePurger(new InterfaceOnlyStoreStub());

        $purger->trackKey(self::L2_USER_A);

        $this->assertSame(
            [self::L2_USER_A],
            (new CacheKeyIndex())->all(),
            '不可枚举驱动下 trackKey() 必须真的把键登记进索引（索引是索引策略的唯一依据）'
        );
    }

    /**
     * 场景 3：不支持 `handler()` / `getCacheKey()` 时，`purge()` 安全退化为索引分支
     *
     * 关键是「退化」而不是「报错」：缺高级方法属于正常形态（File 驱动就是这样），
     * 不能变成 `Call to undefined method`，也不能硬拼一个扫不到键的 SCAN 模式。
     */
    public function testPurgeFallsBackToIndexBranchWhenAdvancedMethodsAreMissing(): void
    {
        $store = new InterfaceOnlyStoreStub([
            self::L2_USER_A => ['dashboard:view'],
            self::L2_USER_B => ['dashboard:view'],
            self::BYSTANDER => 'keep-session',
        ]);

        $purger = new PermissionCachePurger($store);

        // 走真实写入路径登记键（与 PermissionCacheService::writeCache() 一致）
        $purger->trackKey(self::L2_USER_A);
        $purger->trackKey(self::L2_USER_B);
        $purger->trackKey(self::BYSTANDER);

        $report = $purger->purge([PermissionCachePurger::PREFIX_L2_PERMISSION]);

        $this->assertSame(
            'index',
            $report['strategy'],
            '缺少 handler()/getCacheKey() 时必须走索引策略，不得抛异常到调用方'
        );
        $this->assertSame(2, $report['deleted'], '只删命中 l2:permission: 前缀的两个键');
        $this->assertArrayNotHasKey(self::L2_USER_A, $store->data, '命中前缀的键必须被删');
        $this->assertArrayNotHasKey(self::L2_USER_B, $store->data, '命中前缀的键必须被删');
        $this->assertArrayHasKey(self::BYSTANDER, $store->data, '白名单外的键必须原地存活（R1 的安全边界）');
    }

    /**
     * 场景 4（Swoole 代理形态）：非 Driver 但能转发 `handler()` 的 store 仍走 SCAN
     *
     * 改前的 `if (!$store instanceof RedisDriver) return null;` 会把这类 store
     * 误判成「不可枚举」，于是 Redis 下的清除会静默退化成索引策略
     * （Redis 的索引恒为空 → 一个键都删不掉，只在日志里留一条 warning）。
     */
    public function testProxyShapedStoreStillUsesScanPath(): void
    {
        $physicalA = 'alkaid:' . self::L2_USER_A;
        $physicalB = 'alkaid:' . self::L2_USER_B;
        $bystander = 'alkaid:session:keep-me';

        $handler = new FakePhpRedisHandler([[$physicalA, $physicalB, $bystander]]);
        $proxy = new ProxyShapedStoreStub(new RedisForwardTarget($handler));

        $purger = new PermissionCachePurger($proxy);

        $this->assertTrue(
            $purger->canEnumerateByPrefix(),
            '能把 handler() 转发到 phpredis 的代理必须判为「可枚举」'
        );

        $report = $purger->purge([PermissionCachePurger::PREFIX_L2_PERMISSION]);

        $this->assertSame('scan', $report['strategy'], '有 phpredis 客户端时必须走 SCAN 策略');
        $this->assertSame(['alkaid:l2:*'], $handler->scanPatterns, 'SCAN 模式必须拼上物理前缀');
        $this->assertSame(2, $report['deleted']);
        $this->assertSame(
            [[$physicalA, $physicalB]],
            $handler->deleteCalls,
            '只允许删除命中完整前缀的物理键，旁支键不得被删'
        );
    }

    /**
     * 场景 5：`handler()` 抛错时降级为索引策略，绝不把异常抛给调用方
     *
     * 真实触发形态：Swoole 连接池里的连接已被释放（`Connection already has been released!`）
     * 或 Redis 临时不可用。此时清缓存「尽力而为」，不能连带把权限检查打成 500。
     */
    public function testHandlerFailureDegradesToIndexInsteadOfThrowing(): void
    {
        $store = new ProxyShapedStoreStub(new FailingHandlerTarget('Connection already has been released!'));
        $purger = new PermissionCachePurger($store);

        $purger->trackKey(self::L2_USER_A);

        $report = $purger->purge([PermissionCachePurger::PREFIX_L2_PERMISSION]);

        $this->assertSame('index', $report['strategy'], 'handler() 抛错必须降级为索引策略');
        $this->assertFalse($purger->canEnumerateByPrefix());
    }

    /**
     * 场景 6：取不到物理前缀时**不做 SCAN**（否则 MATCH 拼不对，一条都匹配不到还误报成功）
     */
    public function testSkipsScanWhenPhysicalPrefixIsUnavailable(): void
    {
        $handler = new FakePhpRedisHandler([['alkaid:' . self::L2_USER_A]]);
        // 只有 handler()，没有 getCacheKey() —— 代理转发会得到「Call to undefined method」
        $store = new ProxyShapedStoreStub(new HandlerOnlyTarget($handler));

        $purger = new PermissionCachePurger($store);
        $report = $purger->purge([PermissionCachePurger::PREFIX_L2_PERMISSION]);

        $this->assertSame('index', $report['strategy'], '取不到物理前缀必须放弃 SCAN、退化为索引策略');
        $this->assertSame([], $handler->scanPatterns, '取不到前缀时不允许发起 SCAN');
        $this->assertSame([], $handler->deleteCalls, '取不到前缀时不允许用拼错的物理键去 DEL');
    }
}

/**
 * 只实现 `CacheHandlerInterface` 的 store 替身（**刻意不继承** `think\cache\Driver`）
 *
 * 两个原因：
 * 1. 模拟 Swoole 连接池代理的类型形态（接口相同、实现不同）；
 * 2. 刻意**不提供** `handler()` / `getCacheKey()` / `__call()`，
 *    用来验证「缺高级方法」时清除器走安全分支而不是直接炸。
 */
final class InterfaceOnlyStoreStub implements CacheHandlerInterface
{
    /**
     * 替身自己的「存储」
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * @param array<string, mixed> $initial 初始键值
     */
    public function __construct(array $initial = [])
    {
        $this->data = $initial;
    }

    public function has($name): bool
    {
        return array_key_exists((string) $name, $this->data);
    }

    public function get($name, $default = null): mixed
    {
        return $this->data[(string) $name] ?? $default;
    }

    public function set($name, $value, $expire = null): bool
    {
        $this->data[(string) $name] = $value;

        return true;
    }

    public function inc($name, $step = 1)
    {
        $value = (int) ($this->data[(string) $name] ?? 0) + (int) $step;
        $this->data[(string) $name] = $value;

        return $value;
    }

    public function dec($name, $step = 1)
    {
        return $this->inc($name, -1 * (int) $step);
    }

    public function delete($name): bool
    {
        $key = (string) $name;
        $existed = array_key_exists($key, $this->data);
        unset($this->data[$key]);

        return $existed;
    }

    public function clear(): bool
    {
        $this->data = [];

        return true;
    }

    public function clearTag($keys)
    {
        // 替身不支持缓存标签：本组用例也用不到
        return null;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function tag($name)
    {
        throw new RuntimeException('替身不支持缓存标签');
    }

    public function pull($name)
    {
        $value = $this->get($name);
        $this->delete($name);

        return $value;
    }

    public function remember($name, $value, $expire = null)
    {
        if ($this->has($name)) {
            return $this->get($name);
        }

        $this->set($name, $value, $expire);

        return $value;
    }
}

/**
 * 代理形态的 store 替身（与 `think\swoole\pool\proxy\Store` 同构）
 *
 * - 实现 `CacheHandlerInterface`，接口方法**逐个显式转发**到内部目标；
 * - 未声明的 `handler()` / `getCacheKey()` 靠 `__call()` 兜底转发
 *   —— 这正是 `think\swoole\pool\Proxy::__call()` 的做法，也是本组用例要覆盖的形态。
 */
final class ProxyShapedStoreStub implements CacheHandlerInterface
{
    /**
     * @param object $inner 真实目标（转发对象）
     */
    public function __construct(private object $inner)
    {
    }

    /**
     * 兜底转发：未声明的方法一律转给内部目标
     *
     * 目标没有该方法时 PHP 会抛 `Error: Call to undefined method`，与 Swoole 代理的行为一致 ——
     * 清除器必须靠能力探测 + try/catch 兜住它。
     *
     * @param string $method 方法名
     * @param array<mixed> $arguments 参数
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        return $this->inner->{$method}(...$arguments);
    }

    public function has($name): bool
    {
        return $this->inner->has($name);
    }

    public function get($name, $default = null): mixed
    {
        return $this->inner->get($name, $default);
    }

    public function set($name, $value, $expire = null): bool
    {
        return $this->inner->set($name, $value, $expire);
    }

    public function inc($name, $step = 1)
    {
        return $this->inner->inc($name, $step);
    }

    public function dec($name, $step = 1)
    {
        return $this->inner->dec($name, $step);
    }

    public function delete($name): bool
    {
        return $this->inner->delete($name);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function clearTag($keys)
    {
        return $this->inner->clearTag($keys);
    }

    public function getMultiple($keys, $default = null): iterable
    {
        return $this->inner->getMultiple($keys, $default);
    }

    public function setMultiple($values, $ttl = null): bool
    {
        return $this->inner->setMultiple($values, $ttl);
    }

    public function deleteMultiple($keys): bool
    {
        return $this->inner->deleteMultiple($keys);
    }

    public function tag($name)
    {
        return $this->inner->tag($name);
    }

    public function pull($name)
    {
        return $this->inner->pull($name);
    }

    public function remember($name, $value, $expire = null)
    {
        return $this->inner->remember($name, $value, $expire);
    }
}

/**
 * 转发目标基类：提供 `delete()` 等基础语义（代理替身的接口方法需要它）
 */
abstract class ForwardTargetStub
{
    /**
     * 通过接口 `delete()` 删掉的逻辑键
     *
     * @var array<string>
     */
    public array $deletedKeys = [];

    public function has($name): bool
    {
        return false;
    }

    public function get($name, $default = null): mixed
    {
        return $default;
    }

    public function set($name, $value, $expire = null): bool
    {
        return true;
    }

    public function inc($name, $step = 1)
    {
        return 1;
    }

    public function dec($name, $step = 1)
    {
        return 0;
    }

    public function delete($name): bool
    {
        $this->deletedKeys[] = (string) $name;

        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function clearTag($keys)
    {
        return null;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        return [];
    }

    public function setMultiple($values, $ttl = null): bool
    {
        return true;
    }

    public function deleteMultiple($keys): bool
    {
        return true;
    }

    public function tag($name)
    {
        throw new RuntimeException('替身不支持缓存标签');
    }

    public function pull($name)
    {
        return null;
    }

    public function remember($name, $value, $expire = null)
    {
        return $value;
    }
}

/**
 * 「Redis 目标」：`handler()` 给 phpredis 客户端，`getCacheKey()` 给物理前缀
 */
final class RedisForwardTarget extends ForwardTargetStub
{
    /**
     * @param FakePhpRedisHandler $handler phpredis 替身
     */
    public function __construct(private FakePhpRedisHandler $handler)
    {
    }

    /**
     * 模拟 `think\cache\Driver::handler()`
     *
     * @return object
     */
    public function handler()
    {
        return $this->handler;
    }

    /**
     * 模拟 `think\cache\Driver::getCacheKey()`
     *
     * @param string $name 逻辑键
     * @return string
     */
    public function getCacheKey($name): string
    {
        return 'alkaid:' . (string) $name;
    }
}

/**
 * 「取客户端就失败」的目标：模拟连接池连接已释放 / Redis 不可用
 */
final class FailingHandlerTarget extends ForwardTargetStub
{
    /**
     * @param string $message 异常信息
     */
    public function __construct(private string $message)
    {
    }

    /**
     * 模拟 `think\cache\Driver::handler()` 抛出
     *
     * @return object
     */
    public function handler()
    {
        throw new RuntimeException($this->message);
    }

    /**
     * 模拟 `think\cache\Driver::getCacheKey()`
     *
     * @param string $name 逻辑键
     * @return string
     */
    public function getCacheKey($name): string
    {
        return 'alkaid:' . (string) $name;
    }
}

/**
 * 「只有 handler()、没有 getCacheKey()」的目标：验证「取不到物理前缀就不做 SCAN」
 */
final class HandlerOnlyTarget extends ForwardTargetStub
{
    /**
     * @param FakePhpRedisHandler $handler phpredis 替身
     */
    public function __construct(private FakePhpRedisHandler $handler)
    {
    }

    /**
     * 模拟 `think\cache\Driver::handler()`
     *
     * @return object
     */
    public function handler()
    {
        return $this->handler;
    }
}

/**
 * phpredis 替身（必须是 `\Redis` 实例，清除器只认 phpredis 语义）
 *
 * 不调用 `\Redis::__construct()`：没有真实连接也能构造对象，
 * 本替身只用 `scan()` / `del()` 两个方法的调用序列取证。
 */
final class FakePhpRedisHandler extends \Redis
{
    /**
     * 已发生的 SCAN 模式（按调用顺序）
     *
     * @var array<string>
     */
    public array $scanPatterns = [];

    /**
     * 已发生的 DEL 分块
     *
     * @var array<int, array<string>>
     */
    public array $deleteCalls = [];

    /**
     * 每页返回的键（游标用页号模拟，0 表示迭代结束）
     *
     * @var array<int, array<string>>
     */
    private array $pages;

    /**
     * @param array<int, array<string>> $pages 每页返回的物理键
     */
    public function __construct(array $pages = [])
    {
        $this->pages = array_values($pages);
    }

    /**
     * 模拟 `\Redis::scan()`
     *
     * @param string|int|null $iterator 游标（引用）
     * @param string|null $pattern 匹配模式
     * @param int $count COUNT 建议值
     * @param string|null $type TYPE 过滤（phpredis 扩展）
     * @return array<int, string>|false
     */
    public function scan(string|int|null &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|false
    {
        $this->scanPatterns[] = (string) $pattern;

        $index = $iterator === null ? 0 : (int) $iterator;
        $keys = $this->pages[$index] ?? [];
        $next = $index + 1;
        $iterator = isset($this->pages[$next]) ? $next : 0;

        return $keys === [] ? false : $keys;
    }

    /**
     * 模拟 `\Redis::del(array $keys)`
     *
     * 父类签名是 `del(array|string $key, string ...$other_keys)`（phpredis 支持多键），
     * 这里保持完全一致的形参，避免「覆盖内部类方法签名不兼容」的致命错误。
     *
     * @param array<string>|string $key 待删键
     * @param string ...$other_keys 额外的键（phpredis 的多键形态）
     * @return \Redis|int|false
     */
    public function del(array|string $key, string ...$other_keys): \Redis|int|false
    {
        $keys = is_array($key) ? array_values($key) : [$key];
        foreach ($other_keys as $otherKey) {
            $keys[] = $otherKey;
        }

        $this->deleteCalls[] = $keys;

        return count($keys);
    }
}
