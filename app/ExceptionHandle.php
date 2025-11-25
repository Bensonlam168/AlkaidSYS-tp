<?php

namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
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
