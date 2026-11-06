<?php

declare(strict_types=1);

namespace Tests\Legacy;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Tests\Support\ThinkHelperBootstrap;

/**
 * 守卫：phpunit 配置里的「限流阈值」不得退化到让整条 Feature 套件被 429 打红。
 *
 * 事故背景（CI 实红、本地全绿）：
 *   Backend CI 的 `PHPUnit Tests` 有 19 条用例返回 429，响应体是
 *       {"code":429,"message":"Too Many Requests",
 *        "data":{"scope":"ip","capacity":100,"rate":10,"tokens":0,
 *                "identifier":"0.0.0.0","algorithm":"token_bucket"}}
 *   —— 被 **ip** 维度拦了，桶容量只有 100。而 phpunit.xml 里明明 force 了
 *   `RATELIMIT_TB_CAPACITY=100000`：那是一项**死配置**。
 *
 *   为什么死：token_bucket 分支的取值顺序是
 *       `$capacity = (int) ($rule['capacity'] ?? $tbCfg['capacity'] ?? 100);`
 *   （app/middleware/RateLimit.php:227-228），而 `resolveScopeRule()` 优先返回
 *   `config('ratelimit.scopes.<scope>')`，config/ratelimit.php 的每个 scope 都自带
 *   capacity / rate —— 于是 `$tbCfg` **永远轮不到**。全局的 RATELIMIT_TB_* 只在
 *   「scope 自己没声明 capacity/rate」时才兜底。
 *
 *   后果放大：整条 Feature 套件共用同一个 ip 桶（CI 上所有请求的客户端 IP 都是 0.0.0.0），
 *   runner 约 30 秒跑完 314 个用例 ≈ 10 req/s，正好等于默认补充速率，突发即打空，
 *   之后级联 429；本地同一批用例要 184 秒，令牌来得及补回去，所以**本地永远绿**。
 *
 * 所以「测试配置里 force 住 scope 级 capacity/rate」不是调参，而是测试确定性的前提。
 * 本守卫把这件事变成断言，断言分组（失败信息前缀与之一一对应）：
 *   R0 取样口径本身不能空转：真实配置里必须仍有 scope 声明 capacity/rate，且
 *      user/tenant/ip/route 四个维度都在（否则 R1~R4 会「一条都不检查却显示绿色」）
 *   R1 每个这类 scope 的 CAPACITY / RATE，三份配置都必须声明且 force="true"
 *   R2 三份配置的同名变量取值必须**完全一致**（值漂移等于换了语义）
 *   R3 每个这类 scope 的 LIMIT / PERIOD 与 RATELIMIT_ALGORITHM 同样必须 force 住
 *      （fixed_window 兜底分支读的是 limit/period；算法漂移会让整组阈值失效）
 *   R4 下限：CAPACITY ≥ 10000、RATE ≥ 1000 —— 防止有人把值改回 100 之后守卫仍然绿
 *
 * 刻意不做的事：
 *   - 不钉死具体数值与书写顺序。只约束「下限 + 三份一致」：调参（100000 → 200000）不该
 *     逼人改测试，否则守卫会变成「每次调参都要动」的噪音源，最后被顺手删掉；
 *   - 不改生产默认值、不改限流逻辑：config/ratelimit.php 与 app/middleware/RateLimit.php
 *     保持原样，本守卫只盯**测试配置**；
 *   - 不用正则硬啃 XML（见 envDeclarations() 的注释）。
 *
 * 约束（与同目录其它守卫一致）：只读本地文件（require 配置 + DOMDocument 解析 XML），
 * 不写文件、不连库、不调外部命令、不访问网络。
 */
final class RateLimitPhpunitConfigGuardTest extends TestCase
{
    /** 限流配置（相对仓库根）：scope 名单的唯一真相源 */
    private const RATELIMIT_CONFIG = 'config/ratelimit.php';

    /** 受门禁约束的三份 phpunit 配置：基线 + 两个任务变体 */
    private const PHPUNIT_CONFIGS = ['phpunit.xml', 'phpunit.t048.xml', 'phpunit.t050.xml'];

    /** scope 级 token_bucket 参数（config/ratelimit.php 的 scopes.*.capacity / .rate） */
    private const TOKEN_BUCKET_KEYS = ['capacity', 'rate'];

