<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Multitenant;

use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;
use Alkaid\Workflow\Engine\Multitenant\Interfaces\TenantAwareInterface;

/**
 * Tenant Isolation Guard | 租户隔离守卫
 *
 * Provides methods to validate and enforce tenant isolation rules.
 * Ensures that cross-tenant access is properly blocked.
 * 提供验证和强制执行租户隔离规则的方法。确保跨租户访问被正确阻止。
 *
 * @package Alkaid\Workflow\Engine\Multitenant
 */
class TenantIsolationGuard
{
    /**
     * Access log for audit purposes
     *
     * @var array
     */
    private static array $accessLog = [];

    /**
     * Maximum log entries to keep
     *
     * @var int
     */
    private static int $maxLogEntries = 1000;

    /**
     * Callback for violation events
     *
     * @var callable|null
     */
    private static $violationCallback = null;

    /**
     * Validate that entity belongs to current tenant | 验证实体属于当前租户
     *
     * @param TenantAwareInterface|array $entity Entity to validate
     * @param string $resourceType Resource type for error message
     * @param string|null $resourceId Resource ID for error message
     * @return void
     * @throws TenantIsolationException If validation fails
     */
    public static function validate(
        TenantAwareInterface|array $entity,
        string $resourceType = 'resource',
        ?string $resourceId = null
    ): void {
        if (!TenantContext::isIsolationEnforced()) {
            return;
        }

        $currentTenantId = TenantContext::getTenantId();
        if ($currentTenantId === null) {
            return; // No tenant context means no restriction
        }

        $entityTenantId = self::extractTenantId($entity);

        if ($entityTenantId !== null && $entityTenantId !== $currentTenantId) {
            self::logViolation($currentTenantId, $entityTenantId, $resourceType, $resourceId);

            throw TenantIsolationException::crossTenantAccess(
                $currentTenantId,
                $entityTenantId,
                $resourceType,
                $resourceId
            );
        }

        self::logAccess($currentTenantId, $resourceType, $resourceId, true);
    }

    /**
     * Check if entity belongs to current tenant (non-throwing) | 检查实体是否属于当前租户
     *
     * @param TenantAwareInterface|array $entity Entity to check
     * @return bool
     */
    public static function check(TenantAwareInterface|array $entity): bool
    {
        $currentTenantId = TenantContext::getTenantId();
        if ($currentTenantId === null) {
            return true;
        }

        $entityTenantId = self::extractTenantId($entity);

        return $entityTenantId === null || $entityTenantId === $currentTenantId;
    }

    /**
     * Ensure current tenant context exists | 确保当前租户上下文存在
     *
     * @param string $operation Operation description for error
     * @return int Tenant ID
     * @throws TenantIsolationException If no tenant context
     */
    public static function requireTenantContext(string $operation = 'operation'): int
    {
        $tenantId = TenantContext::getTenantId();

        if ($tenantId === null && TenantContext::isIsolationEnforced()) {
            throw TenantIsolationException::missingTenantContext($operation);
        }

        return $tenantId ?? 0;
    }

    /**
     * Validate multiple entities | 验证多个实体
     *
     * @param iterable $entities Entities to validate
     * @param string $resourceType Resource type
     * @return void
     * @throws TenantIsolationException If any validation fails
     */
    public static function validateAll(iterable $entities, string $resourceType = 'resource'): void
    {
        foreach ($entities as $index => $entity) {
            self::validate($entity, $resourceType, (string)$index);
        }
    }

    /**
     * Filter entities to only those belonging to current tenant | 过滤属于当前租户的实体
     *
     * @param iterable $entities Entities to filter
     * @return array Filtered entities
     */
    public static function filter(iterable $entities): array
    {
        $currentTenantId = TenantContext::getTenantId();
        if ($currentTenantId === null) {
            return is_array($entities) ? $entities : iterator_to_array($entities);
        }

        $filtered = [];
        foreach ($entities as $key => $entity) {
            $entityTenantId = self::extractTenantId($entity);
            if ($entityTenantId === null || $entityTenantId === $currentTenantId) {
                $filtered[$key] = $entity;
            }
        }

        return $filtered;
    }

