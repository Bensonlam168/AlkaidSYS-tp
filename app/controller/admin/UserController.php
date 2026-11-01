<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\ApiController;
use Domain\User\Model\User;
use Infrastructure\Permission\Cache\PermissionCacheInvalidator;
use Infrastructure\Permission\Service\GrantCeilingService;
use Infrastructure\User\Repository\UserRepository;
use think\App;
use think\Response;
use think\facade\Log;

/**
 * 用户管理控制器
 * User Management Controller
 *
 * 提供用户 CRUD、状态管理、角色分配等功能
 * Provides user CRUD, status management, role assignment, etc.
 *
 * ## 权限缓存失效（ER-06）
 *
 * 撤角色 / 停用 / 删除用户后，必须让该用户的权限缓存立刻失效，
 * 否则最长 300s（L2 TTL）内旧权限仍然生效 —— 「撤权成功但没生效」。
 *
 * 为什么挂在控制器而不是 `UserRepository`：
 * - `UserRepository` 不在本次改动范围（避免与其它改动冲突）；
 * - 用户级写操作的控制器入口唯一，且都在**写操作返回之后**（`setRoles` 自带事务并已提交），
 *   时机满足「提交后失效」；
 * - 失效器 `PermissionCacheInvalidator` 是叶子类（只依赖 Cache/Db），不会造成循环依赖。
 *
 * 所有失效调用都**不会抛异常**（内部 try/catch + warning），因此不会把一次成功的写入变成失败。
 *
 * ## 角色分配的授予边界（ER-05）
 *
 * `setRoles`（以及创建用户时带的 `role_ids`）会把一整套角色能力转交给目标用户。
 * 角色比权限更粗，因此按「目标角色集合的**权限并集** ⊆ 操作者自身权限集合」判定：
 * 操作者只能把「自己已经拥有的能力」转交给别人，不能借角色分配完成提权。
 * 判定逻辑在 `Infrastructure\Permission\Service\GrantCeilingService`。
 */
class UserController extends ApiController
{
    protected UserRepository $userRepository;

    /**
     * 权限缓存失效器（ER-06）
     */
    protected PermissionCacheInvalidator $cacheInvalidator;

    /**
     * 授予边界服务（ER-05）
     */
    protected GrantCeilingService $grantCeiling;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->userRepository = new UserRepository();
        $this->cacheInvalidator = new PermissionCacheInvalidator();
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
     * 获取用户列表
     * Get user list with pagination
     *
     * @return Response
     */
    public function index(): Response
    {
        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return $this->error('租户信息缺失', 401);
        }

        $page = (int) $this->request->get('page', 1);
        $pageSize = (int) $this->request->get('page_size', 20);

        // 构建筛选条件 | Build filter conditions
        $filters = [];

        $keyword = $this->request->get('keyword');
        if (!empty($keyword)) {
            $filters['keyword'] = $keyword;
        }

