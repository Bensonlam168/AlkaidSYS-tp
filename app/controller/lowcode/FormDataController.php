<?php

declare(strict_types=1);

namespace app\controller\lowcode;

use app\controller\ApiController;
use think\App;
use think\Request;
use Infrastructure\Lowcode\FormDesigner\Service\FormDataManager;
use think\Response;

/**
 * Form Data Controller | 表单数据控制器
 *
 * Manages form data submission and retrieval.
 * 管理表单数据提交和检索。
 *
 * @package app\controller\lowcode
 */
class FormDataController extends ApiController
{
    protected FormDataManager $manager;

    public function __construct(App $app, FormDataManager $manager)
    {
        parent::__construct($app);
        $this->manager = $manager;
    }

    /**
     * List form data | 获取表单数据列表
     *
     * @param string $formName Form name | 表单名称
     * @param Request $request
     * @return Response
     */
    public function index(string $name, Request $request): Response
    {
        // Get query parameters | 获取查询参数
        $params = $request->get();
        $page = (int)($params['page'] ?? 1);
        $pageSize = (int)($params['pageSize'] ?? 20);

        // Validate pagination parameters | 验证分页参数
        $paginationValidation = $this->validatePagination($page, $pageSize);
        if (!$paginationValidation['valid']) {
            return $this->validationError($paginationValidation['errors']);
        }

        // Extract filters (exclude pagination params) | 提取筛选条件（排除分页参数）
        $filters = array_diff_key($params, array_flip(['page', 'pageSize']));

        // Validate filters parameter | 验证筛选参数
        $filtersValidation = $this->validateFilters($filters);
        if (!$filtersValidation['valid']) {
            return $this->validationError($filtersValidation['errors']);
        }

        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        try {
            // 参数顺序：表单名、租户ID（必选）、筛选条件、分页参数、站点ID
            // 调整为 PHP 8.2+ 兼容签名：必选参数在可选参数之前
            $result = $this->manager->list($name, $tenantId, $filters, $page, $pageSize, $siteId);
            return $this->paginate($result['list'], $result['total'], $result['page'], $result['pageSize']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Get single form data record | 获取单条表单数据记录
     *
     * @param string $name Form name | 表单名称
     * @param int $id Data ID | 数据ID
     * @param Request $request
     * @return Response
     */
    public function read(string $name, int $id, Request $request): Response
    {
        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        try {
            $data = $this->manager->get($name, $id, $tenantId, $siteId);
            if (!$data) {
                return $this->notFound('Data not found');
            }
            return $this->success($data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Save form data (Create/Update) | 保存表单数据（创建/更新）
     *
     * @param string $formName Form name | 表单名称
     * @param Request $request
     * @return Response
     */
    public function save(string $name, Request $request): Response
    {
        $params = $request->post();

        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        try {
            $id = $this->manager->save($name, $params, $tenantId, $siteId);
            return $this->success(['id' => $id], 'Data saved successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Delete form data | 删除表单数据
     *
     * @param string $formName Form name | 表单名称
     * @param int $id Data ID | 数据ID
     * @param Request $request
     * @return Response
     */
    public function delete(string $name, int $id, Request $request): Response
    {
        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        try {
            $deleted = $this->manager->delete($name, $id, $tenantId, $siteId);

            if (!$deleted) {
                return $this->notFound('Data not found or delete failed');
            }

            return $this->success(null, 'Data deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
