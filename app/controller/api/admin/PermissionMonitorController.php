<?php

declare(strict_types=1);

namespace app\controller\api\admin;

use app\controller\ApiController;
use Infrastructure\Permission\Service\PermissionService;
use Infrastructure\User\Repository\UserRepository;
use think\App;

/**
 * 权限服务监控 API 控制器
 * Permission Service Monitor API Controller
 *
 * 提供权限服务性能监控和管理接口
 * Provides permission service performance monitoring and management APIs
 *
 * ## 租户隔离（跨租户越权修复）
 *
 * 本控制器所有按 `:userId` 操作的接口，在调用 `PermissionService` **之前**会先做一次
 * 归属校验（`ensureUserInCurrentTenant()`）：目标用户必须属于当前请求租户。
 *
 * 为什么必须有这道校验：`PermissionService::getUserPermissions()/hasPermission()/clearUserCache()`
 * 内部是「按 userId 反查该用户自己的 `users.tenant_id`」，**不含请求租户维度**。
 * 也就是说服务层是「以被查用户为准」，而不是「以调用者租户为准」——只要拿到任意 userId，
 * 就能读出/清掉**其它租户**用户的权限状态（实测：租户 1 的 admin 读取租户 2 用户的
 * `/user/<id>/permissions` 返回 200 + 该用户的权限集）。
 *
 * 归属校验失败统一按 **404** 处理（而不是 403）：403 会泄露「该 id 存在但不属于你」，
 * 404 与「用户不存在」不可区分，避免跨租户的用户 ID 探测。
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
     * 用户仓储（仅用于租户归属校验）
     * User repository (used only for tenant ownership validation)
     */
    protected UserRepository $userRepository;

    /**
     * 构造函数
     *
     * 注意：必须显式调用 `parent::__construct($app)`。历史实现是 `__construct()` 且**未调用父类**，
     * 导致 `$this->app / $this->request / $this->langService` 全部未初始化
     * （表现之一：本控制器所有响应都缺 `trace_id`）。租户归属校验依赖 `$this->request`，
     * 因此这里一并对齐基类契约。
     */
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->permissionService = new PermissionService();
        $this->userRepository = new UserRepository();
    }

    /**
     * 校验目标用户属于当前请求租户
     * Ensure the target user belongs to the current request tenant
     *
     * 成功返回 null；失败返回可直接 `return` 的错误响应。
     *
     * 租户上下文取自 `app\Request::tenantId()`（Auth 中间件已按 JWT 的 `tenant_id` 覆盖写入）：
     * 解析不出时该方法会抛 `TenantContextMissingException`（fail-closed），
     * 由 `app\ExceptionHandle` 统一映射为 400 + 1001 / 500 + 5000，控制器不重复兜底。
     *
     * @param int $userId 目标用户 ID
     * @return \think\Response|null 校验通过返回 null，否则返回 404 错误响应
     */
    protected function ensureUserInCurrentTenant(int $userId): ?\think\Response
    {
        $tenantId = $this->request->tenantId();

        if ($this->userRepository->findById($userId, $tenantId) === null) {
            // 刻意不区分「用户不存在」与「用户属于其它租户」：两者都返回 404，
            // 否则 403/404 的差异本身就构成跨租户的用户 ID 探测通道。
            //
            // 注意 `ApiController::error()` 的第 2 个参数是**业务码**、第 4 个才是 HTTP 状态码，
            // 所以这里显式把 HTTP 也设为 404（只传 `error($msg, 404)` 会得到 HTTP 400 + 业务码 404）。
            return $this->error('用户不存在', 404, [], 404);
        }

        return null;
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
        // 跨租户防护：只允许清理**本租户**用户的权限缓存
        // Cross-tenant guard: only users of the current tenant may be cleared
        $ownershipError = $this->ensureUserInCurrentTenant($userId);
        if ($ownershipError !== null) {
            return $ownershipError;
        }

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
        // 跨租户防护：目标用户必须属于当前请求租户
        // Cross-tenant guard: the target user must belong to the current request tenant
        $ownershipError = $this->ensureUserInCurrentTenant($userId);
        if ($ownershipError !== null) {
            return $ownershipError;
        }

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
        // 跨租户防护：目标用户必须属于当前请求租户
        // Cross-tenant guard: the target user must belong to the current request tenant
        $ownershipError = $this->ensureUserInCurrentTenant($userId);
        if ($ownershipError !== null) {
            return $ownershipError;
        }

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
