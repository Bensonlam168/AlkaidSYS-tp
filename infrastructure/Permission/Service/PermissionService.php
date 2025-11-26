<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Service;

use Infrastructure\User\Repository\UserRepository;
use think\facade\Db;
use think\facade\Config;

/**
 * Permission Service | 权限服务
 *
 * Provides permission-related business logic for RBAC with Casbin integration.
 * 为 RBAC 提供权限相关的业务逻辑，集成 Casbin 授权引擎。
 *
 * 支持三种运行模式 | Supports three running modes:
 * - DB_ONLY: 仅使用数据库查询（默认，向后兼容）| Only use database queries (default, backward compatible)
 * - CASBIN_ONLY: 仅使用 Casbin 检查 | Only use Casbin checking
 * - DUAL_MODE: 同时使用两种方式，结果取并集 | Use both methods, merge results
 *
 * @package Infrastructure\Permission\Service
 */
class PermissionService
{
    /**
     * Casbin 服务实例
     * Casbin service instance
     */
    protected ?CasbinService $casbinService = null;

    /**
     * Casbin 是否启用
     * Whether Casbin is enabled
     */
    protected bool $casbinEnabled = false;

    /**
     * Casbin 运行模式
     * Casbin running mode
     */
    protected string $casbinMode = 'DB_ONLY';

    /**
     * 构造函数
     * Constructor
     *
     * @param CasbinService|null $casbinService Casbin 服务实例（可选）| Casbin service instance (optional)
     */
    public function __construct(?CasbinService $casbinService = null)
    {
        $this->casbinService = $casbinService;
        $this->casbinEnabled = Config::get('casbin.enabled', false);
        $this->casbinMode = Config::get('casbin.mode', 'DB_ONLY');

        // 如果 Casbin 启用但未注入服务，则创建实例
        // If Casbin is enabled but service not injected, create instance
        if ($this->casbinEnabled && $this->casbinService === null) {
            $this->casbinService = new CasbinService();
        }
    }
    /**
     * Get user permissions | 获取用户权限
     *
     * Retrieves all permissions for a given user based on their roles.
     * Returns permissions in `resource:action` format for external use.
     *
     * 根据用户的角色检索用户的所有权限。
     * 返回 `resource:action` 格式的权限供外部使用。
     *
     * 支持三种运行模式 | Supports three running modes:
     * - DB_ONLY: 仅使用数据库查询 | Only use database queries
     * - CASBIN_ONLY: 仅使用 Casbin 检查 | Only use Casbin checking
     * - DUAL_MODE: 同时使用两种方式，结果取并集 | Use both methods, merge results
     *
     * Internal format: `resource.action` (slug)
     * External format: `resource:action` (code)
     *
     * 内部格式：`resource.action`（slug）
     * 外部格式：`resource:action`（code）
     *
     * @param int $userId User ID | 用户ID
     * @return array Array of permission codes in `resource:action` format | `resource:action` 格式的权限码数组
     */
    public function getUserPermissions(int $userId): array
    {
        // 如果 Casbin 未启用，使用数据库查询
        // If Casbin is not enabled, use database query
        if (!$this->casbinEnabled) {
            return $this->getUserPermissionsFromDatabase($userId);
        }

        // Choose implementation based on mode using match expression
        // 使用 match 表达式根据模式选择实现
        return match ($this->casbinMode) {
            'CASBIN_ONLY' => $this->getUserPermissionsFromCasbin($userId),
            'DUAL_MODE' => $this->mergePermissions(
                $this->getUserPermissionsFromDatabase($userId),
                $this->getUserPermissionsFromCasbin($userId)
            ),
            default => $this->getUserPermissionsFromDatabase($userId), // DB_ONLY and others
        };
    }

