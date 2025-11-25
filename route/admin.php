<?php

// +----------------------------------------------------------------------
// | 管理员路由 | Admin Routes
// +----------------------------------------------------------------------

use think\facade\Route;

// Admin Casbin routes | 管理员 Casbin 路由
Route::group('v1/admin/casbin', function () {
    // 手动刷新策略 | Manually reload policy
    // POST /v1/admin/casbin/reload-policy
    //
    // 权限要求 | Permission Required: casbin.manage
    // 限流保护 | Rate Limit: 10 requests per minute
    Route::post('reload-policy', '\\app\\controller\\admin\\CasbinController@reloadPolicy')
        ->middleware([
            \app\middleware\Auth::class,
            \app\middleware\Permission::class . ':casbin.manage',
            \app\middleware\RateLimit::class,
        ]);
});

