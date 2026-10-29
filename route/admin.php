<?php

// +----------------------------------------------------------------------
// | 管理员路由 | Admin Routes
// +----------------------------------------------------------------------

use think\facade\Route;

// Tenant Management routes | 租户管理路由
Route::group('v1/admin/tenants', function () {
    // 获取租户列表 | Get tenant list
    // GET /v1/admin/tenants
    Route::get('', 'app\controller\admin\TenantController@index')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'tenant.read');

    // 获取单个租户 | Get single tenant
    // GET /v1/admin/tenants/:id
    Route::get(':id', 'app\controller\admin\TenantController@read')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'tenant.read')
        ->pattern(['id' => '\d+']);

    // 创建租户 | Create tenant
    // POST /v1/admin/tenants
    Route::post('', 'app\controller\admin\TenantController@save')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'tenant.write');

    // 更新租户 | Update tenant
    // PUT /v1/admin/tenants/:id
    Route::put(':id', 'app\controller\admin\TenantController@update')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'tenant.write')
        ->pattern(['id' => '\d+']);

    // 删除租户 | Delete tenant
    // DELETE /v1/admin/tenants/:id
    Route::delete(':id', 'app\controller\admin\TenantController@delete')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'tenant.delete')
        ->pattern(['id' => '\d+']);

    // 切换租户状态 | Toggle tenant status
    // PATCH /v1/admin/tenants/:id/status
    Route::patch(':id/status', 'app\controller\admin\TenantController@toggleStatus')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'tenant.write')
        ->pattern(['id' => '\d+']);
});

// Role Management routes | 角色管理路由
Route::group('v1/admin/roles', function () {
    // 获取角色列表 | Get role list
    // GET /v1/admin/roles
    Route::get('', 'app\controller\admin\RoleController@index')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'role.read');

    // 获取单个角色 | Get single role
    // GET /v1/admin/roles/:id
    Route::get(':id', 'app\controller\admin\RoleController@read')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'role.read')
        ->pattern(['id' => '\d+']);

    // 创建角色 | Create role
    // POST /v1/admin/roles
    Route::post('', 'app\controller\admin\RoleController@save')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'role.write');

    // 更新角色 | Update role
    // PUT /v1/admin/roles/:id
    Route::put(':id', 'app\controller\admin\RoleController@update')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'role.write')
        ->pattern(['id' => '\d+']);

    // 删除角色 | Delete role
    // DELETE /v1/admin/roles/:id
    Route::delete(':id', 'app\controller\admin\RoleController@delete')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'role.delete')
        ->pattern(['id' => '\d+']);

    // 设置角色权限 | Set role permissions
    // PUT /v1/admin/roles/:id/permissions
    Route::put(':id/permissions', 'app\controller\admin\RoleController@setPermissions')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'role.write')
        ->pattern(['id' => '\d+']);
});

// Permission Management routes | 权限管理路由
Route::group('v1/admin/permissions', function () {
    // 获取权限列表 | Get permission list
    // GET /v1/admin/permissions
    Route::get('', 'app\controller\admin\PermissionController@index')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'permission.read');

    // 获取按资源分组的权限 | Get permissions grouped by resource
    // GET /v1/admin/permissions/grouped
    Route::get('grouped', 'app\controller\admin\PermissionController@grouped')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'permission.read');

    // 获取所有资源类型 | Get all resource types
    // GET /v1/admin/permissions/resources
    Route::get('resources', 'app\controller\admin\PermissionController@resources')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'permission.read');

    // 获取所有操作类型 | Get all action types
    // GET /v1/admin/permissions/actions
    Route::get('actions', 'app\controller\admin\PermissionController@actions')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'permission.read');

    // 获取单个权限 | Get single permission
    // GET /v1/admin/permissions/:id
    Route::get(':id', 'app\controller\admin\PermissionController@read')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'permission.read')
        ->pattern(['id' => '\d+']);

    // 创建权限 | Create permission
    // POST /v1/admin/permissions
    Route::post('', 'app\controller\admin\PermissionController@save')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'permission.write');

    // 更新权限 | Update permission
    // PUT /v1/admin/permissions/:id
    Route::put(':id', 'app\controller\admin\PermissionController@update')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'permission.write')
        ->pattern(['id' => '\d+']);

    // 删除权限 | Delete permission
    // DELETE /v1/admin/permissions/:id
    Route::delete(':id', 'app\controller\admin\PermissionController@delete')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'permission.delete')
        ->pattern(['id' => '\d+']);
});

// Admin Casbin routes | 管理员 Casbin 路由
Route::group('v1/admin/casbin', function () {
    // 手动刷新策略 | Manually reload policy
    // POST /v1/admin/casbin/reload-policy
    //
    // 权限要求 | Permission Required: casbin.manage
    // 限流保护 | Rate Limit: 10 requests per minute
    Route::post('reload-policy', 'app\controller\admin\CasbinController@reloadPolicy')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'casbin.manage')
        ->middleware(\app\middleware\RateLimit::class);
});

