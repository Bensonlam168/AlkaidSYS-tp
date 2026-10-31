<?php

declare (strict_types=1);

namespace app;

use think\Service;
use Infrastructure\Plugin\PluginManager;

/**
 * 应用服务类
 */
class AppService extends Service
{
    /**
     * R37：`.env` 解析结论缓存（null 表示本次运行尚未检查）
     *
     * 同一进程（一次请求 / 一条命令）只解析一次 .env，避免 boot() 被多次触发时重复读盘。
     * 解析结论在同一进程内不会变化，缓存不会掩盖「运行中有人改了 .env」这类场景 ——
     * 那种场景下 ThinkPHP 自己也只在启动时读一次 .env。
     *
     * @var array{path: string, exists: bool, parsed: bool, line: int|null, raw: string|null, error: string|null}|null
     */
    protected static ?array $envFileReport = null;

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

        // 同一进程只检查一次（结论不会变）
        if (self::$envFileReport === null) {
            self::$envFileReport = self::inspectEnvFile($this->app->getRootPath() . '.env');
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
        // 这里注册一个只负责改退出码的 shutdown 回调：异常照旧抛给框架渲染，
        // 消息/堆栈的可读性不变，只是进程最终以 1 结束。
        if ($this->app->runningInConsole()) {
            register_shutdown_function(static function (): void {
                exit(1);
            });
        }

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
