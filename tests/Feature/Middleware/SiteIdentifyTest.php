<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use app\constant\ErrorCode;
use app\exception\InvalidSiteContextException;
use app\middleware\SiteIdentify;
use app\Request;
use ReflectionProperty;
use Tests\ThinkPHPTestCase;
use think\facade\Db;
use think\Response;

/**
 * SiteIdentify 中间件的站点归属校验测试
 *
 * 覆盖三档输入（无头 / 显式 0 / 正整数）与 `site_id > 0` 的三项校验
 * （存在性 / 归属 / 可用性），以及 `sites.id` 的上界处理。
 *
 * ## 为什么不 mock 仓储
 * 本用例要证明的是「中间件 + `sites` 表」这一整条链的行为：`findContextById()` 的租户条件、
 * `findOwnershipHintUnscoped()` 只用于选择 reason 而不参与放行。mock 掉 `SiteRepository`
 * 会把「租户条件有没有真的写进 where」这件事一起 mock 没了 —— 而那正是本次改动的核心。
 * 因此用**真实测试库 + 显式 id 夹具**，并在 tearDown 里删干净。
 *
 * ## 夹具 id 的选取
 * 站点用 9001..9004，跨租户对照租户用 9902：避开 seeder 的权限 id 段（1001..1033）、
 * 既有租户（1 / 2）与既有站点（1）。setUp 里先删后建（幂等），
 * 避免上一次异常中断留下的残留行让「存在性」断言失去意义。
 *
 * @package Tests\Feature\Middleware
 */
class SiteIdentifyTest extends ThinkPHPTestCase
{
    /**
     * 站点 ID：属于租户 1，状态 active（正向用例）
     */
    private const SITE_OWN_ACTIVE = 9001;

    /**
     * 站点 ID：属于「别的租户」（负向用例：site_tenant_mismatch）
     */
    private const SITE_OTHER_TENANT = 9002;

    /**
     * 站点 ID：属于租户 1，状态 inactive（负向用例：site_unusable）
     */
    private const SITE_INACTIVE = 9003;

    /**
     * 站点 ID：属于租户 1，状态 maintenance（负向用例：site_unusable）
     */
    private const SITE_MAINTENANCE = 9004;

    /**
     * 「别的租户」夹具的租户 ID（sites.tenant_id 有外键 → tenants.id）
     */
    private const FOREIGN_TENANT_ID = 9902;

    /**
     * 当前请求使用的租户 ID（测试库既有租户）
     */
    private const CURRENT_TENANT_ID = 1;

    /**
     * 探针路径：不存在的路由也足够，因为全局中间件在路由分发**之前**执行
     */
    private const PROBE_PATH = 'v1/site-identify-probe';

    protected function setUp(): void
    {
        parent::setUp();

        // 确保容器解析出的是 app\Request（siteId()/tenantIdOrNull() 的来源）
        $this->app()->bind(\think\Request::class, \app\Request::class);

        $this->removeFixtures();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();
        parent::tearDown();
    }

    /**
     * 用例 1：显式 site_id = 0 → 放行，且**不查库**
     *
     * 「不查库」的证据：即使完全没有租户上下文（若走归属校验会因
     * `tenant_context_missing` 被拒），`0` 依然放行 —— 说明它在租户解析之前就短路了。
     */
    public function testExplicitZeroSiteIsAllowedWithoutLookup(): void
    {
        $request = $this->makeRequest(['X-Site-ID' => '0']);

        $this->runMiddleware($request);

        $this->assertSame(0, $request->siteId(), '显式 0 应写入站点维度 0（全局桶）');
        $this->assertSame(0, $request->siteIdOrNull(), '显式 0 必须能被 siteIdOrNull() 与「未提供」区分开');
        $this->assertSame(0, $this->readSiteIdProperty($request), 'setSiteId(0) 应真的写进 Request 实例属性');
    }

    /**
     * 用例 2：site_id 存在、属于当前租户且 active → 放行
     */
    public function testExistingSiteOfCurrentTenantIsAllowed(): void
    {
        $request = $this->makeRequest([
            'X-Tenant-ID' => (string) self::CURRENT_TENANT_ID,
            'X-Site-ID'   => (string) self::SITE_OWN_ACTIVE,
        ]);

        $this->runMiddleware($request);

        $this->assertSame(self::SITE_OWN_ACTIVE, $request->siteId());
        $this->assertSame(self::SITE_OWN_ACTIVE, $this->readSiteIdProperty($request));
    }

