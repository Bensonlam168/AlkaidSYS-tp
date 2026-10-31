<?php

use think\migration\Seeder;

/**
 * Core Platform Seed | 核心平台种子数据
 *
 * Seeds the database with initial data for tenants, roles, permissions, and users.
 * 为数据库填充租户、角色、权限和用户的初始数据。
 */
class CorePlatformSeed extends Seeder
{
    /**
     * Run seed | 执行填充
     */
    public function run(): void
    {
        // 1. Create default tenant | 创建默认租户
        $this->createDefaultTenant();

        // 2. Create roles | 创建角色
        $this->createRoles();

        // 3. Create permissions | 创建权限
        $this->createPermissions();

        // 4. Assign permissions to roles | 分配权限给角色
        $this->assignPermissionsToRoles();

        // 5. Create default admin user | 创建默认管理员用户
        $this->createDefaultUser();
    }

    /**
     * Create default tenant | 创建默认租户
     */
    protected function createDefaultTenant(): void
    {
        $this->table('tenants')->insert([
            [
                'id' => 1,
                'name' => 'Default Tenant',
                'slug' => 'default',
                'domain' => null,
                'status' => 'active',
                'config' => json_encode([
                    'locale' => 'zh_CN',
                    'timezone' => 'Asia/Shanghai',
                ]),
                'max_sites' => 10,
                'max_users' => 100,
                'expires_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ])->saveData();

        // Create default site | 创建默认站点
        $this->table('sites')->insert([
            [
                'id' => 1,
                'tenant_id' => 1,
                'name' => 'Default Site',
                'slug' => 'default',
                'domain' => null,
                'status' => 'active',
                'config' => json_encode([
                    'locale' => 'zh_CN',
                    'timezone' => 'Asia/Shanghai',
                ]),
                'theme' => 'default',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ])->saveData();
    }

    /**
     * Create roles | 创建角色
     */
    protected function createRoles(): void
    {
        $this->table('roles')->insert([
            [
                'id' => 1,
                'tenant_id' => 1,
                'name' => 'Administrator',
                'slug' => 'admin',
                'description' => 'System administrator with full access',
                'is_system' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'id' => 2,
                'tenant_id' => 1,
                'name' => 'User',
                'slug' => 'user',
                'description' => 'Regular user with basic access',
                'is_system' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ])->saveData();
    }

    /**
     * Create permissions | 创建权限
     */
    protected function createPermissions(): void
    {
        $permissions = [
            // Form Designer permissions | 表单设计器权限
            ['name' => 'View Forms', 'slug' => 'forms.view', 'resource' => 'forms', 'action' => 'view'],
            ['name' => 'Create Forms', 'slug' => 'forms.create', 'resource' => 'forms', 'action' => 'create'],
            ['name' => 'Update Forms', 'slug' => 'forms.update', 'resource' => 'forms', 'action' => 'update'],
            ['name' => 'Delete Forms', 'slug' => 'forms.delete', 'resource' => 'forms', 'action' => 'delete'],

            // Form Data permissions | 表单数据权限
            ['name' => 'View Form Data', 'slug' => 'form_data.view', 'resource' => 'form_data', 'action' => 'view'],
            ['name' => 'Create Form Data', 'slug' => 'form_data.create', 'resource' => 'form_data', 'action' => 'create'],
            ['name' => 'Update Form Data', 'slug' => 'form_data.update', 'resource' => 'form_data', 'action' => 'update'],
            ['name' => 'Delete Form Data', 'slug' => 'form_data.delete', 'resource' => 'form_data', 'action' => 'delete'],

            // Collection permissions | 集合权限
            ['name' => 'View Collections', 'slug' => 'collections.view', 'resource' => 'collections', 'action' => 'view'],
            ['name' => 'Create Collections', 'slug' => 'collections.create', 'resource' => 'collections', 'action' => 'create'],
            ['name' => 'Update Collections', 'slug' => 'collections.update', 'resource' => 'collections', 'action' => 'update'],
            ['name' => 'Delete Collections', 'slug' => 'collections.delete', 'resource' => 'collections', 'action' => 'delete'],

            // User management permissions | 用户管理权限
            ['name' => 'View Users', 'slug' => 'users.view', 'resource' => 'users', 'action' => 'view'],
            ['name' => 'Create Users', 'slug' => 'users.create', 'resource' => 'users', 'action' => 'create'],
            ['name' => 'Update Users', 'slug' => 'users.update', 'resource' => 'users', 'action' => 'update'],
            ['name' => 'Delete Users', 'slug' => 'users.delete', 'resource' => 'users', 'action' => 'delete'],

            // Role management permissions | 角色管理权限
            ['name' => 'View Roles', 'slug' => 'roles.view', 'resource' => 'roles', 'action' => 'view'],
            ['name' => 'Create Roles', 'slug' => 'roles.create', 'resource' => 'roles', 'action' => 'create'],
            ['name' => 'Update Roles', 'slug' => 'roles.update', 'resource' => 'roles', 'action' => 'update'],
            ['name' => 'Delete Roles', 'slug' => 'roles.delete', 'resource' => 'roles', 'action' => 'delete'],
        ];

        $data = [];
        foreach ($permissions as $index => $permission) {
            $data[] = array_merge($permission, [
                'id' => $index + 1,
                'description' => $permission['name'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->table('permissions')->insert($data)->saveData();
    }

    /**
     * Assign permissions to roles | 分配权限给角色
     */
    protected function assignPermissionsToRoles(): void
    {
        // Admin gets all permissions | 管理员获得所有权限
        //
        // 【为什么不能硬编码权限 ID（R43 修复）】
        // 这里原先是 `for ($i = 1; $i <= 20; $i++)`，把管理员权限写死成 ID 1..20。隐患有两个：
        //   1. 权限并非只由本 seeder 创建，多个迁移也会插入权限：
        //      20251126000001（casbin.manage）、20251218000001（tenant/role 管理权限）、
        //      20251219000002（dept.* / menu.* 共 6 条）。它们的自增 ID 都不在 1..20 内，
        //      于是**管理员永远拿不到这些后加的权限** —— 表现为「迁移已建出 dept.read，
        //      但管理员进不了部门管理页」，且全程无报错，极难定位。
        //   2. 硬编码 ID 与表的自增分配顺序绑定，任何一次插入顺序变化都会静默错配。
        //
        // 因此改为 INSERT ... SELECT 动态读取 permissions 表的**全部** ID 分配给管理员。
        // NOT EXISTS 保证幂等：重复执行 seeder 不会产生重复行，也不会撞复合主键 (role_id, permission_id)。
        $this->execute("
            INSERT INTO role_permissions (role_id, permission_id, created_at)
            SELECT 1, p.id, NOW()
            FROM permissions p
            WHERE NOT EXISTS (
                SELECT 1 FROM role_permissions rp
                WHERE rp.role_id = 1 AND rp.permission_id = p.id
            )
        ");

        // Regular user gets view permissions only | 普通用户只有查看权限
        // 同样不再硬编码 ID：原写法写死 1 / 5 / 9，与 createPermissions() 的插入顺序强耦合；
        // 而 slug 才是 permissions 表的唯一键（idx_slug），按 slug 取 ID 是稳定契约。
        $this->execute("
            INSERT INTO role_permissions (role_id, permission_id, created_at)
            SELECT 2, p.id, NOW()
            FROM permissions p
            WHERE p.slug IN ('forms.view', 'form_data.view', 'collections.view')
            AND NOT EXISTS (
                SELECT 1 FROM role_permissions rp
                WHERE rp.role_id = 2 AND rp.permission_id = p.id
            )
        ");
    }

    /**
     * Create default admin user | 创建默认管理员用户
     */
    protected function createDefaultUser(): void
    {
        // Create admin user | 创建管理员用户
        $this->table('users')->insert([
            [
                'id' => 1,
                'tenant_id' => 1,
                'username' => 'admin',
                'email' => 'admin@alkaidsys.local',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'name' => 'System Administrator',
                'avatar' => null,
                'phone' => null,
                'status' => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
                'last_login_at' => null,
                'last_login_ip' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ])->saveData();

        // Assign admin role to admin user | 分配管理员角色
        $this->table('user_roles')->insert([
            [
                'user_id' => 1,
                'role_id' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ])->saveData();
    }
}
