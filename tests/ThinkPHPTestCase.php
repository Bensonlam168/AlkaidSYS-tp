<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use think\App;

/**
 * ThinkPHP Test Case | ThinkPHP测试用例基类
 *
 * Provides ThinkPHP framework initialization for tests that need it.
 * 为需要ThinkPHP框架的测试提供初始化。
 */
abstract class ThinkPHPTestCase extends BaseTestCase
{
    protected static ?App $app = null;

    /**
     * Track whether we've restored PHPUnit's global error/exception handlers
     * after ThinkPHP's Error initializer has registered its own handlers.
     *
     * 在测试环境中，ThinkPHP 的 \\think\\initializer\\Error::init() 会通过
     * set_error_handler/set_exception_handler 注册全局处理器，覆盖 PHPUnit 的 handler，
     * 若不恢复会导致 PHPUnit 11 报告 Risky。
     */
    protected static bool $errorHandlersRestored = false;

    /**
     * Bootstrap ThinkPHP application | 启动ThinkPHP应用
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$app === null) {
            $rootPath = dirname(__DIR__);

            // Load ThinkPHP helper functions (including env())
            // 加载 ThinkPHP 辅助函数（包括 env()）
            $helperFile = $rootPath . '/vendor/topthink/framework/src/helper.php';
            if (file_exists($helperFile)) {
                require_once $helperFile;
            }

            // Initialize ThinkPHP App | 初始化ThinkPHP应用
            self::$app = new App($rootPath);

            // Manual environment variables loading | 手动加载环境变量
            $envFile = $rootPath . '/.env';
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos(trim($line), '#') === 0) {
                        continue;
                    }
                    if (strpos($line, '=') !== false) {
                        list($name, $value) = explode('=', $line, 2);
                        putenv(trim($name) . '=' . trim($value));
                    }
                }
            }

            // Initialize database configuration | 初始化数据库配置
            $dbConfig = [
                'default' => 'mysql',
                'connections' => [
                    'mysql' => [
                        'type' => 'mysql',
                        'hostname' => getenv('DB_HOST') ?: '127.0.0.1',
                        'database' => getenv('DB_NAME') ?: 'alkaid_sys',
                        'username' => getenv('DB_USER') ?: 'root',
                        'password' => getenv('DB_PASS') ?: 'root',
                        'hostport' => getenv('DB_PORT') ?: '3306',
                        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
                        'prefix' => '',
                    ],
                ],
            ];

            // Initialize Cache configuration | 初始化Cache配置
            // R26：派生自 config/cache.php，不再手抄整份 cache 数组，
            // 避免 config/cache.php 演进时测试配置静默漂移（此前仅 3 处有意偏离，却复制了全部结构）。
            // Redis store 必须保留：RateLimitService、Casbin 依赖 cache.stores.redis。
            // 注意：必须在 App::initialize() 之前注入——多数测试在 initialize 之前就调用 Cache::clear()，
            // 而 File 驱动实例一旦创建就被容器缓存、之后不会重建，仅改 config 文件对测试路径无效。
            $cacheConfig = require $rootPath . '/config/cache.php';

            // 偏离 1：默认驱动钉死为 file。
            // 理由：测试不应依赖 Redis 可用性，也不应被运行环境导出的 CACHE_DRIVER 影响
            // （容器内 php 的 variables_order 含 E，shell 导出的 CACHE_DRIVER 会进入 $_ENV 被 env() 读到，
            // 而 config 在 APP_ENV=production 时还会强制 redis）。
            $cacheConfig['default'] = 'file';

            // 偏离 2：测试缓存键前缀与生产键区分，避免测试写入/清理污染生产键空间。
            $cacheConfig['stores']['redis']['prefix'] = 'alkaid:test:';

            // 不再覆盖 stores.file.path：config 中为 ''，由 File 驱动回落为
            // App::getRuntimePath() . 'cache' 并补目录分隔符（vendor/topthink/framework/src/think/cache/driver/File.php:53-58），
            // 即 $rootPath/runtime/cache/，与旧代码硬编码的绝对路径完全等价。
            //
            // 同时刻意不覆盖 stores.file.prefix（R20 依赖）：该前缀来自 config 的
            // env('CACHE_FILE_PREFIX', '')，phpunit.xml 注入 CACHE_FILE_PREFIX=test，
            // 使 Cache::clear()（File 驱动实现为 rmdir(path . prefix)）只清 runtime/cache/test/ 子树，
            // 而不是整个 runtime/cache 目录（整目录删除会误删并行跑测试的进程写入的缓存，导致 Casbin 系列伪失败）。
            // 若这条派生链断裂，R20 的整目录删除问题会回归。

            // Initialize Log configuration | 初始化Log配置
            $logConfig = [
                'default' => 'file',
                'channels' => [
                    'file' => [
                        'type' => 'File',
                        'path' => $rootPath . '/runtime/log/',
                    ],
                ],
            ];

            // Set configurations in App | 在App中设置配置
            self::$app->config->set($dbConfig, 'database');
            self::$app->config->set($cacheConfig, 'cache');
            self::$app->config->set($logConfig, 'log');

            // Initialize runtime directories | 初始化运行时目录
            if (!is_dir($rootPath . '/runtime/cache')) {
                mkdir($rootPath . '/runtime/cache', 0777, true);
            }
            if (!is_dir($rootPath . '/runtime/log')) {
                mkdir($rootPath . '/runtime/log', 0777, true);
            }

            // Load service providers from app/provider.php | 加载服务提供者
            // This is critical for binding app\Request to think\Request
            // 这对于将 app\Request 绑定到 think\Request 至关重要
            $providerFile = $rootPath . '/app/provider.php';
            if (file_exists($providerFile)) {
                $providers = include $providerFile;
                if (is_array($providers)) {
                    foreach ($providers as $abstract => $concrete) {
                        self::$app->bind($abstract, $concrete);
                    }
                }
            }
        }
    }

    /**
     * Run HTTP request through ThinkPHP and restore PHPUnit's global handlers once.
     *
     * 在第一次通过 http->run() 执行请求后，恢复 PHPUnit 启动时的 error/exception handler，
     * 以避免 PHPUnit 将 ThinkPHP 注册的全局 handler 视为“测试代码未清理自己的 handler”而标记 Risky。
     */
    protected function runHttp($request)
    {
        $response = self::$app->http->run($request);

        if (!self::$errorHandlersRestored) {
            restore_error_handler();
            restore_exception_handler();
            self::$errorHandlersRestored = true;
        }

        return $response;
    }

    /**
     * Get ThinkPHP App instance | 获取ThinkPHP应用实例
     */
    protected function app(): App
    {
        return self::$app;
    }

    /**
     * Get ThinkPHP App instance for container operations | 获取用于容器操作的 ThinkPHP App 实例
     *
     * This method is provided for MockContainerTrait compatibility.
     * 此方法提供与 MockContainerTrait 的兼容性。
     *
     * @return App
     */
    protected function getContainerApp(): App
    {
        return self::$app;
    }
}
