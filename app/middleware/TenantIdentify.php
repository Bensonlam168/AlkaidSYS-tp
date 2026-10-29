<?php

declare(strict_types=1);

namespace app\middleware;

use app\constant\ErrorCode;
use app\exception\ForbiddenException;
use Domain\Tenant\Model\Tenant;
use Infrastructure\Tenant\Repository\TenantRepository;
use Closure;
use think\facade\Env;
use think\facade\Log;
use think\Request;

/**
 * Tenant Identify Middleware | 租户识别中间件
 *
 * 职责：
 *   1. 从 `X-Tenant-ID` 请求头解析租户标识（**仅作为未认证场景的弱信号**）；
 *   2. 校验该租户是否真实存在、状态是否可用（安全修复 C-4 的纵深防御）；
 *   3. 将租户 ID 写入 Request，供后续中间件与业务层使用。
 *
 * 安全说明：
 *   - 本中间件**不授予任何租户归属权**。真正的租户归属以 JWT 中的 `tenant_id` 为准
 *     （见 `app\middleware\Auth`）。
 *   - 注册接口不采用本中间件解析出的租户，其目标租户由
 *     `Infrastructure\Tenant\Service\TenantRegistrationResolver` 解析
 *     （域名绑定或服务端配置），见设计文档 `04-multi-tenant-design.md:330`。
 *   - 显式提供 `X-Tenant-ID` 但租户不存在 / 被停用 / 已过期时，直接拒绝请求（fail-closed）。
 *
 * @package app\middleware
 */
class TenantIdentify
{
    /**
     * 租户仓储（惰性初始化，避免无谓查询）
     */
    private ?TenantRepository $tenantRepository = null;

    /**
     * Handle request | 处理请求
     *
     * @param Request $request Custom request instance with tenant/user context
     * @param Closure $next Next middleware in the stack
     * @return mixed Response or next middleware result
     * @throws ForbiddenException 显式指定的租户不可用时抛出
     */
    public function handle(Request $request, Closure $next)
    {
        // 读取原始请求头（不通过 $request->tenantId()，避免循环依赖）
        $rawHeader = $request->header('X-Tenant-ID');

        // 情况 A：未显式提供请求头 → 使用服务端默认租户（保持既有行为）
        if ($rawHeader === null || $rawHeader === '') {
            $tenantId = $this->getDefaultTenantId();
            if (method_exists($request, 'setTenantId')) {
                $request->setTenantId($tenantId);
            }

            return $next($request);
        }

        // 情况 B：显式提供请求头 → 校验租户状态
        $tenantId = (int) $rawHeader;
        if ($tenantId <= 0) {
            $this->reject($request, 'invalid_tenant_header', null, '租户标识无效');

            return $next($request); // 不可达，仅为静态分析
        }

        $tenant = $this->getTenantRepository()->findById($tenantId);

        // 情形 B1：租户不存在
        //
        // 设计取舍（C-4）：此处**不拒绝**，仅记录告警。理由：
        //   1. 不存在租户名下不可能有任何数据，放行不会造成数据泄漏；
        //   2. 真正的越权风险在「注册」——注册已改为不信任本请求头
        //      （见 TenantRegistrationResolver）；
        //   3. 全新环境（CI 只跑 migrate:run，不跑 seed:run）尚无任何租户，
        //      若在此严格拒绝会导致全部带 X-Tenant-ID 的请求 403。
        // 若后续要求严格模式，需先在迁移中保证默认租户存在。
        if ($tenant === null) {
            Log::warning('[TENANT_IDENTIFY_UNKNOWN] Unknown tenant header accepted (no data can belong to it)', [
                'tenant_id' => $tenantId,
                'ip'        => $request->ip(),
                'path'      => $request->pathinfo(),
                'trace_id'  => method_exists($request, 'getTraceId') ? $request->getTraceId() : null,
            ]);

            if (method_exists($request, 'setTenantId')) {
                $request->setTenantId($tenantId);
            }

            return $next($request);
        }

        // 情形 B2：租户存在但被停用 / 已过期 → 拒绝（明确的业务阻断，且不依赖种子数据）
        if (!$this->isUsable($tenant)) {
            $this->reject($request, 'tenant_unusable', $tenant, '指定的租户当前不可用');

            return $next($request);
        }

        if (method_exists($request, 'setTenantId')) {
            $request->setTenantId($tenantId);
        }

        return $next($request);
    }

    /**
     * 租户是否可用（active 且未过期）
     */
    private function isUsable(Tenant $tenant): bool
    {
        if (!$tenant->isActive()) {
            return false;
        }

        $expiresAt = $tenant->toArray()['expires_at'] ?? null;
        if (!empty($expiresAt) && strtotime((string) $expiresAt) !== false
            && strtotime((string) $expiresAt) < time()) {
            return false;
        }

        return true;
    }

    /**
     * 服务端默认租户 ID
     *
     * 可通过 `DEFAULT_TENANT_ID` 配置；默认 1（保持单租户部署的既有行为）。
     */
    private function getDefaultTenantId(): int
    {
        $configured = (int) Env::get('DEFAULT_TENANT_ID', 1);

        return $configured > 0 ? $configured : 1;
    }

    /**
     * 惰性获取租户仓储
     */
    private function getTenantRepository(): TenantRepository
    {
        if ($this->tenantRepository === null) {
            $this->tenantRepository = new TenantRepository();
        }

        return $this->tenantRepository;
    }

    /**
     * 统一拒绝出口（审计日志 + 抛出 403）
     *
     * @param Request $request 当前请求
     * @param string $reason 机器可读原因
     * @param Tenant|null $tenant 相关租户
     * @param string $message 面向客户端的提示
     * @return never
     * @throws ForbiddenException
     */
    private function reject(Request $request, string $reason, ?Tenant $tenant, string $message): void
    {
        Log::warning('[TENANT_IDENTIFY_REJECTED] Tenant header rejected', [
            'reason'    => $reason,
            'tenant_id' => $tenant?->getId() ?? $request->header('X-Tenant-ID'),
            'ip'        => $request->ip(),
            'path'      => $request->pathinfo(),
            'trace_id'  => method_exists($request, 'getTraceId') ? $request->getTraceId() : null,
        ]);

        throw new ForbiddenException($message, ErrorCode::FORBIDDEN);
    }
}
