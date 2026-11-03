<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Cache;

use InvalidArgumentException;
use think\contract\CacheHandlerInterface;
use think\facade\Cache;
use think\facade\Log;

/**
 * 权限缓存前缀清除器（驱动自适应）
 * Permission cache prefix purger (driver-adaptive)
 *
 * ## 背景（R1 缺陷）
 *
 * 历史实现用 `Cache::clear()` 清「全部权限缓存」，而 ThinkPHP 的 Redis 驱动把它实现成
 * `flushDB()`（`think/cache/driver/Redis.php:206`）—— **清空整个 Redis 库**，
 * 连同 `session:*`（与缓存同库）和限流计数一起清掉。File 驱动则是 `rmdir(缓存目录)`，
 * 把低代码/表单/casbin 策略缓存一并删掉。清「权限缓存」不该有这种爆炸半径。
 *
 * ## 安全边界：严格前缀白名单
 *
 * 只有以下前缀族内的键允许被删除，其它一律抛 `InvalidArgumentException` 拒绝执行：
 *
 * | 前缀族 | 说明 |
 * | --- | --- |
 * | `l1:permission:` | L1 权限集缓存（60s） |
 * | `l2:permission:` | L2 权限集缓存（300s） |
 * | `permission:`    | 权限集（无级别前缀时）/ 单权限检查缓存 |
 * | `casbin:check:`  | Casbin 单次鉴权结果缓存 |
 * | `casbin:policy:` | Casbin 全量策略缓存 |
 *
 * `session:`、`rl:`（限流）、`lowcode:`、`casbin_last_reload` 等**全都不在**族内，
 * 因此结构上不可能被误删 —— 这正是 `tests/Integration/Permission/PermissionCachePurgeTest`
 * 断言「session/限流键存活」的依据。
 *
 * ## 驱动适配
 *
 * - **Redis（phpredis）**：`Cache::store()->handler()` 拿到底层客户端，
 *   `SCAN` + 批量 `DEL`（见 `RedisScanPurger`）。**不用 `KEYS`**（阻塞）、**不用 `FLUSHDB`**。
 * - **File / 其它不可枚举驱动**：键名被 `md5()` 后落盘，无法反推，改删
 *   `CacheKeyIndex` 里记录过的键（逐键 `Cache::delete()`）。
 * - **任何情况都不退化为 `Cache::clear()`**：那是本轮要消灭的东西。
 *   无法枚举且索引为空时，只记 warning 并返回 0，绝不放宽成清全库。
 *
 * ## Swoole 常驻进程：为什么类型是 `CacheHandlerInterface` 而不是 `think\cache\Driver`
 *
 * `Cache::store()` 在 **Swoole 常驻进程**下返回 `think\swoole\pool\proxy\Store`
 * （`think\swoole\pool\Cache::createDriver()` 把每个 store 都包成连接池代理）。
 * 它 `extends Proxy implements CacheHandlerInterface`，**不继承** `think\cache\Driver`，
 * 未声明的方法全靠 `Proxy::__call()` 转发给池里的真实驱动。
 * 因此属性/参数声明成 `?think\cache\Driver` 会在「在 Swoole 里构造本类」时直接
 * `TypeError`（`Cannot assign think\swoole\pool\proxy\Store to property ... of type ?think\cache\Driver`），
 * 表现为「权限缓存被清掉之后的第一批权限检查偶发 500」。
 *
 * 两者的唯一共同契约是 {@see CacheHandlerInterface}：接口内的方法（`delete()` 等）一定可用；
 * 而 `getCacheKey()` / `handler()` 是 `Driver` 的「高级方法」，代理只能靠 `__call` 转发，
 * 所以**使用前必须能力探测**（{@see self::supports()}），探测不到就走安全分支：
 * 取不到物理前缀就不做 SCAN（MATCH 模式拼不对等于白扫），退化为索引策略；
 * 取不到 Redis 客户端就当「不可枚举」。任何一步都不把异常抛给调用方。
 *
 * @package Infrastructure\Permission\Cache
 */
final class PermissionCachePurger
{
    /**
     * L1 权限集缓存前缀
     */
    public const PREFIX_L1_PERMISSION = 'l1:permission:';

    /**
     * L2 权限集缓存前缀
     */
    public const PREFIX_L2_PERMISSION = 'l2:permission:';

    /**
     * 权限集 / 单权限检查缓存前缀
     */
    public const PREFIX_PERMISSION = 'permission:';

    /**
     * Casbin 单次鉴权结果缓存前缀
     */
    public const PREFIX_CASBIN_CHECK = 'casbin:check:';

