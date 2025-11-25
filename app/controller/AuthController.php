<?php

declare(strict_types=1);

namespace app\controller;

use Infrastructure\Auth\JwtService;
use Infrastructure\User\Repository\UserRepository;
use Infrastructure\Permission\Service\PermissionService;
use Domain\User\Model\User;
use think\Response;
use think\facade\Log;

/**
 * Auth Controller | 认证控制器
 *
 * Handles user authentication endpoints.
 * 处理用户认证端点。
 *
 * @package app\controller
 */
class AuthController extends ApiController
{
    protected JwtService $jwtService;
    protected UserRepository $userRepository;
    protected PermissionService $permissionService;

    /**
     * Constructor with dependency injection | [0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m
     *
     * Use ThinkPHP container to inject dependencies instead of manual instantiation.
     * [0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m
     *
     * [0m[38;5;244m[48;5;236m@param \think\App $app Application instance | [0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m[0m[38;5;244m[48;5;236m应用实例
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
            ], 'Login successful');
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

        $tenantId = $request->tenantId();

        try {
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

            // Assign default user role (ID: 2) | 分配默认用户角色
            $this->userRepository->assignRole($userId, 2);

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
            ], 'Registration successful');
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
            $user = $this->userRepository->findById($userId);

            if (!$user) {
                return $this->error('User not found', 404);
            }

            // Get user roles | 获取用户角色
            $roleIds = $this->userRepository->getRoleIds($userId);

            // Get user permissions in resource:action format | 获取用户权限（resource:action 格式）
            $permissions = $this->permissionService->getUserPermissions($userId);

            return $this->success([
                'user' => $user->toArray(),
                'roles' => $roleIds,
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
        return $this->error('Internal server error', 5000, [], 500);
    }
}
