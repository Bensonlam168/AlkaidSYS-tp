<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\ApiController;
use Domain\Permission\Exception\RoleWriteRejectedException;
use Domain\Permission\Model\Role;
use Infrastructure\Permission\Repository\RoleRepository;
use Infrastructure\Permission\Service\GrantCeilingService;
use think\App;
use think\Response;
use think\facade\Log;

/**
 * Role Controller | 角色控制器
 *
 * Provides CRUD operations for role management.
 * 提供角色管理的增删改查操作。
 *
 * ## 授予边界（ER-05）
 *
 * 本控制器有**三条**会改变「角色权限集」的写路径：`save`（创建时带 permissions）、
 * `update`（更新时带 permissions）、`setPermissions`（专门改权限集）。三条都必须满足
 * 「请求的权限集合 ⊆ 操作者自身当前持有的权限集合」，否则 403 / 2002 且**不写库**。
 * 比对逻辑在 `Infrastructure\Permission\Service\GrantCeilingService`，本类只负责
 * 「取操作者 → 校验 → 翻译成统一响应 / 记录差集日志」。
 *
 * @package app\controller\admin
 */
class RoleController extends ApiController
{
    protected RoleRepository $roleRepository;

    /**
     * 授予边界服务（ER-05）
     */
    protected GrantCeilingService $grantCeiling;

