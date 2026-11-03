<?php

declare(strict_types=1);

namespace Tests\Unit\Tenant;

use app\constant\ErrorCode;
use app\exception\ForbiddenException;
use Domain\Tenant\Model\Tenant;
use Infrastructure\Tenant\Repository\TenantRepository;
use Infrastructure\Tenant\Service\TenantRegistrationResolver;
use Mockery;
use Mockery\MockInterface;
use Tests\ThinkPHPTestCase;
use think\facade\Env;

/**
 * 注册租户解析器安全测试（C-4）
 *
 * 验证核心安全属性：注册的目标租户**不得**来自客户端可控制的 `X-Tenant-ID`，
 * 只能来自「租户域名绑定」或「服务端配置 REGISTRATION_TENANT_ID」，
 * 且必须校验租户状态（active、未过期、未超 max_users）。
 *
 * 说明：此处为确定性单元测试，不经过 HTTP 栈，
 * 避免已知的 `Request` 单例 / 路由规则跨测试污染（见代码质量报告 I-3）。
 *
 * @package Tests\Unit\Tenant
 */
class TenantRegistrationResolverTest extends ThinkPHPTestCase
{
    /** @var TenantRepository&MockInterface */
    private $tenantRepository;

    private ?string $originalRegistrationTenantId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 复用 ThinkPHPTestCase 的共享 App 实例，避免创建第二个 App 导致与其他用例冲突
        $this->originalRegistrationTenantId = Env::get('REGISTRATION_TENANT_ID');
        Env::set('REGISTRATION_TENANT_ID', '');

