<?php

declare(strict_types=1);

namespace Tests\Performance\Benchmark\Permission;

use Infrastructure\Permission\Service\CasbinService;
use Infrastructure\Permission\Service\PermissionService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Performance\Support\PerformanceBenchmark;
use Tests\Performance\Support\PerformanceReporter;
use Tests\Performance\Support\PerformanceThreshold;
use Tests\ThinkPHPTestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * Casbin 性能基准测试
 * Casbin Performance Benchmark Tests
 *
 * 测试 Casbin 授权引擎的性能指标。
 * Test performance metrics of Casbin authorization engine.
 *
 * ## 为什么类上要显式写 #[Group('performance')]
 *
 * `phpunit.performance.xml` 已经用 `<directory groups="performance">` 给整个目录打了组，
 * 但那是**配置侧的隐式约定**：单看这个文件，读者无法判断它属于性能套件，
 * 直接 `--group performance` 也不一定命中。显式属性让归属在代码里可见，
 * 且与 CasbinControllerTest::testReloadPolicyExecutionTimeWithinBudget 的写法一致
 * （那个是方法级，这里是类级；不要再在方法上重复加）。
 *
 * ## 阈值
 *
 * 三个墙钟阈值都是「环境变量可配 + 代码默认值」，见 DEFAULT_* / *_ENV 常量。
 * 性能断言的职责是拦住量级退化（毫秒级变数十秒级），不是「在满载机器上守住某个毫秒数」。
 *
 * 默认值按「同一台机器多次实跑的最差值再留约 2 倍余量」定（实测见各常量的注释）：
 * 本套件跑在 Docker 容器的 bind mount 上，而 ThinkPHP 日志通道在
 * `runningInConsole()` 为真时每条日志都立即落盘（实测 8~17ms/条），
 * 于是每一次 Casbin `check()` 都自带一条日志的 I/O 成本 —— 这也是为什么
 * 绝对数字比「原生文件系统的服务器」差一个量级以上。
 *
 * ## 历史中位数比对（detectRegression）为什么要放宽
 *
 * `testSinglePermissionCheckPerformance` 会拿本次均值与本机历史中位数比较，
 * 超过阈值就 `markTestIncomplete`。原阈值是 +20%，在共享/抖动宿主上基本等于随机：
 * 同一份代码三次跑出 11.29 / 13.68 / 18.83ms（I/O 抖动），历史中位数追不上这种漂移，
 * 于是「本轮偶尔 Incomplete」本身成了噪声。现在阈值可配（默认 2.0，即 3 倍中位数才判退化），
 * 只做**量级**兜底；「是否超标」的主判据仍是下面三条预算断言。
 */
#[Group('performance')]
class CasbinPerformanceTest extends ThinkPHPTestCase
{
    /**
     * 单次权限检查的平均耗时预算（毫秒）默认值
     *
     * 实测 11.29 / 13.68 / 18.83ms（含一条日志落盘），默认 40ms ≈ 2.1 倍余量。
     */
    private const DEFAULT_SINGLE_CHECK_AVG_MS = 40.0;

    /**
     * 单次权限检查平均耗时预算的环境变量名
     */
    private const SINGLE_CHECK_AVG_ENV = 'PERF_SINGLE_CHECK_AVG_MS';

    /**
     * 1000 次批量权限检查的总耗时预算（毫秒）默认值
     *
     * 实测 16214 / 21173 / 22822 / 24408ms，默认 50000ms ≈ 2 倍余量。
     */
    private const DEFAULT_BATCH_CHECK_TOTAL_MS = 50000.0;

    /**
     * 批量权限检查总耗时预算的环境变量名
     */
    private const BATCH_CHECK_TOTAL_ENV = 'PERF_BATCH_CHECK_TOTAL_MS';

    /**
     * 策略加载平均耗时预算（毫秒）默认值
     *
     * 实测 216 / 221 / 302 / 396ms（夹具里 1000 用户 + 100 角色 + 数千条关联），
     * 默认 800ms ≈ 2 倍余量。
     */
    private const DEFAULT_POLICY_LOAD_AVG_MS = 800.0;

