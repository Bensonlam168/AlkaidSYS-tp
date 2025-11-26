<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use Infrastructure\Permission\Service\PermissionService;
use Infrastructure\I18n\LanguageService;
use think\Request;
use think\facade\Log;

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
    /**
     * Permission service for RBAC checks | 权限服务（用于 RBAC 校验）
     */
    protected PermissionService $permissionService;

    /**
     * Language service for i18n | 语言服务（用于国际化）
     */
    protected LanguageService $langService;

    public function __construct()
    {
        // Use dedicated PermissionService as the single source of truth
        // 使用专门的 PermissionService 作为权限校验的单一入口
        $this->permissionService = new PermissionService();
        $this->langService = app()->make(LanguageService::class);
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
                $this->langService->trans('error.token_missing'),
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
                    $this->langService->trans('error.permission_denied'),
                    403,
                    $traceId
                );
            }

            return $next($request);
        } catch (\Exception $e) {
            // Get trace_id for observability and log internal error
            // 获取 trace_id 并记录内部错误日志
            $traceId = $this->getTraceId($request);

            Log::error('Internal error in Permission middleware', [
                'message'     => $e->getMessage(),
                'exception'   => $e,
                'trace_id'    => $traceId,
                'user_id'     => $userId,
                'permission'  => $permission,
                'request_uri' => $request->url(),
                'ip'          => $request->ip(),
            ]);

            // Return generic internal error response to client | 对客户端仅返回通用内部错误响应
            return ResponseHelper::jsonError(
                5000,
                'Internal server error',
                500,
                $traceId
            );
        }
    }

    /**
     * Check if user has permission | 检查用户是否有权限
     *
     * This method accepts either the internal slug format (`resource.action`)
     * or the external code format (`resource:action`) and normalizes it to
     * `resource:action` before delegating to PermissionService.
     *
     * 本方法同时支持内部 slug 形式（`resource.action`）和对外权限码形式
     *（`resource:action`），并在委托给 PermissionService 之前统一转换为
     * `resource:action` 格式。
     *
     * @param int $userId User ID | 用户ID
     * @param string $permission Permission identifier in slug or code format
     *                           | slug 或对外权限码格式的权限标识符
     * @return bool
     */
    protected function checkUserPermission(int $userId, string $permission): bool
    {
        // Normalize permission identifier to external code format (resource:action)
        // 将权限标识符统一转换为对外权限码格式（resource:action）
        if (str_contains($permission, '.') && !str_contains($permission, ':')) {
            // Internal slug (resource.action) -> External code (resource:action)
            // 内部 slug（resource.action）-> 对外权限码（resource:action）
            $permission = str_replace('.', ':', $permission);
        }

        // Delegate to PermissionService to enforce a single permission model
        // 委托给 PermissionService，以确保权限模型的一致性和可维护性
        return $this->permissionService->hasPermission($userId, $permission);
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