    /**
     * Casbin 全量策略缓存前缀
     */
    public const PREFIX_CASBIN_POLICY = 'casbin:policy:';

    /**
     * 严格前缀白名单（唯一合法清单）
     *
     * 新增前缀族必须先在这里登记，否则清除请求会被直接拒绝 —— 这是「默认拒绝」，
     * 而不是「默认允许 + 事后审计」。
     */
    public const ALLOWED_PREFIXES = [
        self::PREFIX_L1_PERMISSION,
        self::PREFIX_L2_PERMISSION,
        self::PREFIX_PERMISSION,
        self::PREFIX_CASBIN_CHECK,
        self::PREFIX_CASBIN_POLICY,
    ];

    /**
     * 精确删除时分块大小
     */
    private const DELETE_CHUNK_SIZE = 500;

    /**
     * 缓存 store（默认为当前默认 store）
     *
     * 类型是**共同接口**，不是 `think\cache\Driver`：Swoole 常驻进程下
     * `Cache::store()` 返回 `think\swoole\pool\proxy\Store`（实现本接口但不继承 `Driver`），
     * 详见类注释「Swoole 常驻进程」一节。
     */
    private ?CacheHandlerInterface $store;

    /**
     * 非可枚举驱动的键索引
     */
    private CacheKeyIndex $index;

    /**
     * 当前驱动是否可按前缀枚举（懒计算并缓存）
     */
    private ?bool $enumerable = null;

    /**
     * @param CacheHandlerInterface|null $store 缓存 store（默认 `Cache::store()`；Swoole 下是连接池代理）
     * @param CacheKeyIndex|null $index 键索引（默认新建）
     */
    public function __construct(?CacheHandlerInterface $store = null, ?CacheKeyIndex $index = null)
    {
        $this->store = $store;
        $this->index = $index ?? new CacheKeyIndex();
    }

