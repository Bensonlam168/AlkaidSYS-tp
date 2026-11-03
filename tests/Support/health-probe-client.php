<?php

declare(strict_types=1);

/**
 * 真实进程探针客户端 | Real-process health probe client
 *
 * 用法（由 `tests/Feature/ReadinessProbeTest` 通过 `Tests\Support\CliProcess::phpScript()` 调用）：
 *
 *   php tests/Support/health-probe-client.php healthz [none|redis_down|db_down]
 *
 * 输出：一行 `PROBE_RESULT={json}`，含状态码、关键响应头与解析后的响应体。
 *
 * ## 为什么需要「另一个进程」
 *
 * 「Redis 不可达时 `/healthz` 仍 200」这类结论在**同一个进程**里做不出来：
 *   - 同进程里「让 Redis 不可达」只能改配置或换 mock；改配置对**已经建立**的连接/驱动
 *     实例无效（`think\Cache` 会缓存驱动），mock 则完全绕过了真实客户端；
 *   - 真正要证明的是「真实的 PHP 进程 + 真实的 phpredis 客户端 + 真实的中间件链，
 *     在 Redis 连不上的时候 `/healthz` 依然 200」。
 *
 * 因此这里起一个**独立 PHP 进程**：`Tests\Support\CliProcess` 会用 `PHP_ENV_NAME` 把它指向
 * 一份临时环境文件（因此绝不读开发库 `.env`），本脚本再在**启动完成之后**把依赖
 * 「弄坏」，最后走一遍真实的 `think\Http::run()`。
 *
 * ## 为什么是「启动之后再弄坏」
 *
 * `app/service.php` 里的 `RedisHealthCheckService` 会在 `App::initialize()` 阶段做一次
 * Redis 读写自检，**连不上就直接抛异常**（启动期 fail-fast，这是既有设计）。
 * 也就是说「Redis 一开始就不可达」根本走不到探针 —— 那是**启动**问题（容器会崩溃重启），
 * 不是**运行期**问题。
 *
 * 本脚本复现的是真正危险的那个场景：**worker 已经跑起来，随后 Redis 掉了**。
 * 做法是把 `session.store` 指向一个新增的、指向 `127.0.0.1:1`（无人监听）的 Redis store：
 * `SessionInit` 第一次访问 session 时会用真实 phpredis 去连，拿到的是**真实的
 * `Connection refused`**，而不是 mock 出来的异常。
 *
 * `db_down` 同理：把数据库连接指向 `127.0.0.1:1`。DB 在启动期不会被连接（只有
 * `/readyz` 会去查），因此不需要额外的绕法。
 *
 * ## fail-closed
 *
 * 本脚本会连数据库（`/readyz` 的检查），因此启动后立刻断言实际生效的库名含 `_test`，
 * 不满足直接退出（exit 3）。这是 `CliProcess` 那道守卫在「脚本侧」的复核。
 *
 * @package Tests\Support
 */

use think\App;

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

$targetPath = (string) ($argv[1] ?? '');
$scenario   = (string) ($argv[2] ?? 'none');

if ($targetPath === '') {
    fwrite(STDERR, "usage: health-probe-client.php <pathinfo> [none|redis_down|db_down]\n");
    exit(2);
}

/**
 * 在**启动完成之后**制造依赖故障
 *
 * 刻意不用 mock：被改动的只有「连接配置」，客户端、中间件链、控制器全部是真实代码。
 *
 * @param App    $app      已初始化的应用
 * @param string $scenario none / redis_down / db_down
 * @return void
 */
function simulateDependencyOutage(App $app, string $scenario): void
{
    if ($scenario === 'none') {
        return;
    }

    if ($scenario === 'redis_down') {
        // 1) 让 SessionInit 去用一个连不通的 Redis store（真实连、真实失败）
        $session          = (array) $app->config->get('session');
        $session['store'] = 'probe_redis_down';
        $app->config->set($session, 'session');

        // 2) 该 store 指向 127.0.0.1:1（无人监听 → 立即 Connection refused）
        $cache = (array) $app->config->get('cache');
        $cache['stores']['probe_redis_down'] = [
            'type'       => 'Redis',
            'host'       => '127.0.0.1',
            'port'       => 1,
            'password'   => '',
            'select'     => 0,
            'timeout'    => 1,
            'expire'     => 0,
            'persistent' => false,
            'prefix'     => 'probe:',
            'tag_prefix' => 'tag:',
            'serialize'  => [],
            'fail_delete' => false,
        ];
        $app->config->set($cache, 'cache');

        return;
    }

    if ($scenario === 'db_down') {
        $database = (array) $app->config->get('database');
        $database['connections']['mysql']['hostname'] = '127.0.0.1';
        $database['connections']['mysql']['hostport'] = '1';
        $app->config->set($database, 'database');

        return;
    }

    fwrite(STDERR, sprintf("unknown scenario [%s]\n", $scenario));
    exit(2);
}

$app = new App($root);
$app->initialize();

// 与 app/provider.php 一致：路由/控制器里拿到的 request 必须是 app\Request（带 traceId 等方法）
$app->bind(\think\Request::class, \app\Request::class);

$effectiveDbName = (string) config('database.connections.mysql.database');
if (!str_contains($effectiveDbName, '_test')) {
    fwrite(STDERR, sprintf("refuse to run: effective database [%s] is not a _test database\n", $effectiveDbName));
    exit(3);
}

simulateDependencyOutage($app, $scenario);

$request = new \app\Request();
$request->setMethod('GET');
$request->setPathinfo($targetPath);

$response = $app->http->run($request);
$content  = (string) $response->getContent();
$decoded  = json_decode($content, true);

$result = [
    'path'            => $targetPath,
    'scenario'        => $scenario,
    'status'          => $response->getCode(),
    'content_type'    => (string) $response->getHeader('Content-Type'),
    'cache_control'   => (string) $response->getHeader('Cache-Control'),
    'trace_id_header' => (string) $response->getHeader('X-Trace-Id'),
    // 是否带会话 cookie：SessionInit 执行过的直接证据（探针被旁路时不会有）
    'session_cookie'  => (string) $response->getHeader('Set-Cookie'),
    // 旁路层标记：非空说明响应由 HealthProbeBypass 直接产出（SessionInit 等全未执行）
    'bypass_header'   => (string) $response->getHeader('X-Health-Probe-Bypass'),
    // 非 JSON 响应（例如异常页）只回报长度，避免把整页 HTML 塞进断言输出
    'json'            => is_array($decoded) ? $decoded : null,
    'raw_length'      => strlen($content),
];

printf("PROBE_RESULT=%s\n", json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