    /**
     * 从数据库获取用户权限
     * Get user permissions from database
     *
     * @param int $userId User ID | 用户ID
     * @return array Array of permission codes | 权限码数组
     */
    protected function getUserPermissionsFromDatabase(int $userId): array
    {
        // Step 1: Get user's role IDs with tenant isolation
        // 使用用户仓储在多租户上下文中安全地获取角色ID，确保只返回与用户租户匹配的角色
        $userRepository = new UserRepository();
        $roleIds = $userRepository->getRoleIds($userId);

        // If user has no roles, return empty array | 如果用户没有角色，返回空数组
        if (empty($roleIds)) {
            return [];
        }

        // Step 2: Get permission IDs from roles | 从角色获取权限ID
        $permissionIds = Db::table('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->column('permission_id');

        // If no permissions assigned to roles, return empty array | 如果角色没有分配权限，返回空数组
        if (empty($permissionIds)) {
            return [];
        }

        // Remove duplicates | 去重
        $permissionIds = array_unique($permissionIds);

        // Step 3: Get permission details (resource and action) | 获取权限详情（资源和操作）
        $permissions = Db::table('permissions')
            ->whereIn('id', $permissionIds)
            ->field(['resource', 'action'])
            ->select()
            ->toArray();

        // Step 4: Convert to external format (resource:action) | 转换为外部格式（resource:action）
        $permissionCodes = [];
        foreach ($permissions as $permission) {
            if (!empty($permission['resource']) && !empty($permission['action'])) {
                // External format: resource:action | 外部格式：resource:action
                $permissionCodes[] = $permission['resource'] . ':' . $permission['action'];
            }
        }

        // Remove duplicates and return | 去重并返回
        return array_values(array_unique($permissionCodes));
    }

    /**
     * 从 Casbin 获取用户权限
     * Get user permissions from Casbin
     *
     * @param int $userId User ID | 用户ID
     * @return array Array of permission codes | 权限码数组
     */
    protected function getUserPermissionsFromCasbin(int $userId): array
    {
        // 如果 CasbinService 未初始化，返回空数组
        // If CasbinService is not initialized, return empty array
        if ($this->casbinService === null) {
            return [];
        }

        // 获取用户的租户ID
        // Get user's tenant ID
        $tenantId = $this->getTenantId($userId);
        if ($tenantId === null) {
            return [];
        }

        // 调用 CasbinService 获取权限
        // Call CasbinService to get permissions
        try {
            return $this->casbinService->getUserPermissions($userId, $tenantId);
        } catch (\Exception $e) {
            // 如果 Casbin 调用失败，记录错误并返回空数组
            // If Casbin call fails, log error and return empty array
            // TODO: 添加日志记录
            return [];
        }
    }

    /**
     * 合并权限数组
     * Merge permission arrays
     *
     * @param array $dbPermissions 数据库权限 | Database permissions
     * @param array $casbinPermissions Casbin 权限 | Casbin permissions
     * @return array 合并后的权限数组 | Merged permission array
     */
    protected function mergePermissions(array $dbPermissions, array $casbinPermissions): array
    {
        // 合并两个数组
        // Merge two arrays
        $merged = array_merge($dbPermissions, $casbinPermissions);

        // 去重并返回
        // Remove duplicates and return
        return array_values(array_unique($merged));
    }

    /**
     * 获取用户的租户ID
     * Get user's tenant ID
     *
     * @param int $userId User ID | 用户ID
     * @return int|null Tenant ID or null if not found | 租户ID，如果未找到则返回 null
     */
    protected function getTenantId(int $userId): ?int
    {
        // 从用户表获取租户ID
        // Get tenant ID from users table
        $tenantId = Db::table('users')
            ->where('id', $userId)
            ->value('tenant_id');

        return $tenantId ? (int)$tenantId : null;
    }

    /**
     * Check if user has permission | 检查用户是否有权限
     *
     * @param int $userId User ID | 用户ID
     * @param string $permissionCode Permission code in `resource:action` format | `resource:action` 格式的权限码
     * @return bool True if user has permission | 如果用户有权限则返回 true
     */
    public function hasPermission(int $userId, string $permissionCode): bool
    {
        $userPermissions = $this->getUserPermissions($userId);
        return in_array($permissionCode, $userPermissions, true);
    }

    /**
     * Check if user has any of the given permissions | 检查用户是否有任一给定权限
     *
     * @param int $userId User ID | 用户ID
     * @param array $permissionCodes Array of permission codes | 权限码数组
     * @return bool True if user has any of the permissions | 如果用户有任一权限则返回 true
     */
    public function hasAnyPermission(int $userId, array $permissionCodes): bool
    {
        $userPermissions = $this->getUserPermissions($userId);
        foreach ($permissionCodes as $code) {
            if (in_array($code, $userPermissions, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has all of the given permissions | 检查用户是否有所有给定权限
     *
     * @param int $userId User ID | 用户ID
     * @param array $permissionCodes Array of permission codes | 权限码数组
     * @return bool True if user has all of the permissions | 如果用户有所有权限则返回 true
     */
    public function hasAllPermissions(int $userId, array $permissionCodes): bool
    {
        $userPermissions = $this->getUserPermissions($userId);
        foreach ($permissionCodes as $code) {
            if (!in_array($code, $userPermissions, true)) {
                return false;
            }
        }
        return true;
    }
}
