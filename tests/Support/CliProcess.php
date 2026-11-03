<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * 测试用子进程启动助手 | Test-only subprocess launcher
 *
 * ## 为什么需要它（ER-15 的「第二半」）
 *
 * 测试里用 `exec()` 拉起的子进程是**全新的 PHP 进程**，会重新走一遍
 * `App::initialize() → Env::load('.env')`。而 `.env` 里的 `DB_NAME = alkaid_sys` 是**开发库**，
 * 于是「父进程已被 phpunit.xml / ThinkPHPTestCase 隔离到 alkaid_sys_test」这件事
 * 对子进程完全不成立。实测两次全量跑：开发库 369 → 371 张表，
 * `lowcode_collections` 342 → 344（tenant_id=99997 的 `e2e_cli_*`）。
 * 父进程守卫再严也保护不了另一个进程 —— 这就是本助手存在的原因。
 *
 * ## 为什么不能「给子进程传 DB_NAME=alkaid_sys_test」
 *
 * `think\Env::get()` **先读 `.env` 填充的 data，缺失才回落进程环境**（且回落键名带 `PHP_` 前缀），
 * 所以进程环境里同名的 `DB_NAME` 打不过 `.env`。这条捷径已实测无效，别再试。
 *
 * ## 本助手的做法：临时环境文件 + PHP_ENV_NAME
 *
 * `PHP_ENV_NAME` 是**替换**而不是叠加：设置它之后，`App::initialize()` 只读
 * `.env.{PHP_ENV_NAME}`，**完全不读 `.env`**（见 `vendor/topthink/framework/src/think/App.php`
 * 的 `initialize()` / `loadEnv()`）。于是本助手：
 *
 *   1. 在项目根生成一份**完整的**临时环境文件 `.env.alkaidtest_<pid>_<rand>`
 *      （`.gitignore` 的 `.env.*` 已覆盖，不入库）；
 *   2. 内容取自「已解析的真实配置」四层合并：
 *      `.env.example`（版本库内的单一真相源，CI 一定有）
 *      → `.env`（本地实际取值，只读）
 *      → 当前进程环境（phpunit.xml 的 `<env>` / CI workflow 的 `env:` 注入）
 *      → **强制覆盖**为测试库的 `DB_*`；
 *   3. 以 `PHP_ENV_NAME=<临时名>` + `ALKAID_TESTING=1` 启动子进程；
 *   4. 临时文件由进程结束时的 shutdown 钩子删除（**不在**每次调用后立即 unlink，
 *      原因见 {@see self::spawn()}：unlink 会清空整份 stat 缓存，破坏 R42 的反向对照用例）。
 *
 * 因此本方案同时满足三种场景：
 *   - **本地**：`.env` 仍指向开发库（手工命令不受影响），子进程只认临时文件；
 *   - **CI（没有 `.env.test`）**：临时文件是每次运行现生成的，只有 `.env.example` 也够；
 *   - **不改 `.env`**：本类对 `.env` 只读。
 *
 * ## fail-closed
 *
 * 组装 `DB_*` 时会断言库名含 `_test`，不满足就**在父进程直接抛异常**、不启动子进程。
 * 唯一例外是 {@see self::thinkWithEnvOverrides()}：它存在的目的就是「故意把子进程指向错误的库」，
 * 用来验证 CLI 侧的守卫（见 `tests/Integration/Lowcode/LowcodeCliTestDatabaseGuardTest.php`）。
 *
 * @package Tests\Support
 */
final class CliProcess
{
    /**
     * 测试库名必须包含的标记（与 tests/ThinkPHPTestCase.php 的判据保持一致）
     */
    private const TEST_DB_MARKER = '_test';

    /**
     * 运行环境未提供 DB_NAME 时的兜底值（指向测试库，绝不回落到开发库）
     */
    private const DEFAULT_TEST_DB_NAME = 'alkaid_sys_test';

    /**
     * 测试上下文标记：CLI 命令侧据此启用 fail-closed 守卫
     *
     * 刻意用自定义标记而不是 `PHPUNIT`：后者不是环境变量（只是常量），子进程里看不到。
     */
    private const TESTING_FLAG = 'ALKAID_TESTING';

    /**
     * 临时环境文件名前缀（最终形如 `.env.alkaidtest_12345_1a2b3c4d`）
     */
    private const ENV_NAME_PREFIX = 'alkaidtest_';

