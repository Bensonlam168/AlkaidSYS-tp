<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Cache;

use InvalidArgumentException;
use think\cache\Driver as CacheDriver;
use think\cache\driver\Redis as RedisDriver;
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
     * 缓存驱动实例（默认为当前默认 store）
     */
    private ?CacheDriver $store;

    /**
     * 非可枚举驱动的键索引
     */
    private CacheKeyIndex $index;

    /**
     * 当前驱动是否可按前缀枚举（懒计算并缓存）
     */
    private ?bool $enumerable = null;

    /**
     * @param CacheDriver|null $store 缓存驱动（默认 `Cache::store()`）
     * @param CacheKeyIndex|null $index 键索引（默认新建）
     */
    public function __construct(?CacheDriver $store = null, ?CacheKeyIndex $index = null)
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
        if ($handler !== null) {
            $store = $this->store();
            $deleted = (new RedisScanPurger($handler, $store->getCacheKey('')))->purge($prefixes);

            return ['driver' => $driver, 'strategy' => 'scan', 'deleted' => $deleted];
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

        if ($handler !== null) {
            $physicalKeys = [];
            foreach ($keys as $key) {
                $physicalKeys[] = $store->getCacheKey($key);
            }

            foreach (array_chunk($physicalKeys, self::DELETE_CHUNK_SIZE) as $chunk) {
                $deleted += (int) $handler->del($chunk);
            }
        } else {
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
     * 取缓存驱动实例
     *
     * @return CacheDriver
     */
    private function store(): CacheDriver
    {
        if ($this->store === null) {
            $this->store = Cache::store();
        }

        return $this->store;
    }

    /**
     * 取 phpredis 底层客户端（非 Redis 驱动 / Predis 返回 null）
     *
     * @return object|null
     */
    private function redisHandler(): ?object
    {
        $store = $this->store();
        if (!$store instanceof RedisDriver) {
            return null;
        }

        try {
            $handler = $store->handler();
        } catch (\Throwable $e) {
            // Redis 不可用：当作「不可枚举」处理，让上层走索引路径而不是抛错中断业务
            Log::warning('权限缓存清除：Redis 客户端不可用，降级为索引精确删除', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $handler instanceof \Redis ? $handler : null;
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
