<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\facade\Cache;
use think\facade\Log;

/**
 * 应用层限流中间件（固定时间窗口）。
 *
 * - 支持 user/tenant/ip/route 多维度限流；
 * - 支持 IP/用户/租户白名单；
 * - 使用 Redis/Cache 计数，异常时 fail-open 降级放行；
 * - 命中限流时返回 429，并在 Request 上打 rate_limited 标记，供 AccessLog / Nginx 使用。
 */
class RateLimit
{
    /**
     * Handle rate limiting for incoming request | 处理请求的限流逻辑
     *
     * @param Request $request Custom request instance with tenant/user context
     * @param Closure $next Next middleware in the stack
     * @return mixed Response or next middleware result
     */
    public function handle(Request $request, Closure $next)
    {
        $config = (array) config('ratelimit', []);
        if (empty($config['enabled'])) {
            return $next($request);
        }

        // 白名单：IP / 用户 / 租户
        if ($this->isWhitelisted($request, $config)) {
            if (method_exists($request, 'setRateLimited')) {
                $request->setRateLimited(false, [
                    'scope'      => 'whitelist',
                    'identifier' => $this->resolveClientIp($request),
                    'reason'     => 'whitelist',
                ]);
            }
            return $next($request);
        }

        $env       = (string) env('APP_ENV', 'dev');
        $algorithm = (string) ($config['algorithm'] ?? 'token_bucket'); // token_bucket | fixed_window
        $store     = $config['store'] ?? null;
        $cache     = $store ? Cache::store($store) : Cache::store();
        $global    = (array) ($config['default'] ?? []);
        $scopesCfg = (array) ($config['scopes'] ?? []);
        $routesCfg = (array) ($config['routes'] ?? []);
        $routeRule = $this->matchRouteConfig($request, $routesCfg);

        $limitHit = false;
        $hitMeta  = [];

        // 选择算法：Token Bucket 或 Fixed Window
        if ($algorithm === 'token_bucket') {
            return $this->handleTokenBucket($request, $next, $env, $store, $global, $scopesCfg, $routeRule);
        }

        // Fallback: Fixed Window 算法（原有实现）
        foreach (['user', 'tenant', 'ip', 'route'] as $scope) {
            $rule = $this->resolveScopeRule($scope, $global, $scopesCfg, $routeRule);
            if ($rule === null) {
                continue;
            }
            if (isset($rule['enabled']) && !$rule['enabled']) {
                continue;
            }

            $identifier = $this->resolveIdentifier($scope, $request);
            if ($identifier === null || $identifier === '') {
                continue;
            }

            $limit  = (int) ($rule['limit'] ?? 0);
            $period = (int) ($rule['period'] ?? 0);
            if ($limit <= 0 || $period <= 0) {
                continue;
            }

            $key = $this->buildCacheKey($env, $scope, (string) $identifier, $period);

            try {
                // 固定时间窗口：每次请求对计数器 +1，首个请求时设置过期时间
                $current = (int) $cache->inc($key);
                if ($current === 1) {
                    $cache->handler()->expire($key, $period);
                }
            } catch (\Throwable $e) {
                return $this->passThroughOnError($request, $next, $e);
            }

            if ($current > $limit) {
                $limitHit = true;
                $hitMeta  = [
                    'scope'      => $scope,
                    'key'        => $key,
                    'limit'      => $limit,
                    'period'     => $period,
                    'current'    => $current,
                    'identifier' => (string) $identifier,
                    'algorithm'  => 'fixed_window',
                ];
                break;
            }
        }

        if ($limitHit) {
            if (method_exists($request, 'setRateLimited')) {
                $request->setRateLimited(true, $hitMeta);
            }
            return $this->buildRateLimitedResponse($hitMeta);
        }

        if (method_exists($request, 'setRateLimited')) {
            $request->setRateLimited(false, []);
        }
        return $next($request);
    }

