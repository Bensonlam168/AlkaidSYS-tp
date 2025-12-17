<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Multitenant\Interfaces;

/**
 * Tenant Repository Interface | 多租户仓储接口
 *
 * Defines the contract for repositories that support tenant-aware data access.
 * 定义支持租户感知数据访问的仓储契约。
 *
 * @package Alkaid\Workflow\Engine\Multitenant\Interfaces
 */
interface TenantRepositoryInterface
{
    /**
     * Set tenant scope for queries | 设置查询的租户范围
     *
     * @param int $tenantId Tenant ID
     * @return static
     */
    public function forTenant(int $tenantId): static;

    /**
     * Get current tenant scope | 获取当前租户范围
     *
     * @return int|null
     */
    public function getCurrentTenantScope(): ?int;

    /**
     * Disable tenant filtering (for admin/system operations) | 禁用租户过滤
     *
     * @return static
     */
    public function withoutTenantScope(): static;

    /**
     * Re-enable tenant filtering | 重新启用租户过滤
     *
     * @return static
     */
    public function withTenantScope(): static;

    /**
     * Check if tenant scope is active | 检查租户范围是否激活
     *
     * @return bool
     */
    public function hasTenantScope(): bool;
}