    /**
     * 策略加载平均耗时预算的环境变量名
     */
    private const POLICY_LOAD_AVG_ENV = 'PERF_POLICY_LOAD_AVG_MS';

    /**
     * 历史中位数比对的退化阈值（倍数）默认值
     *
     * 2.0 表示「本次均值 > 历史中位数 × 3」才判退化。原先是 0.2（即 +20%），
     * 在本机 I/O 抖动下会随机把用例标成 Incomplete。
     */
    private const DEFAULT_REGRESSION_THRESHOLD = 2.0;

    /**
     * 历史中位数比对阈值的环境变量名
     */
    private const REGRESSION_THRESHOLD_ENV = 'PERF_REGRESSION_THRESHOLD';

    protected CasbinService $casbinService;
    protected PermissionService $permissionService;
    protected array $performanceReport = [];
    protected PerformanceBenchmark $benchmark;
    protected PerformanceReporter $reporter;

    /**
     * 夹具可用的真实租户 ID 列表（来自 tenants 表，见 resolveFixtureTenantIds）
     *
     * @var list<int>
     */
    protected array $fixtureTenantIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 创建基准管理器和报告生成器
        // Create benchmark manager and reporter
        $this->benchmark = new PerformanceBenchmark();
        $this->reporter = new PerformanceReporter($this->benchmark);

        // 历史中位数比对的退化阈值同样可配（默认 2.0 = 3 倍中位数才判退化）：
        // 20% 的老阈值在 I/O 抖动的宿主上会把「本轮状态」误判成「性能回归」，
        // 让用例随机变成 Incomplete（实测同一份代码三次跑 11.29 / 13.68 / 18.83ms）。
        $this->benchmark->setRegressionThreshold(
            PerformanceThreshold::readFloat(self::REGRESSION_THRESHOLD_ENV, self::DEFAULT_REGRESSION_THRESHOLD)
        );

        // 创建服务实例
        // Create service instances
        $this->casbinService = new CasbinService();
        $this->permissionService = new PermissionService($this->casbinService);

        // 准备性能测试数据
        // Prepare performance test data
        $this->preparePerformanceTestData();

        // 重新加载策略
        // Reload policy
        $this->casbinService->reloadPolicy();
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        // Clean up test data
        $this->cleanupTestData();

        // 生成性能报告（使用新的报告生成器）
        // Generate performance report (using new reporter)
        $reportFile = $this->reporter->generate('casbin-performance-benchmark-' . date('Y-m-d') . '.md');
        // 注意：不要在这里直接 echo，以免 PHPUnit 将测试标记为 risky（unexpected output）
        // Note: Do not echo here to avoid PHPUnit marking tests as risky due to unexpected output.

