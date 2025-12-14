<?php

declare(strict_types=1);

namespace Infrastructure\RateLimit\Service;

use think\facade\Cache;
use think\facade\Log;

/**
 * Token Bucket 限流服务 | Token Bucket Rate Limiting Service
 *
 * 使用 Redis 实现的 Token Bucket 算法，提供平滑的流量控制和突发流量处理能力。
 * 通过 Lua 脚本保证 Redis 操作的原子性，避免并发竞争问题。
 *
 * Token Bucket Algorithm:
 * - 每个桶有固定容量（capacity）和令牌补充速率（rate，令牌/秒）
 * - 每次请求尝试消费指定数量的令牌（默认1个）
 * - 令牌按速率自动补充，但不超过桶容量
 * - 桶中令牌不足时拒绝请求
 *
 * Redis Data Structure:
 * - Key: {prefix}:{dimension}:{identifier}:token_bucket
 * - Value: Hash {tokens, last_update, capacity, rate}
 *
 * @package infrastructure\RateLimit\Service
 */
class RateLimitService
{
    /**
     * Lua script for token bucket algorithm | Token Bucket 算法的 Lua 脚本
     *
     * 原子性地执行以下操作：
     * 1. 获取当前桶状态（tokens, last_update）
     * 2. 计算时间差并补充令牌
     * 3. 尝试消费令牌
     * 4. 更新桶状态
     *
     * @var string
     */
    private const LUA_SCRIPT = <<<'LUA'
-- 参数：KEYS[1] = bucket key
-- 参数：ARGV[1] = capacity (桶容量)
-- 参数：ARGV[2] = rate (令牌补充速率，令牌/秒)
-- 参数：ARGV[3] = cost (本次消耗的令牌数)
-- 参数：ARGV[4] = now (当前时间戳，秒)
-- 参数：ARGV[5] = ttl (key 过期时间，秒)
-- 返回：{allowed, tokens, last_update} - allowed=1表示允许，0表示拒绝

local key = KEYS[1]
local capacity = tonumber(ARGV[1])
local rate = tonumber(ARGV[2])
local cost = tonumber(ARGV[3])
local now = tonumber(ARGV[4])
local ttl = tonumber(ARGV[5])

-- 获取当前桶状态
local bucket = redis.call('HMGET', key, 'tokens', 'last_update')
local tokens = tonumber(bucket[1])
local last_update = tonumber(bucket[2])

-- 如果桶不存在，初始化为满桶
if tokens == nil then
    tokens = capacity
    last_update = now
end

-- 计算时间差并补充令牌
local time_passed = math.max(0, now - last_update)
local new_tokens = math.min(capacity, tokens + time_passed * rate)

-- 尝试消费令牌
local allowed = 0
if new_tokens >= cost then
    new_tokens = new_tokens - cost
    allowed = 1
end

-- 更新桶状态
redis.call('HMSET', key, 'tokens', new_tokens, 'last_update', now)
redis.call('EXPIRE', key, ttl)

return {allowed, new_tokens, now}
LUA;

    /**
     * Cache store instance | 缓存存储实例
     *
     * @var \think\Cache|null
     */
    private $cache = null;

    /**
     * Lua script SHA1 hash | Lua 脚本 SHA1 哈希
     *
     * @var string|null
     */
    private $scriptSha = null;

    /**
     * Constructor | 构造函数
     *
     * Rate limiting requires Redis for atomic operations and distributed state.
     * Default store is 'redis' regardless of the application's default cache driver.
     *
     * 限流需要 Redis 来实现原子操作和分布式状态。
     * 默认使用 'redis' store，不受应用默认缓存驱动影响。
     *
     * @param string|null $store Cache store name, defaults to 'redis'
     */
    public function __construct(?string $store = null)
    {
        // Rate limiting must use Redis for atomic Lua script execution
        // 限流必须使用 Redis 以执行原子 Lua 脚本
        $this->cache = Cache::store($store ?? 'redis');
    }

    /**
     * 尝试消费令牌 | Attempt to consume tokens from the bucket
     *
     * @param string $key Bucket key
     * @param int $capacity Bucket capacity (maximum tokens)
     * @param float $rate Token refill rate (tokens per second)
     * @param int $cost Number of tokens to consume (default: 1)
     * @return bool True if request is allowed, false if rate limited
     */
    public function allowRequest(string $key, int $capacity, float $rate, int $cost = 1): bool
    {
        try {
            // 确保参数有效
            if ($capacity <= 0 || $rate <= 0 || $cost <= 0) {
                Log::warning('RateLimitService: Invalid parameters', [
                    'key' => $key,
                    'capacity' => $capacity,
                    'rate' => $rate,
                    'cost' => $cost,
                ]);
                return true; // Fail open on invalid config
            }

            $redis = $this->getRedisHandler();
            if (!$redis) {
                return $this->failOpen('Redis handler not available');
            }

            // 执行 Lua 脚本
            $result = $this->evalLuaScript($redis, $key, $capacity, $rate, $cost);

            if (!is_array($result) || count($result) < 1) {
                return $this->failOpen('Invalid Lua script result');
            }

            $allowed = (int) $result[0];
            return $allowed === 1;
        } catch (\Throwable $e) {
            return $this->failOpen('Exception: ' . $e->getMessage());
        }
    }

