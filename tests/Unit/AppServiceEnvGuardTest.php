<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\AppService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;

/**
 * R42：`.env` 运行期守卫的「签名感知缓存」测试
 *
 * 要钉住三件事：
 *   1. 文件没变 → **零解析**：第二次守卫只做 stat，不再调 parse_ini_file；
 *   2. 文件变了（被修好）→ 重新检查并**自愈**，同一进程内无需重启；
 *   3. 算签名前必须 clearstatcache：否则 stat 缓存会让「别的进程已修好 .env」对长驻进程完全不可见。
 *
 * 为什么单开一个文件，而不是塞进 tests/Unit/Command/EnvCheckCommandTest.php：
 *   那个文件钉的是 `env:check` 诊断命令（命令层：行号文案 / 跳过变量检查 / 退出码）
 *   以及 `inspectEnvFile()` 的纯解析细节；
 *   本文件钉的是 `AppService::boot()` 里那条**运行期守卫**的缓存与自愈行为 —— 断言对象完全不同。
 *   另一个现实理由：缓存是 `AppService` 的**静态**状态，需要逐用例清理；
 *   把它和 443 行的命令测试混在一起，会让那边继续膨胀，还得让清理逻辑与命令用例纠缠。
 *
 * 关于真实 `.env`：本文件**完全不碰**仓库根的 `.env`。
 *   守卫取的是 `$app->getRootPath() . '.env'`，所以每个用例自建一个临时 app 根目录即可
 *   （临时 `.env` 放在其中）；命令行退出码那条用例也只在子进程里用临时根目录。
 *
 * @package Tests\Unit
 */
class AppServiceEnvGuardTest extends TestCase
{
    /**
     * 临时 app 根目录（带结尾分隔符，内含临时 .env）
     */
    private string $rootPath;

    /**
     * 临时 .env 绝对路径
     */
    private string $envPath;

    /**
     * 用例开始前的全局容器实例（tearDown 原样还原）
     *
     * `new think\App($root)` 会顺带 `Container::setInstance($this)`（vendor 构造函数里写死），
     * 若不还原，后续用例的 `Cache::` / `Log::` 门面就会去解析这个「空配置临时 App」。
     */
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();

        $this->rootPath = sys_get_temp_dir()
            . '/appservice-guard-' . getmypid() . '-' . bin2hex(random_bytes(4))
            . '/';
        mkdir($this->rootPath, 0777, true);
        $this->envPath = $this->rootPath . '.env';