    /**
     * 用例 3：site_id 不存在 → 403（reason=site_not_found），且未被 setSiteId
     */
    public function testUnknownSiteIsRejected(): void
    {
        $request = $this->makeRequest([
            'X-Tenant-ID' => (string) self::CURRENT_TENANT_ID,
            'X-Site-ID'   => '999',
        ]);

        $exception = $this->runMiddlewareExpectingRejection($request);

        $this->assertSame(InvalidSiteContextException::REASON_SITE_NOT_FOUND, $exception->getReason());
        $this->assertSame(ErrorCode::FORBIDDEN, $exception->getCode());
        $this->assertSame('999', $exception->getRawValue());
        $this->assertNull(
            $this->readSiteIdProperty($request),
            '被拒绝的请求不允许写入 setSiteId()，否则后续若有代码 catch 掉异常就会按该站点继续读写'
        );
    }

    /**
     * 用例 4：站点存在，但属于**别的租户** → 403（reason=site_tenant_mismatch）
     *
     * 这是本次收紧最关键的一条：改动前「租户 1 带租户 2 的 site_id」会被原样放行。
     */
    public function testSiteOfAnotherTenantIsRejected(): void
    {
        $request = $this->makeRequest([
            'X-Tenant-ID' => (string) self::CURRENT_TENANT_ID,
            'X-Site-ID'   => (string) self::SITE_OTHER_TENANT,
        ]);

        $exception = $this->runMiddlewareExpectingRejection($request);

        $this->assertSame(InvalidSiteContextException::REASON_SITE_TENANT_MISMATCH, $exception->getReason());
        $this->assertSame(ErrorCode::FORBIDDEN, $exception->getCode());
        $this->assertNull($this->readSiteIdProperty($request));

        // 同一条站点，换成它真正所属的租户 → 放行（证明拒绝的原因确实是「归属」而不是「状态/不存在」）
        $ownerRequest = $this->makeRequest([
            'X-Tenant-ID' => (string) self::FOREIGN_TENANT_ID,
            'X-Site-ID'   => (string) self::SITE_OTHER_TENANT,
        ]);
        $this->runMiddleware($ownerRequest);
        $this->assertSame(self::SITE_OTHER_TENANT, $ownerRequest->siteId());
    }

    /**
     * 用例 5：站点属于本租户但状态不是 active → 403（reason=site_unusable）
     *
     * 覆盖 `inactive` 与 `maintenance` 两种枚举值：只有 `active` 可以放行。
     */
    public function testUnusableSiteIsRejected(): void
    {
        foreach ([self::SITE_INACTIVE, self::SITE_MAINTENANCE] as $siteId) {
            $request = $this->makeRequest([
                'X-Tenant-ID' => (string) self::CURRENT_TENANT_ID,
                'X-Site-ID'   => (string) $siteId,
            ]);

            $exception = $this->runMiddlewareExpectingRejection($request);

            $this->assertSame(
                InvalidSiteContextException::REASON_SITE_UNUSABLE,
                $exception->getReason(),
                '站点 ' . $siteId . ' 属于本租户但状态不可用，应报 site_unusable'
            );
            $this->assertNull($this->readSiteIdProperty($request));
        }
    }

    /**
     * 用例 6：无 X-Site-ID → 放行，且站点维度保持「未提供」（null）
     */
    public function testMissingSiteHeaderKeepsSiteContextUnset(): void
    {
        $request = $this->makeRequest(['X-Tenant-ID' => (string) self::CURRENT_TENANT_ID]);

        $this->runMiddleware($request);

        $this->assertNull($request->siteIdOrNull(), '没带 X-Site-ID 时 siteIdOrNull() 必须是 null（不能伪造成显式 0）');
        $this->assertSame(0, $request->siteId(), 'siteId() 仍回落到全局桶 0，业务读写路径行为不变');
        $this->assertNull($this->readSiteIdProperty($request), '没带头时不应写入 Request 实例属性');
    }

    /**
     * 用例 7：有 X-Site-ID 但没有租户上下文 → 拒绝（reason=tenant_context_missing）
     *
     * 正常链路上 `TenantIdentify` 在前，不会出现；这里模拟「绕过租户识别直接调用本中间件」，
     * 要求 fail-closed —— 不允许在「不知道站点属于谁」的情况下放行。
     */
    public function testMissingTenantContextIsRejected(): void
    {
        $request = $this->makeRequest(['X-Site-ID' => (string) self::SITE_OWN_ACTIVE]);

        $exception = $this->runMiddlewareExpectingRejection($request);

        $this->assertSame(InvalidSiteContextException::REASON_TENANT_CONTEXT_MISSING, $exception->getReason());
        $this->assertNull($this->readSiteIdProperty($request));
    }

