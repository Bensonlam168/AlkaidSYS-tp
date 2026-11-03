<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\ThinkPHPTestCase;
use think\facade\Db;

class AccessLogMiddlewareTest extends ThinkPHPTestCase
{
    /**
     * 多租户上下文用例使用的站点夹具（真实 `sites` 行）
     *
     * 为什么需要：`SiteIdentify` 自「站点归属校验」落地后，`X-Site-ID` 指向的站点
     * 必须真实存在、属于请求租户且 `status = active`，否则请求在入口就被 403
     * （`AccessLog` 位于 `SiteIdentify` 之后，根本不会被调用，日志也就不会产生）。
     * 本用例原本用 101/202 这两个**不存在的站点 id** 来验证「日志里记的是请求头里的站点」，
     * 断言本身是对的，缺的是对应的站点行 —— 因此这里按需造出来，断言一行不改。
     *
     * 用显式 id 101 / 202：既有的断言就是拿这两个值比对，改夹具而不改期望值，
     * 才能证明「多租户上下文写入日志」这条行为本身没变。
     */
    private const FIXTURE_SITES = [
        101 => 1, // 站点 101 → 租户 1
        202 => 2, // 站点 202 → 租户 2
    ];

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

        // 确保使用 app\Request 并加载认证路由，方便在集成测试中触发 Auth 中间件
        $this->app()->bind(\think\Request::class, \app\Request::class);

        $routeFile = $this->app()->getRootPath() . 'route/auth.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        $this->seedSiteFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeSiteFixtures();
        parent::tearDown();
    }

    /**
     * 造出本用例需要的站点行（先删后建，保证「存在且 active」）
     *
     * `sites.tenant_id` 有外键 → `tenants.id`：租户 1 / 2 是测试库既有夹具
     * （见 `database/seeds/TestPlatformSeed.php` 的产出清单）。
     */
    private function seedSiteFixtures(): void
    {
        $this->removeSiteFixtures();

        $rows = [];
        foreach (self::FIXTURE_SITES as $siteId => $tenantId) {
            $rows[] = [
                'id'        => $siteId,
                'tenant_id' => $tenantId,
                'name'      => 'AccessLog Fixture Site ' . $siteId,
                'slug'      => 'access-log-fixture-' . $siteId,
                'domain'    => null,
                'status'    => 'active',
                'theme'     => 'default',
            ];
        }

        Db::name('sites')->insertAll($rows);
    }

    /**
     * 删除站点夹具，避免污染其它用例与「测试库回到基线」的验收
     */
    private function removeSiteFixtures(): void
    {
        Db::name('sites')->whereIn('id', array_keys(self::FIXTURE_SITES))->delete();
    }

    public function test_access_log_written_for_simple_request(): void
    {
        // Use container to create Request instance to ensure app\Request is used
        // 使用容器创建 Request 实例以确保使用 app\Request
        $request = $this->app()->make(\think\Request::class);
        $request->setMethod('GET');
        $request->setPathinfo('non-existent-path-for-access-log-test');

        // 设置部分上下文头部，便于在日志中校验（使用 withHeader 正确写入头部）
        $request = $request->withHeader([
            'X-Tenant-ID'      => '1',
            'User-Agent'       => 'AccessLogTest/1.0',
            // 在 CLI/Swoole 场景下，Request::ip() 可能返回 0.0.0.0，这里只要求字段存在即可
            'X-Forwarded-For'  => '203.0.113.1',
        ]);

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

    public function test_access_log_contains_multi_tenant_context_for_different_tenants(): void
    {
        // 第一个租户请求
        $request1 = $this->app()->make(\think\Request::class);
        $request1->setMethod('GET');
        $request1->setPathinfo('tenant-1-access-log-test');
        $request1 = $request1->withHeader([
            'X-Tenant-ID'     => '1',
            'X-Site-ID'       => '101',
            'X-Forwarded-For' => '203.0.113.11',
        ]);

        $this->runHttp($request1);

        // 第二个租户请求
        $request2 = $this->app()->make(\think\Request::class);
        $request2->setMethod('GET');
        $request2->setPathinfo('tenant-2-access-log-test');
        $request2 = $request2->withHeader([
            'X-Tenant-ID'     => '2',
            'X-Site-ID'       => '202',
            'X-Forwarded-For' => '203.0.113.12',
        ]);

        $this->runHttp($request2);

        $rootPath = dirname(__DIR__, 2);
        $dir      = $rootPath . '/runtime/log/access';
        $this->assertDirectoryExists($dir);

        $pattern = $dir . '/access-' . date('Ymd') . '.log';
        $files   = glob($pattern);
        $this->assertNotEmpty($files, 'Expected access log file to be created');

        $file  = $files[0];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotEmpty($lines, 'Expected at least one access log line');

        $byPath = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $byPath[$row['path'] ?? ''] = $row;
        }

        $entry1 = $byPath['/tenant-1-access-log-test'] ?? null;
        $entry2 = $byPath['/tenant-2-access-log-test'] ?? null;

        $this->assertNotNull($entry1, 'Missing access log for tenant 1');
        $this->assertNotNull($entry2, 'Missing access log for tenant 2');

        $this->assertSame(1, $entry1['tenant_id']);
        $this->assertSame(101, $entry1['site_id']);

        $this->assertSame(2, $entry2['tenant_id']);
        $this->assertSame(202, $entry2['site_id']);
    }

    public function test_access_log_contains_user_and_tenant_context_for_authenticated_request(): void
    {
        // 通过 AuthHelper 生成带 user/tenant/site 上下文的测试 Token
        $token = \Tests\Helpers\AuthHelper::generateTestToken(userId: 1, tenantId: 3, siteId: 0);

        /** @var \think\Request $request */
        $request = $this->app()->make(\think\Request::class);
        $request->setMethod('GET');
        $request->setPathinfo('v1/auth/me');
        $request = $request->withHeader([
            'Authorization'   => 'Bearer ' . $token,
            // TenantIdentify 仍然从头部读取租户，确保与 token 一致
            'X-Tenant-ID'     => '3',
            'X-Forwarded-For' => '203.0.113.21',
        ]);

        $response = $this->runHttp($request);
        $this->assertNotNull($response);

        $rootPath = dirname(__DIR__, 2);
        $dir      = $rootPath . '/runtime/log/access';
        $this->assertDirectoryExists($dir);

        $pattern = $dir . '/access-' . date('Ymd') . '.log';
        $files   = glob($pattern);
        $this->assertNotEmpty($files, 'Expected access log file to be created');

        $file  = $files[0];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotEmpty($lines, 'Expected at least one access log line');

        $entry = null;
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            if (($row['path'] ?? '') === '/v1/auth/me') {
                $entry = $row;
            }
        }

        $this->assertNotNull($entry, 'Missing access log entry for /v1/auth/me');
        $this->assertSame(1, $entry['user_id']);
        $this->assertSame(3, $entry['tenant_id']);
        $this->assertSame(0, $entry['site_id']);
    }
}
