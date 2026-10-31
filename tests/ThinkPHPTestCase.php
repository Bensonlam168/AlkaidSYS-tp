<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use RuntimeException;
use think\App;
use think\Env;

/**
 * ThinkPHP Test Case | ThinkPHP测试用例基类
 *
 * Provides ThinkPHP framework initialization for tests that need it.
 * 为需要ThinkPHP框架的测试提供初始化。
 *
 * ## 测试资源隔离（ER-15）
 *
 * 背景：此前本基类在 `setUpBeforeClass()` 里逐行读取 `.env` 并 `putenv()` 全部键，
 * 而 `.env` 的 `DB_NAME = alkaid_sys` 是**开发库**；随后据此构造连接配置，
 * 于是「本地跑测试 = 直接写开发库」。开发库已因此沉淀 369 张表 / 207 个租户。
 *
 * 现在的三道防线：
 *   1. **DB_\* 不再来自 `.env`**：既不在手工 `putenv()` 里写回，也不允许 ThinkPHP
 *      的 `Env::load()` 把它们塞进运行时环境（见 `createIsolatedDbEnv()` 的说明）。
 *   2. **fail-closed 断言**：解析出的库名必须含 `_test`，否则**抛异常终止**，
 *      而不是只打一条 warning 继续跑（警告会被淹没，测试照样写开发库）。
 *   3. **显式连接配置**：连接参数由本基类显式构造，不依赖 `config/database.php`
 *      读取 `env()` 的时序。
 *
 * 注意：`phpunit.xml` 里的 DB_* 刻意**不加** `force`（见该文件 A 段注释），
 * 所以 shell 导出的 `DB_NAME` 会胜出 —— 若胜出值不含 `_test`，第 2 道防线会直接拒绝运行。
 */
abstract class ThinkPHPTestCase extends BaseTestCase
{
    /**
     * 测试库名必须包含的标记（fail-closed 判据）
     */
    private const TEST_DB_MARKER = '_test';

    /**
     * 运行环境未提供 DB_NAME 时的兜底值（指向测试库，绝不回落到开发库 alkaid_sys）
     */
    private const DEFAULT_TEST_DB_NAME = 'alkaid_sys_test';

    /**
     * 不允许从 `.env` 覆盖的环境变量前缀（数据库连接参数）
     */
    private const DB_ENV_PREFIX = 'DB_';

    protected static ?App $app = null;

    /**
     * 本次进程解析并钉死的数据库连接参数（唯一来源，`.env` 不参与）
     *
     * @var array<string, string>
     */
    protected static array $dbEnv = [];

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

            // ---------------------------------------------------------------
            // 第 1 步：解析数据库连接参数并做 fail-closed 断言
            // 必须放在创建 App / 打开任何连接之前 —— 不合格时要「立刻退出」，
            // 而不是先连上开发库、跑出半截脏数据再报错。
            // ---------------------------------------------------------------
            self::$dbEnv = self::resolveDatabaseEnv();
            self::assertTestDatabaseName(self::$dbEnv['DB_NAME']);

            // Load ThinkPHP helper functions (including env())
            // 加载 ThinkPHP 辅助函数（包括 env()）
            $helperFile = $rootPath . '/vendor/topthink/framework/src/helper.php';
            if (file_exists($helperFile)) {
                require_once $helperFile;
            }

            // Initialize ThinkPHP App | 初始化ThinkPHP应用
            self::$app = new App($rootPath);

            // ---------------------------------------------------------------
            // 第 2 步：绑定隔离版 Env，阻断 initialize() 阶段 `.env` 对 DB_* 的覆盖
            //
            // 仅「跳过手工 putenv」不足以隔离：App::initialize() 内部还会调用
            // loadEnv() → Env::load('.env') → Env::set()，而 config/database.php
            // 的 database 取的是 env('DB_NAME')。实测（探针）：
            //   跳过 putenv 但换用原生 Env 时，initialize() 之后
            //   config('database.connections.mysql.database') 仍变成 alkaid_sys，
            //   实际连接的库也是 alkaid_sys。
            // 因此这里换成会过滤 DB_* 的 Env 子类，并把解析结果钉进 Env 数据。
            // ---------------------------------------------------------------
            self::$app->instance('env', self::createIsolatedDbEnv(self::$dbEnv));

            // ---------------------------------------------------------------
            // 第 3 步：非 DB_* 键照旧从 `.env` 取（历史行为不变）
            // 例如 JWT_SECRET / JWT_ISSUER / DEFAULT_TIMEZONE 仍依赖 `.env`；
            // CI 没有 `.env` 时这段是 no-op，配置由 workflow 的 env: 注入。
            // ---------------------------------------------------------------
            self::putEnvFromDotEnv($rootPath . '/.env');

