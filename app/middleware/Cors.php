<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * CORS Middleware | 跨域资源共享中间件
 *
 * Handles Cross-Origin Resource Sharing (CORS) for API requests.
 * 处理 API 请求的跨域资源共享（CORS）。
 *
 * @package app\middleware
 */
class Cors
{
    /**
     * Handle an incoming request | 处理传入请求
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get allowed origins from environment | 从环境变量获取允许的源
        $allowedOrigins = $this->getAllowedOrigins();
        $origin = $request->header('Origin', '');

        // Validate configuration in production | 生产环境验证配置
        $this->validateProductionConfig($allowedOrigins);

        // Log rejected CORS requests | 记录被拒绝的 CORS 请求
        if (!empty($origin) && !$this->isOriginAllowed($origin, $allowedOrigins) && !in_array('*', $allowedOrigins, true)) {
            $this->logCorsRejection($request, $origin, $allowedOrigins);
        }

        // Handle preflight OPTIONS request | 处理预检 OPTIONS 请求
        if ($request->method() === 'OPTIONS') {
            return $this->handlePreflightRequest($origin, $allowedOrigins);
        }

        // Process the request | 处理请求
        /** @var Response $response */
        $response = $next($request);

        // Add CORS headers to response | 添加 CORS 头到响应
        return $this->addCorsHeaders($response, $origin, $allowedOrigins);
    }

    /**
     * Handle preflight OPTIONS request | 处理预检 OPTIONS 请求
     *
     * @param string $origin
     * @param array $allowedOrigins
     * @return Response
     */
    protected function handlePreflightRequest(string $origin, array $allowedOrigins): Response
    {
        $response = response('', 204);

        // Add CORS headers | 添加 CORS 头
        $headers = $this->getCorsHeaders($origin, $allowedOrigins);
        $headers['Access-Control-Max-Age'] = '86400'; // 24 hours | 24小时

        $response->header($headers);

        return $response;
    }

    /**
     * Add CORS headers to response | 添加 CORS 头到响应
     *
     * @param Response $response
     * @param string $origin
     * @param array $allowedOrigins
     * @return Response
     */
    protected function addCorsHeaders(Response $response, string $origin, array $allowedOrigins): Response
    {
        $headers = $this->getCorsHeaders($origin, $allowedOrigins);
        $response->header($headers);

        return $response;
    }

    /**
     * Get CORS headers | 获取 CORS 头
     *
     * @param string $origin
     * @param array $allowedOrigins
     * @return array
     */
    protected function getCorsHeaders(string $origin, array $allowedOrigins): array
    {
        $headers = [];

        // Check if origin is allowed | 检查源是否被允许
        if ($this->isOriginAllowed($origin, $allowedOrigins)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Credentials'] = 'true';
        } elseif (in_array('*', $allowedOrigins, true)) {
            $headers['Access-Control-Allow-Origin'] = '*';
        }

        // Add other CORS headers | 添加其他 CORS 头
        $headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
        $headers['Access-Control-Allow-Headers'] = 'Accept, Authorization, Content-Type, X-Requested-With, X-Trace-Id, X-Tenant-ID, X-Site-ID, Accept-Language';
        $headers['Access-Control-Expose-Headers'] = 'X-Trace-Id, X-Rate-Limited, X-RateLimit-Scope';

        return $headers;
    }

    /**
     * Check if origin is allowed | 检查源是否被允许
     *
     * @param string $origin
     * @param array $allowedOrigins
     * @return bool
     */
    protected function isOriginAllowed(string $origin, array $allowedOrigins): bool
    {
        if (empty($origin)) {
            return false;
        }

        return in_array($origin, $allowedOrigins, true);
    }

    /**
     * Get allowed origins from environment | 从环境变量获取允许的源
     *
     * @return array
     */
    protected function getAllowedOrigins(): array
    {
        $origins = env('CORS_ALLOWED_ORIGINS', '');

        if (empty($origins)) {
            // Default allowed origins for development | 开发环境默认允许的源
            return [
                'http://localhost:5666',  // web-antd dev server
                'http://localhost:5173',  // playground dev server
                'http://localhost:5555',  // web-ele dev server
                'http://localhost:5556',  // web-naive dev server
                'http://localhost:5557',  // web-tdesign dev server
            ];
        }

        // Parse comma-separated origins | 解析逗号分隔的源
        return array_map('trim', explode(',', $origins));
    }

    /**
     * Validate CORS configuration in production environment | 验证生产环境的 CORS 配置
     *
     * @param array $allowedOrigins
     * @return void
     */
    protected function validateProductionConfig(array $allowedOrigins): void
    {
        $env = strtolower((string) env('APP_ENV', 'production'));
        $prodLikeEnvs = ['production', 'prod', 'stage', 'staging'];

        // Only validate in production-like environments | 仅在生产类环境中验证
        if (!in_array($env, $prodLikeEnvs, true)) {
            return;
        }

        // Check if using default localhost origins in production | 检查生产环境是否使用默认的 localhost 源
        $localhostOrigins = array_filter($allowedOrigins, function ($origin) {
            return str_contains($origin, 'localhost') || str_contains($origin, '127.0.0.1');
        });

        if (!empty($localhostOrigins)) {
            $this->logConfigWarning(
                'CORS configuration warning: Using localhost origins in production environment',
                ['env' => $env, 'localhost_origins' => $localhostOrigins]
            );
        }

        // Check if CORS_ALLOWED_ORIGINS is empty in production | 检查生产环境是否未配置 CORS_ALLOWED_ORIGINS
        $configuredOrigins = env('CORS_ALLOWED_ORIGINS', '');
        if (empty($configuredOrigins)) {
            $this->logConfigWarning(
                'CORS configuration warning: CORS_ALLOWED_ORIGINS is not configured in production environment, using default localhost origins',
                ['env' => $env, 'default_origins' => $allowedOrigins]
            );
        }
    }

    /**
     * Log CORS rejection | 记录 CORS 拒绝
     *
     * @param Request $request
     * @param string $origin
     * @param array $allowedOrigins
     * @return void
     */
    protected function logCorsRejection(Request $request, string $origin, array $allowedOrigins): void
    {
        // Check if CORS rejection logging is enabled | 检查是否启用 CORS 拒绝日志
        $enabled = (bool) env('CORS_REJECTION_LOG_ENABLED', true);
        if (!$enabled) {
            return;
        }

        // Get trace_id for observability | 获取 trace_id 用于可观测性
        $traceId = null;
        if (method_exists($request, 'traceId')) {
            $traceId = $request->traceId();
        }
        if (!$traceId) {
            $traceId = $request->header('X-Trace-Id') ?: $request->header('X-Request-Id');
        }

        $logEntry = [
            'timestamp'       => date(DATE_ATOM),
            'env'             => (string) env('APP_ENV', 'production'),
            'trace_id'        => $traceId,
            'event'           => 'cors_rejected',
            'origin'          => $origin,
            'method'          => $request->method(),
            'path'            => $request->pathinfo(),
            'client_ip'       => $request->ip(),
            'user_agent'      => $request->header('User-Agent'),
            'allowed_origins' => $allowedOrigins,
        ];

        $this->writeCorsLog($logEntry);
    }

    /**
     * Log configuration warning | 记录配置警告
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function logConfigWarning(string $message, array $context = []): void
    {
        $logEntry = [
            'timestamp' => date(DATE_ATOM),
            'level'     => 'WARNING',
            'event'     => 'cors_config_warning',
            'message'   => $message,
            'context'   => $context,
        ];

        $this->writeCorsLog($logEntry);
    }

    /**
     * Write CORS log to file | 写入 CORS 日志到文件
     *
     * @param array $logEntry
     * @return void
     */
    protected function writeCorsLog(array $logEntry): void
    {
        $rootPath = dirname(__DIR__, 2); // app/middleware -> app -> project root
        $dir = $rootPath . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'cors';

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $file = $dir . DIRECTORY_SEPARATOR . 'cors-' . date('Ymd') . '.log';
        $line = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
