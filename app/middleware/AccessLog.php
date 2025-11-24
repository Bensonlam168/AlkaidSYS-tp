<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * AccessLog middleware
 *
 * 记录每个请求的结构化访问日志（JSON 单行），写入 runtime/log/access/access-YYYYMMDD.log。
 * - 在 Trace 中间件之后执行，复用 trace_id。
 * - 在 handle() 中记录开始时间，调用下游后在 finally 中写入日志。
 */
class AccessLog
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
        $enabled = (bool) env('ACCESS_LOG_ENABLED', true);
        $start = microtime(true);

        $response = null;
        $exception = null;

        try {
            /** @var Response $response */
            $response = $next($request);
            return $response;
        } catch (\Throwable $e) {
            $exception = $e;
            throw $e;
        } finally {
            if ($enabled) {
                try {
                    $this->writeAccessLog($request, $response, $start, microtime(true), $exception);
                } catch (\Throwable $ignored) {
                    // 忽略访问日志写入过程中的任何异常，避免影响主流程
                }
            }
        }
    }

    /**
     * 写入访问日志
     *
     * @param Request         $request
     * @param Response|null   $response
     * @param float           $start
     * @param float           $end
     * @param \Throwable|null $exception
     */
    protected function writeAccessLog(Request $request, ?Response $response, float $start, float $end, ?\Throwable $exception = null): void
    {
        $durationMs = (int) round(($end - $start) * 1000);
        $statusCode = $response ? $response->getCode() : 500;

        if ($exception !== null && $statusCode < 500) {
            $statusCode = 500;
        }

        $env = (string) env('APP_ENV', 'production');

        // 解析 trace_id
        $traceId = null;
        if (method_exists($request, 'traceId')) {
            $traceId = $request->traceId();
        }
        if (!$traceId) {
            $traceId = $request->header('X-Trace-Id') ?: $request->header('X-Request-Id');
        }

        // 解析多租户上下文
        $tenantId = null;
        $siteId = null;
        $userId = null;

        if (method_exists($request, 'tenantId')) {
            try {
                $tenantId = $request->tenantId();
            } catch (\Throwable $e) {
                $tenantId = null;
            }
        } else {
            $tenantHeader = $request->header('X-Tenant-ID');
            if ($tenantHeader !== null && $tenantHeader !== '') {
                $tenantId = (int) $tenantHeader;
            }
        }

        if (method_exists($request, 'siteId')) {
            try {
                $siteId = $request->siteId();
            } catch (\Throwable $e) {
                $siteId = null;
            }
        } else {
            $siteHeader = $request->header('X-Site-ID');
            if ($siteHeader !== null && $siteHeader !== '') {
                $siteId = (int) $siteHeader;
            }
        }

        if (method_exists($request, 'userId')) {
            try {
                $userId = $request->userId();
            } catch (\Throwable $e) {
                $userId = null;
            }
        }

        // 客户端 IP 与 UA
        $clientIp = $request->header('X-Forwarded-For');
        if (is_string($clientIp) && $clientIp !== '') {
            $parts = explode(',', $clientIp);
            $clientIp = trim($parts[0]);
        } else {
            $clientIp = $request->ip();
        }

        $userAgent = (string) $request->header('User-Agent', '');

        // 基本请求信息
        $method = strtoupper($request->method());
        $path = '/' . ltrim($request->pathinfo(), '/');
        $query = $request->get();

        $rateLimited = false;
        $rateInfo    = null;
        if (method_exists($request, 'isRateLimited')) {
            try {
                $rateLimited = $request->isRateLimited();
            } catch (\Throwable $e) {
                $rateLimited = false;
            }
        }
        if (method_exists($request, 'getRateLimitInfo')) {
            try {
                $rateInfo = $request->getRateLimitInfo();
            } catch (\Throwable $e) {
                $rateInfo = null;
            }
        }

        $logEntry = [
            'timestamp'        => date(DATE_ATOM),
            'env'              => $env,
            'trace_id'         => $traceId,
            'method'           => $method,
            'path'             => $path,
            'query'            => $query,
            'status_code'      => $statusCode,
            'response_time_ms' => $durationMs,
            'client_ip'        => $clientIp,
            'user_agent'       => $userAgent,
            'user_id'          => $userId,
            'tenant_id'        => $tenantId,
            'site_id'          => $siteId,
            'rate_limited'     => $rateLimited,
        ];

        if ($rateInfo !== null) {
            if (isset($rateInfo['scope'])) {
                $logEntry['rate_limit_scope'] = $rateInfo['scope'];
            }
            if (isset($rateInfo['key'])) {
                $logEntry['rate_limit_key'] = $rateInfo['key'];
            }
            if (isset($rateInfo['limit'])) {
                $logEntry['rate_limit_limit'] = $rateInfo['limit'];
            }
            if (isset($rateInfo['period'])) {
                $logEntry['rate_limit_period'] = $rateInfo['period'];
            }
            if (isset($rateInfo['current'])) {
                $logEntry['rate_limit_current'] = $rateInfo['current'];
            }
            if (isset($rateInfo['identifier'])) {
                $logEntry['rate_limit_identifier'] = $rateInfo['identifier'];
            }
            if (isset($rateInfo['reason'])) {
                $logEntry['rate_limit_reason'] = $rateInfo['reason'];
            }
        }

        if ($exception !== null) {
            $logEntry['error'] = [
                'class'   => get_class($exception),
                'message' => $exception->getMessage(),
            ];
        }

        $rootPath = dirname(__DIR__, 2); // app/middleware -> app -> project root
        $dir = $rootPath . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'access';

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $file = $dir . DIRECTORY_SEPARATOR . 'access-' . date('Ymd') . '.log';
        $line = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}

