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

// ===========================================================================
// 健康探针 | Health probe
// ===========================================================================
//
// `GET /healthz` —— **liveness** 探针：只回答一个问题「这个 Swoole worker 还能不能响应请求」。
//
// 边界（都是刻意的）：
//   - **不查库**：控制器里没有任何 Db/Cache 调用，也不做鉴权（不挂 Auth / Permission），
//     被容器 HEALTHCHECK、docker-compose healthcheck、负载均衡高频调用时必须便宜且无副作用；
//   - **无副作用**：同一个请求打 1 次和打 1 万次，业务状态都不变（幂等）；
//   - **返回统一响应结构**：`code / message / data / timestamp(/ trace_id)`，见
//     `AGENTS.md` 的「REST 与错误码」约束与 `app\controller\ApiController` 的类注释。
//
// ⚠️ 它**不能**证明「数据库 / Redis 可用」（那属于 readiness 探针的职责）。
//    具体原因（全局 `SessionInit` 会读 Redis 会话）与后续做法见
//    `docs/technical-specs/deployment/image-and-orchestration-production-requirements.md` §6.
Route::get('healthz', 'app\controller\HealthController@index');

// ===========================================================================
// 框架演示路由（仅非生产环境注册）| Framework demo routes (non-production only)
// ===========================================================================
//
// 这两个路由是 ThinkPHP 骨架自带的演示/调试入口，**生产环境不注册**：
//   - `GET /think`      —— 闭包直接返回 `hello,ThinkPHP8!`，零业务价值，
//                          且把「本服务是 ThinkPHP」这件事无条件暴露给外部（指纹识别）；
//   - `GET /hello/:name` —— 回显用户输入（`app\controller\Index::hello()`），同样零业务价值。
//
// 保留在 dev/test/local 的理由：本地排障时 `/think` 是最便宜的「路由 + 中间件能否跑通」验证点
// （容器里 `curl 127.0.0.1:8080/think` 立刻给结论，不经过任何业务逻辑）。
// 注意：**没有任何测试依赖它们**（探针链路改由 `tests/Feature/HealthzProbeTest` 用 `/healthz` 验证），
// 所以生产环境删掉它们不会影响回归套件。
//
// ⚠️ 只删路由**不等于**删掉入口：`config/route.php` 的 `url_route_must = false`，
//    没有任何规则命中时会退化到「控制器/方法」自动解析，因此 `/index/hello`（以及
//    `/index` 的 ThinkPHP 欢迎页）仍然可达。生产环境的那扇门由
//    `app\controller\Index` 自己按 `APP_ENV` 关掉（见该类注释），不要只依赖本文件。
//
// ⚠️ 注册结果依赖 `APP_ENV`，因此 `php think route:verify` 必须在**非生产**环境下运行
//    （CI 用的是 `APP_ENV=testing`）。在 `APP_ENV=production` 下跑 route:verify 会把
//    文档里的这两行判成 `extra` —— 这与既有的 `/debug/session-redis` 是同一类行为，
//    因为生产环境本就不注册它们。
$__appEnv = strtolower((string) env('APP_ENV', 'production'));
$__prodLikeEnvs = ['production', 'prod', 'stage', 'staging'];
if (!in_array($__appEnv, $__prodLikeEnvs, true)) {
    Route::get('think', function () {
        return 'hello,ThinkPHP8!';
    });

    Route::get('hello/:name', 'index/hello');

    // Debug routes (only enabled in non-production environments)
    // 调试路由：唯一的入口 /debug/session-redis，用于联调 Session ↔ Redis 是否真的打通。
    Route::get('debug/session-redis', 'DebugController/sessionRedis');
}
