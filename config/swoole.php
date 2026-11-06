<?php

return [
    'http'       => [
        'enable'     => true,
        'host'       => '0.0.0.0',
        'port'       => 8080,
        'worker_num' => swoole_cpu_num(),
        'options'    => [],
    ],
    'websocket'  => [
        'enable'        => false,
        'route' => true,
        'handler'       => \think\swoole\websocket\Handler::class,
        'ping_interval' => 25000,
        'ping_timeout'  => 60000,
        'room'          => [
            'type'  => 'table',
            'table' => [
                'room_rows'   => 8192,
                'room_size'   => 2048,
                'client_rows' => 4096,
                'client_size' => 2048,
            ],
            'redis' => [
                'host'          => '127.0.0.1',
                'port'          => 6379,
                'max_active'    => 3,
                'max_wait_time' => 5,
            ],
        ],
        'listen'        => [],
        'subscribe'     => [],
    ],
    'rpc'        => [
        'server' => [
            'enable'     => false,
            'host'       => '0.0.0.0',
            'port'       => 9000,
            'worker_num' => swoole_cpu_num(),
            'services'   => [],
        ],
        'client' => [],
    ],
    //队列
    'queue'      => [
        'enable'  => false,
        'workers' => [],
    ],
    //热更新
    'hot_update' => [
        'enable'  => env('APP_DEBUG', false),
        'name'    => ['*.php'],
        // 监听目录：app/、route/、infrastructure/、domain/
        // 机制：hot_update worker 按 mtime 轮询 include 下的 *.php（Timer::tick 2000ms），
        // 发现变化就给其它 worker 发 ReloadMessage；worker 退出后由 Swoole\Process\Pool 重新拉起，
        // 而 InteractsWithHttp::createHttpServer() 第一行就是 preloadHttp()，
        // 会重新 loadRoutes()/loadMiddleware()，因此 route/ 纳入监听后改路由文件即可自动生效。
        //
        // infrastructure/ 与 domain/ 必须在这里：分层的初衷是把业务逻辑从 controller 挪出去，
        // 而工作流这类模块的编排全在 Infrastructure\Workflow\Service\**。
        // 漏掉它们的后果不是「稍慢一点生效」，而是**改了代码却毫无反应** ——
        // 常驻 worker 里早已载入旧类，只有重启容器才会换。这类静默失效最难定位：
        // 测试跑的是新代码（CLI 进程），HTTP 跑的是旧代码，两边表现不一致。
        // 代价是轮询文件数从 97 涨到 235（只是 stat，不解析），可忽略。
        //
        // 注意：config/ 不在此列（config/swoole.php 本身改动必须重启容器）。
        'include' => [
            app_path(),
            root_path() . 'route',
            root_path() . 'infrastructure',
            root_path() . 'domain',
        ],
        'exclude' => [],
    ],
    //连接池
    'pool'       => [
        'db'    => [
            'enable'        => true,
            'max_active'    => 3,
            'max_wait_time' => 5,
        ],
        'cache' => [
            'enable'        => true,
            'max_active'    => 3,
            'max_wait_time' => 5,
        ],
        //自定义连接池
    ],
    'ipc'        => [
        'type'  => 'unix_socket',
        'redis' => [
            'host'          => '127.0.0.1',
            'port'          => 6379,
            'max_active'    => 3,
            'max_wait_time' => 5,
        ],
    ],
    //锁
    'lock'       => [
        'enable' => false,
        'type'   => 'table',
        'redis'  => [
            'host' => '127.0.0.1',
            'port'          => 6379,
            'max_active'    => 3,
            'max_wait_time' => 5,
        ],
    ],
    'tables'     => [],
    //每个worker里需要预加载以共用的实例
    'concretes'  => [],
    //重置器
    'resetters'  => [],
    //每次请求前需要清空的实例
    'instances'  => [],
    //每次请求前需要重新执行的服务
    'services'   => [],
];
