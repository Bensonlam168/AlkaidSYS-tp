<?php

declare(strict_types=1);

/**
 * Error Messages - Chinese | 错误消息 - 中文
 *
 * @package app\lang\zh-cn
 */
return [
    // Success | 成功
    'success' => '成功',

    // Common errors | 通用错误
    'unknown_error' => '未知错误',
    'internal_server_error' => '服务器内部错误',
    'service_unavailable' => '服务暂时不可用',

    // Validation errors | 验证错误
    'validation_failed' => '验证失败',
    'invalid_parameters' => '参数无效',
    'missing_required_field' => '缺少必填字段',
    'invalid_format' => '格式无效',

    // Authentication errors | 认证错误
    'unauthorized' => '未授权',
    'token_missing' => 'Token缺失、无效或已过期',
    'token_invalid' => 'Token无效',
    'token_expired' => 'Token已过期',
    'invalid_credentials' => '用户名或密码错误',
    'user_not_found' => '用户不存在',
    'user_disabled' => '用户已被禁用',
    'login_failed' => '登录失败',

    // Authorization errors | 授权错误
    'forbidden' => '禁止访问',
    'permission_denied' => '权限不足',
    'access_denied' => '访问被拒绝',

    // Resource errors | 资源错误
    'resource_not_found' => '资源不存在',
    'resource_already_exists' => '资源已存在',
    'resource_conflict' => '资源冲突',

    // Rate limiting | 限流
    'rate_limited' => '请求过于频繁',
    'too_many_requests' => '请求次数过多',

    // Database errors | 数据库错误
    'database_error' => '数据库错误',
    'query_failed' => '查询失败',
    'connection_failed' => '连接失败',

    // External service errors | 外部服务错误
    'external_service_error' => '外部服务错误',
    'api_call_failed' => 'API调用失败',
    'timeout' => '请求超时',

    // Error codes mapping | 错误码映射
    1000 => '参数错误',
    1001 => '参数验证失败',
    1002 => '参数格式错误',
    1003 => '缺少必填参数',

    2001 => '未授权：Token缺失、无效或已过期',
    2002 => '权限不足',
    2003 => '用户名或密码错误',
    2004 => '用户不存在',
    2005 => '用户已被禁用',
    2006 => 'Token已过期',
    2007 => 'Token无效',

    3001 => '资源不存在',
    3002 => '资源已存在',
    3003 => '资源冲突',

    4001 => '请求过于频繁',
    4002 => '请求次数过多',

    5000 => '服务器内部错误',
    5001 => '数据库错误',
    5002 => '外部服务错误',
];