    /** scope 级要钉住的环境变量后缀：前两个归 token_bucket 分支，后两个归 fixed_window 分支 */
    private const SCOPE_ENV_SUFFIXES = ['CAPACITY', 'RATE', 'LIMIT', 'PERIOD'];

    /** 中间件逐个遍历的限流维度（app/middleware/RateLimit.php 的 :123 与 :212 两处循环） */
    private const MIDDLEWARE_SCOPES = ['user', 'tenant', 'ip', 'route'];

    /** 桶容量下限：远高于「真实限流」量级，本守卫只要求「打不空」 */
    private const MIN_CAPACITY = 10000;

    /** 补充速率下限（令牌/秒）：同上 */
    private const MIN_RATE = 1000;

    /** 中间件认识的算法名（其它取值会静默掉进 fixed_window 兜底分支） */
    private const KNOWN_ALGORITHMS = ['token_bucket', 'fixed_window'];

    /**
     * 解析结果缓存：同一份 XML 在一次运行里会被多条断言反复查询
     *
     * @var array<string, array<string, array{value:string, force:bool, forceRaw:?string, line:int}>>
     */
    private static array $declarationCache = [];

    // -------------------------------------------------------------------
    // R0：取样口径不能空转
    // -------------------------------------------------------------------

    /**
     * R0（前置、防空转）：真实配置里确实还存在「按 scope 声明 capacity/rate」这回事。
     *
     * 这一条对应 env-check 脚本里 `[CATALOG-EMPTY]` 的教训：R1~R4 都是「对每个这样的
     * scope 遍历检查」，一旦取样集合变空，它们会**一条都不检查**却仍然是绿色 ——
     * 空转型守卫比没有守卫更糟，因为它会出现在「已纳入 CI」的报告里。
     */
    public function testR0RealConfigStillDeclaresTokenBucketScopes(): void
    {
        $scopes = self::tokenBucketScopes();
        $violations = [];

        if ($scopes === []) {
            $violations[] = 'config/ratelimit.php 的 scopes 里没有任何一个 scope 声明 capacity 或 rate：'
                . 'R1~R4 会因此一条都不检查却仍显示绿色（空转）。'
                . '若这是刻意重构（例如把 token_bucket 参数整体挪到别处），'
                . '请同步修改本守卫的取样口径，而不是让它继续空转。';
        }

        foreach (self::MIDDLEWARE_SCOPES as $scope) {
            if (in_array($scope, $scopes, true)) {
                continue;
            }
            $violations[] = sprintf(
                'scope「%s」不再在 config/ratelimit.php 里声明 capacity/rate，但它仍是 '
                . 'app/middleware/RateLimit.php 逐个遍历的限流维度之一：'
                . '要么是配置被删掉（该维度失去突发保护），要么是维度被改名（本守卫的取样范围已经跑偏）。',
                $scope
            );
        }

        self::assertSame([], $violations, self::summarize('R0', $violations));
    }

    // -------------------------------------------------------------------
    // R1：scope 级 capacity / rate 必须被 force 住
    // -------------------------------------------------------------------

    /**
     * R1：每个声明了 capacity/rate 的 scope，三份配置都必须有对应的
     * `RATELIMIT_SCOPE_<SCOPE>_CAPACITY` 与 `.._RATE`，且 `force="true"`。
     *
     * 这一条就是「429 事故」的直接回归守卫：缺任意一条，测试就跑在配置默认的小桶上
     * （ip 默认 capacity=100 / rate=10），而 `RATELIMIT_TB_*` 救不了它。
     */
    public function testR1EveryTokenBucketScopeCapacityAndRateIsForced(): void
    {
        $violations = [];

        foreach (self::tokenBucketScopes() as $scope) {
            foreach (self::TOKEN_BUCKET_KEYS as $key) {
                $envName = self::scopeEnvName($scope, strtoupper($key));
                $violations = array_merge(
                    $violations,
                    self::collectForcedViolations($envName, self::consequence($envName))
                );
            }
        }

        self::assertSame([], $violations, self::summarize('R1', $violations));
    }

    // -------------------------------------------------------------------
    // R2：三份配置取值必须完全一致
    // -------------------------------------------------------------------