// User Management routes | 用户管理路由
Route::group('v1/admin/users', function () {
    // 获取用户列表 | Get user list
    // GET /v1/admin/users
    Route::get('', 'app\controller\admin\UserController@index')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'user.read');

    // 获取单个用户 | Get single user
    // GET /v1/admin/users/:id
    Route::get(':id', 'app\controller\admin\UserController@read')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'user.read')
        ->pattern(['id' => '\d+']);

    // 创建用户 | Create user
    // POST /v1/admin/users
    Route::post('', 'app\controller\admin\UserController@save')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'user.write');

    // 更新用户 | Update user
    // PUT /v1/admin/users/:id
    Route::put(':id', 'app\controller\admin\UserController@update')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'user.write')
        ->pattern(['id' => '\d+']);

    // 删除用户 | Delete user
    // DELETE /v1/admin/users/:id
    Route::delete(':id', 'app\controller\admin\UserController@delete')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'user.delete')
        ->pattern(['id' => '\d+']);

    // 更新用户状态 | Update user status
    // PATCH /v1/admin/users/:id/status
    Route::patch(':id/status', 'app\controller\admin\UserController@updateStatus')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'user.write')
        ->pattern(['id' => '\d+']);

    // 设置用户角色 | Set user roles
    // PUT /v1/admin/users/:id/roles
    Route::put(':id/roles', 'app\controller\admin\UserController@setRoles')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'user.write')
        ->pattern(['id' => '\d+']);

    // 重置用户密码 | Reset user password
    // POST /v1/admin/users/:id/reset-password
    Route::post(':id/reset-password', 'app\controller\admin\UserController@resetPassword')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'user.write')
        ->pattern(['id' => '\d+']);
});

// Permission Monitor routes | 权限监控路由
Route::group('v1/admin/permission-monitor', function () {
    // 获取缓存统计 | Get cache statistics
    // GET /v1/admin/permission-monitor/cache-stats
    Route::get('cache-stats', 'app\controller\api\admin\PermissionMonitorController@getCacheStats');

    // 获取性能指标 | Get performance metrics
    // GET /v1/admin/permission-monitor/performance
    Route::get('performance', 'app\controller\api\admin\PermissionMonitorController@getPerformanceMetrics');

    // 清除用户缓存 | Clear user cache
    // DELETE /v1/admin/permission-monitor/cache/user/:userId
    Route::delete('cache/user/:userId', 'app\controller\api\admin\PermissionMonitorController@clearUserCache');

    // 清除所有缓存 | Clear all cache
    // DELETE /v1/admin/permission-monitor/cache
    Route::delete('cache', 'app\controller\api\admin\PermissionMonitorController@clearAllCache');

    // 获取用户权限 | Get user permissions
    // GET /v1/admin/permission-monitor/user/:userId/permissions
    Route::get('user/:userId/permissions', 'app\controller\api\admin\PermissionMonitorController@getUserPermissions');

    // 检查用户权限 | Check user permission
    // GET /v1/admin/permission-monitor/user/:userId/check/:permission
    Route::get('user/:userId/check/:permission', 'app\controller\api\admin\PermissionMonitorController@checkPermission');

    // 健康检查 | Health check
    // GET /v1/admin/permission-monitor/health
    Route::get('health', 'app\controller\api\admin\PermissionMonitorController@healthCheck');
});

// Department Management routes | 部门管理路由
Route::group('v1/admin/depts', function () {
    // 获取部门列表（树形结构） | Get department list (tree structure)
    // GET /v1/admin/depts
    Route::get('', 'app\controller\admin\DeptController@index')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'dept.read');

    // 获取单个部门 | Get single department
    // GET /v1/admin/depts/:id
    Route::get(':id', 'app\controller\admin\DeptController@read')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'dept.read')
        ->pattern(['id' => '\d+']);

    // 创建部门 | Create department
    // POST /v1/admin/depts
    Route::post('', 'app\controller\admin\DeptController@save')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'dept.write');

    // 更新部门 | Update department
    // PUT /v1/admin/depts/:id
    Route::put(':id', 'app\controller\admin\DeptController@update')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'dept.write')
        ->pattern(['id' => '\d+']);

    // 删除部门 | Delete department
    // DELETE /v1/admin/depts/:id
    Route::delete(':id', 'app\controller\admin\DeptController@delete')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'dept.delete')
        ->pattern(['id' => '\d+']);
});

// Menu Management routes | 菜单管理路由
Route::group('v1/admin/menus', function () {
    // 检查菜单名称是否存在 | Check if menu name exists
    // GET /v1/admin/menus/check-name (backward compatible)
    // GET /v1/admin/menus/name-exists (frontend expected)
    Route::get('check-name', 'app\controller\admin\MenuController@checkName')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'menu.read');
    Route::get('name-exists', 'app\controller\admin\MenuController@checkName')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'menu.read');

    // 检查菜单路径是否存在 | Check if menu path exists
    // GET /v1/admin/menus/check-path (backward compatible)
    // GET /v1/admin/menus/path-exists (frontend expected)
    Route::get('check-path', 'app\controller\admin\MenuController@checkPath')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'menu.read');
    Route::get('path-exists', 'app\controller\admin\MenuController@checkPath')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'menu.read');

    // 获取菜单列表（树形结构） | Get menu list (tree structure)
    // GET /v1/admin/menus
    Route::get('', 'app\controller\admin\MenuController@index')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'menu.read');

    // 获取单个菜单 | Get single menu
    // GET /v1/admin/menus/:id
    Route::get(':id', 'app\controller\admin\MenuController@read')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'menu.read')
        ->pattern(['id' => '\d+']);

    // 创建菜单 | Create menu
    // POST /v1/admin/menus
    Route::post('', 'app\controller\admin\MenuController@save')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'menu.write');

    // 更新菜单 | Update menu
    // PUT /v1/admin/menus/:id
    Route::put(':id', 'app\controller\admin\MenuController@update')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'menu.write')
        ->pattern(['id' => '\d+']);

    // 删除菜单 | Delete menu
    // DELETE /v1/admin/menus/:id
    Route::delete(':id', 'app\controller\admin\MenuController@delete')
        ->middleware(\app\middleware\Auth::class)
        ->middleware(\app\middleware\Permission::class, 'menu.delete')
        ->pattern(['id' => '\d+']);
});
