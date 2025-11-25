<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\ApiController;
use Infrastructure\Permission\Service\CasbinService;
use think\Response;
use think\facade\Log;

/**
 * Casbin 管理控制器
 * Casbin Admin Controller
 *
 * 提供 Casbin 授权引擎的管理功能，包括手动刷新策略等。
 * Provides management functions for Casbin authorization engine, including manual policy reload.
 *
 * @package app\controller\admin
 */
class CasbinController extends ApiController
{
    protected CasbinService $casbinService;

    /**
     * 构造函数
     * Constructor
     *
     * @param \think\App $app 应用实例 | Application instance
     * @param CasbinService $casbinService Casbin 服务 | Casbin service
     */
    public function __construct(
        \think\App $app,
        CasbinService $casbinService
    ) {
        parent::__construct($app);
        $this->casbinService = $casbinService;
    }

    /**
     * 手动刷新 Casbin 策略
     * Manually reload Casbin policy
     *
     * 从数据库重新加载 Casbin 策略，用于策略更新后立即生效。
     * Reload Casbin policies from database for immediate effect after policy updates.
     *
     * 使用场景 | Use Cases:
     * - 角色权限关系更新后需要立即生效
     * - 用户角色分配更新后需要立即生效
     * - 权限配置更新后需要立即生效
     *
     * - Role-permission relationships updated and need immediate effect
     * - User-role assignments updated and need immediate effect
     * - Permission configurations updated and need immediate effect
     *
     * 权限要求 | Permission Required:
     * - casbin:manage
     *
     * 限流保护 | Rate Limit:
     * - 10 次/分钟 | 10 requests per minute
     *
     * @return Response JSON 响应 | JSON response
     */
    public function reloadPolicy(): Response
    {
        try {
            // 记录开始时间
            // Record start time
            $startTime = microtime(true);

            // 获取请求上下文
            // Get request context
            $userId = $this->request->userId();
            $tenantId = $this->request->tenantId();
            $ip = $this->request->ip();
            $traceId = $this->request->header('X-Trace-Id') ?: uniqid('casbin_reload_', true);

            // 刷新策略
            // Reload policy
            $this->casbinService->reloadPolicy();

            // 计算执行时间
            // Calculate execution time
            $executionTime = (microtime(true) - $startTime) * 1000; // ms

            // 记录审计日志
            // Record audit log
            Log::info('Casbin policy reloaded manually', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'ip' => $ip,
                'execution_time_ms' => round($executionTime, 2),
                'timestamp' => time(),
            ]);

            // 返回成功响应
            // Return success response
            return $this->success([
                'execution_time_ms' => round($executionTime, 2),
                'timestamp' => time(),
                'trace_id' => $traceId,
            ], 'Policy reloaded successfully');
        } catch (\Exception $e) {
            // 获取请求上下文（用于错误日志）
            // Get request context (for error logging)
            $userId = $this->request->userId() ?? null;
            $tenantId = $this->request->tenantId() ?? null;
            $traceId = $this->request->header('X-Trace-Id') ?: uniqid('casbin_reload_error_', true);

            // 记录错误日志
            // Record error log
            Log::error('Failed to reload Casbin policy', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 返回错误响应
            // Return error response
            return $this->error(
                'Failed to reload policy: ' . $e->getMessage(),
                5000,
                [
                    'trace_id' => $traceId,
                ],
                500
            );
        }
    }
}

