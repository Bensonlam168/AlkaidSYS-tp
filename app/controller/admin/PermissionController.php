<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\ApiController;
use Domain\Permission\Model\Permission;
use Infrastructure\Permission\Repository\PermissionRepository;
use think\App;
use think\Response;

/**
 * Permission Controller | 权限控制器
 *
 * Provides CRUD operations for permission management.
 * 提供权限管理的增删改查操作。
 *
 * @package app\controller\admin
 */
class PermissionController extends ApiController
{
    protected PermissionRepository $permissionRepository;

    /**
     * Constructor | 构造函数
     */
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->permissionRepository = new PermissionRepository();
    }

    /**
     * List permissions | 权限列表
     *
     * GET /v1/admin/permissions
     *
     * Query Parameters:
     * - page: int (default: 1)
     * - pageSize: int (default: 20)
     * - name: string (optional, fuzzy search)
     * - slug: string (optional, fuzzy search)
     * - resource: string (optional, exact match)
     * - action: string (optional, exact match)
     *
     * @return Response
     */
    public function index(): Response
    {
        $page = (int)$this->request->get('page', 1);
        $pageSize = (int)$this->request->get('pageSize', 20);

        // Build filters | 构建筛选条件
        $filters = [];
        $filterFields = ['name', 'slug', 'resource', 'action'];
        foreach ($filterFields as $field) {
            $value = $this->request->get($field);
            if ($value !== null && $value !== '') {
                $filters[$field] = $value;
            }
        }

        // Validate pagination | 验证分页参数
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));

        $result = $this->permissionRepository->list($filters, $page, $pageSize);

        return $this->paginate(
            $result['list'],
            $result['total'],
            $result['page'],
            $result['pageSize']
        );
    }

    /**
     * Get single permission | 获取单个权限
     *
     * GET /v1/admin/permissions/:id
     *
     * @param int $id Permission ID | 权限 ID
     * @return Response
     */
    public function read(int $id): Response
    {
        $permission = $this->permissionRepository->findById($id);

        if ($permission === null) {
            return $this->error(
                $this->langService->trans('permission.not_found'),
                404,
                [],
                404
            );
        }

        $data = $permission->toArray();
        $data['roles'] = $this->permissionRepository->getRolesWithPermission($id);

        return $this->success($data);
    }

    /**
     * Create permission | 创建权限
     *
     * POST /v1/admin/permissions
     *
     * Request Body:
     * - name: string (required)
     * - slug: string (required, unique, format: resource.action)
     * - resource: string (optional)
     * - action: string (optional)
     * - description: string (optional)
     *
     * @return Response
     */
    public function save(): Response
    {
        $data = $this->request->post();

        // Validate required fields | 验证必填字段
        $errors = $this->validatePermissionData($data);
        if (!empty($errors)) {
            return $this->error(
                $this->langService->trans('validation.failed'),
                422,
                $errors,
                422
            );
        }

        // Check slug uniqueness | 检查 slug 唯一性
        if ($this->permissionRepository->slugExists($data['slug'])) {
            return $this->error(
                $this->langService->trans('permission.slug_exists'),
                422,
                ['slug' => [$this->langService->trans('permission.slug_exists')]],
                422
            );
        }

        // Auto-extract resource and action from slug | 从 slug 自动提取 resource 和 action
        $slugParts = explode('.', $data['slug'], 2);
        $resource = $data['resource'] ?? ($slugParts[0] ?? null);
        $action = $data['action'] ?? ($slugParts[1] ?? null);

        // Create permission | 创建权限
        $permission = new Permission($data['name'], $data['slug']);
        $permission->fromArray([
            'resource' => $resource,
            'action' => $action,
        ]);

        // Set description if provided | 如果提供了描述则设置
        if (isset($data['description'])) {
            $reflectionClass = new \ReflectionClass($permission);
            if ($reflectionClass->hasProperty('description')) {
                $descProperty = $reflectionClass->getProperty('description');
                $descProperty->setAccessible(true);
                $descProperty->setValue($permission, $data['description']);
            }
        }

        $permissionId = $this->permissionRepository->save($permission);

        // Reload to get complete data | 重新加载获取完整数据
        $savedPermission = $this->permissionRepository->findById($permissionId);

        return $this->success(
            $savedPermission->toArray(),
            $this->langService->trans('permission.created'),
            0,
            201
        );
    }

    /**
     * Update permission | 更新权限
     *
     * PUT /v1/admin/permissions/:id
     *
     * @param int $id Permission ID | 权限 ID
     * @return Response
     */
    public function update(int $id): Response
    {
        $permission = $this->permissionRepository->findById($id);

        if ($permission === null) {
            return $this->error(
                $this->langService->trans('permission.not_found'),
                404,
                [],
                404
            );
        }

        $data = $this->request->put();

        // Validate data | 验证数据
        $errors = $this->validatePermissionData($data, true);
        if (!empty($errors)) {
            return $this->error(
                $this->langService->trans('validation.failed'),
                422,
                $errors,
                422
            );
        }

        // Check slug uniqueness (exclude current) | 检查 slug 唯一性（排除当前）
        if (isset($data['slug']) && $data['slug'] !== $permission->getSlug()) {
            if ($this->permissionRepository->slugExists($data['slug'], $id)) {
                return $this->error(
                    $this->langService->trans('permission.slug_exists'),
                    422,
                    ['slug' => [$this->langService->trans('permission.slug_exists')]],
                    422
                );
            }
        }

        // Determine new slug | 确定新的 slug
        $newSlug = $data['slug'] ?? $permission->getSlug();

        // Auto-extract resource and action from slug | 从 slug 自动提取 resource 和 action
        $slugParts = explode('.', $newSlug, 2);
        $resource = $data['resource'] ?? ($slugParts[0] ?? $permission->getResource());
        $action = $data['action'] ?? ($slugParts[1] ?? $permission->getAction());

        // Create updated permission | 创建更新后的权限
        $newPermission = new Permission(
            $data['name'] ?? $permission->getName(),
            $newSlug
        );
        $newPermission->setId($id);
        $newPermission->fromArray([
            'resource' => $resource,
            'action' => $action,
        ]);

        // Set description if provided | 如果提供了描述则设置
        if (isset($data['description'])) {
            $reflectionClass = new \ReflectionClass($newPermission);
            if ($reflectionClass->hasProperty('description')) {
                $descProperty = $reflectionClass->getProperty('description');
                $descProperty->setAccessible(true);
                $descProperty->setValue($newPermission, $data['description']);
            }
        }

        $this->permissionRepository->save($newPermission);

        // Reload to get updated data | 重新加载获取更新后的数据
        $updatedPermission = $this->permissionRepository->findById($id);

        return $this->success(
            $updatedPermission->toArray(),
            $this->langService->trans('permission.updated')
        );
    }

    /**
     * Delete permission | 删除权限
     *
     * DELETE /v1/admin/permissions/:id
     *
     * @param int $id Permission ID | 权限 ID
     * @return Response
     */
    public function delete(int $id): Response
    {
        $permission = $this->permissionRepository->findById($id);

        if ($permission === null) {
            return $this->error(
                $this->langService->trans('permission.not_found'),
                404,
                [],
                404
            );
        }

        // Check if permission is used by any roles | 检查权限是否被任何角色使用
        $roles = $this->permissionRepository->getRolesWithPermission($id);
        if (!empty($roles)) {
            return $this->error(
                $this->langService->trans('permission.in_use'),
                409,
                ['roles' => $roles],
                409
            );
        }

        $deleted = $this->permissionRepository->delete($id);

        if (!$deleted) {
            return $this->error(
                $this->langService->trans('permission.delete_failed'),
                500,
                [],
                500
            );
        }

        return $this->success(
            null,
            $this->langService->trans('permission.deleted')
        );
    }

    /**
     * Get all resources | 获取所有资源类型
     *
     * GET /v1/admin/permissions/resources
     *
     * @return Response
     */
    public function resources(): Response
    {
        $resources = $this->permissionRepository->getResources();

        return $this->success(['list' => $resources]);
    }

    /**
     * Get all actions | 获取所有操作类型
     *
     * GET /v1/admin/permissions/actions
     *
     * @return Response
     */
    public function actions(): Response
    {
        $actions = $this->permissionRepository->getActions();

        return $this->success(['list' => $actions]);
    }

    /**
     * Get permissions grouped by resource | 获取按资源分组的权限
     *
     * GET /v1/admin/permissions/grouped
     *
     * Returns both list and grouped permissions for role assignment.
     * 返回列表和分组权限，用于角色授权。
     *
     * @return Response
     */
    public function grouped(): Response
    {
        $grouped = $this->permissionRepository->getGroupedByResource();

        // Flatten grouped to list for convenience | 将分组展平为列表以便使用
        $list = [];
        foreach ($grouped as $permissions) {
            foreach ($permissions as $permission) {
                $list[] = $permission;
            }
        }

        return $this->success([
            'list' => $list,
            'grouped' => $grouped,
        ]);
    }

    /**
     * Validate permission data | 验证权限数据
     *
     * @param array $data Data to validate | 要验证的数据
     * @param bool $isUpdate Whether this is an update operation | 是否为更新操作
     * @return array Validation errors | 验证错误
     */
    protected function validatePermissionData(array $data, bool $isUpdate = false): array
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

        // Validate name length | 验证名称长度
        if (!empty($data['name']) && mb_strlen($data['name']) > 100) {
            $errors['name'] = [$this->langService->trans('validation.max', ['field' => 'name', 'max' => 100])];
        }

        // Validate slug format | 验证 slug 格式
        if (!empty($data['slug']) && !preg_match('/^[a-z0-9_.]+$/', $data['slug'])) {
            $errors['slug'] = [$this->langService->trans('permission.invalid_slug_format')];
        }

        // Validate slug length | 验证 slug 长度
        if (!empty($data['slug']) && strlen($data['slug']) > 100) {
            $errors['slug'] = [$this->langService->trans('validation.max', ['field' => 'slug', 'max' => 100])];
        }

        // Validate description length | 验证描述长度
        if (!empty($data['description']) && mb_strlen($data['description']) > 255) {
            $errors['description'] = [$this->langService->trans('validation.max', ['field' => 'description', 'max' => 255])];
        }

        // Validate resource length | 验证资源长度
        if (!empty($data['resource']) && strlen($data['resource']) > 50) {
            $errors['resource'] = [$this->langService->trans('validation.max', ['field' => 'resource', 'max' => 50])];
        }

        // Validate action length | 验证操作长度
        if (!empty($data['action']) && strlen($data['action']) > 50) {
            $errors['action'] = [$this->langService->trans('validation.max', ['field' => 'action', 'max' => 50])];
        }

        return $errors;
    }
}
