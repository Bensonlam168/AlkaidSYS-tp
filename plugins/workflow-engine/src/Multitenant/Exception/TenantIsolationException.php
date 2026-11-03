<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Multitenant\Exception;

use RuntimeException;

/**
 * Tenant Isolation Exception | 租户隔离异常
 *
 * Thrown when tenant isolation rules are violated.
 * 当违反租户隔离规则时抛出。
 *
 * @package Alkaid\Workflow\Engine\Multitenant\Exception
 */
class TenantIsolationException extends RuntimeException
{
    /**
     * Expected tenant ID
     *
     * @var int|null
     */
    protected ?int $expectedTenantId;

    /**
     * Actual tenant ID
     *
     * @var int|null
     */
    protected ?int $actualTenantId;

    /**
     * Resource type being accessed
     *
     * @var string|null
     */
    protected ?string $resourceType;

    /**
     * Resource ID being accessed
     *
     * @var string|null
     */
    protected ?string $resourceId;

    /**
     * Constructor
     *
     * @param string $message Error message
     * @param int|null $expectedTenantId Expected tenant ID
     * @param int|null $actualTenantId Actual tenant ID
     * @param string|null $resourceType Resource type
     * @param string|null $resourceId Resource ID
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = 'Tenant isolation violation',
        ?int $expectedTenantId = null,
        ?int $actualTenantId = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $this->expectedTenantId = $expectedTenantId;
        $this->actualTenantId = $actualTenantId;
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;

        parent::__construct($message, $code, $previous);
    }

    /**
     * Get expected tenant ID | 获取期望的租户ID
     *
     * @return int|null
     */
    public function getExpectedTenantId(): ?int
    {
        return $this->expectedTenantId;
    }

    /**
     * Get actual tenant ID | 获取实际的租户ID
     *
     * @return int|null
     */
    public function getActualTenantId(): ?int
    {
        return $this->actualTenantId;
    }

    /**
     * Get resource type | 获取资源类型
     *
     * @return string|null
     */
    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }

    /**
     * Get resource ID | 获取资源ID
     *
     * @return string|null
     */
    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    /**
     * Get detailed context | 获取详细上下文
     *
     * @return array
     */
    public function getContext(): array
    {
        return [
            'expected_tenant_id' => $this->expectedTenantId,
            'actual_tenant_id' => $this->actualTenantId,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ];
    }

    /**
     * Create exception for cross-tenant access attempt | 创建跨租户访问异常
     *
     * @param int $currentTenantId Current tenant ID
     * @param int $targetTenantId Target tenant ID being accessed
     * @param string $resourceType Resource type
     * @param string|null $resourceId Resource ID
     * @return static
     */
    public static function crossTenantAccess(
        int $currentTenantId,
        int $targetTenantId,
        string $resourceType,
        ?string $resourceId = null
    ): static {
        $message = sprintf(
            'Cross-tenant access denied: tenant %d cannot access %s (tenant %d)',
            $currentTenantId,
            $resourceType . ($resourceId ? ":{$resourceId}" : ''),
            $targetTenantId
        );

        return new static($message, $currentTenantId, $targetTenantId, $resourceType, $resourceId);
    }

    /**
     * Create exception for missing tenant context | 创建缺少租户上下文异常
     *
     * @param string $operation Operation being performed
     * @return static
     */
    public static function missingTenantContext(string $operation): static
    {
        return new static("Tenant context required for operation: {$operation}");
    }

    /**
     * Create exception for tenant mismatch | 创建租户不匹配异常
     *
     * @param int $expected Expected tenant ID
     * @param int|null $actual Actual tenant ID
     * @param string $resourceType Resource type
     * @return static
     */
    public static function tenantMismatch(int $expected, ?int $actual, string $resourceType): static
    {
        $message = sprintf(
            'Tenant mismatch for %s: expected %d, got %s',
            $resourceType,
            $expected,
            $actual === null ? 'null' : $actual
        );

        return new static($message, $expected, $actual, $resourceType);
    }
}
