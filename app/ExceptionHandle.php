<?php

namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\facade\Log;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
        // 业务层拒绝（如 C-4 注册租户不可用）已在业务侧记录 warning，避免重复记 error 日志
        \app\exception\ForbiddenException::class,
        // 租户上下文缺失：由 render() 按「请求级/配置级」分别记 warning / error，
        // 这里的报告交给 render()，避免同一条失败被打两条日志（且级别不同会互相干扰告警）
        \app\exception\TenantContextMissingException::class,
        // 站点标识非法：同样由 render() 记 warning（带 reason 与原始取值），避免重复记 error
        \app\exception\InvalidSiteContextException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response | 将异常渲染为HTTP响应
     *
     * @access public
     * @param \think\Request $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        // Get trace ID if available | 获取追踪ID（如果可用）
        $traceId = method_exists($request, 'traceId') ? $request->traceId() : null;

        // Get environment | 获取环境
        $env = env('APP_ENV', 'production');
        $isDebug = in_array($env, ['dev', 'development', 'local'], true);

        // Handle different exception types | 处理不同类型的异常

        // 1. Validation Exception | 验证异常
        if ($e instanceof ValidateException) {
            return $this->buildJsonResponse(
                422,
                'Validation failed | 验证失败',
                422,  // Unified validation error code | 统一的验证错误码
                ['errors' => $e->getError()],
                $traceId,
                $isDebug ? $e : null
            );
        }

        // 2. Model Not Found Exception | 模型未找到异常
        if ($e instanceof ModelNotFoundException || $e instanceof DataNotFoundException) {
            return $this->buildJsonResponse(
                404,
                'Resource not found | 资源不存在',
                4004,
                null,
                $traceId,
                $isDebug ? $e : null
            );
        }

        // 2.5 Domain-level forbidden exception | 业务层拒绝访问异常
        //
        // 例如注册目标租户无法解析或不可用（C-4 安全修复），
        // 统一映射为 HTTP 403 + 业务码（默认 2002，注册场景为 2008）。
        if ($e instanceof \app\exception\ForbiddenException) {
            return $this->buildJsonResponse(
                403,
                $e->getMessage() ?: 'Forbidden | 禁止访问',
                (int) $e->getCode() ?: 2002,
                null,
                $traceId,
                $isDebug ? $e : null
            );
        }

        // 2.6 Tenant context missing | 租户上下文缺失（fail-closed 的显式失败出口）
        //
        // 来源：`Request::tenantId()` 解析不出租户时（无已设值 + 无有效 `X-Tenant-ID`），
        // 以及 `TenantIdentify::getDefaultTenantId()` 发现 `DEFAULT_TENANT_ID` 未配置时。
        // 两者对外语义不同，必须分开映射：
        //   - 请求级（REASON_REQUEST）：调用方没有提供租户上下文 → HTTP 400 + 业务码 1001
        //     （PARAMETER_MISSING，缺少必需参数），提示补 `X-Tenant-ID`；
        //   - 配置级（REASON_CONFIG）：服务端部署配错 → HTTP 500 + 业务码 5000（SERVER_ERROR）。
        //     生产环境对外只给通用文案，可操作细节（怎么配 DEFAULT_TENANT_ID）只进日志，
        //     避免向外部暴露部署内部信息；dev/local 环境直接返回完整信息便于定位。
        if ($e instanceof \app\exception\TenantContextMissingException) {
            $isConfigError = $e->getReason() === \app\exception\TenantContextMissingException::REASON_CONFIG;

            $context = [
                'reason'   => $e->getReason(),
                'message'  => $e->getMessage(),
                'path'     => method_exists($request, 'pathinfo') ? $request->pathinfo() : null,
                'method'   => method_exists($request, 'method') ? $request->method() : null,
                'trace_id' => $traceId,
            ];

            if ($isConfigError) {
                // 配置错误必须能被值班看到：这是「服务端自己坏了」，不是调用方的问题
                $this->safeLog(
                    'error',
                    '[TENANT_CONTEXT_MISSING_CONFIG] 租户上下文不可用：DEFAULT_TENANT_ID 未配置或非正整数，'
                        . '且请求未携带 X-Tenant-ID header（path=' . ($context['path'] ?? '') . '）',
                    $context
                );
            } else {
                // 请求级缺失属于客户端可修正的问题，记 warning 即可，避免刷 error 栈
                $this->safeLog(
                    'warning',
                    '[TENANT_CONTEXT_MISSING_REQUEST] 租户上下文不可用：既无已写入的租户 ID，也无有效的 '
                        . 'X-Tenant-ID header（path=' . ($context['path'] ?? '') . '）',
                    $context
                );
            }

            return $this->buildJsonResponse(
                $isConfigError ? 500 : 400,
                ($isConfigError && !$isDebug)
                    ? 'Tenant context unavailable | 租户上下文不可用（请联系管理员检查 DEFAULT_TENANT_ID 配置）'
                    : $e->getMessage(),
                (int) $e->getCode() ?: 5000,
                null,
                $traceId,
                $isDebug ? $e : null
            );
        }

        // 2.7 Invalid site context | 站点标识非法（`X-Site-ID` 校验失败的显式出口）
        //
        // 来源：`app\middleware\SiteIdentify`，分两类原因：
        //   A. **格式/取值非法**（`malformed` / `negative` / `site_id_out_of_range`）——
        //      请求头本身不是合法的站点 ID；
        //   B. **站点归属/状态不允许**（`site_not_found` / `site_tenant_mismatch` /
        //      `site_unusable` / `tenant_context_missing`）—— 标识格式没问题，
        //      但该站点在 `sites` 里不存在、不属于当前租户，或状态不是 `active`。
        // 与 `TenantContextMissingException` 的**语义差异**：这不是「缺少上下文」，
        // 而是「**提供了站点标识，但标识本身非法 / 该站点不允许使用**」——
        // 「无站点」是合法的（按 0 全局桶处理），因此这里不存在「缺站点」对应的分支。
        //
        // 每个 reason 一个独立 tag（排查方向不同，tag 也可直接 grep）：
        //   - REASON_MALFORMED          ：不是整数（`abc` / `1.5` / `1e3`）→ 前端把非数字塞进 header；
        //   - REASON_NEGATIVE           ：负数（`-5` / `-1`）→ 多半把 `-1` 当成「未设置」哨兵；
        //   - REASON_SITE_ID_OUT_OF_RANGE：超出 `int unsigned` 上界 → 客户端算错了 ID；
        //   - REASON_SITE_NOT_FOUND     ：站点不存在 → 调用方写死了不存在的站点 id；
        //   - REASON_SITE_TENANT_MISMATCH：站点属于别的租户 → **跨租户探测**，安全事件关注项；
        //   - REASON_SITE_UNUSABLE      ：站点被停用 / 维护中 → 运营动作，通常成批出现；
        //   - REASON_TENANT_CONTEXT_MISSING：租户上下文缺失 → 中间件顺序被改动或绕过租户识别。
        // 对外映射一致：HTTP 403 + 业务码 2002（与 `invalid_tenant_header` 完全对齐，
        // 不新增错误码，保证前端既有的 2002 分支继续生效）。
        // ⚠️ 注意：`site_not_found` 与 `site_tenant_mismatch` 的**响应正文逐字节相同**，
        // 差别只在这条日志 tag 与 context.reason 里 —— 对外区分两者等于提供站点存在性探测器。
        if ($e instanceof \app\exception\InvalidSiteContextException) {
            $context = [
                'reason'    => $e->getReason(),
                'raw_value' => $e->getRawValue(),
                'message'   => $e->getMessage(),
                'path'      => method_exists($request, 'pathinfo') ? $request->pathinfo() : null,
                'method'    => method_exists($request, 'method') ? $request->method() : null,
                'trace_id'  => $traceId,
            ];

            $this->safeLog(
                'warning',
                $this->siteContextLogTag($e->getReason()) . '（path=' . ($context['path'] ?? '') . '）',
                $context
            );

            return $this->buildJsonResponse(
                403,
                $e->getMessage() ?: 'Invalid site context | 站点标识非法',
                (int) $e->getCode() ?: 2002,
                null,
                $traceId,
                $isDebug ? $e : null
            );
        }

        // 3. HTTP Exception | HTTP异常
        if ($e instanceof HttpException) {
            $statusCode = $e->getStatusCode();
            $message = $e->getMessage() ?: $this->getDefaultMessage($statusCode);

            return $this->buildJsonResponse(
                $statusCode,
                $message,
                $statusCode * 10, // 例如：404 -> 4040
                null,
                $traceId,
                $isDebug ? $e : null
            );
        }

        // 4. HTTP Response Exception | HTTP响应异常（直接返回）
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        // 5. Other exceptions | 其他异常
        // In production, hide detailed error messages | 生产环境隐藏详细错误信息
        $message = $isDebug
            ? $e->getMessage()
            : 'Internal Server Error | 服务器内部错误';

        $data = null;
        if ($isDebug) {
            $data = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ];
        }

        return $this->buildJsonResponse(
            500,
            $message,
            5000,
            $data,
            $traceId,
            $isDebug ? $e : null
        );
    }

    /**
     * Build unified JSON response | 构建统一的JSON响应
     *
     * @param int $httpCode HTTP status code | HTTP状态码
     * @param string $message Error message | 错误消息
     * @param int $code Business error code | 业务错误码
     * @param mixed $data Additional data | 附加数据
     * @param string|null $traceId Trace ID | 追踪ID
     * @param Throwable|null $exception Exception for logging | 用于日志的异常
     * @return Response
     */
    protected function buildJsonResponse(
        int $httpCode,
        string $message,
        int $code,
        $data = null,
        ?string $traceId = null,
        ?Throwable $exception = null
    ): Response {
        $response = [
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ];

        // Add trace ID if available | 添加追踪ID（如果可用）
        if ($traceId) {
            $response['trace_id'] = $traceId;
        }

        return json($response, $httpCode);
    }

    /**
     * 安全写日志 | Write log safely
     *
     * 异常处理器是最后的兜底路径：它自身再抛异常会变成「白屏 500」，
     * 连统一响应结构都没有。因此这里的日志失败必须被吞掉（只失去可观测性，不影响响应）。
     *
     * @param string $level   error / warning
     * @param string $message 日志正文（含机器可读 tag，便于 grep/告警）
     * @param array  $context 结构化上下文
     */
    protected function safeLog(string $level, string $message, array $context): void
    {
        try {
            Log::record($message, $level, $context);
            Log::save();
        } catch (Throwable) {
            // 日志通道不可用时静默降级，避免异常处理器自己 500
        }
    }

    /**
     * 站点上下文异常的日志 tag（按 reason 分流，便于告警规则分档）
     *
     * 为什么要在 tag 上分流：`SiteIdentify` 的几条拒绝原因处置方式完全不同 ——
     * 格式错/超范围是调用方缺陷、`site_not_found` 多半是写死了一个不存在的 id、
     * `site_tenant_mismatch` 可能是跨租户探测（安全关注）、`site_unusable` 是运营动作
     * （停用/维护，通常成批出现，不该按「客户端出错」刷告警）。
     *
     * 未知 reason 回落到通用 tag：绝不因为「新加了 reason 但忘了加映射」
     * 就让这条日志失去可检索性。
     *
     * @param string $reason `InvalidSiteContextException::REASON_*`
     * @return string 日志 tag（形如 `[SITE_CONTEXT_INVALID_MALFORMED] 说明文字`）
     */
    protected function siteContextLogTag(string $reason): string
    {
        $tags = [
            \app\exception\InvalidSiteContextException::REASON_MALFORMED
                => '[SITE_CONTEXT_INVALID_MALFORMED] 站点标识非法：X-Site-ID 不是非负整数，已拒绝',
            \app\exception\InvalidSiteContextException::REASON_NEGATIVE
                => '[SITE_CONTEXT_INVALID_NEGATIVE] 站点标识非法：X-Site-ID 为负数，已拒绝',
            \app\exception\InvalidSiteContextException::REASON_SITE_ID_OUT_OF_RANGE
                => '[SITE_CONTEXT_SITE_ID_OUT_OF_RANGE] 站点标识非法：X-Site-ID 超出无符号 32 位上界，已拒绝',
            \app\exception\InvalidSiteContextException::REASON_SITE_NOT_FOUND
                => '[SITE_CONTEXT_SITE_NOT_FOUND] 站点归属校验失败：sites 表中不存在该站点，已拒绝',
            \app\exception\InvalidSiteContextException::REASON_SITE_TENANT_MISMATCH
                => '[SITE_CONTEXT_SITE_TENANT_MISMATCH] 站点归属校验失败：站点不属于当前租户，已拒绝（安全关注项）',
            \app\exception\InvalidSiteContextException::REASON_SITE_UNUSABLE
                => '[SITE_CONTEXT_SITE_UNUSABLE] 站点归属校验失败：站点状态不是 active，已拒绝',
            \app\exception\InvalidSiteContextException::REASON_TENANT_CONTEXT_MISSING
                => '[SITE_CONTEXT_TENANT_MISSING] 站点归属校验失败：缺少租户上下文，已拒绝',
        ];

        return $tags[$reason] ?? ('[SITE_CONTEXT_INVALID] 站点标识非法：reason=' . $reason . '，已拒绝');
    }

    /**
     * Get default message for HTTP status code | 获取HTTP状态码的默认消息
     *
     * @param int $statusCode
     * @return string
     */
    protected function getDefaultMessage(int $statusCode): string
    {
        $messages = [
            400 => 'Bad Request | 错误的请求',
            401 => 'Unauthorized | 未授权',
            403 => 'Forbidden | 禁止访问',
            404 => 'Not Found | 未找到',
            405 => 'Method Not Allowed | 方法不允许',
            422 => 'Unprocessable Entity | 无法处理的实体',
            429 => 'Too Many Requests | 请求过多',
            500 => 'Internal Server Error | 服务器内部错误',
            502 => 'Bad Gateway | 错误的网关',
            503 => 'Service Unavailable | 服务不可用',
        ];

        return $messages[$statusCode] ?? 'Error | 错误';
    }
}
