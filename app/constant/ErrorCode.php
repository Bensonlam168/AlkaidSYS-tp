<?php

declare(strict_types=1);

namespace app\constant;

/**
 * Business Error Code Constants | 业务错误码常量
 *
 * Centralized definition of all business error codes.
 * 所有业务错误码的集中定义。
 *
 * Error Code Ranges | 错误码范围：
 * - 0: Success | 成功
 * - 400-1999: Parameter/Validation errors | 参数/验证错误
 * - 2001-2007: Authentication/Authorization errors | 认证/授权错误
 * - 3001-3999: Resource errors | 资源错误
 * - 4001-4999: Rate limiting/Throttling | 限流相关
 * - 5000+: Server errors | 服务器错误
 *
 * @see docs/technical-specs/api/api-specification.md
 * @see design/04-security-performance/11-security-design.md
 * @package app\constant
 */
final class ErrorCode
{
    // Success | 成功
    public const SUCCESS = 0;

    // Parameter/Validation Errors (400-1999) | 参数/验证错误
    public const BAD_REQUEST = 400;
    public const VALIDATION_ERROR = 422;
    public const PARAMETER_MISSING = 1001;
    public const PARAMETER_INVALID = 1002;

    // Authentication Errors (2001-2007) | 认证错误
    public const UNAUTHORIZED = 2001;          // Token missing/invalid/expired
    public const FORBIDDEN = 2002;             // Insufficient permissions
    public const REFRESH_TOKEN_INVALID = 2003; // Invalid refresh token format
    public const REFRESH_TOKEN_EXPIRED = 2004; // Refresh token expired
    public const TOKEN_ISSUER_MISMATCH = 2005; // Token issuer mismatch
    public const WRONG_TOKEN_TYPE = 2006;      // Access token used for refresh
    public const TOKEN_REVOKED = 2007;         // Token revoked or replayed

    // Resource Errors (3001-3999) | 资源错误
    public const NOT_FOUND = 404;
    public const RESOURCE_NOT_FOUND = 3001;
    public const RESOURCE_EXISTS = 3002;
    public const RESOURCE_CONFLICT = 3003;

    // Rate Limiting (4001-4999) | 限流相关
    public const RATE_LIMITED = 4001;
    public const TOO_MANY_REQUESTS = 429;

    // Server Errors (5000+) | 服务器错误
    public const SERVER_ERROR = 5000;
    public const DATABASE_ERROR = 5001;
    public const EXTERNAL_SERVICE_ERROR = 5002;

    /**
     * Get message for error code | 获取错误码消息
     *
     * Uses language service for internationalization.
     * 使用语言服务实现国际化。
     *
     * @param int $code Error code
     * @param string|null $lang Language code (null = use current language)
     * @return string Error message
     */
    public static function getMessage(int $code, ?string $lang = null): string
    {
        try {
            $langService = app()->make(\Infrastructure\I18n\LanguageService::class);
            $message = $langService->transError($code, $lang);

            // If translation not found, return code-based key | 如果未找到翻译，返回基于代码的键
            if (str_starts_with($message, 'error.')) {
                return self::getFallbackMessage($code);
            }

            return $message;
        } catch (\Throwable $e) {
            // Fallback to English if language service fails | 如果语言服务失败，回退到英文
            return self::getFallbackMessage($code);
        }
    }

    /**
     * Get fallback message (English) | 获取回退消息（英文）
     *
     * @param int $code Error code
     * @return string Fallback message
     */
    protected static function getFallbackMessage(int $code): string
    {
        return match ($code) {
            self::SUCCESS => 'Success',
            self::BAD_REQUEST => 'Bad Request',
            self::VALIDATION_ERROR => 'Validation Error',
            self::PARAMETER_MISSING => 'Required Parameter Missing',
            self::PARAMETER_INVALID => 'Invalid Parameter',
            self::UNAUTHORIZED => 'Unauthorized',
            self::FORBIDDEN => 'Forbidden',
            self::REFRESH_TOKEN_INVALID => 'Invalid Refresh Token',
            self::REFRESH_TOKEN_EXPIRED => 'Refresh Token Expired',
            self::TOKEN_ISSUER_MISMATCH => 'Token Issuer Mismatch',
            self::WRONG_TOKEN_TYPE => 'Wrong Token Type',
            self::TOKEN_REVOKED => 'Token Revoked',
            self::NOT_FOUND => 'Not Found',
            self::RESOURCE_NOT_FOUND => 'Resource Not Found',
            self::RESOURCE_EXISTS => 'Resource Already Exists',
            self::RESOURCE_CONFLICT => 'Resource Conflict',
            self::RATE_LIMITED => 'Rate Limited',
            self::TOO_MANY_REQUESTS => 'Too Many Requests',
            self::SERVER_ERROR => 'Internal Server Error',
            self::DATABASE_ERROR => 'Database Error',
            self::EXTERNAL_SERVICE_ERROR => 'External Service Error',
            default => 'Unknown Error',
        };
    }

    /**
     * Check if code indicates authentication error | 检查是否为认证错误
     *
     * @param int $code Error code
     * @return bool
     */
    public static function isAuthError(int $code): bool
    {
        return $code >= 2001 && $code <= 2007;
    }

    /**
     * Get HTTP status code for business error code | 获取业务错误码对应的 HTTP 状态码
     *
     * @param int $code Business error code
     * @return int HTTP status code
     */
    public static function toHttpStatus(int $code): int
    {
        return match (true) {
            $code === self::SUCCESS => HttpStatus::OK,
            $code === self::VALIDATION_ERROR => HttpStatus::UNPROCESSABLE_ENTITY,
            $code === self::UNAUTHORIZED => HttpStatus::UNAUTHORIZED,
            $code === self::FORBIDDEN => HttpStatus::FORBIDDEN,
            $code >= 2003 && $code <= 2007 => HttpStatus::UNAUTHORIZED,
            $code === self::NOT_FOUND || $code === self::RESOURCE_NOT_FOUND => HttpStatus::NOT_FOUND,
            $code === self::RATE_LIMITED || $code === self::TOO_MANY_REQUESTS => HttpStatus::TOO_MANY_REQUESTS,
            $code >= 5000 => HttpStatus::INTERNAL_SERVER_ERROR,
            default => HttpStatus::BAD_REQUEST,
        };
    }
}
