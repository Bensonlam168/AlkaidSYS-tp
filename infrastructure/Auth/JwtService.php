<?php

declare(strict_types=1);

namespace Infrastructure\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

/**
 * JWT Service | JWT服务
 *
 * Handles JWT token generation and validation.
 * 处理JWT令牌的生成和验证。
 *
 * @package Infrastructure\Auth
 */
class JwtService
{
    protected string $secret;
    protected string $algorithm = 'HS256';
    /**
     * JWT issuer | JWT 签发者
     */
    protected string $issuer;

    /**
     * Default TTL for generic tokens (seconds) | 通用令牌默认有效期（秒）
     */
    protected int $ttl = 86400; // 24 hours

    /**
     * Access token TTL (seconds) | Access Token 有效期（秒）
     */
    protected int $accessTtl = 7200; // 2 hours

    /**
     * Refresh token TTL (seconds) | Refresh Token 有效期（秒）
     */
    protected int $refreshTtl = 604800; // 7 days

    // Error type identifiers for token validation | Token 校验错误类型标识
    public const ERR_INVALID_TOKEN_FORMAT = 'INVALID_TOKEN_FORMAT';
    public const ERR_TOKEN_EXPIRED       = 'TOKEN_EXPIRED';
    public const ERR_INVALID_ISSUER      = 'INVALID_ISSUER';
    public const ERR_INVALID_TOKEN_TYPE  = 'INVALID_TOKEN_TYPE';
    public const ERR_TOKEN_REVOKED       = 'TOKEN_REVOKED';

    public function __construct()
    {
        $this->secret = (string) \think\facade\Env::get('JWT_SECRET', 'CHANGE_THIS_IN_PRODUCTION');
        $this->issuer = (string) \think\facade\Env::get('JWT_ISSUER', 'https://api.alkaidsys.local');
    }

    /**
     * Generate JWT token | 生成JWT令牌
     *
     * @param array $payload Token payload | 令牌载荷
     * @return string JWT token | JWT令牌
     */
    public function generateToken(array $payload): string
    {
        $now = time();

        $tokenPayload = array_merge($payload, [
            'iat' => $now,                    // Issued at | 签发时间
            'exp' => $now + $this->ttl,       // Expiration | 过期时间
            'nbf' => $now,                    // Not before | 生效时间
        ]);

        return JWT::encode($tokenPayload, $this->secret, $this->algorithm);
    }

