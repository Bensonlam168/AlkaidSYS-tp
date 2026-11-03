<?php

declare(strict_types=1);

/**
 * Permission Language Pack | 权限模块语言包（zh-cn）
 *
 * 只登记本包（缺陷 #1：权限码不可变）新增的键。
 * 历史遗留的 `permission.not_found` / `permission.created` 等键此前并未入包
 * （`LanguageService::trans()` 会原样返回键名），本文件**刻意不顺手补全**：
 * 补全会改写既有接口的响应文案，属于另一个变更，应与本次安全修复分开评估。
 *
 * @package app\lang\zh-cn
 */
return [
    // 已存在的权限码被尝试改写（slug / resource / action）
    'code_immutable' => '权限码不可修改，如需变更请走迁移',

    // 新建的权限码不在操作者自身权限集内
    'code_not_owned' => '权限不足：不能创建超出自身权限范围的权限码，新增权限请走迁移',

    // 新建时无法从 slug / resource / action 解析出权限码
    'code_required' => '无法解析出权限码：resource 与 action 均为必填，且可由 slug（resource.action）推导',

    // 字段类型不合法（请求体是任意 JSON，字符串字段被传成数组/数字等）
    'invalid_field_type' => ':field 必须是字符串',
];
