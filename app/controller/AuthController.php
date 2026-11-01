<?php

declare(strict_types=1);

namespace app\controller;

use Infrastructure\Auth\JwtService;
use Infrastructure\Auth\PrincipalStateChecker;
use Infrastructure\Tenant\Repository\TenantRepository;
use Infrastructure\User\Repository\UserRepository;
use Infrastructure\Permission\Service\PermissionService;
use Infrastructure\Tenant\Service\TenantRegistrationResolver;
use Domain\User\Model\User;
use app\constant\ErrorCode;
use think\Response;
use think\facade\Log;
use think\facade\Db;

/**
 * Auth Controller | 认证控制器
 *
 * Handles user authentication endpoints.
 * 处理用户认证端点。
 *
 * ## 会话失效闭环（ER-04）
 *
 * 「令牌有效」只说明它在签发时是真的，不说明**主体现在仍然有效**。因此本控制器在
 * 会话的**两个起点**上都要回查主体状态，否则 `app\middleware\Auth` 的逐请求校验
 * 会被一条新令牌完全绕过：
 *   - `login()`：拒绝不可用租户下的登录（用户状态检查原已存在）；
 *   - `refresh()`：拒绝为已停用用户/不可用租户续期，并把该 refresh token 彻底作废。
 * 判据统一由 `Infrastructure\Auth\PrincipalStateChecker` 提供，避免多处漂移。
 *
 * @package app\controller
 */
class AuthController extends ApiController
{
    protected JwtService $jwtService;
    protected UserRepository $userRepository;
    protected PermissionService $permissionService;

    /**
     * 认证主体状态校验器（ER-04）| Principal state checker
     *
     * 登录与刷新两条链路共用；判据实现见 `Infrastructure\Auth\PrincipalStateChecker`。
     */
    protected PrincipalStateChecker $principalStateChecker;

    /**
     * Tenant resolver for registration | 注册目标租户解析器
     *
     * 安全说明（C-4）：注册的目标租户**不得**取自客户端可控制的请求头，
     * 只能由「租户域名绑定」或「服务端配置 REGISTRATION_TENANT_ID」解析得出。
     */
    protected TenantRegistrationResolver $tenantRegistrationResolver;

    /**
     * Constructor with dependency injection | 构造函数（依赖注入）
     *
     * Use ThinkPHP container to inject dependencies instead of manual instantiation.
     * 使用 ThinkPHP 容器注入依赖，而非手动实例化。
     *
     * @param \think\App $app Application instance | 应用实例
     * @param JwtService $jwtService JWT service | JWT 服务
     * @param UserRepository $userRepository User repository | 用户仓储
     * @param PermissionService $permissionService Permission service | 权限服务
     */
    public function __construct(
        \think\App $app,
        JwtService $jwtService,
        UserRepository $userRepository,
        PermissionService $permissionService
    ) {
        parent::__construct($app);

        $this->jwtService = $jwtService;
        $this->userRepository = $userRepository;
        $this->permissionService = $permissionService;
        $this->tenantRegistrationResolver = new TenantRegistrationResolver();
        // 复用已注入的用户仓储，租户仓储在此新建（本控制器此前不依赖它）
        $this->principalStateChecker = new PrincipalStateChecker($userRepository, new TenantRepository());
    }

