<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use Infrastructure\I18n\LanguageService;
use think\App;
use think\Response;

/**
 * API Controller Base Class | API控制器基类
 *
 * Provides unified response format for all API endpoints.
 * 为所有 API 端点提供统一的响应格式。
 *
 * Unified Response Format (Final) | 统一响应规范（最终版）：
 * - HTTP status 用于传输语义（200/4xx/5xx），业务状态码使用 `code` 字段；
 * - 成功：{ code: 0, message: "Success", data: {...}, timestamp: 1234567890, trace_id?: "xxx" }
 * - 失败：{ code: 非 0, message: "Error", data?: {...}, timestamp: 1234567890, trace_id?: "xxx" }
 *   - 对于带字段级错误的场景，约定 data.errors 为详细错误列表。
 *
 * @package app\controller
 */
class ApiController extends BaseController
{
    /**
     * Language service | 语言服务
     */
    protected LanguageService $langService;

    /**
     * Constructor | 构造函数
     */
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->langService = $app->make(LanguageService::class);
    }

    /**
     * Return successful response | 返回成功响应
     *
     * @param mixed $data Response data | 响应数据
     * @param string|null $message Success message (null = use default) | 成功消息（null = 使用默认）
     * @param int $code Business status code (0 for success) | 业务状态码（0表示成功）
     * @param int $httpCode HTTP status code | HTTP状态码
     * @return Response
     */
    protected function success($data = null, ?string $message = null, int $code = 0, int $httpCode = 200): Response
    {
        if ($message === null) {
            $message = $this->langService->trans('common.success');
        }
        $response = [
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ];

        // Add trace_id for observability | 添加 trace_id 用于可观测性
        $traceId = $this->getTraceId();
        if ($traceId !== null) {
            $response['trace_id'] = $traceId;
        }

        return json($response, $httpCode);
    }

    /**
     * Return paginated response | 返回分页响应
     *
     * Response structure: { list, total, page, page_size, total_pages }
     * 响应结构：{ list, total, page, page_size, total_pages }
     *
     * @param array $list Data list | 数据列表
     * @param int $total Total count | 总数
     * @param int $page Current page | 当前页
     * @param int $pageSize Page size | 每页数量
     * @param string $message Success message | 成功消息
     * @return Response
     */
    protected function paginate(array $list, int $total, int $page, int $pageSize, string $message = 'Success'): Response
    {
        $data = [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,  // Unified field name (snake_case) | 统一字段名（下划线命名）
            'total_pages' => (int)ceil($total / max($pageSize, 1)),  // Calculate total pages | 计算总页数
        ];

        return $this->success($data, $message);
    }

    /**
     * Return error response | 返回错误响应
     *
     * @param string $message Error message | 错误消息
     * @param int $code Business error code (non-zero) | 业务错误码（非0）
     * @param array $errors Detailed error list | 详细错误列表
     * @param int $httpCode HTTP status code | HTTP状态码
     * @return Response
     */
    protected function error(string $message, int $code = 400, array $errors = [], int $httpCode = 400): Response
    {
        $response = [
            'code'      => $code,
            'message'   => $message,
            'data'      => null,
            'timestamp' => time(),
        ];

        if (!empty($errors)) {
            $response['data'] = ['errors' => $errors];
        }

        // Add trace_id for observability | 添加 trace_id 用于可观测性
        $traceId = $this->getTraceId();
        if ($traceId !== null) {
            $response['trace_id'] = $traceId;
        }

        return json($response, $httpCode);
    }

    /**
     * Return validation error response | 返回验证错误响应
     *
     * @param array $errors Validation errors | 验证错误
     * @param string|null $message Error message (null = use default) | 错误消息（null = 使用默认）
     * @return Response
     */
    protected function validationError(array $errors, ?string $message = null): Response
    {
        if ($message === null) {
            $message = $this->langService->trans('error.validation_failed');
        }
        return $this->error($message, 422, $errors, 422);
    }

    /**
     * Return not found response | 返回未找到响应
     *
     * @param string|null $message Error message (null = use default) | 错误消息（null = 使用默认）
     * @return Response
     */
    protected function notFound(?string $message = null): Response
    {
        if ($message === null) {
            $message = $this->langService->trans('error.resource_not_found');
        }
        return $this->error($message, 404, [], 404);
    }

    /**
     * Return unauthorized response | 返回未授权响应
     *
     * @param string|null $message Error message (null = use default) | 错误消息（null = 使用默认）
     * @return Response
     */
    protected function unauthorized(?string $message = null): Response
    {
        if ($message === null) {
            $message = $this->langService->trans('error.unauthorized');
        }
        return $this->error($message, 401, [], 401);
    }

    /**
     * Return forbidden response | 返回禁止访问响应
     *
     * @param string|null $message Error message (null = use default) | 错误消息（null = 使用默认）
     * @return Response
     */
    protected function forbidden(?string $message = null): Response
    {
        if ($message === null) {
            $message = $this->langService->trans('error.forbidden');
        }
        return $this->error($message, 403, [], 403);
    }

    /**
     * Validate pagination parameters | 验证分页参数
     *
     * @param int $page Page number | 页码
     * @param int $pageSize Page size | 每页数量
     * @param int $maxPageSize Maximum page size | 最大每页数量
     * @return array{valid: bool, errors: array} Validation result | 验证结果
     */
    protected function validatePagination(int $page, int $pageSize, int $maxPageSize = 100): array
    {
        $errors = [];

        if ($page < 1) {
            $errors['page'] = 'Page number must be greater than or equal to 1 | 页码必须大于等于1';
        }

        if ($pageSize < 1) {
            $errors['pageSize'] = 'Page size must be greater than or equal to 1 | 每页数量必须大于等于1';
        }

        if ($pageSize > $maxPageSize) {
            $errors['pageSize'] = "Page size must not exceed {$maxPageSize} | 每页数量不能超过{$maxPageSize}";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate filters parameter | 验证筛选参数
     *
     * @param mixed $filters Filters parameter | 筛选参数
     * @return array{valid: bool, errors: array} Validation result | 验证结果
     */
    protected function validateFilters($filters): array
    {
        $errors = [];

        if ($filters !== null && !is_array($filters)) {
            $errors['filters'] = 'Filters must be an array | 筛选参数必须是数组';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get trace ID from request | 从请求中获取 trace ID
     *
     * Retrieves the trace ID that was injected by the Trace middleware.
     * 获取由 Trace 中间件注入的 trace ID。
     *
     * @return string|null Trace ID or null if not available | Trace ID 或 null（如果不可用）
     */
    protected function getTraceId(): ?string
    {
        $request = $this->request ?? null;

        // When running in CLI tests or non-HTTP contexts, the request instance
        // may not be available. In that case we simply skip trace_id injection.
        // 在 CLI 测试或非 HTTP 场景下，请求实例可能不存在，此时直接跳过 trace_id 注入。
        if ($request === null) {
            return null;
        }

        // Priority 1: Get from Request object (injected by Trace middleware)
        // 优先级 1：从 Request 对象获取（由 Trace 中间件注入）
        if (is_object($request) && method_exists($request, 'getTraceId')) {
            $traceId = $request->getTraceId();
            if (is_string($traceId) && $traceId !== '') {
                return $traceId;
            }
        }

        // Priority 2: Fallback to request header (if supported)
        // 优先级 2：降级到请求头（在支持 header 方法的情况下）
        if (is_object($request) && method_exists($request, 'header')) {
            $traceId = $request->header('X-Trace-Id');
            if (is_string($traceId) && $traceId !== '') {
                return $traceId;
            }
        }

        return null;
    }
}