    /**
     * 当前缓存驱动短名（用于日志与报告）
     *
     * @return string
     */
    public function driverName(): string
    {
        $class = get_class($this->store());

        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /**
     * 当前驱动能否按前缀枚举键
     *
     * 只有 phpredis 才能（`\Redis::scan()`）。Predis 的前缀由客户端自身处理、
     * `getCacheKey('')` 返回空串，走扫描路径会匹配不到键，因此也归入「不可枚举」，
     * 退化为索引删除而不是硬凑一个未验证的模式。
     *
     * @return bool
     */
    public function canEnumerateByPrefix(): bool
    {
        if ($this->enumerable === null) {
            $this->enumerable = $this->redisHandler() !== null;
        }

        return $this->enumerable;
    }

    /**
     * 记录一个「已写入」的缓存键（供不可枚举驱动按前缀清理时使用）
     *
     * Redis 下是空操作（`canEnumerateByPrefix()` 为真时立刻返回），不产生任何往返；
     * 但索引写入失败**不允许**影响业务：缓存已写成功，索引只是辅助信息。
     *
     * @param string $key 逻辑键（不含驱动前缀）
     * @return void
     */
    public function trackKey(string $key): void
    {
        if ($key === '' || $this->canEnumerateByPrefix()) {
            return;
        }

        try {
            $this->index->record([$key]);
        } catch (\Throwable $e) {
            Log::warning('权限缓存键索引写入失败（不影响业务，该键将只能等 TTL 过期）', [
                'cache_key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 按前缀清除缓存
     *
     * @param array<string> $prefixes 白名单内的逻辑前缀（可带参数尾巴，如 `permission:tenant:1:user:2:check:`）
     * @return array{driver: string, strategy: string, deleted: int, tracked?: int}
     * @throws InvalidArgumentException 前缀不在白名单内时（fail-closed）
     */
    public function purge(array $prefixes): array
    {
        $prefixes = array_values(array_unique($prefixes));
        foreach ($prefixes as $prefix) {
            $this->assertAllowed($prefix);
        }

        $driver = $this->driverName();

        if ($prefixes === []) {
            return ['driver' => $driver, 'strategy' => 'noop', 'deleted' => 0];
        }

        $handler = $this->redisHandler();
        $physicalPrefix = $handler === null ? null : $this->physicalPrefix();

        if ($handler !== null && $physicalPrefix !== null) {
            $deleted = (new RedisScanPurger($handler, $physicalPrefix))->purge($prefixes);

            return ['driver' => $driver, 'strategy' => 'scan', 'deleted' => $deleted];
        }

        if ($handler !== null) {
            // 有 phpredis 客户端却拿不到物理前缀：SCAN 的 MATCH 模式无法拼对，
            // 硬扫只会「一条都匹配不到」并误报成功，因此明确降级并留下证据
            Log::warning('权限缓存前缀清除：驱动未暴露键前缀，无法进行 SCAN，降级为索引精确删除', [
                'driver' => $driver,
                'prefixes' => $prefixes,
            ]);
        }

        // 非可枚举驱动：只删索引里记录过的键
        $tracked = $this->index->all();
        $matched = $this->filterKeysByPrefixes($tracked, $prefixes);

        $deleted = $this->deleteKeys($matched);

        if ($tracked === []) {
            Log::warning('权限缓存前缀清除：当前驱动无法按前缀枚举，且键索引为空，可能有键未被清除（最长等待 TTL）', [
                'driver' => $driver,
                'prefixes' => $prefixes,
            ]);
        }

        return [
            'driver' => $driver,
            'strategy' => 'index',
            'deleted' => $deleted,
            'tracked' => count($tracked),
        ];
    }

    /**
     * 精确删除一批逻辑键（L1/L2 这类结构已知的键）
     *
     * @param array<string> $keys 逻辑键（不含驱动前缀）
     * @return int 实际删除的键数量
     * @throws InvalidArgumentException 存在白名单外的键时（fail-closed，一个都不删）
     */
    public function deleteKeys(array $keys): int
    {
        $keys = array_values(array_unique(array_filter(
            $keys,
            static fn ($key) => is_string($key) && $key !== ''
        )));

        if ($keys === []) {
            return 0;
        }

        // 先整体校验再动手：宁可整批不删，也不能删到白名单外的键
        foreach ($keys as $key) {
            $this->assertAllowed($key);
        }

        $store = $this->store();
        $handler = $this->redisHandler();
        $deleted = 0;

        // 前置：必须能取到物理前缀。它同时证明 `getCacheKey()` 可调用且不抛
        // （比再探一次更省一次往返），否则退回接口内的 delete()：
        // 拿不到前缀还去调 getCacheKey($key)，一旦代理不认这个方法就会把异常抛给调用方。
        if ($handler !== null && $this->physicalPrefix() !== null) {
            // 逐键仍走驱动自己的 getCacheKey()：File 之类会把键名 hash 成路径，
            // 「前缀 + 键名」只对基类实现（`prefix . $name`）成立，不能想当然地替代
            $physicalKeys = [];
            foreach ($keys as $key) {
                $physicalKeys[] = $store->getCacheKey($key);
            }

            foreach (array_chunk($physicalKeys, self::DELETE_CHUNK_SIZE) as $chunk) {
                $deleted += (int) $handler->del($chunk);
            }
        } else {
            // 没有 phpredis 客户端（File / 代理不支持 handler()）：逐键走接口内的 delete()
            foreach ($keys as $key) {
                if ($store->delete($key)) {
                    $deleted++;
                }
            }
        }

        // 同步收缩索引（Redis 下索引本来就是空的，这里等价于空操作）
        try {
            $this->index->remove($keys);
        } catch (\Throwable $e) {
            Log::warning('权限缓存键索引收缩失败（不影响已完成的删除）', [
                'error' => $e->getMessage(),
            ]);
        }

        return $deleted;
    }

    /**
     * 校验前缀 / 键是否落在白名单内
     *
     * @param string $prefixOrKey
     * @return void
     * @throws InvalidArgumentException
     */
    public function assertAllowed(string $prefixOrKey): void
    {
        // 空串会匹配「所有键」，必须显式拒绝
        if ($prefixOrKey === '') {
            throw new InvalidArgumentException('拒绝执行空前缀的缓存清除：空前缀等价于清空整个缓存库');
        }

        foreach (self::ALLOWED_PREFIXES as $allowed) {
            if (str_starts_with($prefixOrKey, $allowed)) {
                return;
            }
        }

        throw new InvalidArgumentException(sprintf(
            '拒绝清除白名单外的缓存键「%s」：仅允许 %s',
            $prefixOrKey,
            implode(' / ', self::ALLOWED_PREFIXES)
        ));
    }

    /**
     * 取缓存 store（懒取 `Cache::store()`）
     *
     * @return CacheHandlerInterface
     */
    private function store(): CacheHandlerInterface
    {
        if ($this->store === null) {
            $this->store = Cache::store();
        }

        return $this->store;
    }

    /**
     * 能力探测：store 上是否真的可以调用某个「高级方法」
     *
     * 为什么用 `is_callable()` 而不用 `method_exists()`：
     * Swoole 的 `think\swoole\pool\proxy\Store` **没有声明** `handler()` / `getCacheKey()`，
     * 未声明的一律靠 `Proxy::__call()` 转发到池里的真实驱动。
     * `method_exists()` 只认类里声明过的符号，对这类代理恒为 false，
     * 会把「Redis + Swoole」误判成「不支持」，SCAN 路径永远走不到（清除静默失效）；
     * `is_callable()` 把 `__call()` 视作可调用，判断与运行时行为一致。
     *
     * @param string $method 方法名（`handler` / `getCacheKey`）
     * @return bool
     */
    private function supports(string $method): bool
    {
        return is_callable([$this->store(), $method]);
    }

    /**
     * 取 phpredis 底层客户端（拿不到就返回 null，绝不抛给调用方）
     *
     * 判定顺序：能力探测 → 调用（失败即降级） → 必须是 `\Redis` 实例。
     * 刻意**不要求** store 是 `think\cache\Driver` 的子类（Swoole 代理不是），
     * 只要它能把 `handler()` 转发到真实驱动、且给出的是 phpredis 客户端即可。
     *
     * 为什么要求 `\Redis`：`RedisScanPurger` 用的是 phpredis 的
     * `scan(&$cursor, $pattern, $count)` 语义；Predis 的 `scan()` 参数形态完全不同
     * （选项数组成第二参），硬套会扫不到键。判据之外的情况一律按「不可枚举」处理。
     *
     * @return object|null
     */
    private function redisHandler(): ?object
    {
        if (!$this->supports('handler')) {
            return null;
        }

        try {
            $handler = $this->store()->handler();
        } catch (\Throwable $e) {
            // Redis 不可用 / 连接池连接已释放：当作「不可枚举」处理，让上层走索引路径而不是抛错中断业务
            Log::warning('权限缓存清除：无法取得 Redis 客户端，降级为索引精确删除', [
                'store' => get_class($this->store()),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $handler instanceof \Redis ? $handler : null;
    }

    /**
     * 取驱动的物理键前缀（`getCacheKey('')`，如 `alkaid:`）
     *
     * 拿不到前缀直接返回 null —— 此时 SCAN 的 MATCH 模式拼不对（一个键都匹配不上），
     * 调用方必须降级为索引策略，而不是硬拼一个「看起来像」的模式。
     *
     * @return string|null
     */
    private function physicalPrefix(): ?string
    {
        if (!$this->supports('getCacheKey')) {
            return null;
        }

        try {
            $prefix = $this->store()->getCacheKey('');
        } catch (\Throwable $e) {
            Log::warning('权限缓存清除：无法取得缓存键前缀，降级为索引精确删除', [
                'store' => get_class($this->store()),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // 空前缀是合法值（驱动没配 prefix 时物理键名等于逻辑键名），只有非字符串才算「取不到」
        return is_string($prefix) ? $prefix : null;
    }

    /**
     * 从键清单里筛出命中任一前缀的键（索引策略用）
     *
     * 复杂度：O(键数 × 不同前缀长度数)，而不是 O(键数 × 前缀数)。
     * 为什么在意：角色一次变更可能涉及上千用户，前缀数就是「用户数 × 2」；
     * 朴素的嵌套 `str_starts_with` 会让「1000 用户」退化成百万级字符串比较
     * （实测 File 驱动 1000 用户 4000 键从 2s 降到毫秒级，见交付报告）。
     *
     * 做法：把前缀按长度分桶（前缀都以 `:` 结尾且结构固定，不同长度通常只有 2-4 种），
     * 对每个键只做「按长度截取 + 哈希查表」。
     *
     * @param array<string> $keys
     * @param array<string> $prefixes
     * @return array<string>
     */
    private function filterKeysByPrefixes(array $keys, array $prefixes): array
    {
        if ($keys === [] || $prefixes === []) {
            return [];
        }

        /** @var array<int, array<string, true>> $byLength 长度 => 前缀集合 */
        $byLength = [];
        foreach ($prefixes as $prefix) {
            $byLength[strlen($prefix)][$prefix] = true;
        }

        $matched = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                continue;
            }

            $keyLength = strlen($key);
            foreach ($byLength as $length => $prefixMap) {
                if ($length > $keyLength) {
                    continue;
                }

                if (isset($prefixMap[substr($key, 0, $length)])) {
                    $matched[] = $key;
                    break;
                }
            }
        }

        return $matched;
    }
}
