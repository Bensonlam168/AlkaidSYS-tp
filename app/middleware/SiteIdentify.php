<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;

/**
 * Site Identify Middleware | 站点识别中间件
 *
 * Identifies and validates the site from the request.
 * 从请求中识别并验证站点。
 *
 * @package app\middleware
 */
class SiteIdentify
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
        // Get site ID directly from header | 直接从header获取站点ID
        // Avoid calling $request->siteId() to prevent circular dependency
        // 避免调用$request->siteId()防止循环依赖
        $siteId = (int)$request->header('X-Site-ID', '0');

        // Validate site belongs to tenant (reserved for future enhancement) | 验证站点属于租户的逻辑保留为后续增强点
        // 如需在后续阶段收紧站点权限控制，请在设计文档中补充完整方案后，再在此处接入站点归属校验逻辑。

        // Set site ID to request (only if custom Request class is used) | 设置站点ID到请求（仅当使用自定义Request类时）
        if (method_exists($request, 'setSiteId')) {
            $request->setSiteId($siteId);
        }

        // Continue to next middleware | 继续到下一个中间件
        return $next($request);
    }
}
