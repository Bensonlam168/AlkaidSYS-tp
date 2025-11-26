<?php

/**
 * Application Menu Configuration | 应用菜单配置
 *
 * Define menus that will be registered when the application is installed.
 * 定义应用安装时将注册的菜单。
 *
 * @return array<int, array<string, mixed>>
 */
return [
    [
        'title' => 'Template Application',
        'icon' => 'template',
        'path' => '/template',
        'sort' => 100,
        'status' => 1,
        'children' => [
            [
                'title' => 'Dashboard',
                'path' => '/template/dashboard',
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => 'Settings',
                'path' => '/template/settings',
                'sort' => 2,
                'status' => 1,
            ],
        ],
    ],
];
