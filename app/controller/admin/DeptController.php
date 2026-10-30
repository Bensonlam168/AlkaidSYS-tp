<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\ApiController;
use Domain\Organization\Model\Dept;
use Infrastructure\Organization\Repository\DeptRepository;
use think\App;
use think\Response;

/**
 * Dept Controller | 部门控制器
 *
 * Provides CRUD operations for department management.
 * 提供部门管理的增删改查操作。
 *
 * @package app\controller\admin
 */
class DeptController extends ApiController
{
    protected DeptRepository $deptRepository;

    /**
     * Constructor | 构造函数
     */
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->deptRepository = new DeptRepository();
    }

    /**
     * Get current tenant ID | 获取当前租户 ID
     *
     * @return int
     */
    protected function getTenantId(): int
    {
        // 租户上下文统一由 app\Request::tenantId() 解析
        //（该方法已内置「认证上下文 → X-Tenant-ID 请求头 → 默认租户」降级），
        // 控制器内不再重复降级，避免出现硬编码租户 1 的兜底分支。
        return $this->request->tenantId();
    }

    /**
     * List departments (tree structure) | 部门列表（树形结构）
     *
     * GET /v1/admin/depts
     *
     * @return Response
     */
    public function index(): Response
    {
        $tenantId = $this->getTenantId();
        $tree = $this->deptRepository->getTree($tenantId);

        return $this->success($tree);
    }

    /**
     * Get single department | 获取单个部门
     *
     * GET /v1/admin/depts/:id
     *
     * @param int $id Department ID | 部门 ID
     * @return Response
     */
    public function read(int $id): Response
    {
        $tenantId = $this->getTenantId();
        $dept = $this->deptRepository->findById($id, $tenantId);

        if ($dept === null) {
            return $this->error(
                $this->langService->trans('dept.not_found', [], '部门不存在'),
                404,
                [],
                404
            );
        }

        return $this->success($dept->toArray());
    }

    /**
     * Create department | 创建部门
     *
     * POST /v1/admin/depts
     *
     * Request Body:
     * - name: string (required)
     * - pid: int (optional, parent department ID)
     * - status: int (optional, default 1)
     * - sort: int (optional, default 0)
     * - remark: string (optional)
     *
     * @return Response
     */
    public function save(): Response
    {
        $tenantId = $this->getTenantId();
        $data = $this->request->post();

        // Validate required fields | 验证必填字段
        $errors = $this->validateDeptData($data);
        if (!empty($errors)) {
            return $this->error(
                $this->langService->trans('validation.failed', [], '验证失败'),
                422,
                $errors,
                422
            );
        }

        // Validate parent exists if provided | 验证父部门存在
        $pid = isset($data['pid']) && $data['pid'] !== '' ? (int)$data['pid'] : null;
        if ($pid !== null) {
            $parent = $this->deptRepository->findById($pid, $tenantId);
            if ($parent === null) {
                return $this->error(
                    $this->langService->trans('dept.parent_not_found', [], '父部门不存在'),
                    422,
                    ['pid' => ['父部门不存在']],
                    422
                );
            }
        }

        // Create department | 创建部门
        $dept = new Dept($tenantId, $data['name']);
        $dept->setPid($pid);

        if (isset($data['status'])) {
            $dept->setStatus((bool)$data['status']);
        }
        if (isset($data['sort'])) {
            $dept->setSort((int)$data['sort']);
        }
        if (isset($data['remark'])) {
            $dept->setRemark($data['remark']);
        }

        $deptId = $this->deptRepository->save($dept);

        // Reload to get complete data | 重新加载获取完整数据
        $savedDept = $this->deptRepository->findById($deptId, $tenantId);

        return $this->success(
            $savedDept->toArray(),
            $this->langService->trans('dept.created', [], '部门创建成功'),
            0,
            201
        );
    }

    /**
     * Update department | 更新部门
     *
     * PUT /v1/admin/depts/:id
     *
     * @param int $id Department ID | 部门 ID
     * @return Response
     */
    public function update(int $id): Response
    {
        $tenantId = $this->getTenantId();
        $dept = $this->deptRepository->findById($id, $tenantId);

        if ($dept === null) {
            return $this->error(
                $this->langService->trans('dept.not_found', [], '部门不存在'),
                404,
                [],
                404
            );
        }

        $data = $this->request->put();

        // Validate data | 验证数据
        $errors = $this->validateDeptData($data, true);
        if (!empty($errors)) {
            return $this->error(
                $this->langService->trans('validation.failed', [], '验证失败'),
                422,
                $errors,
                422
            );
        }

        // Validate parent if changed | 验证父部门（如果更改）
        if (isset($data['pid'])) {
            $newPid = $data['pid'] !== '' && $data['pid'] !== null ? (int)$data['pid'] : null;

            // Cannot set self as parent | 不能设置自己为父部门
            if ($newPid === $id) {
                return $this->error(
                    $this->langService->trans('dept.cannot_be_own_parent', [], '不能设置自己为父部门'),
                    422,
                    ['pid' => ['不能设置自己为父部门']],
                    422
                );
            }

            // Validate parent exists | 验证父部门存在
            if ($newPid !== null) {
                $parent = $this->deptRepository->findById($newPid, $tenantId);
                if ($parent === null) {
                    return $this->error(
                        $this->langService->trans('dept.parent_not_found', [], '父部门不存在'),
                        422,
                        ['pid' => ['父部门不存在']],
                        422
                    );
                }
            }

            $dept->setPid($newPid);
        }

        // Update fields | 更新字段
        if (isset($data['name'])) {
            $dept->setName($data['name']);
        }
        if (isset($data['status'])) {
            $dept->setStatus((bool)$data['status']);
        }
        if (isset($data['sort'])) {
            $dept->setSort((int)$data['sort']);
        }
        if (array_key_exists('remark', $data)) {
            $dept->setRemark($data['remark']);
        }

        $this->deptRepository->save($dept);

        // Reload to get updated data | 重新加载获取更新后的数据
        $updatedDept = $this->deptRepository->findById($id, $tenantId);

        return $this->success(
            $updatedDept->toArray(),
            $this->langService->trans('dept.updated', [], '部门更新成功')
        );
    }

    /**
     * Delete department | 删除部门
     *
     * DELETE /v1/admin/depts/:id
     *
     * @param int $id Department ID | 部门 ID
     * @return Response
     */
    public function delete(int $id): Response
    {
        $tenantId = $this->getTenantId();
        $dept = $this->deptRepository->findById($id, $tenantId);

        if ($dept === null) {
            return $this->error(
                $this->langService->trans('dept.not_found', [], '部门不存在'),
                404,
                [],
                404
            );
        }

        // Check if has children | 检查是否有子部门
        if ($this->deptRepository->hasChildren($id, $tenantId)) {
            return $this->error(
                $this->langService->trans('dept.has_children', [], '该部门包含子部门，无法删除'),
                409,
                [],
                409
            );
        }

        $deleted = $this->deptRepository->delete($id, $tenantId);

        if (!$deleted) {
            return $this->error(
                $this->langService->trans('dept.delete_failed', [], '删除失败'),
                500,
                [],
                500
            );
        }

        return $this->success(
            null,
            $this->langService->trans('dept.deleted', [], '部门删除成功')
        );
    }

    /**
     * Validate department data | 验证部门数据
     *
     * @param array $data Data to validate | 要验证的数据
     * @param bool $isUpdate Whether this is an update operation | 是否为更新操作
     * @return array Validation errors | 验证错误
     */
    protected function validateDeptData(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        // Required fields for create | 创建时必填字段
        if (!$isUpdate) {
            if (empty($data['name'])) {
                $errors['name'] = [$this->langService->trans('validation.required', ['field' => 'name'], '名称不能为空')];
            }
        }

        // Validate name length | 验证名称长度
        if (!empty($data['name']) && mb_strlen($data['name']) > 100) {
            $errors['name'] = [$this->langService->trans('validation.max', ['field' => 'name', 'max' => 100], '名称最多100个字符')];
        }

        // Validate remark length | 验证备注长度
        if (!empty($data['remark']) && mb_strlen($data['remark']) > 255) {
            $errors['remark'] = [$this->langService->trans('validation.max', ['field' => 'remark', 'max' => 255], '备注最多255个字符')];
        }

        // Validate status | 验证状态
        if (isset($data['status']) && !in_array($data['status'], [0, 1, '0', '1', true, false], true)) {
            $errors['status'] = [$this->langService->trans('validation.in', ['field' => 'status'], '状态值无效')];
        }

        // Validate sort | 验证排序
        if (isset($data['sort']) && (!is_numeric($data['sort']) || (int)$data['sort'] < 0)) {
            $errors['sort'] = [$this->langService->trans('validation.min', ['field' => 'sort', 'min' => 0], '排序值必须大于等于0')];
        }

        return $errors;
    }
}
