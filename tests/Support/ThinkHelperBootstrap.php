<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use think\Container;
use think\Env;

/**
 * 测试进程的 ThinkPHP helper / Env 引导 | ThinkPHP helper & Env bootstrap for tests
 *
 * ## 为什么需要它
 *
 * `composer.json` 的 `autoload.files` 是**空数组**，所以框架 helper
 * （`vendor/topthink/framework/src/helper.php`，定义 `env()` / `app()` / `config()` / `json()` 等）
 * **不会**被 `vendor/autoload.php` 自动加载 —— 只有 `think\App::initialize()` 才会
 * `include_once` 它。于是任何「不启动应用、只 require 配置或直接调 helper」的测试
 * 都必须自己补这一课，否则 Fatal error: Call to undefined function env()。
 *
 * 这段引导原先在 9 个测试文件的顶层、外加 tests/Legacy/RateLimitPhpunitConfigGuardTest
 * 里各抄了一份，且 9 份的相对路径深度**各不相同**（`/../../`、`/../../../`、`/../../../../`）。
 * 路径抄错不会立刻炸：`require_once` 只是落空、不报错，随后表现为别处的
 * 「class not exists: env」之类毫不相干的怪异错误。所以这里把它收敛成
 * 「全仓库唯一一处知道 helper 相对仓库根在哪」的地方。
 *
 * ## ① function_exists('env') 与 ② 容器 bound('env') 是两个判定，不能合并（实测踩过）
 *
 * - ① 回答的是「这个函数在不在当前进程里」；
 * - ② 回答的是「容器里有没有 `'env'` 这个标识的实例」。
 *
 * ① 为真**不**代表 ② 为真，也就不代表 `env()` 可调用：`env()` 的实现链是
 * helper 函数 → `think\facade\Env` → 容器标识 `'env'` → `think\Env` 实例。
 * PHPUnit **构建套件**阶段就会加载 9 个测试文件，那些文件顶层的 `require_once`
 * 让 `env()` 早已存在；但此刻**没有任何代码**往容器里绑过 `'env'`，
 * 于是任意 `require config/*.php`（配置里都写 `env('KEY', 默认值)`）立刻抛
 * `ClassNotFoundException: class not exists: env`。
 * 反过来，应用启动过的进程里 ① ② 都为真，此时**不要**再覆盖绑定（见 ensureEnvBinding()）。
 *
 * ## 为什么绑裸 think\Env 拿到的是「生产默认值」，以及为什么不污染后续用例
 *
 * 裸 `new think\Env()` 只用 `$_ENV` 初始化、**不读 `.env`**，所以 `env('KEY', 默认值)`
 * 返回的是 `config/*.php` 里写的那个**默认值**。对「默认部署声明了哪些参数」这类
 * 静态守卫，这正是想要的语义：要的是仓库里的默认值，而不是某台机器 `.env` 的现值。
 *
 * 不污染的原因：`think\App` 构造时会 `static::setInstance($this)` 把**自己**设为当前容器，
 * 并把自己已 load `.env` 的 `think\Env` 绑进**新**容器；此后 `env()` 走 App 的实例，
 * 本类临时绑在旧容器上的裸实例随之失联。同一进程实测：先补 helper + 绑裸 Env、
 * require 完一份配置，再 `new App()->initialize()`，`env('DB_HOST')` 仍是 `.env` 里的
 * `mysql`，不是 `$_ENV` 里的 `127.0.0.1`。
 * 全量 `php think test` 时 Feature 套件先跑过、App 已绑好 `'env'`，本类整段早退。
 *
 * 约束（与 tests/Legacy 下其它守卫一致）：只读本地文件，不写文件、不连库、
 * 不调外部命令、不访问网络。
 */
final class ThinkHelperBootstrap
{
    /**
     * 框架 helper 相对仓库根的路径
     *
     * 全仓库只有这里知道这个相对位置：原先 9 个测试文件各自按 `__DIR__` 往上数
     * （两级 / 三级 / 四级各有），数错只是 require 落空、不会有任何报错提示。
     */
    private const HELPER_RELATIVE_PATH = 'vendor/topthink/framework/src/helper.php';

