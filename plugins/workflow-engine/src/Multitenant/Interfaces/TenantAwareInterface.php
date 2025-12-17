<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Multitenant\Interfaces;

/**
 * Tenant Aware Interface | 多租户感知接口
 *
 * Defines the contract for entities/services that are aware of tenant context.
 * Classes implementing this interface can be automatically filtered and validated
 * for tenant isolation.
 * 定义对租户上下文感知的实体/服务的契约。
 * 实现此接口的类可以自动过滤和验证租户隔离。
 *
 * @package Alkaid\Workflow\Engine\Multitenant\Interfaces
 */
interface TenantAwareInterface
{
    /**
     * Get tenant ID | 获取租户ID
     *
     * @return int|null
     */
    public function getTenantId(): ?int;

    /**
     * Set tenant ID | 设置租户ID
     *
     * @param int $tenantId Tenant ID
     * @return static
     */
    public function setTenantId(int $tenantId): static;

    /**
     * Check if belongs to specified tenant | 检查是否属于指定租户
     *
     * @param int $tenantId Tenant ID to check
     * @return bool
     */
    public function belongsToTenant(int $tenantId): bool;
}
