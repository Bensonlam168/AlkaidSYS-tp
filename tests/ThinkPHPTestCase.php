<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;
use think\App;
use think\Container;
use think\Env;
use think\Middleware;
use think\Request;
use think\Response;

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
 *
 * ## 容器状态隔离（跨测试类 Mock 泄漏）
 *
 * 背景：`self::$app` 是 **static**，`app()` / `getContainerApp()` 返回的始终是同一个 `think\App`，
 * 也就是同一个 `think\Container`。于是「某个测试类往容器里 bind 了 mock」这件事会**穿透到后续所有测试类**
 * —— 只要后续类从容器 `make()` 同一个抽象，拿到的就是上一个类留下的 Mockery Mock。
 * 实测受害：`Tests\Integration\Lowcode\LowcodeCliIntegrationTest`（21 条）与
 * `Tests\E2E\MultiTenantIsolationTest`（3 条）本身一行 Mockery 都没写，却全部
 * `Mockery_4_..._CollectionManager::create(), but no expectations were specified`。
 *
 * 修法：**在容器层面做「基线快照 / 还原」**，而不是给每个受害用例补 `shouldReceive`
 * （那是掩盖：会让集成测试依赖一个假的 CollectionManager，失去集成价值）：
 *   1. `setUpBeforeClass()` 建好 App、绑定 `app/provider.php` 之后，快照容器的 `bind` / `instances`；
 *   2. 每个测试类结束时（`tearDownAfterClass()`）把「测试注入的替身」清掉、把基线放回去。
 *
 * 为什么快照只取 `bind` / `instances` 两个属性：`think\Container` 里承载绑定的就这两个数组
 * （另有 `static $instance` 全局单例指针与 `invokeCallback` 解析回调，反射实证见 `snapshotContainerState()`）。
 *
 * 为什么**不能**整份还原 `bind`：框架服务在 `App::initialize()` 时会通过 `$service->bind` 注册
 * `queue` / `queue.failer`(闭包) / `Faker\Generator`(闭包) / `think\migration\Factory`(闭包) 等，
 * 它们不属于任何测试类；整份回滚会让后续类解析不到这些服务。因此只删「指向被丢弃替身」的绑定。
 *
 * 已知边界（**有意不做**，见验收报告的风险项）：
 *   - 只做「跨测试类」还原，不做「同类内跨测试方法」还原。把 Request / Response 也按方法清理
 *     能顺带修掉 `TraceIdCoverageTest` 在 `--order-by=random` 下的随机红（`app\Request`
 *     的 `$traceId` 属性由 Trace 中间件写入后不自愈，且 `ApiController::getTraceId()` 优先读属性），
 *     但会让 `CollectionControllerTest::testDeleteIgnoresTenantIdInBody` 变红 —— 它目前依赖
 *     上一个方法留在容器里的 `Content-Type` 头才让 `Request::withInput()` 解析出 `put` 数据。
 *     要同时修好，必须先让该用例自己 `withHeader(['Content-Type' => 'application/json'])`。
 *   - `think\Route` 的路由规则、`think\Event` 的监听器、`think\Cache` 的缓存项仍是跨类共享的；
 *     本次不动它们（动 Route 会让 `include_once route/*.php` 的用例注册不到路由）。
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
     * 容器状态基线（跨测试类隔离的唯一依据）
     *
     * 只在首次 `setUpBeforeClass()` 里建立（App 建好、`app/provider.php` 绑定完成、
     * 尚未执行任何用例），之后每个测试类结束时按它把容器还原。
     *
     * 结构 = `think\Container` 中承载绑定的两个属性：
     *   - `bind`      : abstract => 具体类名（字符串别名）/ 闭包 / 实例
     *   - `instances` : abstract => 已解析（或直接注入）的对象实例
     *
     * @var array{bind: array<string, mixed>, instances: array<string, mixed>}|null
     */
    private static ?array $containerBaseline = null;

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

            // ---------------------------------------------------------------
            // 第 5 步：快照容器状态，作为「跨测试类还原」的基线
            //
            // 取在这里的理由：App 已建好、DB/Cache/Log 覆盖配置已写入、
            // provider.php 的绑定已生效，且**还没有任何一个用例跑过** —— 此刻容器里
            // 只有框架自带的绑定与实例（`app` / `think\Container` / 隔离版 `env`），
            // 不含任何测试注入的替身，正是干净的基线。
            // ---------------------------------------------------------------
            self::$containerBaseline = self::snapshotContainerState(self::$app);
        }
    }

    /**
     * 每个测试类结束时还原容器状态 | Restore container state after each test class
     *
     * 与 `setUpBeforeClass()` 成对：把本测试类往容器里塞的东西清掉，
     * 让下一个测试类拿到的是「干净基线 + 框架服务绑定」，而不是上个类留下的 mock。
     *
     * 注意：PHPUnit 10+ 里若 `setUpBeforeClass()` 抛异常，本方法不会被调用，
     * 此时进程内的泄漏会残留 —— 但那种情况整轮测试已经失败，不再叠加误导。
     */
    public static function tearDownAfterClass(): void
    {
        self::restoreContainerState();

        parent::tearDownAfterClass();
    }

    /**
     * 快照容器状态 | Snapshot container state
     *
     * 反射实证（PHP 8.2 + think-container 3.x / think-framework 8.x）：
     * `think\Container` 只声明了 4 个属性，其中承载「绑定」的只有后两个：
     *   - protected static $instance        : 全局容器单例指针（`App::__construct` 里 `static::setInstance($this)`）
     *   - protected        $instances       : abstract => 已解析实例（`make()` 末尾写入）
     *   - protected        $bind            : abstract => 类名字符串 / 闭包
     *   - protected        $invokeCallback  : `resolving()` 注册的解析回调
     * 没有 `alias` / `resolved` 这类属性 —— 别名就是 `$bind` 里的字符串，`resolved` 由 `$instances` 承担。
     *
     * 因此快照只需 `bind` + `instances`。`invokeCallback` 不参与还原：
     * 测试不注册解析回调，而它是框架/服务在 `initialize()` 期注册的，回滚反而会破坏服务。
     *
     * @return array{bind: array<string, mixed>, instances: array<string, mixed>}
     */
    private static function snapshotContainerState(App $app): array
    {
        return [
            'bind' => self::readContainerArray($app, 'bind'),
            'instances' => self::readContainerArray($app, 'instances'),
        ];
    }

    /**
     * 读取容器上的受保护数组属性 | Read a protected array property of the container
     *
     * ThinkPHP 没有提供「列出绑定 / 取消绑定」的公开 API（只有 `bind` / `instance` / `delete`），
     * 因此还原只能走反射。属性声明在 `think\Container` 上，`App` 继承之。
     *
     * @return array<string, mixed>
     */
    private static function readContainerArray(App $app, string $name): array
    {
        $property = new ReflectionProperty(Container::class, $name);
        $property->setAccessible(true);

        $value = $property->getValue($app);

        return is_array($value) ? $value : [];
    }

    /**
     * 写入容器上的受保护数组属性 | Write a protected array property of the container
     *
     * @param array<string, mixed> $value
     */
    private static function writeContainerArray(App $app, string $name, array $value): void
    {
        $property = new ReflectionProperty(Container::class, $name);
        $property->setAccessible(true);
        $property->setValue($app, $value);
    }

    /**
     * 还原容器状态 | Restore container state to the baseline
     *
     * 三条规则（顺序不能换）：
     *   1. 丢弃「本测试类私有」的实例 —— 测试注入的替身（Mockery mock / 匿名类 / 闭包）
     *      与「一个请求一个对象」的 Request / Response；
     *   2. 把基线里的实例放回去（`env` 被换成别的 Env、`app` 被换掉时能纠正回来）；
     *   3. 删除**指向被丢弃替身**的 `bind` 条目，再把基线里的绑定写回。
     *      其余新增绑定一律保留 —— 它们是框架服务注册的（`queue` / `queue.failer` /
     *      `Faker\Generator` / `think\migration\Factory` 等），删了会让后续测试类解析不到服务。
     */
    private static function restoreContainerState(): void
    {
        if (null === self::$app || null === self::$containerBaseline) {
            return;
        }

        $app = self::$app;
        $bind = self::readContainerArray($app, 'bind');
        $instances = self::readContainerArray($app, 'instances');

        // 第 1 步：丢弃本测试类留下的实例
        $discarded = [];
        foreach ($instances as $abstract => $instance) {
            if (!self::isClassLocalInstance($instance)) {
                continue;
            }

            unset($instances[$abstract]);
            $discarded[(string) $abstract] = true;
        }

        // 第 2 步：基线实例归位（快照里的键一定以快照值为准）
        foreach (self::$containerBaseline['instances'] as $abstract => $instance) {
            $instances[$abstract] = $instance;
        }

        // 第 3 步：先删「指向替身」的绑定，再按基线写回基线绑定
        //         闭包绑定必须删：`bind(X, fn () => $mock)` 不会进 `instances` 之外的痕迹，
        //         只清 `instances` 时下一次 `make(X)` 会重新调用那个闭包、把 mock 再端出来。
        foreach (array_keys($discarded) as $abstract) {
            if (!array_key_exists($abstract, self::$containerBaseline['bind'])) {
                unset($bind[$abstract]);
            }
        }
        foreach (self::$containerBaseline['bind'] as $abstract => $concrete) {
            $bind[$abstract] = $concrete;
        }

        self::writeContainerArray($app, 'instances', $instances);
        self::writeContainerArray($app, 'bind', $bind);

        // 第 4 步：兜底把全局容器单例指回测试 App
        //         少数用例会 `new App()` 自建容器（`AppServiceEnvGuardTest` /
        //         `ServiceProviderWiringTest`），正常路径会在 tearDown 里还原；
        //         若用例在 setUp 中途失败，PHPUnit 不会调 tearDown，指针就会漂走，
        //         导致后续所有门面（Facade）都从另一个容器解析。
        if (Container::getInstance() !== $app) {
            Container::setInstance($app);
        }
    }

    /**
     * 判定实例是否「属于单个测试类」——即必须随测试类结束一并丢弃
     *
     * 判据是「这个对象要么是测试塞进来的，要么本来就该按类重建」，因此丢弃不会影响后续类正常解析：
     *   1. Mockery 替身：跨类残留正是本次要修的 24 条失败的根因；
     *   2. 匿名类：测试里 `new class { public function handle($request, $next) {...} }`
     *      顶掉 Auth / Permission 中间件的常见写法（它同样会绕过鉴权，属于「假绿」风险）；
     *   3. 闭包：`instance('xxx', fn () => ...)` 形式的注入；
     *   4. Request / Response：`think\Http::run()` 每次都用 `instance('request', $request)` 覆盖，
     *      说明它们本就是「一个请求一个对象」；跨类存活会把上个用例的 header / tenant / trace_id
     *      带给下一个用例（实测：`FormApiTest` 跑完后 `TraceIdCoverageTest` 的 4 条用例全部
     *      读到了上一个用例的 `X-Trace-Id`，断言期望值对不上）；
     *   5. `think\Middleware`：它的队列是**进程级累加**且框架从不清空
     *      （`think\route\Dispatch::doRouteAfter()` → `$app->middleware->import($middleware, 'route')`）。
     *      于是一个类跑过带路由中间件的路由（如 `v1/auth/me` 上的 `app\middleware\Auth`）之后，
     *      后续类的**每个**请求都会再走一遍 Auth —— 实测 `AccessLogMiddlewareTest` 跑完后，
     *      `AuthLoginRefreshTest` 的 `POST v1/auth/login` 被 Auth 拦成 401 + 2001。
     *   6. 一切**非 `think\` 命名空间**的实例（业务/基础设施/测试命名空间）。
     *      这一条不是「顺手清理」，而是必需的：容器会把 `make()` 解析结果缓存进 `instances`，
     *      而 `app\middleware\Auth` 这类业务类并不在 `bind` 里（靠反射实例化），
     *      于是 A 类跑过一次请求后 `instances['app\middleware\Auth']` 就有了**真实**的 Auth；
     *      B 类再 `bind(app\middleware\Auth::class, fn () => $noop)` 时，`make()` 命中旧实例、
     *      闭包根本不执行 → 鉴权照旧生效 → 断言 200 的用例拿到 401。
     *      实测：`FormApiTest` → `FormDataControllerMultiTenantTest` 3 条用例 401/2001 就是这个原因。
     *
     * 反过来，**框架单例只保留 `think\` 命名空间**的：`think\Config` / `think\Db` / `think\Cache` /
     * `think\Event` / `think\Log` / `think\Lang` / 隔离版 `think\Env` …（`think\Middleware` 是例外，
     * 见第 5 条）。丢 `think\Config` 会连带丢掉本基类在 `setUpBeforeClass()` 里写入的
     * database/cache/log 覆盖配置，丢 `think\Db` 会断开已建立的连接 ——
     * 两者都会让「修好泄漏」变成「换一批失败」。
     * 业务单例（`Infrastructure\*` / `app\*`）丢掉是可接受的：它们的 `bind` 仍在，下次 `make()` 会重建。
     */
    private static function isClassLocalInstance(mixed $instance): bool
    {
        if (!is_object($instance)) {
            return true;
        }

        if ($instance instanceof \Mockery\MockInterface) {
            return true;
        }

        if ((new ReflectionObject($instance))->isAnonymous()) {
            return true;
        }

        if ($instance instanceof \Closure) {
            return true;
        }

        if ($instance instanceof Request || $instance instanceof Response) {
            return true;
        }

        // 5. `think\Middleware` 也必须丢：它的队列是**进程级累加**且框架从不清空
        //    （`think\route\Dispatch::doRouteAfter()` → `$app->middleware->import($middleware, 'route')`）。
        //    于是一个类跑过带路由中间件的路由（如 `v1/auth/me` 上的 `app\middleware\Auth`）之后，
        //    后续类的**每个**请求都会再走一遍 Auth —— 实测 `AccessLogMiddlewareTest` 跑完后，
        //    `AuthLoginRefreshTest` 的 `POST v1/auth/login` 被 Auth 拦成 401 + 2001。
        //    丢掉它是安全的：全局中间件每次请求都会 `loadMiddleware()` 重新 import。
        if ($instance instanceof Middleware) {
            return true;
        }

        return !str_starts_with(get_class($instance), 'think\\');
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