            // ---------------------------------------------------------------
            // 第 4 步：把钉死的 DB_* 写回进程环境
            // 目的：仍直接调用 getenv('DB_*') 的代码、以及 exec() 拉起的子进程，
            // 读到的都是**断言通过**的值；即便 phpunit.xml 未注入，也不会写成 alkaid_sys。
            // ---------------------------------------------------------------
            foreach (self::$dbEnv as $key => $value) {
                putenv($key . '=' . $value);
            }

            // Initialize database configuration | 初始化数据库配置
            // 参数全部来自 self::$dbEnv（已断言含 _test），不再逐个 getenv()
            $dbConfig = [
                'default' => 'mysql',
                'connections' => [
                    'mysql' => [
                        'type' => self::$dbEnv['DB_TYPE'],
                        'hostname' => self::$dbEnv['DB_HOST'],
                        'database' => self::$dbEnv['DB_NAME'],
                        'username' => self::$dbEnv['DB_USER'],
                        'password' => self::$dbEnv['DB_PASS'],
                        'hostport' => self::$dbEnv['DB_PORT'],
                        'charset' => self::$dbEnv['DB_CHARSET'],
                        'prefix' => '',
                    ],
                ],
            ];

            // 防御性复核：显式配置写下去之后再确认一次生效的库名
            // （即便上面被误改，也不会带着开发库名跑用例）
            self::assertEffectiveDatabaseIsTest($dbConfig['connections']['mysql']['database'], 'explicit config');

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
     * 解析数据库连接参数 | Resolve database connection parameters
     *
     * 取值优先级：进程环境（phpunit.xml 注入 / CI 的 env: 注入 / shell 导出）> 测试库兜底值。
     * **`.env` 不参与**：`.env` 写的是开发库，这是 ER-15 的根因。
     *
     * @return array<string, string>
     */
    private static function resolveDatabaseEnv(): array
    {
        $read = static function (string $key, string $default): string {
            $value = getenv($key);

            return (false === $value || '' === $value) ? $default : (string) $value;
        };

        return [
            'DB_TYPE' => $read('DB_TYPE', 'mysql'),
            'DB_HOST' => $read('DB_HOST', '127.0.0.1'),
            // 兜底值指向测试库：任何「忘记注入」的场景都不允许回落到 alkaid_sys
            'DB_NAME' => $read('DB_NAME', self::DEFAULT_TEST_DB_NAME),
            'DB_USER' => $read('DB_USER', 'root'),
            'DB_PASS' => $read('DB_PASS', 'root'),
            'DB_PORT' => $read('DB_PORT', '3306'),
            'DB_CHARSET' => $read('DB_CHARSET', 'utf8mb4'),
        ];
    }

    /**
     * fail-closed 断言：库名必须含 `_test`，否则抛异常终止整个测试类
     *
     * 刻意用异常而不是 `trigger_error` / 输出警告：警告不会让 PHPUnit 停下来，
     * 测试会继续在开发库上 CRUD —— 那正是本函数要消灭的行为。
     */
    private static function assertTestDatabaseName(string $dbName): void
    {
        if (str_contains($dbName, self::TEST_DB_MARKER)) {
            return;
        }

        throw new RuntimeException(sprintf(
            "拒绝在非测试库上运行测试：解析出的数据库名「%s」不包含「%s」。\n"
            . "请创建 %s 并检查 phpunit.xml 与 .env：\n"
            . "  1) phpunit.xml 的 <env name=\"DB_NAME\" value=\"%s\"/> 必须指向测试库；\n"
            . "  2) .env 的 DB_NAME 不得指向开发库 alkaid_sys（DB_* 连接键已不从 .env 读取，但请保持正确）；\n"
            . "  3) 不要在 shell 里导出 DB_NAME 覆盖 phpunit.xml（DB_* 刻意未加 force，已存在的值优先）；\n"
            . "  4) 冷启动建库：PHP_ENV_NAME=test php think migrate:run（.env.test 指向测试库）。\n"
            . '参考：ER-15 —— 本地测试曾直接写开发库 alkaid_sys（已沉淀 369 张表 / 207 个租户）。',
            $dbName,
            self::TEST_DB_MARKER,
            self::DEFAULT_TEST_DB_NAME,
            self::DEFAULT_TEST_DB_NAME
        ));
    }

