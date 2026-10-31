<?php

declare(strict_types=1);

namespace app\controller\api\admin;

use app\controller\ApiController;
use Infrastructure\Permission\Service\PermissionService;

/**
 * 权限服务监控 API 控制器
 * Permission Service Monitor API Controller
 *
 * 提供权限服务性能监控和管理接口
 * Provides permission service performance monitoring and management APIs
 *
 * @package app\controller\api\admin
 */
class PermissionMonitorController extends ApiController
{
    /**
     * Permission Service 实例
     */
    protected PermissionService $permissionService;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->permissionService = new PermissionService();
    }

    /**
     * 获取缓存统计信息
     * Get Cache Statistics
     *
     * @return \think\Response
     */
    public function getCacheStats(): \think\Response
    {
        try {
            $stats = $this->permissionService->getCacheStats();

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => $stats,
                'timestamp' => time(),
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => 'Failed to get cache stats: ' . $e->getMessage(),
                'timestamp' => time(),
            ], 500);
        }
    }

    /**
     * 获取性能指标
     * Get Performance Metrics
     *
     * @return \think\Response
     */
    public function getPerformanceMetrics(): \think\Response
    {
        try {
            $cacheService = $this->permissionService->getCacheService();

            if (!$cacheService) {
                return json([
                    'code' => 400,
                    'message' => 'Cache service not enabled',
                    'timestamp' => time(),
                ], 400);
            }

            $stats = $cacheService->getCacheStats();

            // 计算性能指标
            $metrics = [
                'cache' => [
                    'enabled' => true,
                    'l1_cache' => [
                        'hit_rate' => $stats['l1_cache']['hit_rate'],
                        'ttl' => $stats['l1_cache']['ttl'],
                        'hits' => $stats['l1_cache']['hits'],
                        'misses' => $stats['l1_cache']['misses'],
                    ],
                    'l2_cache' => [
                        'hit_rate' => $stats['l2_cache']['hit_rate'],
                        'ttl' => $stats['l2_cache']['ttl'],
                        'hits' => $stats['l2_cache']['hits'],
                        'misses' => $stats['l2_cache']['misses'],
                    ],
                    'overall' => [
                        'hit_rate' => $stats['overall']['overall_hit_rate'],
                        'total_queries' => $stats['overall']['total_queries'],
                        'avg_latency_ms' => $stats['overall']['avg_latency_ms'],
                        'p95_latency_ms' => $stats['overall']['p95_latency_ms'],
                    ],
                ],
                'targets' => [
                    'p95_latency_target_ms' => 100,
                    'concurrent_query_target' => 1000,
                    'hit_rate_target' => 90,
                ],
                'status' => [
                    'p95_latency_ok' => $stats['overall']['p95_latency_ms'] < 100,
                    'hit_rate_ok' => $stats['overall']['overall_hit_rate'] >= 90,
                ],
            ];

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => $metrics,
                'timestamp' => time(),
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => 'Failed to get performance metrics: ' . $e->getMessage(),
                'timestamp' => time(),
            ], 500);
        }
    }

    /**
     * 清除用户缓存
     * Clear User Cache
     *
     * @param int $userId
     * @return \think\Response
     */
    public function clearUserCache(int $userId): \think\Response
    {
        try {
            $this->permissionService->clearUserCache($userId);

            return json([
                'code' => 200,
                'message' => "User cache cleared for user ID: {$userId}",
                'data' => ['user_id' => $userId],
                'timestamp' => time(),
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => 'Failed to clear user cache: ' . $e->getMessage(),
                'timestamp' => time(),
            ], 500);
        }
    }

    /**
     * 清除所有缓存
     * Clear All Cache
     *
     * @return \think\Response
     */
    public function clearAllCache(): \think\Response
    {
        try {
            $this->permissionService->clearAllCache();

            return json([
                'code' => 200,
                'message' => 'All permission caches cleared',
                'timestamp' => time(),
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => 'Failed to clear all caches: ' . $e->getMessage(),
                'timestamp' => time(),
            ], 500);
        }
    }

    /**
     * 获取用户权限
     * Get User Permissions
     *
     * @param int $userId
     * @return \think\Response
     */
    public function getUserPermissions(int $userId): \think\Response
    {
        try {
            $permissions = $this->permissionService->getUserPermissions($userId);

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'user_id' => $userId,
                    'permissions' => $permissions,
                    'count' => count($permissions),
                ],
                'timestamp' => time(),
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => 'Failed to get user permissions: ' . $e->getMessage(),
                'timestamp' => time(),
            ], 500);
        }
    }

    /**
     * 检查用户权限
     * Check User Permission
     *
     * @param int $userId
     * @param string $permission
     * @return \think\Response
     */
    public function checkPermission(int $userId, string $permission): \think\Response
    {
        try {
            $hasPermission = $this->permissionService->hasPermission($userId, $permission);

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'user_id' => $userId,
                    'permission' => $permission,
                    'has_permission' => $hasPermission,
                ],
                'timestamp' => time(),
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => 'Failed to check permission: ' . $e->getMessage(),
                'timestamp' => time(),
            ], 500);
        }
    }

    /**
     * 健康检查
     * Health Check
     *
     * @return \think\Response
     */
    public function healthCheck(): \think\Response
    {
        try {
            $cacheService = $this->permissionService->getCacheService();

            $health = [
                'status' => 'ok',
                'timestamp' => time(),
                'services' => [
                    'permission_service' => 'ok',
                    'cache_service' => $cacheService ? 'ok' : 'disabled',
                ],
                'checks' => [],
            ];

            // 检查缓存服务
            if ($cacheService) {
                $stats = $cacheService->getCacheStats();

                // 检查 P95 延迟
                $p95Latency = $stats['overall']['p95_latency_ms'];
                $health['checks']['p95_latency'] = [
                    'status' => $p95Latency < 100 ? 'ok' : 'warning',
                    'value' => $p95Latency,
                    'threshold' => 100,
                    'unit' => 'ms',
                ];

                // 检查命中率
                $hitRate = $stats['overall']['overall_hit_rate'];
                $health['checks']['hit_rate'] = [
                    'status' => $hitRate >= 90 ? 'ok' : 'warning',
                    'value' => $hitRate,
                    'threshold' => 90,
                    'unit' => '%',
                ];
            }

            $overallStatus = 'ok';
            foreach ($health['checks'] as $check) {
                if ($check['status'] === 'warning') {
                    $overallStatus = 'warning';
                }
            }

            $health['status'] = $overallStatus;
            $statusCode = $overallStatus === 'ok' ? 200 : 206;

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => $health,
            ], $statusCode);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => 'Health check failed: ' . $e->getMessage(),
                'data' => [
                    'status' => 'error',
                    'timestamp' => time(),
                ],
            ], 500);
        }
    }
}
