<?php

declare(strict_types=1);

namespace app\controller;

use think\facade\Session;
use think\facade\Config;
use think\facade\Cache;
use think\Response;

/**
 * Debug Controller | 调试控制器
 *
 * 提供用于验证 Session -> Redis 行为的调试接口。
 */
class DebugController extends ApiController
{
    /**
     * GET /debug/session-redis
     *
     * 在 HTTP + Swoole 场景下写入并读取 Session，返回配置与 Session 信息，
     * 便于在 Redis 中进行联调与验证。
     */
    public function sessionRedis(): Response
    {
        $env = (string) env('APP_ENV', 'production');

        $sessionConfig = [
            'type'   => Config::get('session.type'),
            'store'  => Config::get('session.store'),
            'prefix' => Config::get('session.prefix'),
        ];

        $redisInfo = [
            'host' => env('REDIS_HOST', 'redis'),
            'port' => (int) env('REDIS_PORT', 6379),
            'db'   => (int) env('REDIS_DB', 0),
        ];

        $value = 'http_session_redis_test_' . date('Ymd_His');

        // 写入 Session 测试值
        Session::set('t1_session_redis_test', $value);

        // 获取当前 Session ID
        $sessionId = Session::getId() ?: session_id();

        // 再次读取刚写入的值，验证读写一致性
        $readValue = Session::get('t1_session_redis_test');

        // 直接通过 Cache::store('redis') 进行一次写入/读取，用于确认 Redis 连接是否可用
        $cacheKey   = 't1_session_redis_debug_cache_' . date('Ymd_His');
        $cacheValue = 'cache_test_' . bin2hex(random_bytes(4));

        $cacheWriteOk   = false;
        $cacheReadValue = null;
        $cacheError     = null;

        try {
            $cacheWriteOk   = Cache::store('redis')->set($cacheKey, $cacheValue, 600);
            $cacheReadValue = Cache::store('redis')->get($cacheKey);
        } catch (\Throwable $e) {
            $cacheError = $e->getMessage();
        }

        $redisCliHint = sprintf(
            'docker compose exec redis redis-cli KEYS "session*" # 然后使用 GET 对具体 session key 读取内容（session_id=%s）；另可使用 KEYS "%s" 检查 Cache 写入测试 key',
            $sessionId,
            $cacheKey
        );

        $data = [
            'env'              => $env,
            'session_config'   => $sessionConfig,
            'session_id'       => $sessionId,
            'written_value'    => $value,
            'read_value'       => $readValue,
            'redis_info'       => $redisInfo,
            'redis_cli_hint'   => $redisCliHint,
            'cache_debug'      => [
                'key'         => $cacheKey,
                'written'     => $cacheValue,
                'write_ok'    => $cacheWriteOk,
                'read_value'  => $cacheReadValue,
                'error'       => $cacheError,
            ],
        ];

        return $this->success($data, 'Session Redis test completed');
    }
}
