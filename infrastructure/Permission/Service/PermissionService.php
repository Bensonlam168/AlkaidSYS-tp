<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Service;

use Infrastructure\User\Repository\UserRepository;
use think\facade\Db;

/**
 * Permission Service | 权限服务
 *
 * Provides permission-related business logic for RBAC.
 * 为 RBAC 提供权限相关的业务逻辑。
 *
 * @package Infrastructure\Permission\Service
 */
class PermissionService
{
    /**
     * Get user permissions | 获取用户权限
     *
     * Retrieves all permissions for a given user based on their roles.
     * Returns permissions in `resource:action` format for external use.
     *
     * 根据用户的角色检索用户的所有权限。
     * 返回 `resource:action` 格式的权限供外部使用。
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
