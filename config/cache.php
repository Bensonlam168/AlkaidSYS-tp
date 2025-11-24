<?php

// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

$appEnv = strtolower((string) env('APP_ENV', 'production'));
$prodLikeEnvs = ['production', 'prod', 'stage', 'staging'];
$defaultStore = in_array($appEnv, $prodLikeEnvs, true)
    ? 'redis'
    : (string) env('CACHE_DRIVER', 'file');

return [
    // 默认缓存驱动 | Default cache driver
    // 生产环境强制使用 Redis，非生产环境可通过 CACHE_DRIVER 配置（默认 file）
    // Production forces Redis; non-production can choose driver via CACHE_DRIVER (default: file)
    'default' => $defaultStore,

    // 缓存连接方式配置
    'stores'  => [
        'file' => [
            // 驱动方式
            'type'       => 'File',
            // 缓存保存目录
            'path'       => '',
            // 缓存前缀
            'prefix'     => '',
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        'redis' => [
            // 驱动方式
            'type'       => 'Redis',
            // Redis主机地址
            'host'       => env('REDIS_HOST', 'redis'),
            // Redis端口
            'port'       => (int) env('REDIS_PORT', 6379),
            // Redis密码
            'password'   => (string) env('REDIS_PASSWORD', ''),
            // Redis数据库索引
            'select'     => (int) env('REDIS_DB', 0),
            // 缓存前缀
            'prefix'     => 'alkaid:',
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制
            'serialize'  => [],
            // 超时时间
            'timeout'    => 0,
        ],
        // 更多的缓存连接
    ],
];
