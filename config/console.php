<?php

// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        // Unified test entry point | 统一测试入口 (T-056)
        'test' => \app\command\TestCommand::class,

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

        // Route documentation commands | 路由文档命令 (T-060)
        'route:doc' => \app\command\RouteDocCommand::class,
        'route:verify' => \app\command\RouteVerifyCommand::class,

        // Language pack commands | 语言包命令 (T-061, T-063)
        'lang:check' => \app\command\LangCheckCommand::class,
        'lang:sync' => \app\command\LangSyncCommand::class,

        // Environment check commands | 环境检查命令 (T-062)
        'env:check' => \app\command\EnvCheckCommand::class,

        // Plugin commands | 插件命令 (T-035)
        'plugin:list' => \app\command\plugin\PluginListCommand::class,
        'plugin:enable' => \app\command\plugin\PluginEnableCommand::class,
        'plugin:disable' => \app\command\plugin\PluginDisableCommand::class,
        'plugin:scan' => \app\command\plugin\PluginScanCommand::class,

        // Migration batch semantics commands | 迁移批次语义命令 (R6)
        //
        // 注意：这里必须使用「数字键」而非 'migrate:run' => ... 的字符串键。
        // 原因：vendor 的 think\migration\Service 通过 think\Console::starting() 注册同名命令，
        //      这些回调在 Console::start() 中执行，**晚于** loadCommands() 读取本配置的时机，
        //      字符串键注册的类会在之后被 vendor 命令覆盖（已实测）。
        //      用数字键可让本命令在 loadCommands() 期间被实例化，从而在 configure() 中追加
        //      一个更晚的 starting 回调，在 vendor 之后再登记一次，完成覆盖。
        \app\command\migrate\RunCommand::class,
        \app\command\migrate\RollbackCommand::class,
    ],
];
