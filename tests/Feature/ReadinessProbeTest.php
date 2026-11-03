<?php

declare(strict_types=1);

namespace Tests\Feature;

use app\middleware\HealthProbeBypass;
use Tests\Support\CliProcess;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 探针链路测试：liveness / readiness / 旁路匹配 | Health probe tests
 *
 * 本文件覆盖三类**只有真跑起来才能确认**的结论：
 *
 *   1. **旁路匹配严格性**：只有 pathinfo 全等于 `healthz` / `readyz` 的 GET/HEAD 才被
 *      `HealthProbeBypass` 接管；`/healthz/`、`/healthz/x`、`/HEALTHZ` 都不接管。
 *      断言方式是「不看状态码，看旁路是否生效」：旁路命中时 `SessionInit` 与 `AccessLog`
 *      都不执行（无 `Set-Cookie`、无访问日志行），近似路径两者都会执行。
 *      —— 这比断状态码更硬：ThinkPHP 的路由本身是宽松的（`complete_match=false` +
 *      `url_convert`），近似路径照样能命中控制器，所以状态码根本区分不出「旁路」与「路由」。
 *
 *   2. **依赖故障下的 liveness**：把 session 用的 Redis 指到 `127.0.0.1:1` 之后
 *      `/healthz` 仍 **200**。这条必须在**独立进程**里做（理由见
 *      `tests/Support/health-probe-client.php` 的文件注释），并且必须有对照组：
 *      同一个进程环境里普通路由（`/think`）会 500 —— 否则「200」可能只是「其实没坏」。
 *
 *   3. **readiness 的独立检查**：Redis 挂 → 503 且 `checks.redis.ok=false`、
 *      **同时** `checks.database.ok=true`；DB 挂 → 503 且 `checks.database.ok=false`、
 *      **同时** `checks.redis.ok=true`。双向都要有，才能证明「一项失败不影响其它项」，
 *      而不是「遇到第一个失败就返回」。
 *
 * @package Tests\Feature
 */
class ReadinessProbeTest extends ThinkPHPTestCase
{
    /**
     * 探针路径（不带前导斜杠，与 `Request::pathinfo()` 一致）
     */
    private const LIVENESS_PATH = 'healthz';

    /**
     * readiness 路径
     */
    private const READINESS_PATH = 'readyz';

    /**
     * 子进程探针客户端（真实进程，见文件头注释）
     */
    private const PROBE_CLIENT = 'tests/Support/health-probe-client.php';

    protected function setUp(): void
    {
        parent::setUp();

        // 确保 App 已完成 initialize()：路由文件/配置都要在「已加载」的前提下才谈得上覆盖
        if (!$this->app()->initialized()) {
            $this->app()->initialize();
        }

        // 让 runHttp() 走项目自己的 Request（tenantId / traceId 等上下文方法都在它上面）
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 注册路由（include_once：同进程只注册一次）
        include_once $this->app()->getRootPath() . 'route/app.php';

        // App::initialize() 会注册框架自己的 error/exception handler（think\initializer\Error）。
        // `ThinkPHPTestCase::runHttp()` 在**第一次**发请求后会还原它们；但本类的第一个用例
        // 是纯静态的匹配规则测试（不发请求），不还原就会让 PHPUnit 报 risky。
        // 这里在初始化后立刻补一次（与 runHttp 完全同一手法，全进程只做一次）。
        if (!self::$errorHandlersRestored) {
            restore_error_handler();
            restore_exception_handler();
            self::$errorHandlersRestored = true;
        }
    }

    // -----------------------------------------------------------------------
    // 1. 旁路匹配严格性
    // -----------------------------------------------------------------------