    /**
     * R2：三份配置里同名变量的取值必须**完全一致**。
     *
     * 为什么单独判而不只依赖 `scripts/check-phpunit-config.php` 的检查 A：
     * 那个脚本是 CI 门禁，回答的是「变体有没有跟上基线」；而「三份一起被改成 100」
     * 它照样绿（它只比对一致性，不判断值够不够大）。本守卫两层都要：一致性由 R2 守，
     * 够不够大由 R4 守 —— 少一层就是少一条阻断路径。
     */
    public function testR2ForcedValuesAreIdenticalAcrossConfigs(): void
    {
        $violations = [];

        foreach (self::requiredEnvNames() as $envName) {
            $values = [];
            foreach (self::PHPUNIT_CONFIGS as $config) {
                $declarations = self::envDeclarations($config);
                if (!array_key_exists($envName, $declarations)) {
                    // 「某份配置里缺这一项」由 R1 / R3 负责报，这里只比较都声明了的取值
                    continue;
                }
                $values[$config] = $declarations[$envName]['value'];
            }

            if (count(array_unique($values)) <= 1) {
                continue;
            }

            $detail = [];
            foreach ($values as $config => $value) {
                $detail[] = $config . '=' . $value;
            }

            $violations[] = sprintf(
                '%s 在三份配置里取值不一致（%s）：同名变量换个值就是换了个语义，'
                . '「变体绿」与「基线绿」不再是同一个绿；'
                . 'scripts/check-phpunit-config.php 的检查 A 按字面量比对，这种不一致会直接把 CI 判红。',
                $envName,
                implode(' / ', $detail)
            );
        }

        self::assertSame([], $violations, self::summarize('R2', $violations));
    }

    // -------------------------------------------------------------------
    // R3：limit / period 与算法必须被 force 住
    // -------------------------------------------------------------------

    /**
     * R3：每个这类 scope 的 LIMIT / PERIOD，以及 `RATELIMIT_ALGORITHM` 都必须被 force 住。
     *
     * 两组参数各自归一条分支：token_bucket 读 capacity/rate，fixed_window 读 limit/period。
     * 算法一旦被外部环境切到 fixed_window，capacity/rate 整组失效，闸门就只剩 limit/period；
     * 反过来把算法切走也一样。所以两组都得钉住，否则「钉了 A 分支的阈值」只是看起来安全。
     */
    public function testR3LimitPeriodAndAlgorithmAreForced(): void
    {
        $violations = [];

        foreach (self::tokenBucketScopes() as $scope) {
            foreach (['LIMIT', 'PERIOD'] as $suffix) {
                $envName = self::scopeEnvName($scope, $suffix);
                $violations = array_merge(
                    $violations,
                    self::collectForcedViolations($envName, self::consequence($envName))
                );
            }
        }

        $violations = array_merge(
            $violations,
            self::collectForcedViolations(
                'RATELIMIT_ALGORITHM',
                '算法是「读哪一组阈值」的总开关：不 force 住时，运行环境导出 RATELIMIT_ALGORITHM '
                . '会让测试在 token_bucket 与 fixed_window 之间漂移 —— '
                . '漂到 fixed_window 时 RATELIMIT_SCOPE_*_CAPACITY / _RATE 整组失效，'
                . '漂到 token_bucket 时 LIMIT / PERIOD 整组失效，同一份用例的结论随机器而变'
            )
        );

        foreach (self::PHPUNIT_CONFIGS as $config) {
            $declarations = self::envDeclarations($config);
            $algorithm = $declarations['RATELIMIT_ALGORITHM']['value'] ?? null;
            if ($algorithm === null || in_array($algorithm, self::KNOWN_ALGORITHMS, true)) {
                continue;
            }
            $violations[] = sprintf(
                '%s 的 RATELIMIT_ALGORITHM="%s" 不是中间件认识的算法名（%s）：'
                . 'app/middleware/RateLimit.php:118 只认 token_bucket，其余取值一律掉进 fixed_window 兜底分支，'
                . '这种「静默换算法」没有任何断言会报出来',
                $config,
                $algorithm,
                implode(' / ', self::KNOWN_ALGORITHMS)
            );
        }

        self::assertSame([], $violations, self::summarize('R3', $violations));
    }

