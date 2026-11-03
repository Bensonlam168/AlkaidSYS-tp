<?php

declare(strict_types=1);

namespace Infrastructure\Tenant\Service;

use app\constant\ErrorCode;
use app\exception\ForbiddenException;
use Domain\Tenant\Model\Tenant;
use Infrastructure\Tenant\Repository\TenantRepository;
use think\facade\Db;
use think\facade\Env;
use think\facade\Log;
use think\Request;

/**
 * 注册租户解析器 | Tenant resolver for self-service registration
 *
 * 背景（安全修复 C-4）：
 *   注册接口此前直接采用客户端可控制的 `X-Tenant-ID` 头决定用户落库租户，
 *   导致任何未认证者可为任意租户创建账号并取得该租户的合法 JWT。
 *
 * 解析策略（**永不信任客户端请求头**）：
 *   1. 域名绑定：请求 Host 命中 `tenants.domain`（唯一索引）→ 使用该租户；
 *   2. 服务端配置：`REGISTRATION_TENANT_ID` 环境变量指定 → 使用该租户；
 *   3. 以上均未命中 → 拒绝注册（fail-closed）。
 *
 * 命中后仍需校验租户可用性：状态为 active、未过期、未超出 max_users。
 *
 * @package Infrastructure\Tenant\Service
 */
class TenantRegistrationResolver
{
    /**
     * 租户仓储
     */
    private TenantRepository $tenantRepository;

    /**
     * 构造函数
     *
     * @param TenantRepository|null $tenantRepository 可注入（便于测试）
     */
    public function __construct(?TenantRepository $tenantRepository = null)
    {
        $this->tenantRepository = $tenantRepository ?? new TenantRepository();
    }

    /**
     * 解析注册目标租户
     *
     * @param Request $request 当前请求
     * @return Tenant 可用的租户
     * @throws ForbiddenException 无法解析或租户不可用时抛出（code=2008）
     */
    public function resolve(Request $request): Tenant
    {
        $tenant = $this->resolveByDomain($request);
        $source = 'domain';

        if ($tenant === null) {
            $tenant = $this->resolveByConfig();
            $source = 'config';
        }

        if ($tenant === null) {
            $this->reject('no_registration_tenant', null, '当前部署未开放自助注册');
        }

        $this->assertUsable($tenant, $source);

        Log::info('[TENANT_REGISTRATION_ALLOWED] Registration tenant resolved', [
            'tenant_id'   => $tenant->getId(),
            'tenant_slug' => $tenant->getSlug(),
            'source'      => $source,
            'host'        => $this->extractHost($request),
            'trace_id'    => $this->getTraceId($request),
        ]);

        return $tenant;
    }

    /**
     * 按请求 Host 匹配租户自定义域名
     *
     * @param Request $request 当前请求
     * @return Tenant|null
     */
    private function resolveByDomain(Request $request): ?Tenant
    {
        $host = $this->extractHost($request);
        if ($host === '') {
            return null;
        }

        $tenant = $this->tenantRepository->findByDomain($host);
        if ($tenant === null) {
            return null;
        }

        return $tenant;
    }

    /**
     * 按服务端配置解析默认注册租户
     *
     * @return Tenant|null
     */
    private function resolveByConfig(): ?Tenant
    {
        $configured = Env::get('REGISTRATION_TENANT_ID', '');
        if ($configured === '' || $configured === null) {
            return null;
        }

        $tenantId = (int) $configured;
        if ($tenantId <= 0) {
            return null;
        }

        return $this->tenantRepository->findById($tenantId);
    }

    /**
     * 校验租户是否可用于注册
     *
     * @param Tenant $tenant 租户
     * @param string $source 解析来源（用于日志与错误提示）
     * @return void
     * @throws ForbiddenException
     */
    private function assertUsable(Tenant $tenant, string $source): void
    {
        // 1. 状态必须为 active
        if (!$tenant->isActive()) {
            $this->reject('tenant_not_active', $tenant, '该租户当前不可注册', [
                'status' => $tenant->getStatus(),
                'source' => $source,
            ]);
        }

        // 2. 订阅未过期
        $expiresAt = $tenant->toArray()['expires_at'] ?? null;
        if (!empty($expiresAt) && strtotime((string) $expiresAt) !== false
            && strtotime((string) $expiresAt) < time()) {
            $this->reject('tenant_expired', $tenant, '该租户订阅已过期', [
                'expires_at' => $expiresAt,
                'source'     => $source,
            ]);
        }

        // 3. 用户数未超出上限（max_users <= 0 视为不限）
        $maxUsers = $tenant->getMaxUsers();
        if ($maxUsers > 0) {
            $current = (int) Db::name('users')
                ->where('tenant_id', $tenant->getId())
                ->count();
            if ($current >= $maxUsers) {
                $this->reject('tenant_user_limit_reached', $tenant, '该租户用户数已达上限', [
                    'max_users' => $maxUsers,
                    'current'   => $current,
                    'source'    => $source,
                ]);
            }
        }
    }

    /**
     * 提取规范化 Host（去端口、转小写）
     *
     * @param Request $request 当前请求
     * @return string 无 Host 时返回空串
     */
    private function extractHost(Request $request): string
    {
        $host = (string) $request->header('host', '');
        if ($host === '') {
            $host = (string) $request->host();
        }
        if ($host === '') {
            return '';
        }

        // 去掉端口，例如 tenant-a.example.com:8080 → tenant-a.example.com
        $host = strtolower(trim(explode(':', $host)[0]));

        return $host;
    }

    /**
     * 统一拒绝出口（记录审计日志后抛出）
     *
     * @param string $reason 拒绝原因（机器可读）
     * @param Tenant|null $tenant 相关租户（可能为空）
     * @param string $message 面向客户端的提示
     * @param array $context 附加上下文
     * @return never
     * @throws ForbiddenException
     */
    private function reject(string $reason, ?Tenant $tenant, string $message, array $context = []): void
    {
        Log::warning('[TENANT_REGISTRATION_REJECTED] Registration tenant rejected', array_merge([
            'reason'    => $reason,
            'tenant_id' => $tenant?->getId(),
            'ip'        => request()->ip() ?? null,
        ], $context));

        throw new ForbiddenException($message, ErrorCode::REGISTRATION_NOT_ALLOWED);
    }

    /**
     * 读取 trace_id（若可得）
     *
     * @param Request $request 当前请求
     * @return string|null
     */
    private function getTraceId(Request $request): ?string
    {
        if (method_exists($request, 'getTraceId')) {
            $traceId = $request->getTraceId();
            return is_string($traceId) && $traceId !== '' ? $traceId : null;
        }

        return null;
    }
}