    /**
     * `matchProbePath()` 只接受**全等**的精确路径，且只接受 GET / HEAD
     *
     * 表驱动而不是只发几个 HTTP 请求：匹配规则是安全边界（旁路会跳过 AccessLog 与
     * RateLimit），必须把近似路径一次性列全，将来谁把 `===` 改成 `str_starts_with`
     * 或加了 `strtolower`，这里立刻红。
     */
    public function testProbePathMatcherOnlyAcceptsExactPaths(): void
    {
        // 允许：全等 + GET/HEAD
        $this->assertSame(HealthProbeBypass::LIVENESS_PATH, HealthProbeBypass::matchProbePath('healthz', 'GET'));
        $this->assertSame(HealthProbeBypass::LIVENESS_PATH, HealthProbeBypass::matchProbePath('healthz', 'HEAD'));
        $this->assertSame(HealthProbeBypass::READINESS_PATH, HealthProbeBypass::matchProbePath('readyz', 'GET'));
        $this->assertSame(HealthProbeBypass::READINESS_PATH, HealthProbeBypass::matchProbePath('readyz', 'HEAD'));

        // 拒绝：尾斜杠 / 子路径 / 大小写 / 前缀扩展 / 空白 / 空路径 / 方法不符
        $rejected = [
            ['pathinfo' => 'healthz/', 'method' => 'GET', 'why' => '尾斜杠'],
            ['pathinfo' => 'healthz/x', 'method' => 'GET', 'why' => '子路径'],
            ['pathinfo' => 'healthz/', 'method' => 'HEAD', 'why' => '尾斜杠（HEAD）'],
            ['pathinfo' => 'HEALTHZ', 'method' => 'GET', 'why' => '大写'],
            ['pathinfo' => 'Healthz', 'method' => 'GET', 'why' => '混合大小写'],
            ['pathinfo' => 'healthzanything', 'method' => 'GET', 'why' => '前缀扩展（前缀匹配会中招）'],
            ['pathinfo' => 'healthz-v2', 'method' => 'GET', 'why' => '前缀扩展（短横线）'],
            ['pathinfo' => ' healthz', 'method' => 'GET', 'why' => '前导空白'],
            ['pathinfo' => 'healthz ', 'method' => 'GET', 'why' => '尾随空白'],
            ['pathinfo' => 'readyz/', 'method' => 'GET', 'why' => '尾斜杠（readyz）'],
            ['pathinfo' => 'readyz/x', 'method' => 'GET', 'why' => '子路径（readyz）'],
            ['pathinfo' => 'READYZ', 'method' => 'GET', 'why' => '大写（readyz）'],
            ['pathinfo' => '', 'method' => 'GET', 'why' => '空路径'],
            ['pathinfo' => '/', 'method' => 'GET', 'why' => '根路径'],
            ['pathinfo' => 'v1/healthz', 'method' => 'GET', 'why' => '被包在业务前缀下'],
            ['pathinfo' => 'healthz', 'method' => 'POST', 'why' => '方法不符'],
            ['pathinfo' => 'healthz', 'method' => 'PUT', 'why' => '方法不符'],
            ['pathinfo' => 'healthz', 'method' => 'DELETE', 'why' => '方法不符'],
            ['pathinfo' => 'healthz', 'method' => 'OPTIONS', 'why' => '方法不符（预检由 Cors 处理）'],
            ['pathinfo' => 'readyz', 'method' => 'POST', 'why' => '方法不符（readyz）'],
        ];

        foreach ($rejected as $case) {
            $this->assertNull(
                HealthProbeBypass::matchProbePath($case['pathinfo'], $case['method']),
                sprintf('探针匹配必须严格拒绝 pathinfo=「%s」method=%s（%s）', $case['pathinfo'], $case['method'], $case['why'])
            );
        }
    }