    /**
     * 环境文件名固定前缀（`App::loadEnv()` 拼的是 `rootPath . '.env.' . $envName`，
     * 所以临时文件只能落在项目根，不能放进 runtime/）
     */
    private const ENV_FILE_PREFIX = '.env.';

    /**
     * 从当前进程环境带进临时环境文件的键前缀
     *
     * 覆盖 phpunit.xml 里 `<env ... force="true">` 钉死的测试语义变量
     * （APP_ENV / CACHE_FILE_PREFIX / RATELIMIT_* 等）与 CI 注入的连接参数。
     * 只按前缀白名单传递，避免把 PATH、LS_COLORS 这类无关变量塞进 INI 文件。
     *
     * @var array<int, string>
     */
    private const PROPAGATED_ENV_PREFIXES = [
        'APP_',
        'DB_',
        'REDIS_',
        'CACHE_',
        'LOG_',
        'JWT_',
        'DEFAULT_',
        'REGISTRATION_',
        'RATELIMIT_',
        'ALKAID_',
    ];

    /**
     * 前缀不匹配、但必须传递的键（phpunit.xml force 的 DDL 闸门）
     *
     * 漏掉它的后果：CLI 在测试库里执行 CREATE TABLE 会被 SchemaBuilder 拒绝，
     * E2E 用例会以「DDL 未授权」失败，而真正的原因在环境文件少了一行。
     *
     * @var array<int, string>
     */
    private const PROPAGATED_ENV_KEYS = [
        'ALLOW_RUNTIME_DDL',
    ];

    /**
     * 已登记清理的临时文件（shutdown 兜底用）
     *
     * @var array<int, string>
     */
    private static array $pendingCleanup = [];

    /**
     * shutdown 清理钩子是否已注册
     */
    private static bool $cleanupRegistered = false;

    /**
     * 以「已隔离到测试库」的环境执行 `php think <args>`
     *
     * @param array<int, string> $args think 之后的参数（如 `['lowcode:create-model', 'Foo', '--fields=title:string']`）
     * @return CliProcessResult
     */
    public static function think(array $args): CliProcessResult
    {
        return self::runThink($args, []);
    }

    /**
     * 同上，但允许覆写临时环境文件里的键
     *
     * **仅供 fail-closed 回归测试使用**：它是「故意把子进程指向错误的库」的唯一入口，
     * 用来证明 CLI 命令侧的守卫真的会拒绝，而不是继续写库。生产/普通用例请用 {@see self::think()}。
     *
     * @param array<int, string>          $args         think 之后的参数
     * @param array<string, string>       $envOverrides 覆写到临时环境文件的键值（如 `['DB_NAME' => 'alkaid_sys']`）
     * @return CliProcessResult
     */
    public static function thinkWithEnvOverrides(array $args, array $envOverrides): CliProcessResult
    {
        return self::runThink($args, $envOverrides);
    }

    /**
     * 以同样的隔离环境执行任意 PHP 脚本
     *
     * 用途：测试里需要「另一个进程」做验证探针（例如 AppServiceEnvGuardTest 的 guardEnvFile 探针）。
     *
     * @param string             $scriptPath 脚本绝对路径
     * @param array<int, string> $args       传给脚本的参数
     * @return CliProcessResult
     */
    public static function phpScript(string $scriptPath, array $args = []): CliProcessResult
    {
        return self::spawn(array_merge([PHP_BINARY, $scriptPath], $args), []);
    }

    /**
     * 以同样的隔离环境执行任意 shell 命令
     *
     * 用途：需要「外部进程改写文件」这类不碰数据库的操作（例如 AppServiceEnvGuardTest 的 cp/touch）。
     * 走 `/bin/sh -c`，调用方自行 `escapeshellarg()`。
     *
     * @param string $command shell 命令
     * @return CliProcessResult
     */
    public static function shell(string $command): CliProcessResult
    {
        return self::spawn(['/bin/sh', '-c', $command], []);
    }

    /**
     * 项目根目录（tests/Support 往上两层）
     */
    public static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * 执行 `php think <args>`
     *
     * @param array<int, string>    $args
     * @param array<string, string> $envOverrides
     */
    private static function runThink(array $args, array $envOverrides): CliProcessResult
    {
        $root = self::projectRoot();

        return self::spawn(array_merge([PHP_BINARY, $root . '/think'], $args), $envOverrides);
    }