    /**
     * Constructor | 构造函数
     */
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->roleRepository = new RoleRepository();
        $this->grantCeiling = new GrantCeilingService();
    }

    /**
     * Get current tenant ID | 获取当前租户 ID
     *
     * @return int
     */
    protected function getTenantId(): int
    {
        // 租户上下文统一由 app\Request::tenantId() 解析（严格模式）：
        // 已设值（TenantIdentify / Auth 中间件写入）→ X-Tenant-ID 请求头，
        // 两者都没有时**抛 TenantContextMissingException**（fail-closed）。
        // 「默认租户」只在 TenantIdentify 一处决定，且必须由 DEFAULT_TENANT_ID 显式配置；
        // 控制器内不再重复降级，也不允许出现硬编码租户 1 的兜底分支。
        return $this->request->tenantId();
    }

    /**
     * List roles | 角色列表
     *
     * GET /v1/admin/roles
     *
     * Query Parameters:
     * - page: int (default: 1)
     * - pageSize: int (default: 20)
     * - name: string (optional, fuzzy search)
     * - slug: string (optional, fuzzy search)
     * - is_system: bool (optional)
     *
     * @return Response
     */
    public function index(): Response
    {
        $tenantId = $this->getTenantId();
        $page = (int)$this->request->get('page', 1);
        $pageSize = (int)$this->request->get('pageSize', 20);

        // Build filters | 构建筛选条件
        $filters = [];
        $filterFields = ['name', 'slug'];
        foreach ($filterFields as $field) {
            $value = $this->request->get($field);
            if ($value !== null && $value !== '') {
                $filters[$field] = $value;
            }
        }

        // Handle boolean filter | 处理布尔类型筛选
        $isSystem = $this->request->get('is_system');
        if ($isSystem !== null && $isSystem !== '') {
            $filters['is_system'] = in_array($isSystem, ['1', 'true', true], true);
        }

        // Validate pagination | 验证分页参数
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));

        $result = $this->roleRepository->list($tenantId, $filters, $page, $pageSize);

        return $this->paginate(
            $result['list'],
            $result['total'],
            $result['page'],
            $result['pageSize']
        );
    }

    /**
     * Get single role | 获取单个角色
     *
     * GET /v1/admin/roles/:id
     *
     * @param int $id Role ID | 角色 ID
     * @return Response
     */
    public function read(int $id): Response
    {
        $tenantId = $this->getTenantId();
        $role = $this->roleRepository->findById($id, $tenantId);

        if ($role === null) {
            return $this->error(
                $this->langService->trans('role.not_found'),
                404,
                [],
                404
            );
        }

        $data = $role->toArray();
        $data['permissions'] = $this->roleRepository->getRolePermissionIds($id);

        return $this->success($data);
    }

    /**
     * Create role | 创建角色
     *
     * POST /v1/admin/roles
     *
     * Request Body:
     * - name: string (required)
     * - slug: string (required, unique within tenant)
     * - description: string (optional)
     * - permissions: array<int> (optional, permission IDs)
     *
     * @return Response
     */
    public function save(): Response
    {
        $tenantId = $this->getTenantId();
        $data = $this->request->post();

        // Validate required fields | 验证必填字段
        $errors = $this->validateRoleData($data);
        if (!empty($errors)) {
            return $this->error(
                $this->langService->trans('validation.failed'),
                422,
                $errors,
                422
            );
        }

        // Check slug uniqueness within tenant | 检查租户内 slug 唯一性
        if ($this->roleRepository->slugExists($data['slug'], $tenantId)) {
            return $this->error(
                $this->langService->trans('role.slug_exists'),
                422,
                ['slug' => [$this->langService->trans('role.slug_exists')]],
                422
            );
        }

        // Create role | 创建角色
        $role = new Role($tenantId, $data['name'], $data['slug']);

        // Set description via reflection | 通过反射设置描述
        if (isset($data['description'])) {
            $reflectionClass = new \ReflectionClass($role);
            $descProperty = $reflectionClass->getProperty('description');
            $descProperty->setAccessible(true);
            $descProperty->setValue($role, $data['description']);
        }

        // ER-05 授予边界：必须在**建角色之前**判定。
        // 若先建角色再校验，拒绝时库里会留下一个"半成品角色"（已有角色行、没有权限），
        // 既是部分执行，也让攻击者能靠 403 不断堆垃圾数据。
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $denied = $this->guardPermissionGrant($data['permissions']);
            if ($denied !== null) {
                return $denied;
            }
        }

        $roleId = $this->roleRepository->save($role);

        // Sync permissions if provided | 如果提供了权限则同步
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $this->roleRepository->syncPermissions($roleId, array_map('intval', $data['permissions']));
        }

        // Reload to get complete data | 重新加载获取完整数据
        $savedRole = $this->roleRepository->findById($roleId, $tenantId);
        $responseData = $savedRole->toArray();
        $responseData['permissions'] = $this->roleRepository->getRolePermissionIds($roleId);

        return $this->success(
            $responseData,
            $this->langService->trans('role.created'),
            0,
            201
        );
    }

    /**
     * Update role | 更新角色
     *
     * PUT /v1/admin/roles/:id
     *
     * @param int $id Role ID | 角色 ID
     * @return Response
     */
    public function update(int $id): Response
    {
        $tenantId = $this->getTenantId();
        $role = $this->roleRepository->findById($id, $tenantId);

        if ($role === null) {
            return $this->error(
                $this->langService->trans('role.not_found'),
                404,
                [],
                404
            );
        }

        // Check if system role | 检查是否系统角色
        if ($role->isSystem()) {
            return $this->error(
                $this->langService->trans('role.system_role_readonly'),
                403,
                [],
                403
            );
        }

        $data = $this->request->put();

        // Validate data | 验证数据
        $errors = $this->validateRoleData($data, true);
        if (!empty($errors)) {
            return $this->error(
                $this->langService->trans('validation.failed'),
                422,
                $errors,
                422
            );
        }

        // Check slug uniqueness (exclude current) | 检查 slug 唯一性（排除当前）
        if (isset($data['slug']) && $data['slug'] !== $role->getSlug()) {
            if ($this->roleRepository->slugExists($data['slug'], $tenantId, $id)) {
                return $this->error(
                    $this->langService->trans('role.slug_exists'),
                    422,
                    ['slug' => [$this->langService->trans('role.slug_exists')]],
                    422
                );
            }
        }

        // Update role by creating new instance | 通过创建新实例更新角色
        $newRole = new Role(
            $tenantId,
            $data['name'] ?? $role->getName(),
            $data['slug'] ?? $role->getSlug()
        );
        $newRole->setId($id);

        // Set description via reflection | 通过反射设置描述
        $reflectionClass = new \ReflectionClass($newRole);
        $descProperty = $reflectionClass->getProperty('description');
        $descProperty->setAccessible(true);
        $descProperty->setValue($newRole, $data['description'] ?? null);

        // ER-05 授予边界：本入口同样能改权限集（PUT /roles/:id 带 permissions），
        // 必须在 `save()`（写角色行）之前判定，避免"角色已改名、权限被拒"的部分执行。
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $denied = $this->guardPermissionGrant($data['permissions']);
            if ($denied !== null) {
                return $denied;
            }
        }

        try {
            $this->roleRepository->save($newRole);
        } catch (RoleWriteRejectedException $e) {
            // 兜底：本方法开头已用 findById($id, $tenantId) 校验过归属，
            // 走到这里只可能是「校验通过后角色归属被并发改动」。
            // 对外统一回 404（不区分「不存在」与「属于别的租户」，避免泄露他租户数据的存在性）。
            return $this->error(
                $this->langService->trans('role.not_found'),
                404,
                [],
                404
            );
        }

        // Sync permissions if provided | 如果提供了权限则同步
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $this->roleRepository->syncPermissions($id, array_map('intval', $data['permissions']));
        }

        // Reload to get updated data | 重新加载获取更新后的数据
        $updatedRole = $this->roleRepository->findById($id, $tenantId);
        $responseData = $updatedRole->toArray();
        $responseData['permissions'] = $this->roleRepository->getRolePermissionIds($id);

        return $this->success(
            $responseData,
            $this->langService->trans('role.updated')
        );
    }

    /**
     * Delete role | 删除角色
     *
     * DELETE /v1/admin/roles/:id
     *
     * @param int $id Role ID | 角色 ID
     * @return Response
     */
    public function delete(int $id): Response
    {
        $tenantId = $this->getTenantId();
        $role = $this->roleRepository->findById($id, $tenantId);

        if ($role === null) {
            return $this->error(
                $this->langService->trans('role.not_found'),
                404,
                [],
                404
            );
        }

        // Check if system role | 检查是否系统角色
        if ($role->isSystem()) {
            return $this->error(
                $this->langService->trans('role.system_role_cannot_delete'),
                403,
                [],
                403
            );
        }

        // Check if role has users | 检查角色是否有用户
        $userCount = \think\facade\Db::name('user_roles')
            ->where('role_id', $id)
            ->count();

        if ($userCount > 0) {
            return $this->error(
                $this->langService->trans('role.has_users'),
                409,
                [],
                409
            );
        }

        $deleted = $this->roleRepository->delete($id, $tenantId);

        if (!$deleted) {
            return $this->error(
                $this->langService->trans('role.delete_failed'),
                500,
                [],
                500
            );
        }

        return $this->success(
            null,
            $this->langService->trans('role.deleted')
        );
    }

    /**
     * Set role permissions | 设置角色权限
     *
     * PUT /v1/admin/roles/:id/permissions
     *
     * Request Body:
     * - permissions: array<int> (required, permission IDs)
     *
     * @param int $id Role ID | 角色 ID
     * @return Response
     */
    public function setPermissions(int $id): Response
    {
        $tenantId = $this->getTenantId();
        $role = $this->roleRepository->findById($id, $tenantId);

        if ($role === null) {
            return $this->error(
                $this->langService->trans('role.not_found'),
                404,
                [],
                404
            );
        }

        // Check if system role | 检查是否系统角色
        if ($role->isSystem()) {
            return $this->error(
                $this->langService->trans('role.system_role_readonly'),
                403,
                [],
                403
            );
        }

        $data = $this->request->put();

        if (!isset($data['permissions']) || !is_array($data['permissions'])) {
            return $this->error(
                $this->langService->trans('validation.failed'),
                422,
                ['permissions' => [$this->langService->trans('validation.required', ['field' => 'permissions'])]],
                422
            );
        }

        // ER-05 授予边界：本方法是「改权限集」的专用入口，必须先过子集校验再落库。
        // `syncPermissions()` 内部是 delete + insertAll（无事务），一旦放行后再失败
        // （例如权限 ID 不存在撞外键）就会变成「旧权限已删、新权限没插上」的部分执行。
        $denied = $this->guardPermissionGrant($data['permissions']);
        if ($denied !== null) {
            return $denied;
        }

        $this->roleRepository->syncPermissions($id, array_map('intval', $data['permissions']));

        $responseData = $role->toArray();
        $responseData['permissions'] = $this->roleRepository->getRolePermissionIds($id);

        return $this->success(
            $responseData,
            $this->langService->trans('role.permissions_updated')
        );
    }

    /**
     * Get all available permissions | 获取所有可用权限
     *
     * GET /v1/admin/permissions
     *
     * @return Response
     */
    public function permissions(): Response
    {
        $permissions = $this->roleRepository->getAllPermissions();

        // Group by resource | 按资源分组
        $grouped = [];
        foreach ($permissions as $permission) {
            $resource = $permission['resource'] ?? 'other';
            if (!isset($grouped[$resource])) {
                $grouped[$resource] = [];
            }
            $grouped[$resource][] = $permission;
        }

        return $this->success([
            'list' => $permissions,
            'grouped' => $grouped,
        ]);
    }

    /**
     * Validate role data | 验证角色数据
     *
     * @param array $data Data to validate | 要验证的数据
     * @param bool $isUpdate Whether this is an update operation | 是否为更新操作
     * @return array Validation errors | 验证错误
     */
    protected function validateRoleData(array $data, bool $isUpdate = false): array
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
        if (!empty($data['name']) && mb_strlen($data['name']) > 50) {
            $errors['name'] = [$this->langService->trans('validation.max', ['field' => 'name', 'max' => 50])];
        }

        // Validate slug format | 验证 slug 格式
        if (!empty($data['slug']) && !preg_match('/^[a-z0-9_-]+$/', $data['slug'])) {
            $errors['slug'] = [$this->langService->trans('role.invalid_slug_format')];
        }

        // Validate slug length | 验证 slug 长度
        if (!empty($data['slug']) && strlen($data['slug']) > 50) {
            $errors['slug'] = [$this->langService->trans('validation.max', ['field' => 'slug', 'max' => 50])];
        }

        // Validate description length | 验证描述长度
        if (!empty($data['description']) && mb_strlen($data['description']) > 255) {
            $errors['description'] = [$this->langService->trans('validation.max', ['field' => 'description', 'max' => 255])];
        }

        // Validate permissions array | 验证权限数组
        if (isset($data['permissions']) && !is_array($data['permissions'])) {
            $errors['permissions'] = [$this->langService->trans('validation.array', ['field' => 'permissions'])];
        }

        return $errors;
    }

    /**
     * 授予边界校验：请求的权限集合必须是**操作者自身权限集合**的子集（ER-05）
     *
     * 三条改权限集的写路径（save / update / setPermissions）共用本方法。
     *
     * 拒绝时**不回显具体缺哪些权限**：那等于把"操作者自己没什么"告诉调用方，
     * 可被用来逐条试探出边界（例如先试 `tenant.write`、再试 `casbin.manage`）。
     * 完整差集只写日志（含权限 ID 与权限码），便于事后取证。
     *
     * @param array<int|string> $permissionIds 请求要授予的权限 ID 列表
     * @return Response|null null = 通过；非 null = 应直接 return 的拒绝/校验响应
     */
    protected function guardPermissionGrant(array $permissionIds): ?Response
    {
        $operatorId = $this->getCurrentUserId();

        // fail-closed：拿不到已认证的操作用户就不允许改权限集。
        // 正常链路里 Auth 中间件已保证 userId 存在，走到这里只可能是路由被绕开。
        if ($operatorId === null) {
            Log::warning('Grant ceiling denied: operator identity missing', [
                'path' => $this->request->pathinfo(),
                'trace_id' => $this->getTraceId(),
            ]);

            return $this->grantCeilingDeniedResponse();
        }

        $inspection = $this->grantCeiling->inspectPermissions($operatorId, $permissionIds);

        // 请求里有不存在的权限 ID：属于入参错误（继续写入会撞 role_permissions 外键），
        // 用 422 而不是 403 —— 这个分支不涉及"操作者有没有权限"，不泄露操作者权限集。
        if ($inspection['unknown'] !== []) {
            Log::warning('Grant rejected: unknown permission ids', [
                'operator_user_id' => $operatorId,
                'unknown_permission_ids' => $inspection['unknown'],
                'path' => $this->request->pathinfo(),
                'trace_id' => $this->getTraceId(),
            ]);

            return $this->error(
                $this->langService->trans('validation.failed'),
                422,
                ['permissions' => [$this->langService->trans('validation.exists', ['field' => 'permissions'])]],
                422
            );
        }

        if ($inspection['denied'] !== []) {
            Log::warning('Grant ceiling denied: operator does not own the permissions', [
                'operator_user_id' => $operatorId,
                'denied_permission_ids' => array_keys($inspection['denied']),
                'denied_permission_codes' => array_values($inspection['denied']),
                'path' => $this->request->pathinfo(),
                'trace_id' => $this->getTraceId(),
            ]);

            return $this->grantCeilingDeniedResponse();
        }

        return null;
    }

    /**
     * 统一的授予边界拒绝响应：HTTP 403 / 业务码 2002
     *
     * 与 `app\middleware\Permission` 的「权限不足」保持同一语义与错误码，
     * 客户端不需要为"越界授权"单独写一套错误处理。
     *
     * @return Response
     */
    protected function grantCeilingDeniedResponse(): Response
    {
        return $this->error(
            $this->langService->trans('error.grant_ceiling_exceeded'),
            2002,
            [],
            403
        );
    }

    /**
     * 获取当前已认证操作者的用户 ID
     *
     * **只信任 Auth 中间件从 JWT 解出并写入请求上下文的用户 ID**。
     * 刻意不读 `X-User-Id` 之类的请求头：那是客户端可任意设置的值，
     * 一旦用它决定"操作者是谁"，授予边界就变成了"客户端自己声明自己有多少权限"。
     *
     * @return int|null 未认证时为 null
     */
    protected function getCurrentUserId(): ?int
    {
        if (method_exists($this->request, 'userId')) {
            $userId = $this->request->userId();
            if (is_int($userId) && $userId > 0) {
                return $userId;
            }
        }

        return null;
    }
}
