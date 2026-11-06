<?php

use app\AppService;
use app\provider\CacheEnvironmentGuardService;
use app\provider\SessionEnvironmentGuardService;
use app\provider\RedisHealthCheckService;
use app\provider\WorkflowEngineService;

// 系统服务定义文件
// 服务在完成全局初始化之后执行
return [
    AppService::class,
    CacheEnvironmentGuardService::class,
    SessionEnvironmentGuardService::class,
    RedisHealthCheckService::class,
    // 工作流引擎装配（只接线，不构造：构造代价与时机见该类注释）
    WorkflowEngineService::class,
];