    /**
     * Validate and decode JWT token | 验证并解码JWT令牌
     *
     * @param string $token JWT token | JWT令牌
     * @return array Decoded payload | 解码后的载荷
     * @throws \Exception If token is invalid | 如果令牌无效
     */
    public function validateToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
        } catch (ExpiredException $e) {
            throw new \Exception(self::ERR_TOKEN_EXPIRED . ': ' . $e->getMessage());
        } catch (SignatureInvalidException | BeforeValidException | \UnexpectedValueException $e) {
            throw new \Exception(self::ERR_INVALID_TOKEN_FORMAT . ': ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new \Exception(self::ERR_INVALID_TOKEN_FORMAT . ': ' . $e->getMessage());
        }

        $payload = (array) $decoded;

        // Validate issuer | 校验签发者
        if (($payload['iss'] ?? null) !== $this->issuer) {
            throw new \Exception(self::ERR_INVALID_ISSUER . ': Token issuer mismatch');
        }

        // Check blacklist by jti
        if (isset($payload['jti']) && $this->isTokenBlacklisted($payload['jti'])) {
            throw new \Exception(self::ERR_TOKEN_REVOKED . ': Token has been revoked');
        }

        return $payload;
    }

    /**
     * Generate Access Token for user | 为用户生成 Access Token
     */
    public function generateAccessToken(int $userId, int $tenantId, int $siteId = 0): string
    {
        $claims = $this->buildStandardClaims($this->accessTtl, 'access');

        $payload = array_merge($claims, [
            'user_id'   => $userId,
            'tenant_id' => $tenantId,
            'site_id'   => $siteId,
        ]);

        return JWT::encode($payload, $this->secret, $this->algorithm);
    }

    /**
     * Generate Refresh Token for user | 为用户生成 Refresh Token
     */
    public function generateRefreshToken(int $userId, int $tenantId, int $siteId = 0): string
    {
        $claims = $this->buildStandardClaims($this->refreshTtl, 'refresh');

        $payload = array_merge($claims, [
            'user_id'   => $userId,
            'tenant_id' => $tenantId,
            'site_id'   => $siteId,
        ]);

        $token = JWT::encode($payload, $this->secret, $this->algorithm);

        // Store refresh token identifier for whitelist checks | 存储 Refresh Token 标识用于白名单校验
        $this->storeRefreshToken(
            $userId,
            $tenantId,
            (string) $claims['jti']
        );

        return $token;
    }

    /**
     * Validate Access Token | 验证 Access Token
     */
    public function validateAccessToken(string $token): array
    {
        $payload = $this->validateToken($token);

        if (($payload['type'] ?? null) !== 'access') {
            throw new \Exception(self::ERR_INVALID_TOKEN_TYPE . ': Expected access token');
        }

        return $payload;
    }

    /**
     * Validate Refresh Token | 验证 Refresh Token
     */
    public function validateRefreshToken(string $token): array
    {
        $payload = $this->validateToken($token);

        if (($payload['type'] ?? null) !== 'refresh') {
            throw new \Exception(self::ERR_INVALID_TOKEN_TYPE . ': Expected refresh token');
        }

        $userId   = (int) ($payload['user_id'] ?? 0);
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $jti      = (string) ($payload['jti'] ?? '');

        if ($userId <= 0 || $tenantId <= 0 || $jti === '') {
            throw new \Exception(self::ERR_INVALID_TOKEN_FORMAT . ': Invalid refresh token payload');
        }

        if (!$this->isRefreshTokenActive($userId, $tenantId, $jti)) {
            throw new \Exception(self::ERR_TOKEN_REVOKED . ': Refresh token has been revoked or invalidated');
        }

        return $payload;
    }

    /**
     * Get access token TTL (seconds) | 获取 Access Token 有效期（秒）
     */
    public function getAccessTokenTtl(): int
    {
        return $this->accessTtl;
    }

    /**
     * Generate JWT ID | 生成 JWT ID
     */
    protected function generateJti(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Build standard JWT claims | 构建标准 JWT 声明
     */
    protected function buildStandardClaims(int $ttl, string $type): array
    {
        $now = time();

        return [
            'iss'  => $this->issuer,
            'iat'  => $now,
            'nbf'  => $now,
            'exp'  => $now + $ttl,
            'jti'  => $this->generateJti(),
            'type' => $type,
        ];
    }

    /**
     * Decode raw token payload for logging/debug | 解码原始 Token 载荷（用于日志/调试）
     */
    public function decodeTokenPayload(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
            return (array) $decoded;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Store refresh token flag in cache | 在缓存中存储 Refresh Token 标记
     */
    protected function storeRefreshToken(int $userId, int $tenantId, string $jti): void
    {
        $key = $this->getRefreshTokenCacheKey($userId, $tenantId, $jti);
        cache($key, true, $this->refreshTtl);
    }

    /**
     * Invalidate refresh token (remove from whitelist) | 使 Refresh Token 失效（从白名单移除）
     */
    public function invalidateRefreshToken(int $userId, int $tenantId, string $jti): void
    {
        $key = $this->getRefreshTokenCacheKey($userId, $tenantId, $jti);
        cache($key, null);
    }

    /**
     * Check if refresh token is active | 检查 Refresh Token 是否仍然有效
     */
    protected function isRefreshTokenActive(int $userId, int $tenantId, string $jti): bool
    {
        $key = $this->getRefreshTokenCacheKey($userId, $tenantId, $jti);
        return cache($key) === true;
    }

    /**
     * Build refresh token cache key | 构建 Refresh Token 缓存键
     */
    protected function getRefreshTokenCacheKey(int $userId, int $tenantId, string $jti): string
    {
        return sprintf('refresh_token:%d:%d:%s', $userId, $tenantId, $jti);
    }

    /**
     * Revoke token by jti (blacklist) | 基于 jti 将令牌加入黑名单
     */
    public function revokeToken(string $jti, ?int $ttl = null): void
    {
        $ttl = $ttl ?? $this->refreshTtl;
        cache("token_blacklist:{$jti}", true, $ttl);
    }

    /**
     * Check if token is blacklisted | 检查令牌是否在黑名单中
     */
    protected function isTokenBlacklisted(string $jti): bool
    {
        return cache("token_blacklist:{$jti}") === true;
    }


    /**
     * Extract user ID from token | 从令牌提取用户ID
     *
     * @param string $token JWT token | JWT令牌
     * @return int|null User ID | 用户ID
     */
    public function getUserIdFromToken(string $token): ?int
    {
        try {
            $payload = $this->validateToken($token);
            return isset($payload['user_id']) ? (int)$payload['user_id'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
