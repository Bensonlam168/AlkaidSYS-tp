<?php

use app\AppService;
use app\provider\CacheEnvironmentGuardService;
use app\provider\SessionEnvironmentGuardService;
use app\provider\RedisHealthCheckService;

// 系统服务定义文件
// 服务在完成全局初始化之后执行
return [
    AppService::class,
    CacheEnvironmentGuardService::class,
    SessionEnvironmentGuardService::class,
    RedisHealthCheckService::class,
];
