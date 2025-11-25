<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use Infrastructure\User\Repository\UserRepository;
use think\facade\Db;

/**
 * Permission Middleware | 权限校验中间件
 * 
 * Checks if user has required permission.
 * 检查用户是否具有所需权限。
 * 
 * @package app\middleware
 */
class Permission
{
    protected UserRepository $userRepository;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
    }

    /**
     * Handle request | 处理请求
     *
     * @param Request $request Custom request instance with tenant/user context
     * @param Closure $next Next middleware in the stack
     * @param string $permission Permission slug required | 所需权限标识符
     * @return mixed Response or next middleware result
     */
    public function handle(Request $request, Closure $next, string $permission = '')
    {
        // Dev environment skip switch for Permission middleware | 仅在开发环境可通过环境变量跳过权限中间件
        if (env('APP_ENV') === 'dev' && env('PERMISSION_SKIP_MIDDLEWARE', false)) {
            return $next($request);
        }

        // Get user ID from request (only if custom Request class is used) | 从请求获取用户ID（仅当使用自定义Request类时）
        $userId = null;
        if (method_exists($request, 'userId')) {
            $userId = $request->userId();
        }

        if (!$userId) {
            // Get trace_id for observability | 获取 trace_id 用于可观测性
            $traceId = $this->getTraceId($request);

            return ResponseHelper::jsonError(
                2001,
                'Unauthorized: Token is missing, invalid, or expired',
                401,
                $traceId
            );
        }

        // If no specific permission required, just check authentication | 如果不需要特定权限，仅检查认证
        if (empty($permission)) {
            return $next($request);
        }

        try {
            // Check if user has permission | 检查用户是否有权限
            $hasPermission = $this->checkUserPermission($userId, $permission);

            if (!$hasPermission) {
                // Get trace_id for observability | 获取 trace_id 用于可观测性
                $traceId = $this->getTraceId($request);

                return ResponseHelper::jsonError(
                    2002,
                    'Forbidden: Insufficient permissions',
                    403,
                    $traceId
                );
            }

            return $next($request);
        } catch (\Exception $e) {
            // Get trace_id for observability | 获取 trace_id 用于可观测性
            $traceId = $this->getTraceId($request);

            return ResponseHelper::jsonError(
                5000,
                'Permission check failed: ' . $e->getMessage(),
                500,
                $traceId
            );
        }
    }

    /**
     * Check if user has permission | 检查用户是否有权限
     * 
     * @param int $userId User ID | 用户ID
     * @param string $permission Permission slug | 权限标识符
     * @return bool
     */
    protected function checkUserPermission(int $userId, string $permission): bool
    {
        // Get user's role IDs | 获取用户的角色ID
        $roleIds = $this->userRepository->getRoleIds($userId);

        if (empty($roleIds)) {
            return false;
        }

        // Get permission ID by slug | 通过slug获取权限ID
        $permissionId = Db::name('permissions')
            ->where('slug', $permission)
            ->value('id');

        if (!$permissionId) {
            return false;
        }

        // Check if any of user's roles have this permission | 检查用户的任一角色是否有此权限
        $count = Db::name('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permissionId)
            ->count();

        return $count > 0;
    }

    /**
     * Get trace ID from request | 从请求中获取 trace ID
     *
     * @param Request $request Request instance | 请求实例
     * @return string|null Trace ID or null | Trace ID 或 null
     */
    protected function getTraceId(Request $request): ?string
    {
        // Priority 1: Get from Request object (injected by Trace middleware)
        // 优先级 1：从 Request 对象获取（由 Trace 中间件注入）
        if (method_exists($request, 'getTraceId')) {
            $traceId = $request->getTraceId();
            if (is_string($traceId) && $traceId !== '') {
                return $traceId;
            }
        }

        // Priority 2: Fallback to request header
        // 优先级 2：降级到请求头
        $traceId = $request->header('X-Trace-Id');
        if (is_string($traceId) && $traceId !== '') {
            return $traceId;
        }

        return null;
    }
}
