<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\facade\Db;

/**
 * 添加系统管理权限（租户、角色、权限、用户）
 * Add System Management Permissions (Tenant, Role, Permission, User)
 *
 * 为租户管理、角色管理、权限管理和用户管理 API 添加所需的权限。
 * Add required permissions for tenant, role, permission and user management APIs.
 */
class AddTenantAndRoleManagementPermissions extends Migrator
{
    /**
     * 权限定义
     * Permission definitions
     */
    private array $permissions = [
        // 租户管理权限 | Tenant Management Permissions
        [
            'name' => 'View Tenants',
            'slug' => 'tenant.read',
            'resource' => 'tenant',
            'action' => 'read',
            'description' => 'View tenant list and details',
        ],
        [
            'name' => 'Manage Tenants',
            'slug' => 'tenant.write',
            'resource' => 'tenant',
            'action' => 'write',
            'description' => 'Create and update tenants',
        ],
        [
            'name' => 'Delete Tenants',
            'slug' => 'tenant.delete',
            'resource' => 'tenant',
            'action' => 'delete',
            'description' => 'Delete tenants',
        ],
        // 角色管理权限 | Role Management Permissions
        [
            'name' => 'View Roles',
            'slug' => 'role.read',
            'resource' => 'role',
            'action' => 'read',
            'description' => 'View role list and details',
        ],
        [
            'name' => 'Manage Roles',
            'slug' => 'role.write',
            'resource' => 'role',
            'action' => 'write',
            'description' => 'Create and update roles',
        ],
        [
            'name' => 'Delete Roles',
            'slug' => 'role.delete',
            'resource' => 'role',
            'action' => 'delete',
            'description' => 'Delete roles',
        ],
        // 权限管理权限 | Permission Management Permissions
        [
            'name' => 'View Permissions',
            'slug' => 'permission.read',
            'resource' => 'permission',
            'action' => 'read',
            'description' => 'View permission list and details',
        ],
        [
            'name' => 'Manage Permissions',
            'slug' => 'permission.write',
            'resource' => 'permission',
            'action' => 'write',
            'description' => 'Create and update permissions',
        ],
        [
            'name' => 'Delete Permissions',
            'slug' => 'permission.delete',
            'resource' => 'permission',
            'action' => 'delete',
            'description' => 'Delete permissions',
        ],
        // 用户管理权限 | User Management Permissions
        [
            'name' => 'View Users',
            'slug' => 'user.read',
            'resource' => 'user',
            'action' => 'read',
            'description' => 'View user list and details',
        ],
        [
            'name' => 'Manage Users',
            'slug' => 'user.write',
            'resource' => 'user',
            'action' => 'write',
            'description' => 'Create and update users',
        ],
        [
            'name' => 'Delete Users',
            'slug' => 'user.delete',
            'resource' => 'user',
            'action' => 'delete',
            'description' => 'Delete users',
        ],
    ];

    /**
     * 执行迁移
     * Run migration
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $insertedPermissionIds = [];

        foreach ($this->permissions as $permission) {
            // 检查权限是否已存在
            // Check if permission already exists
            $exists = Db::table('permissions')
                ->where('slug', $permission['slug'])
                ->find();

            if ($exists) {
                $this->output->writeln("<info>Permission {$permission['slug']} already exists, skipping...</info>");
                $insertedPermissionIds[] = $exists['id'];
                continue;
            }

            // 插入权限
            // Insert permission
            $permissionId = Db::table('permissions')->insertGetId([
                'name' => $permission['name'],
                'slug' => $permission['slug'],
                'resource' => $permission['resource'],
                'action' => $permission['action'],
                'description' => $permission['description'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $insertedPermissionIds[] = $permissionId;
            $this->output->writeln("<info>Permission {$permission['slug']} created successfully</info>");
        }

        // 将权限分配给 Administrator 角色 (通常 ID 为 1)
        // Assign permissions to Administrator role (usually ID 1)
        $adminRole = Db::table('roles')->where('slug', 'admin')->find();
        if ($adminRole) {
            foreach ($insertedPermissionIds as $permissionId) {
                // 检查是否已分配
                // Check if already assigned
                $exists = Db::table('role_permissions')
                    ->where('role_id', $adminRole['id'])
                    ->where('permission_id', $permissionId)
                    ->find();

                if (!$exists) {
                    Db::table('role_permissions')->insert([
                        'role_id' => $adminRole['id'],
                        'permission_id' => $permissionId,
                        'created_at' => $now,
                    ]);
                }
            }
            $this->output->writeln('<info>Permissions assigned to Administrator role</info>');
        } else {
            $this->output->writeln('<comment>Administrator role not found, permissions not assigned to any role</comment>');
        }
    }

    /**
     * 回滚迁移
     * Rollback migration
     */
    public function down(): void
    {
        $slugs = array_column($this->permissions, 'slug');

        // 获取权限 ID
        // Get permission IDs
        $permissionIds = Db::table('permissions')
            ->whereIn('slug', $slugs)
            ->column('id');

        if (!empty($permissionIds)) {
            // 删除角色权限关联
            // Delete role-permission associations
            Db::table('role_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();

            // 删除权限
            // Delete permissions
            Db::table('permissions')
                ->whereIn('slug', $slugs)
                ->delete();
        }

        $this->output->writeln('<info>Tenant, role and permission management permissions deleted</info>');
    }
}
