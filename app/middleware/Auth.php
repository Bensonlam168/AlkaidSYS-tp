<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use Infrastructure\Auth\JwtService;
use Infrastructure\I18n\LanguageService;
use app\constant\ErrorCode;
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
 * - invalid_tenant_scope: Token payload carries non-positive user_id/tenant_id
 *   令牌中的 user_id / tenant_id 非正数（存量或伪造 token），对外仍返回 401 + 2001
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
     * @param string $reason Failure reason (token_missing|token_invalid|token_expired|token_revoked|decode_error|invalid_tenant_scope)
     * @param array $context Additional context
     * @return void
     */
    protected function logAuthFailure(Request $request, string $reason, array $context = []): void
    {
        // Get trace_id for observability | 获取 trace_id 用于可观测性
        $traceId = $this->getTraceId($request);

        // Get multi-tenant context if available | 获取多租户上下文（如果可用）
        $tenantId = null;
        $siteId   = null;
        $userId   = null;

        if (method_exists($request, 'getTenantContext')) {
            try {
                $tenantContext = $request->getTenantContext();
                $tenantId      = $tenantContext['tenant_id'] ?? null;
                $siteId        = $tenantContext['site_id'] ?? null;
                $userId        = $tenantContext['user_id'] ?? null;
            } catch (\Throwable $e) {
                // Fallback to individual getters below | 回退到单个 getter
            }
        }

        if ($tenantId === null && method_exists($request, 'tenantId')) {
            try {
                $tenantId = $request->tenantId();
            } catch (\Throwable $e) {
                $tenantId = null;
            }
        }
        if ($siteId === null && method_exists($request, 'siteId')) {
            try {
                $siteId = $request->siteId();
            } catch (\Throwable $e) {
                $siteId = null;
            }
        }
        if ($userId === null && method_exists($request, 'userId')) {
            try {
                $userId = $request->userId();
            } catch (\Throwable $e) {
                $userId = null;
            }
        }

        $path = '/' . ltrim($request->pathinfo(), '/');

        // M7：把 reason 写进**日志正文**（而不只是 context 数组）
        //
        // ThinkPHP 8 的 Channel::record() 对第二个参数只做 `{key}` 字符串插值
        // （见 vendor/topthink/framework/src/think/log/Channel.php），
        // 文件驱动 File::save() 最终只输出 time/type/msg 三个字段，
        // 因此原先写在 context 里的 reason/trace_id/tenant_id 等**根本不会落盘**：
        // 日志里只剩一句 "Authentication failed"，任何失败原因都不可见。
        // 这里用 `{reason}` 占位符让归类结果真正出现在日志正文里，
        // 其余 context 字段保留（便于将来换用支持结构化 context 的通道）。
        Log::warning('Authentication failed: {reason}', array_merge([
            'reason'     => $reason,
            'trace_id'   => $traceId,
            'tenant_id'  => $tenantId,
            'site_id'    => $siteId,
            'user_id'    => $userId,
            'client_ip'  => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'path'       => $path,
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
                ErrorCode::UNAUTHORIZED,
                $this->langService->trans('error.token_missing'),
                401,
                $traceId
            );
        }

        try {
            // Validate ACCESS token only | 仅校验 Access Token
            //
            // 安全（缺陷 C-6）：受保护接口必须拒绝 Refresh Token。
            // validateToken() 只验签名不校验 token 类型，会让 refresh token
            // 直接获得接口访问权限；validateAccessToken() 会额外校验 type=access。
            $payload = $this->jwtService->validateAccessToken($token);

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
            $reason       = 'token_invalid';
            $errorCode    = ErrorCode::UNAUTHORIZED;
            $errorMessage = $this->langService->trans('error.token_invalid');

            // Classify error type | 分类错误类型
            $exceptionMessage = $e->getMessage();
            if (strpos($exceptionMessage, 'Expired') !== false || strpos($exceptionMessage, 'expired') !== false) {
                $reason       = 'token_expired';
                $errorCode    = ErrorCode::UNAUTHORIZED;
                $errorMessage = $this->langService->trans('error.token_expired');
            } elseif (strpos($exceptionMessage, 'revoked') !== false || strpos($exceptionMessage, 'Revoked') !== false) {
                $reason       = 'token_revoked';
                $errorCode    = ErrorCode::UNAUTHORIZED;
                $errorMessage = $this->langService->trans('error.token_revoked');
            } elseif (strpos($exceptionMessage, 'decode') !== false || strpos($exceptionMessage, 'Decode') !== false) {
                $reason = 'decode_error';
            } elseif (strpos($exceptionMessage, 'INVALID_TENANT_SCOPE') !== false) {
                // M7：租户/用户上下文非法（user_id 或 tenant_id 非正数）单独归类
                //
                // 这类异常来自 JwtService::validateAccessToken() 的校验侧兜底（缺陷 R19）：
                // 覆盖「改造前签发的存量 token」与「持同一密钥的伪造 token」——它们都不经过签发侧。
                // 原先落进兜底分支被记成 token_invalid，日志里与「令牌格式错误/签名失败」无法区分，
                // 排查时看不出「token 合法但租户上下文为 0」这一独立故障形态。
                //
                // 注意：本分支只改**日志 reason**，不改对外响应——
                // errorCode / errorMessage / HTTP 状态仍保持 ErrorCode::UNAUTHORIZED(2001) + error.token_invalid + 401。
                // 分支顺序：该消息不包含 expired/revoked/decode，放在兜底之前即可，不会与其他分支冲突。
                $reason = 'invalid_tenant_scope';
            }

            // Log authentication failure with exception details | 记录认证失败及异常详情
            $this->logAuthFailure($request, $reason, [
                'exception'     => get_class($e),
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
