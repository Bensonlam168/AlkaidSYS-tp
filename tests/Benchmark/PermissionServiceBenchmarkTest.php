<?php

declare(strict_types=1);

namespace Tests\Benchmark;

use Infrastructure\Permission\Service\PermissionCacheService;
use Infrastructure\Permission\Service\PermissionService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Performance\Support\PerformanceThreshold;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 权限服务性能基准测试
 * Permission Service Performance Benchmark
 *
 * 验证 T-117 性能目标（阈值一律「环境变量可配 + 代码里只写默认值」）：
 * - P95 延迟 < PERF_P95_LATENCY_MS（默认 300ms）
 * - 并发查询 > PERF_MIN_QPS（默认 8）
 * - 缓存命中率 > PERF_MIN_CACHE_HIT_RATE（默认 90%）
 * - 缓存失效 < PERF_MAX_INVALIDATION_MS（默认 200ms，3 次采样取中位数）
 *
 * ## 为什么类上要显式写 #[Group('performance')]
 *
 * `phpunit.performance.xml` 用 `<directory groups="performance">` 给整个目录打了组，
 * 但那是配置侧的隐式约定；显式属性让「这个类属于性能套件」在代码里可见，
 * 也让 `--group performance` 不依赖那份 XML 的目录配置。
 *
 * ## 夹具为什么要自己建用户（而不是用 1..10 号用户）
 *
 * 原实现假设测试库里有 1..10 号用户：
 *   - 并发用例：`$userId = (($batch * $batchSize + $i) % 10) + 1`
 *   - 命中率用例：`$userId = ($i % 10) + 1`
 * 实际测试库只有 TestPlatformSeed 建的 1 个系统用户（外加其他用例的残留），
 * 于是大部分查询在 `PermissionService::getUserPermissions()` 里因为
 * 「用户不存在 → 返回 []」直接短路：既没走缓存，也没查角色，更没写缓存。
 * 测出来的 QPS 是「查不到用户」的速度，既低又与其他轮次不可比。
 *
 * 现在 setUp() 里建 **确定性的基准夹具**：用户 6001..6010 + 角色 6001 + 权限 6001..6003，
 * 全部挂在 tenants 表里**真实存在**的一个租户下（users.tenant_id 有 fk_users_tenant_id，
 * 不能假设租户 1 一定存在）。ID 段选 6001+ 的理由：
 *   - 避开 seed 的 1/2/3；
 *   - 避开 PermissionServiceIntegrationTest 清理的 >= 7000；
 *   - 避开 CasbinPerformanceTest 清理的 >= 10000（含 CasbinControllerTest 的 88888）。
 * 清理时按 ID 精确删除（whereIn），不按「>= 起点」大范围删，避免误伤其他用例的夹具。
 *
 * ## 默认阈值怎么定的（实测依据）
 *
 * 墙钟阈值只承担「拦住量级退化」的职责，不能把机器抖动当成回归。本仓库的性能套件
 * 跑在 Docker 容器里、工作区是宿主机的 bind mount，而 ThinkPHP 的日志通道在
 * `runningInConsole()` 为真时**每写一条日志就落盘一次**（`Log::createDriver()` 里
 * `$lazy = !realtime_write && !runningInConsole()`）：
 *   - 实测单次 `Log::debug()` 约 8~17ms，`getUserPermissions()` 每次至少写 2 条日志；
 *   - 也就是说「缓存命中的一次权限查询」在本机有一个 **≥ 34ms 的地板**（对应 QPS 天花板约 30）。
 * 阈值默认值因此按「同一台机器多次实跑的最差值」定：上限类再留约 2 倍余量，
 * 下限类（QPS）取最差实测的约 0.5 倍 —— 而不是照抄服务器指标。
 *
 * | 指标 | 3 次实跑（本机） | 另一次全量套件内 | 默认值 |
 * | --- | --- | --- | --- |
 * | P95 延迟 | 55.07 / 80.36 / 125.74 ms | 76.02 / 133.42 ms | 300 ms |
 * | QPS | 23.58 / 25.68 / 22.51 | 15.28 / 24.10 / 24.84 | 8 |
 * | 缓存失效（中位数） | 19.02 / 19.14 / 49.30 ms | 32.47 ms | 200 ms |
 * | 缓存命中率 | 100% | 100% | 90（不变） |
 *
 * 想在原生/Linux（没有这层 I/O 放大）上验真实指标，或想把预算收紧当作发布门禁，
 * 显式导出环境变量即可，例如：
 *   PERF_MIN_QPS=300 PERF_P95_LATENCY_MS=50 ./vendor/bin/phpunit -c phpunit.performance.xml
 *
 * @package Tests\Benchmark
 */