    /**
     * HTTP 层的 A/B：精确路径被旁路接管，近似路径不被接管
     *
     * 判据是响应头 `X-Health-Probe-Bypass`（只有旁路分支会加，见中间件类注释）：
     *   - 精确路径：**有**该头 + 200 + `no-store`；
     *   - 近似路径：**没有**该头 —— 说明请求走了完整中间件链（`SessionInit` 等），
     *     只是 ThinkPHP 的路由本身宽松（`complete_match=false` + `url_convert`），
     *     所以它仍然能命中同一个控制器并返回 200。
     *
     * 为什么不用「访问日志行数」做判据：实测在 macOS bind mount 上
     * `count(file(...))` 读到的是**滞后**内容（同一进程刚追加的行不一定立刻可见），
     * 用它做断言会得到假失败/假通过。响应头是进程内的确定值，没有这个问题。
     *
     * ⚠️ 近似路径的 200 是**路由**的既有行为，与本轮改动无关；这里把它一起断言，
     * 是为了让「路由变严（变 404）」或「旁路被写宽（带上 BYPASS_HEADER）」都必须
     * 有人显式处理，而不是悄悄漂移。
     */
    public function testOnlyExactProbePathsAreBypassed(): void
    {
        $exactPaths = ['/' . self::LIVENESS_PATH, '/' . self::READINESS_PATH];

        foreach ($exactPaths as $path) {
            $response = $this->probe($path);

            $this->assertSame(200, $response->getCode(), $path . ' 响应体：' . (string) $response->getContent());
            $this->assertNotSame(
                '',
                (string) $response->getHeader(HealthProbeBypass::BYPASS_HEADER),
                $path . ' 必须由 HealthProbeBypass 直接产出（缺少旁路标记头说明中间件没命中）'
            );
            $this->assertStringContainsString('no-store', (string) $response->getHeader('Cache-Control'));
        }

        $nearMissPaths = [
            '/' . self::LIVENESS_PATH . '/',
            '/' . self::LIVENESS_PATH . '/x',
            '/HEALTHZ',
            '/' . self::READINESS_PATH . '/',
            '/READYZ',
        ];

        foreach ($nearMissPaths as $path) {
            $response = $this->probe($path);

            $this->assertSame(
                '',
                (string) $response->getHeader(HealthProbeBypass::BYPASS_HEADER),
                $path . ' 不是探针路径，**不得**被旁路命中（否则等于给攻击者一块跳过 AccessLog/RateLimit 的地毯）'
            );
            $this->assertSame(200, $response->getCode(), $path . ' 响应体：' . (string) $response->getContent());
        }
    }

    // -----------------------------------------------------------------------
    // 2. 依赖故障：真实进程证据
    // -----------------------------------------------------------------------

