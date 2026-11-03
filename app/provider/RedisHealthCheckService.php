<?php

namespace app\provider;

use app\AppService;
use think\Service;
use think\facade\Cache;
use think\facade\Config;

/**
 * 在使用 Redis 作为缓存或 Session 后端时，启动期做一次读写自检。
 *
 * - 当 cache.default === 'redis' 或 session 使用 cache+redis 时生效；
 * - 通过 Cache::store('redis') 做一次 set/get；
 * - 如连接失败或读写异常，直接抛出包含 host/port 的异常，避免"静默退化"。
 */
class RedisHealthCheckService extends Service
{
    public function boot(): void
    {
        $cacheDefault  = Config::get('cache.default');
        $sessionConfig = (array) Config::get('session', []);

        $usesRedisCache = $cacheDefault === 'redis';
        $usesRedisSession = ($sessionConfig['type'] ?? null) === 'cache'
            && ($sessionConfig['store'] ?? null) === 'redis';

        if (!$usesRedisCache && !$usesRedisSession) {
            // 当前环境并未启用 Redis 作为缓存/Session 后端，跳过自检
            return;
        }

        $host = (string) env('REDIS_HOST', 'redis');
        $port = (int) env('REDIS_PORT', 6379);

        $key = 'health:redis_ping:' . date('YmdHis');

        try {
            $written = Cache::store('redis')->set($key, 'ok', 60);
            $read    = Cache::store('redis')->get($key);

            if (!$written || $read !== 'ok') {
                throw new \RuntimeException('Redis read/write check failed');
            }
        } catch (\Throwable $e) {
            // R51：boot 期失败必须让命令行进程以非 0 退出。
            // 框架的全局异常处理器（set_exception_handler）只渲染异常、不设置退出码，
            // 不先标记就会「打印 RuntimeException 但 exit 0」，部署脚本误判成功（实测确认）。
            // 注意：内层「read/write check failed」也会走到这里，标记一次即可覆盖全部失败路径。
            AppService::markBootFailed();

            throw new \RuntimeException(
                sprintf(
                    'Redis health check failed (host=%s, port=%d): %s',
                    $host,
                    $port,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
    }
}
