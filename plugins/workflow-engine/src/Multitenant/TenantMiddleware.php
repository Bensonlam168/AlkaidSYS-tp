<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Multitenant;

use Closure;
use think\Request;
use think\Response;

/**
 * Tenant Middleware | 租户中间件
 *
 * Automatically extracts tenant ID from requests and initializes tenant context.
 * Supports multiple tenant identification strategies.
 * 自动从请求中提取租户ID并初始化租户上下文。支持多种租户识别策略。
 *
 * @package Alkaid\Workflow\Engine\Multitenant
 */
class TenantMiddleware
{
    /**
     * Tenant identification strategy
     *
     * @var string
     */
    protected string $strategy = 'header';

    /**
     * Header name for tenant ID
     *
     * @var string
     */
    protected string $headerName = 'X-Tenant-ID';

    /**
     * Query parameter name for tenant ID
     *
     * @var string
     */
    protected string $queryParam = 'tenant_id';

    /**
     * Route parameter name for tenant ID
     *
     * @var string
     */
    protected string $routeParam = 'tenant_id';

    /**
     * Session key for tenant ID
     *
     * @var string
     */
    protected string $sessionKey = 'current_tenant_id';

    /**
     * Callback to resolve tenant ID from request
     *
     * @var callable|null
     */
    protected $customResolver = null;

    /**
     * Constructor
     *
     * @param array $config Configuration options
     */
    public function __construct(array $config = [])
    {
        if (isset($config['strategy'])) {
            $this->strategy = $config['strategy'];
        }
        if (isset($config['header_name'])) {
            $this->headerName = $config['header_name'];
        }
        if (isset($config['query_param'])) {
            $this->queryParam = $config['query_param'];
        }
        if (isset($config['route_param'])) {
            $this->routeParam = $config['route_param'];
        }
        if (isset($config['session_key'])) {
            $this->sessionKey = $config['session_key'];
        }
        if (isset($config['resolver']) && is_callable($config['resolver'])) {
            $this->customResolver = $config['resolver'];
        }
    }

    /**
     * Handle incoming request | 处理传入请求
     *
     * @param Request $request HTTP request
     * @param Closure $next Next middleware
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Extract tenant ID from request
        $tenantId = $this->resolveTenantId($request);

        if ($tenantId !== null) {
            // Initialize tenant context
            TenantContext::initialize(
                $tenantId,
                $this->resolveUserId($request),
                [
                    'request_id' => $request->header('X-Request-ID'),
                    'ip' => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                ]
            );
        }

        try {
            return $next($request);
        } finally {
            // Clear context after request
            TenantContext::clear();
        }
    }

    /**
     * Resolve tenant ID from request | 从请求解析租户ID
     *
     * @param Request $request HTTP request
     * @return int|null
     */
    protected function resolveTenantId(Request $request): ?int
    {
        // Use custom resolver if set
        if ($this->customResolver !== null) {
            $tenantId = call_user_func($this->customResolver, $request);
            return $tenantId !== null ? (int)$tenantId : null;
        }

        // Try multiple strategies based on configuration
        $tenantId = match ($this->strategy) {
            'header' => $this->resolveFromHeader($request),
            'query' => $this->resolveFromQuery($request),
            'route' => $this->resolveFromRoute($request),
            'session' => $this->resolveFromSession($request),
            'domain' => $this->resolveFromDomain($request),
            'auto' => $this->resolveAuto($request),
            default => null,
        };

        return $tenantId;
    }

    /**
     * Resolve tenant ID from header | 从请求头解析租户ID
     *
     * @param Request $request HTTP request
     * @return int|null
     */
    protected function resolveFromHeader(Request $request): ?int
    {
        $tenantId = $request->header($this->headerName);
        return $tenantId !== null && $tenantId !== '' ? (int)$tenantId : null;
    }

    /**
     * Resolve tenant ID from query parameter | 从查询参数解析租户ID
     *
     * @param Request $request HTTP request
     * @return int|null
     */
    protected function resolveFromQuery(Request $request): ?int
    {
        $tenantId = $request->get($this->queryParam);
        return $tenantId !== null && $tenantId !== '' ? (int)$tenantId : null;
    }

    /**
     * Resolve tenant ID from route parameter | 从路由参数解析租户ID
     *
     * @param Request $request HTTP request
     * @return int|null
     */
    protected function resolveFromRoute(Request $request): ?int
    {
        $tenantId = $request->route($this->routeParam);
        return $tenantId !== null && $tenantId !== '' ? (int)$tenantId : null;
    }

    /**
     * Resolve tenant ID from session | 从会话解析租户ID
     *
     * @param Request $request HTTP request
     * @return int|null
     */
    protected function resolveFromSession(Request $request): ?int
    {
        if (function_exists('session')) {
            $tenantId = session($this->sessionKey);
            return $tenantId !== null ? (int)$tenantId : null;
        }
        return null;
    }

    /**
     * Resolve tenant ID from domain | 从域名解析租户ID
     *
     * @param Request $request HTTP request
     * @return int|null
     */
    protected function resolveFromDomain(Request $request): ?int
    {
        $host = $request->host();

        // Check for subdomain pattern: {tenant}.example.com
        if (preg_match('/^(\d+)\./', $host, $matches)) {
            return (int)$matches[1];
        }

        // Could implement domain-to-tenant lookup here
        // For now, return null to fall back to other methods
        return null;
    }

    /**
     * Auto-resolve tenant ID from multiple sources | 自动从多个来源解析租户ID
     *
     * @param Request $request HTTP request
     * @return int|null
     */
    protected function resolveAuto(Request $request): ?int
    {
        // Priority: header > route > query > session > domain
        return $this->resolveFromHeader($request)
            ?? $this->resolveFromRoute($request)
            ?? $this->resolveFromQuery($request)
            ?? $this->resolveFromSession($request)
            ?? $this->resolveFromDomain($request);
    }

    /**
     * Resolve user ID from request | 从请求解析用户ID
     *
     * @param Request $request HTTP request
     * @return int|null
     */
    protected function resolveUserId(Request $request): ?int
    {
        // Try to get user ID from session or auth
        if (function_exists('session')) {
            $userId = session('user_id');
            if ($userId !== null) {
                return (int)$userId;
            }
        }

        // Try from header (for API requests)
        $userId = $request->header('X-User-ID');
        if ($userId !== null && $userId !== '') {
            return (int)$userId;
        }

        return null;
    }

    /**
     * Set custom tenant resolver | 设置自定义租户解析器
     *
     * @param callable $resolver Resolver function(Request): ?int
     * @return static
     */
    public function setResolver(callable $resolver): static
    {
        $this->customResolver = $resolver;
        return $this;
    }

    /**
     * Set tenant identification strategy | 设置租户识别策略
     *
     * @param string $strategy Strategy name
     * @return static
     */
    public function setStrategy(string $strategy): static
    {
        $this->strategy = $strategy;
        return $this;
    }
}
