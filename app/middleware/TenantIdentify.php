<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;

/**
 * Tenant Identify Middleware | 租户识别中间件
 *
 * Identifies and validates the tenant from the request.
 * 从请求中识别并验证租户。
 *
 * @package app\middleware
 */
class TenantIdentify
{
    /**
     * Handle request | 处理请求
     *
     * @param Request $request Custom request instance with tenant/user context
     * @param Closure $next Next middleware in the stack
     * @return mixed Response or next middleware result
     */
    public function handle(Request $request, Closure $next)
    {
        // Get tenant ID directly from header | 直接从header获取租户ID
        // Avoid calling $request->tenantId() to prevent circular dependency
        // 避免调用$request->tenantId()防止循环依赖
        $tenantId = (int)$request->header('X-Tenant-ID', '1');

        // Validate tenant exists (optional) | 验证租户存在（如需可在此处接入租户验证逻辑）
        // 当前版本仅负责从请求中解析租户ID并写入 Request，上层可通过仓储/服务进一步校验。

        // Set tenant ID to request (only if custom Request class is used) | 设置租户ID到请求（仅当使用自定义Request类时）
        if (method_exists($request, 'setTenantId')) {
            $request->setTenantId($tenantId);
        }

        // Continue to next middleware | 继续到下一个中间件
        return $next($request);
    }
}
