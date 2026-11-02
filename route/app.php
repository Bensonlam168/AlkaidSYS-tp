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
// 健康探针 | Health probes
// ===========================================================================
//
// 两个端点、两种语义，**不要混用**：
//
// | 端点 | 语义 | 依赖面 | 失败时编排层该做什么 |
// | --- | --- | --- | --- |
// | `GET /healthz` | **liveness**：本 Swoole worker 还能不能把请求处理完 | 0 个（不查库/不读缓存/不鉴权） | 重启容器 |
// | `GET /readyz`  | **readiness**：运行时依赖（DB/Redis/配置）是否可用 | DB + Redis + 配置 | 摘流量，**不要**重启 |
//
// 边界（都是刻意的）：
//   - **无副作用 / 幂等**：打 1 次和打 1 万次，业务状态都不变（`/readyz` 只 `SELECT 1` + `PING`）；
//   - **无鉴权**：不挂 Auth / Permission —— 编排层不可能持有业务凭据；
//   - **返回统一响应结构**：`code / message / data / timestamp(/ trace_id)`，见
//     `AGENTS.md` 的「REST 与错误码」约束与 `app\controller\ApiController` 的类注释。
//
// ⚠️ 这两个路由的**实际入口是中间件** `app\middleware\HealthProbeBypass`
//    （`app/middleware.php` 里注册在 `SessionInit` 之前）：它命中探针路径就直接调用控制器，
//    绕开会读 Redis 会话的 `SessionInit` 与 fail-closed 的 `TenantIdentify`。
//    没有那层旁路，Redis 一抖动 `/healthz` 就会在路由之前 500，K8s 会把全部实例重启。
//
//    这里仍然注册路由，是作为「中间件没生效时的兜底」：两条路径**共用同一个控制器方法**，
//    响应字段与缓存头完全一致，因此不存在「两条路径都能响应但行为不同」的歧义。
//    代价是：兜底路径会重新经过 SessionInit（见
//    `docs/technical-specs/deployment/image-and-orchestration-production-requirements.md` §6）。
Route::get('healthz', 'app\controller\HealthController@index');
Route::get('readyz', 'app\controller\HealthController@ready');

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
