<?php

declare(strict_types=1);

/**
 * Setup Test Data Script | 测试数据准备脚本
 * 
 * Creates necessary test data for running tests with authentication and permission control.
 * 创建运行带认证和权限控制测试所需的测试数据。
 * 
 * Usage: php tests/setup_test_data.php
 */

require __DIR__ . '/../vendor/autoload.php';

use think\facade\Db;

// Initialize ThinkPHP application
$app = new \think\App();
$app->initialize();

echo "=== Setting up test data for authentication and permission tests ===\n\n";

try {
    // 1. Check if test user exists
    echo "1. Checking test user (id=1)...\n";
    $user = Db::table('users')->where('id', 1)->find();
    
    if (!$user) {
        echo "   Creating test user...\n";
        Db::table('users')->insert([
            'id' => 1,
            'username' => 'test_user',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'tenant_id' => 1,
            'site_id' => 0,
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        echo "   ✅ Test user created (id=1, username=test_user)\n";
    } else {
        echo "   ✅ Test user already exists (id={$user['id']}, username={$user['username']})\n";
    }
    
    // 2. Check and create lowcode permissions
    echo "\n2. Checking lowcode permissions...\n";
    $permissions = [
        [
            'slug' => 'lowcode:read',
            'name' => 'Lowcode Read',
            'resource' => 'lowcode',
            'action' => 'read',
            'description' => 'Read lowcode resources'
        ],
        [
            'slug' => 'lowcode:write',
            'name' => 'Lowcode Write',
            'resource' => 'lowcode',
            'action' => 'write',
            'description' => 'Create and update lowcode resources'
        ],
        [
            'slug' => 'lowcode:delete',
            'name' => 'Lowcode Delete',
            'resource' => 'lowcode',
            'action' => 'delete',
            'description' => 'Delete lowcode resources'
        ],
    ];

    $createdPermissions = [];
    foreach ($permissions as $permission) {
        $existing = Db::table('permissions')->where('slug', $permission['slug'])->find();

        if (!$existing) {
            echo "   Creating permission: {$permission['slug']}...\n";
            $id = Db::table('permissions')->insertGetId([
                'slug' => $permission['slug'],
                'name' => $permission['name'],
                'resource' => $permission['resource'],
                'action' => $permission['action'],
                'description' => $permission['description'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $createdPermissions[$permission['slug']] = $id;
            echo "   ✅ Permission created (id={$id})\n";
        } else {
            $createdPermissions[$permission['slug']] = $existing['id'];
            echo "   ✅ Permission already exists (id={$existing['id']}, slug={$permission['slug']})\n";
        }
    }
    
    // 3. Check and create test role
    echo "\n3. Checking test role...\n";
    $role = Db::table('roles')->where('slug', 'test_admin')->find();
    
    if (!$role) {
        echo "   Creating test role...\n";
        $roleId = Db::table('roles')->insertGetId([
            'slug' => 'test_admin',
            'name' => 'Test Admin',
            'description' => 'Test administrator role with all lowcode permissions',
            'tenant_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        echo "   ✅ Test role created (id={$roleId})\n";
    } else {
        $roleId = $role['id'];
        echo "   ✅ Test role already exists (id={$roleId}, slug=test_admin)\n";
    }
    
    // 4. Assign permissions to role
    echo "\n4. Assigning permissions to test role...\n";
    foreach ($createdPermissions as $slug => $permissionId) {
        $existing = Db::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->find();
        
        if (!$existing) {
            Db::table('role_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            echo "   ✅ Assigned permission: {$slug}\n";
        } else {
            echo "   ✅ Permission already assigned: {$slug}\n";
        }
    }
    
    // 5. Assign role to test user
    echo "\n5. Assigning test role to test user...\n";
    $existing = Db::table('user_roles')
        ->where('user_id', 1)
        ->where('role_id', $roleId)
        ->find();
    
    if (!$existing) {
        Db::table('user_roles')->insert([
            'user_id' => 1,
            'role_id' => $roleId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        echo "   ✅ Role assigned to user\n";
    } else {
        echo "   ✅ Role already assigned to user\n";
    }
    
    echo "\n=== Test data setup completed successfully! ===\n";
    echo "\nTest user credentials:\n";
    echo "  User ID: 1\n";
    echo "  Username: test_user\n";
    echo "  Tenant ID: 1\n";
    echo "  Permissions: lowcode:read, lowcode:write, lowcode:delete\n";
    
} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