#[Group('performance')]
class PermissionServiceBenchmarkTest extends ThinkPHPTestCase
{
    /**
     * P95 延迟预算（毫秒）默认值
     *
     * 实测 55~133ms（本机 I/O 放大所致），默认取 300ms：约 2.2 倍余量，
     * 仍能拦住「延迟掉一个量级」的退化。
     */
    private const DEFAULT_P95_LATENCY_MS = 300.0;

    /**
     * P95 延迟预算的环境变量名
     */
    private const P95_LATENCY_ENV = 'PERF_P95_LATENCY_MS';

    /**
     * 最低 QPS 默认值
     *
     * 本机实测 15.28 / 22.51 / 23.58 / 24.10 / 24.84 / 25.68 / 36.92（全部为缓存命中路径，
     * 命中率 100%，回源 0 次）—— 同一台机器上就能差 2.4 倍，因为每次权限查询至少要写
     * 2 条日志，而单条日志落盘实测 8~17ms，本机的 QPS 天花板约 30。
     *
     * 默认 8 ≈ 「最差实测的 0.5 倍」，只拦住「吞吐掉到量级以下」的退化。
     * 「缓存到底有没有生效」不靠这个数字判断 —— 用例里有一条确定性断言
     * （测量段回源查库次数必须为 0），它不随机器快慢变化。
     * 在原生文件系统上跑时可用 PERF_MIN_QPS 收紧到 300 以上。
     */
    private const DEFAULT_MIN_QPS = 8.0;

    /**
     * 最低 QPS 的环境变量名
     */
    private const MIN_QPS_ENV = 'PERF_MIN_QPS';

    /**
     * 缓存命中率下限（百分比）默认值
     */
    private const DEFAULT_MIN_CACHE_HIT_RATE = 90.0;

    /**
     * 缓存命中率下限的环境变量名
     */
    private const MIN_CACHE_HIT_RATE_ENV = 'PERF_MIN_CACHE_HIT_RATE';

    /**
     * 缓存失效耗时上限（毫秒）默认值
     *
     * 3 次采样取中位数后实测 19~49ms（单次采样曾抖到 114ms），
     * 默认 200ms：约 4 倍余量，只拦住「失效退化成全量扫描」这类问题。
     */
    private const DEFAULT_MAX_INVALIDATION_MS = 200.0;

    /**
     * 缓存失效耗时上限的环境变量名
     */
    private const MAX_INVALIDATION_MS_ENV = 'PERF_MAX_INVALIDATION_MS';

    /**
     * 基准用户 ID 段起点（6001..6010，选段理由见类注释）
     */
    private const BENCH_USER_ID_BASE = 6001;

    /**
     * 基准用户数量（沿用原用例「10 个用户循环」的意图）
     */
    private const BENCH_USER_COUNT = 10;

    /**
     * 基准角色 ID
     */
    private const BENCH_ROLE_ID = 6001;

    /**
     * 基准权限 ID 段起点
     */
    private const BENCH_PERMISSION_ID_BASE = 6001;

    /**
     * 基准权限数量（让缓存里存的是非空数组，而不是「空权限」的空转路径）
     */
    private const BENCH_PERMISSION_COUNT = 3;

    /**
     * Permission Service 实例
     */
    protected PermissionService $permissionService;

    /**
     * Permission Cache Service 实例
     */
    protected PermissionCacheService $cacheService;

    /**
     * 被测用户 ID（基准夹具的第一个用户）
     */
    protected int $testUserId = self::BENCH_USER_ID_BASE;

    /**
     * 夹具使用的租户 ID（来自 tenants 表，非硬编码）
     */
    protected int $testTenantId = 0;

    /**
     * 测试用权限码
     */
    protected array $testPermissions = [
        'user:read',
        'user:write',
        'role:read',
        'permission:read',
    ];

