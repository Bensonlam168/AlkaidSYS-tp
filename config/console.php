<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'test:schema' => \app\command\TestSchema::class,
        'test:event' => \app\command\TestEvent::class,
        'test:validator' => \app\command\TestValidator::class,
        'test:field' => \app\command\TestField::class,
        'test:collection' => \app\command\TestCollection::class,
        'test:field-types' => \app\command\TestFieldTypes::class,
        'test:lowcode-collection' => \app\command\TestLowcodeCollection::class,
        'test:ddl-guard' => \app\command\TestDDLGuard::class,
        'test:session-redis' => \app\command\TestSessionRedis::class,
    ],
];
