<?php

declare(strict_types=1);

namespace app\constant;

/**
 * HTTP Status Code Constants | HTTP 状态码常量
 *
 * Standard HTTP status codes used across the application.
 * 应用程序中使用的标准 HTTP 状态码。
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status
 * @package app\constant
 */
final class HttpStatus
{
    // 2xx Success | 成功
    public const OK = 200;
    public const CREATED = 201;
    public const ACCEPTED = 202;
    public const NO_CONTENT = 204;

    // 3xx Redirection | 重定向
    public const MOVED_PERMANENTLY = 301;
    public const FOUND = 302;
    public const NOT_MODIFIED = 304;

    // 4xx Client Error | 客户端错误
    public const BAD_REQUEST = 400;
    public const UNAUTHORIZED = 401;
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const METHOD_NOT_ALLOWED = 405;
    public const UNPROCESSABLE_ENTITY = 422;
    public const TOO_MANY_REQUESTS = 429;

    // 5xx Server Error | 服务器错误
    public const INTERNAL_SERVER_ERROR = 500;
    public const BAD_GATEWAY = 502;
    public const SERVICE_UNAVAILABLE = 503;
    public const GATEWAY_TIMEOUT = 504;

    /**
     * HTTP status messages | HTTP 状态消息
     */
    public const MESSAGES = [
        self::OK => 'OK',
        self::CREATED => 'Created',
        self::ACCEPTED => 'Accepted',
        self::NO_CONTENT => 'No Content',
        self::BAD_REQUEST => 'Bad Request',
        self::UNAUTHORIZED => 'Unauthorized',
        self::FORBIDDEN => 'Forbidden',
        self::NOT_FOUND => 'Not Found',
        self::METHOD_NOT_ALLOWED => 'Method Not Allowed',
        self::UNPROCESSABLE_ENTITY => 'Unprocessable Entity',
        self::TOO_MANY_REQUESTS => 'Too Many Requests',
        self::INTERNAL_SERVER_ERROR => 'Internal Server Error',
        self::BAD_GATEWAY => 'Bad Gateway',
        self::SERVICE_UNAVAILABLE => 'Service Unavailable',
        self::GATEWAY_TIMEOUT => 'Gateway Timeout',
    ];

    /**
     * Get message for status code | 获取状态码消息
     *
     * @param int $code HTTP status code
     * @return string Status message
     */
    public static function getMessage(int $code): string
    {
        return self::MESSAGES[$code] ?? 'Unknown Status';
    }

    /**
     * Check if status code indicates success | 检查状态码是否表示成功
     *
     * @param int $code HTTP status code
     * @return bool
     */
    public static function isSuccess(int $code): bool
    {
        return $code >= 200 && $code < 300;
    }

    /**
     * Check if status code indicates client error | 检查状态码是否表示客户端错误
     *
     * @param int $code HTTP status code
     * @return bool
     */
    public static function isClientError(int $code): bool
    {
        return $code >= 400 && $code < 500;
    }

    /**
     * Check if status code indicates server error | 检查状态码是否表示服务器错误
     *
     * @param int $code HTTP status code
     * @return bool
     */
    public static function isServerError(int $code): bool
    {
        return $code >= 500 && $code < 600;
    }
}
