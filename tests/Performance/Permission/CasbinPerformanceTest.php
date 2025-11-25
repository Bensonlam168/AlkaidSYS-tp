<?php

declare(strict_types=1);

namespace Tests\Performance\Permission;

use Infrastructure\Permission\Service\CasbinService;
use Infrastructure\Permission\Service\PermissionService;
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

    protected function setUp(): void
    {
        parent::setUp();
        
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
        
        // 生成性能报告
        // Generate performance report
        $this->generatePerformanceReport();
        
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
        
        // 插入用户角色关联（每个用户 1 个角色）
        // Insert user-role associations (1 role per user)
        $userRoles = [];
        for ($userId = 10000; $userId < 11000; $userId++) {
            $roleId = 10000 + (($userId - 10000) % 100);
            $userRoles[] = [
                'user_id' => $userId,
                'role_id' => $roleId,
            ];
        }
        Db::table('user_roles')->insertAll($userRoles);
        
        // 插入角色权限关联（每个角色 10 个权限）
        // Insert role-permission associations (10 permissions per role)
        $rolePermissions = [];
        for ($roleId = 10000; $roleId < 10100; $roleId++) {
            for ($j = 0; $j < 10; $j++) {
                $permId = 10000 + ((($roleId - 10000) * 10 + $j) % 1000);
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
        
        // 记录性能数据
        // Record performance data
        $this->performanceReport['single_check'] = [
            'avg_time_ms' => round($avgTime, 2),
            'max_time_ms' => round($maxTime, 2),
            'min_time_ms' => round($minTime, 2),
            'iterations' => 100,
        ];
        
        // 验证性能目标：< 10ms
        // Verify performance target: < 10ms
        $this->assertLessThan(10, $avgTime, "Average permission check time should be < 10ms, got {$avgTime}ms");
    }

    /**
     * 测试批量权限检查性能
     * Test batch permission check performance
     */
    public function testBatchPermissionCheckPerformance(): void
    {
        // 配置 CASBIN_ONLY 模式
        // Configure CASBIN_ONLY mode
        Config::set(['casbin.mode' => 'CASBIN_ONLY']);
        
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
        
        // 记录性能数据
        // Record performance data
        $this->performanceReport['batch_check'] = [
            'total_time_ms' => round($totalTime, 2),
            'avg_time_ms' => round($totalTime / 1000, 2),
            'iterations' => 1000,
        ];
        
        // 验证性能目标：< 10s（考虑到策略重新加载）
        // Verify performance target: < 10s (considering policy reload)
        $this->assertLessThan(10000, $totalTime, "Batch permission check time should be < 10s, got {$totalTime}ms");
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
        
        // 验证 CASBIN_ONLY 性能优于 DB_ONLY
        // Verify CASBIN_ONLY is faster than DB_ONLY
        $this->assertLessThan(
            $results['DB_ONLY']['avg_time_ms'],
            $results['CASBIN_ONLY']['avg_time_ms'],
            "CASBIN_ONLY should be faster than DB_ONLY"
        );
    }

    /**
     * 生成性能报告
     * Generate performance report
     */
    protected function generatePerformanceReport(): void
    {
        if (empty($this->performanceReport)) {
            return;
        }
        
        $report = "# Casbin Performance Benchmark Report\n\n";
        $report .= "**Date**: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($this->performanceReport as $testName => $data) {
            $report .= "## " . ucfirst(str_replace('_', ' ', $testName)) . "\n\n";
            $report .= "```\n";
            $report .= json_encode($data, JSON_PRETTY_PRINT);
            $report .= "\n```\n\n";
        }
        
        // 保存报告
        // Save report
        file_put_contents(
            __DIR__ . '/../../../docs/report/casbin-performance-report-' . date('Y-m-d') . '.md',
            $report
        );
    }
}