        // 静态缓存是**进程级**的：上一个用例（甚至别的测试类）可能已经写过，
        // 必须先清空，否则「零解析」的计数起点就是错的。
        $this->resetGuardCache();
    }

    protected function tearDown(): void
    {
        // 同理，收尾也要清：留着临时文件的结论会让后续用例（如 ServiceProviderWiringTest 里
        // 真实的 `AppService::boot()`）复用一份与它无关的缓存。
        $this->resetGuardCache();

        @unlink($this->envPath);
        @unlink($this->rootPath . 'staged.env');
        @unlink($this->rootPath . 'probe.php');
        @rmdir($this->rootPath);

        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    /**
     * 零解析：同一进程内连续调用守卫，第二次起不触发任何 parse_ini_file
     *
     * 取证方式：用一个只重写 `inspectEnvFileForGuard()` 的替身**计数**，
     * 而不是比耗时 —— 容器 bind mount 的 IO 抖动足以淹没一次 parse_ini_file 的差异。
     */
    public function testGuardReusesCachedVerdictWithoutParsingAgain(): void
    {
        $this->writeEnv("APP_ENV = dev\nDB_HOST = mysql\n");

        $service = $this->makeCountingService();

        $service->runGuard();
        $service->runGuard();
        $service->runGuard();

        $this->assertSame(
            1,
            $service->inspectCount,
            '签名未变时必须复用上次结论：三次守卫只允许解析一次（第二、三次只做 stat）'
        );
    }

    /**
     * R42 的核心价值：同一进程内把坏 `.env` 修好，守卫**不再抛异常**（无需重启进程）
     *
     * 修复前（R37 的永久缓存）：第一次解析出的「坏」结论会被一直复用，
     * 运维按提示改好 `.env` 也照样抛错，直到重启 —— 真实的运维死锁。
     */
    public function testGuardSelfHealsAfterEnvFileIsFixed(): void
    {
        // 1) 先写成坏的：注释里的半角括号会让 parse_ini_file 语法错误
        $this->writeEnv("APP_ENV = dev\n# 测试注入的坏注释 (v2)\n");

        $service = $this->makeCountingService();

        // 注意：不要写成 try { runGuard(); $this->fail(...) } catch (\RuntimeException) ——
        // PHPUnit 的 AssertionFailedError 本身继承 RuntimeException，fail() 会被自己的 catch 吞掉，
        // 失败信息就变成「断言不满足」这种看不懂的样子。用显式标志位。
        $firstThrew = false;
        try {
            $service->runGuard();
        } catch (\RuntimeException $e) {
            $firstThrew = true;
            $this->assertStringContainsString('无法解析', $e->getMessage(), '异常消息应说明解析失败');
            $this->assertStringContainsString('出错行号：2', $e->getMessage(), '异常消息应给出可定位的行号');
        }
        $this->assertTrue($firstThrew, '坏 .env 必须让守卫抛异常（响亮失败是 R37 的要求，不能被缓存改造削弱）');
        $this->assertSame(1, $service->inspectCount, '第一次守卫必须真的解析一次');

        // 2) 就地把文件修好，并把 mtime 推到不同的秒
        //    （同一秒内改写是签名漏判的经典场景，用例里显式避开，见报告「风险」一节）
        $this->writeEnv("APP_ENV = dev\n# 测试注入的坏注释 v2\n", time() + 120);

        // 3) 再调一次：签名已变 → 重新检查 → 自愈（不抛异常即为通过）
        try {
            $service->runGuard();
        } catch (\RuntimeException $e) {
            $this->fail('自愈失败：文件已修好但守卫仍抛错，原文：' . $e->getMessage());
        }

        $this->assertSame(2, $service->inspectCount, '文件变了必须重新解析一次，才能拿到「已经修好」的结论');
    }

    /**
     * 反向对照：签名若不清 stat 缓存，就看不见「别的进程已修好 .env」——自愈失效
     *
     * 这条用例把 R42 最容易漏掉的一步`clearstatcache(true, $path)`钉死：
     * 用一个**故意不调 clearstatcache** 的替身复现 R37 的死锁（修好了也继续抛错），
     * 从而证明业务代码里的 clearstatcache 不是装饰。
     *
     * 改写动作刻意交给**另一个进程**（exec + cp/touch）：只有外部进程的写入
     * 一定不会触发本进程 stat 缓存的自动失效，用例才稳定可复现。
     */
    public function testSignatureWithoutClearstatcacheCannotSeeExternalFix(): void
    {
        $this->writeEnv("APP_ENV = dev\n# 测试注入的坏注释 (v2)\n");

        $service = $this->makeNaiveService();

        $firstThrew = false;
        try {
            $service->runGuard();
        } catch (\RuntimeException $e) {
            $firstThrew = true;
            $this->assertStringContainsString('无法解析', $e->getMessage());
        }
        $this->assertTrue($firstThrew, '坏 .env 必须让守卫抛异常');

        $this->rewriteFromAnotherProcess("APP_ENV = dev\n# 测试注入的坏注释 v2\n", time() + 240);

        $secondThrew = false;
        try {
            $service->runGuard();
        } catch (\RuntimeException $e) {
            // 期望路径：签名被 stat 缓存冻住 → 继续复用「坏」结论 → 退回 R37 的「必须重启」死锁
            $secondThrew = true;
            $this->assertStringContainsString('无法解析', $e->getMessage(), '复用的应仍是旧结论');
        }

        $this->assertTrue(
            $secondThrew,
            '不清 stat 缓存时，签名应当**看不到**这次外部修改（本用例正是要证明 clearstatcache 必不可少）。'
            . '它竟然自愈了，说明 PHP stat 缓存的行为变了，请复核 buildEnvFileSignature() 里的必要性说明'
        );
        $this->assertSame(1, $service->inspectCount, '签名被 stat 缓存冻住时不应重新解析');
    }

    /**
     * `.env` 不存在：放行，且「不存在」也是一种**稳定**签名（不会每次调用都重新检查）
     */
    public function testMissingEnvFileIsAllowedAndVerdictIsCached(): void
    {
        $service = $this->makeCountingService();

        $service->runGuard();
        $service->runGuard();

        $this->assertSame(
            1,
            $service->inspectCount,
            '文件不存在时应放行，且「不存在」要被 -1 哨兵签名缓存住，不必每次重查'
        );
    }

    /**
     * 签名对 mtime 与 size 都敏感，且文件未变时保持稳定
     */
    public function testSignatureReflectsMtimeAndSize(): void
    {
        $this->writeEnv("A=1\n");
        $first = GuardCountingAppService::signatureOf($this->envPath);

        // 只改 mtime
        touch($this->envPath, time() + 60);
        $second = GuardCountingAppService::signatureOf($this->envPath);
        $this->assertNotSame($first, $second, 'mtime 变化必须改变签名');

        // 只改 size
        file_put_contents($this->envPath, "A=1\nB=2\n");
        $third = GuardCountingAppService::signatureOf($this->envPath);
        $this->assertNotSame($second, $third, 'size 变化必须改变签名');

        // 都没变 → 签名必须稳定（否则缓存永远命中不了）
        $this->assertSame($third, GuardCountingAppService::signatureOf($this->envPath), '文件未变时签名必须稳定');
    }

    /**
     * R37 的例外未被破坏：`env:check` 诊断命令下守卫整体跳过（连解析都不做）
     *
     * 若守卫在这一步就抛错，「用来诊断 .env 的命令」会被「.env 坏了就抛错」的守卫挡在门外，
     * 诊断报告永远看不到 —— 这正是当初加例外的原因。
     */
    public function testDiagnosticCommandStillBypassesGuard(): void
    {
        $this->writeEnv("APP_ENV = dev\n# 测试注入的坏注释 (v2)\n");

        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['think', 'env:check'];

        // 例外分支只在「命令行」下才生效，所以这里必须用真实的（命令行）App
        $service = $this->makeCountingService(true);

        try {
            // 不抛异常即为通过
            $service->runGuard();
        } finally {
            if ($originalArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }

        $this->assertSame(0, $service->inspectCount, '诊断命令下守卫应完全跳过（一次解析都不该发生）');
    }

    /**
     * R37 的命令行退出码修正未被破坏：坏 `.env` 下进程必须以 **1** 结束
     *
     * 走子进程是因为「进程退出码」在进程内观察不到；也不能用真实 `.env`，
     * 所以给子进程一个临时 app 根目录（`new App($tmpRoot)` → 读 `$tmpRoot/.env`）。
     * 关键断言是 exit === 1 而不是 255：PHP 的未捕获异常默认退出码是 255，
     * 拿到 1 就说明 `register_shutdown_function(exit(1))` 那段修正仍然生效。
     */
    public function testBrokenEnvFileMakesCliProcessExitWithOne(): void
    {
        $this->writeEnv("APP_ENV = dev\n# 测试注入的坏注释 (v2)\n");

        $autoload   = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $scriptPath = $this->rootPath . 'probe.php';

        $template = <<<'PHP'
<?php
require __AUTOLOAD__;

class GuardProbe extends \app\AppService
{
    public function run(): void
    {
        $this->guardEnvFile();
        echo "GUARD_PASSED\n";
    }
}

(new GuardProbe(new \think\App($argv[1])))->run();
echo "SHOULD_NOT_REACH\n";
PHP;

        file_put_contents($scriptPath, str_replace('__AUTOLOAD__', var_export($autoload, true), $template));

        $lines    = [];
        $exitCode = 0;
        exec(
            sprintf(
                '%s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($scriptPath),
                escapeshellarg($this->rootPath)
            ),
            $lines,
            $exitCode
        );
        $output = implode("\n", $lines);

        $this->assertSame(1, $exitCode, "坏 .env 必须以 1 退出（255 说明退出码修正失效），实际输出：\n{$output}");
        $this->assertStringContainsString('无法解析', $output, "输出应保留可定位的守卫消息，实际输出：\n{$output}");
        $this->assertStringNotContainsString('GUARD_PASSED', $output, '守卫不得放行（否则等于静默降级）');
    }

    /**
     * 生成一个「会统计解析次数」的守卫替身
     *
     * @param bool $console true = 用真实（命令行）App；false = 用「非命令行」App
     *                       （避免守卫注册 shutdown 里的 exit(1)，那会让 PHPUnit 进程以 1 退出）
     * @return GuardCountingAppService
     */
    private function makeCountingService(bool $console = false): GuardCountingAppService
    {
        $app = $console ? new App($this->rootPath) : new GuardNonConsoleApp($this->rootPath);

        return new GuardCountingAppService($app);
    }

    /**
     * 生成一个「签名不清 stat 缓存」的守卫替身（反向对照用）
     *
     * @return GuardNaiveSignatureAppService
     */
    private function makeNaiveService(): GuardNaiveSignatureAppService
    {
        return new GuardNaiveSignatureAppService(new GuardNonConsoleApp($this->rootPath));
    }

    /**
     * 写入临时 `.env`（本进程写）
     *
     * @param string   $content 文件内容
     * @param int|null $mtime   mtime（null = 交给文件系统）
     */
    private function writeEnv(string $content, ?int $mtime = null): void
    {
        file_put_contents($this->envPath, $content);

        if ($mtime !== null) {
            touch($this->envPath, $mtime);
        }

        // 本进程写完立刻清 stat 缓存：避免后面算「基准签名」时读到写入前的旧值，
        // 把用例的前提（磁盘真值）搞错。业务代码里的 clearstatcache 是另一件事（见 buildEnvFileSignature）。
        clearstatcache(true, $this->envPath);
    }

    /**
     * 由**另一个进程**改写临时 `.env`（并指定 mtime）
     *
     * 为什么必须换进程：本进程直接 `file_put_contents()` / `touch()` 时，
     * PHP 是否会顺带失效 stat 缓存属于实现细节；而外部进程的写入**一定**不会动我们的缓存，
     * 这样「不清 clearstatcache 就看不见变化」才能稳定复现。
     *
     * 为什么这里**不能**顺手 unlink 掉暂存文件（看起来更干净）：
     * 实测 PHP 8.2 的 `unlink()` 会**清空整份 stat 缓存**（连无关文件一起），
     * 一旦在两次取签名之间 unlink，本用例想复现的「stat 缓存冻住 mtime/size」就被自己抹掉了
     * （初版用例正是栽在这里：反向对照意外地「自愈」成功）。
     * 暂存文件统一交给 tearDown 清理。
     *
     * @param string $content 新内容
     * @param int    $mtime   目标 mtime（时间戳）
     */
    private function rewriteFromAnotherProcess(string $content, int $mtime): void
    {
        $staged = $this->rootPath . 'staged.env';
        file_put_contents($staged, $content);

        // GNU touch 才有 -d @时间戳；BSD（macOS 宿主）走 fallback 的 touch
        $command = sprintf(
            'cp %s %s && { touch -d @%d %s 2>/dev/null || touch %s; }',
            escapeshellarg($staged),
            escapeshellarg($this->envPath),
            $mtime,
            escapeshellarg($this->envPath),
            escapeshellarg($this->envPath)
        );

        $lines    = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $lines, $exitCode);

        $this->assertSame(0, $exitCode, '外部进程改写临时 .env 失败：' . implode("\n", $lines));
    }

    /**
     * 清空 `AppService` 的进程级缓存（两个静态属性都是 protected，用反射清理）
     *
     * 不为此在生产代码里开 `resetForTest()`：那是只有测试用的 API，
     * 反射虽稍啰嗦，但不会让「测试专用重置」变成生产代码的公开承诺。
     */
    private function resetGuardCache(): void
    {
        $reflection = new \ReflectionClass(AppService::class);

        foreach (['envFileReport', 'envFileSignature'] as $name) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }
    }
}

