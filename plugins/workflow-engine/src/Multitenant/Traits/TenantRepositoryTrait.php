<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Multitenant\Traits;

use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;

/**
 * Tenant Repository Trait | 多租户仓储特性
 *
 * Provides implementation for TenantRepositoryInterface. Can be used by
 * repositories to add tenant-aware query functionality.
 * 提供 TenantRepositoryInterface 的实现。可用于仓储类添加租户感知查询功能。
 *
 * @package Alkaid\Workflow\Engine\Multitenant\Traits
 */
trait TenantRepositoryTrait
{
    /**
     * Current tenant scope | 当前租户范围
     *
     * @var int|null
     */
    protected ?int $tenantScope = null;

    /**
     * Whether tenant scope is enabled | 是否启用租户范围
     *
     * @var bool
     */
    protected bool $tenantScopeEnabled = true;

    /**
     * Whether to auto-detect tenant from context | 是否从上下文自动检测租户
     *
     * @var bool
     */
    protected bool $autoDetectTenant = true;

    /**
     * Set tenant scope for queries | 设置查询的租户范围
     *
     * @param int $tenantId Tenant ID
     * @return static
     */
    public function forTenant(int $tenantId): static
    {
        $this->tenantScope = $tenantId;
        $this->tenantScopeEnabled = true;
        return $this;
    }

    /**
     * Get current tenant scope | 获取当前租户范围
     *
     * @return int|null
     */
    public function getCurrentTenantScope(): ?int
    {
        if (!$this->tenantScopeEnabled) {
            return null;
        }

        if ($this->tenantScope !== null) {
            return $this->tenantScope;
        }

        if ($this->autoDetectTenant) {
            return TenantContext::getTenantId();
        }

        return null;
    }

    /**
     * Disable tenant filtering (for admin/system operations) | 禁用租户过滤
     *
     * @return static
     */
    public function withoutTenantScope(): static
    {
        $this->tenantScopeEnabled = false;
        return $this;
    }

    /**
     * Re-enable tenant filtering | 重新启用租户过滤
     *
     * @return static
     */
    public function withTenantScope(): static
    {
        $this->tenantScopeEnabled = true;
        return $this;
    }

    /**
     * Check if tenant scope is active | 检查租户范围是否激活
     *
     * @return bool
     */
    public function hasTenantScope(): bool
    {
        return $this->tenantScopeEnabled && $this->getCurrentTenantScope() !== null;
    }

    /**
     * Reset tenant scope to default (auto-detect from context) | 重置租户范围
     *
     * @return static
     */
    public function resetTenantScope(): static
    {
        $this->tenantScope = null;
        $this->tenantScopeEnabled = true;
        $this->autoDetectTenant = true;
        return $this;
    }

    /**
     * Set auto-detect tenant setting | 设置自动检测租户
     *
     * @param bool $autoDetect
     * @return static
     */
    public function setAutoDetectTenant(bool $autoDetect): static
    {
        $this->autoDetectTenant = $autoDetect;
        return $this;
    }

    /**
     * Apply tenant filter to query conditions | 应用租户过滤到查询条件
     *
     * @param array $conditions Existing conditions
     * @return array Modified conditions
     */
    protected function applyTenantFilter(array $conditions): array
    {
        $tenantId = $this->getCurrentTenantScope();

        if ($tenantId !== null) {
            $conditions['tenant_id'] = $tenantId;
        }

        return $conditions;
    }

    /**
     * Apply tenant ID to data before save | 保存前应用租户ID到数据
     *
     * @param array $data Data to save
     * @return array Modified data
     * @throws TenantIsolationException If tenant ID is required but not available
     */
    protected function applyTenantToData(array $data): array
    {
        $tenantId = $this->getCurrentTenantScope();

        if ($tenantId === null && TenantContext::isIsolationEnforced()) {
            throw new TenantIsolationException(
                'Cannot save data without tenant ID when isolation is enforced'
            );
        }

        if ($tenantId !== null) {
            $data['tenant_id'] = $tenantId;
        }

        return $data;
    }

    /**
     * Validate entity belongs to current tenant | 验证实体属于当前租户
     *
     * @param array|object $entity Entity to validate
     * @return bool
     * @throws TenantIsolationException If validation fails
     */
    protected function validateEntityTenant(array|object $entity): bool
    {
        if (!TenantContext::isIsolationEnforced()) {
            return true;
        }

        $tenantId = $this->getCurrentTenantScope();
        if ($tenantId === null) {
            return true; // No scope means no restriction
        }

        $entityTenantId = is_array($entity)
            ? ($entity['tenant_id'] ?? null)
            : ($entity->tenantId ?? $entity->getTenantId ?? null);

        if ($entityTenantId !== null && $entityTenantId !== $tenantId) {
            throw new TenantIsolationException(
                "Entity belongs to tenant {$entityTenantId}, not current tenant {$tenantId}"
            );
        }

        return true;
    }

    /**
     * Get tenant column name (can be overridden) | 获取租户列名
     *
     * @return string
     */
    protected function getTenantColumn(): string
    {
        return 'tenant_id';
    }
}
