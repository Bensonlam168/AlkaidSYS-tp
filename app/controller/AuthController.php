<?php

declare(strict_types=1);

namespace app\controller;

use app\controller\ApiController;
use Infrastructure\Auth\JwtService;
use Infrastructure\User\Repository\UserRepository;
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

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->jwtService = new JwtService();
        $this->userRepository = new UserRepository();
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
            return $this->error('Login failed: ' . $e->getMessage());
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
            return $this->error('Registration failed: ' . $e->getMessage());
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

            return $this->success([
                'user' => $user->toArray(),
                'roles' => $roleIds,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to get user info: ' . $e->getMessage());
        }
    }
}
