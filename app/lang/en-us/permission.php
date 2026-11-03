<?php

declare(strict_types=1);

/**
 * Permission Language Pack | 权限模块语言包（en-us）
 *
 * 与 `app/lang/zh-cn/permission.php` 保持**同键**（`php think lang:check` 门禁要求）。
 *
 * @package app\lang\en-us
 */
return [
    // An existing permission code was rewritten (slug / resource / action)
    'code_immutable' => 'Permission code is immutable; change it through a migration',

    // The new permission code is not held by the operator
    'code_not_owned' => 'Permission denied: you cannot create a permission code beyond your own scope; add new permissions through a migration',

    // The code cannot be derived from slug / resource / action on create
    'code_required' => 'Cannot derive the permission code: both resource and action are required, and can be derived from slug (resource.action)',

    // Invalid field type (the request body is arbitrary JSON)
    'invalid_field_type' => ':field must be a string',
];
