<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use Infrastructure\Auth\JwtService;
use Infrastructure\I18n\LanguageService;
use think\facade\Log;

/**
 * Authentication Middleware | 认证中间件
 *
 * Validates JWT token and injects user/tenant context into request.
 * 验证 JWT token 并将用户/租户上下文注入到请求中。
 *
 * Authentication failure reasons | 认证失败原因分类：
 * - token_missing: Authorization header not provided
 * - token_invalid: Token format error or signature verification failed
 * - token_expired: Token has expired
 * - token_revoked: Token has been revoked
 * - decode_error: Failed to decode token payload
 *
 * @package app\middleware
 */
class Auth
{
    protected JwtService $jwtService;
    protected LanguageService $langService;

    public function __construct()
    {
        $this->jwtService = new JwtService();
        $this->langService = app()->make(LanguageService::class);
    }

    /**
     * Log authentication failure with detailed context | 记录认证失败及详细上下文
     *
     * @param Request $request Request instance
     * @param string $reason Failure reason (token_missing|token_invalid|token_expired|token_revoked|decode_error)
     * @param array $context Additional context
     * @return void
     */
    protected function logAuthFailure(Request $request, string $reason, array $context = []): void
    {
        // Get trace_id for observability | 获取 trace_id 用于可观测性
        $traceId = $this->getTraceId($request);

        // Get tenant_id if available | 获取 tenant_id（如果可用）
        $tenantId = null;
        if (method_exists($request, 'getTenantId')) {
            $tenantId = $request->getTenantId();
        }

        Log::warning('Authentication failed', array_merge([
            'reason'     => $reason,
            'trace_id'   => $traceId,
            'tenant_id'  => $tenantId,
            'client_ip'  => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'path'       => $request->path(),
            'method'     => $request->method(),
            'timestamp'  => time(),
        ], $context));
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
            // Log authentication failure | 记录认证失败
            $this->logAuthFailure($request, 'token_missing');

            // Get trace_id for observability | 获取 trace_id 用于可观测性
            $traceId = $this->getTraceId($request);

            return ResponseHelper::jsonError(
                2001,
                $this->langService->trans('error.token_missing'),
                401,
                $traceId
            );
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
            // Determine failure reason based on exception | 根据异常确定失败原因
            $reason = 'token_invalid';
            $errorCode = 2001;
            $errorMessage = $this->langService->trans('error.token_invalid');

            // Classify error type | 分类错误类型
            $exceptionMessage = $e->getMessage();
            if (strpos($exceptionMessage, 'Expired') !== false || strpos($exceptionMessage, 'expired') !== false) {
                $reason = 'token_expired';
                $errorCode = 2002;
                $errorMessage = $this->langService->trans('error.token_expired');
            } elseif (strpos($exceptionMessage, 'revoked') !== false || strpos($exceptionMessage, 'Revoked') !== false) {
                $reason = 'token_revoked';
                $errorCode = 2003;
                $errorMessage = 'Token has been revoked';
            } elseif (strpos($exceptionMessage, 'decode') !== false || strpos($exceptionMessage, 'Decode') !== false) {
                $reason = 'decode_error';
            }

            // Log authentication failure with exception details | 记录认证失败及异常详情
            $this->logAuthFailure($request, $reason, [
                'exception' => get_class($e),
                'error_message' => $exceptionMessage,
            ]);

            // Get trace_id for observability | 获取 trace_id 用于可观测性
            $traceId = $this->getTraceId($request);

            return ResponseHelper::jsonError(
                $errorCode,
                $errorMessage,
                401,
                $traceId
            );
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
