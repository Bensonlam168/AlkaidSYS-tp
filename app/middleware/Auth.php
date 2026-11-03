<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;
use Infrastructure\Auth\JwtService;
use Infrastructure\Auth\PrincipalStateChecker;
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
 * - user_not_found: 令牌中的用户在所属租户内已不存在（被删除，或被改到其它租户）
 * - user_inactive: 用户状态为 `inactive`
 * - user_locked: 用户状态为 `locked`
 * - tenant_not_found: 令牌中的租户行不存在
 * - tenant_unusable: 租户状态为 `inactive` / `suspended`
 * - tenant_expired: 租户 `expires_at` 已过期
 * - principal_check_error: 主体状态校验自身失败（数据库不可用等），返回 500 而非 401
 *
 * ## 会话失效闭环（ER-04）
 *
 * 历史实现只做三件事：`validateAccessToken()`（签名/过期/type=access）+ 把
 * `user_id` / `tenant_id` / `site_id` 从 payload 写进 Request + 放行。
 * **从不回查用户与租户的当前状态**，后果是：
 *   管理员把用户设为 `inactive` / `locked`，或把租户设为 `inactive` / `suspended`、
 *   或租户 `expires_at` 已过期，该用户**已签发的** Access Token 在 2 小时有效期内仍然完全可用。
 *
 * 现在 token 校验通过后、`$next($request)` 之前，追加一次主体状态闭环校验
 * （`Infrastructure\Auth\PrincipalStateChecker`）：
 *   1. 用户必须仍存在于令牌声明的租户内；
 *   2. 用户状态必须是 `active`；
 *   3. 租户必须可用（存在 + `active` + 未过期，判据复用 `TenantUsability`）。
 * 任一不满足 → **401 + 2001**（不是 403：会话失效属于认证层，前端应「重新登录」而不是「权限不足」）。
 *
 * 上下文写入**先于**状态校验：即使请求最终被拒，审计日志/访问日志仍能记录
 * 「是哪个 user_id / tenant_id 发起的」——排障时最需要的信息不会因为拒绝而丢失。
 *
 * @package app\middleware
 */
class Auth
{
    protected JwtService $jwtService;
    protected LanguageService $langService;

    /**
     * 主体状态校验器（ER-04）| Principal state checker
     *
     * 每次请求真查 `users` / `tenants` 主键各一次，**不做任何缓存**（含 static），
     * 理由见 `Infrastructure\Auth\PrincipalStateChecker` 类注释。
     */
    protected PrincipalStateChecker $principalStateChecker;

    public function __construct()
    {
        $this->jwtService = new JwtService();
        $this->langService = app()->make(LanguageService::class);
        $this->principalStateChecker = new PrincipalStateChecker();
    }

    /**
     * Log authentication failure with detailed context | 记录认证失败及详细上下文
     *
     * @param Request $request Request instance
     * @param string $reason Failure reason (token_missing|token_invalid|token_expired|token_revoked|decode_error|invalid_tenant_scope|user_not_found|user_inactive|user_locked|tenant_not_found|tenant_unusable|tenant_expired)
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

            // 会话失效闭环（ER-04）：令牌有效 ≠ 主体仍然有效
            //
            // 放在上下文写入**之后**：请求即使被拒，审计/访问日志里仍留得下主体身份。
            // 放在 `$next($request)` **之前**：被停用的主体不会进入任何业务逻辑。
            $stateResponse = $this->rejectIfPrincipalUnusable(
                $request,
                (int) ($payload['user_id'] ?? 0),
                (int) ($payload['tenant_id'] ?? 0)
            );
            if ($stateResponse !== null) {
                return $stateResponse;
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
     * Reject when the authenticated principal is no longer usable | 主体已不可用时拒绝请求
     *
     * 仅在 `validateAccessToken()` 通过之后调用：此时令牌本身可信，
     * 但「令牌可信」不代表「主体现在仍然可用」（ER-04）。
     *
     * 返回值的三种语义：
     *   - `null`        ：主体可用，继续走 `$next($request)`；
     *   - `Response(401)`: 用户/租户已不可用 —— 401 + 2001 + `error.session_invalid`；
     *   - `Response(500)`: 校验过程自身失败（数据库不可用等）。
     *     这里**刻意不返回 401**：把一次数据库故障说成「会话失效」会让全站用户被登出，
     *     真正的原因反而被掩盖。500 同样是 fail-closed（请求不会继续进入业务逻辑）。
     *
     * 对外文案统一为 `error.session_invalid`，**不区分**具体原因：
     * reason（`user_not_found` / `user_inactive` / `user_locked` / `tenant_not_found` /
     * `tenant_unusable` / `tenant_expired`）只写日志。
     * 取舍说明：区分原因对排障有用，但会让持有合法签名令牌的一方得以判断
     * 「该用户是否存在」「该租户是否被停用/欠费」——那属于账号与运营状态泄露，
     * 而客户端对这五种情况应做的事完全相同（清本地会话 → 重新登录）。
     * 排障需求由日志（含 reason）+ 响应里的 `trace_id` 共同满足。
     *
     * @param Request $request 当前请求
     * @param int $userId 令牌中的用户 ID
     * @param int $tenantId 令牌中的租户 ID
     * @return Response|null 需要拒绝时返回响应；放行时返回 null
     */
    protected function rejectIfPrincipalUnusable(Request $request, int $userId, int $tenantId): ?Response
    {
        try {
            $reason = $this->principalStateChecker->check($userId, $tenantId);
        } catch (\Throwable $e) {
            // 校验依赖数据库：查不出来就不能放行（fail-closed）
            Log::error('Authentication principal state check failed', [
                'reason'        => 'principal_check_error',
                'exception'     => get_class($e),
                'error_message' => $e->getMessage(),
                'user_id'       => $userId,
                'tenant_id'     => $tenantId,
                'path'          => '/' . ltrim($request->pathinfo(), '/'),
                'method'        => $request->method(),
                'trace_id'      => $this->getTraceId($request),
            ]);

            return ResponseHelper::jsonError(
                ErrorCode::SERVER_ERROR,
                $this->langService->trans('error.internal_server_error'),
                500,
                $this->getTraceId($request)
            );
        }

        if ($reason === null) {
            return null;
        }

        // 记录可区分的原因（对排障与审计至关重要），但对外只暴露统一文案
        $this->logAuthFailure($request, $reason, [
            'stage'     => 'principal_state',
            'user_id'   => $userId,
            'tenant_id' => $tenantId,
        ]);

        return ResponseHelper::jsonError(
            ErrorCode::UNAUTHORIZED,
            $this->langService->trans('error.session_invalid'),
            401,
            $this->getTraceId($request)
        );
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
