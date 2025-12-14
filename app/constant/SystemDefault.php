<?php

declare(strict_types=1);

namespace app\constant;

/**
 * System Default Value Constants | 系统默认值常量
 *
 * Centralized definition of system default values.
 * 系统默认值的集中定义。
 *
 * @package app\constant
 */
final class SystemDefault
{
    // Multi-tenant Defaults | 多租户默认值
    public const TENANT_ID = 1;       // Default tenant ID | 默认租户 ID
    public const SITE_ID = 0;         // Default site ID (0 = main site) | 默认站点 ID（0 = 主站点）

    // Pagination Defaults | 分页默认值
    public const PAGE = 1;            // Default page number | 默认页码
    public const PAGE_SIZE = 20;      // Default page size | 默认每页数量
    public const MAX_PAGE_SIZE = 100; // Maximum allowed page size | 最大每页数量

    // Cache TTL Defaults (in seconds) | 缓存过期时间默认值（秒）
    public const CACHE_TTL_SHORT = 300;      // 5 minutes | 5 分钟
    public const CACHE_TTL_MEDIUM = 3600;    // 1 hour | 1 小时
    public const CACHE_TTL_LONG = 86400;     // 24 hours | 24 小时
    public const CACHE_TTL_WEEK = 604800;    // 7 days | 7 天

    // Token Expiry Defaults (in seconds) | Token 过期时间默认值（秒）
    public const ACCESS_TOKEN_TTL = 3600;          // 1 hour | 1 小时
    public const REFRESH_TOKEN_TTL = 1209600;      // 14 days | 14 天

    // Rate Limiting Defaults | 限流默认值
    public const RATE_LIMIT_REQUESTS = 60;  // Requests per window | 每窗口请求数
    public const RATE_LIMIT_WINDOW = 60;    // Window size in seconds | 窗口大小（秒）

    // System Limits | 系统限制
    public const MAX_LOGIN_ATTEMPTS = 5;    // Max login attempts before lockout | 锁定前最大登录尝试次数
    public const LOCKOUT_DURATION = 900;    // Lockout duration in seconds (15 min) | 锁定时长（秒，15 分钟）

    // File Upload Defaults | 文件上传默认值
    public const MAX_UPLOAD_SIZE = 10485760;  // 10 MB in bytes | 10 MB（字节）
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
}
