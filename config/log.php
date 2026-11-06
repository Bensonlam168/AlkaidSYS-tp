<?php

// +----------------------------------------------------------------------
// | 日志设置
// +----------------------------------------------------------------------
return [
    // 默认日志记录通道
    'default'      => 'file',
    // 日志记录级别
    'level'        => [],
    // 日志类型记录的通道 ['error'=>'email',...]
    'type_channel' => [],
    // 关闭全局日志写入
    'close'        => false,
    // 全局日志处理 支持闭包
    'processor'    => null,

    // 日志通道列表
    'channels'     => [
        'file' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => '',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => [],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],
        // 事件告警专用通道：用于订阅 event.alert.*（T-049）
        'event_alert' => [
            'type'           => 'File',
            'path'           => '',
            // 独立写入单文件，便于 Promtail/ELK 采集
            'single'         => true,
            'file_size'      => 0,
            'apart_level'    => [],
            'max_files'      => 0,
            'json'           => true,
            'processor'      => null,
            'close'          => false,
            'format'         => '[%s][%s] %s',
            'realtime_write' => true,
        ],
        // 工作流引擎专用通道
        //
        // 为什么必须有：引擎的日志写入点硬编码为 `Log::channel('workflow')`
        // （plugins/workflow-engine/src/Services/Support/RecordsHistorySafely.php），
        // 而 ThinkPHP 的 `Log::channel()` 在通道不存在时**不回落默认通道**，而是抛
        // `InvalidArgumentException: Channel [workflow] not found.`（实测）。
        // 通道缺失 → 引擎吞掉异常降级到 `error_log()`：流程照跑，但「实例启动/任务完成/
        // 事件写入失败」这些审计旁路日志一条都进不了应用日志，属于最难发现的静默退化。
        // 因此这里显式声明，并在引擎装配期做「通道存在性」校验
        // （见 Infrastructure\Workflow\WorkflowEngineBootstrap::warnIfLogChannelMissing()，
        //  通道缺失时以默认通道打 WARNING —— 日志是旁路能力，不该让整个引擎装配失败）。
        //
        // 独立成文件 + json 行格式，便于 Promtail/ELK 单独采集工作流日志。
        // realtime_write 必须为 true：Swoole 常驻进程里 Log 实例按请求重建，
        // 非实时写入会依赖析构/请求收尾落盘，长驻进程下容易丢最后一批日志。
        'workflow' => [
            'type'           => 'File',
            'path'           => '',
            'single'         => true,
            'file_size'      => 0,
            'apart_level'    => [],
            'max_files'      => 30,
            'json'           => true,
            'processor'      => null,
            'close'          => false,
            'format'         => '[%s][%s] %s',
            'realtime_write' => true,
        ],
        // 其它日志通道配置
    ],

];
