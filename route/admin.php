<?php

// +----------------------------------------------------------------------
// | 管理员路由 | Admin Routes
// +----------------------------------------------------------------------

use think\facade\Route;

// Admin Casbin routes | 管理员 Casbin 路由
Route::group('v1/admin/casbin', function () {
    // 手动刷新策略 | Manually reload policy
    // POST /v1/admin/casbin/reload-policy
    Route::post('reload-policy', '\\app\\controller\\admin\\CasbinController@reloadPolicy')
        ->middleware([
            \app\middleware\Auth::class,
            // TODO: 添加权限中间件 Permission::class . ':casbin:manage'
            // TODO: 添加限流中间件 RateLimit::class . ':10,60'
        ]);
});