    /**
     * User login | 用户登录
     *
     * POST /v1/auth/login
     *
     * @param \think\Request $request
     * @return Response
     */
    public function login(\think\Request $request): Response
    {
        $data = $request->post();

        // Validate input | 验证输入
        if (empty($data['email']) || empty($data['password'])) {
            return $this->error('Email and password are required');
        }

        // Get tenant ID | 获取租户ID
        $tenantId = $request->tenantId();

        try {
            // 会话失效闭环（ER-04）：登录是会话的起点，不可用租户不得签发令牌
            //
            // 为什么这里必须自己查一次（不能只依赖 TenantIdentify）：
            //   - `TenantIdentify` 只对**请求头显式指定**的租户做状态校验；
            //     请求未带 `X-Tenant-ID` 时它是「落到配置的默认租户」这条分支，**完全不校验状态**；
            //   - 于是「默认租户被停用 / 欠费过期」时，用户仍能登录并拿到全新令牌 ——
            //     中间件里的逐请求闭环会被「重新登录」这一步绕开。
            // 用户状态检查见下方 `!$user->isActive()`（原已存在，覆盖 inactive / locked）。
            $tenantReason = $this->principalStateChecker->checkTenant($tenantId);
            if ($tenantReason !== null) {
                Log::warning('Login rejected: tenant unusable', [
                    'scene'     => 'auth.login',
                    'reason'    => $tenantReason,
                    'tenant_id' => $tenantId,
                    'ip'        => $request->ip(),
                    'trace_id'  => $this->getTraceId(),
                ]);

                // 403 + 2002：与 `TenantIdentify` 拒绝不可用租户时的口径一致（登录阶段尚无会话可失效）
                return $this->error(
                    $this->langService->trans('error.tenant_unavailable'),
                    ErrorCode::FORBIDDEN,
                    [],
                    403
                );
            }

            // Find user by email | 通过邮箱查找用户
            $user = $this->userRepository->findByEmail($tenantId, $data['email']);

            if (!$user) {
                return $this->error('Invalid credentials', 401);
            }

            // Verify password | 验证密码
            if (!$user->verifyPassword($data['password'])) {
                return $this->error('Invalid credentials', 401);
            }

            // Check if user is active | 检查用户是否激活
            if (!$user->isActive()) {
                return $this->error('User account is not active', 403);
            }

            // Generate JWT tokens | 生成 JWT 令牌（Access/Refresh）
            $accessToken = $this->jwtService->generateAccessToken(
                $user->getId(),
                $user->getTenantId(),
                $request->siteId()
            );
            $refreshToken = $this->jwtService->generateRefreshToken(
                $user->getId(),
                $user->getTenantId(),
                $request->siteId()
            );

            // Record login | 记录登录
            $user->recordLogin($request->ip());
            $this->userRepository->save($user);

            return $this->success([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => $this->jwtService->getAccessTokenTtl(),
                'user' => $user->toArray(),
            ], $this->langService->trans('auth.login_successful'));
        } catch (\Exception $e) {
            // Handle unexpected internal error | 处理未预期的内部异常
            return $this->handleInternalError($e, 'auth.login');
        }
    }

    /**
     * User registration | 用户注册
     *
     * POST /v1/auth/register
     *
     * @param \think\Request $request
     * @return Response
     */
    public function register(\think\Request $request): Response
    {
        $data = $request->post();

        // Validate input | 验证输入
        if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
            return $this->error('Username, email and password are required');
        }

        // 安全（C-4）：注册目标租户由域名绑定或服务端配置解析，**不采用** X-Tenant-ID 请求头。
        // 无法解析或租户不可用时抛 ForbiddenException(code=2008)，由全局异常处理器统一响应。
        $tenant = $this->tenantRegistrationResolver->resolve($request);
        $tenantId = (int) $tenant->getId();

        Log::info('[AUTH_REGISTER] Registration accepted', [
            'tenant_id' => $tenantId,
            'tenant_slug' => $tenant->getSlug(),
            'email' => $data['email'],
            'ip' => $request->ip(),
            'trace_id' => $this->getTraceId(),
        ]);