    /**
     * **Redis 不可达时 `/healthz` 仍 200**（本轮核心）
     *
     * 子进程环境：session 的 Redis 被指到 `127.0.0.1:1`（真实 phpredis 连接 → 真实
     * `Connection refused`）。对照组用 `/think`：同一进程环境里它必须 500 ——
     * 没有这条，「/healthz 200」无法排除「其实 Redis 是好的」。
     */
    public function testHealthzStaysHealthyWhenRedisIsUnreachable(): void
    {
        $live = $this->runChildProbe(self::LIVENESS_PATH, 'redis_down');

        $this->assertSame(
            200,
            $live['status'],
            'Redis 不可达时 liveness 必须仍是 200。实际响应：' . json_encode($live['json'], JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame('ok', $live['json']['data']['status'] ?? null);
        $this->assertSame(0, $live['json']['code'] ?? null);
        $this->assertSame('ok', $live['json']['message'] ?? null);
        $this->assertNotEmpty($live['json']['trace_id'] ?? null, '旁路后仍要带 trace_id（Trace 在旁路之前执行）');
        $this->assertStringContainsString('no-store', (string) $live['cache_control']);
        $this->assertSame('healthz', (string) $live['bypass_header'], 'liveness 响应必须由旁路直接产出（SessionInit 不得执行）');

        // 对照组：证明这个子进程里的 Redis 是真的不可达
        $control = $this->runChildProbe('think', 'redis_down');
        $this->assertSame(
            500,
            $control['status'],
            '对照组失败：同一环境下普通路由 /think 必须 500（否则「/healthz 200」不能证明探针免疫 Redis 故障）'
            . '，实际响应：' . json_encode($control['json'], JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * **DB 不可达时 `/healthz` 仍 200**
     *
     * liveness 本来就不该碰数据库；这里用真实进程钉死它，防止将来有人为了「顺手查一下」
     * 给 `index()` 加上 Db 调用（同进程的零 SQL 断言在 `HealthzProbeTest` 里）。
     */
    public function testHealthzStaysHealthyWhenDatabaseIsUnreachable(): void
    {
        $live = $this->runChildProbe(self::LIVENESS_PATH, 'db_down');

        $this->assertSame(
            200,
            $live['status'],
            '数据库不可达时 liveness 必须仍是 200。实际响应：' . json_encode($live['json'], JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame('ok', $live['json']['data']['status'] ?? null);

        // 证明该子进程里数据库是真的不可达（同一环境的 readiness 必须报 database 失败）
        $ready = $this->runChildProbe(self::READINESS_PATH, 'db_down');
        $this->assertFalse(
            $ready['json']['data']['checks']['database']['ok'] ?? true,
            '对照组失败：同一环境下 /readyz 的 database 检查必须失败，否则「/healthz 200」不构成证据'
        );
    }

    /**
     * `/readyz`：Redis 挂 → 503，且**同时**报出 database 正常
     *
     * 这条同时回答了两个问题：
     *   - readiness 不会被 SessionInit 提前打成 500（否则拿不到逐项结果）；
     *   - 各项检查互相独立（database 没有因为 redis 失败而被跳过）。
     */
    public function testReadyzReportsRedisOutageWithoutFailingOtherChecks(): void
    {
        $ready = $this->runChildProbe(self::READINESS_PATH, 'redis_down');
        $body  = (array) ($ready['json'] ?? []);
        $data  = (array) ($body['data'] ?? []);
        $checks = (array) ($data['checks'] ?? []);

        $this->assertSame(503, $ready['status'], 'Redis 不可达时 readiness 必须是 503。实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertSame(503, $body['code'] ?? null);
        $this->assertSame('degraded', $body['message'] ?? null);
        $this->assertSame('degraded', $data['status'] ?? null);

        $this->assertFalse($checks['redis']['ok'] ?? true, 'checks.redis.ok 必须为 false');
        $this->assertStringContainsString(
            'redis connect 127.0.0.1:1 failed',
            (string) ($checks['redis']['error'] ?? ''),
            'checks.redis.error 应说明连接不可达（实际：' . (string) ($checks['redis']['error'] ?? '') . '）'
        );
        $this->assertTrue($checks['database']['ok'] ?? false, 'Redis 失败**不得**影响数据库检查（各项必须独立执行）');
        $this->assertTrue($checks['config']['ok'] ?? false, 'Redis 失败**不得**影响配置检查');

        $this->assertStringContainsString('no-store', (string) $ready['cache_control']);
        $this->assertSame(
            'readyz',
            (string) $ready['bypass_header'],
            '/readyz 必须由旁路（session-exempt 分支）产出 —— 否则 Redis 一挂它就成了 500，拿不到逐项结果'
        );
    }

    /**
     * `/readyz`：DB 挂 → 503，且**同时**报出 redis 正常（反方向同样独立）
     */
    public function testReadyzReportsDatabaseOutageWithoutFailingOtherChecks(): void
    {
        $ready  = $this->runChildProbe(self::READINESS_PATH, 'db_down');
        $body   = (array) ($ready['json'] ?? []);
        $checks = (array) ($body['data']['checks'] ?? []);

        $this->assertSame(503, $ready['status'], '数据库不可达时 readiness 必须是 503。实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertSame('degraded', $body['data']['status'] ?? null);
        $this->assertFalse($checks['database']['ok'] ?? true, 'checks.database.ok 必须为 false');
        $this->assertStringContainsString(
            'SQLSTATE',
            (string) ($checks['database']['error'] ?? ''),
            'checks.database.error 应是 PDO 连接错误（实际：' . (string) ($checks['database']['error'] ?? '') . '）'
        );
        $this->assertTrue($checks['redis']['ok'] ?? false, '数据库失败**不得**影响 Redis 检查（各项必须独立执行）');
    }

    // -----------------------------------------------------------------------
    // 3. readiness 的正常路径与「只读」边界
    // -----------------------------------------------------------------------

    /**
     * 依赖都正常时 `/readyz` 返回 200 + 逐项结果
     */
    public function testReadyzReturnsOkWhenAllChecksPass(): void
    {
        $response = $this->probe('/' . self::READINESS_PATH);

        $this->assertSame(200, $response->getCode(), '响应体：' . (string) $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->getHeader('Cache-Control'));

        $body   = json_decode((string) $response->getContent(), true);
        $checks = $body['data']['checks'] ?? [];

        $this->assertIsArray($body);
        $this->assertSame(0, $body['code'] ?? null);
        $this->assertSame('ok', $body['data']['status'] ?? null);
        $this->assertTrue($checks['database']['ok'] ?? false);
        $this->assertTrue($checks['redis']['ok'] ?? false);
        $this->assertTrue($checks['config']['ok'] ?? false);
        $this->assertIsNumeric($checks['database']['latency_ms'] ?? null, '每项都应给出独立耗时');
        $this->assertIsNumeric($checks['redis']['latency_ms'] ?? null);
        $this->assertSame(1, $checks['config']['default_tenant_id'] ?? null, 'phpunit.xml 里钉死 DEFAULT_TENANT_ID=1');
        $this->assertNotEmpty($body['trace_id'] ?? null);

        // /readyz 也走旁路（不是路由兜底）
        $this->assertSame('readyz', (string) $response->getHeader(HealthProbeBypass::BYPASS_HEADER));
    }

    /**
     * `/readyz` 只发出**一条只读语句**（`SELECT 1`），不产生查询噪音
     *
     * 与 `/healthz` 的「零条 SQL」不同，readiness **本来就要查库** —— 这里锁定的不是
     * 「不查」，而是「只查一条 `SELECT 1`」：没有业务表、没有写操作、没有多余往返。
     */
    public function testReadyzIssuesOnlyASingleReadonlyStatement(): void
    {
        $statements = [];
        $manager    = $this->app()->make('db');

        // 快照/还原监听器列表：`Db::listen()` 只增不删，不还原会跨用例累积
        $listenProperty = new \ReflectionProperty($manager, 'listen');
        $listenProperty->setAccessible(true);
        $baselineListeners = $listenProperty->getValue($manager);

        Db::listen(static function ($sql) use (&$statements): void {
            $statements[] = (string) $sql;
        });

        try {
            // 对照组：证明收集器确实在工作（否则「只有一条」可能只是没挂上）
            Db::table('users')->where('id', '>', 0)->limit(1)->select();
            $this->assertNotEmpty($statements, 'SQL 监听器没有收到任何语句，说明本用例的观测手段失效');

            $statements = [];
            $response   = $this->probe('/' . self::READINESS_PATH);
            $duringProbe = $statements;
        } finally {
            $listenProperty->setValue($manager, $baselineListeners);
        }

        $this->assertSame(200, $response->getCode(), '响应体：' . (string) $response->getContent());

        // 只保留真正的业务语句（连接事件形如 `CONNECT:mysql`，不计入「查询噪音」）
        $queries = [];
        foreach ($duringProbe as $statement) {
            if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $statement) === 1) {
                $queries[] = trim((string) preg_replace('/\s+/', ' ', $statement));
            }
        }

        $this->assertSame(
            ['SELECT 1'],
            $queries,
            'readiness 只允许发出 SELECT 1，实际：' . json_encode($queries, JSON_UNESCAPED_UNICODE)
        );
    }

    // -----------------------------------------------------------------------
    // 辅助方法
    // -----------------------------------------------------------------------

    /**
     * 发一次请求（pathinfo 不带前导斜杠：框架拿它和路由规则的 rule 直接比较）
     *
     * @param string $path 路径（带前导斜杠）
     * @return \think\Response
     */
    private function probe(string $path)
    {
        $request = new \app\Request();
        $request->setMethod('GET');
        $request->setPathinfo(ltrim($path, '/'));

        return $this->runHttp($request);
    }

    /**
     * 在**独立 PHP 进程**里发一次探针请求，返回 `PROBE_RESULT` 解析后的数组
     *
     * @param string $path     请求路径（不带前导斜杠）
     * @param string $scenario 故障场景：none / redis_down / db_down
     * @return array{path: string, scenario: string, status: int, content_type: string, cache_control: string, trace_id_header: string, session_cookie: string, bypass_header: string, json: array<string, mixed>|null, raw_length: int}
     */
    private function runChildProbe(string $path, string $scenario): array
    {
        $result = CliProcess::phpScript(
            $this->app()->getRootPath() . self::PROBE_CLIENT,
            [$path, $scenario]
        );

        $this->assertTrue(
            $result->succeeded(),
            sprintf("探针子进程失败（exit=%d）：\n%s", $result->exitCode, $result->output)
        );

        $payload = null;
        foreach (explode("\n", $result->output) as $line) {
            if (str_starts_with($line, 'PROBE_RESULT=')) {
                $payload = json_decode(substr($line, strlen('PROBE_RESULT=')), true);
            }
        }

        $this->assertIsArray($payload, "子进程没有输出可解析的 PROBE_RESULT：\n" . $result->output);

        return $payload;
    }
}
