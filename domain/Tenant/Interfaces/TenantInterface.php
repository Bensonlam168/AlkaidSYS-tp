<?php

declare(strict_types=1);

namespace Domain\Tenant\Interfaces;

/**
 * Tenant Interface | 租户接口
 *
 * @package Domain\Tenant\Interfaces
 */
interface TenantInterface
{
    /**
     * Get tenant ID | 获取租户ID
     */
    public function getId(): ?int;

    /**
     * Get tenant name | 获取租户名称
     */
    public function getName(): string;

    /**
     * Get tenant slug | 获取租户标识符
     */
    public function getSlug(): string;

    /**
     * Get custom domain | 获取自定义域名
     */
    public function getDomain(): ?string;

    /**
     * Get tenant status | 获取租户状态
     */
    public function getStatus(): string;

    /**
     * Check if tenant is active | 检查租户是否激活
     */
    public function isActive(): bool;

    /**
     * Check if tenant is suspended | 检查租户是否暂停
     */
    public function isSuspended(): bool;

    /**
     * Get tenant configuration | 获取租户配置
     */
    public function getConfig(): array;

    /**
     * Convert to array | 转换为数组
     */
    public function toArray(): array;

    /**
     * Load from array | 从数组加载
     */
    public function fromArray(array $data): self;
}