    /**
     * 附加用例：超出 `sites.id int unsigned` 上界的取值 → 拒绝（reason=site_id_out_of_range）
     *
     * 4294967296 = 2^32，刚好越过 unsigned int 上界；再补一个超长数字串，
     * 证明实现不是先 `(int)` 再比较（那一版会把超长串截成 `PHP_INT_MAX`）。
     */
    public function testOutOfRangeSiteIdIsRejected(): void
    {
        foreach (['4294967296', str_repeat('9', 40)] as $rawSiteId) {
            $request = $this->makeRequest([
                'X-Tenant-ID' => (string) self::CURRENT_TENANT_ID,
                'X-Site-ID'   => $rawSiteId,
            ]);

            $exception = $this->runMiddlewareExpectingRejection($request);

            $this->assertSame(
                InvalidSiteContextException::REASON_SITE_ID_OUT_OF_RANGE,
                $exception->getReason(),
                'X-Site-ID=' . substr($rawSiteId, 0, 12) . '... 应判为超出范围'
            );
        }

        // 上界本身（4294967295）不因「边界值」被拒，而是因为「不存在」被拒 —— 说明边界判断没差一
        $boundary = $this->makeRequest([
            'X-Tenant-ID' => (string) self::CURRENT_TENANT_ID,
            'X-Site-ID'   => '4294967295',
        ]);
        $this->assertSame(
            InvalidSiteContextException::REASON_SITE_NOT_FOUND,
            $this->runMiddlewareExpectingRejection($boundary)->getReason()
        );
    }

    /**
     * HTTP 实测 1：不存在 / 跨租户 / 停用站点经完整中间件链 → 403 + 业务码 2002
     *
     * 直调只能证明「抛了异常」，这里证明「HTTP 层真的被转成了 403/2002」
     * （`ExceptionHandle` 对 reason 的分流不影响状态码）。
     */
    public function testHttpRejectsBadSiteWith403AndCode2002(): void
    {
        $cases = [
            '999'                                 => InvalidSiteContextException::REASON_SITE_NOT_FOUND,
            (string) self::SITE_OTHER_TENANT      => InvalidSiteContextException::REASON_SITE_TENANT_MISMATCH,
            (string) self::SITE_INACTIVE          => InvalidSiteContextException::REASON_SITE_UNUSABLE,
        ];

        foreach ($cases as $rawSiteId => $expectedReason) {
            $response = $this->runHttp($this->makeRequest([
                'X-Tenant-ID' => (string) self::CURRENT_TENANT_ID,
                'X-Site-ID'   => (string) $rawSiteId,
            ]));

            $this->assertSame(403, $response->getCode(), 'X-Site-ID=' . $rawSiteId . ' 应返回 403');

            $body = json_decode((string) $response->getContent(), true);
            $this->assertIsArray($body);
            $this->assertSame(2002, (int) $body['code'], 'X-Site-ID=' . $rawSiteId . ' 的业务码应为 2002');

            // 「不存在」与「属于别的租户」的响应正文必须一致（不泄露站点存在性）
            if ($expectedReason === InvalidSiteContextException::REASON_SITE_NOT_FOUND) {
                $this->assertStringNotContainsString('site_not_found', (string) $body['message']);
                $this->assertStringNotContainsString('site_tenant_mismatch', (string) $body['message']);
            }
        }
    }

    /**
     * HTTP 实测 2：存在且可用的站点 / 显式 0 / 无请求头 → 都不被 403
     *
     * 路径本身不存在（404）是预期的 —— 这里只关心站点中间件有没有误伤放行路径。
     */
    public function testHttpAllowsValidSiteZeroAndMissingHeader(): void
    {
        $headers = [
            'existing site' => [
                'X-Tenant-ID' => (string) self::CURRENT_TENANT_ID,
                'X-Site-ID'   => (string) self::SITE_OWN_ACTIVE,
            ],
            'explicit zero' => [
                'X-Tenant-ID' => (string) self::CURRENT_TENANT_ID,
                'X-Site-ID'   => '0',
            ],
            'missing header' => [
                'X-Tenant-ID' => (string) self::CURRENT_TENANT_ID,
            ],
        ];

        foreach ($headers as $case => $caseHeaders) {
            $response = $this->runHttp($this->makeRequest($caseHeaders));

            $this->assertNotSame(403, $response->getCode(), $case . ' 不应被站点中间件拒绝');

            $body = json_decode((string) $response->getContent(), true);
            if (is_array($body) && array_key_exists('code', $body)) {
                $this->assertNotSame(2002, (int) $body['code'], $case . ' 不应命中站点鉴权的业务码 2002');
            }
        }
    }

