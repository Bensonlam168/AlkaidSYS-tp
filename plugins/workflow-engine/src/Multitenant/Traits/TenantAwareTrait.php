<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Multitenant\Traits;

use Alkaid\Workflow\Engine\Multitenant\TenantContext;

/**
 * Tenant Aware Trait | 多租户感知特性
 *
 * Provides implementation for TenantAwareInterface. Can be used by
 * entities, models, and DTOs that need tenant awareness.
 * 提供 TenantAwareInterface 的实现。可用于需要租户感知的实体、模型和 DTO。
 *
 * @package Alkaid\Workflow\Engine\Multitenant\Traits
 */
trait TenantAwareTrait
{
    /**
     * Tenant ID | 租户ID
     *
     * @var int|null
     */
    protected ?int $tenantId = null;

    /**
     * Get tenant ID | 获取租户ID
     *
     * @return int|null
     */
    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    /**
     * Set tenant ID | 设置租户ID
     *
     * @param int $tenantId Tenant ID
     * @return static
     */
    public function setTenantId(int $tenantId): static
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    /**
     * Check if belongs to specified tenant | 检查是否属于指定租户
     *
     * @param int $tenantId Tenant ID to check
     * @return bool
     */
    public function belongsToTenant(int $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    /**
     * Initialize tenant ID from context if not set | 如果未设置，从上下文初始化租户ID
     *
     * @return static
     */
    public function initializeTenantFromContext(): static
    {
        if ($this->tenantId === null) {
            $this->tenantId = TenantContext::getTenantId();
        }
        return $this;
    }

    /**
     * Validate tenant matches current context | 验证租户是否与当前上下文匹配
     *
     * @return bool
     */
    public function validateTenantContext(): bool
    {
        $currentTenantId = TenantContext::getTenantId();

        if ($currentTenantId === null) {
            return true; // No tenant context means no restriction
        }

        return $this->tenantId === $currentTenantId;
    }
}
