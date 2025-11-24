<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use Infrastructure\Auth\JwtService;

/**
 * Auth Middleware | 认证中间件
 * 
 * Validates JWT token and authenticates the user.
 * 验证JWT令牌并认证用户。
 * 
 * @package app\middleware
 */
class Auth
{
    protected JwtService $jwtService;

    public function __construct()
    {
        $this->jwtService = new JwtService();
    }

    /**
     * Handle request | 处理请求
     *
     * @param Request $request Custom request instance with tenant/user context
     * @param Closure $next Next middleware in the stack
     * @return mixed Response or next middleware result
     */
    public function handle(Request $request, Closure $next)
    {
        // Dev environment skip switch for Auth middleware | 仅在开发环境可通过环境变量跳过认证中间件
        if (env('APP_ENV') === 'dev' && env('AUTH_SKIP_MIDDLEWARE', false)) {
            return $next($request);
        }

        // Get token from Authorization header | 从Authorization头获取token
        $token = $this->getTokenFromRequest($request);

        if (!$token) {
            return json([
                'code'      => 2001,
                'message'   => 'Unauthorized: Token is missing, invalid, or expired',
                'data'      => null,
                'timestamp' => time(),
            ], 401);
        }

        try {
            // Validate token | 验证token
            $payload = $this->jwtService->validateToken($token);

            // Set user context to request (only if custom Request class is used) | 设置用户上下文到请求（仅当使用自定义Request类时）
            if (isset($payload['user_id']) && method_exists($request, 'setUserId')) {
                $request->setUserId((int)$payload['user_id']);
            }
            if (isset($payload['tenant_id']) && method_exists($request, 'setTenantId')) {
                $request->setTenantId((int)$payload['tenant_id']);
            }
            if (isset($payload['site_id']) && method_exists($request, 'setSiteId')) {
                $request->setSiteId((int)$payload['site_id']);
            }

            return $next($request);
        } catch (\Exception $e) {
            return json([
                'code'      => 2001,
                'message'   => 'Unauthorized: Token is missing, invalid, or expired',
                'data'      => null,
                'timestamp' => time(),
            ], 401);
        }
    }

    /**
     * Get token from request | 从请求获取token
     * 
     * @param \think\Request $request
     * @return string|null
     */
    protected function getTokenFromRequest($request): ?string
    {
        $authorization = $request->header('Authorization', '');
        
        if (empty($authorization)) {
            return null;
        }

        // Extract Bearer token | 提取Bearer token
        if (strpos($authorization, 'Bearer ') === 0) {
            return substr($authorization, 7);
        }

        return null;
    }
}
