<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Multitenant;

use Swoole\Coroutine;
use RuntimeException;

/**
 * Tenant Context | 租户上下文
 *
 * Manages tenant context in a coroutine-safe manner. Stores tenant information
 * per coroutine to ensure proper isolation in concurrent environments.
 * 以协程安全的方式管理租户上下文。按协程存储租户信息以确保并发环境中的正确隔离。
 *
 * @package Alkaid\Workflow\Engine\Multitenant
 */
class TenantContext
{
    /**
     * Coroutine-local tenant storage
     * 协程本地租户存储
     *
     * @var array<int, array>
     */
    private static array $contexts = [];

    /**
     * Default tenant ID for non-coroutine contexts
     * 非协程上下文的默认租户ID
     *
     * @var int|null
     */
    private static ?int $defaultTenantId = null;

    /**
     * Global tenant configuration
     * 全局租户配置
     *
     * @var array<int, array>
     */
    private static array $tenantConfigs = [];

    /**
     * Whether tenant isolation is enforced
     * 是否强制租户隔离
     *
     * @var bool
     */
    private static bool $isolationEnforced = true;

    /**
     * Set current tenant ID | 设置当前租户ID
     *
     * @param int $tenantId Tenant ID
     * @return void
     */
    public static function setTenantId(int $tenantId): void
    {
        $cid = self::getCoroutineId();

        if ($cid > 0) {
            if (!isset(self::$contexts[$cid])) {
                self::$contexts[$cid] = [];
            }
            self::$contexts[$cid]['tenant_id'] = $tenantId;
        } else {
            self::$defaultTenantId = $tenantId;
        }
    }

    /**
     * Get current tenant ID | 获取当前租户ID
     *
     * @param bool $required Whether tenant ID is required
     * @return int|null
     * @throws RuntimeException If required but not set
     */
    public static function getTenantId(bool $required = false): ?int
    {
        $cid = self::getCoroutineId();

        $tenantId = null;
        if ($cid > 0 && isset(self::$contexts[$cid]['tenant_id'])) {
            $tenantId = self::$contexts[$cid]['tenant_id'];
        } else {
            $tenantId = self::$defaultTenantId;
        }

        if ($required && $tenantId === null) {
            throw new RuntimeException('Tenant ID is required but not set in context');
        }

        return $tenantId;
    }

    /**
     * Check if tenant ID is set | 检查租户ID是否已设置
     *
     * @return bool
     */
    public static function hasTenantId(): bool
    {
        return self::getTenantId() !== null;
    }

    /**
     * Set current user ID | 设置当前用户ID
     *
     * @param int $userId User ID
     * @return void
     */
    public static function setUserId(int $userId): void
    {
        $cid = self::getCoroutineId();

        if ($cid > 0) {
            if (!isset(self::$contexts[$cid])) {
                self::$contexts[$cid] = [];
            }
            self::$contexts[$cid]['user_id'] = $userId;
        } else {
            if (!isset(self::$contexts[0])) {
                self::$contexts[0] = [];
            }
            self::$contexts[0]['user_id'] = $userId;
        }
    }

    /**
     * Get current user ID | 获取当前用户ID
     *
     * @return int|null
     */
    public static function getUserId(): ?int
    {
        $cid = self::getCoroutineId();

        if ($cid > 0 && isset(self::$contexts[$cid]['user_id'])) {
            return self::$contexts[$cid]['user_id'];
        }

        return self::$contexts[0]['user_id'] ?? null;
    }

    /**
     * Set context value | 设置上下文值
     *
     * @param string $key Key
     * @param mixed $value Value
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        $cid = self::getCoroutineId();
        $contextId = $cid > 0 ? $cid : 0;

        if (!isset(self::$contexts[$contextId])) {
            self::$contexts[$contextId] = [];
        }
        self::$contexts[$contextId][$key] = $value;
    }

    /**
     * Get context value | 获取上下文值
     *
     * @param string $key Key
     * @param mixed $default Default value
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cid = self::getCoroutineId();

        if ($cid > 0 && isset(self::$contexts[$cid][$key])) {
            return self::$contexts[$cid][$key];
        }

        return self::$contexts[0][$key] ?? $default;
    }

    /**
     * Initialize context for current coroutine | 初始化当前协程的上下文
     *
     * @param int $tenantId Tenant ID
     * @param int|null $userId User ID (optional)
     * @param array $extra Additional context data
     * @return void
     */
    public static function initialize(int $tenantId, ?int $userId = null, array $extra = []): void
    {
        $cid = self::getCoroutineId();
        $contextId = $cid > 0 ? $cid : 0;

        self::$contexts[$contextId] = array_merge([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'initialized_at' => microtime(true),
        ], $extra);

        if ($cid <= 0) {
            self::$defaultTenantId = $tenantId;
        }
    }

