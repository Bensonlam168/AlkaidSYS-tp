<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Session;

/**
 * TestSessionRedis Command | Session-Redis E2E 验证命令（T1-SESSION-REDIS）
 *
 * 用于验证当前 Session 配置是否真正使用 Redis 作为后端存储：
 * - 写入一个测试 Session 值；
 * - 输出 session_id 以及写入的 key/value；
 * - 提示运维在 Redis 中按 session_id 搜索对应 key。
 */
class TestSessionRedis extends Command
{
    protected function configure()
    {
        $this->setName('test:session-redis')
            ->setDescription('Test that session storage is backed by Redis (T1-SESSION-REDIS)');
    }

    protected function execute(Input $input, Output $output)
    {
        $env = (string) env('APP_ENV', 'production');
        $output->writeln("APP_ENV = {$env}");

        $sessionConfig = [
            'type'   => config('session.type'),
            'store'  => config('session.store'),
            'prefix' => config('session.prefix'),
        ];
        $output->writeln('Session config: ' . json_encode($sessionConfig, JSON_UNESCAPED_UNICODE));

        $value = 't1_session_redis_test_' . date('Ymd_His');

        // 写入 Session 测试值
        Session::set('t1_session_redis_test', $value);

        // 获取当前 Session ID（优先通过 Session 门面获取）
        $sessionId = Session::getId();
        if (!$sessionId) {
            $sessionId = session_id();
        }

        $output->writeln('Session ID: ' . $sessionId);
        $output->writeln('Written session key "t1_session_redis_test" with value: ' . $value);
        $output->writeln('Hint: use redis-cli KEYS "*' . $sessionId . '*" or KEYS "session*" to locate the raw session key.');

        return 0;
    }
}

