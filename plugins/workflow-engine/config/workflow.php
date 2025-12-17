<?php

return [
    // 工作流引擎基础配置
    'engine' => [
        // 是否启用工作流引擎
        'enabled' => true,

        // 引擎配置类
        'configuration_class' => \Alkaid\Workflow\Engine\cfg\Configuration::class,

        // 默认工作流类型
        'default_workflow_type' => 'approval',

        // 调试模式
        'debug' => env('APP_DEBUG', false),

        // 日志配置
        'logging' => [
            'channel' => 'workflow',
            'level' => env('APP_DEBUG', false) ? 'debug' : 'info',
            'path' => app()->getRuntimePath() . 'log/workflow/',
        ],
    ],

    // 数据库配置
    'database' => [
        // 表前缀
        'prefix' => 'alkaid_',

        // 数据表配置
        'tables' => [
            'workflow_definitions' => 'workflow_definitions',
            'workflow_instances' => 'workflow_instances',
            'workflow_tasks' => 'workflow_tasks',
            'workflow_triggers' => 'workflow_triggers',
            'workflow_event_logs' => 'workflow_event_logs',
        ],

        // 分区配置（大数据量时使用）
        'partitioning' => [
            'enabled' => false,
            'type' => 'range', // range, hash
            'column' => 'created_at',
            'interval' => 'month', // month, day
        ],
    ],

    // 缓存配置
    'cache' => [
        // 缓存驱动
        'driver' => env('CACHE_DRIVER', 'redis'),

        // 缓存键前缀
        'prefix' => 'workflow:',

        // 缓存配置
        'stores' => [
            'redis' => [
                'type' => 'redis',
                'host' => env('REDIS_HOST', '127.0.0.1'),
                'port' => env('REDIS_PORT', 6379),
                'password' => env('REDIS_PASSWORD', ''),
                'select' => env('REDIS_DB', 0),
            ],
            'file' => [
                'type' => 'file',
                'path' => app()->getRuntimePath() . 'cache/workflow/',
            ],
        ],

        // 缓存时间（秒）
        'ttl' => [
            'workflow_definition' => 3600, // 1小时
            'workflow_instance' => 1800, // 30分钟
            'workflow_task' => 900, // 15分钟
            'workflow_statistics' => 7200, // 2小时
        ],
    ],

    // 多租户配置
    'tenant' => [
        // 是否启用多租户
        'enabled' => true,

        // 租户隔离策略
        'isolation_strategy' => 'column', // column, schema, database

        // 默认租户ID
        'default_tenant_id' => 1,

        // 租户缓存前缀
        'cache_prefix' => 'tenant:',

        // 租户配置
        'tenant_config' => [
            'cache_enabled' => true,
            'custom_fields' => [],
        ],
    ],

    // 节点配置
    'nodes' => [
        // 节点类型映射
        'type_mapping' => [
            'start' => \Alkaid\Workflow\Engine\parser\handler\StartParser::class,
            'end' => \Alkaid\Workflow\Engine\parser\handler\EndParser::class,
            'task' => \Alkaid\Workflow\Engine\parser\handler\TaskParser::class,
            'decision' => \Alkaid\Workflow\Engine\parser\handler\DecisionParser::class,
            'fork' => \Alkaid\Workflow\Engine\parser\handler\ForkParser::class,
            'join' => \Alkaid\Workflow\Engine\parser\handler\JoinParser::class,
            'custom' => \Alkaid\Workflow\Engine\parser\handler\CustomParser::class,
            'sub_process' => \Alkaid\Workflow\Engine\parser\handler\WfSubProcessParser::class,
        ],

        // 默认节点配置
        'default_config' => [
            'timeout' => 86400, // 24小时
            'retry_count' => 3,
            'retry_interval' => 300, // 5分钟
        ],

        // 节点扩展配置
        'extensions' => [
            // 自定义节点处理器
            'custom_handlers' => [],
        ],
    ],

    // 任务配置
    'task' => [
        // 默认任务配置
        'defaults' => [
            'priority' => 5,
            'timeout' => 86400, // 24小时
            'reminder_enabled' => true,
            'reminder_times' => [3600, 1800, 600], // 提前1小时、30分钟、10分钟
        ],

        // 任务分配策略
        'assignment_strategy' => 'user', // user, role, department

        // 任务完成策略
        'completion_strategy' => 'first', // first, all, any

        // 任务通知配置
        'notification' => [
            'enabled' => true,
            'channels' => ['email', 'sms', 'webhook'],
            'template' => 'workflow/task_notification',
        ],
    ],

    // 触发器配置
    'trigger' => [
        // 触发器类型
        'types' => [
            'manual' => '手动触发',
            'webhook' => 'Webhook触发',
            'schedule' => '定时触发',
            'data_event' => '数据事件触发',
            'message_event' => '消息事件触发',
        ],

        // 触发器配置
        'config' => [
            'webhook' => [
                'timeout' => 30,
                'retry_count' => 3,
                'retry_interval' => 60,
            ],
            'schedule' => [
                'cron_expression' => '* * * * *',
                'timezone' => 'Asia/Shanghai',
            ],
        ],
    ],

    // 事件配置
    'event' => [
        // 是否启用事件系统
        'enabled' => true,

        // 事件监听器
        'listeners' => [
            'workflow.started' => [\Alkaid\Workflow\Engine\event\ProcessEventService::class, 'onWorkflowStarted'],
            'workflow.completed' => [\Alkaid\Workflow\Engine\event\ProcessEventService::class, 'onWorkflowCompleted'],
            'workflow.failed' => [\Alkaid\Workflow\Engine\event\ProcessEventService::class, 'onWorkflowFailed'],
            'task.created' => [\Alkaid\Workflow\Engine\event\ProcessEventService::class, 'onTaskCreated'],
            'task.completed' => [\Alkaid\Workflow\Engine\event\ProcessEventService::class, 'onTaskCompleted'],
            'task.timeout' => [\Alkaid\Workflow\Engine\event\ProcessEventService::class, 'onTaskTimeout'],
        ],

        // 事件队列
        'queue' => [
            'enabled' => true,
            'connection' => 'default',
            'queue' => 'workflow_events',
        ],
    ],

    // 表达式引擎配置
    'expression' => [
        // 表达式引擎类
        'engine_class' => \Alkaid\Workflow\Engine\Expression\ExpressionEngine::class,

        // 允许的函数
        'allowed_functions' => [
            'date', 'time', 'strtotime', 'number_format',
            'min', 'max', 'abs', 'round', 'ceil', 'floor',
        ],

        // 安全配置
        'security' => [
            'disabled_functions' => ['exec', 'shell_exec', 'system', 'passthru'],
            'max_execution_time' => 30,
            'max_memory' => '128M',
        ],
    ],

    // 性能配置
    'performance' => [
        // 异步处理
        'async' => [
            'enabled' => true,
            'connection' => 'redis',
            'queue' => 'workflow_async',
            'worker_count' => 5,
        ],

        // 批处理
        'batch' => [
            'enabled' => true,
            'batch_size' => 100,
            'timeout' => 300,
        ],

        // 并发控制
        'concurrency' => [
            'max_instances_per_user' => 10,
            'max_tasks_per_instance' => 1000,
        ],
    ],

    // 监控配置
    'monitoring' => [
        // 是否启用监控
        'enabled' => env('APP_DEBUG', false),

        // 性能指标
        'metrics' => [
            'enabled' => true,
            'interval' => 60, // 秒
            'retention' => 86400, // 24小时
        ],

        // 健康检查
        'health_check' => [
            'enabled' => true,
            'interval' => 300, // 5分钟
            'timeout' => 30,
        ],
    ],

    // API配置
    'api' => [
        // API版本
        'version' => 'v1',

        // API前缀
        'prefix' => '/api/workflow',

        // 认证方式
        'auth' => [
            'enabled' => true,
            'type' => 'jwt', // jwt, session, oauth
        ],

        // 限流配置
        'rate_limit' => [
            'enabled' => true,
            'requests' => 100,
            'period' => 3600, // 1小时
        ],

        // 跨域配置
        'cors' => [
            'enabled' => true,
            'allow_origins' => ['*'],
            'allow_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
            'allow_headers' => ['Content-Type', 'Authorization', 'X-Tenant-ID'],
        ],
    ],

    // 扩展配置
    'extensions' => [
        // 扩展目录
        'path' => app()->getAppPath() . 'workflow/extensions/',

        // 已加载的扩展
        'loaded' => [],

        // 扩展配置
        'configs' => [],
    ],
];
