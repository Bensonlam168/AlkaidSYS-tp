<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use app\middleware\AccessLog;
use app\Request;
use Tests\ThinkPHPTestCase;
use think\Response;

/**
 * AccessLog 中多租户上下文写入日志的单元测试
 *
 * 验证 AccessLog 能将 tenant_id/site_id/路径 等信息写入 runtime/log/access/access-*.log。
 */
class AccessLogTenantContextTest extends ThinkPHPTestCase
{
    protected string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = $this->app()->getRootPath() . 'runtime/log/access';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $this->logFile = $dir . '/access-' . date('Ymd') . '.log';
        @unlink($this->logFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    public function testWriteAccessLogContainsTenantContext(): void
    {
        $request = $this->app()->make(Request::class);
        $request->setMethod('GET');
        $request->setPathinfo('unit/access-log');
        $request = $request->withHeader([
            'X-Tenant-ID' => '101',
            'X-Site-ID' => '3',
            'User-Agent' => 'phpunit',
            'X-Trace-Id' => 'trace-abc',
        ]);

        // 直接调用中间件，触发访问日志写入
        $middleware = new AccessLog();
        $middleware->handle($request, static function () {
            return Response::create('ok', 'html', 200);
        });

        $this->assertFileExists($this->logFile, '访问日志文件未生成');
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines);
        $last = json_decode($lines[count($lines) - 1], true);

        $this->assertSame(101, (int) ($last['tenant_id'] ?? -1));
        $this->assertSame(3, (int) ($last['site_id'] ?? -1));
        $this->assertSame('/unit/access-log', $last['path'] ?? null);
        $this->assertSame(200, (int) ($last['status_code'] ?? 0));
        $this->assertSame('trace-abc', $last['trace_id'] ?? null);
    }
}
