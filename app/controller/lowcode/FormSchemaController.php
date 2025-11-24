<?php

declare(strict_types=1);

namespace app\controller\lowcode;

use app\controller\ApiController;
use think\Request;
use Infrastructure\Lowcode\FormDesigner\Service\FormSchemaManager;
use think\Response;

/**
 * Form Schema Controller | 表单Schema控制器
 * 
 * Manages form schemas via RESTful API.
 * 通过RESTful API管理表单Schema。
 * 
 * @package app\controller\lowcode
 */
class FormSchemaController extends ApiController
{
    protected FormSchemaManager $manager;

    public function __construct(FormSchemaManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * List form schemas | 列出表单Schema
     * 
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        // Get query parameters | 获取查询参数
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 20);
        $filters = $request->except(['page', 'pageSize']);

        // Validate pagination parameters | 验证分页参数
        $paginationValidation = $this->validatePagination($page, $pageSize);
        if (!$paginationValidation['valid']) {
            return $this->validationError($paginationValidation['errors']);
        }

        // Validate filters parameter | 验证筛选参数
        $filtersValidation = $this->validateFilters($filters);
        if (!$filtersValidation['valid']) {
            return $this->validationError($filtersValidation['errors']);
        }

        // Get tenant ID from request context (set by TenantIdentify/Auth middleware)
        // 从请求上下文获取租户ID（由 TenantIdentify/Auth 中间件设置）
        $tenantId = $request->tenantId();

        $result = $this->manager->list($tenantId, $filters, $page, $pageSize);

        return $this->paginate($result['list'], $result['total'], $result['page'], $result['pageSize']);
    }

    /**
     * Get form schema | 获取表单Schema
     * 
     * @param string $name
     * @param Request $request
     * @return Response
     */
    public function read(string $name, Request $request): Response
    {
        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        $form = $this->manager->get($name, $tenantId, $siteId);

        if (!$form) {
            return $this->notFound('Form not found');
        }

        return $this->success($form);
    }

    /**
     * Create form | 创建表单
     *
     * @param Request $request
     * @return Response
     */
    public function save(Request $request): Response
    {
        $data = $request->post();

        // Inject tenant context | 注入租户上下文
        $data['tenant_id'] = $request->tenantId();
        $data['site_id'] = $request->siteId();

        try {
            $form = $this->manager->create($data);
            return $this->success($form, 'Form created successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Update form | 更新表单
     * 
     * @param string $name Form name | 表单名称
     * @param Request $request
     * @return Response
     */
    public function update(string $name, Request $request): Response
    {
        // Use param() to get data from various sources (GET/POST/PUT/route vars)
        // param()可以从多种来源获取数据（GET/POST/PUT/路由变量）
        $data = $request->param();
        $data['name'] = $name; // Ensure name matches URL

        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        // Verify ownership/existence | 验证所有权/存在性
        $existing = $this->manager->get($name, $tenantId, $siteId);
        if (!$existing) {
            return $this->notFound('Form not found');
        }
        
        // Extract ID for update
        $id = $existing['id'];
        
        // Prepare update data (don't include id, tenant_id, site_id as they're separate params)
        // 准备更新数据（id、tenant_id、site_id作为独立参数，不放在data中）
        unset($data['name']); // Name cannot be changed via update
        unset($data['id']);
        unset($data['tenant_id']);
        unset($data['site_id']);

        try {
            $this->manager->update($id, $data, $tenantId);
            return $this->success(null, 'Form updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Delete form | 删除表单
     * 
     * @param string $name Form name | 表单名称
     * @param Request $request
     * @return Response
     */
    public function delete(string $name, Request $request): Response
    {
        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        try {
            // Resolve ID from name | 从名称解析ID
            $existing = $this->manager->get($name, $tenantId, $siteId);
            if (!$existing) {
                return $this->notFound('Form not found');
            }

            $this->manager->delete($existing['id'], $tenantId);
            return $this->success(null, 'Form deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Duplicate form | 复制表单
     * 
     * @param string $name Source form name | 源表单名称
     * @param Request $request
     * @return Response
     */
    public function duplicate(string $name, Request $request): Response
    {
        $newName = $request->post('new_name');
        if (empty($newName)) {
            return $this->error('New name is required');
        }

        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        try {
            $id = $this->manager->duplicate($name, $newName, $tenantId, $siteId);
            return $this->success(['id' => $id], 'Form duplicated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
