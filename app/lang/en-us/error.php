<?php

declare(strict_types=1);

/**
 * Error Messages - English | 错误消息 - 英文
 *
 * @package app\lang\en-us
 */
return [
    // Success | 成功
    'success' => 'Success',

    // Common errors | 通用错误
    'unknown_error' => 'Unknown Error',
    'internal_server_error' => 'Internal Server Error',
    'service_unavailable' => 'Service Unavailable',

    // Validation errors | 验证错误
    'validation_failed' => 'Validation Failed',
    'invalid_parameters' => 'Invalid Parameters',
    'missing_required_field' => 'Missing Required Field',
    'invalid_format' => 'Invalid Format',

    // Authentication errors | 认证错误
    'unauthorized' => 'Unauthorized',
    'token_missing' => 'Token is missing, invalid, or expired',
    'token_invalid' => 'Invalid Token',
    'token_expired' => 'Token Expired',
    'invalid_credentials' => 'Invalid username or password',
    'user_not_found' => 'User not found',
    'user_disabled' => 'User has been disabled',
    'login_failed' => 'Login failed',
    // ER-04: single external message for an unusable session subject.
    // Deliberately does not distinguish user-missing / user-disabled / tenant-suspended / tenant-expired
    // (that would leak account existence and tenant operational state); the reason is logged only.
    'session_invalid' => 'Your session is no longer valid, please sign in again',
    // Tenant is not usable (login-time rejection; same 403 semantics as TenantIdentify)
    'tenant_unavailable' => 'The current tenant is not available',

    // Authorization errors | 授权错误
    'forbidden' => 'Forbidden',
    'permission_denied' => 'Permission Denied',
    'access_denied' => 'Access Denied',
    // ER-05 grant ceiling: out-of-scope grant (granting permissions you do not hold,
    // or assigning roles stronger than yourself). Actionable but non-probing:
    // it never lists the specific permissions the operator is missing.
    'grant_ceiling_exceeded' => 'Permission denied: you cannot grant permissions beyond your own scope',

    // Resource errors | 资源错误
    'resource_not_found' => 'Resource Not Found',
    'resource_already_exists' => 'Resource Already Exists',
    'resource_conflict' => 'Resource Conflict',

    // Rate limiting | 限流
    'rate_limited' => 'Rate Limited',
    'too_many_requests' => 'Too Many Requests',

    // Database errors | 数据库错误
    'database_error' => 'Database Error',
    'query_failed' => 'Query Failed',
    'connection_failed' => 'Connection Failed',

    // External service errors | 外部服务错误
    'external_service_error' => 'External Service Error',
    'api_call_failed' => 'API Call Failed',
    'timeout' => 'Request Timeout',

    // Error codes mapping | 错误码映射
    1000 => 'Invalid Parameters',
    1001 => 'Validation Failed',
    1002 => 'Invalid Format',
    1003 => 'Missing Required Parameters',

    2001 => 'Unauthorized: Token is missing, invalid, or expired',
    2002 => 'Permission Denied',
    2003 => 'Invalid Credentials',
    2004 => 'User Not Found',
    2005 => 'User Disabled',
    2006 => 'Token Expired',
    2007 => 'Invalid Token',

    3001 => 'Resource Not Found',
    3002 => 'Resource Already Exists',
    3003 => 'Resource Conflict',

    4001 => 'Rate Limited',
    4002 => 'Too Many Requests',

    5000 => 'Internal Server Error',
    5001 => 'Database Error',
    5002 => 'External Service Error',
];