        $this->tenantRepository = Mockery::mock(TenantRepository::class);
    }

    protected function tearDown(): void
    {
        Env::set('REGISTRATION_TENANT_ID', (string) ($this->originalRegistrationTenantId ?? ''));
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 构造带指定 Host 与 X-Tenant-ID 的请求
     */
    private function makeRequest(string $host = '', int $headerTenantId = 0): \app\Request
    {
        $headers = [];
        if ($host !== '') {
            $headers['host'] = $host;
        }
        if ($headerTenantId > 0) {
            $headers['X-Tenant-ID'] = (string) $headerTenantId;
        }

        $request = new \app\Request();
        if (!empty($headers)) {
            $request = $request->withHeader($headers);
        }

        return $request;
    }

    /**
     * 构造可用租户
     */
    private function makeTenant(int $id = 9902, string $status = 'active', int $maxUsers = 0): Tenant
    {
        $tenant = new Tenant('Test Tenant', 'test-tenant');
        $tenant->setId($id);
        $tenant->setStatus($status);
        $tenant->setMaxUsers($maxUsers);

        return $tenant;
    }

    // -----------------------------------------------------------------------
    // 核心安全属性
    // -----------------------------------------------------------------------

    /**
     * 客户端 X-Tenant-ID 不得影响注册目标租户
     */
    public function testResolveIgnoresClientTenantHeader(): void
    {
        $configTenant = $this->makeTenant(9902);
        $attackerTenant = $this->makeTenant(9903);

        // 域名不匹配
        $this->tenantRepository->shouldReceive('findByDomain')->andReturnNull();
        // 服务端配置指向 9902
        Env::set('REGISTRATION_TENANT_ID', '9902');
        $this->tenantRepository->shouldReceive('findById')->with(9902)->andReturn($configTenant);
        // 攻击者指定的 9903 不应被查询到（若被查询则会返回该租户）
        $this->tenantRepository->shouldReceive('findById')->with(9903)->andReturn($attackerTenant);

        $resolver = new TenantRegistrationResolver($this->tenantRepository);

        // 请求头伪造 9903
        $tenant = $resolver->resolve($this->makeRequest('evil.example.com', 9903));

        $this->assertSame(9902, $tenant->getId(), '注册租户必须来自服务端配置，而非 X-Tenant-ID');
    }

    /**
     * 优先使用域名绑定
     */
    public function testResolvePrefersDomainBinding(): void
    {
        $domainTenant = $this->makeTenant(9904);

        $this->tenantRepository->shouldReceive('findByDomain')
            ->with('tenant-a.example.com')
            ->andReturn($domainTenant);
        // 域名命中后不应读取配置
        Env::set('REGISTRATION_TENANT_ID', '9902');
        $this->tenantRepository->shouldNotReceive('findById');

        $resolver = new TenantRegistrationResolver($this->tenantRepository);
        $tenant = $resolver->resolve($this->makeRequest('tenant-a.example.com:8080'));

        $this->assertSame(9904, $tenant->getId(), 'Host（去端口后）应命中域名绑定租户');
    }

    /**
     * 既无域名绑定也无配置 → 拒绝注册（fail-closed）
     */
    public function testResolveRejectsWhenNoTenantResolvable(): void
    {
        $this->tenantRepository->shouldReceive('findByDomain')->andReturnNull();

        $resolver = new TenantRegistrationResolver($this->tenantRepository);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(ErrorCode::REGISTRATION_NOT_ALLOWED);

        $resolver->resolve($this->makeRequest('unknown.example.com'));
    }

    /**
     * 配置指向不存在的租户 → 拒绝
     */
    public function testResolveRejectsWhenConfiguredTenantMissing(): void
    {
        $this->tenantRepository->shouldReceive('findByDomain')->andReturnNull();
        Env::set('REGISTRATION_TENANT_ID', '9999');
        $this->tenantRepository->shouldReceive('findById')->with(9999)->andReturnNull();

        $resolver = new TenantRegistrationResolver($this->tenantRepository);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(ErrorCode::REGISTRATION_NOT_ALLOWED);

        $resolver->resolve($this->makeRequest());
    }

    // -----------------------------------------------------------------------
    // 租户状态校验
    // -----------------------------------------------------------------------

    /**
     * 停用租户 → 拒绝
     */
    public function testResolveRejectsSuspendedTenant(): void
    {
        $suspended = $this->makeTenant(9902, 'suspended');

        $this->tenantRepository->shouldReceive('findByDomain')->andReturn($suspended);

        $resolver = new TenantRegistrationResolver($this->tenantRepository);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(ErrorCode::REGISTRATION_NOT_ALLOWED);

        $resolver->resolve($this->makeRequest('tenant-a.example.com'));
    }

    /**
     * inactive 租户 → 拒绝
     */
    public function testResolveRejectsInactiveTenant(): void
    {
        $inactive = $this->makeTenant(9902, 'inactive');

        $this->tenantRepository->shouldReceive('findByDomain')->andReturn($inactive);

        $resolver = new TenantRegistrationResolver($this->tenantRepository);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(ErrorCode::REGISTRATION_NOT_ALLOWED);

        $resolver->resolve($this->makeRequest('tenant-a.example.com'));
    }

    /**
     * 订阅已过期 → 拒绝
     */
    public function testResolveRejectsExpiredTenant(): void
    {
        $expired = $this->makeTenant(9902);
        $expired->fromArray(['expires_at' => date('Y-m-d H:i:s', time() - 86400)]);

        $this->tenantRepository->shouldReceive('findByDomain')->andReturn($expired);

        $resolver = new TenantRegistrationResolver($this->tenantRepository);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(ErrorCode::REGISTRATION_NOT_ALLOWED);

        $resolver->resolve($this->makeRequest('tenant-a.example.com'));
    }

    /**
     * 正常可用租户 → 通过
     */
    public function testResolveAcceptsActiveTenant(): void
    {
        $active = $this->makeTenant(9902);

        $this->tenantRepository->shouldReceive('findByDomain')->andReturn($active);

        $resolver = new TenantRegistrationResolver($this->tenantRepository);
        $tenant = $resolver->resolve($this->makeRequest('tenant-a.example.com'));

        $this->assertSame(9902, $tenant->getId());
        $this->assertTrue($tenant->isActive());
    }
}
