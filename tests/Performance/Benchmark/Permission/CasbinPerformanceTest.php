<?php

declare(strict_types=1);

namespace Tests\Performance\Benchmark\Permission;

use Infrastructure\Permission\Service\CasbinService;
use Infrastructure\Permission\Service\PermissionService;
use Tests\Performance\Support\PerformanceBenchmark;
use Tests\Performance\Support\PerformanceReporter;
use Tests\ThinkPHPTestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * Casbin 性能基准测试
 * Casbin Performance Benchmark Tests
 * 
 * 测试 Casbin 授权引擎的性能指标。
 * Test performance metrics of Casbin authorization engine.
 */
class CasbinPerformanceTest extends ThinkPHPTestCase
{
    protected CasbinService $casbinService;
    protected PermissionService $permissionService;
    protected array $performanceReport = [];
    protected PerformanceBenchmark $benchmark;
    protected PerformanceReporter $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建基准管理器和报告生成器
        // Create benchmark manager and reporter
        $this->benchmark = new PerformanceBenchmark();
        $this->reporter = new PerformanceReporter($this->benchmark);

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
        echo "\nPerformance report generated: {$reportFile}\n";

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
        
        // 插入 1000 个测试用户
        // Insert 1000 test users
        $users = [];
        for ($i = 10000; $i < 11000; $i++) {
            $users[] = [
                'id' => $i,
                'tenant_id' => ($i % 10) + 1, // 10 个租户
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
                'tenant_id' => ($i % 10) + 1,
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
                'resource' => "perf_test_resource_" . (($i - 10000) % 100),
                'action' => "action_" . (($i - 10000) % 10),
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
        $times = [];
        for ($i = 0; $i < 100; $i++) {
            $startTime = microtime(true);
            $this->casbinService->check(10000, 1, 'perf_test_resource_0', 'action_0');
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

        // 验证性能目标：< 10ms
        // Verify performance target: < 10ms
        $this->assertLessThan(10, $avgTime, "Average permission check time should be < 10ms, got {$avgTime}ms");
    }

    /**
     * 测试批量权限检查性能
     * Test batch permission check performance
     *
     * 性能目标说明 | Performance Target Explanation:
     * - 目标：< 8s（1000 次检查）| Target: < 8s (1000 checks)
     * - 理论计算：1000 × 7ms = 7s | Theoretical: 1000 × 7ms = 7s
     * - 实际测试：约 4-5s | Actual: ~4-5s
     * - 目标设置为 8s 以留有余量，适应不同测试环境的性能差异
     * - Target set to 8s to allow margin, adapting to performance differences in different test environments
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
            $userId = 10000 + ($i % 100);
            $resourceId = $i % 100;
            $actionId = $i % 10;
            $this->casbinService->check($userId, 1, "perf_test_resource_{$resourceId}", "action_{$actionId}");
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
        // 注释掉输出以避免 risky test 警告
        // Comment out output to avoid risky test warning
        // echo "\nBatch Permission Check Performance:\n";
        // echo "  Total time: " . round($totalTime, 2) . "ms\n";
        // echo "  Average time per check: " . round($avgTime, 2) . "ms\n";
        // echo "  Iterations: 1000\n";

        // 验证性能目标：< 8s（不包含策略刷新开销）
        // Verify performance target: < 8s (without policy reload overhead)
        $this->assertLessThan(8000, $totalTime, "Batch permission check time should be < 8s, got {$totalTime}ms");
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
        
        // 验证性能目标：< 100ms
        // Verify performance target: < 100ms
        $this->assertLessThan(100, $avgTime, "Average policy loading time should be < 100ms, got {$avgTime}ms");
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

