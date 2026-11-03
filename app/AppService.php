<?php

declare (strict_types=1);

namespace app;

use Infrastructure\Plugin\PluginManager;
use think\App;
use think\Container;
use think\Service;

/**
 * 应用服务类
 */
class AppService extends Service
{
    /**
     * R51：启动期（boot）失败标记（**进程级、粘性**）
     *
     * 为什么需要它：`php think <命令>` 在 boot() 阶段抛异常时，框架的全局异常处理器
     * （`think\initializer\Error::appException()`，经 `set_exception_handler()` 注册）
     * 只负责渲染异常、**不设置退出码**，于是进程以 0 结束 —— 部署脚本里的
     * `php think xxx || exit 1` 会把「配置根本起不来」误判成成功。
     * （实测：Redis 不可达时 `php think env:check` 打印 RuntimeException 后仍 `exit 0`。）
     *
     * 语义：**本进程任何 boot 期守卫抛过异常就置 true**，由
     * {@see self::registerBootFailureExitHook()} 注册的唯一 shutdown 钩子在收尾时把退出码改成 1。
     *
     * 为什么刻意做成「粘性」（本进程内不清零）：
     *   - 写入点只有 boot 期守卫（{@see self::markBootFailed()}），请求处理阶段没有任何写入口；
     *   - 一个 boot 已经失败的进程不可能「恢复正常服务」，唯一正确处理是整进程重启；
     *   - 粘性保证「先失败、异常随后被某处吞掉」也不会以 0 退出，否则又退回本缺陷。
     *
     * @var bool
     */
    protected static bool $bootFailed = false;

    /**
     * R51：「boot 失败 ⇒ 控制台退出码非 0」的 shutdown 钩子是否已注册（幂等标记）
     *
     * 只允许注册一次：同一进程注册多个同类回调会让每个都执行 `exit(1)`，
     * 既无意义，也会让「到底谁改了退出码」变得难以追踪。
     *
     * @var bool
     */
    protected static bool $bootFailureExitHookRegistered = false;

    /**
     * R37：`.env` 解析结论缓存（null 表示本进程尚未检查过）
     *
     * 只在「文件签名未变」时复用；签名变了就重新检查 —— 见 $envFileSignature。
     *
     * @var array{path: string, exists: bool, parsed: bool, line: int|null, raw: string|null, error: string|null}|null
     */
    protected static ?array $envFileReport = null;

    /**
     * R42：`.env` 的文件签名（形如 `/path/.env|1761900000|1234`，即 path|mtime|size）
     *
     * 为什么需要签名（自愈）：
     * R37 最初的缓存注释写的是「同一进程只检查一次（结论不会变）」，这句话是**错的** ——
     * `.env` 是可以被修好的。长驻进程（Swoole worker、常驻 CLI daemon）一旦缓存了「坏」结论，
     * 运维按异常提示把 `.env` 修好之后，进程仍会继续抛同样的异常，直到重启。
     * 那是一个真实的运维死锁：错误信息让人去修 `.env`，修完了却必须重启才生效。
     * 加上签名后：文件没变 → 复用上次结论（代价只有 stat，零 parse_ini_file）；
     * 文件变了 → 重新检查并覆盖缓存，于是「修好 .env 即恢复」，不必重启。
     *
     * 为什么签名必须包含 path：静态缓存跨实例共享，测试 / 多环境共用同一进程时，
     * A 文件的结论绝不能被当成 B 文件的结论复用。
     *
     * @var string|null
     */
    protected static ?string $envFileSignature = null;

    public function register()
    {
        // 服务注册
    }

