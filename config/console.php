<?php

// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        // Test commands | 测试命令
        'test:schema' => \app\command\TestSchema::class,
        'test:event' => \app\command\TestEvent::class,
        'test:validator' => \app\command\TestValidator::class,
        'test:field' => \app\command\TestField::class,
        'test:collection' => \app\command\TestCollection::class,
        'test:field-types' => \app\command\TestFieldTypes::class,
        'test:lowcode-collection' => \app\command\TestLowcodeCollection::class,
        'test:ddl-guard' => \app\command\TestDDLGuard::class,
        'test:session-redis' => \app\command\TestSessionRedis::class,

        // Lowcode commands | 低代码命令
        'lowcode:create-model' => \app\command\lowcode\CreateModelCommand::class,
        'lowcode:create-form' => \app\command\lowcode\CreateFormCommand::class,
        'lowcode:generate' => \app\command\lowcode\GenerateCommand::class,
        'lowcode:migration:diff' => \app\command\lowcode\MigrationDiffCommand::class,
    ],
];
