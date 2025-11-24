<?php
// +----------------------------------------------------------------------
// | 认证和授权路由 | Auth Routes
// +----------------------------------------------------------------------

use think\facade\Route;

// Auth routes | 认证路由
Route::group('v1/auth', function () {
    Route::post('login', 'AuthController@login');
    Route::post('register', 'AuthController@register');
    Route::post('refresh', 'AuthController@refresh');
    
    // Protected routes | 受保护的路由
    Route::get('me', 'AuthController@me')->middleware(\app\middleware\Auth::class);
});