        try {
            // 确保当前租户有默认 user 角色，避免跨租户复用 tenant=1 的系统角色
            // Ensure default user role exists per tenant to avoid reusing tenant=1 system role
            $defaultRoleId = $this->resolveOrCreateDefaultUserRoleId($tenantId);

            // Check if user exists | 检查用户是否存在
            $existingUser = $this->userRepository->findByEmail($tenantId, $data['email']);
            if ($existingUser) {
                return $this->error('Email already registered', 400);
            }

            // Create new user | 创建新用户
            $user = new User(
                $tenantId,
                $data['username'],
                $data['email'],
                password_hash($data['password'], PASSWORD_DEFAULT)
            );

            if (isset($data['name'])) {
                $user->setName($data['name']);
            }

            // Save user | 保存用户
            $userId = $this->userRepository->save($user);
            $user->setId($userId);

            // 分配默认 user 角色（按租户隔离）| Assign default user role per tenant
            $this->userRepository->assignRole($userId, $defaultRoleId);

            // Generate JWT tokens | 生成 JWT 令牌（Access/Refresh）
            $accessToken = $this->jwtService->generateAccessToken(
                $user->getId(),
                $user->getTenantId(),
                $request->siteId()
            );
            $refreshToken = $this->jwtService->generateRefreshToken(
                $user->getId(),
                $user->getTenantId(),
                $request->siteId()
            );

            return $this->success([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => $this->jwtService->getAccessTokenTtl(),
                'user' => $user->toArray(),
            ], $this->langService->trans('auth.registration_successful'));
        } catch (\Exception $e) {
            // Handle unexpected internal error | 处理未预期的内部异常
            return $this->handleInternalError($e, 'auth.register');
        }
    }

    /**
     * Refresh token | 刷新令牌
     *
     * POST /v1/auth/refresh
     *
     * @param \think\Request $request
     * @return Response
     */
    public function refresh(\think\Request $request): Response
    {
        $token = $request->header('Authorization', '');

        if (strpos($token, 'Bearer ') === 0) {
            $token = substr($token, 7);
        }

        if (empty($token)) {
            return $this->error('No token provided', 401);
        }

        try {
            $payload = $this->jwtService->validateRefreshToken($token);

            $userId   = (int) ($payload['user_id'] ?? 0);
            $tenantId = (int) ($payload['tenant_id'] ?? 0);
            $siteId   = (int) ($payload['site_id'] ?? 0);
            $oldJti   = (string) ($payload['jti'] ?? '');

            // 会话失效闭环（ER-04）：refresh 是「续期」，主体已不可用时绝不能续期
            //
            // 这一步**必须**在签发任何新令牌之前：否则「停用后仍能不断换出新 access token」，
            // `app\middleware\Auth` 的逐请求校验就形同虚设（每次换新令牌又能再撑 2 小时）。
            //
            // 顺序说明（不引入「先删后验」窗口）：
            //   1. 先验 Refresh Token 自身（validateRefreshToken，含白名单）；
            //   2. 再验主体状态；
            //   3. **确认主体可用之后**才签发新令牌并作废旧令牌（下方原有逻辑）。
            //   拒绝分支里才作废旧刷新令牌 —— 此时本来就没有任何新令牌被签发，不存在窗口。
            try {
                $stateReason = $this->principalStateChecker->check($userId, $tenantId);
            } catch (\Throwable $e) {
                // 校验依赖数据库：查不出来不能放行，但也不该记成「令牌失效」（否则一次 DB 故障 = 全员登出）
                return $this->handleInternalError($e, 'auth.refresh.principal_state');
            }

            if ($stateReason !== null) {
                // 既然要失效就失效彻底：白名单移除 + jti 加黑名单，
                // 避免已停用的客户端在黑名单写入前继续重放这条 refresh token。
                if ($oldJti !== '') {
                    $this->jwtService->invalidateRefreshToken($userId, $tenantId, $oldJti);
                    $this->jwtService->revokeToken($oldJti);
                }

                Log::warning('Refresh rejected: principal unusable', [
                    'scene'     => 'auth.refresh',
                    'reason'    => $stateReason,
                    'user_id'   => $userId,
                    'tenant_id' => $tenantId,
                    'jti'       => $oldJti,
                    'ip'        => $request->ip(),
                    'trace_id'  => $this->getTraceId(),
                ]);

                // 401 + 2001：与 Auth 中间件的主体失效口径一致（前端统一走「重新登录」）
                return $this->error(
                    $this->langService->trans('error.session_invalid'),
                    ErrorCode::UNAUTHORIZED,
                    [],
                    401
                );
            }

            $accessToken = $this->jwtService->generateAccessToken(
                $userId,
                $tenantId,
                $siteId
            );

            $newRefreshToken = $this->jwtService->generateRefreshToken(
                $userId,
                $tenantId,
                $siteId
            );

            if ($oldJti !== '') {
                $this->jwtService->invalidateRefreshToken($userId, $tenantId, $oldJti);
                $this->jwtService->revokeToken($oldJti);
            }

            return $this->success([
                'access_token'  => $accessToken,
                'refresh_token' => $newRefreshToken,
                'token_type'    => 'Bearer',
                'expires_in'    => $this->jwtService->getAccessTokenTtl(),
            ], 'Token refreshed successfully');
        } catch (\Exception $e) {
            $rawMessage = $e->getMessage();
            $type       = strtok($rawMessage, ':');

            $businessCode = 2003;
            $clientMsg    = 'Invalid refresh token format or signature';

            switch ($type) {
                case JwtService::ERR_TOKEN_EXPIRED:
                    $businessCode = 2004;
                    $clientMsg    = 'Refresh token has expired';
                    break;
                case JwtService::ERR_INVALID_ISSUER:
                    $businessCode = 2005;
                    $clientMsg    = 'Token issuer mismatch';
                    break;
                case JwtService::ERR_INVALID_TOKEN_TYPE:
                    $businessCode = 2006;
                    $clientMsg    = 'Expected refresh token, got access token';
                    break;
                case JwtService::ERR_TOKEN_REVOKED:
                    $businessCode = 2007;
                    $clientMsg    = 'Refresh token has been revoked or invalidated';

                    // Log potential replay attack or revoked usage | 记录可能的重放攻击或撤销后的使用
                    $decoded  = $this->jwtService->decodeTokenPayload($token) ?? [];
                    $traceId = method_exists($request, 'traceId') ? $request->traceId() : uniqid('trace_', true);

                    Log::warning('Refresh token revoked or invalidated', [
                        'type'            => $type,
                        'scene'           => 'auth.refresh',
                        'user_id'         => $decoded['user_id'] ?? null,
                        'tenant_id'       => $decoded['tenant_id'] ?? null,
                        'site_id'         => $decoded['site_id'] ?? null,
                        'jti'             => $decoded['jti'] ?? null,
                        'ip'              => $request->ip(),
                        'user_agent'      => $request->header('User-Agent'),
                        'x_forwarded_for' => $request->header('X-Forwarded-For'),
                        'trace_id'        => $traceId,
                    ]);
                    break;
                default:
                    // keep default mapping to INVALID_TOKEN_FORMAT
                    break;
            }

            return $this->error($clientMsg, $businessCode, [], 401);
        }
    }

    /**
     * Get current user info | 获取当前用户信息
     *
     * GET /v1/auth/me
     *
     * Returns user information including permissions in `resource:action` format.
     * 返回用户信息，包括 `resource:action` 格式的权限。
     *
     * @param \think\Request $request
     * @return Response
     */
    public function me(\think\Request $request): Response
    {
        $userId = $request->userId();

        if (!$userId) {
            return $this->error('Unauthorized', 401);
        }

        try {
            // 按当前租户范围查询用户，避免跨租户信息泄露
            // Query user within current tenant to prevent cross-tenant leakage
            $user = $this->userRepository->findById($userId, $request->tenantId());

            if (!$user) {
                return $this->error($this->langService->trans('auth.user_not_found'), 404);
            }

            // Get user roles | 获取用户角色
            // 说明：getRoleIds() 已内置租户隔离（仅返回与用户同租户的角色），此处无需再过滤
            $roleIds = $this->userRepository->getRoleIds($userId);

            // Resolve role slugs for frontend authority matching (defect A7) | 解析角色 slug（缺陷 A7）
            //
            // 前端路由守卫使用 `route.meta.authority`（角色 **slug**，如 `admin`）做菜单过滤，
            // 而原响应只提供角色 ID，导致「整数 ID vs 字符串 slug」永不匹配、系统管理菜单整体消失。
            // 因此新增 `role_slugs`；原有 `roles`（角色 ID）保留以兼容既有消费者。
            $roleSlugs = [];
            if (!empty($roleIds)) {
                // $roleIds 已按租户过滤，这里直接取 slug 即可，无需重复过滤租户
                $slugs = Db::name('roles')
                    ->whereIn('id', $roleIds)
                    ->column('slug');
                // 统一转为字符串并剔除空值，保证返回的是纯 slug 数组
                $roleSlugs = array_values(array_filter(
                    array_map('strval', $slugs),
                    static fn (string $slug): bool => $slug !== ''
                ));
            }

            // Get user permissions in resource:action format | 获取用户权限（resource:action 格式）
            $permissions = $this->permissionService->getUserPermissions($userId);

            return $this->success([
                'user' => $user->toArray(),
                'roles' => $roleIds,              // 兼容字段：角色 ID 数组 | Legacy: role IDs
                'role_slugs' => $roleSlugs,       // 新增字段：角色 slug 数组 | New: role slugs
                'permissions' => $permissions,  // New field | 新增字段
            ]);
        } catch (\Exception $e) {
            // Handle unexpected internal error | 处理未预期的内部异常
            return $this->handleInternalError($e, 'auth.me');
        }
    }

    /**
     * Get user permission codes | 获取用户权限码
     *
     * GET /v1/auth/codes
     *
     * Returns an array of permission codes in `resource:action` format.
     * This is a thin wrapper around /v1/auth/me.permissions for clients
     * that only need the permission codes.
     *
     * 返回 `resource:action` 格式的权限码数组。
     * 这是 /v1/auth/me.permissions 的瘦包装，供只需要权限码的客户端使用。
     *
     * @param \think\Request $request
     * @return Response
     */
    public function codes(\think\Request $request): Response
    {
        $userId = $request->userId();

        if (!$userId) {
            return $this->error('Unauthorized', 401);
        }

        try {
            // Get user permissions in resource:action format | 获取用户权限（resource:action 格式）
            $permissions = $this->permissionService->getUserPermissions($userId);

            return $this->success($permissions);
        } catch (\Exception $e) {
            // Handle unexpected internal error | 处理未预期的内部异常
            return $this->handleInternalError($e, 'auth.codes');
        }
    }

    /**
     * Handle unexpected internal errors in auth flows | 处理认证流程中的内部异常
     *
     * Hides internal exception details from clients and logs full context
     * (including trace_id) for observability.
     * 对外隐藏内部异常细节，仅返回通用错误消息，并记录包含 trace_id 的详细日志。
     *
     * @param \Throwable $e Exception instance | 异常实例
     * @param string $scene Logical scene identifier (e.g. auth.login) | 逻辑场景标识
     * @return Response
     */
    protected function handleInternalError(\Throwable $e, string $scene): Response
    {
        $traceId = $this->getTraceId();

        Log::error('Internal error in AuthController', [
            'scene'     => $scene,
            'message'   => $e->getMessage(),
            'exception' => $e,
            'trace_id'  => $traceId,
        ]);

        // Generic internal error response | 通用内部错误响应
        return $this->error($this->langService->trans('error.internal_server_error'), 5000, [], 500);
    }

    /**
     * 获取或创建指定租户的默认 user 角色 ID
     * - 若已存在 slug=user 的角色则直接返回
     * - 否则从租户1模板角色复制并同步角色权限
     */
    protected function resolveOrCreateDefaultUserRoleId(int $tenantId): int
    {
        $role = Db::name('roles')
            ->where('tenant_id', $tenantId)
            ->where('slug', 'user')
            ->find();

        if ($role) {
            return (int) $role['id'];
        }

        $templateRole = Db::name('roles')
            ->where('tenant_id', 1)
            ->where('slug', 'user')
            ->find();

        if (!$templateRole) {
            throw new \RuntimeException('默认 user 角色模板缺失');
        }

        Db::startTrans();
        try {
            // Double check under transaction to avoid duplicates | 事务内二次检查避免重复插入
            $existing = Db::name('roles')
                ->where('tenant_id', $tenantId)
                ->where('slug', 'user')
                ->find();

            if ($existing) {
                Db::commit();
                return (int) $existing['id'];
            }

            $now = date('Y-m-d H:i:s');
            $newRoleId = (int) Db::name('roles')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => $templateRole['name'] ?? 'User',
                'slug' => 'user',
                'description' => $templateRole['description'] ?? 'Regular user with basic access',
                // 延用模板的 is_system 语义，按租户维度落盘
                'is_system' => $templateRole['is_system'] ?? 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $permissionIds = Db::name('role_permissions')
                ->where('role_id', (int) $templateRole['id'])
                ->column('permission_id');

            if (!empty($permissionIds)) {
                $rows = [];
                foreach ($permissionIds as $permissionId) {
                    $rows[] = [
                        'role_id' => $newRoleId,
                        'permission_id' => (int) $permissionId,
                        'created_at' => $now,
                    ];
                }
                Db::name('role_permissions')->insertAll($rows);
            }

            Db::commit();
            return $newRoleId;
        } catch (\Throwable $e) {
            Db::rollback();

            $existing = Db::name('roles')
                ->where('tenant_id', $tenantId)
                ->where('slug', 'user')
                ->find();
            if ($existing) {
                return (int) $existing['id'];
            }

            throw $e;
        }
    }
}
