<?php

declare(strict_types=1);

return [
    // 插件基础路径 | 默认 addons/plugins
    'base_path' => root_path() . 'addons' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR,

    // 启用的插件键列表（key 与插件命名空间保持一致）
    'enabled' => [],

    // 插件清单（可选）：用于显式声明插件类，避免扫描
    // 结构示例：
    // [
    //     [
    //         'key' => 'sample_plugin',
    //         'class' => \plugin\sample_plugin\Plugin::class,
    //         'manifest' => ['version' => '1.0.0'],
    //     ],
    // ]
    'plugins' => [],
];
