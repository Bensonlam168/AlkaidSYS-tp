<?php
// +----------------------------------------------------------------------
// | 认证和授权路由 | Auth Routes
// +----------------------------------------------------------------------

use think\facade\Route;

// Auth routes | 认证路由
Route::group('v1/auth', function () {
    Route::post('login', '\\app\\controller\\AuthController@login');
    Route::post('register', '\\app\\controller\\AuthController@register');
    Route::post('refresh', '\\app\\controller\\AuthController@refresh');

    // Protected routes | 受保护的路由
    Route::get('me', '\\app\\controller\\AuthController@me')->middleware(\app\middleware\Auth::class);
    Route::get('codes', '\\app\\controller\\AuthController@codes')->middleware(\app\middleware\Auth::class);
});