        $status = $this->request->get('status');
        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }

        $roleId = $this->request->get('role_id');
        if (!empty($roleId)) {
            $filters['role_id'] = (int) $roleId;
        }

        $result = $this->userRepository->list($tenantId, $filters, $page, $pageSize);

        // 转换 User 对象为数组 | Convert User objects to arrays
        $items = array_map(function (User $user) {
            $data = $user->toArray();
            // 移除敏感信息 | Remove sensitive information
            unset($data['password']);
            return $data;
        }, $result['items']);

        return $this->success([
            'items' => $items,
            'total' => $result['total'],
            'page' => $result['page'],
            'page_size' => $result['page_size'],
        ]);
    }

    /**
     * 获取单个用户详情
     * Get single user detail
     *
     * @param int $id 用户ID | User ID
     * @return Response
     */
    public function read(int $id): Response
    {
        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return $this->error('租户信息缺失', 401);
        }

        $result = $this->userRepository->findWithRoles($id, $tenantId);
        if (!$result) {
            return $this->error('用户不存在', 404);
        }

        $userData = $result['user']->toArray();
        // 移除敏感信息 | Remove sensitive information
        unset($userData['password']);

        return $this->success([
            'user' => $userData,
            'roles' => $result['roles'],
        ]);
    }

    /**
     * 创建用户
     * Create new user
     *
     * @return Response
     */
    public function save(): Response
    {
        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return $this->error('租户信息缺失', 401);
        }

        // 获取请求数据 | Get request data
        $username = $this->request->post('username');
        $email = $this->request->post('email');
        $password = $this->request->post('password');
        $name = $this->request->post('name', '');
        $phone = $this->request->post('phone', '');
        $status = $this->request->post('status', 'active');
        $roleIds = $this->request->post('role_ids', []);

        // 验证必填字段 | Validate required fields
        if (empty($username)) {
            return $this->error('用户名不能为空');
        }
        if (empty($email)) {
            return $this->error('邮箱不能为空');
        }
        if (empty($password)) {
            return $this->error('密码不能为空');
        }

        // 验证邮箱格式 | Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('邮箱格式不正确');
        }

        // 验证用户名长度 | Validate username length
        if (strlen($username) < 3 || strlen($username) > 50) {
            return $this->error('用户名长度应在3-50个字符之间');
        }

        // 验证密码长度 | Validate password length
        if (strlen($password) < 6) {
            return $this->error('密码长度不能少于6个字符');
        }

        // 检查用户名是否已存在 | Check if username already exists
        if ($this->userRepository->usernameExists($tenantId, $username)) {
            return $this->error('用户名已存在');
        }

        // 检查邮箱是否已存在 | Check if email already exists
        if ($this->userRepository->emailExists($tenantId, $email)) {
            return $this->error('邮箱已被使用');
        }

        // ER-05 授予边界：创建用户时就带 role_ids，等于一次角色分配，必须在**建用户之前**判定，
        // 否则拒绝时会留下一个"已创建但没有角色"的账号（部分执行）。
        if (!empty($roleIds) && is_array($roleIds)) {
            $denied = $this->guardRoleAssignment($roleIds);
            if ($denied !== null) {
                return $denied;
            }
        }

        try {
            // 创建用户对象 | Create user object
            $user = new User($tenantId, $username, $email, password_hash($password, PASSWORD_BCRYPT));
            $user->setName($name);
            if (!empty($phone)) {
                $user->setPhone($phone);
            }
            $user->setStatus($status);

            // 保存用户 | Save user
            $userId = $this->userRepository->save($user);

            // 设置角色 | Set roles
            if (!empty($roleIds) && is_array($roleIds)) {
                $this->userRepository->setRoles($userId, array_map('intval', $roleIds));
            }

            return $this->success(['id' => $userId], '用户创建成功');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('用户创建失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新用户信息
     * Update user information
     *
     * @param int $id 用户ID | User ID
     * @return Response
     */
    public function update(int $id): Response
    {
        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return $this->error('租户信息缺失', 401);
        }

        // 查找用户 | Find user
        $user = $this->userRepository->findById($id, $tenantId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        // 获取请求数据 | Get request data
        $username = $this->request->post('username');
        $email = $this->request->post('email');
        $name = $this->request->post('name');
        $phone = $this->request->post('phone');
        $status = $this->request->post('status');

        // 更新用户名 | Update username
        if ($username !== null && $username !== $user->getUsername()) {
            if (strlen($username) < 3 || strlen($username) > 50) {
                return $this->error('用户名长度应在3-50个字符之间');
            }
            if ($this->userRepository->usernameExists($tenantId, $username, $id)) {
                return $this->error('用户名已存在');
            }
            $user->setUsername($username);
        }

        // 更新邮箱 | Update email
        if ($email !== null && $email !== $user->getEmail()) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->error('邮箱格式不正确');
            }
            if ($this->userRepository->emailExists($tenantId, $email, $id)) {
                return $this->error('邮箱已被使用');
            }
            $user->setEmail($email);
        }

        // 更新其他字段 | Update other fields
        if ($name !== null) {
            $user->setName($name);
        }
        if ($phone !== null) {
            $user->setPhone($phone);
        }
        if ($status !== null) {
            $user->setStatus($status);
        }

        try {
            $this->userRepository->save($user);

            // ER-06：更新分支可能改了 status（停用/锁定），权限缓存必须立刻失效。
            // 放在 save() 之后：此时写入已提交，避免并发请求用旧数据把缓存重新填满。
            // 放在 try 之外更保险？不：这里放在 try 内是有意的——失效器自身不抛异常，
            // 若 save() 抛异常则不会走到这一行。
            $this->cacheInvalidator->invalidateUser($id, $tenantId);

            return $this->success(null, '用户更新成功');
        } catch (\Exception $e) {
            return $this->error('用户更新失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除用户
     * Delete user
     *
     * @param int $id 用户ID | User ID
     * @return Response
     */
    public function delete(int $id): Response
    {
        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return $this->error('租户信息缺失', 401);
        }

        // 检查用户是否存在 | Check if user exists
        $user = $this->userRepository->findById($id, $tenantId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        // 禁止删除自己 | Prevent self-deletion
        $currentUserId = $this->getCurrentUserId();
        if ($currentUserId && $currentUserId === $id) {
            return $this->error('不能删除自己的账号');
        }

        try {
            $result = $this->userRepository->delete($id, $tenantId);
            if ($result) {
                // ER-06：用户已删除，清掉其残留的权限缓存（尽力而为，不影响删除结果）
                $this->cacheInvalidator->invalidateUser($id, $tenantId);

                return $this->success(null, '用户删除成功');
            }
            return $this->error('用户删除失败');
        } catch (\Exception $e) {
            return $this->error('用户删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新用户状态
     * Update user status
     *
     * @param int $id 用户ID | User ID
     * @return Response
     */
    public function updateStatus(int $id): Response
    {
        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return $this->error('租户信息缺失', 401);
        }

        $status = $this->request->post('status');
        if (empty($status)) {
            return $this->error('状态不能为空');
        }

        // 验证状态值 | Validate status value
        $validStatuses = ['active', 'inactive', 'locked'];
        if (!in_array($status, $validStatuses, true)) {
            return $this->error('无效的状态值，有效值为: ' . implode(', ', $validStatuses));
        }

        // 检查用户是否存在 | Check if user exists
        $user = $this->userRepository->findById($id, $tenantId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        // 禁止禁用/锁定自己 | Prevent disabling/locking self
        $currentUserId = $this->getCurrentUserId();
        if ($currentUserId && $currentUserId === $id && $status !== 'active') {
            return $this->error('不能禁用或锁定自己的账号');
        }

        try {
            $result = $this->userRepository->updateStatus($id, $tenantId, $status);
            if ($result) {
                // ER-06：账号被停用/锁定后，权限缓存立即失效。
                // 与 ER-04 的中间件校验形成双保险：中间件拦「status != active」，
                // 这里保证即便中间件放行也不会因为缓存命中而继续持有旧权限。
                $this->cacheInvalidator->invalidateUser($id, $tenantId);

                return $this->success(null, '状态更新成功');
            }
            return $this->error('状态更新失败');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('状态更新失败: ' . $e->getMessage());
        }
    }

    /**
     * 设置用户角色
     * Set user roles
     *
     * @param int $id 用户ID | User ID
     * @return Response
     */
    public function setRoles(int $id): Response
    {
        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return $this->error('租户信息缺失', 401);
        }

        $roleIds = $this->request->post('role_ids', []);
        if (!is_array($roleIds)) {
            return $this->error('role_ids 必须是数组');
        }

        // 检查用户是否存在 | Check if user exists
        $user = $this->userRepository->findById($id, $tenantId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        // ER-05 授予边界：目标角色集合的权限并集必须是操作者权限集合的子集。
        // 放在 `setRoles()` 之前：该方法内部是 delete + insertAll（有事务），
        // 但"先删后拒"仍然是部分执行，必须在动手前判定。
        $denied = $this->guardRoleAssignment($roleIds);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->userRepository->setRoles($id, array_map('intval', $roleIds));

            // ER-06：角色变更后立刻失效该用户的权限缓存。
            // setRoles() 内部自带事务并在返回前提交，因此这里是「提交后失效」，
            // 不会出现「清完缓存又被并发请求用未提交数据填满」的窗口。
            $this->cacheInvalidator->invalidateUser($id, $tenantId);

            return $this->success(null, '角色设置成功');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('角色设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 重置用户密码
     * Reset user password
     *
     * @param int $id 用户ID | User ID
     * @return Response
     */
    public function resetPassword(int $id): Response
    {
        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return $this->error('租户信息缺失', 401);
        }

        // 检查用户是否存在 | Check if user exists
        $user = $this->userRepository->findById($id, $tenantId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $newPassword = $this->request->post('new_password');

        // 如果没有提供新密码，则生成随机密码 | Generate random password if not provided
        if (empty($newPassword)) {
            $newPassword = $this->generateRandomPassword();
        } else {
            // 验证密码长度 | Validate password length
            if (strlen($newPassword) < 6) {
                return $this->error('密码长度不能少于6个字符');
            }
        }

        try {
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $result = $this->userRepository->updatePassword($id, $tenantId, $hashedPassword);

            if ($result) {
                return $this->success([
                    'new_password' => $newPassword,
                ], '密码重置成功');
            }
            return $this->error('密码重置失败');
        } catch (\Exception $e) {
            return $this->error('密码重置失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取当前用户ID
     * Get current user ID
     *
     * **只信任 Auth 中间件从 JWT 解出并写入请求上下文的用户 ID**。
     *
     * 历史实现优先读 `X-User-Id` 请求头 —— 那是客户端可任意设置的值，于是：
     *   - 「禁止删除自己」「禁止禁用/锁定自己」两个保护和 ER-05 的授予边界
     *     都能靠伪造一个别的 user id 绕开；
     *   - ER-05 尤其致命：授予边界要拿「操作者是谁」去查权限集，
     *     若这个值来自请求头，攻击者把自己的 id 换成 seeded admin 的 id 就能"合法"授予任何权限。
     *
     * 因此这里删掉了请求头分支，只保留两个**服务端可信**来源：
     *   1. `Request::userId()`（Auth 中间件写入）；
     *   2. `$request->user['id']`（框架注入的已认证用户，非客户端可控）。
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

        // 从 JWT token 或 session 获取 | Get from JWT token or session
        $user = $this->request->user ?? null;
        if (is_array($user) && isset($user['id'])) {
            return (int) $user['id'];
        }

        return null;
    }

    /**
     * 授予边界校验：目标角色集合的权限并集必须是**操作者自身权限集合**的子集（ER-05）
     *
     * `setRoles` 与 `save`（创建用户时带 `role_ids`）共用本方法。
     *
     * 拒绝时**不回显具体缺哪些权限**（避免被用来逐条探测操作者的权限边界）；
     * 完整差集只写日志。
     *
     * @param array<int|string> $roleIds 请求要分配的角色 ID 列表
     * @return Response|null null = 通过；非 null = 应直接 return 的拒绝/校验响应
     */
    protected function guardRoleAssignment(array $roleIds): ?Response
    {
        $operatorId = $this->getCurrentUserId();

        // fail-closed：拿不到已认证的操作用户就不允许分配角色
        if ($operatorId === null) {
            Log::warning('Grant ceiling denied: operator identity missing', [
                'path' => $this->request->pathinfo(),
                'trace_id' => $this->getTraceId(),
            ]);

            return $this->grantCeilingDeniedResponse();
        }

        $inspection = $this->grantCeiling->inspectRoles($operatorId, $roleIds, $this->getTenantId());

        // 角色不存在 / 属于其它租户：入参错误（**不**区分「不存在」与「别人的角色」，
        // 避免泄露他租户角色的存在性；真实原因写日志）
        if ($inspection['unknown'] !== []) {
            Log::warning('Role assignment rejected: unknown role ids', [
                'operator_user_id' => $operatorId,
                'unknown_role_ids' => $inspection['unknown'],
                'path' => $this->request->pathinfo(),
                'trace_id' => $this->getTraceId(),
            ]);

            return $this->error('角色不存在或不属于当前租户', 422, [], 422);
        }

        if ($inspection['denied'] !== []) {
            Log::warning('Grant ceiling denied: role set exceeds operator permissions', [
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
     * 与 `app\middleware\Permission` 的「权限不足」同语义、同错误码。
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
     * 生成随机密码
     * Generate random password
     *
     * @param int $length 密码长度 | Password length
     * @return string
     */
    protected function generateRandomPassword(int $length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $charsLength = strlen($chars);

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $charsLength - 1)];
        }

        return $password;
    }
}