    /**
     * 复核「实际生效」的库名（第二道防线的兜底检查）
     */
    private static function assertEffectiveDatabaseIsTest(string $effectiveDbName, string $source): void
    {
        if (str_contains($effectiveDbName, self::TEST_DB_MARKER)) {
            return;
        }

        throw new RuntimeException(sprintf(
            "拒绝在非测试库上运行测试：%s 解析出的数据库名「%s」不包含「%s」。\n"
            . '请创建 %s 并检查 phpunit.xml 与 .env。',
            $source,
            $effectiveDbName,
            self::TEST_DB_MARKER,
            self::DEFAULT_TEST_DB_NAME
        ));
    }

    /**
     * 构造隔离版 Env | Create isolated Env instance
     *
     * 与原生 `think\Env` 的唯一差别是 `load()`：加载 `.env` / `.env.{PHP_ENV_NAME}` 时
     * **丢弃 DB_\* 键**，并把 resolveDatabaseEnv() 的结果钉进 Env 数据。
     *
     * 为什么必须替换容器实例而不是只在 `load()` 之后覆盖：
     *   `App::initialize()` 会在**任意时刻**（首个 http->run / console 调用）触发
     *   `loadEnv()`，届时 `.env` 里的 DB_NAME 会覆盖任何更早写入的值；
     *   只有替换 Env 实现才能在每次加载时都拦住它。
     *
     * 除 DB_\* 外的键（JWT_SECRET / REDIS_HOST / DEFAULT_TIMEZONE …）行为完全不变。
     *
     * @param array<string, string> $pinnedDbEnv 已解析并断言过的 DB_* 值
     */
    private static function createIsolatedDbEnv(array $pinnedDbEnv): Env
    {
        // 注意：匿名类内部的 `self` 指向匿名类本身，拿不到外层常量，
        // 因此把前缀作为构造参数传进去（避免复制一份字面量导致两处漂移）
        return new class ($pinnedDbEnv, self::DB_ENV_PREFIX) extends Env {
            /**
             * 被钉死的数据库连接键（大写）
             *
             * @var array<string, string>
             */
            private array $pinnedDbKeys;

            /**
             * 数据库相关环境变量前缀（如 DB_）
             */
            private string $dbKeyPrefix;

            /**
             * @param array<string, string> $pinnedDbEnv
             * @param string                $dbKeyPrefix
             */
            public function __construct(array $pinnedDbEnv, string $dbKeyPrefix)
            {
                parent::__construct();

                $this->pinnedDbKeys = $pinnedDbEnv;
                $this->dbKeyPrefix = $dbKeyPrefix;

                // 覆盖 $_ENV 快照：即使外层环境导出了开发库名，Env 数据也以断言通过的值为准
                $this->set($pinnedDbEnv);
            }

            /**
             * 读取环境变量文件，但丢弃 DB_* 键
             *
             * @param string $file
             */
            public function load(string $file): void
            {
                $env = parse_ini_file($file, true, INI_SCANNER_RAW) ?: [];

                foreach (array_keys($env) as $key) {
                    if ($this->isDatabaseKey((string) $key)) {
                        unset($env[$key]);
                    }
                }

                $this->set($env);
            }

            /**
             * 判定是否为「数据库连接键」（整段 DB_* 前缀都算，含 DB_CHARSET / DB_PREFIX 等）
             */
            private function isDatabaseKey(string $key): bool
            {
                $upper = strtoupper($key);

                return str_starts_with($upper, $this->dbKeyPrefix) || array_key_exists($upper, $this->pinnedDbKeys);
            }
        };
    }

    /**
     * 把 `.env` 的非 DB_* 键写回进程环境 | Load non-DB keys from .env into process env
     *
     * 保留历史行为：部分代码仍直接 `getenv('XXX')`（如 config/event.php、config/hook.php）。
     * DB_* 一律跳过 —— 这是 ER-15 的修复点之一，`putenv` 出来的值会被
     * `getenv()` 调用方与子进程读到，写错库名的代价是全库污染。
     */
    private static function putEnvFromDotEnv(string $envFile): void
    {
        if (!file_exists($envFile)) {
            // CI 没有 `.env`：配置全由 workflow 的 env: 注入，这里什么都不做
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);

                // DB_* 不写回：连接参数只认 phpunit.xml / CI env（见 resolveDatabaseEnv）
                if (str_starts_with(strtoupper($name), self::DB_ENV_PREFIX)) {
                    continue;
                }

                putenv($name . '=' . trim($value));
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
        // 发请求前复核一次：initialize() 会按 config/database.php 重建连接配置，
        // 这里确认它拿到的仍是测试库（Env 已隔离，正常情况恒成立；此处只做兜底）
        self::assertEffectiveDatabaseIsTest(
            (string) self::$app->config->get('database.connections.mysql.database'),
            'http->run 前的 database 配置'
        );

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
