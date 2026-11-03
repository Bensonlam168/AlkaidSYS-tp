<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\AppService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;

/**
 * R51：boot 期失败 ⇒ `php think <命令>` 退出码非 0
 *
 * ## 钉住的是什么
 *
 * 框架的全局异常处理器（`think\initializer\Error::appException()`，经 `set_exception_handler()`
 * 注册）只渲染异常、**不设置退出码**。所以 boot() 阶段抛异常的守卫如果只抛不标记，
 * 进程会以 **0** 结束 —— `php think env:check || exit 1` 在「生产配置根本起不来」时
 * 看到的是成功。实测（修复前）：
 *
 *   - Redis 不可达 → `php think env:check` 打印 RuntimeException，`echo $?` = 0；
 *   - Cache / Session Guard 抛异常 → 探针进程 `echo $?` = 0（`GUARD_PASSED` 也没打印）。
 *
 * 修复后三者都必须以 **1** 退出，而「正常成功」的路径必须仍是 **0**（这是同一份用例里的对照，
 * 因为最容易被写坏的做法就是「全局捕获后无条件 exit(1)」）。
 *
 * ## 为什么全部走子进程
 *
 * 「进程退出码」在进程内观察不到。更要紧的是：在本进程（PHPUnit）里触发一次真实标记，
 * 会**让整个测试进程在收尾时 exit 1** —— 看起来像「测试失败」，其实钩子按设计工作。
 * 所以：
 *   - 需要真实退出码的用例 → 一律 `proc_open` 起子进程（{@see self::runPhp()}）；
 *   - 进程内只验证「非控制台不受影响」这一条（用替身 App 把 `runningInConsole()` 钉成 false，
 *     钩子根本不会注册，因此不可能改变 PHPUnit 自己的退出码）。
 *
 * ## 为什么不碰仓库根的 `.env`
 *
 * 子进程统一用 `PHP_ENV_NAME` 指向**临时**的 `.env.<name>`（`.gitignore` 的 `.env.*` 已覆盖，
 * 用例结束即删）。`PHP_ENV_NAME` 对框架是「替换」语义：子进程只读那一份，不读 `.env`。
 * 因此本文件既不改 `.env`（其 sha256 是被验收项），也不会因为 `.env` 的内容而漂移。
 *
 * @package Tests\Unit
 */
class AppServiceBootFailureExitCodeTest extends TestCase
{
    /**
     * 项目根目录（子进程的 cwd，也是 `think` 入口所在处）
     */
    private string $projectRoot;

    /**
     * 临时探针脚本目录
     */
    private string $probeDir;

    /**
     * 本次用例创建过的临时环境文件（tearDown 统一删除）
     *
     * @var array<int, string>
     */
    private array $envFiles = [];

    /**
     * 用例开始前的全局容器实例（tearDown 原样还原）
     */
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot      = dirname(__DIR__, 2);
        $this->previousContainer = Container::getInstance();

        $this->probeDir = sys_get_temp_dir()
            . '/appservice-bootfail-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->probeDir, 0777, true);

