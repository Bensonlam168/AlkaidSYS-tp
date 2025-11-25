<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\ThinkPHPTestCase;
use think\facade\Cache;

class RateLimitMiddlewareTest extends ThinkPHPTestCase
{
    public function test_requests_under_limit_are_not_rate_limited(): void
    {
        // 清理限流相关 cache，避免其他测试或手工请求遗留的计数影响本用例
        if (class_exists(Cache::class)) {
            Cache::clear();
        }

        $request = new \app\Request();
        $request->setMethod('GET');
        $request->setPathinfo('ratelimit-test-ok');
        $request->header('X-Forwarded-For', '198.51.100.1');

        $response = $this->runHttp($request);

        $this->assertNotNull($response);
        $this->assertNotSame(429, $response->getCode());
    }

    public function test_exceeding_limit_returns_429_and_sets_rate_limited_flag(): void
    {
        // 使用 /debug/session-redis 作为简单 GET 接口进行压测
        $path = 'debug/session-redis';

        // 先清理可能存在的历史访问日志，避免干扰
        $rootPath = dirname(__DIR__, 2);
        $dir      = $rootPath . '/runtime/log/access';
        if (is_dir($dir)) {
            $pattern = $dir . '/access-' . date('Ymd') . '.log';
            foreach (glob($pattern) ?: [] as $file) {
                @unlink($file);
            }
        }

        $lastResponse = null;
        for ($i = 0; $i < 120; $i++) {
            $request = new \app\Request();
            $request->setMethod('GET');
            $request->setPathinfo($path);
            $request->header('X-Forwarded-For', '203.0.113.10');

            $lastResponse = $this->runHttp($request);
        }

        $this->assertNotNull($lastResponse);
        $this->assertSame(429, $lastResponse->getCode());

        // 读取访问日志，检查是否存在 rate_limited=true 的记录
        $this->assertDirectoryExists($dir);

        $pattern = $dir . '/access-' . date('Ymd') . '.log';
        $files   = glob($pattern);
        $this->assertNotEmpty($files, 'Expected access log file to be created');

        $file  = $files[0];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotEmpty($lines, 'Expected at least one access log line');

        $foundRateLimited = false;
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }
            if (($data['path'] ?? '') === '/'.$path && !empty($data['rate_limited'])) {
                $foundRateLimited = true;
                break;
            }
        }

        $this->assertTrue($foundRateLimited, 'Expected at least one rate_limited=true entry in access log');
    }
}