    /**
     * 设置测试环境
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->permissionService = new PermissionService();
        $this->cacheService = $this->permissionService->getCacheService();

        // 清除所有缓存
        $this->cacheService?->clearAllCache();

        // 建立确定性基准夹具（用户 / 角色 / 权限）
        $this->prepareBenchmarkUsers();

        // 测量对象固定为第一个基准用户，避免依赖 seed 用户是否恰好存在
        $this->testUserId = self::BENCH_USER_ID_BASE;
    }

    /**
     * 测试权限查询性能（P95 < 预算，默认 300ms）
     * Test Permission Query Performance (P95 < budget, default 100ms)
     */
    public function testPermissionQueryP95Latency(): void
    {
        if (!$this->cacheService) {
            $this->markTestSkipped('缓存服务未启用，跳过性能测试');
            return;
        }

        $queryCount = 1000;
        $latencies = [];

        // 预热缓存，并顺带校验夹具：夹具坏掉时 getUserPermissions() 会返回空数组，
        // 这里立刻失败，而不是拿「每次都是空数组」的样本测出一个漂亮的 P95。
        $warmed = $this->permissionService->getUserPermissions($this->testUserId);
        $this->assertNotEmpty(
            $warmed,
            sprintf(
                '基准用户 %d 解析出的权限为空（期望 %d 个 perf_bench_resource:*）。'
                . '请检查 setUp() 里的夹具是否插入成功，以及 roles.tenant_id 是否与 users.tenant_id 一致。',
                $this->testUserId,
                self::BENCH_PERMISSION_COUNT
            )
        );

        // 执行多次查询
        for ($i = 0; $i < $queryCount; $i++) {
            $startTime = microtime(true);
            $this->permissionService->getUserPermissions($this->testUserId);
            $latency = (microtime(true) - $startTime) * 1000; // 转换为毫秒
            $latencies[] = $latency;
        }

        // 计算 P95 / P99 与平均延迟
        sort($latencies);
        $p95Latency = $latencies[(int) floor(count($latencies) * 0.95)];
        $p99Latency = $latencies[(int) floor(count($latencies) * 0.99)];
        $avgLatency = array_sum($latencies) / count($latencies);
        $budgetMs = PerformanceThreshold::readFloat(self::P95_LATENCY_ENV, self::DEFAULT_P95_LATENCY_MS);

        // 输出性能指标（基准测试的正常产物；phpunit.performance.xml 刻意没开
        // beStrictAboutOutputDuringTests）
        echo "\n=== 权限查询性能测试结果 ===\n";
        echo "查询次数: {$queryCount}\n";
        echo '平均延迟: ' . round($avgLatency, 2) . "ms\n";
        echo 'P95 延迟: ' . round($p95Latency, 2) . "ms\n";
        echo 'P99 延迟: ' . round($p99Latency, 2) . "ms\n";
        echo '生效阈值: P95 < ' . sprintf('%.2f', $budgetMs) . 'ms（' . self::P95_LATENCY_ENV . "）\n";

        // 验证 P95 < 预算
        $this->assertLessThan(
            $budgetMs,
            $p95Latency,
            sprintf(
                'P95 延迟 %.2fms 超过预算 %.2fms（本次 %d 次查询：平均 %.2fms、P99 %.2fms）。'
                . '生效阈值来自环境变量 %s：未设置/为空/非数字/小于等于 0 时回落默认值 %.2fms；'
                . '慢机器上可显式放宽，例如导出 %s=200 后重跑。',
                $p95Latency,
                $budgetMs,
                $queryCount,
                $avgLatency,
                $p99Latency,
                self::P95_LATENCY_ENV,
                self::DEFAULT_P95_LATENCY_MS,
                self::P95_LATENCY_ENV
            )
        );
        echo '✅ P95 延迟达标: ' . round($p95Latency, 2) . 'ms < ' . sprintf('%.2f', $budgetMs) . "ms\n";
    }