    /**
     * Handle Token Bucket 算法限流 | Handle Token Bucket rate limiting
     *
     * @param Request $request Request instance
     * @param Closure $next Next middleware
     * @param string $env Environment
     * @param string|null $store Cache store
     * @param array $global Global config
     * @param array $scopesCfg Scopes config
     * @param array $routeRule Route-specific rule
     * @return mixed Response or next middleware result
     */
    protected function handleTokenBucket(
        Request $request,
        Closure $next,
        string $env,
        ?string $store,
        array $global,
        array $scopesCfg,
        array $routeRule
    ) {
        try {
            $service = new \Infrastructure\RateLimit\Service\RateLimitService($store);
            $config  = (array) config('ratelimit');
            $tbCfg   = (array) ($config['token_bucket'] ?? []);

            $limitHit = false;
            $hitMeta  = [];

            foreach (['user', 'tenant', 'ip', 'route'] as $scope) {
                $rule = $this->resolveScopeRule($scope, $global, $scopesCfg, $routeRule);
                if ($rule === null) {
                    continue;
                }
                if (isset($rule['enabled']) && !$rule['enabled']) {
                    continue;
                }

                $identifier = $this->resolveIdentifier($scope, $request);
                if ($identifier === null || $identifier === '') {
                    continue;
                }

                // Token Bucket 参数：capacity 和 rate
                $capacity = (int) ($rule['capacity'] ?? $tbCfg['capacity'] ?? 100);
                $rate     = (float) ($rule['rate'] ?? $tbCfg['rate'] ?? 10.0);
                $cost     = (int) ($tbCfg['cost_per_request'] ?? 1);

                if ($capacity <= 0 || $rate <= 0) {
                    continue;
                }

                $key = \Infrastructure\RateLimit\Service\RateLimitService::buildKey($env, $scope, (string) $identifier);

                if (!$service->allowRequest($key, $capacity, $rate, $cost)) {
                    $info = $service->getRateLimitInfo($key);
                    $limitHit = true;
                    $hitMeta  = [
                        'scope'      => $scope,
                        'key'        => $key,
                        'capacity'   => $capacity,
                        'rate'       => $rate,
                        'tokens'     => $info['tokens'],
                        'identifier' => (string) $identifier,
                        'algorithm'  => 'token_bucket',
                    ];
                    break;
                }
            }

            if ($limitHit) {
                if (method_exists($request, 'setRateLimited')) {
                    $request->setRateLimited(true, $hitMeta);
                }
                return $this->buildRateLimitedResponse($hitMeta);
            }

            if (method_exists($request, 'setRateLimited')) {
                $request->setRateLimited(false, []);
            }
            return $next($request);
        } catch (\Throwable $e) {
            return $this->passThroughOnError($request, $next, $e);
        }
    }


    /**
     * 是否命中白名单。
     */
    protected function isWhitelisted(Request $request, array $config): bool
    {
        $whitelist  = (array) ($config['whitelist'] ?? []);
        $ipList     = (array) ($whitelist['ips'] ?? []);
        $userList   = array_map('strval', (array) ($whitelist['users'] ?? []));
        $tenantList = array_map('strval', (array) ($whitelist['tenants'] ?? []));

        $ip = $this->resolveClientIp($request);
        if ($ip && in_array($ip, $ipList, true)) {
            return true;
        }

        $userId = null;
        if (method_exists($request, 'userId')) {
            try {
                $userId = $request->userId();
            } catch (\Throwable) {
                $userId = null;
            }
        }
        if ($userId !== null && in_array((string) $userId, $userList, true)) {
            return true;
        }

        $tenantId = null;
        if (method_exists($request, 'tenantId')) {
            try {
                $tenantId = $request->tenantId();
            } catch (\Throwable) {
                $tenantId = null;
            }
        }
        if ($tenantId !== null && in_array((string) $tenantId, $tenantList, true)) {
            return true;
        }

        return false;
    }

    /**
     * 解析客户端 IP（优先使用 X-Forwarded-For）。
     */
    protected function resolveClientIp(Request $request): string
    {
        $xff = $request->header('X-Forwarded-For');
        if (is_string($xff) && $xff !== '') {
            $parts = explode(',', $xff);
            return trim($parts[0]);
        }
        return (string) $request->ip();
    }

    /**
     * 根据路由前缀匹配最具体的配置。
     */
    protected function matchRouteConfig(Request $request, array $routes): array
    {
        $path = '/' . ltrim($request->pathinfo(), '/');
        $best = [];
        $len  = 0;

        foreach ($routes as $prefix => $cfg) {
            $prefix = (string) $prefix;
            if ($prefix === '') {
                continue;
            }
            if ($prefix[0] !== '/') {
                $prefix = '/' . $prefix;
            }
            if (str_starts_with($path, $prefix) && strlen($prefix) > $len) {
                $len  = strlen($prefix);
                $best = (array) $cfg;
            }
        }

        return $best;
    }

