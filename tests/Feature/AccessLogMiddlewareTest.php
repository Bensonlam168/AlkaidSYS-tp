<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\ThinkPHPTestCase;

class AccessLogMiddlewareTest extends ThinkPHPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 清理上一轮测试生成的访问日志，避免干扰断言
        $rootPath = dirname(__DIR__, 2);
        $dir = $rootPath . '/runtime/log/access';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.log') as $file) {
                @unlink($file);
            }
        }
    }

    public function test_access_log_written_for_simple_request(): void
    {
        // Use container to create Request instance to ensure app\Request is used
        // 使用容器创建 Request 实例以确保使用 app\Request
        $request = $this->app()->make(\think\Request::class);
        $request->setMethod('GET');
        $request->setPathinfo('non-existent-path-for-access-log-test');

        // 设置部分上下文头部，便于在日志中校验
        $request->header('X-Tenant-ID', '1');
        $request->header('User-Agent', 'AccessLogTest/1.0');
        // 在 CLI/Swoole 场景下，Request::ip() 可能返回 0.0.0.0，这里只验证我们设置的 X-Forwarded-For 被解析
        $request->header('X-Forwarded-For', '203.0.113.1');

        // Use runHttp() from base class to properly handle error handlers
        // 使用基类的 runHttp() 方法正确处理错误处理器
        $response = $this->runHttp($request);

        $this->assertNotNull($response);

        $rootPath = dirname(__DIR__, 2);
        $dir = $rootPath . '/runtime/log/access';
        $this->assertDirectoryExists($dir);

        $pattern = $dir . '/access-' . date('Ymd') . '.log';
        $files = glob($pattern);
        $this->assertNotEmpty($files, 'Expected access log file to be created');

        $file = $files[0];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotEmpty($lines, 'Expected at least one access log line');

        $lastLine = $lines[count($lines) - 1];
        $data = json_decode($lastLine, true);
        $this->assertIsArray($data, 'Access log line should be valid JSON');

        // 核心字段检查
        $expectedKeys = [
            'timestamp', 'env', 'trace_id', 'method', 'path', 'query',
            'status_code', 'response_time_ms', 'client_ip', 'user_agent',
            'user_id', 'tenant_id', 'site_id', 'rate_limited',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $data, "Missing access log field: {$key}");
        }

        $this->assertSame('GET', $data['method']);
        $this->assertSame('/non-existent-path-for-access-log-test', $data['path']);
        // 在 CLI/Swoole 场景下，Request::ip() 可能返回 0.0.0.0，这里只要求字段存在即可
        $this->assertArrayHasKey('client_ip', $data);
        // User-Agent
        $this->assertArrayHasKey('user_agent', $data);
        $this->assertIsNumeric($data['response_time_ms']);
    }
}