    /**
     * Ensure entity belongs to specific tenant | 确保实体属于特定租户
     *
     * @param TenantAwareInterface|array $entity Entity to check
     * @param int $tenantId Tenant ID to match
     * @param string $resourceType Resource type
     * @return void
     * @throws TenantIsolationException If validation fails
     */
    public static function ensureBelongsTo(
        TenantAwareInterface|array $entity,
        int $tenantId,
        string $resourceType = 'resource'
    ): void {
        $entityTenantId = self::extractTenantId($entity);

        if ($entityTenantId !== null && $entityTenantId !== $tenantId) {
            throw TenantIsolationException::tenantMismatch($tenantId, $entityTenantId, $resourceType);
        }
    }

    /**
     * Run callback in bypass mode (skip isolation checks) | 在旁路模式下运行回调
     *
     * WARNING: Use with caution. Only for system-level operations.
     * 警告：谨慎使用。仅用于系统级操作。
     *
     * @param callable $callback Callback to run
     * @return mixed Callback result
     */
    public static function bypass(callable $callback): mixed
    {
        $wasEnforced = TenantContext::isIsolationEnforced();

        try {
            TenantContext::setIsolationEnforced(false);
            return $callback();
        } finally {
            TenantContext::setIsolationEnforced($wasEnforced);
        }
    }

    /**
     * Run callback with specific tenant | 使用特定租户运行回调
     *
     * @param int $tenantId Tenant ID
     * @param callable $callback Callback to run
     * @return mixed Callback result
     */
    public static function asTenant(int $tenantId, callable $callback): mixed
    {
        return TenantContext::runWithTenant($tenantId, $callback);
    }

    /**
     * Set violation callback | 设置违规回调
     *
     * @param callable $callback Callback(int $currentTenant, int $targetTenant, string $resource)
     * @return void
     */
    public static function onViolation(callable $callback): void
    {
        self::$violationCallback = $callback;
    }

    /**
     * Get access log | 获取访问日志
     *
     * @return array
     */
    public static function getAccessLog(): array
    {
        return self::$accessLog;
    }

    /**
     * Clear access log | 清除访问日志
     *
     * @return void
     */
    public static function clearAccessLog(): void
    {
        self::$accessLog = [];
    }

    /**
     * Set maximum log entries | 设置最大日志条目数
     *
     * @param int $max Maximum entries
     * @return void
     */
    public static function setMaxLogEntries(int $max): void
    {
        self::$maxLogEntries = $max;
    }

    /**
     * Get violation statistics | 获取违规统计
     *
     * @return array
     */
    public static function getViolationStats(): array
    {
        $violations = array_filter(self::$accessLog, fn($entry) => !$entry['allowed']);
        $allowed = array_filter(self::$accessLog, fn($entry) => $entry['allowed']);

        return [
            'total_accesses' => count(self::$accessLog),
            'allowed' => count($allowed),
            'violations' => count($violations),
            'violation_rate' => count(self::$accessLog) > 0
                ? count($violations) / count(self::$accessLog)
                : 0,
        ];
    }

    /**
     * Extract tenant ID from entity | 从实体中提取租户ID
     *
     * @param TenantAwareInterface|array $entity
     * @return int|null
     */
    private static function extractTenantId(TenantAwareInterface|array $entity): ?int
    {
        if ($entity instanceof TenantAwareInterface) {
            return $entity->getTenantId();
        }

        return $entity['tenant_id'] ?? null;
    }

    /**
     * Log access attempt | 记录访问尝试
     *
     * @param int $tenantId Tenant ID
     * @param string $resourceType Resource type
     * @param string|null $resourceId Resource ID
     * @param bool $allowed Whether access was allowed
     * @return void
     */
    private static function logAccess(
        int $tenantId,
        string $resourceType,
        ?string $resourceId,
        bool $allowed
    ): void {
        if (count(self::$accessLog) >= self::$maxLogEntries) {
            array_shift(self::$accessLog);
        }

        self::$accessLog[] = [
            'timestamp' => microtime(true),
            'tenant_id' => $tenantId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'allowed' => $allowed,
        ];
    }

    /**
     * Log violation and trigger callback | 记录违规并触发回调
     *
     * @param int $currentTenantId Current tenant
     * @param int $targetTenantId Target tenant
     * @param string $resourceType Resource type
     * @param string|null $resourceId Resource ID
     * @return void
     */
    private static function logViolation(
        int $currentTenantId,
        int $targetTenantId,
        string $resourceType,
        ?string $resourceId
    ): void {
        self::logAccess($currentTenantId, $resourceType, $resourceId, false);

        if (self::$violationCallback !== null) {
            call_user_func(
                self::$violationCallback,
                $currentTenantId,
                $targetTenantId,
                $resourceType . ($resourceId ? ":{$resourceId}" : '')
            );
        }
    }
}
