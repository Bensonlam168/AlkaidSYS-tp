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
            return json([
                'code'      => 2001,
                'message'   => 'Unauthorized: Token is missing, invalid, or expired',
                'data'      => null,
                'timestamp' => time(),
            ], 401);
        }

        // If no specific permission required, just check authentication | 如果不需要特定权限，仅检查认证
        if (empty($permission)) {
            return $next($request);
        }

        try {
            // Check if user has permission | 检查用户是否有权限
            $hasPermission = $this->checkUserPermission($userId, $permission);

            if (!$hasPermission) {
                return json([
                    'code'      => 2002,
                    'message'   => 'Forbidden: Insufficient permissions',
                    'data'      => null,
                    'timestamp' => time(),
                ], 403);
            }

            return $next($request);
        } catch (\Exception $e) {
            // Use unified error response structure and standard internal error code
            $traceId = method_exists($request, 'traceId') ? $request->traceId() : null;

            $response = [
                'code'      => 5000,
                'message'   => 'Permission check failed: ' . $e->getMessage(),
                'data'      => null,
                'timestamp' => time(),
            ];

            if ($traceId !== null) {
                $response['trace_id'] = $traceId;
            }

            return json($response, 500);
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
}
