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
 * 环境行为 | Environment behaviour：
 * - 生产类环境（production/prod/stage/staging，或未设置 APP_ENV）：密钥为空、命中公开占位符
 *   或长度不足 32 字节时，**构造本类即抛异常**；由于本类是惰性构造的（中间件/DI 按需解析），
 *   进程不会在启动期失败，表现是「进程正常启动，之后每个需要鉴权的请求被拒绝」。
 * - 非生产环境（dev/local/testing）：允许占位符；密钥为空时回退到内置开发密钥并记录 warning。
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

    /**
     * 内置开发密钥 | Built-in development fallback secret
     *
     * 仅用于非生产环境：当 JWT_SECRET 为空时回退到该值。
     * 原因：firebase/php-jwt 拒绝空 key 解码，若直接用空密钥签名会出现
     * 「登录返回 200 且带 token，但随后每个受保护接口都 401」的极难定位问题。
     * 该值属于公开占位符（前缀 dev-only），生产环境会被 fail-fast 拒绝。
     */
    public const DEV_FALLBACK_SECRET = 'dev-only-insecure-secret-please-change-in-production-0123456789';

    /**
     * 生产环境公开占位符黑名单（精确匹配，忽略大小写）
     */
    protected const PUBLIC_PLACEHOLDER_SECRETS = [
        'CHANGE_THIS_IN_PRODUCTION',
        'REPLACE_WITH_STRONG_RANDOM_SECRET_KEY_AT_LEAST_32_BYTES',
    ];

    /**
     * 生产环境公开占位符前缀黑名单（前缀匹配，忽略大小写）
     *
     * 采用前缀匹配而不是逐个枚举，避免以后新增 REPLACE_WITH_XXX / DEV_ONLY_XXX
     * 之类占位符时又漏判。
     */
    protected const PUBLIC_PLACEHOLDER_PREFIXES = [
        'REPLACE_WITH',
        'DEV_ONLY',
        'DEV-ONLY',
    ];

    /**
     * 生产环境密钥最小长度（字节），用于保证足够熵
     */
    protected const MIN_PRODUCTION_SECRET_LENGTH = 32;

    /**
     * 生产类环境词表（与 app/command/EnvCheckCommand.php 的词表保持一致）
     */
    protected const PRODUCTION_ENVS = ['production', 'prod', 'stage', 'staging'];

    // Error type identifiers for token validation | Token 校验错误类型标识
    public const ERR_INVALID_TOKEN_FORMAT = 'INVALID_TOKEN_FORMAT';
    public const ERR_TOKEN_EXPIRED       = 'TOKEN_EXPIRED';
    public const ERR_INVALID_ISSUER      = 'INVALID_ISSUER';
    public const ERR_INVALID_TOKEN_TYPE  = 'INVALID_TOKEN_TYPE';
    public const ERR_TOKEN_REVOKED       = 'TOKEN_REVOKED';
    /**
     * 租户/用户上下文无效（缺陷 R19）
     *
     * 签发侧与校验侧共用：user_id <= 0 或 tenant_id <= 0 的令牌一律拒绝
     * （评审 M1 之后两侧校验维度一致：签发侧、校验侧都判 user_id 与 tenant_id）。
     * 注意：该文案不得包含 expired / revoked / decode 等词，
     * 否则 app\middleware\Auth 的失败原因归类会被误导。
     */
    public const ERR_INVALID_TENANT_SCOPE = 'INVALID_TENANT_SCOPE';

    public function __construct()
    {
        $this->secret = (string) \think\facade\Env::get('JWT_SECRET', 'CHANGE_THIS_IN_PRODUCTION');
        $this->issuer = (string) \think\facade\Env::get('JWT_ISSUER', 'https://api.alkaidsys.local');

        // 安全（缺陷 C-5）：生产环境密钥不安全时立即失败
        //
        // `CHANGE_THIS_IN_PRODUCTION` / `REPLACE_WITH_*` / `dev-only-*` 都是仓库内公开字符串，
        // 若生产环境仍使用它们（或密钥为空、长度不足），任何知道该字符串的人都能伪造任意用户的
        // token。生产环境必须在 .env 中配置足够长的随机密钥，否则构造 JwtService 时抛异常。
        //
        // 注意（问题 3）：JwtService 是**惰性构造**的（中间件 / DI 按需解析，无 provider 在启动期解析它），
        // 因此进程**不会**在启动期失败；真实表现是「进程正常启动，之后每个需要鉴权的请求被拒绝」。
        //
        // 未显式配置（或显式配置为空）APP_ENV 时按 production 处理（失败安全）。
        $appEnv = strtolower(trim((string) \think\facade\Env::get('APP_ENV', 'production')));
        if ($appEnv === '') {
            $appEnv = 'production';
        }

        if (in_array($appEnv, self::PRODUCTION_ENVS, true)) {
            $this->assertProductionSecretIsSecure($this->secret, $appEnv);
            return;
        }

        // 非生产环境（dev/local/testing）：空密钥回退到内置开发密钥，并记录 warning
        //
        // 缺陷修复（问题 1）：若不回退，空密钥会「签发成功但校验失败」——
        // firebase/php-jwt 的 Key::__construct 拒绝空 key，异常为
        // `INVALID_TOKEN_FORMAT: Key material must not be empty`。
        if (trim($this->secret) === '') {
            $this->secret = self::DEV_FALLBACK_SECRET;

            \think\facade\Log::warning(
                'JWT_SECRET is empty; falling back to the built-in development secret. '
                . 'The public development key is ONLY acceptable for local development/testing. | '
                . 'JWT_SECRET 为空，已回退到内置开发密钥（公开字符串），仅限本地开发/测试使用；生产环境必须配置独立强密钥。'
            );
        }
    }

    /**
     * 生产环境密钥强度校验 | Assert production secret strength
     *
     * 依次校验：非空 → 不是公开占位符 → 长度满足最小熵要求。
     * 异常消息中只暴露「原因 + 长度」，不输出密钥本身，避免把密钥写进日志。
     *
     * @param string $secret 待校验密钥
     * @param string $appEnv 已归一化（小写）的环境名
     * @throws \RuntimeException 密钥不满足生产环境要求时抛出
     */
    protected function assertProductionSecretIsSecure(string $secret, string $appEnv): void
    {
        $trimmed = trim($secret);

        if ($trimmed === '') {
            throw new \RuntimeException(sprintf(
                'JWT_SECRET must not be empty in production (APP_ENV=%s); configure a sufficiently long random secret',
                $appEnv
            ));
        }

        if ($this->isPublicPlaceholder($trimmed)) {
            throw new \RuntimeException(sprintf(
                'JWT_SECRET is a known public placeholder in production (APP_ENV=%s); '
                . 'generate a unique random secret, e.g. `openssl rand -hex 32`',
                $appEnv
            ));
        }

        if (strlen($trimmed) < self::MIN_PRODUCTION_SECRET_LENGTH) {
            throw new \RuntimeException(sprintf(
                'JWT_SECRET is too short (%d < %d bytes) for production (APP_ENV=%s); '
                . 'use at least %d bytes of entropy',
                strlen($trimmed),
                self::MIN_PRODUCTION_SECRET_LENGTH,
                $appEnv,
                self::MIN_PRODUCTION_SECRET_LENGTH
            ));
        }
    }

    /**
     * 判断密钥是否为仓库内公开的占位符 | Is a publicly known placeholder
     */
    protected function isPublicPlaceholder(string $secret): bool
    {
        $upper = strtoupper($secret);

        if (in_array($upper, self::PUBLIC_PLACEHOLDER_SECRETS, true)) {
            return true;
        }

        foreach (self::PUBLIC_PLACEHOLDER_PREFIXES as $prefix) {
            if (str_starts_with($upper, strtoupper($prefix))) {
                return true;
            }
        }

        return false;
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
     *
     * @throws \InvalidArgumentException 用户或租户上下文无效时抛出
     */
    public function generateAccessToken(int $userId, int $tenantId, int $siteId = 0): string
    {
        // 安全（缺陷 R19）：租户/用户上下文无效的 token 不得签发
        //
        // 历史实现把 $userId / $tenantId 原样写进 payload，tenant_id = 0 的 token
        // 能签发成功，但此后每个受保护请求都会因为「当前租户为 0」被拒绝，
        // 表现成「拿到 token 却到处 403/401」这种极难定位的问题。
        // 因此在这里 fail-fast，而不是让无效上下文流入下游。
        // 注意：刷新侧（generateRefreshToken）**没有**签发侧校验，
        // 刷新 token 的 user_id / tenant_id 校验位于 validateRefreshToken（校验侧），签发侧不重复。
        if ($userId <= 0 || $tenantId <= 0) {
            throw new \InvalidArgumentException(sprintf(
                '%s: user_id and tenant_id must both be positive (got user_id=%d, tenant_id=%d)',
                self::ERR_INVALID_TENANT_SCOPE,
                $userId,
                $tenantId
            ));
        }

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
     *
     * @throws \Exception 令牌无效、类型不符或用户/租户上下文无效（非正数）时抛出
     */
    public function validateAccessToken(string $token): array
    {
        $payload = $this->validateToken($token);

        if (($payload['type'] ?? null) !== 'access') {
            throw new \Exception(self::ERR_INVALID_TOKEN_TYPE . ': Expected access token');
        }

        // 安全（缺陷 R19）：校验侧必须兜住 user_id / tenant_id 非正数的令牌
        //
        // 签发侧已拒绝此类令牌，但**存量令牌**（本次改造之前签发的、仍在有效期内的）
        // 以及「拿到同一密钥的伪造者」都不会经过签发侧，因此校验侧必须再挡一次。
        // 否则 tenant_id = 0 会一路写进 Request 上下文，让按租户过滤的查询失去隔离语义。
        //
        // 补检 user_id（评审 M1）：签发侧校验的是 user_id 与 tenant_id **两个**维度，
        // 校验侧原先只判 tenant_id，于是伪造 `user_id = 0, tenant_id = 1` 的令牌能通过校验。
        // 这种令牌只在挂了 Permission 中间件的路由上被挡下；仅挂 Auth 的路由会以 0 号身份
        // 继续执行业务（0 号用户不存在，权限/审计/归属判定全部落在未定义语义上），
        // 因此把 user_id 并入同一处校验，与签发侧保持对称。
        $userId   = (int) ($payload['user_id'] ?? 0);
        $tenantId = (int) ($payload['tenant_id'] ?? 0);

        if ($userId <= 0 || $tenantId <= 0) {
            throw new \Exception(sprintf(
                '%s: user_id and tenant_id must both be positive (got user_id=%d, tenant_id=%d)',
                self::ERR_INVALID_TENANT_SCOPE,
                $userId,
                $tenantId
            ));
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