    public function boot()
    {
        // R37 运行期守卫：`.env`「存在但解析失败」时直接抛异常。
        // 背景：think\Env::load() 的实现是 `parse_ini_file($file, true, INI_SCANNER_RAW) ?: []`，
        // 语法错误时拿到的是空数组、所有键静默失效（vendor 代码，不可改）。
        // 症状：容器正常起来了，但连不上库 / 配置像完全没生效，极难定位。
        // 边界：`.env` 不存在时正常放行 —— 容器可以只用环境变量注入配置。
        $this->guardEnvFile();

        // 插件系统基础：启动时加载已配置/启用的插件（T-035）
        // 注意：本阶段不做目录扫描与下载，仅支持 config/plugin.php 声明的插件清单
        $pluginManager = new PluginManager($this->app);
        $pluginManager->loadFromConfig();
        $this->app->instance(PluginManager::class, $pluginManager);

        // 应用系统：注册并加载延迟服务提供者（A5）
        // 说明：ApplicationServiceProvider 继承项目自有的 Infrastructure\DI\AbstractServiceProvider，
        // 并非 think\Service 子类，不能写入 app/service.php，只能在容器就绪后手动接线。
        // provider 接线失败不得影响主流程（避免全站 500），故整体兜底为告警日志。
        try {
            $providerManager = new \Infrastructure\DI\ServiceProviderManager($this->app);
            // 延迟提供者：register() 仅登记服务映射，不立即执行其 register()
            $providerManager->register(\Infrastructure\Application\ApplicationServiceProvider::class);

            // 按服务名触发延迟加载；返回 false 表示该服务未登记，记录告警但不抛异常
            if (!$providerManager->loadDeferred(\Infrastructure\Application\ApplicationManager::class)) {
                \think\facade\Log::warning('应用服务提供者延迟加载未命中', [
                    'provider' => \Infrastructure\Application\ApplicationServiceProvider::class,
                    'service'  => \Infrastructure\Application\ApplicationManager::class,
                ]);
            }

            $this->app->instance(\Infrastructure\DI\ServiceProviderManager::class, $providerManager);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('应用服务提供者注册失败，已跳过（不影响主流程）', [
                'provider' => \Infrastructure\Application\ApplicationServiceProvider::class,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * R51：标记「本进程的启动期检查已失败」，并保证控制台进程以非 0 退出
     *
     * **约定（新增 boot 期守卫时必须遵守）**：任何在 `boot()` 里抛异常的守卫，
     * 都要在 `throw` **之前**调用本方法，否则 `php think <命令>` 仍会以 0 退出，
     * 部署脚本/CI 看到的是「成功」。示例：
     *
     * ```php
     * if ($misconfigured) {
     *     \app\AppService::markBootFailed();   // 必须先标记，再抛
     *     throw new \RuntimeException('...');
     * }
     * ```
     *
     * 本方法自身幂等：重复调用只是重复置位，shutdown 钩子只会注册一次。
     *
     * @return void
     */
    public static function markBootFailed(): void
    {
        self::$bootFailed = true;

        self::registerBootFailureExitHook();
    }

    /**
     * R51：本进程的启动期检查是否已失败（诊断 / 测试可读）
     *
     * @return bool
     */
    public static function hasBootFailed(): bool
    {
        return self::$bootFailed;
    }

    /**
     * R51：注册「boot 失败 ⇒ exit(1)」的 shutdown 钩子（进程内幂等、只对控制台生效）
     *
     * 为什么用 shutdown 钩子而不是直接 `exit(1)`：异常必须照旧抛给框架渲染，
     * 消息/堆栈可读性不能变。这里只在**进程收尾**时改退出码。
     *
     * 为什么只挂控制台：FPM / 常驻 HTTP worker 的退出码由进程管理器决定，
     * 在那里 `exit(1)` 只会制造「进程随机退出」的噪音，不会让任何部署脚本受益。
     *
     * 关于 Swoole：worker 进程的 `PHP_SAPI` 同样是 `cli`，`runningInConsole()` 会返回 true；
     * 但 Swoole 的应用 boot 发生在 **fork 之前的主进程**
     * （`think\swoole\Manager::prepareApplication()` → `$app->initialize()`），
     * 也就是说「boot 失败」只可能出现在那份主进程里 —— 它本来就该非 0 退出；
     * worker 是 fork 之后才存在的，继承到的 {@see self::$bootFailed} 恒为 false，
     * 因此该钩子在 worker 里不会改变任何退出码（详见本缺陷的执行报告「风险」一节）。
     *
     * @return void
     */
    protected static function registerBootFailureExitHook(): void
    {
        // 幂等：成功注册过就不再重复注册
        if (self::$bootFailureExitHookRegistered) {
            return;
        }

        // 非控制台不注册（也**不**占用幂等标记：否则同一进程里先出现「非控制台失败」
        // 会让后续真正的控制台失败失去退出码修正）
        if (!self::isConsoleProcess()) {
            return;
        }

        self::$bootFailureExitHookRegistered = true;

        register_shutdown_function(static function (): void {
            // 成功路径：标记为 false，什么都不做 —— 正常命令/worker 的退出码保持原样
            if (self::$bootFailed) {
                exit(1);
            }
        });
    }

    /**
     * R51：当前进程是不是「控制台」进程
     *
     * 优先问容器里的 `think\App`：`runningInConsole()` 是本项目判定「命令行 / HTTP」的唯一口径，
     * 而且测试可以用替身 App 把结果钉死（见 tests/Unit/AppServiceBootFailureExitCodeTest.php）。
     * 容器尚未就绪时（极端早鸟场景）退回 `PHP_SAPI` 判断，探测失败绝不影响正常流程。
     *
     * @return bool
     */
    protected static function isConsoleProcess(): bool
    {
        try {
            $container = Container::getInstance();

            if ($container->has('app')) {
                $app = $container->make('app');

                if ($app instanceof App) {
                    return $app->runningInConsole();
                }
            }
        } catch (\Throwable) {
            // 容器不可用 → 落到下面的 SAPI 兜底
        }

        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    /**
     * R37：`.env` 运行期守卫（只在「文件存在但解析失败」时抛错）
     *
     * 刻意**不做** try/catch 兜底：这条检查的目的就是响亮失败。
     * 若这里静默降级成一条日志，就等于把「配置全部失效」这种致命故障降级成
     * 「一堆看不懂的运行时错误」（连不上库、鉴权全 401、多租户过滤失效……）。
     *
     * @return void
     * @throws \RuntimeException .env 存在但无法解析
     */
    protected function guardEnvFile(): void
    {
        // 唯一的例外：`env:check` 诊断命令本身。
        // 原因：boot() 早于命令执行，若守卫无差别抛异常，「用来诊断 .env 的命令」就会被
        // 「.env 坏了就抛异常」的守卫挡在门外 —— 诊断报告永远看不到，只能看到守卫消息。
        // 这不是放行：env:check 自己会检查同一件事并以退出码 1 失败（见 EnvCheckCommand::execute()），
        // 「响亮失败」的性质不变，只是把「谁来喊」从守卫换成了诊断命令。
        if ($this->isDiagnosingEnvFile()) {
            return;
        }

        // R42：签名感知缓存 —— 先算签名，签名未变就**直接复用**上次结论（不做任何 parse_ini_file），
        // 签名变了才重新检查。这样既保住「同一进程不重复读盘」，又让修好 .env 后无需重启即可自愈。
        $path      = $this->app->getRootPath() . '.env';
        $signature = static::buildEnvFileSignature($path);

        if (self::$envFileReport === null || self::$envFileSignature !== $signature) {
            // 注意顺序：签名必须在 inspectEnvFile() **之前**取。
            // 若反过来（先解析、后取签名），一旦文件在两步之间被修好，就会得到
            // 「解析到旧内容（坏） + 记下新签名（好）」的组合，之后所有调用都因为签名一致
            // 而复用那份「坏」结论 —— 正好是 R42 要消灭的死锁。
            // 现在的顺序最坏只是多解析一次（stat 与 parse 之间文件被改），永远不会把错误结论钉死。
            self::$envFileReport    = $this->inspectEnvFileForGuard($path);
            self::$envFileSignature = $signature;
        }

        $report = self::$envFileReport;

        // 文件不存在 → 放行：容器可以只用环境变量注入配置，此时 .env 本就不该存在
        if (!$report['exists'] || $report['parsed']) {
            return;
        }

        // 命令行下必须额外保证「退出码非 0」。
        // 实测（PHP 8.2 + ThinkPHP 8.0）：boot() 在 think\initializer\BootService 阶段执行，
        // 此时 think\initializer\Error 已注册全局异常处理器；它只渲染异常、不设置退出码，
        // 于是 `php think <任意命令>` 会打印上面的异常后仍以 0 退出 ——
        // 部署脚本与 CI 会把「配置全废」误判成成功（正是 R37 要消灭的那种静默）。
        //
        // R51：这里原先自带一份 register_shutdown_function(exit(1))，现改为走**唯一**的通用机制
        // （markBootFailed → AppService 里那个只注册一次的钩子），避免每个守卫各抄一份、
        // 抄漏一个就退回「boot 失败仍 exit 0」。异常照旧抛给框架渲染，可读性不变。
        self::markBootFailed();

        self::throwEnvFileParseError($report);
    }

    /**
     * R37：当前进程是否正在执行 `env:check` 诊断命令
     *
     * 只能用控制台 argv 判断：boot() 阶段还没有「已解析的命令对象」，
     * 而 `think\Console::getCommandName()` 是 protected 且需要 Input 实例。
     * `env:check` 没有别名（见 EnvCheckCommand::configure()），因此按命令名精确匹配即可；
     * 极端情况下匹配失败（例如用命名空间缩写 `php think e:c` 调用）只会退回守卫抛错，
     * 不会放过真正坏掉的配置。
     *
     * @return bool
     */
    protected function isDiagnosingEnvFile(): bool
    {
        if (!$this->app->runningInConsole()) {
            return false;
        }

        $argv = $_SERVER['argv'] ?? null;
        if (!is_array($argv)) {
            return false;
        }

        return in_array('env:check', $argv, true);
    }

    /**
     * R37：`.env` 存在但解析失败时抛出可定位的异常
     *
     * 单独抽成静态方法，便于 `env:check` 与测试复用同一份错误文案（避免两处漂移）。
     *
     * @param array{path: string, exists: bool, parsed: bool, line: int|null, raw: string|null, error: string|null} $report
     * @throws \RuntimeException 恒抛
     */
    public static function throwEnvFileParseError(array $report): never
    {
        $lineText = $report['line'] === null ? '未知（未能从错误消息中解析出行号）' : (string) $report['line'];
        $rawText  = $report['raw'] ?? '未知';

        throw new \RuntimeException(
            ".env 文件存在但无法解析，所有配置项都会失效：{$report['path']}\n"
            . "出错行号：{$lineText}\n"
            . "出错行原文：{$rawText}\n"
            . '解析错误：' . ($report['error'] ?? '未知') . "\n"
            . "后果：think\\Env::load() 会静默兜底为空数组（?: []），.env 中所有键都会失效\n"
            . '修复：请在 .env 注释里使用全角括号，见 AGENTS.md Configuration & Security'
        );
    }

    /**
     * R42：计算 `.env` 的文件签名（`path|mtime|size`）
     *
     * 为什么第一件事必须是 clearstatcache(true, $path)：
     * PHP 会把 filemtime() / filesize() / is_file() 的结果缓存在 stat 缓存里，而这份缓存
     * **不会**因为别的进程（运维编辑器、docker exec、配置管理工具）改了文件而失效。
     * 长驻进程里后果很严重：磁盘上的 `.env` 已经修好，我们这边 filemtime() 还是旧值、
     * 签名永远不变，于是「签名未变 → 复用坏结论」会一直成立，自愈彻底失效。
     * 实测（PHP 8.2，容器内由外部进程改写文件）：
     *   不调 clearstatcache → filemtime()/filesize() 仍返回改写前的旧值；
     *   调 clearstatcache(true, $path) 后 → 返回新值。
     * 只清这一个文件（第二个参数），代价是两次 stat，可以忽略。
     *
     * @param string $path `.env` 绝对路径
     * @return string 形状固定的签名字符串
     */
    protected static function buildEnvFileSignature(string $path): string
    {
        clearstatcache(true, $path);

        // 取不到（文件不存在 / 权限不足）时统一用 -1 哨兵：
        // 让「不存在」也成为一种**稳定**签名，否则每次都拿到 false，会退化成每次调用都重新检查。
        $mtime = @filemtime($path);
        $size  = @filesize($path);

        return $path
            . '|' . ($mtime === false ? '-1' : (string) $mtime)
            . '|' . ($size === false ? '-1' : (string) $size);
    }

    /**
     * R42：守卫专用的解析入口（测试接缝）
     *
     * 单开一个 protected 方法而不是直接调 self::inspectEnvFile()，是为了让测试能用子类
     * 统计「到底解析了几次」—— 「零解析」是 R42 的验收点，不能拿「耗时变短」当证据
     * （容器 bind mount 的 IO 抖动足以淹没这点差异）。
     *
     * @param string $path `.env` 绝对路径
     * @return array{path: string, exists: bool, parsed: bool, line: int|null, raw: string|null, error: string|null}
     */
    protected function inspectEnvFileForGuard(string $path): array
    {
        return self::inspectEnvFile($path);
    }

    /**
     * R37：检查指定 ini 文件能否被 parse_ini_file 解析
     *
     * 与 `think\Env::load()` 用完全相同的调用方式（`parse_ini_file($path, true, INI_SCANNER_RAW)`），
     * 区别只在于：解析失败时不再 `?: []` 静默兜底，而是把行号与出错行原文带回来。
     *
     * 为什么必须自己装 error handler：parse_ini_file 解析失败时只发一条 Warning 并返回 false，
     * 行号只存在于这条 Warning 的文本里（形如 `syntax error, unexpected '(' in /path/.env on line 2`），
     * 不接管 handler 就拿不到它。
     *
     * @param string $path ini 文件绝对路径
     * @return array{path: string, exists: bool, parsed: bool, line: int|null, raw: string|null, error: string|null}
     *         exists=false 表示文件不存在（调用方据此放行）；parsed=true 表示解析成功或无需检查
     */
    public static function inspectEnvFile(string $path): array
    {
        $report = [
            'path'   => $path,
            'exists' => false,
            // 文件不存在时视为「无需检查」：调用方必须先看 exists，再看 parsed
            'parsed' => true,
            'line'   => null,
            'raw'    => null,
            'error'  => null,
        ];

        if (!is_file($path)) {
            return $report;
        }

        $report['exists'] = true;
        $report['parsed'] = false;

        // 用局部变量接收 Warning 文本；handler 返回 true 表示「已处理」，不再走默认处理（不会打日志/抛异常）
        $errorMessage = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$errorMessage): bool {
            $errorMessage = $errstr;
            return true;
        });

        try {
            $parsed = parse_ini_file($path, true, INI_SCANNER_RAW);
        } finally {
            // 无论成功失败都必须还原：ThinkPHP 的 Error 初始化器已注册了自己的 handler，
            // 不还原会让后续所有 PHP 错误绕过框架处理（测试里还会被判 Risky）。
            restore_error_handler();
        }

        // 只有 false 代表语法错误；空文件返回空数组 []，属于「解析成功但没有键」
        if ($parsed !== false) {
            $report['parsed'] = true;
            return $report;
        }

        $report['error'] = $errorMessage ?? 'parse_ini_file() 返回 false（未捕获到具体错误消息）';

        if ($errorMessage !== null && preg_match('/on line (\d+)/i', $errorMessage, $matches) === 1) {
            $line = (int) $matches[1];
            $report['line'] = $line;
            $report['raw']  = self::readFileLine($path, $line);
        }

        return $report;
    }

    /**
     * R37：读取文件第 N 行的原文（用于把「第几行出错」变成「哪一行出错」）
     *
     * @param string $path   文件路径
     * @param int    $lineNo 1 起始的行号
     * @return string|null 越界或读不到时返回 null
     */
    private static function readFileLine(string $path, int $lineNo): ?string
    {
        if ($lineNo < 1) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $current = 0;
            while (($line = fgets($handle)) !== false) {
                $current++;
                if ($current === $lineNo) {
                    // 去掉行尾换行，避免异常消息里出现多余空行
                    return rtrim($line, "\r\n");
                }
            }
            return null;
        } finally {
            fclose($handle);
        }
    }
}