    /**
     * 解析某个 scope 的具体规则：路由覆盖 > scopes > 全局默认。
     */
    protected function resolveScopeRule(string $scope, array $global, array $scopesCfg, array $routeRule): ?array
    {
        if (isset($routeRule['scopes'][$scope])) {
            return (array) $routeRule['scopes'][$scope];
        }
        if (isset($routeRule[$scope])) {
            return (array) $routeRule[$scope];
        }
        if (isset($scopesCfg[$scope])) {
            return (array) $scopesCfg[$scope];
        }
        if (!empty($global)) {
            return (array) $global;
        }
        return null;
    }

    /**
     * 根据 scope 解析唯一标识符。
     */
    protected function resolveIdentifier(string $scope, Request $request): ?string
    {
        switch ($scope) {
            case 'user':
                return (string) (method_exists($request, 'userId') ? ($request->userId() ?? '') : '');
            case 'tenant':
                return (string) (method_exists($request, 'tenantId') ? ($request->tenantId() ?? '') : '');
            case 'ip':
                return $this->resolveClientIp($request);
            case 'route':
                return '/' . ltrim($request->pathinfo(), '/');
            default:
                return null;
        }
    }

    /**
     * 生成 Redis key。
     */
    protected function buildCacheKey(string $env, string $scope, string $identifier, int $period): string
    {
        return sprintf('rl:%s:%s:%s:%ds', $env, $scope, md5($identifier), $period);
    }

    /**
     * 构造 429 响应。
     */
    protected function buildRateLimitedResponse(array $meta)
    {
        $algorithm = (string) ($meta['algorithm'] ?? 'fixed_window');

        // Get trace_id from request | 从请求获取 trace_id
        $traceId = null;
        try {
            $request = request();
            if ($request && method_exists($request, 'getTraceId')) {
                $traceId = $request->getTraceId();
            } elseif ($request && method_exists($request, 'header')) {
                $traceId = $request->header('X-Trace-Id');
            }
        } catch (\Throwable $e) {
            // Ignore trace ID retrieval errors
        }

        if ($algorithm === 'token_bucket') {
            // Token Bucket 响应
            $body = [
                'code'      => 429,
                'message'   => 'Too Many Requests',
                'data'      => [
                    'scope'      => $meta['scope'] ?? null,
                    'capacity'   => $meta['capacity'] ?? null,
                    'rate'       => $meta['rate'] ?? null,
                    'tokens'     => $meta['tokens'] ?? null,
                    'identifier' => $meta['identifier'] ?? null,
                    'algorithm'  => 'token_bucket',
                ],
                'timestamp' => time(),
            ];

            // Add trace_id if available | 添加 trace_id（如果可用）
            if ($traceId) {
                $body['trace_id'] = $traceId;
            }

            $response = json($body, 429);
            $response->header([
                'Retry-After'           => '1',
                'X-Rate-Limited'        => '1',
                'X-RateLimit-Scope'     => (string) ($meta['scope'] ?? ''),
                'X-RateLimit-Limit'     => (string) ($meta['capacity'] ?? 0),
                'X-RateLimit-Remaining' => (string) max(0, floor($meta['tokens'] ?? 0)),
                'X-RateLimit-Reset'     => (string) (time() + 1),
            ]);
        } else {
            // Fixed Window 响应
            $ttl = (int) ($meta['period'] ?? 0);
            if ($ttl <= 0) {
                $ttl = 1;
            }

            $body = [
                'code'      => 429,
                'message'   => 'Too Many Requests',
                'data'      => [
                    'scope'      => $meta['scope'] ?? null,
                    'limit'      => $meta['limit'] ?? null,
                    'period'     => $meta['period'] ?? null,
                    'current'    => $meta['current'] ?? null,
                    'identifier' => $meta['identifier'] ?? null,
                    'algorithm'  => 'fixed_window',
                ],
                'timestamp' => time(),
            ];

            // Add trace_id if available | 添加 trace_id（如果可用）
            if ($traceId) {
                $body['trace_id'] = $traceId;
            }

            $response = json($body, 429);
            $response->header([
                'Retry-After'       => (string) $ttl,
                'X-Rate-Limited'    => '1',
                'X-RateLimit-Scope' => (string) ($meta['scope'] ?? ''),
            ]);
        }

        return $response;
    }

    /**
     * Cache/Redis 故障时降级放行。
     */
    protected function passThroughOnError(Request $request, Closure $next, \Throwable $e)
    {
        try {
            Log::warning('RateLimit cache error, fallback to pass-through', [
                'exception' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // ignore
        }

        if (method_exists($request, 'setRateLimited')) {
            $request->setRateLimited(false, [
                'scope'  => 'error',
                'reason' => 'cache_error',
            ]);
        }

        return $next($request);
    }
}