        // 静态标记是**进程级**的：任何残留都会影响后续用例与 PHPUnit 自己的退出码
        $this->resetBootFailureState();
    }

    protected function tearDown(): void
    {
        $this->resetBootFailureState();

        foreach ($this->envFiles as $file) {
            @unlink($file);
        }
        $this->envFiles = [];

        foreach ((array) glob($this->probeDir . '/*.php') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->probeDir);

        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    /**
     * 核心证据 1：Redis 不可达 → `php think env:check` 必须以 1 退出
     *
     * 这就是缺陷报告里那条实测命令的等价复现（只把 Redis 指到不可达端口，其余配置照抄 `.env`）。
     * 修复前该命令**打印异常后 exit 0**，正是「部署脚本误判成功」的根源。
     */
    public function testRedisUnreachableMakesThinkCommandExitWithOne(): void
    {
        $profile = $this->createEnvProfile('badredis', [
            'APP_ENV'    => 'production',   // 生产类环境：cache 被强制为 redis，于是启动期会做 Redis 自检
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => '6399',         // 容器内必然不可达
        ]);

        $result = $this->runPhp(['think', 'env:check'], ['PHP_ENV_NAME' => $profile]);

        $this->assertSame(
            1,
            $result['exitCode'],
            "Redis 不可达时 php think env:check 必须非 0 退出（0 就是本缺陷），实际输出：\n{$result['output']}"
        );
        $this->assertStringContainsString(
            'Redis health check failed',
            $result['output'],
            "异常消息必须照旧渲染出来（只是额外修正退出码），实际输出：\n{$result['output']}"
        );
        $this->assertStringNotContainsString(
            '环境检查通过',
            $result['output'],
            '启动期就失败了，命令体不该执行到「通过」那一步'
        );
    }

    /**
     * 核心证据 2a：Cache Guard 抛异常 → 必须以 1 退出
     *
     * 失败条件无法用环境变量构造（`config/cache.php` 在生产类环境把 default 强制成 redis），
     * 所以用探针在「App 正常 boot 之后」把 config 改坏，再驱动守卫自己的 boot() ——
     * 观察的仍然是**真实的进程退出码**，而不是进程内的标志位。
     */
    public function testCacheGuardFailureMakesProcessExitWithOne(): void
    {
        $script = $this->writeProbe('cache_guard', <<<'PHP'
<?php
// 探针：让 CacheEnvironmentGuardService 在 boot 期抛异常，观察进程退出码
require __AUTOLOAD__;

$app = new \think\App();
$app->initialize();

$app->env->set('APP_ENV', 'production');
$app->config->set(['default' => 'file'], 'cache');

(new \app\provider\CacheEnvironmentGuardService($app))->boot();
echo "GUARD_PASSED\n";
PHP);

        $result = $this->runPhp([$script]);

        $this->assertSame(
            1,
            $result['exitCode'],
            "Cache Guard 抛异常时进程必须非 0 退出，实际输出：\n{$result['output']}"
        );
        $this->assertStringContainsString('Cache driver misconfigured', $result['output']);
        $this->assertStringNotContainsString('GUARD_PASSED', $result['output'], '守卫不得放行');
    }

    /**
     * 核心证据 2b：Session Guard 抛异常 → 必须以 1 退出
     */
    public function testSessionGuardFailureMakesProcessExitWithOne(): void
    {
        $script = $this->writeProbe('session_guard', <<<'PHP'
<?php
// 探针：让 SessionEnvironmentGuardService 在 boot 期抛异常，观察进程退出码
require __AUTOLOAD__;

$app = new \think\App();
$app->initialize();

$app->env->set('APP_ENV', 'production');
$app->config->set(['type' => 'file', 'store' => 'file'], 'session');

(new \app\provider\SessionEnvironmentGuardService($app))->boot();
echo "GUARD_PASSED\n";
PHP);

        $result = $this->runPhp([$script]);

        $this->assertSame(
            1,
            $result['exitCode'],
            "Session Guard 抛异常时进程必须非 0 退出，实际输出：\n{$result['output']}"
        );
        $this->assertStringContainsString('Session configuration misconfigured', $result['output']);
        $this->assertStringNotContainsString('GUARD_PASSED', $result['output'], '守卫不得放行');
    }

    /**
     * 核心证据（对照）：**正常成功**的命令退出码必须仍是 0
     *
     * 这条比上面三条更重要：最容易写坏的做法是「注册一个无条件 exit(1) 的钩子」，
     * 那会把所有命令都变成失败。用非生产环境（不需要生产类强约束）+ file 缓存跑一遍完整自检。
     */
    public function testSuccessfulCommandStillExitsWithZero(): void
    {
        $profile = $this->createEnvProfile('ok', [
            'APP_ENV'      => 'dev',
            'CACHE_DRIVER' => 'file',
        ]);

        $result = $this->runPhp(['think', 'env:check'], ['PHP_ENV_NAME' => $profile]);

        $this->assertSame(
            0,
            $result['exitCode'],
            "正常成功的 env:check 必须仍以 0 退出（把一切变成 1 同样是缺陷），实际输出：\n{$result['output']}"
        );
        $this->assertStringContainsString('环境检查通过', $result['output']);
    }

    /**
     * 核心证据（对照）：不触发 Guard 的命令（`php think list`）退出码仍是 0
     */
    public function testThinkListStillExitsWithZero(): void
    {
        $profile = $this->createEnvProfile('list', [
            'APP_ENV'      => 'dev',
            'CACHE_DRIVER' => 'file',
        ]);

        $result = $this->runPhp(['think', 'list'], ['PHP_ENV_NAME' => $profile]);

        $this->assertSame(
            0,
            $result['exitCode'],
            "php think list 必须仍以 0 退出，实际输出：\n{$result['output']}"
        );
        $this->assertStringContainsString('Usage:', $result['output'], 'list 命令应正常输出用法说明');
    }

    /**
     * 不标记失败时，探针进程必须仍以 0 退出
     *
     * 与 {@see self::testIdempotentMarksKeepSingleExitCode()} 成对：一个证「标记后 = 1」，
     * 一个证「不标记 = 0」，从而证明钩子不是无条件的。
     */
    public function testProcessWithoutBootFailureStillExitsWithZero(): void
    {
        $script = $this->writeProbe('control_ok', <<<'PHP'
<?php
// 对照探针：不标记失败 → 进程必须以 0 退出
require __AUTOLOAD__;

$app = new \think\App();
$app->initialize();

echo 'BOOT_FAILED=' . (\app\AppService::hasBootFailed() ? '1' : '0') . "\n";
PHP);

        $result = $this->runPhp([$script]);

        $this->assertSame(
            0,
            $result['exitCode'],
            "没有 boot 失败的进程必须仍以 0 退出，实际输出：\n{$result['output']}"
        );
        $this->assertStringContainsString('BOOT_FAILED=0', $result['output']);
    }

    /**
     * 幂等：同一进程标记多次只注册一个钩子，且不会出现「二次 exit」的致命错误
     *
     * 若每个守卫各注册一个 `exit(1)` 回调，或者每次 mark 都注册一个，收尾时会有多个 shutdown
     * 回调接连执行 `exit()`。这条用例连标 5 次，要求：退出码仍是 1、输出里没有 Fatal error。
     */
    public function testIdempotentMarksKeepSingleExitCode(): void
    {
        $script = $this->writeProbe('idempotent', <<<'PHP'
<?php
// 幂等探针：重复标记 → 仍然只有一个钩子、仍然以 1 退出、不产生二次 exit 的异常
require __AUTOLOAD__;

$app = new \think\App();
$app->initialize();

for ($i = 0; $i < 5; $i++) {
    \app\AppService::markBootFailed();
}

echo 'MARKED=' . (\app\AppService::hasBootFailed() ? '1' : '0') . "\n";
PHP);

        $result = $this->runPhp([$script]);

        $this->assertSame(
            1,
            $result['exitCode'],
            "标记过 boot 失败的进程必须以 1 退出（重复标记不改变结论），实际输出：\n{$result['output']}"
        );
        $this->assertStringContainsString('MARKED=1', $result['output']);
        $this->assertStringNotContainsString('Fatal error', $result['output'], '重复注册不得产生致命错误');
        $this->assertStringNotContainsString('Cannot', $result['output'], '重复注册不得产生 PHP 级错误');
    }

    /**
     * 非控制台进程不受影响：钩子根本不注册（Swoole worker / FPM 的退出码不属于我们管）
     *
     * 用替身 App 把 `runningInConsole()` 钉成 false —— 这条只能在进程内验证，
     * 因为「钩子没注册」本身没有可观察的退出码（而且真去注册就会污染 PHPUnit 的退出码）。
     */
    public function testNonConsoleProcessDoesNotRegisterExitHook(): void
    {
        // 构造替身会顺带把全局容器指向它（App::__construct 里 Container::setInstance）
        new BootFailureNonConsoleApp($this->projectRoot);

        AppService::markBootFailed();

        $this->assertTrue(AppService::hasBootFailed(), '失败事实与 SAPI 无关，标记仍要置位（供诊断/日志使用）');
        $this->assertFalse(
            $this->isExitHookRegistered(),
            '非控制台进程不得注册 exit(1) 钩子：HTTP worker 的退出码不由 boot 结果决定'
        );
    }

    /**
     * 生成一份临时环境文件（`.env.<profile>`），返回 profile 名
     *
     * 内容以仓库根的 `.env` 为底稿（保证是「只改了一处」的对照），
     * 先剔除要覆盖的键再追加，避免依赖 `parse_ini_file` 对重复键的取舍规则。
     * 注意：`.env` 含本机口令，本文件只复制、不打印；文件由 tearDown 删除，且被 `.gitignore` 的 `.env.*` 覆盖。
     *
     * @param string                $name        profile 名（最终形如 `.env.r51_<name>_<pid>_<rand>`）
     * @param array<string, string> $overrides   要覆盖/追加的键值
     * @return string profile 名（传给子进程的 `PHP_ENV_NAME`）
     */
    private function createEnvProfile(string $name, array $overrides): string
    {
        $base = (string) file_get_contents($this->projectRoot . '/.env');

        // 剔除被覆盖的键（含注释前缀的行不动，避免破坏 .env 里的说明）
        $keys = implode('|', array_map(static fn (string $k): string => preg_quote($k, '/'), array_keys($overrides)));
        if ('' !== $keys) {
            $base = (string) preg_replace('/^[ \t]*(?:' . $keys . ')[ \t]*=.*\R/mi', '', $base);
        }

        $lines = [];
        foreach ($overrides as $key => $value) {
            $lines[] = $key . ' = ' . $value;
        }

        $profile = 'r51_' . $name . '_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $path    = $this->projectRoot . '/.env.' . $profile;

        if (false === file_put_contents($path, rtrim($base) . "\n" . implode("\n", $lines) . "\n")) {
            $this->fail('无法写入临时环境文件：' . $path);
        }

        $this->envFiles[] = $path;

        return $profile;
    }

    /**
     * 把探针脚本写到临时目录，返回脚本绝对路径
     *
     * @param string $name     文件名（不含扩展名）
     * @param string $template 脚本模板（`__AUTOLOAD__` 会被替换成真实的 autoload 路径）
     * @return string
     */
    private function writeProbe(string $name, string $template): string
    {
        $autoload = $this->projectRoot . '/vendor/autoload.php';
        $path     = $this->probeDir . '/' . $name . '.php';

        if (false === file_put_contents($path, str_replace('__AUTOLOAD__', var_export($autoload, true), $template))) {
            $this->fail('无法写入探针脚本：' . $path);
        }

        return $path;
    }

    /**
     * 起一个 PHP 子进程并回收退出码与合并输出
     *
     * 用 `proc_open()` + argv 数组（不经过 shell），参数里的路径无需转义。
     * 子进程 env = 当前进程 env + `$env`，并显式清掉 `PHP_ENV_NAME` / `ENV_NAME`
     * （`ENV_NAME` 会覆盖 `PHP_ENV_NAME` 的回落，留着会让 profile 失效）。
     *
     * 为什么不需要像 tests/Support/CliProcess 那样把 DB 强制指向测试库：
     * 这里跑的是 `env:check` / `list` / 只读探针，**没有任何写库路径**（探针只做 boot 与配置覆盖）。
     *
     * @param array<int, string>    $argv 形如 `['think', 'env:check']` 或 `[$scriptPath]`（PHP_BINARY 会自动加在最前）
     * @param array<string, string> $env  追加/覆盖的环境变量
     * @return array{exitCode: int, output: string}
     */
    private function runPhp(array $argv, array $env = []): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            // stderr 合并进 stdout：异常与普通输出按真实顺序一起看
            2 => ['redirect', 1],
        ];

        $pipes   = [];
        $process = proc_open(
            array_merge([PHP_BINARY], $argv),
            $descriptors,
            $pipes,
            $this->projectRoot,
            $this->childEnvironment($env)
        );

        if (!is_resource($process)) {
            $this->fail('无法启动子进程：' . implode(' ', $argv));
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return ['exitCode' => proc_close($process), 'output' => $output];
    }

    /**
     * 组装子进程环境变量表
     *
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private function childEnvironment(array $env): array
    {
        $result = [];
        foreach (getenv() as $key => $value) {
            if (is_string($value)) {
                $result[(string) $key] = $value;
            }
        }

        // 这两个键决定读哪份环境文件：必须先清空，再由调用方按需设置，
        // 否则父进程（或 shell）里的残留会让子进程读到意料之外的 profile。
        unset($result['PHP_ENV_NAME'], $result['ENV_NAME']);

        foreach ($env as $key => $value) {
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * 读取「exit(1) 钩子是否已注册」（protected 静态属性，用反射读）
     *
     * 不为此在生产代码里开 `isExitHookRegistered()`：那是只有测试关心的内部状态，
     * 反射虽啰嗦，但不会把「测试专用」变成生产代码的公开承诺（与 AppServiceEnvGuardTest 同一取舍）。
     *
     * @return bool
     */
    private function isExitHookRegistered(): bool
    {
        $property = (new \ReflectionClass(AppService::class))->getProperty('bootFailureExitHookRegistered');
        $property->setAccessible(true);

        return (bool) $property->getValue();
    }

    /**
     * 清空 `AppService` 的进程级 boot 失败状态
     *
     * `$bootFailed` 一旦置位，本进程收尾时就会被改成 exit(1) —— 不清会让**后续用例**
     * 与 PHPUnit 自己的退出码受牵连。`$bootFailureExitHookRegistered` 只在本文件里
     * 从没被真正置位过（钩子只在控制台注册），这里一并复位以免用例间互相影响。
     */
    private function resetBootFailureState(): void
    {
        $reflection = new \ReflectionClass(AppService::class);

        foreach (['bootFailed' => false, 'bootFailureExitHookRegistered' => false] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }
}

/**
 * 测试替身：把「是否控制台」钉死为 false
 *
 * 为什么要替身：真实 PHPUnit 进程是 cli，`runningInConsole()` 为 true；
 * 在进程内注册那个 `exit(1)` 钩子会让**整个测试进程**在收尾时以 1 退出 ——
 * 看起来像「测试失败」，其实是钩子按设计工作（真实退出码另用子进程用例覆盖）。
 */
class BootFailureNonConsoleApp extends App
{
    /**
     * @return bool 恒 false
     */
    public function runningInConsole(): bool
    {
        return false;
    }
}
