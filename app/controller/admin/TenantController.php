<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\ApiController;
use Domain\Tenant\Model\Tenant;
use Infrastructure\Tenant\Repository\TenantRepository;
use think\App;
use think\Response;

/**
 * Tenant Controller | 租户控制器
 *
 * Provides CRUD operations for tenant management.
 * 提供租户管理的增删改查操作。
 *
 * @package app\controller\admin
 */
class TenantController extends ApiController
{
    protected TenantRepository $tenantRepository;

    /**
     * Constructor | 构造函数
     */
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->tenantRepository = new TenantRepository();
    }

    /**
     * List tenants | 租户列表
     *
     * GET /v1/admin/tenants
     *
     * Query Parameters:
     * - page: int (default: 1)
     * - pageSize: int (default: 20)
     * - name: string (optional, fuzzy search)
     * - slug: string (optional, fuzzy search)
     * - status: string (optional, exact match)
     * - domain: string (optional, fuzzy search)
     *
     * @return Response
     */
    public function index(): Response
    {
        $page = (int)$this->request->get('page', 1);
        $pageSize = (int)$this->request->get('pageSize', 20);

        // Build filters | 构建筛选条件
        $filters = [];
        $filterFields = ['name', 'slug', 'status', 'domain'];
        foreach ($filterFields as $field) {
            $value = $this->request->get($field);
            if ($value !== null && $value !== '') {
                $filters[$field] = $value;
            }
        }

        // Validate pagination | 验证分页参数
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));

        $result = $this->tenantRepository->list($filters, $page, $pageSize);

        return $this->paginate(
            $result['list'],
            $result['total'],
            $result['page'],
            $result['pageSize']
        );
    }

    /**
     * Get single tenant | 获取单个租户
     *
     * GET /v1/admin/tenants/:id
     *
     * @param int $id Tenant ID | 租户 ID
     * @return Response
     */
    public function read(int $id): Response
    {
        $tenant = $this->tenantRepository->findById($id);

        if ($tenant === null) {
            return $this->error(
                $this->langService->trans('tenant.not_found'),
                404,
                [],
                404
            );
        }

        return $this->success($tenant->toArray());
    }

    /**
     * Create tenant | 创建租户
     *
     * POST /v1/admin/tenants
     *
     * Request Body:
     * - name: string (required)
     * - slug: string (required, unique)
     * - domain: string (optional)
     * - status: string (optional, default: 'active')
     * - config: object (optional)
     * - max_sites: int (optional, default: 1)
     * - max_users: int (optional, default: 10)
     * - expires_at: string (optional, datetime)
     *
     * @return Response
     */
    public function save(): Response
    {
        $data = $this->request->post();

        // Validate required fields | 验证必填字段
        $errors = $this->validateTenantData($data);
        if (!empty($errors)) {
            return $this->error(
                $this->langService->trans('validation.failed'),
                422,
                $errors,
                422
            );
        }

        // Check slug uniqueness | 检查 slug 唯一性
        if ($this->tenantRepository->slugExists($data['slug'])) {
            return $this->error(
                $this->langService->trans('tenant.slug_exists'),
                422,
                ['slug' => [$this->langService->trans('tenant.slug_exists')]],
                422
            );
        }

        // Create tenant | 创建租户
        $tenant = new Tenant($data['name'], $data['slug']);

        // Set optional fields | 设置可选字段
        if (isset($data['domain'])) {
            $tenant->setDomain($data['domain']);
        }
        if (isset($data['status'])) {
            $tenant->setStatus($data['status']);
        }
        if (isset($data['config']) && is_array($data['config'])) {
            $tenant->setConfig($data['config']);
        }
        if (isset($data['max_sites'])) {
            $tenant->setMaxSites((int)$data['max_sites']);
        }
        if (isset($data['max_users'])) {
            $tenant->setMaxUsers((int)$data['max_users']);
        }
        if (isset($data['expires_at'])) {
            $tenant->setExpiresAt($data['expires_at']);
        }

        $id = $this->tenantRepository->save($tenant);

        // Reload to get complete data | 重新加载获取完整数据
        $savedTenant = $this->tenantRepository->findById($id);

        return $this->success(
            $savedTenant->toArray(),
            $this->langService->trans('tenant.created'),
            0,
            201
        );
    }

    /**
     * Update tenant | 更新租户
     *
     * PUT /v1/admin/tenants/:id
     *
     * @param int $id Tenant ID | 租户 ID
     * @return Response
     */
    public function update(int $id): Response
    {
        $tenant = $this->tenantRepository->findById($id);

        if ($tenant === null) {
            return $this->error(
                $this->langService->trans('tenant.not_found'),
                404,
                [],
                404
            );
        }

        $data = $this->request->put();

        // Validate data | 验证数据
        $errors = $this->validateTenantData($data, true);
        if (!empty($errors)) {
            return $this->error(
                $this->langService->trans('validation.failed'),
                422,
                $errors,
                422
            );
        }

        // Check slug uniqueness (exclude current) | 检查 slug 唯一性（排除当前）
        if (isset($data['slug']) && $data['slug'] !== $tenant->getSlug()) {
            if ($this->tenantRepository->slugExists($data['slug'], $id)) {
                return $this->error(
                    $this->langService->trans('tenant.slug_exists'),
                    422,
                    ['slug' => [$this->langService->trans('tenant.slug_exists')]],
                    422
                );
            }
            $tenant = new Tenant($tenant->getName(), $data['slug']);
            $tenant->fromArray(['id' => $id]);
        }

        // Update fields | 更新字段
        if (isset($data['name'])) {
            // Need to recreate tenant with new name since name is constructor param
            $newTenant = new Tenant($data['name'], $tenant->getSlug());
            $newTenant->fromArray($tenant->toArray());
            $tenant = $newTenant;
        }
        if (isset($data['domain'])) {
            $tenant->setDomain($data['domain']);
        }
        if (isset($data['status'])) {
            $tenant->setStatus($data['status']);
        }
        if (isset($data['config']) && is_array($data['config'])) {
            $tenant->setConfig($data['config']);
        }
        if (isset($data['max_sites'])) {
            $tenant->setMaxSites((int)$data['max_sites']);
        }
        if (isset($data['max_users'])) {
            $tenant->setMaxUsers((int)$data['max_users']);
        }
        if (isset($data['expires_at'])) {
            $tenant->setExpiresAt($data['expires_at']);
        }

        $this->tenantRepository->save($tenant);

        // Reload to get updated data | 重新加载获取更新后的数据
        $updatedTenant = $this->tenantRepository->findById($id);

        return $this->success(
            $updatedTenant->toArray(),
            $this->langService->trans('tenant.updated')
        );
    }

    /**
     * Delete tenant | 删除租户
     *
     * DELETE /v1/admin/tenants/:id
     *
     * @param int $id Tenant ID | 租户 ID
     * @return Response
     */
    public function delete(int $id): Response
    {
        $tenant = $this->tenantRepository->findById($id);

        if ($tenant === null) {
            return $this->error(
                $this->langService->trans('tenant.not_found'),
                404,
                [],
                404
            );
        }

        $deleted = $this->tenantRepository->delete($id);

        if (!$deleted) {
            return $this->error(
                $this->langService->trans('tenant.delete_failed'),
                500,
                [],
                500
            );
        }

        return $this->success(
            null,
            $this->langService->trans('tenant.deleted')
        );
    }

    /**
     * Toggle tenant status | 切换租户状态
     *
     * PATCH /v1/admin/tenants/:id/status
     *
     * Request Body:
     * - status: string (required, 'active' | 'inactive' | 'suspended')
     *
     * @param int $id Tenant ID | 租户 ID
     * @return Response
     */
    public function toggleStatus(int $id): Response
    {
        $tenant = $this->tenantRepository->findById($id);

        if ($tenant === null) {
            return $this->error(
                $this->langService->trans('tenant.not_found'),
                404,
                [],
                404
            );
        }

        $status = $this->request->post('status');
        $validStatuses = ['active', 'inactive', 'suspended'];

        if (!in_array($status, $validStatuses, true)) {
            return $this->error(
                $this->langService->trans('tenant.invalid_status'),
                422,
                ['status' => [$this->langService->trans('tenant.invalid_status')]],
                422
            );
        }

        $tenant->setStatus($status);
        $this->tenantRepository->save($tenant);

        return $this->success(
            $tenant->toArray(),
            $this->langService->trans('tenant.status_updated')
        );
    }

    /**
     * Validate tenant data | 验证租户数据
     *
     * @param array $data Data to validate | 要验证的数据
     * @param bool $isUpdate Whether this is an update operation | 是否为更新操作
     * @return array Validation errors | 验证错误
     */
    protected function validateTenantData(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        // Required fields for create | 创建时必填字段
        if (!$isUpdate) {
            if (empty($data['name'])) {
                $errors['name'] = [$this->langService->trans('validation.required', ['field' => 'name'])];
            }
            if (empty($data['slug'])) {
                $errors['slug'] = [$this->langService->trans('validation.required', ['field' => 'slug'])];
            }
        }

        // Validate slug format | 验证 slug 格式
        if (!empty($data['slug']) && !preg_match('/^[a-z0-9_-]+$/', $data['slug'])) {
            $errors['slug'] = [$this->langService->trans('tenant.invalid_slug_format')];
        }

        // Validate status | 验证状态
        if (isset($data['status'])) {
            $validStatuses = ['active', 'inactive', 'suspended'];
            if (!in_array($data['status'], $validStatuses, true)) {
                $errors['status'] = [$this->langService->trans('tenant.invalid_status')];
            }
        }

        // Validate max_sites and max_users | 验证最大站点数和用户数
        if (isset($data['max_sites']) && (!is_numeric($data['max_sites']) || $data['max_sites'] < 0)) {
            $errors['max_sites'] = [$this->langService->trans('validation.min', ['field' => 'max_sites', 'min' => 0])];
        }
        if (isset($data['max_users']) && (!is_numeric($data['max_users']) || $data['max_users'] < 0)) {
            $errors['max_users'] = [$this->langService->trans('validation.min', ['field' => 'max_users', 'min' => 0])];
        }

        return $errors;
    }
}
