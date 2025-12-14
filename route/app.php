<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Route;

Route::get('think', function () {
    return 'hello,ThinkPHP8!';
});

Route::get('hello/:name', 'index/hello');


// Debug routes (only enabled in non-production environments)
$__appEnv = strtolower((string) env('APP_ENV', 'production'));
$__prodLikeEnvs = ['production', 'prod', 'stage', 'staging'];
if (!in_array($__appEnv, $__prodLikeEnvs, true)) {
    Route::get('debug/session-redis', 'DebugController/sessionRedis');
}
