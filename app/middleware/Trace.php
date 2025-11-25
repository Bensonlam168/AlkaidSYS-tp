<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * Trace middleware
 *
 * 为每个请求注入 trace_id，用于链路追踪和日志关联：
 * - 优先使用客户端传入的 X-Trace-Id 或 X-Request-Id
 * - 否则自动生成新的 trace_id
 * - 将 trace_id 写入自定义 Request 对象（如果支持 setTraceId）
 * - 将 trace_id 回写到响应头 X-Trace-Id
 */
class Trace
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = $this->resolveTraceId($request);

        // 注入到自定义 Request 对象，便于控制器和服务层使用
        if (method_exists($request, 'setTraceId')) {
            $request->setTraceId($traceId);
        }

        /** @var Response $response */
        $response = $next($request);

        // 将 trace_id 写入响应头，便于前端和调用方排错
        if (method_exists($response, 'header')) {
            // ThinkPHP Response::header(array $header) 需要传入数组
            $response->header(['X-Trace-Id' => $traceId]);
        }

        return $response;
    }

    /**
     * 解析请求中的 trace_id，如果不存在则生成新的。
     */
    protected function resolveTraceId(Request $request): string
    {
        $traceId = $request->header('X-Trace-Id');

        if (!$traceId) {
            $traceId = $request->header('X-Request-Id');
        }

        if (is_string($traceId) && $traceId !== '') {
            return $traceId;
        }

        return $this->generateTraceId();
    }

    /**
     * 生成新的 trace_id。
     */
    protected function generateTraceId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return uniqid('trace_', true);
        }
    }
}