    /**
     * 获取限流信息 | Get rate limit information
     *
     * @param string $key Bucket key
     * @return array{tokens: float, last_update: int, capacity: int|null, rate: float|null}
     */
    public function getRateLimitInfo(string $key): array
    {
        try {
            $redis = $this->getRedisHandler();
            if (!$redis) {
                return [
                    'tokens' => 0,
                    'last_update' => time(),
                    'capacity' => null,
                    'rate' => null,
                ];
            }

            $bucket = $redis->hMGet($key, ['tokens', 'last_update']);

            return [
                'tokens' => isset($bucket['tokens']) ? (float) $bucket['tokens'] : 0,
                'last_update' => isset($bucket['last_update']) ? (int) $bucket['last_update'] : time(),
                'capacity' => null, // Redis 中不存储 capacity，由配置提供
                'rate' => null,     // Redis 中不存储 rate，由配置提供
            ];
        } catch (\Throwable $e) {
            Log::warning('RateLimitService: Failed to get rate limit info', [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);

            return [
                'tokens' => 0,
                'last_update' => time(),
                'capacity' => null,
                'rate' => null,
            ];
        }
    }

    /**
     * 重置限流桶 | Reset rate limit bucket
     *
     * @param string $key Bucket key
     * @return bool True on success
     */
    public function reset(string $key): bool
    {
        try {
            $redis = $this->getRedisHandler();
            if (!$redis) {
                return false;
            }

            return (bool) $redis->del($key);
        } catch (\Throwable $e) {
            Log::warning('RateLimitService: Failed to reset bucket', [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 获取 Redis 连接 | Get Redis handler
     *
     * @return \Redis|null
     */
    private function getRedisHandler(): ?\Redis
    {
        try {
            $handler = $this->cache->handler();

            // ThinkPHP Cache 的 Redis 驱动返回的是 \Redis 实例
            if ($handler instanceof \Redis) {
                return $handler;
            }

            Log::warning('RateLimitService: Cache handler is not Redis instance', [
                'handler_type' => get_class($handler),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('RateLimitService: Failed to get Redis handler', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 执行 Lua 脚本 | Execute Lua script
     *
     * @param \Redis $redis Redis connection
     * @param string $key Bucket key
     * @param int $capacity Bucket capacity
     * @param float $rate Token refill rate
     * @param int $cost Tokens to consume
     * @return array Script result
     */
    private function evalLuaScript(\Redis $redis, string $key, int $capacity, float $rate, int $cost): array
    {
        $now = time();
        $ttl = max(3600, (int) ceil($capacity / $rate * 2)); // TTL = 至少2倍桶容量恢复时间

        // 尝试使用 EVALSHA（如果脚本已缓存）
        if ($this->scriptSha !== null) {
            try {
                $result = $redis->evalSha(
                    $this->scriptSha,
                    [$key, $capacity, $rate, $cost, $now, $ttl],
                    1 // numkeys
                );

                if (is_array($result)) {
                    return $result;
                }
            } catch (\RedisException $e) {
                // Script not found, fallback to EVAL
                $this->scriptSha = null;
            }
        }

        // 使用 EVAL 并缓存 SHA
        $result = $redis->eval(
            self::LUA_SCRIPT,
            [$key, $capacity, $rate, $cost, $now, $ttl],
            1 // numkeys
        );

        // 尝试获取脚本 SHA（用于后续 EVALSHA）
        try {
            $this->scriptSha = $redis->script('load', self::LUA_SCRIPT);
        } catch (\Throwable) {
            // Ignore if script load fails
        }

        return is_array($result) ? $result : [];
    }

    /**
     * 降级放行 | Fail open on error
     *
     * @param string $reason Failure reason
     * @return bool Always true (allow request)
     */
    private function failOpen(string $reason): bool
    {
        Log::warning('RateLimitService: Fail open - ' . $reason);
        return true;
    }

    /**
     * 构建限流键 | Build rate limit key
     *
     * @param string $env Environment (dev/stage/prod)
     * @param string $scope Scope (user/tenant/ip/route)
     * @param string $identifier Unique identifier
     * @param string $algorithm Algorithm type (token_bucket)
     * @return string Rate limit key
     */
    public static function buildKey(string $env, string $scope, string $identifier, string $algorithm = 'token_bucket'): string
    {
        return sprintf('rl:%s:%s:%s:%s', $env, $scope, md5($identifier), $algorithm);
    }
}
