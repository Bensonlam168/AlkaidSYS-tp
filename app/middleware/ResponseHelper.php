<?php

declare(strict_types=1);

namespace app\middleware;

use think\Response;

/**
 * Response Helper for Middleware | 中间件响应辅助类
 * 
 * Provides unified JSON error response construction for middleware.
 * 为中间件提供统一的 JSON 错误响应构造。
 * 
 * All error responses should include trace_id for observability.
 * 所有错误响应都应包含 trace_id 以便可观测性。
 * 
 * @package app\middleware
 */
class ResponseHelper
{
    /**
     * Create a JSON error response with trace_id | 创建包含 trace_id 的 JSON 错误响应
     * 
     * @param int $code Business error code | 业务错误码
     * @param string $message Error message | 错误消息
     * @param int $httpCode HTTP status code | HTTP 状态码
     * @param string|null $traceId Trace ID for request tracking | 用于请求追踪的 Trace ID
     * @param mixed $data Additional error data | 额外的错误数据
     * @return Response JSON response | JSON 响应
     */
    public static function jsonError(
        int $code,
        string $message,
        int $httpCode,
        ?string $traceId = null,
        $data = null
    ): Response {
        $response = [
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ];
        
        // Add trace_id for observability | 添加 trace_id 用于可观测性
        if ($traceId !== null) {
            $response['trace_id'] = $traceId;
        }
        
        return json($response, $httpCode);
    }
}