    /**
     * 纯静态工具类：禁止实例化
     */
    private function __construct()
    {
    }

    /**
     * 仓库根目录的绝对路径（不带结尾斜杠）
     *
     * 本文件在 `<仓库根>/tests/Support/`，上两级即仓库根，与 phpunit.xml 里
     * `<directory>tests/...</directory>` 的解析基准一致。
     */
    public static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * 框架 helper 的绝对路径
     *
     * 暴露出来是为了让守卫类在执行 `require` 之前能用自己的 `assertFileExists()` 给出
     * 可读的失败信息（断言失败 F 比未捕获异常 E 更贴近原有用例语义）；
     * 加载逻辑本身仍在 ensureHelperFunctionsLoaded() 里，路径仍只在这里解析。
     */
    public static function helperFile(): string
    {
        return self::repoRoot() . '/' . self::HELPER_RELATIVE_PATH;
    }

    /**
     * 确保框架 helper（env() / app() / config() / json() 等）已在当前进程里定义
     *
     * 只在 `env()` 尚不存在时 `require_once`：
     *   - 应用启动过（helper 由 `App::initialize()` 里的 `include_once` 加载）、
     *     或别的测试文件已经在文件顶层加载过时，本方法是 no-op；
     *   - helper 文件内部每个函数都自带 `function_exists()` 守卫，且 `require_once`
     *     本身按真实路径去重，因此不存在「cannot redeclare」的风险。
     *
     * @throws RuntimeException helper 文件缺失（通常是没装 composer 依赖）时抛出，
     *                          消息里给出当前期望的绝对路径与修复命令
     */
    public static function ensureHelperFunctionsLoaded(): void
    {
        if (function_exists('env')) {
            return;
        }

        $helper = self::helperFile();
        if (!is_file($helper)) {
            throw new RuntimeException(sprintf(
                '找不到 ThinkPHP 框架 helper 文件：%s' . PHP_EOL
                . '它由 composer 依赖 topthink/framework 提供，请先在 alkaid-backend 容器内执行 '
                . 'composer install。不要在测试里再用 __DIR__ 相对路径去猜它的位置 —— '
                . '这正是本类要收敛掉的重复（路径数错时 require_once 会静默落空）。',
                $helper
            ));
        }

        require_once $helper;
    }

    /**
     * 确保容器的 `'env'` 标识可用：未绑定则绑一个裸 `think\Env`，已绑定则**不动**
     *
     * 已绑定时不动，是因为那说明应用已经启动过，容器里是 App 自己的 `think\Env`
     * （读过 `.env`），比本方法能造的裸实例更可信；覆盖它会把「测试进程已按 .env 隔离好」
     * 这件事打回默认值。
     *
     * 注意：本方法**不**负责加载 helper（那是 ensureHelperFunctionsLoaded() 的职责）。
     * 两个判定必须分开 —— 详见类注释 ①② 一段。
     */
    public static function ensureEnvBinding(): void
    {
        $container = Container::getInstance();
        if ($container->bound('env')) {
            return;
        }

        $container->instance('env', new Env());
    }

    /**
     * 在「helper 已加载 + 容器已绑定 env」的前提下执行一段代码，并返回其结果
     *
     * 供静态守卫使用：`require config/xxx.php` 这类动作既需要 `env()` 这个函数存在（①），
     * 又需要它**真的能跑**（容器里得有 `'env'` 绑定，②）；两者缺一都会抛异常。
     * 拿到的是**生产默认值**语义，不是 .env 里的现值，见类注释。
     *
     * @template T
     * @param callable(): T $fn 需要 env() 可用的动作
     * @return T
     */
    public static function withDefaultEnv(callable $fn): mixed
    {
        self::ensureHelperFunctionsLoaded();
        self::ensureEnvBinding();

        return $fn();
    }
}