    // -------------------------------------------------------------------
    // R4：下限断言
    // -------------------------------------------------------------------

    /**
     * R4：CAPACITY ≥ 10000、RATE ≥ 1000。
     *
     * 只做「下限」而不钉死具体数值：调参（100000 → 200000）不该逼人改测试。
     * 但必须有下限 —— 否则把值改回 100（也就是事故当时的默认值）之后，
     * R1~R3 依然全绿，守卫会眼睁睁放行同一次 429 事故。
     */
    public function testR4CapacityAndRateMeetMinimumBounds(): void
    {
        $violations = [];

        foreach (self::tokenBucketScopes() as $scope) {
            $violations = array_merge(
                $violations,
                self::collectBoundViolations(self::scopeEnvName($scope, 'CAPACITY'), (float) self::MIN_CAPACITY),
                self::collectBoundViolations(self::scopeEnvName($scope, 'RATE'), (float) self::MIN_RATE)
            );
        }

        self::assertSame([], $violations, self::summarize('R4', $violations));
    }

    // -------------------------------------------------------------------
    // 取样：真实配置里的 scope 名单
    // -------------------------------------------------------------------

    /**
     * 从**真实配置**里取出「声明了 token_bucket 参数」的 scope 名
     *
     * 为什么 require 真配置、而不是在本文件里抄一份 ['user','tenant','ip','route']：
     * 抄一份等于给「有哪些 scope」造第二份真相源 —— 配置里新增一个带 capacity/rate 的维度后，
     * 守卫照旧只看老的四个，新维度在测试里悄悄回到默认桶（100），
     * 下一次「本地绿、CI 429」就有了新的来源。
     *
     * @return array<int, string>
     */
    private static function tokenBucketScopes(): array
    {
        $config = self::ratelimitConfig();
        $scopes = [];

        foreach ((array) ($config['scopes'] ?? []) as $name => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            foreach (self::TOKEN_BUCKET_KEYS as $key) {
                if (!array_key_exists($key, $rule)) {
                    continue;
                }
                $scopes[] = (string) $name;

                break;
            }
        }

        sort($scopes, SORT_STRING);

        return $scopes;
    }

    /**
     * 本守卫要求三份配置都必须钉住的变量名全集（R2 用）
     *
     * @return array<int, string>
     */
    private static function requiredEnvNames(): array
    {
        $names = [];
        foreach (self::tokenBucketScopes() as $scope) {
            foreach (self::SCOPE_ENV_SUFFIXES as $suffix) {
                $names[] = self::scopeEnvName($scope, $suffix);
            }
        }
        $names[] = 'RATELIMIT_ALGORITHM';

        return $names;
    }

    /**
     * 把 scope 名与后缀拼成 phpunit 配置里的变量名（大写）
     */
    private static function scopeEnvName(string $scope, string $suffix): string
    {
        return 'RATELIMIT_SCOPE_' . strtoupper($scope) . '_' . $suffix;
    }

    // -------------------------------------------------------------------
    // 读取真实配置
    // -------------------------------------------------------------------

    /**
     * 读取 config/ratelimit.php 的真实配置数组
     *
     * 为什么必须 require 而不是扫文本：本守卫要回答的是「哪些 scope 声明了 capacity/rate」，
     * 只有真跑一遍这份 PHP 才能回答（配置里还有 env() 兜底与条件表达式）；
     * 文本匹配要么漏掉条件分支，要么把注释里提到的字段当成真声明。
     *
     * 为什么必须走 `withDefaultEnv()`：配置里每个值都写成 `env('RATELIMIT_*', 默认值)`，
     * 而「env() 函数存在」与「容器里绑定了 'env'」是两个判定 —— 只补前者，
     * require 会抛 ClassNotFoundException: class not exists: env。完整来龙去脉
     * （含「为什么裸 Env 拿到的是生产默认值」「为什么不污染后续用例」）见
     * `Tests\Support\ThinkHelperBootstrap` 的类注释，本文件不再重复一份。
     */
    private static function ratelimitConfig(): array
    {
        // 只有在 helper 尚未加载（本进程第一次采样）时才需要这条前置断言：文件缺失时给出
        // 可读的断言失败，而不是让 require_once 静默落空。判定条件与重构前一致 ——
        // 注意它问的是「要不要现加载 helper」，不是「env() 能不能用」（后者见新类注释 ①②）。
        if (!function_exists('env')) {
            self::assertFileExists(
                ThinkHelperBootstrap::helperFile(),
                '框架 helper 不存在：请先在 alkaid-backend 容器内 composer install'
            );
        }

        $config = ThinkHelperBootstrap::withDefaultEnv(
            static fn (): mixed => require self::repoPath(self::RATELIMIT_CONFIG)
        );
        self::assertIsArray($config, 'config/ratelimit.php 必须 return 一个数组，否则本守卫无法取样');

        return $config;
    }

