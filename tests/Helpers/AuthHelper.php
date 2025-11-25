<?php

declare(strict_types=1);

namespace tests\Helpers;

use Infrastructure\Auth\JwtService;
use think\App;
use think\Request;

/**
 * Authentication Helper for Tests | 测试认证辅助工具
 *
 * Provides utilities for generating JWT tokens and authenticated requests in test environment.
 * 为测试环境提供生成 JWT token 和已认证请求的工具。
 *
 * @package tests\Helpers
 */
class AuthHelper
{
    /**
     * Generate test JWT access token | 生成测试 JWT access token
     *
     * Creates a valid JWT access token for testing purposes.
     * 创建用于测试的有效 JWT access token。
     *
     * @param int $userId User ID | 用户ID (default: 1)
     * @param int $tenantId Tenant ID | 租户ID (default: 1)
     * @param int $siteId Site ID | 站点ID (default: 0)
     * @return string JWT access token | JWT access token
     *
     * @example
     * ```php
     * $token = AuthHelper::generateTestToken();
     * $token = AuthHelper::generateTestToken(userId: 2, tenantId: 1);
     * ```
     */
    public static function generateTestToken(
        int $userId = 1,
        int $tenantId = 1,
        int $siteId = 0
    ): string {
        $jwtService = new JwtService();
        return $jwtService->generateAccessToken($userId, $tenantId, $siteId);
    }

    /**
     * Create authenticated request for tests | 创建已认证的测试请求
     *
     * Creates a Request instance with a valid JWT token in the Authorization header.
     * 创建一个在 Authorization header 中包含有效 JWT token 的 Request 实例。
     *
     * @param App $app Application instance | 应用实例
     * @param int $userId User ID | 用户ID (default: 1)
     * @param int $tenantId Tenant ID | 租户ID (default: 1)
     * @param int $siteId Site ID | 站点ID (default: 0)
     * @return Request Authenticated request instance | 已认证的请求实例
     *
     * @example
     * ```php
     * $request = AuthHelper::createAuthenticatedRequest($this->app());
     * $request = AuthHelper::createAuthenticatedRequest($this->app(), userId: 2);
     * ```
     */
    public static function createAuthenticatedRequest(
        App $app,
        int $userId = 1,
        int $tenantId = 1,
        int $siteId = 0
    ): Request {
        $token = self::generateTestToken($userId, $tenantId, $siteId);

        return $app->make(Request::class)
            ->withHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * Generate test refresh token | 生成测试 refresh token
     *
     * Creates a valid JWT refresh token for testing purposes.
     * 创建用于测试的有效 JWT refresh token。
     *
     * @param int $userId User ID | 用户ID (default: 1)
     * @param int $tenantId Tenant ID | 租户ID (default: 1)
     * @param int $siteId Site ID | 站点ID (default: 0)
     * @return string JWT refresh token | JWT refresh token
     *
     * @example
     * ```php
     * $refreshToken = AuthHelper::generateTestRefreshToken();
     * ```
     */
    public static function generateTestRefreshToken(
        int $userId = 1,
        int $tenantId = 1,
        int $siteId = 0
    ): string {
        $jwtService = new JwtService();
        return $jwtService->generateRefreshToken($userId, $tenantId, $siteId);
    }

    /**
     * Create request with custom headers | 创建带自定义 headers 的请求
     *
     * Creates a Request instance with custom headers for testing.
     * 创建一个带自定义 headers 的 Request 实例用于测试。
     *
     * @param App $app Application instance | 应用实例
     * @param array $headers Custom headers | 自定义 headers
     * @param int $userId User ID for token generation | 用于生成 token 的用户ID (default: 1)
     * @param int $tenantId Tenant ID for token generation | 用于生成 token 的租户ID (default: 1)
     * @param int $siteId Site ID for token generation | 用于生成 token 的站点ID (default: 0)
     * @return Request Request instance with custom headers | 带自定义 headers 的请求实例
     *
     * @example
     * ```php
     * $request = AuthHelper::createRequestWithHeaders($this->app(), [
     *     'X-Custom-Header' => 'value',
     *     'Accept' => 'application/json'
     * ]);
     * ```
     */
    public static function createRequestWithHeaders(
        App $app,
        array $headers = [],
        int $userId = 1,
        int $tenantId = 1,
        int $siteId = 0
    ): Request {
        $token = self::generateTestToken($userId, $tenantId, $siteId);

        $request = $app->make(Request::class)
            ->withHeader('Authorization', 'Bearer ' . $token);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