    /**
     * 构造一个请求对象（方法 GET、路径为不存在的探针路径）
     *
     * ⚠️ 第三个参数 `$newInstance = true` 是必需的，不是写法偏好：
     *   1. `Container::make()` 会把解析结果缓存进 `instances`，同一个测试方法里连续 `make()`
     *      拿到的是**同一个** `app\Request`；
     *   2. `withHeader()` 是原地修改并 `return $this`（不是克隆）；
     *   3. `tenantIdOrNull()` 读 header 后会把结果**写回实例属性**（`$this->tenantId`）。
     * 三条叠加的后果：方法内第二个请求会带着上一个请求的 `tenantId`，于是「换成站点真正
     * 所属的租户再试一次」这种断言会莫名其妙地失败。强制新建实例才能让每个请求自带干净上下文
     * （实测：不加 `true` 时 `testSiteOfAnotherTenantIsRejected` 的第二段必然报
     * `site_tenant_mismatch`，因为租户还停在上一次解析的 1）。
     *
     * @param array<string, string> $headers 请求头
     */
    private function makeRequest(array $headers): Request
    {
        /** @var Request $request */
        $request = $this->app()->make(\think\Request::class, [], true);
        $request->setMethod('GET');
        $request->setPathinfo(self::PROBE_PATH);

        /** @var Request $request */
        $request = $request->withHeader($headers);

        return $request;
    }

    /**
     * 直调中间件（期望放行）
     */
    private function runMiddleware(Request $request): void
    {
        $middleware = new SiteIdentify();
        $response = $middleware->handle($request, static fn () => Response::create('ok', 'html', 200));

        $this->assertSame(200, $response->getCode(), '中间件应继续到下一个处理环节');
    }

    /**
     * 直调中间件（期望拒绝），返回抛出的异常供断言 reason
     */
    private function runMiddlewareExpectingRejection(Request $request): InvalidSiteContextException
    {
        try {
            (new SiteIdentify())->handle($request, static fn () => Response::create('ok', 'html', 200));
        } catch (InvalidSiteContextException $e) {
            return $e;
        }

        $this->fail('预期被 SiteIdentify 拒绝，但请求被放行了');
    }

    /**
     * 读取 `app\Request` 的 `siteId` 私有状态（区分「有没有被 setSiteId 写过」）
     *
     * 用反射而不是 `siteId()`：后者会**回落到请求头**，无法证明中间件有没有真的写入。
     */
    private function readSiteIdProperty(Request $request): ?int
    {
        $property = new ReflectionProperty(Request::class, 'siteId');
        $property->setAccessible(true);

        $value = $property->getValue($request);

        return $value === null ? null : (int) $value;
    }

    /**
     * 写入夹具：一个跨租户对照租户 + 四个站点（active / 别的租户 / inactive / maintenance）
     */
    private function seedFixtures(): void
    {
        $now = date('Y-m-d H:i:s');

        // sites.tenant_id 有外键 → tenants.id，跨租户对照租户必须先存在
        Db::name('tenants')->insert([
            'id'         => self::FOREIGN_TENANT_ID,
            'name'       => 'SiteIdentify Fixture Tenant',
            'slug'       => 'site-identify-fixture-tenant',
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Db::name('sites')->insertAll([
            [
                'id'        => self::SITE_OWN_ACTIVE,
                'tenant_id' => self::CURRENT_TENANT_ID,
                'name'      => 'SiteIdentify Active Fixture',
                'slug'      => 'site-identify-active-9001',
                'domain'    => null,
                'status'    => 'active',
                'theme'     => 'default',
            ],
            [
                'id'        => self::SITE_OTHER_TENANT,
                'tenant_id' => self::FOREIGN_TENANT_ID,
                'name'      => 'SiteIdentify Other Tenant Fixture',
                'slug'      => 'site-identify-other-tenant-9002',
                'domain'    => null,
                'status'    => 'active',
                'theme'     => 'default',
            ],
            [
                'id'        => self::SITE_INACTIVE,
                'tenant_id' => self::CURRENT_TENANT_ID,
                'name'      => 'SiteIdentify Inactive Fixture',
                'slug'      => 'site-identify-inactive-9003',
                'domain'    => null,
                'status'    => 'inactive',
                'theme'     => 'default',
            ],
            [
                'id'        => self::SITE_MAINTENANCE,
                'tenant_id' => self::CURRENT_TENANT_ID,
                'name'      => 'SiteIdentify Maintenance Fixture',
                'slug'      => 'site-identify-maintenance-9004',
                'domain'    => null,
                'status'    => 'maintenance',
                'theme'     => 'default',
            ],
        ]);
    }

    /**
     * 删除夹具（先删站点再删租户：外键是 CASCADE，但显式顺序更好读也好排障）
     */
    private function removeFixtures(): void
    {
        Db::name('sites')->whereIn('id', [
            self::SITE_OWN_ACTIVE,
            self::SITE_OTHER_TENANT,
            self::SITE_INACTIVE,
            self::SITE_MAINTENANCE,
        ])->delete();

        Db::name('tenants')->where('id', self::FOREIGN_TENANT_ID)->delete();
    }
}