    /**
     * Clear context for current coroutine | 清除当前协程的上下文
     *
     * @return void
     */
    public static function clear(): void
    {
        $cid = self::getCoroutineId();

        if ($cid > 0) {
            unset(self::$contexts[$cid]);
        } else {
            self::$defaultTenantId = null;
            unset(self::$contexts[0]);
        }
    }

    /**
     * Clear all contexts (for testing/cleanup) | 清除所有上下文
     *
     * @return void
     */
    public static function clearAll(): void
    {
        self::$contexts = [];
        self::$defaultTenantId = null;
    }

    /**
     * Run callback within tenant context | 在租户上下文中运行回调
     *
     * @param int $tenantId Tenant ID
     * @param callable $callback Callback to run
     * @param int|null $userId Optional user ID
     * @return mixed Callback result
     */
    public static function runWithTenant(int $tenantId, callable $callback, ?int $userId = null): mixed
    {
        $previousTenantId = self::getTenantId();
        $previousUserId = self::getUserId();

        try {
            self::setTenantId($tenantId);
            if ($userId !== null) {
                self::setUserId($userId);
            }

            return $callback();
        } finally {
            if ($previousTenantId !== null) {
                self::setTenantId($previousTenantId);
            } else {
                // Clear tenant ID if it wasn't set before
                $cid = self::getCoroutineId();
                if ($cid > 0 && isset(self::$contexts[$cid])) {
                    unset(self::$contexts[$cid]['tenant_id']);
                } else {
                    self::$defaultTenantId = null;
                }
            }

            if ($previousUserId !== null) {
                self::setUserId($previousUserId);
            }
        }
    }

    /**
     * Get all context data for current coroutine | 获取当前协程的所有上下文数据
     *
     * @return array
     */
    public static function all(): array
    {
        $cid = self::getCoroutineId();

        if ($cid > 0) {
            return self::$contexts[$cid] ?? [];
        }

        return self::$contexts[0] ?? [];
    }

    /**
     * Set tenant configuration | 设置租户配置
     *
     * @param int $tenantId Tenant ID
     * @param array $config Configuration
     * @return void
     */
    public static function setTenantConfig(int $tenantId, array $config): void
    {
        self::$tenantConfigs[$tenantId] = $config;
    }

    /**
     * Get tenant configuration | 获取租户配置
     *
     * @param int|null $tenantId Tenant ID (null for current)
     * @return array
     */
    public static function getTenantConfig(?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? self::getTenantId();

        if ($tenantId === null) {
            return [];
        }

        return self::$tenantConfigs[$tenantId] ?? [];
    }

    /**
     * Get tenant config value | 获取租户配置值
     *
     * @param string $key Config key
     * @param mixed $default Default value
     * @param int|null $tenantId Tenant ID (null for current)
     * @return mixed
     */
    public static function getTenantConfigValue(string $key, mixed $default = null, ?int $tenantId = null): mixed
    {
        $config = self::getTenantConfig($tenantId);

        return $config[$key] ?? $default;
    }

    /**
     * Set whether tenant isolation is enforced | 设置是否强制租户隔离
     *
     * @param bool $enforced
     * @return void
     */
    public static function setIsolationEnforced(bool $enforced): void
    {
        self::$isolationEnforced = $enforced;
    }

    /**
     * Check if tenant isolation is enforced | 检查是否强制租户隔离
     *
     * @return bool
     */
    public static function isIsolationEnforced(): bool
    {
        return self::$isolationEnforced;
    }

    /**
     * Clone context to a child coroutine | 复制上下文到子协程
     *
     * @param int $childCid Child coroutine ID
     * @return void
     */
    public static function cloneToChild(int $childCid): void
    {
        $parentCid = self::getCoroutineId();
        $parentContext = self::$contexts[$parentCid] ?? self::$contexts[0] ?? [];

        if (!empty($parentContext)) {
            self::$contexts[$childCid] = $parentContext;
        }
    }

    /**
     * Get debug info | 获取调试信息
     *
     * @return array
     */
    public static function getDebugInfo(): array
    {
        $cid = self::getCoroutineId();

        return [
            'coroutine_id' => $cid,
            'tenant_id' => self::getTenantId(),
            'user_id' => self::getUserId(),
            'context_count' => count(self::$contexts),
            'isolation_enforced' => self::$isolationEnforced,
            'current_context' => self::all(),
        ];
    }

    /**
     * Get current coroutine ID | 获取当前协程ID
     *
     * @return int Returns -1 if not in coroutine context
     */
    private static function getCoroutineId(): int
    {
        if (!class_exists(Coroutine::class)) {
            return -1;
        }

        return Coroutine::getCid();
    }
}