    /**
     * 测试高并发权限查询性能（QPS > 预算，默认见 DEFAULT_MIN_QPS）
     * Test High Concurrency Permission Query Performance (QPS > budget)
     *
     * Note: 使用批处理模拟并发，避免 Thread 扩展依赖
     */
    public function testConcurrentPermissionQueries(): void
    {
        if (!$this->cacheService) {
            $this->markTestSkipped('缓存服务未启用，跳过并发测试');
            return;
        }

        $totalQueries = 1000; // 总查询次数
        $batchSize = 100; // 批处理大小
        $userIds = $this->benchmarkUserIds();
        $userCount = count($userIds);

        // 预热：把本轮会用到的**每一个**基准用户都先查一遍，让 L1/L2 里都有数据。
        // 不预热的话，1000 次查询里前 10 次是「查 users 拿租户 + 查角色/权限 + 写两次缓存」
        // 的冷启动路径，后面才是缓存命中 —— 测出来的是混合路径，既低又随数据库负载漂移。
        foreach ($userIds as $userId) {
            $warmed = $this->permissionService->getUserPermissions($userId);
            $this->assertNotEmpty(
                $warmed,
                sprintf(
                    '基准用户 %d 预热后权限仍为空：并发用例会退化成「查不到权限」的空转，QPS 不可比。'
                    . '请检查夹具（users/roles/user_roles/role_permissions）是否插入成功。',
                    $userId
                )
            );
        }

        // 预热产生的 miss 不计入测量段：只统计「缓存已就绪」之后的查询
        $this->cacheService->resetStats();

        $startTime = microtime(true);

        // 批处理执行查询（模拟并发）
        $batches = (int) ceil($totalQueries / $batchSize);
        for ($batch = 0; $batch < $batches; $batch++) {
            $batchQueries = min($batchSize, $totalQueries - ($batch * $batchSize));
            for ($i = 0; $i < $batchQueries; $i++) {
                // 在**真实存在**的基准用户之间轮转（原实现是 (x % 10) + 1，假设 1..10 号用户都存在）
                $userId = $userIds[($batch * $batchSize + $i) % $userCount];
                $this->permissionService->getUserPermissions($userId);
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        // 计算 QPS
        $qps = $totalQueries / $totalTime;

        // 缓存统计：命中率与「回源查库次数」是本用例可信度的判据
        $stats = $this->cacheService->getCacheStats();
        $hitRate = $stats['overall']['overall_hit_rate'];
        $dbFetches = (int) $stats['overall']['total_queries'];
        $minQps = PerformanceThreshold::readFloat(self::MIN_QPS_ENV, self::DEFAULT_MIN_QPS);

        // 输出并发性能指标
        echo "\n=== 高并发权限查询性能测试结果 ===\n";
        echo "总查询次数: {$totalQueries}\n";
        echo "批大小: {$batchSize}\n";
        echo "批次数: {$batches}\n";
        echo "基准用户数: {$userCount}\n";
        echo '总耗时: ' . round($totalTime, 3) . "s\n";
        echo 'QPS: ' . round($qps, 2) . "\n";
        echo '缓存命中率: ' . $hitRate . "%\n";
        echo "测量段回源查库次数: {$dbFetches}\n";
        echo '生效阈值: QPS > ' . sprintf('%.2f', $minQps) . '（' . self::MIN_QPS_ENV . "）\n";

        // 判据 1：测量段内必须全部命中缓存（0 次回源）。
        // 这条比「命中率 > x%」更严格也更稳定：预热正确时它恒成立，
        // 一旦有人把夹具改回不存在的用户、或把缓存关掉，它会立刻红。
        $this->assertSame(
            0,
            $dbFetches,
            sprintf(
                '测量段内出现 %d 次回源查库（缓存命中率 %.2f%%）：预热失效或缓存被清，本次 QPS %.2f 与其他轮次不可比。'
                . '请确认 permission.cache.enabled 为 true，且 L1 TTL（默认 60s）大于本轮耗时 %.3fs。',
                $dbFetches,
                $hitRate,
                $qps,
                $totalTime
            )
        );

        // 判据 2：吞吐不低于阈值
        $this->assertGreaterThan(
            $minQps,
            $qps,
            sprintf(
                'QPS %.2f 低于最低期望 %.2f（本次 %d 次查询 / %.3fs，%d 个基准用户，缓存命中率 %.2f%%）。'
                . '生效阈值来自环境变量 %s：未设置/为空/非数字/小于等于 0 时回落默认值 %.2f；'
                . '慢机器上可显式放宽（例如 %s=20），但掉一个量级通常是缓存失效而不是机器慢。',
                $qps,
                $minQps,
                $totalQueries,
                $totalTime,
                $userCount,
                $hitRate,
                self::MIN_QPS_ENV,
                self::DEFAULT_MIN_QPS,
                self::MIN_QPS_ENV
            )
        );
        echo '✅ QPS 达标: ' . round($qps, 2) . ' > ' . sprintf('%.2f', $minQps) . "\n";
    }

    /**
     * 测试缓存命中率（> 预算，默认 90%）
     * Test Cache Hit Rate (> budget, default 90%)
     */
    public function testCacheHitRate(): void
    {
        if (!$this->cacheService) {
            $this->markTestSkipped('缓存服务未启用，跳过缓存测试');
            return;
        }

        // 清除所有缓存
        $this->cacheService->clearAllCache();

        $queryCount = 100;
        $userIds = $this->benchmarkUserIds();
        $userCount = count($userIds);

        // 第一轮查询（缓存未命中后写入）
        for ($i = 0; $i < $queryCount; $i++) {
            $this->permissionService->getUserPermissions($userIds[$i % $userCount]);
        }

        // 获取统计信息
        $stats = $this->cacheService->getCacheStats();

        echo "\n=== 第一轮查询（缓存未命中）===\n";
        echo 'L1 命中率: ' . $stats['l1_cache']['hit_rate'] . "%\n";
        echo 'L2 命中率: ' . $stats['l2_cache']['hit_rate'] . "%\n";
        echo '总体命中率: ' . $stats['overall']['overall_hit_rate'] . "%\n";

        // 重置统计
        $this->cacheService->resetStats();

        // 第二轮查询（同一批基准用户，应该全部命中缓存）
        for ($i = 0; $i < $queryCount; $i++) {
            $this->permissionService->getUserPermissions($userIds[$i % $userCount]);
        }

        // 获取统计信息
        $stats = $this->cacheService->getCacheStats();
        $overallHitRate = $stats['overall']['overall_hit_rate'];
        $minHitRate = PerformanceThreshold::readFloat(self::MIN_CACHE_HIT_RATE_ENV, self::DEFAULT_MIN_CACHE_HIT_RATE);

        echo "\n=== 第二轮查询（缓存命中）===\n";
        echo 'L1 命中率: ' . $stats['l1_cache']['hit_rate'] . "%\n";
        echo 'L2 命中率: ' . $stats['l2_cache']['hit_rate'] . "%\n";
        echo '总体命中率: ' . $overallHitRate . "%\n";
        echo '总查询次数: ' . $stats['overall']['total_queries'] . "\n";
        echo '生效阈值: 命中率 > ' . sprintf('%.2f', $minHitRate) . '%（' . self::MIN_CACHE_HIT_RATE_ENV . "）\n";

        // 验证命中率 > 预算
        $this->assertGreaterThan(
            $minHitRate,
            $overallHitRate,
            sprintf(
                '缓存命中率 %.2f%% 低于目标 %.2f%%（第二轮 %d 次查询，%d 个基准用户，回源 %d 次）。'
                . '生效阈值来自环境变量 %s：未设置/为空/非数字/小于等于 0 时回落默认值 %.2f。',
                $overallHitRate,
                $minHitRate,
                $queryCount,
                $userCount,
                $stats['overall']['total_queries'],
                self::MIN_CACHE_HIT_RATE_ENV,
                self::DEFAULT_MIN_CACHE_HIT_RATE
            )
        );
        echo '✅ 缓存命中率达标: ' . $overallHitRate . '% > ' . sprintf('%.2f', $minHitRate) . "%\n";
    }

    /**
     * 测试缓存隔离（租户间数据不泄露）
     * Test Cache Isolation (No data leakage between tenants)
     */
    public function testCacheIsolation(): void
    {
        if (!$this->cacheService) {
            $this->markTestSkipped('缓存服务未启用，跳过隔离测试');
            return;
        }

        $tenant1UserId = 1;
        $tenant2UserId = 2;

        // 清除所有缓存
        $this->cacheService->clearAllCache();

        // 租户 1 用户查询权限
        $tenant1Permissions = $this->permissionService->getUserPermissions($tenant1UserId);

        // 租户 2 用户查询权限
        $tenant2Permissions = $this->permissionService->getUserPermissions($tenant2UserId);

        // 获取缓存统计
        $stats = $this->cacheService->getCacheStats();

        echo "\n=== 缓存隔离测试结果 ===\n";
        echo '租户 1 用户权限数量: ' . count($tenant1Permissions) . "\n";
        echo '租户 2 用户权限数量: ' . count($tenant2Permissions) . "\n";
        echo '缓存键是否包含租户 ID: ' . (strpos(serialize($stats), 'tenant') !== false ? '是' : '否') . "\n";

        // 验证不同租户数据不同
        $this->assertTrue(
            $tenant1Permissions !== $tenant2Permissions,
            '不同租户用户的权限应该不同'
        );

        echo "✅ 缓存隔离测试通过\n";
    }

    /**
     * 测试缓存失效性能（< 预算，默认 200ms）
     * Test Cache Invalidation Performance (< budget, default 200ms)
     *
     * 取 3 次中位数而不是单次采样：`clearUserCache()` 只有 3 个文件系统动作
     * （2 次 Cache::delete + 1 条日志落盘），单次采样在 bind mount 上实测
     * 19ms ~ 115ms（6 倍抖动），足以让任何固定预算随机翻红。
     * 中位数保留「一次失效要多久」的语义，同时把单次抖动压低一个量级。
     */
    public function testCacheInvalidationPerformance(): void
    {
        if (!$this->cacheService) {
            $this->markTestSkipped('缓存服务未启用，跳过失效测试');
            return;
        }

        $userId = $this->testUserId;
        $rounds = 3;
        $samples = [];

        for ($round = 0; $round < $rounds; $round++) {
            // 每轮先查一次，确保缓存里**确实有**这一项：删一个不存在的键测不出失效成本
            $this->permissionService->getUserPermissions($userId);

            $startTime = microtime(true);
            $this->permissionService->clearUserCache($userId);
            $samples[] = (microtime(true) - $startTime) * 1000; // 转换为毫秒
        }

        sort($samples);
        $invalidationTime = $samples[(int) floor($rounds / 2)]; // 中位数
        $budgetMs = PerformanceThreshold::readFloat(
            self::MAX_INVALIDATION_MS_ENV,
            self::DEFAULT_MAX_INVALIDATION_MS
        );

        echo "\n=== 缓存失效性能测试结果 ===\n";
        echo '缓存失效耗时（3 次采样，毫秒）: ' . implode(' / ', array_map(static function (float $v): string {
            return (string) round($v, 2);
        }, $samples)) . "\n";
        echo '缓存失效时间（中位数）: ' . round($invalidationTime, 2) . "ms\n";
        echo '生效阈值: < ' . sprintf('%.2f', $budgetMs) . 'ms（' . self::MAX_INVALIDATION_MS_ENV . "）\n";

        // 验证失效时间 < 预算
        $this->assertLessThan(
            $budgetMs,
            $invalidationTime,
            sprintf(
                '缓存失效时间中位数 %.2fms 超过预算 %.2fms（用户 %d，3 次采样：%s）。'
                . '生效阈值来自环境变量 %s：未设置/为空/非数字/小于等于 0 时回落默认值 %.2fms。',
                $invalidationTime,
                $budgetMs,
                $userId,
                implode(' / ', array_map(static function (float $v): string {
                    return (string) round($v, 2);
                }, $samples)),
                self::MAX_INVALIDATION_MS_ENV,
                self::DEFAULT_MAX_INVALIDATION_MS
            )
        );
        echo '✅ 缓存失效性能达标: ' . round($invalidationTime, 2) . 'ms < ' . sprintf('%.2f', $budgetMs) . "ms\n";
    }

    /**
     * 清理测试环境
     */
    protected function tearDown(): void
    {
        // 清除缓存
        if ($this->cacheService) {
            $this->cacheService->clearAllCache();
        }

        // 清理基准夹具（按依赖顺序：关联表 → 主表）
        $this->cleanupBenchmarkUsers();

        parent::tearDown();
    }

    /**
     * 基准用户 ID 列表
     *
     * @return list<int>
     */
    protected function benchmarkUserIds(): array
    {
        $userIds = [];
        for ($i = 0; $i < self::BENCH_USER_COUNT; $i++) {
            $userIds[] = self::BENCH_USER_ID_BASE + $i;
        }

        return $userIds;
    }

    /**
     * 建立确定性的基准夹具（用户 / 角色 / 权限）
     *
     * 为什么不用「从 users 表捞现有用户」：现有用户是 seed 与其他用例的**残留集合**，
     * 数量与权限内容随「之前跑过哪些用例」而变（今天 1 个、明天 3 个），
     * 基准就失去了可比性。固定 ID 段让「同一台机器跑两次」和「两台机器之间」
     * 面对的是同一批主体，QPS / 延迟才有对照价值。
     */
    protected function prepareBenchmarkUsers(): void
    {
        // 先按 ID 精确清一遍，避免上一次崩溃留下的半截夹具
        $this->cleanupBenchmarkUsers();

        // 租户不假设具体 id（users.tenant_id 有 fk_users_tenant_id 外键）
        $tenantIds = Db::table('tenants')->order('id', 'asc')->column('id');
        if (empty($tenantIds)) {
            self::fail(
                "性能夹具无法准备：tenants 表为空，没有任何租户可以挂载基准用户。\n"
                . "本套件的 QPS / 延迟 / 命中率都以「真实用户 + 真实角色 + 真实权限」为前提，\n"
                . "空库上跑出来的数字没有意义，所以这里直接失败而不是跳过。\n"
                . "请先在测试库建租户，任选一种：\n"
                . "  1) docker exec alkaid-backend sh -lc 'PHP_ENV_NAME=test php think seed:run -s TestPlatformSeed'\n"
                . "  2) 跑一次默认套件（TestPlatformSeed 覆盖建租户逻辑）\n"
                . '  3) 冷启动建库：PHP_ENV_NAME=test php think migrate:run'
            );
        }
        $this->testTenantId = (int) $tenantIds[0];

        // 10 个基准用户
        $users = [];
        foreach ($this->benchmarkUserIds() as $userId) {
            $users[] = [
                'id' => $userId,
                'tenant_id' => $this->testTenantId,
                'username' => "perf_bench_user_{$userId}",
                'email' => "perf_bench_user_{$userId}@example.test",
                'password' => 'test',
                'status' => 'active',
            ];
        }
        Db::table('users')->insertAll($users);

        // 1 个角色：roles.slug 在租户内唯一，slug 带 ID 前缀避免与真实数据撞车
        Db::table('roles')->insert([
            'id' => self::BENCH_ROLE_ID,
            'tenant_id' => $this->testTenantId,
            'name' => 'Perf Benchmark Role',
            'slug' => 'perf_bench_role_' . self::BENCH_ROLE_ID,
            'description' => 'Permission benchmark fixture',
        ]);

        // 3 个权限：permissions.slug 全局唯一，同样带 ID 前缀
        $permissions = [];
        $rolePermissions = [];
        for ($i = 0; $i < self::BENCH_PERMISSION_COUNT; $i++) {
            $permissionId = self::BENCH_PERMISSION_ID_BASE + $i;
            $permissions[] = [
                'id' => $permissionId,
                'name' => "Perf Benchmark Permission {$permissionId}",
                'slug' => "perf_bench_perm_{$permissionId}",
                'resource' => 'perf_bench_resource',
                'action' => "action_{$i}",
                'description' => 'Permission benchmark fixture',
            ];
            $rolePermissions[] = [
                'role_id' => self::BENCH_ROLE_ID,
                'permission_id' => $permissionId,
            ];
        }
        Db::table('permissions')->insertAll($permissions);
        Db::table('role_permissions')->insertAll($rolePermissions);

        // 基准用户全部挂到这个角色上
        $userRoles = [];
        foreach ($this->benchmarkUserIds() as $userId) {
            $userRoles[] = [
                'user_id' => $userId,
                'role_id' => self::BENCH_ROLE_ID,
            ];
        }
        Db::table('user_roles')->insertAll($userRoles);
    }

    /**
     * 清理基准夹具
     *
     * 一律按 ID **精确**删除（whereIn / 单条 id），不用「>= 起点」：
     * 起点 6001 之上还有 7000 / 8000 / 88888 / 99999 等其它用例的 ID 段，
     * 大范围删会把它们一起清掉，制造莫名其妙的连锁失败。
     */
    protected function cleanupBenchmarkUsers(): void
    {
        $userIds = $this->benchmarkUserIds();

        $permissionIds = [];
        for ($i = 0; $i < self::BENCH_PERMISSION_COUNT; $i++) {
            $permissionIds[] = self::BENCH_PERMISSION_ID_BASE + $i;
        }

        // 按依赖顺序：先关联表，再主表
        Db::table('role_permissions')->where('role_id', self::BENCH_ROLE_ID)->delete();
        Db::table('user_roles')->whereIn('user_id', $userIds)->delete();
        Db::table('permissions')->whereIn('id', $permissionIds)->delete();
        Db::table('roles')->where('id', self::BENCH_ROLE_ID)->delete();
        Db::table('users')->whereIn('id', $userIds)->delete();
    }
}