    /**
     * 启动子进程并回收退出码与合并输出
     *
     * 用 `proc_open()` + 参数数组（不经过 shell）而不是 `exec()`：
     * 参数里的空格/引号不需要调用方手工转义，也就不会出现「转义错一半」的隐性 bug。
     *
     * @param array<int, string>    $command    完整命令（argv 形式）
     * @param array<string, string> $envOverrides
     */
    private static function spawn(array $command, array $envOverrides): CliProcessResult
    {
        $envName = self::createEnvironmentFile($envOverrides);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            // stderr 合并进 stdout：与旧的 `exec($cmd . ' 2>&1')` 语义一致
            2 => ['redirect', 1],
        ];
        $pipes = [];
        $process = proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            self::projectRoot(),
            self::childEnvironment($envName, $envOverrides)
        );

        if (!is_resource($process)) {
            throw new RuntimeException('无法启动子进程（proc_open 失败）：' . implode(' ', $command));
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        // 刻意**不在**这里 unlink 临时文件：PHP 8.2 的 unlink() 会清空**整份** stat 缓存
        // （连无关文件一起）。AppServiceEnvGuardTest 的反向对照用例正是靠「外部进程改写后
        // 本进程的 stat 缓存仍被冻住」来证明 clearstatcache 不可省 —— 一旦在这里 unlink，
        // 那条用例会假性自愈（初始版本已实测栽在这里）。
        // 清理统一交给 registerCleanup() 注册的 shutdown 钩子：文件在进程结束时删除，
        // 对被 gitignore 的 `.env.*` 来说，短暂的留存没有任何副作用。
        return new CliProcessResult(proc_close($process), $output);
    }

    /**
     * 生成临时环境文件，返回环境名（路径恒为 `<root>/.env.<环境名>`）
     *
     * @param array<string, string> $envOverrides
     */
    private static function createEnvironmentFile(array $envOverrides): string
    {
        $values = self::collectEnvironmentValues($envOverrides);

        $envName = self::ENV_NAME_PREFIX . getmypid() . '_' . bin2hex(random_bytes(4));
        $path = self::projectRoot() . '/' . self::ENV_FILE_PREFIX . $envName;

        $lines = [
            '# 由 tests/Support/CliProcess 生成的临时环境文件（测试子进程专用，用完即删）',
            '# 为什么不用 .env.test：它不在版本库里（.gitignore 的 .env.*），CI 上没有；',
            '# 这里每次运行现生成，内容来自已解析的真实配置，所以本地 / CI 都能工作。',
            '# PHP_ENV_NAME 是「替换」语义：子进程只读本文件，完全不读 .env。',
        ];

        foreach ($values as $key => $value) {
            $line = self::renderIniLine((string) $key, (string) $value);
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        if (false === file_put_contents($path, implode("\n", $lines) . "\n")) {
            throw new RuntimeException('无法写入临时环境文件：' . $path);
        }

        // fail-closed：写出去的内容必须能被 parse_ini_file 原样读回。
        // 若某个值带转义问题，子进程会拿到「缺了一半的配置」并连到意料之外的库 —— 宁可在这里失败。
        self::assertEnvironmentFileRoundTrips($path, $values);

        self::registerCleanup($path);

        return $envName;
    }

    /**
     * 汇总临时环境文件的键值（后面的层覆盖前面的层）
     *
     * @param array<string, string> $envOverrides
     * @return array<string, string>
     */
    private static function collectEnvironmentValues(array $envOverrides): array
    {
        $root = self::projectRoot();

        // 第 1 层：.env.example —— 版本库内的单一真相源（CI 一定存在）
        $values = self::parseEnvFile($root . '/.env.example');

        // 第 2 层：.env —— 本地实际取值（只读）。
        // 解析失败（例如 EnvCheckCommandTest 会临时注入坏行）时跳过：
        // 不能把语法错误带进子进程，那份文件本来就会由用例自己断言。
        $values = array_merge($values, self::parseEnvFile($root . '/.env'));

        // 第 3 层：当前进程环境（phpunit.xml 的 <env>、CI workflow 的 env: 注入）
        foreach (array_keys($values) as $key) {
            $key = (string) $key;
            $value = getenv($key);
            if (false !== $value) {
                $values[$key] = $value;
            }
        }

        foreach (self::propagatedProcessEnv() as $key => $value) {
            $values[$key] = $value;
        }

        // 第 4 层：强制把 DB_* 指向测试库（fail-closed 断言在这里发生）
        foreach (self::resolveDatabaseEnv($envOverrides) as $key => $value) {
            $values[$key] = $value;
        }

        // 测试上下文标记：CLI 命令侧的守卫据此拒绝写非测试库
        $values[self::TESTING_FLAG] = '1';

        return $values;
    }

    /**
     * 从当前进程环境里挑出需要传递的键
     *
     * @return array<string, string>
     */
    private static function propagatedProcessEnv(): array
    {
        $result = [];

        foreach (getenv() as $key => $value) {
            if (!is_string($value) || '' === $value) {
                continue;
            }

            $upper = strtoupper((string) $key);
            if (1 !== preg_match('/^[A-Z_][A-Z0-9_]*$/', $upper)) {
                continue;
            }

            if (in_array($upper, self::PROPAGATED_ENV_KEYS, true)) {
                $result[$upper] = $value;
                continue;
            }

            foreach (self::PROPAGATED_ENV_PREFIXES as $prefix) {
                if (str_starts_with($upper, $prefix)) {
                    $result[$upper] = $value;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * 组装子进程要连的数据库参数（并做 fail-closed 断言）
     *
     * 取值优先级与 tests/ThinkPHPTestCase::resolveDatabaseEnv() 一致：
     * 进程环境（phpunit.xml / CI / shell 导出）> 测试库兜底值。
     *
     * @param array<string, string> $envOverrides 仅供 fail-closed 回归测试的故意覆写
     * @return array<string, string>
     */
    private static function resolveDatabaseEnv(array $envOverrides): array
    {
        $read = static function (string $key, string $default): string {
            $value = getenv($key);

            return (false === $value || '' === $value) ? $default : (string) $value;
        };

        $resolved = [
            'DB_TYPE' => $read('DB_TYPE', 'mysql'),
            'DB_HOST' => $read('DB_HOST', '127.0.0.1'),
            // 兜底值指向测试库：任何「忘记注入」的场景都不允许回落到 alkaid_sys
            'DB_NAME' => $read('DB_NAME', self::DEFAULT_TEST_DB_NAME),
            'DB_USER' => $read('DB_USER', 'root'),
            'DB_PASS' => $read('DB_PASS', 'root'),
            'DB_PORT' => $read('DB_PORT', '3306'),
            'DB_CHARSET' => $read('DB_CHARSET', 'utf8mb4'),
        ];

        $dbNameOverridden = false;
        foreach ($envOverrides as $key => $value) {
            $upper = strtoupper((string) $key);
            if (!str_starts_with($upper, 'DB_')) {
                continue;
            }

            $resolved[$upper] = (string) $value;

            if ('DB_NAME' === $upper) {
                $dbNameOverridden = true;
            }
        }

        // 显式覆写 = 调用方故意制造错误配置（fail-closed 回归测试），此时跳过断言
        if (!$dbNameOverridden && !str_contains($resolved['DB_NAME'], self::TEST_DB_MARKER)) {
            throw new RuntimeException(sprintf(
                "拒绝为测试子进程构造非测试库环境：解析出的数据库名「%s」不包含「%s」。\n"
                . "子进程会写库，一旦连到开发库就会污染基线（实测曾把 alkaid_sys 从 369 张表写成 371 张）。\n"
                . "请按以下顺序处置：\n"
                . "  1) 检查 phpunit.xml 的 <env name=\"DB_NAME\" value=\"%s\"/> 是否被删改；\n"
                . "  2) 不要在 shell 里导出 DB_NAME 覆盖 phpunit.xml（DB_* 刻意未加 force，已存在的值优先）；\n"
                . "  3) 冷启动测试库：PHP_ENV_NAME=test php think migrate:run。\n"
                . '参考：ER-15 / tests/Support/CliProcess.php。',
                $resolved['DB_NAME'],
                self::TEST_DB_MARKER,
                self::DEFAULT_TEST_DB_NAME
            ));
        }

        return $resolved;
    }

    /**
     * 构造子进程的完整环境变量表
     *
     * `proc_open()` 传入 env 数组时是**整体替换**，所以必须先把当前进程环境全部拷贝过来。
     *
     * @param string                $envName
     * @param array<string, string> $envOverrides
     * @return array<string, string>
     */
    private static function childEnvironment(string $envName, array $envOverrides): array
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            if (is_string($value)) {
                $env[(string) $key] = $value;
            }
        }

        // 替换语义：子进程只读临时环境文件，不读 .env
        $env['PHP_ENV_NAME'] = $envName;

        // 显式测试上下文标记：CLI 命令侧的 fail-closed 守卫据此生效
        $env[self::TESTING_FLAG] = '1';

        // 双保险：即使临时文件被删/读不到，Env 的回落链（$_ENV → getenv('PHP_<KEY>')）
        // 也只能拿到测试库。注意这一步**不会**削弱守卫 —— 环境文件的数据优先级更高，
        // 守卫读的是「实际生效的配置」而不是这里的进程环境。
        foreach (self::resolveDatabaseEnv($envOverrides) as $key => $value) {
            $env[$key] = $value;
            $env['PHP_' . $key] = $value;
        }

        return $env;
    }

    /**
     * 把键值渲染成一行 INI
     *
     * 值只在「安全字符集」内才裸写，否则双引号包裹并转义。
     * 已实测：`parse_ini_file(..., INI_SCANNER_RAW)` 能原样读回 `\"` 与 `\\` 的转义形式。
     *
     * @param string $key
     * @param string $value
     * @return string 空串表示该键名非法、应被丢弃
     */
    private static function renderIniLine(string $key, string $value): string
    {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            return '';
        }

        if ('' === $value) {
            return $key . ' = ""';
        }

        // 安全字符集：字母数字与常见分隔符，这些值不需要引号也不会被 INI 解释
        if (1 === preg_match('#^[A-Za-z0-9_.:/@+\-]+$#', $value)) {
            return $key . ' = ' . $value;
        }

        // 换行会让一行变两行（值被截断），一律去掉；反斜杠与双引号必须转义
        $escaped = str_replace(["\r", "\n"], '', $value);
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $escaped);

        return $key . ' = "' . $escaped . '"';
    }

    /**
     * 回读校验：临时环境文件必须能把每个键原样解析回来
     *
     * @param string                $path
     * @param array<string, string> $expected
     */
    private static function assertEnvironmentFileRoundTrips(string $path, array $expected): void
    {
        $parsed = @parse_ini_file($path, true, INI_SCANNER_RAW);

        if (!is_array($parsed)) {
            @unlink($path);
            throw new RuntimeException('临时环境文件无法被 parse_ini_file 解析：' . $path);
        }

        foreach ($expected as $key => $value) {
            $actual = array_key_exists($key, $parsed) ? (string) $parsed[$key] : null;

            if ($actual !== (string) $value) {
                @unlink($path);
                throw new RuntimeException(sprintf(
                    '临时环境文件回读不一致：%s 期望「%s」，实际「%s」（%s）',
                    (string) $key,
                    (string) $value,
                    null === $actual ? 'null' : $actual,
                    $path
                ));
            }
        }
    }

    /**
     * 解析 env 文件（只读）
     *
     * 刻意用 `@` 抑制 `parse_ini_file()` 的 syntax error 警告：
     * 坏 `.env` 的场景由调用方自己的用例断言，不能让这里多出一条 PHPUnit 警告/risky 记录。
     *
     * 存在性判断刻意用 `file_exists()` 而不是 `is_file()`：**实测 PHP 8.2 的 stat 缓存只有一个槽位**，
     * 任何针对「别的路径」的 `is_file()` / `stat()` 都会把之前的缓存顶掉。
     * AppServiceEnvGuardTest 的反向对照用例正是靠「本进程 stat 缓存仍被冻住」来证明
     * clearstatcache() 不可省 —— 这里一次多余的 `is_file()` 就会让它假性自愈。
     * （同一组隔离探针实测：`is_file` / `stat` 会清空，`file_exists` / `parse_ini_file` 不会。）
     *
     * @param string $path
     * @return array<string, string>
     */
    private static function parseEnvFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $parsed = @parse_ini_file($path, true, INI_SCANNER_RAW);

        if (!is_array($parsed)) {
            return [];
        }

        $result = [];
        foreach ($parsed as $key => $value) {
            // 带 [section] 的写法会产生嵌套数组，这里不支持（.env / .env.example 都是扁平键）
            if (is_array($value)) {
                continue;
            }

            // Env::set() 内部会把键名统一成大写，这里提前对齐，避免两处规则漂移
            $result[strtoupper((string) $key)] = (string) $value;
        }

        return $result;
    }

    /**
     * 登记 shutdown 兜底清理（进程被中断时也尽量不留临时文件）
     */
    private static function registerCleanup(string $path): void
    {
        self::$pendingCleanup[] = $path;

        if (self::$cleanupRegistered) {
            return;
        }

        self::$cleanupRegistered = true;

        register_shutdown_function(static function (): void {
            foreach (self::$pendingCleanup as $file) {
                // 用 file_exists 而不是 is_file：见 parseEnvFile() 的说明（stat 缓存只有一个槽位）
                if (file_exists($file)) {
                    @unlink($file);
                }
            }

            self::$pendingCleanup = [];
        });
    }
}