/**
 * 测试替身：暴露守卫入口 + 统计「到底解析了几次」
 *
 * 计数器是「零解析」唯一可靠的取证方式：耗时证据会被容器 bind mount 的抖动淹没。
 */
class GuardCountingAppService extends AppService
{
    /**
     * `inspectEnvFileForGuard()` 被调用的次数（= parse_ini_file 的次数）
     */
    public int $inspectCount = 0;

    /**
     * 暴露 protected 守卫，供测试直接驱动
     */
    public function runGuard(): void
    {
        $this->guardEnvFile();
    }

    /**
     * 暴露签名计算，便于单独验证「签名对 mtime/size 敏感」
     *
     * @param string $path 文件路径
     * @return string 签名
     */
    public static function signatureOf(string $path): string
    {
        return parent::buildEnvFileSignature($path);
    }

    /**
     * @param string $path `.env` 路径
     * @return array{path: string, exists: bool, parsed: bool, line: int|null, raw: string|null, error: string|null}
     */
    protected function inspectEnvFileForGuard(string $path): array
    {
        $this->inspectCount++;

        return parent::inspectEnvFileForGuard($path);
    }
}

/**
 * 测试替身：签名**不**清 stat 缓存（反向对照，用来证明 clearstatcache 必不可少）
 *
 * 这是「R42 之前的错误实现」的最小复刻：stat 缓存冻住 mtime/size → 签名永远不变 →
 * 「修好 .env」对长驻进程不可见 → 退回「必须重启」的死锁。
 */
class GuardNaiveSignatureAppService extends GuardCountingAppService
{
    /**
     * @param string $path 文件路径
     * @return string 签名（故意不清 stat 缓存）
     */
    protected static function buildEnvFileSignature(string $path): string
    {
        $mtime = @filemtime($path);
        $size  = @filesize($path);

        return $path
            . '|' . ($mtime === false ? '-1' : (string) $mtime)
            . '|' . ($size === false ? '-1' : (string) $size);
    }
}

/**
 * 测试替身：把「是否命令行」钉死为 false
 *
 * 为什么必须这么做：守卫在命令行下会 `register_shutdown_function(exit(1))`（给部署脚本用的退出码修正）。
 * 若在 PHPUnit 进程里走到那条分支，**整个测试进程**会在末尾以 1 退出 ——
 * 看起来像「测试失败」，其实是守卫按设计工作。命令行分支另用子进程用例覆盖。
 */
class GuardNonConsoleApp extends App
{
    /**
     * @return bool 恒 false
     */
    public function runningInConsole(): bool
    {
        return false;
    }
}