    /**
     * 相对仓库根的路径 → 绝对路径
     *
     * 仓库根由 `ThinkHelperBootstrap::repoRoot()` 单点解析，本文件不再自己数 `__DIR__`
     * 的层级（数错只会让 require 静默落空）。
     */
    private static function repoPath(string $relativePath): string
    {
        return ThinkHelperBootstrap::repoRoot() . '/' . $relativePath;
    }

    // -------------------------------------------------------------------
    // 解析 phpunit 配置
    // -------------------------------------------------------------------

    /**
     * 读取一份 phpunit 配置的 `<phpunit><php><env>` 声明（name => 声明详情）
     *
     * 为什么用 DOMDocument 而不是正则：`<env>` 的**属性顺序、缩进、换行、自闭合写法**
     * 都只是排版（php-cs-fixer / IDE 重排一次就变），而 name / value / force 是**结构**。
     * 一旦正则把 `name="…" value="…" force="true"` 的顺序写死，改个属性顺序就能让守卫
     * 静默放行 —— 那正是「检查器通过 ≠ 内容正确」。
     * （`scripts/check-phpunit-config.php` 同样用 DOMDocument，两边口径一致。）
     *
     * @return array<string, array{value:string, force:bool, forceRaw:?string, line:int}>
     */
    private static function envDeclarations(string $relativePath): array
    {
        if (array_key_exists($relativePath, self::$declarationCache)) {
            return self::$declarationCache[$relativePath];
        }

        $absolute = self::repoPath($relativePath);
        self::assertFileExists(
            $absolute,
            "{$relativePath} 不存在：它是 phpunit 配置门禁链（check-phpunit-config 检查 A/C）的一份，不能缺失"
        );

        $xml = file_get_contents($absolute);
        self::assertIsString($xml, "{$relativePath} 无法读取，本守卫无法判断限流阈值是否被钉住");

        // 关闭外部实体加载（LIBXML_NONET）：与 check-phpunit-config.php 保持同一种解析姿势
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue(
            $loaded,
            "{$relativePath} 不是合法 XML（解析失败）：配置被改坏时应当先修好它，"
            . '而不是让本守卫在「读不到」的状态下给出结论'
        );

        $declarations = [];
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('/phpunit/php/env') ?: [] as $env) {
            if (!$env instanceof DOMElement) {
                continue;
            }
            // force 的语义与 PHPUnit 的 Xml\Loader::booleanFromString() 一致：
            // 只有 "true"（忽略大小写）才算强制，1 / yes / 带空格一律回落 false。
            $forceRaw = $env->hasAttribute('force') ? $env->getAttribute('force') : null;
            $declarations[$env->getAttribute('name')] = [
                'value'    => $env->getAttribute('value'),
                'force'    => $forceRaw !== null && strtolower(trim($forceRaw)) === 'true',
                'forceRaw' => $forceRaw,
                'line'     => $env->getLineNo(),
            ];
        }

        self::$declarationCache[$relativePath] = $declarations;