        parent::tearDown();
    }

    /**
     * 准备性能测试数据
     * Prepare performance test data
     */
    protected function preparePerformanceTestData(): void
    {
        // 清理现有数据
        // Clean existing data
        $this->cleanupTestData();

        // 先读取**实际存在**的租户，再开始插入
        // Resolve the tenants that actually exist before inserting anything
        // （租户为空时这里会显式失败，不会静默跳过）
        $this->fixtureTenantIds = $this->resolveFixtureTenantIds();

        // 插入 1000 个测试用户
        // Insert 1000 test users
        $users = [];
        for ($i = 10000; $i < 11000; $i++) {
            $users[] = [
                'id' => $i,
                // 在真实租户之间轮转，绝不硬编码 1..10
                // Rotate over the tenants that actually exist (never hardcode 1..10)
                'tenant_id' => $this->tenantIdForOffset($i - 10000),
                'username' => "perf_test_user_{$i}",
                'email' => "perf{$i}@test.com",
                'password' => 'test',
                'status' => 'active',
            ];
        }
        Db::table('users')->insertAll($users);

        // 插入 100 个测试角色
        // Insert 100 test roles
        $roles = [];
        for ($i = 10000; $i < 10100; $i++) {
            $roles[] = [
                'id' => $i,
                'tenant_id' => $this->tenantIdForOffset($i - 10000),
                'name' => "Perf Test Role {$i}",
                'slug' => "perf_test_role_{$i}",
                'description' => 'Performance Test',
            ];
        }
        Db::table('roles')->insertAll($roles);

        // 插入 1000 个测试权限
        // Insert 1000 test permissions
        $permissions = [];
        for ($i = 10000; $i < 11000; $i++) {
            $permissions[] = [
                'id' => $i,
                'name' => "Perf Test Permission {$i}",
                'slug' => "perf_test_perm_{$i}",
                'resource' => 'perf_test_resource_' . (($i - 10000) % 100),
                'action' => 'action_' . (($i - 10000) % 10),
                'description' => 'Performance Test',
            ];
        }
        Db::table('permissions')->insertAll($permissions);

        // 插入用户角色关联（每个用户 1-3 个随机角色，使用去重逻辑）
        // Insert user-role associations (1-3 random roles per user, with deduplication)
        $userRoles = [];
        for ($userId = 10000; $userId < 11000; $userId++) {
            // 随机分配 1-3 个角色
            // Randomly assign 1-3 roles
            $roleCount = rand(1, 3);
            $assignedRoles = [];

            for ($j = 0; $j < $roleCount; $j++) {
                // 生成随机角色 ID，确保不重复
                // Generate random role ID, ensure no duplicates
                do {
                    $roleId = 10000 + rand(0, 99);
                } while (in_array($roleId, $assignedRoles));

                $assignedRoles[] = $roleId;
                $userRoles[] = [
                    'user_id' => $userId,
                    'role_id' => $roleId,
                ];
            }
        }
        Db::table('user_roles')->insertAll($userRoles);

        // 插入角色权限关联（每个角色 10-50 个随机权限，使用去重逻辑）
        // Insert role-permission associations (10-50 random permissions per role, with deduplication)
        $rolePermissions = [];
        for ($roleId = 10000; $roleId < 10100; $roleId++) {
            // 随机分配 10-50 个权限
            // Randomly assign 10-50 permissions
            $permCount = rand(10, 50);
            $assignedPermissions = [];

            for ($j = 0; $j < $permCount; $j++) {
                // 生成随机权限 ID，确保不重复
                // Generate random permission ID, ensure no duplicates
                do {
                    $permId = 10000 + rand(0, 999);
                } while (in_array($permId, $assignedPermissions));

                $assignedPermissions[] = $permId;
                $rolePermissions[] = [
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                ];
            }
        }
        Db::table('role_permissions')->insertAll($rolePermissions);
    }

    /**
     * 解析夹具可用的真实租户 ID
     * Resolve the tenant IDs that actually exist for the fixtures
     *
     * 历史缺陷：原实现硬编码 `($i % 10) + 1`，即假设租户 1..10 都存在。
     * 测试库（alkaid_sys_test）通常只有 TestPlatformSeed 建出来的 2 个租户，
     * 于是插第 1 条用户就撞外键：
     *   `fk_users_tenant_id` 1452 Cannot add or update a child row
     * —— 4 个用例全部 error，且报错位置（插入 SQL）与真正原因（租户假设）相距很远。
     *
     * 现在的规则：**从 tenants 表读实际存在的 id 轮转使用**，不假设任何具体 id；
     * 一个租户都没有时**显式失败**（不 skip、不静默降级）——性能套件不该在没有测试数据时假装通过。
     *
     * @return list<int> 租户 ID 列表（升序，至少 1 个）
     */
    protected function resolveFixtureTenantIds(): array
    {
        $tenantIds = Db::table('tenants')->order('id', 'asc')->column('id');

        if (empty($tenantIds)) {
            self::fail(
                "性能夹具无法准备：tenants 表为空，没有任何租户可以挂载 1000 个测试用户。\n"
                . "users.tenant_id 上有外键 fk_users_tenant_id，没有租户就必然插入失败；\n"
                . "而「跳过或空跑」会让性能数字失去意义，所以这里直接失败。\n"
                . "请先在测试库建租户，任选一种：\n"
                . "  1) docker exec alkaid-backend sh -lc 'PHP_ENV_NAME=test php think seed:run -s TestPlatformSeed'\n"
                . "  2) 跑一次默认套件（TestPlatformSeed 通常已覆盖建租户逻辑）\n"
                . '  3) 冷启动建库：PHP_ENV_NAME=test php think migrate:run'
            );
        }

        return array_map('intval', $tenantIds);
    }

    /**
     * 按序号取一个真实存在的租户 ID（环形轮转）
     * Pick an existing tenant ID by offset (round-robin)
     *
     * @param int $offset 从 0 开始的序号 | Zero-based offset
     * @return int 租户 ID | Tenant ID
     */
    protected function tenantIdForOffset(int $offset): int
    {
        $tenantCount = count($this->fixtureTenantIds);

        return $this->fixtureTenantIds[$offset % $tenantCount];
    }

    /**
     * 清理测试数据
     * Clean up test data
     */
    protected function cleanupTestData(): void
    {
        // 删除测试数据（按依赖顺序）
        // Delete test data (in dependency order)
        Db::table('role_permissions')->where('role_id', '>=', 10000)->delete();
        Db::table('user_roles')->where('user_id', '>=', 10000)->delete();
        Db::table('permissions')->where('id', '>=', 10000)->delete();
        Db::table('roles')->where('id', '>=', 10000)->delete();
        Db::table('users')->where('id', '>=', 10000)->delete();
    }

    /**
     * 测试单次权限检查性能
     * Test single permission check performance
     */
    public function testSinglePermissionCheckPerformance(): void
    {
        // 配置 CASBIN_ONLY 模式
        // Configure CASBIN_ONLY mode
        Config::set(['casbin.mode' => 'CASBIN_ONLY']);

        // 测试 100 次单次权限检查
        // Test 100 single permission checks
        // 用户 10000 的租户来自夹具轮转（见 tenantIdForOffset），不再硬编码租户 1
        $tenantId = $this->tenantIdForOffset(0);
        $times = [];
        for ($i = 0; $i < 100; $i++) {
            $startTime = microtime(true);
            $this->casbinService->check(10000, $tenantId, 'perf_test_resource_0', 'action_0');
            $times[] = (microtime(true) - $startTime) * 1000; // ms
        }

        // 计算统计数据
        // Calculate statistics
        $avgTime = array_sum($times) / count($times);
        $maxTime = max($times);
        $minTime = min($times);

        // 记录性能数据到基准
        // Record performance data to benchmark
        $this->benchmark->record(
            'single_permission_check',
            $avgTime,
            memory_get_peak_usage(),
            [
                'iterations' => 100,
                'max_time_ms' => round($maxTime, 2),
                'min_time_ms' => round($minTime, 2),
            ]
        );

        // 检测性能退化
        // Detect performance regression
        $regression = $this->benchmark->detectRegression('single_permission_check', $avgTime);
        if ($regression['has_regression']) {
            $this->markTestIncomplete($regression['message']);
        }

        // 验证性能目标：平均耗时 < 预算（默认 40ms，可用环境变量放宽/收紧）
        // Verify performance target: average < budget (default 40ms, tunable via env)
        $budgetMs = PerformanceThreshold::readFloat(self::SINGLE_CHECK_AVG_ENV, self::DEFAULT_SINGLE_CHECK_AVG_MS);
        echo "\n=== 单次权限检查性能 ===\n";
        echo '迭代次数: ' . count($times) . "\n";
        echo '平均耗时: ' . round($avgTime, 2) . "ms\n";
        echo '最大耗时: ' . round($maxTime, 2) . 'ms / 最小耗时: ' . round($minTime, 2) . "ms\n";
        echo '生效阈值: < ' . sprintf('%.2f', $budgetMs) . 'ms（' . self::SINGLE_CHECK_AVG_ENV . "）\n";

        $this->assertLessThan(
            $budgetMs,
            $avgTime,
            sprintf(
                '单次权限检查平均耗时 %.2fms 超过预算 %.2fms（本次 %d 次迭代，max %.2fms / min %.2fms）；'
                . '生效阈值来自环境变量 %s（未设置、为空、非数字或小于等于 0 时回落默认值 %.2fms）',
                $avgTime,
                $budgetMs,
                count($times),
                $maxTime,
                $minTime,
                self::SINGLE_CHECK_AVG_ENV,
                self::DEFAULT_SINGLE_CHECK_AVG_MS
            )
        );
    }

    /**
     * 测试批量权限检查性能
     * Test batch permission check performance
     *
     * 性能目标说明 | Performance Target Explanation:
     * - 目标：1000 次检查总耗时 < 预算（默认 50s）| Target: total of 1000 checks < budget (default 50s)
     * - 实测（本机 Docker bind mount + 每条日志即时落盘）：16.2s / 21.2s / 22.8s / 24.4s
     * - Measured on this host (bind mount, log flushed per record): 16.2s / 21.2s / 22.8s / 24.4s
     * - 默认 50s ≈ 2 倍余量；预算可用环境变量 PERF_BATCH_CHECK_TOTAL_MS 调整（毫秒）
     * - Default 50s leaves ~2x margin; override with PERF_BATCH_CHECK_TOTAL_MS (ms)
     */
    public function testBatchPermissionCheckPerformance(): void
    {
        // 配置 CASBIN_ONLY 模式
        // Configure CASBIN_ONLY mode
        Config::set(['casbin.mode' => 'CASBIN_ONLY']);

        // 禁用策略自动刷新以获得稳定的性能测试结果
        // Disable automatic policy reload for stable performance test results
        Config::set(['casbin.reload_ttl' => 0]);

        // 手动刷新一次策略
        // Manually reload policy once
        $this->casbinService->reloadPolicy();

        // 测试 1000 次权限检查
        // Test 1000 permission checks
        $startTime = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $offset = $i % 100;
            $userId = 10000 + $offset;
            $resourceId = $i % 100;
            $actionId = $i % 10;
            $this->casbinService->check(
                $userId,
                $this->tenantIdForOffset($offset),
                "perf_test_resource_{$resourceId}",
                "action_{$actionId}"
            );
        }
        $totalTime = (microtime(true) - $startTime) * 1000; // ms

        // 计算平均时间
        // Calculate average time
        $avgTime = $totalTime / 1000;

        // 记录性能数据
        // Record performance data
        $this->performanceReport['batch_check'] = [
            'total_time_ms' => round($totalTime, 2),
            'avg_time_ms' => round($avgTime, 2),
            'iterations' => 1000,
        ];

        // 性能数据将在测试报告中显示
        // Performance data will be shown in test report
        // 基准测试的指标本来就该打印出来：phpunit.performance.xml 刻意没开
        // beStrictAboutOutputDuringTests，所以这里输出不会变成 risky。
        $budgetMs = PerformanceThreshold::readFloat(self::BATCH_CHECK_TOTAL_ENV, self::DEFAULT_BATCH_CHECK_TOTAL_MS);
        echo "\n=== 批量权限检查性能 ===\n";
        echo "迭代次数: 1000\n";
        echo '总耗时: ' . round($totalTime, 2) . "ms\n";
        echo '平均耗时: ' . round($avgTime, 3) . "ms/次\n";
        echo '生效阈值: < ' . sprintf('%.2f', $budgetMs) . 'ms（' . self::BATCH_CHECK_TOTAL_ENV . "）\n";

        // 验证性能目标：总耗时 < 预算（默认 50s，不含策略刷新开销）
        // Verify performance target: total < budget (default 50s, without policy reload overhead)
        $this->assertLessThan(
            $budgetMs,
            $totalTime,
            sprintf(
                '1000 次批量权限检查总耗时 %.2fms 超过预算 %.2fms（平均 %.3fms/次）；'
                . '生效阈值来自环境变量 %s（未设置、为空、非数字或小于等于 0 时回落默认值 %.2fms）',
                $totalTime,
                $budgetMs,
                $avgTime,
                self::BATCH_CHECK_TOTAL_ENV,
                self::DEFAULT_BATCH_CHECK_TOTAL_MS
            )
        );
    }

    /**
     * 测试策略加载性能
     * Test policy loading performance
     */
    public function testPolicyLoadingPerformance(): void
    {
        // 测试 10 次策略加载
        // Test 10 policy loads
        $times = [];
        for ($i = 0; $i < 10; $i++) {
            $startTime = microtime(true);
            $this->casbinService->reloadPolicy();
            $times[] = (microtime(true) - $startTime) * 1000; // ms
        }

        // 计算统计数据
        // Calculate statistics
        $avgTime = array_sum($times) / count($times);
        $maxTime = max($times);
        $minTime = min($times);

        // 记录性能数据
        // Record performance data
        $this->performanceReport['policy_loading'] = [
            'avg_time_ms' => round($avgTime, 2),
            'max_time_ms' => round($maxTime, 2),
            'min_time_ms' => round($minTime, 2),
            'iterations' => 10,
        ];

        // 验证性能目标：平均耗时 < 预算（默认 800ms，可用环境变量放宽/收紧）
        // Verify performance target: average < budget (default 800ms, tunable via env)
        $budgetMs = PerformanceThreshold::readFloat(self::POLICY_LOAD_AVG_ENV, self::DEFAULT_POLICY_LOAD_AVG_MS);
        echo "\n=== 策略加载性能 ===\n";
        echo 'reload 次数: ' . count($times) . "\n";
        echo '平均耗时: ' . round($avgTime, 2) . "ms\n";
        echo '最大耗时: ' . round($maxTime, 2) . 'ms / 最小耗时: ' . round($minTime, 2) . "ms\n";
        echo '生效阈值: < ' . sprintf('%.2f', $budgetMs) . 'ms（' . self::POLICY_LOAD_AVG_ENV . "）\n";

        $this->assertLessThan(
            $budgetMs,
            $avgTime,
            sprintf(
                '策略加载平均耗时 %.2fms 超过预算 %.2fms（本次 %d 次 reload，max %.2fms / min %.2fms）；'
                . '生效阈值来自环境变量 %s（未设置、为空、非数字或小于等于 0 时回落默认值 %.2fms）',
                $avgTime,
                $budgetMs,
                count($times),
                $maxTime,
                $minTime,
                self::POLICY_LOAD_AVG_ENV,
                self::DEFAULT_POLICY_LOAD_AVG_MS
            )
        );
    }

    /**
     * 测试不同运行模式性能对比
     * Test performance comparison of different running modes
     */
    public function testRunningModesPerformanceComparison(): void
    {
        $modes = ['DB_ONLY', 'CASBIN_ONLY', 'DUAL_MODE'];
        $results = [];

        foreach ($modes as $mode) {
            // 配置模式
            // Configure mode
            Config::set(['casbin.mode' => $mode]);

            // 重新创建服务实例
            // Recreate service instance
            $this->permissionService = new PermissionService($this->casbinService);

            // 测试 100 次权限检查
            // Test 100 permission checks
            $times = [];
            for ($i = 0; $i < 100; $i++) {
                $startTime = microtime(true);
                $this->permissionService->hasPermission(10000, 'perf_test_resource_0:action_0');
                $times[] = (microtime(true) - $startTime) * 1000; // ms
            }

            // 计算统计数据
            // Calculate statistics
            $results[$mode] = [
                'avg_time_ms' => round(array_sum($times) / count($times), 2),
                'max_time_ms' => round(max($times), 2),
                'min_time_ms' => round(min($times), 2),
            ];
        }

        // 记录性能数据
        // Record performance data
        $this->performanceReport['mode_comparison'] = $results;

        echo "\n=== 三种运行模式性能对比（100 次 hasPermission）===\n";
        foreach ($results as $mode => $result) {
            echo sprintf(
                "%-12s 平均 %6.2fms / 最大 %6.2fms / 最小 %6.2fms\n",
                $mode,
                $result['avg_time_ms'],
                $result['max_time_ms'],
                $result['min_time_ms']
            );
        }

        // 性能对比数据已记录到报告中，不做严格断言
        // Performance comparison data is recorded in report, no strict assertion
        // 注意：由于测试环境的性能波动，不同模式的性能可能略有差异
        // Note: Due to performance fluctuations in test environment, different modes may have slight performance differences
        // 验证所有模式都能正常工作（基本的功能性断言）
        // Verify all modes work correctly (basic functional assertion)
        $this->assertNotEmpty($results, 'Performance results should not be empty');
        $this->assertArrayHasKey('DB_ONLY', $results, 'DB_ONLY results should exist');
        $this->assertArrayHasKey('CASBIN_ONLY', $results, 'CASBIN_ONLY results should exist');
        $this->assertArrayHasKey('DUAL_MODE', $results, 'DUAL_MODE results should exist');
    }
}
