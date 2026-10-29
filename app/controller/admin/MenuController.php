<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\ApiController;
use Domain\Organization\Model\Menu;
use Infrastructure\Organization\Repository\MenuRepository;
use think\App;
use think\Response;

/**
 * Menu Controller | 菜单控制器
 *
 * Provides CRUD operations for menu management.
 * 提供菜单管理的增删改查操作。
 *
 * @package app\controller\admin
 */
class MenuController extends ApiController
{
    protected MenuRepository $menuRepository;

    /**
     * Constructor | 构造函数
     */
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->menuRepository = new MenuRepository();
    }

    /**
     * Get current tenant ID | 获取当前租户 ID
     *
     * @return int
     */
    protected function getTenantId(): int
    {
        // Get from authenticated user context | 从认证用户上下文获取
        $tenantId = $this->request->tenantId ?? $this->request->header('X-Tenant-Id');

        if ($tenantId === null) {
            // Default to tenant 1 for development | 开发环境默认租户 1
            return 1;
        }

        return (int)$tenantId;
    }

    /**
     * List menus (tree structure) | 菜单列表（树形结构）
     *
     * GET /v1/admin/menus
     *
     * @return Response
     */
    public function index(): Response
    {
        $tenantId = $this->getTenantId();
        $tree = $this->menuRepository->getTree($tenantId);

        return $this->success($tree);
    }

    /**
     * Get single menu | 获取单个菜单
     *
     * GET /v1/admin/menus/:id
     *
     * @param int $id Menu ID | 菜单 ID
     * @return Response
     */
    public function read(int $id): Response
    {
        $tenantId = $this->getTenantId();
        $menu = $this->menuRepository->findById($id, $tenantId);

        if ($menu === null) {
            return $this->error(
                $this->langService->trans('menu.not_found', [], '菜单不存在'),
                404,
                [],
                404
            );
        }

        return $this->success($menu->toArray());
    }

    /**
     * Check if menu name exists | 检查菜单名称是否存在
     *
     * GET /v1/admin/menus/check-name
     * GET /v1/admin/menus/name-exists
     *
     * Query Parameters:
     * - name: string (required)
     * - exclude_id: int (optional) - backward compatible
     * - id: int (optional) - frontend expected
     *
     * @return Response
     */
    public function checkName(): Response
    {
        $tenantId = $this->getTenantId();
        $name = $this->request->get('name');
        // Support both 'exclude_id' (backend) and 'id' (frontend)
        $excludeId = $this->request->get('exclude_id') ?? $this->request->get('id');

        // Empty name is not considered as existing | 空名称视为不存在
        if (empty($name)) {
            return $this->success(['exists' => false]);
        }

        $exists = $this->menuRepository->nameExists(
            $name,
            $tenantId,
            $excludeId ? (int)$excludeId : null
        );

        return $this->success(['exists' => $exists]);
    }

    /**
     * Check if menu path exists | 检查菜单路径是否存在
     *
     * GET /v1/admin/menus/check-path
     * GET /v1/admin/menus/path-exists
     *
     * Query Parameters:
     * - path: string (required)
     * - exclude_id: int (optional) - backward compatible
     * - id: int (optional) - frontend expected
     *
     * @return Response
     */
    public function checkPath(): Response
    {
        $tenantId = $this->getTenantId();
        $path = $this->request->get('path');
        // Support both 'exclude_id' (backend) and 'id' (frontend)
        $excludeId = $this->request->get('exclude_id') ?? $this->request->get('id');

        // Empty path is not considered as existing | 空路径视为不存在
        if (empty($path)) {
            return $this->success(['exists' => false]);
        }

        $exists = $this->menuRepository->pathExists(
            $path,
            $tenantId,
            $excludeId ? (int)$excludeId : null
        );

        return $this->success(['exists' => $exists]);
    }

    /**
     * Create menu | 创建菜单
     *
     * POST /v1/admin/menus
     *
     * Request Body:
     * - name: string (required, route name)
     * - type: string (required, catalog|menu|embedded|link|button)
     * - pid: int (optional, parent menu ID)
     * - path: string (optional, route path)
     * - component: string (optional, component path)
     * - redirect: string (optional, redirect path)
     * - authCode: string (optional, permission code)
     * - meta: object (optional, menu meta data)
     * - status: int (optional, default 1)
     * - sort: int (optional, default 0)
     *
     * @return Response
     */
    public function save(): Response
    {
        $tenantId = $this->getTenantId();
        $data = $this->request->post();

        // Validate required fields | 验证必填字段
        $errors = $this->validateMenuData($data);
        if (!empty($errors)) {
            return $this->error(
                $this->langService->trans('validation.failed', [], '验证失败'),
                422,
                $errors,
                422
            );
        }

        // Check name uniqueness | 检查名称唯一性
        if ($this->menuRepository->nameExists($data['name'], $tenantId)) {
            return $this->error(
                $this->langService->trans('menu.name_exists', [], '菜单名称已存在'),
                422,
                ['name' => ['菜单名称已存在']],
                422
            );
        }

        // Validate parent exists if provided | 验证父菜单存在
        $pid = isset($data['pid']) && $data['pid'] !== '' ? (int)$data['pid'] : null;
        if ($pid !== null) {
            $parent = $this->menuRepository->findById($pid, $tenantId);
            if ($parent === null) {
                return $this->error(
                    $this->langService->trans('menu.parent_not_found', [], '父菜单不存在'),
                    422,
                    ['pid' => ['父菜单不存在']],
                    422
                );
            }
        }

        // Create menu | 创建菜单
        $menu = new Menu($tenantId, $data['name']);
        $menu->setPid($pid);
        $menu->setType($data['type'] ?? Menu::TYPE_MENU);

        if (isset($data['path'])) {
            $menu->setPath($data['path']);
        }
        if (isset($data['component'])) {
            $menu->setComponent($data['component']);
        }
        if (isset($data['redirect'])) {
            $menu->setRedirect($data['redirect']);
        }
        if (isset($data['authCode'])) {
            $menu->setAuthCode($data['authCode']);
        }
        if (isset($data['meta'])) {
            $menu->setMeta(is_array($data['meta']) ? $data['meta'] : []);
        }
        if (isset($data['status'])) {
            $menu->setStatus((bool)$data['status']);
        }
        if (isset($data['sort'])) {
            $menu->setSort((int)$data['sort']);
        }

        $menuId = $this->menuRepository->save($menu);

        // Reload to get complete data | 重新加载获取完整数据
        $savedMenu = $this->menuRepository->findById($menuId, $tenantId);

        return $this->success(
            $savedMenu->toArray(),
            $this->langService->trans('menu.created', [], '菜单创建成功'),
            0,
            201
        );
    }

    /**
     * Update menu | 更新菜单
     *
     * PUT /v1/admin/menus/:id
     *
     * @param int $id Menu ID | 菜单 ID
     * @return Response
     */
    public function update(int $id): Response
    {
        $tenantId = $this->getTenantId();
        $menu = $this->menuRepository->findById($id, $tenantId);

        if ($menu === null) {
            return $this->error(
                $this->langService->trans('menu.not_found', [], '菜单不存在'),
                404,
                [],
                404
            );
        }

        $data = $this->request->put();

        // Validate data | 验证数据
        $errors = $this->validateMenuData($data, true);
        if (!empty($errors)) {
            return $this->error(
                $this->langService->trans('validation.failed', [], '验证失败'),
                422,
                $errors,
                422
            );
        }

        // Check name uniqueness (exclude current) | 检查名称唯一性（排除当前）
        if (isset($data['name']) && $data['name'] !== $menu->getName()) {
            if ($this->menuRepository->nameExists($data['name'], $tenantId, $id)) {
                return $this->error(
                    $this->langService->trans('menu.name_exists', [], '菜单名称已存在'),
                    422,
                    ['name' => ['菜单名称已存在']],
                    422
                );
            }
        }

        // Validate parent if changed | 验证父菜单（如果更改）
        if (isset($data['pid'])) {
            $newPid = $data['pid'] !== '' && $data['pid'] !== null ? (int)$data['pid'] : null;

            // Cannot set self as parent | 不能设置自己为父菜单
            if ($newPid === $id) {
                return $this->error(
                    $this->langService->trans('menu.cannot_be_own_parent', [], '不能设置自己为父菜单'),
                    422,
                    ['pid' => ['不能设置自己为父菜单']],
                    422
                );
            }

            // Validate parent exists | 验证父菜单存在
            if ($newPid !== null) {
                $parent = $this->menuRepository->findById($newPid, $tenantId);
                if ($parent === null) {
                    return $this->error(
                        $this->langService->trans('menu.parent_not_found', [], '父菜单不存在'),
                        422,
                        ['pid' => ['父菜单不存在']],
                        422
                    );
                }
            }

            $menu->setPid($newPid);
        }

        // Update fields | 更新字段
        if (isset($data['name'])) {
            $menu->setName($data['name']);
        }
        if (isset($data['type'])) {
            $menu->setType($data['type']);
        }
        if (array_key_exists('path', $data)) {
            $menu->setPath($data['path']);
        }
        if (array_key_exists('component', $data)) {
            $menu->setComponent($data['component']);
        }
        if (array_key_exists('redirect', $data)) {
            $menu->setRedirect($data['redirect']);
        }
        if (array_key_exists('authCode', $data)) {
            $menu->setAuthCode($data['authCode']);
        }
        if (isset($data['meta'])) {
            $menu->setMeta(is_array($data['meta']) ? $data['meta'] : []);
        }
        if (isset($data['status'])) {
            $menu->setStatus((bool)$data['status']);
        }
        if (isset($data['sort'])) {
            $menu->setSort((int)$data['sort']);
        }

        $this->menuRepository->save($menu);

        // Reload to get updated data | 重新加载获取更新后的数据
        $updatedMenu = $this->menuRepository->findById($id, $tenantId);

        return $this->success(
            $updatedMenu->toArray(),
            $this->langService->trans('menu.updated', [], '菜单更新成功')
        );
    }

    /**
     * Delete menu | 删除菜单
     *
     * DELETE /v1/admin/menus/:id
     *
     * @param int $id Menu ID | 菜单 ID
     * @return Response
     */
    public function delete(int $id): Response
    {
        $tenantId = $this->getTenantId();
        $menu = $this->menuRepository->findById($id, $tenantId);

        if ($menu === null) {
            return $this->error(
                $this->langService->trans('menu.not_found', [], '菜单不存在'),
                404,
                [],
                404
            );
        }

        // Check if has children | 检查是否有子菜单
        if ($this->menuRepository->hasChildren($id, $tenantId)) {
            return $this->error(
                $this->langService->trans('menu.has_children', [], '该菜单包含子菜单，无法删除'),
                409,
                [],
                409
            );
        }

        $deleted = $this->menuRepository->delete($id, $tenantId);

        if (!$deleted) {
            return $this->error(
                $this->langService->trans('menu.delete_failed', [], '删除失败'),
                500,
                [],
                500
            );
        }

        return $this->success(
            null,
            $this->langService->trans('menu.deleted', [], '菜单删除成功')
        );
    }

    /**
     * Validate menu data | 验证菜单数据
     *
     * @param array $data Data to validate | 要验证的数据
     * @param bool $isUpdate Whether this is an update operation | 是否为更新操作
     * @return array Validation errors | 验证错误
     */
    protected function validateMenuData(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        // Required fields for create | 创建时必填字段
        if (!$isUpdate) {
            if (empty($data['name'])) {
                $errors['name'] = [$this->langService->trans('validation.required', ['field' => 'name'], '菜单名称不能为空')];
            }
            if (empty($data['type'])) {
                $errors['type'] = [$this->langService->trans('validation.required', ['field' => 'type'], '菜单类型不能为空')];
            }
        }

        // Validate name length | 验证名称长度
        if (!empty($data['name']) && mb_strlen($data['name']) > 100) {
            $errors['name'] = [$this->langService->trans('validation.max', ['field' => 'name', 'max' => 100], '名称最多100个字符')];
        }

        // Validate type | 验证类型
        if (!empty($data['type']) && !in_array($data['type'], Menu::VALID_TYPES, true)) {
            $errors['type'] = [$this->langService->trans('validation.in', ['field' => 'type'], '无效的菜单类型')];
        }

        // Validate path length | 验证路径长度
        if (!empty($data['path']) && strlen($data['path']) > 255) {
            $errors['path'] = [$this->langService->trans('validation.max', ['field' => 'path', 'max' => 255], '路径最多255个字符')];
        }

        // Validate component length | 验证组件路径长度
        if (!empty($data['component']) && strlen($data['component']) > 255) {
            $errors['component'] = [$this->langService->trans('validation.max', ['field' => 'component', 'max' => 255], '组件路径最多255个字符')];
        }

        // Validate authCode length | 验证权限标识长度
        if (!empty($data['authCode']) && strlen($data['authCode']) > 100) {
            $errors['authCode'] = [$this->langService->trans('validation.max', ['field' => 'authCode', 'max' => 100], '权限标识最多100个字符')];
        }

        // Validate status | 验证状态
        if (isset($data['status']) && !in_array($data['status'], [0, 1, '0', '1', true, false], true)) {
            $errors['status'] = [$this->langService->trans('validation.in', ['field' => 'status'], '状态值无效')];
        }

        // Validate sort | 验证排序
        if (isset($data['sort']) && (!is_numeric($data['sort']) || (int)$data['sort'] < 0)) {
            $errors['sort'] = [$this->langService->trans('validation.min', ['field' => 'sort', 'min' => 0], '排序值必须大于等于0')];
        }

        // Validate meta is array | 验证 meta 是数组
        if (isset($data['meta']) && !is_array($data['meta'])) {
            $errors['meta'] = [$this->langService->trans('validation.array', ['field' => 'meta'], 'meta 必须是对象')];
        }

        return $errors;
    }
}