        return $declarations;
    }

    // -------------------------------------------------------------------
    // 违规收集
    // -------------------------------------------------------------------

    /**
     * 检查某个变量在三份配置里都被 force 住
     *
     * @param string $why 没 force 住时的后果说明（拼进违规信息）
     * @return array<int, string>
     */
    private static function collectForcedViolations(string $envName, string $why): array
    {
        $violations = [];

        foreach (self::PHPUNIT_CONFIGS as $config) {
            $declarations = self::envDeclarations($config);
            if (!array_key_exists($envName, $declarations)) {
                $violations[] = sprintf(
                    '%s 缺少 <env name="%s" value="…" force="true"/>：%s',
                    $config,
                    $envName,
                    $why
                );

                continue;
            }

            $env = $declarations[$envName];
            if ($env['force']) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d 的 %s 没有带 force="true"（实际 force=%s）：%s',
                $config,
                $env['line'],
                $envName,
                $env['forceRaw'] === null ? '（未声明该属性）' : '"' . $env['forceRaw'] . '"',
                $why
            );
        }

        return $violations;
    }

    /**
     * 检查某个变量的取值是否达到下限
     *
     * @return array<int, string>
     */
    private static function collectBoundViolations(string $envName, float $minimum): array
    {
        $violations = [];
        $unit = str_ends_with($envName, '_RATE') ? '补充速率' : '桶容量';

        foreach (self::PHPUNIT_CONFIGS as $config) {
            $declarations = self::envDeclarations($config);
            if (!array_key_exists($envName, $declarations)) {
                // 缺失由 R1 负责报；这里只管「写了但写小了」，避免同一个问题报两遍
                continue;
            }

            $env = $declarations[$envName];
            if (!is_numeric($env['value'])) {
                $violations[] = sprintf(
                    '%s:%d 的 %s="%s" 不是数字，无法判断它的%s够不够大',
                    $config,
                    $env['line'],
                    $envName,
                    $env['value'],
                    $unit
                );

                continue;
            }

            if ((float) $env['value'] >= $minimum) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d 的 %s=%s 低于下限 %s：%s过低时，整条 Feature 套件共用的 scope 桶'
                . '（CI 上所有请求的客户端 IP 都是 0.0.0.0）会在几十秒内被打空，之后级联 429 ——'
                . '这正是本守卫要防的那次事故（调参可以往大调，不要往回调到会打空的量级）',
                $config,
                $env['line'],
                $envName,
                $env['value'],
                self::formatNumber($minimum),
                $unit
            );
        }

        return $violations;
    }

    /**
     * 变量「没被 force 住」时的后果说明（按后缀区分，因为两条分支读的不是同一组参数）
     */
    private static function consequence(string $envName): string
    {
        $suffix = substr($envName, strrpos($envName, '_') + 1);

        return match ($suffix) {
            'CAPACITY', 'RATE' => 'token_bucket 分支读的是 **scope 级** capacity/rate'
                . '（app/middleware/RateLimit.php:227-228 的 `$rule[\'capacity\'] ?? $tbCfg[\'capacity\']`），'
                . 'scope 只要声明了这两个键，RATELIMIT_TB_CAPACITY / RATELIMIT_TB_RATE 就永远轮不到；'
                . '不 force 住它，测试就跑在配置默认的小桶上（ip 默认 capacity=100 / rate=10），'
                . 'CI 上几十秒就把桶打空',
            'LIMIT', 'PERIOD' => 'fixed_window 分支读的是 scope 级 limit/period'
                . '（app/middleware/RateLimit.php:137-138），而 RATELIMIT_ALGORITHM 一旦被外部环境切换，'
                . '这组参数就是唯一的闸门；不 force 住它，「本不打算测限流」的用例会随机 429，'
                . '「应该 429」的断言又可能永远不成立',
            default => '该变量决定限流走哪条分支、阈值取哪一组，不 force 住时结论随机器漂移',
        };
    }

    /**
     * 把下限渲染成人读得懂的形式（10000.0 → 10000，1000.5 → 1000.5）
     */
    private static function formatNumber(float $value): string
    {
        return $value === floor($value) ? (string) (int) $value : (string) $value;
    }

    /**
     * 把违规列表拼成一条可读的断言消息（空列表时返回空串，避免通过时也刷一段说明）
     *
     * @param array<int, string> $violations
     */
    private static function summarize(string $code, array $violations): string
    {
        if ($violations === []) {
            return '';
        }

        return sprintf(
            '%s 共 %d 条违规：' . PHP_EOL . ' - %s',
            $code,
            count($violations),
            implode(PHP_EOL . ' - ', $violations)
        );
    }
}
